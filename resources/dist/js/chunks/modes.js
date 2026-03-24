import { S, computeHeatRaw, asNumber } from '../state.js';

/* ────────────────────────────────────
   FLOW MODE — Edge classification + filter
──────────────────────────────────── */

// Returns 'keep', 'dim', or 'hide' for a given edge in Flow mode
export function getNodeKind(nodeId) {
    const n = S.cy.getElementById(nodeId);
    return n && n.length ? (n.data('kind') || 'unknown') : 'unknown';
}

export function restoreAllElementVisibility() {
    S.cy.elements().style('display', 'element');
    S.cy.edges().removeClass('dimmed');
}

export function applyFlowModeVisuals() {
    restoreAllElementVisibility();

    // In Flow mode, we want to dim cross-module edges to reduce noise,
    // assuming they form a hairball.
    // If you explicitly want to focus on a module, you use module explorer.
    // So by default, we just keep all nodes visible, and dim "call" or "use" edges 
    // that cross boundaries if there are too many. For now, Flow mode displays everything.
    
    // Subgraph/Orphans have their own rules. We just guarantee everything is visible.
    const allCyNodes = S.cy.nodes().filter(n => !n.data('_groupNode') && !n.data('_zoneNode'));
    allCyNodes.forEach(n => {
        if (n.data('_orphan')) {
            n.style('display', 'element');
        }
    });
}

/* ────────────────────────────────────
   RISK MODE — Percentile + Neighborhood
──────────────────────────────────── */
function percentileScore(sortedDesc, ratio) {
    if (!sortedDesc.length) return 0;
    const idx = Math.floor(sortedDesc.length * (1 - ratio));
    return sortedDesc[Math.min(idx, sortedDesc.length - 1)].score;
}

function buildRiskNodeSet(allCyNodes) {
    const scores = [];
    allCyNodes.forEach(n => {
        if (n.data('_groupNode')) return;
        scores.push({ node: n, score: computeHeatRaw(n) });
    });
    scores.sort((a, b) => b.score - a.score);

    const threshold70 = percentileScore(scores, 0.70); // top 30%
    const minCount = Math.max(8, Math.floor(scores.length * 0.15));

    let primary = scores.filter(s => s.score >= threshold70).map(s => s.node);

    if (primary.length < minCount) {
        const threshold50 = percentileScore(scores, 0.50);
        primary = scores.filter(s => s.score >= threshold50).map(s => s.node);
    }

    return primary;
}

function getDirectNeighborIds(nodeId, allCyEdges) {
    const ids = new Set();
    allCyEdges.forEach(edge => {
        if (edge.data('source') === nodeId) ids.add(edge.data('target'));
        if (edge.data('target') === nodeId) ids.add(edge.data('source'));
    });
    return ids;
}

function expandRiskNeighborhood(primaryNodes, allCyEdges, allCyNodes) {
    const ARCHITECTURAL = new Set([
        'route', 'controller', 'service', 'action', 'helper',
        'repository', 'model', 'job', 'listener', 'event', 'policy', 'middleware', 'rule', 'observer', 'resource', 'provider', 'console'
    ]);
    const CAP = Math.max(5, Math.ceil(primaryNodes.length * 0.30));
    const primaryIds = new Set(primaryNodes.map(n => n.id()));
    const nodeById = new Map();
    allCyNodes.forEach(n => nodeById.set(n.id(), n));

    const candidates = [];
    primaryNodes.forEach(node => {
        const neighborIds = getDirectNeighborIds(node.id(), allCyEdges);
        neighborIds.forEach(nId => {
            if (primaryIds.has(nId)) return;
            const neighbor = nodeById.get(nId);
            if (!neighbor || neighbor.data('_groupNode')) return;
            if (!ARCHITECTURAL.has(neighbor.data('kind'))) return;
            candidates.push({ node: neighbor, score: computeHeatRaw(neighbor) });
        });
    });

    const seen = new Set();
    const unique = candidates
        .filter(c => { if (seen.has(c.node.id())) return false; seen.add(c.node.id()); return true; })
        .sort((a, b) => b.score - a.score)
        .slice(0, CAP);

    return { primary: primaryNodes, context: unique.map(c => c.node) };
}

export function applyRiskModeVisuals() {
    const allCyNodes = S.cy.nodes().filter(n => !n.data('_groupNode') && !n.data('_orphan') && !n.data('_zoneNode'));
    const allCyEdges = S.cy.edges();

    const primaryNodes = buildRiskNodeSet(allCyNodes);
    const { primary, context } = expandRiskNeighborhood(primaryNodes, allCyEdges, allCyNodes);
    const primaryIds = new Set(primary.map(n => n.id()));
    const contextIds = new Set(context.map(n => n.id()));
    const visibleIds = new Set([...primaryIds, ...contextIds]);

    S.cy.nodes().forEach(n => {
        if (n.data('_groupNode')) return;
        const isOrphan = n.data('_orphan');
        if (isOrphan) {
            const riskOk = n.data('risk') === 'critical' || n.data('risk') === 'high';
            n.style('display', riskOk ? 'element' : 'none');
            return;
        }
        n.style('display', visibleIds.has(n.id()) ? 'element' : 'none');
    });

    S.cy.nodes().forEach(n => {
        n.removeClass('risk-primary risk-context');
        if (primaryIds.has(n.id())) n.addClass('risk-primary');
        else if (contextIds.has(n.id())) n.addClass('risk-context');
    });

    S.cy.edges().forEach(edge => {
        const src = edge.source(), tgt = edge.target();
        const srcVisible = visibleIds.has(src.id());
        const tgtVisible = visibleIds.has(tgt.id());
        edge.style('display', (srcVisible && tgtVisible) ? 'element' : 'none');
    });
}


/* ────────────────────────────────────
   ZONES MODE — Transform pipeline
──────────────────────────────────── */

function inferZone(cyNode) {
    const mod = cyNode.data('module');
    if (mod && mod.trim() !== '') return mod.trim();

    const id = cyNode.id();
    const match = id.match(/App\\([^\\]+)/i);
    if (match) return match[1];

    const kindZoneMap = {
        route: 'Routing',
        controller: 'Http',
        command: 'Console',
    };
    const kind = cyNode.data('kind');
    if (kindZoneMap[kind]) return kindZoneMap[kind];

    return 'Shared';
}

function buildZoneAssignments(allCyNodes) {
    const nodeToZone = new Map();
    allCyNodes.forEach(n => nodeToZone.set(n.id(), inferZone(n)));

    const zoneCounts = {};
    for (const z of nodeToZone.values()) {
        zoneCounts[z] = (zoneCounts[z] || 0) + 1;
    }

    const MIN_ZONE = Math.max(2, Math.floor(allCyNodes.length * 0.04));

    const smallZones = new Set(
        Object.entries(zoneCounts)
            .filter(([z, c]) => c < MIN_ZONE && z !== 'Shared')
            .map(([z]) => z)
    );
    for (const [id, zone] of nodeToZone) {
        if (smallZones.has(zone)) nodeToZone.set(id, 'Other');
    }

    return nodeToZone;
}

function worstRisk(current, incoming) {
    const order = ['healthy', 'low', 'medium', 'high', 'critical'];
    const ci = order.indexOf(current);
    const ii = order.indexOf(incoming);
    return ii > ci ? incoming : current;
}

function buildSupernodes(allCyNodes, nodeToZone) {
    const zones = {};
    allCyNodes.forEach(node => {
        const zone = nodeToZone.get(node.id());
        if (!zones[zone]) {
            zones[zone] = {
                id: `zone:${zone}`,
                label: zone,
                count: 0,
                childIds: [],
                maxRisk: 'healthy',
                totalHeat: 0,
            };
        }
        zones[zone].count++;
        zones[zone].childIds.push(node.id());
        zones[zone].totalHeat += computeHeatRaw(node);
        zones[zone].maxRisk = worstRisk(zones[zone].maxRisk, node.data('risk') || 'healthy');
    });

    return Object.values(zones).map(z => ({
        group: 'nodes',
        data: {
            id: z.id,
            label: `${z.label} (${z.count})`,
            kind: 'zone',
            risk: z.maxRisk,
            _zoneNode: true,
            _zoneChildIds: z.childIds,
            _zoneLabel: z.label,
            _totalHeat: z.totalHeat,
        }
    }));
}

function buildZoneEdges(allCyEdges, nodeToZone) {
    const edgeCounts = {}; // "zoneA→zoneB" → count
    allCyEdges.forEach(edge => {
        const srcZone = nodeToZone.get(edge.data('source'));
        const tgtZone = nodeToZone.get(edge.data('target'));
        if (!srcZone || !tgtZone || srcZone === tgtZone) return; // skip intra-zone
        const key = `${srcZone}→${tgtZone}`;
        edgeCounts[key] = (edgeCounts[key] || 0) + 1;
    });

    return Object.entries(edgeCounts).map(([key, count]) => {
        const [src, tgt] = key.split('→');
        return {
            group: 'edges',
            data: {
                id: `zone-edge:${key}`,
                source: `zone:${src}`,
                target: `zone:${tgt}`,
                label: count > 1 ? `${count}` : '',
                type: 'zone_link',
                _edgeCount: count,
            }
        };
    });
}

function checkZoneQuality(allCyNodes, nodeToZone) {
    const UNNAMED = new Set(['Shared', 'Other']);
    let namedCount = 0;
    allCyNodes.forEach(n => {
        if (!UNNAMED.has(nodeToZone.get(n.id()))) namedCount++;
    });
    const quality = namedCount / Math.max(1, allCyNodes.length);
    if (quality < 0.60) {
        showZoneWarning(
            `Zone grouping covers only ${Math.round(quality * 100)}% of nodes. ` +
            `Consider adding module metadata or using Graph or Flow mode.`
        );
    }
}

let _zoneWarningEl = null;
export function showZoneWarning(message) {
    if (_zoneWarningEl) _zoneWarningEl.remove();
    const banner = document.createElement('div');
    banner.style.cssText = `position:fixed;top:52px;left:50%;transform:translateX(-50%);` +
        `background:rgba(234,179,8,.15);border:1px solid #eab308;padding:7px 14px;` +
        `border-radius:6px;font-size:10px;color:#eab308;z-index:25;max-width:420px;` +
        `display:flex;align-items:center;gap:8px;font-family:inherit;`;
    banner.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>` +
        `<span>${message}</span>` +
        `<button style="margin-left:auto;background:none;border:none;cursor:pointer;color:#eab308;font-size:14px;padding:0 4px;" onclick="this.parentNode.remove()">×</button>`;
    document.body.appendChild(banner);
    _zoneWarningEl = banner;
    setTimeout(() => { if (banner.parentNode) banner.remove(); }, 10000);
}

export function dismissZoneWarning() {
    if (_zoneWarningEl) { _zoneWarningEl.remove(); _zoneWarningEl = null; }
}

export function applyZonesMode() {
    const allCyNodes = S.cy.nodes().filter(n => !n.data('_groupNode') && !n.data('_zoneNode'));
    const allCyEdges = S.cy.edges();

    if (!allCyNodes.length) return;

    if (!S._zonesOriginalElements) {
        S._zonesOriginalElements = S.cy.elements().jsons();
    }

    const nodeToZone = buildZoneAssignments(allCyNodes);
    const supernodes = buildSupernodes(allCyNodes, nodeToZone);
    const zoneEdges = buildZoneEdges(allCyEdges, nodeToZone);

    S.cy.startBatch();
    S.cy.elements().remove();
    supernodes.forEach(n => S.cy.add(n));
    zoneEdges.forEach(e => {
        try { S.cy.add(e); } catch (_) { }
    });
    S.cy.endBatch();

    S.cy.off('tap', 'node[kind="zone"]'); // avoid double-binding
    S.cy.on('tap', 'node[kind="zone"]', function(evt) {
        if (window.LogicMap && window.LogicMap.openZoneDrillDown) {
            window.LogicMap.openZoneDrillDown(evt.target);
        }
    });

    checkZoneQuality(allCyNodes, nodeToZone);
}

export function restoreFromZonesMode() {
    if (!S._zonesOriginalElements) return;
    dismissZoneWarning();
    S.cy.off('tap', 'node[kind="zone"]');
    S.cy.startBatch();
    S.cy.elements().remove();
    const nodes = S._zonesOriginalElements.filter(el => el.group === 'nodes');
    const edges = S._zonesOriginalElements.filter(el => el.group === 'edges');
    nodes.forEach(el => S.cy.add(el));
    edges.forEach(el => S.cy.add(el));
    S.cy.endBatch();
    S._zonesOriginalElements = null;
}

/* ────────────────────────────────────
   HEATMAP MODE
──────────────────────────────────── */

function getPercentile(allValues, value) {
    if (!allValues.length) return 0;
    const clamped = Math.max(0, Math.min(1, value));
    const idx = Math.floor((allValues.length - 1) * clamped);
    return allValues[idx];
}

function getHeatmapNodes() {
    return S.cy.nodes().filter(n => !n.data('_groupNode') && !n.data('_zoneNode'));
}

export function recalculateHeatmapData() {
    const nodes = getHeatmapNodes();
    if (!nodes.length) return;

    const raws = [];
    nodes.forEach(node => {
        const raw = Number(computeHeatRaw(node).toFixed(2));
        node.data('heat_raw', raw);
        raws.push(raw);
    });

    const sorted = raws.slice().sort((a, b) => a - b);
    const min = sorted[0];
    const max = sorted[sorted.length - 1];

    if (min === max) {
        nodes.forEach(node => node.data('heat_level', 0));
        return;
    }

    const p25 = getPercentile(sorted, 0.25);
    const p50 = getPercentile(sorted, 0.5);
    const p75 = getPercentile(sorted, 0.75);
    const p90 = getPercentile(sorted, 0.9);

    nodes.forEach(node => {
        const raw = asNumber(node.data('heat_raw'));
        let level = 0;
        if (raw > p90) level = 4;
        else if (raw > p75) level = 3;
        else if (raw > p50) level = 2;
        else if (raw > p25) level = 1;
        node.data('heat_level', level);
    });
}

function syncHeatmapUI() {
    const btn = document.getElementById('heatmap-toggle');
    if (btn) {
        btn.classList.toggle('active', S.heatmapEnabled);
        const label = btn.querySelector('.btn-lbl');
        if (label) label.textContent = S.heatmapEnabled ? 'Heat: On' : 'Heat: Off';
    }

    const mobileLabel = document.getElementById('mobile-heat-label');
    if (mobileLabel) {
        mobileLabel.textContent = S.heatmapEnabled ? 'Heat: On' : 'Heat: Off';
    }

    const hint = document.getElementById('legend-heatmap-hint');
    if (hint) {
        hint.style.display = S.heatmapEnabled ? 'block' : 'none';
    }
}

export function applyHeatmapMode() {
    recalculateHeatmapData();

    const nodes = getHeatmapNodes();
    nodes.removeClass('heatmap-on');
    if (S.heatmapEnabled) {
        nodes.addClass('heatmap-on');
    }

    syncHeatmapUI();
}

export function toggleHeatmap() {
    S.heatmapEnabled = !S.heatmapEnabled;
    applyHeatmapMode();
}
