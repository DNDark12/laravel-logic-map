import { S, getUniqueEdgeId, asNumber, formatShortLabel, getNamespace } from '../state.js';

let sgRequestSerial = 0;
let sgBusy = false;
let sgActionCooldownUntil = 0;
let sgCooldownTimer = null;

const SG_ACTION_COOLDOWN_MS = 600;
const SG_STALE_COOLDOWN_MS = 250;
const addedEdgeIds = new Set(); // For deduplicating edges from BE

export function getSubgraphSeedLabel() {
    if (!S.sgLastSeed) return 'No node selected';
    const seedNode = S.cy.getElementById(S.sgLastSeed);
    if (seedNode.length) {
        return seedNode.data('label') || seedNode.data('fullLabel') || S.sgLastSeed;
    }
    return S.sgLastSeed;
}

function _sgShowUI(seedLabel) {
    document.body.classList.add('subgraph-mode');
    const bar = document.getElementById('sg-controls-bar');
    if (bar) bar.classList.add('show');
    const badge = document.getElementById('sg-badge');
    if (badge) badge.style.display = 'flex';
    const mobileSeed = document.getElementById('mobile-subgraph-seed');
    if (mobileSeed) mobileSeed.textContent = seedLabel || getSubgraphSeedLabel();
    if (window.LogicMap && window.LogicMap.syncMobileSubgraphUI) {
        window.LogicMap.syncMobileSubgraphUI();
    }
}

function _sgHideUI() {
    document.body.classList.remove('subgraph-mode');
    const bar = document.getElementById('sg-controls-bar');
    if (bar) bar.classList.remove('show');
    const badge = document.getElementById('sg-badge');
    if (badge) badge.style.display = 'none';
    if (window.LogicMap && window.LogicMap.syncMobileSubgraphUI) {
        window.LogicMap.syncMobileSubgraphUI();
    }
}

function _getDepth() {
    const raw = document.getElementById('sg-hops')?.value;
    const val = parseInt(raw) || 2;
    return val >= 99 ? 99 : Math.max(1, val);
}

export function isSubgraphLocked() {
    return sgBusy || Date.now() < sgActionCooldownUntil;
}

export function syncSubgraphControlsLockState() {
    const locked = isSubgraphLocked();
    document.querySelectorAll('#sg-depth-grp .sgc-depth-btn').forEach(btn => {
        btn.disabled = locked;
    });
    document.querySelectorAll('#mobile-subgraph-controls .mam-chip[data-mobile-sg-depth]').forEach(btn => {
        btn.disabled = locked || !S.sgMode;
    });

    const rerunBtn = document.querySelector('#sg-controls-bar .sgc-btn');
    if (rerunBtn) {
        rerunBtn.disabled = locked;
        rerunBtn.classList.toggle('is-busy', sgBusy);
    }

    document.querySelectorAll('#mobile-subgraph-controls .mam-action[data-mobile-action="subgraph-rerun"]').forEach(btn => {
        btn.disabled = locked || !S.sgMode;
    });
    document.querySelectorAll('#mobile-subgraph-controls .mam-action[data-mobile-action="subgraph-exit"]').forEach(btn => {
        btn.disabled = !S.sgMode;
    });
}

function startSubgraphActionCooldown(ms = SG_ACTION_COOLDOWN_MS) {
    sgActionCooldownUntil = Date.now() + ms;
    if (sgCooldownTimer) clearTimeout(sgCooldownTimer);
    sgCooldownTimer = setTimeout(() => {
        syncSubgraphControlsLockState();
    }, ms + 20);
    syncSubgraphControlsLockState();
}

function setSubgraphBusyState(isBusy) {
    sgBusy = isBusy;
    syncSubgraphControlsLockState();
}

// Ensure the graph isn't animating fit
function runSubgraphLayoutAfterLoad(onDone) {
    const execute = () => {
        if (window.LogicMap && window.LogicMap.runLayout) {
             window.LogicMap.runLayout(window.LogicMap.currentLayout, onDone);
        } else {
             if (onDone) onDone();
        }
    };
    
    if (!window.LogicMap || !window.LogicMap.fitBusy) {
        execute();
        return;
    }

    const waiter = setInterval(() => {
        if (!window.LogicMap || !window.LogicMap.fitBusy) {
            clearInterval(waiter);
            execute();
        }
    }, 60);
}

// Core fetch-and-render logic
export function _loadSubgraph(seedId) {
    if (!seedId || isSubgraphLocked()) return;
    const requestId = ++sgRequestSerial;
    setSubgraphBusyState(true);
    const depth = _getDepth();
    const isStaleRequest = () => requestId !== sgRequestSerial || !S.sgMode || S.sgLastSeed !== seedId;
    
    const finalize = (focusSeedNode) => {
        if (isStaleRequest()) {
            setSubgraphBusyState(false);
            startSubgraphActionCooldown(SG_STALE_COOLDOWN_MS);
            return;
        }

        if (window.LogicMap && window.LogicMap.separateOrphanNodes) {
             window.LogicMap.separateOrphanNodes();
        }

        runSubgraphLayoutAfterLoad(() => {
            if (!isStaleRequest()) {
                focusSeedNode();
                if (window.LogicMap && window.LogicMap.applyHeatmapMode) window.LogicMap.applyHeatmapMode();
            }
            setSubgraphBusyState(false);
            startSubgraphActionCooldown(SG_ACTION_COOLDOWN_MS);
        });
    };

    const focusSeedNode = () => {
        const seedNode = S.cy.getElementById(seedId);
        if (!seedNode.length) return;

        if (window.LogicMap && window.LogicMap.applyHighlight) window.LogicMap.applyHighlight(seedNode);
        if (window.LogicMap && window.LogicMap.openPanel) window.LogicMap.openPanel(seedNode.data());
        S.cy.animate({
            fit: { padding: (window.LogicMap && window.LogicMap.getDynamicViewportPadding ? window.LogicMap.getDynamicViewportPadding(50, true) : 50) },
            duration: 450,
            easing: 'ease-out-cubic'
        });
    };

    S.cy.startBatch();
    S.cy.elements().addClass('dimmed');
    S.cy.endBatch();

    const apiDepth = depth >= 99 ? 10 : depth;
    fetch(window.LogicMap.withSnapshot(`${window.logicMapConfig.subgraphUrl}/${encodeURIComponent(seedId)}`, { depth: apiDepth }))
        .then(r => r.json())
        .then(json => {
            S.cy.startBatch();
            S.cy.elements().remove();
            if (S._sgOriginalElements) {
                S._sgOriginalElements.forEach(el => S.cy.add(el));
            }

            if (json.ok && json.data && json.data.nodes.length) {
                json.data.nodes.forEach(n => {
                    if (!S.cy.getElementById(n.id).length) {
                        S.cy.add({ data: { ...n, label: formatShortLabel(n.name || n.id), fullLabel: n.name || n.id, _ns: getNamespace(n.id) } });
                    }
                });
                json.data.edges.forEach(e => {
                    const bid = `${e.source}->${e.target}:${e.type}`;
                    if (!S.cy.getElementById(bid).length && !addedEdgeIds.has(bid)) {
                        S.cy.add({ data: { ...e, id: getUniqueEdgeId(e) } });
                    }
                });

                const keepIds = new Set(json.data.nodes.map(n => n.id));
                keepIds.add(seedId);
                S.cy.nodes().forEach(n => {
                    if (n.data('_groupNode')) return;
                    if (!keepIds.has(n.id())) n.remove();
                });
                S.cy.edges().forEach(e => {
                    if (!e.source().length || !e.target().length) e.remove();
                });
            } else {
                // Fallback BFS
                const seed = S.cy.getElementById(seedId);
                if (seed.length) {
                    let nb = seed;
                    for (let i = 0; i < depth; i++) nb = nb.union(nb.neighborhood());
                    S.cy.nodes().forEach(n => {
                        if (n.data('_groupNode')) return;
                        if (!nb.has(n)) n.remove();
                    });
                    S.cy.edges().forEach(e => {
                        if (!e.source().length || !e.target().length) e.remove();
                    });
                }
            }
            S.cy.endBatch();
            finalize(focusSeedNode);
        })
        .catch(() => {
            if (isStaleRequest()) {
                setSubgraphBusyState(false);
                startSubgraphActionCooldown(SG_STALE_COOLDOWN_MS);
                return;
            }
            // Fallback BFS
            S.cy.startBatch();
            S.cy.elements().remove();
            if (S._sgOriginalElements) {
                S._sgOriginalElements.forEach(el => S.cy.add(el));
            }
            const seed = S.cy.getElementById(seedId);
            if (seed.length) {
                let nb = seed;
                for (let i = 0; i < depth; i++) nb = nb.union(nb.neighborhood());
                S.cy.nodes().forEach(n => {
                    if (n.data('_groupNode')) return;
                    if (!nb.has(n)) n.remove();
                });
                S.cy.edges().forEach(e => {
                    if (!e.source().length || !e.target().length) e.remove();
                });
            }
            S.cy.endBatch();
            finalize(focusSeedNode);
        });
}

export function enterSubGraph(seedNodes) {
    if (!seedNodes) return;

    let seeds = [];
    if (typeof seedNodes === 'string') seeds = [seedNodes];
    else if (seedNodes.length !== undefined) {
        seedNodes.forEach(n => seeds.push(typeof n === 'string' ? n : n.id()));
    } else if (seedNodes.id) {
        seeds = [seedNodes.id()];
    }

    if (!seeds.length) return;
    
    if (seeds.some(id => String(id).startsWith('zone:'))) {
        window.LogicMap && window.LogicMap.showWarning && window.LogicMap.showWarning('SubGraph API cannot be run on a generic Zone module. Double-click the zone to drill down instead.', 3000);
        return;
    }

    if (isSubgraphLocked()) return;

    const seedId = seeds[0];
    S.sgLastSeed = seedId;

    if (!S.sgMode) {
        S._sgOriginalElements = S.cy.elements().jsons();
    }
    S.sgMode = true;

    const shortLabel = S.cy.getElementById(seedId)?.data('label') || seedId.split('\\').pop() || seedId;
    _sgShowUI(shortLabel);
    _loadSubgraph(seedId);
}

export function rerunSubGraph() {
    if (!S.sgMode || !S.sgLastSeed || isSubgraphLocked()) return;
    _loadSubgraph(S.sgLastSeed);
}

export function exitSubGraph() {
    if (!S.sgMode) return;
    sgRequestSerial += 1;
    sgActionCooldownUntil = 0;
    if (sgCooldownTimer) {
        clearTimeout(sgCooldownTimer);
        sgCooldownTimer = null;
    }
    setSubgraphBusyState(false);
    S.sgMode = false;
    S.sgLastSeed = null;
    _sgHideUI();

    if (S._zonesOriginalElements && window.LogicMap && window.LogicMap.restoreFromZonesMode) {
        window.LogicMap.restoreFromZonesMode();
    }

    if (S._sgOriginalElements) {
        S.cy.startBatch();
        S.cy.elements().remove();
        const nodes = S._sgOriginalElements.filter(el => el.group === 'nodes');
        const edges = S._sgOriginalElements.filter(el => el.group === 'edges');
        nodes.forEach(el => S.cy.add(el));
        edges.forEach(el => S.cy.add(el));
        S.cy.endBatch();
        S._sgOriginalElements = null;
        if (window.LogicMap && window.LogicMap.separateOrphanNodes) {
            window.LogicMap.separateOrphanNodes();
        }
        
        const returnLayout = window.LogicMap.currentLayout === 'zones' ? 'graph' : window.LogicMap.currentLayout;
        if (window.LogicMap && window.LogicMap.runLayout) {
             window.LogicMap.runLayout(returnLayout, () => {
                S.cy.animate({
                    fit: { padding: window.LogicMap.getDynamicViewportPadding ? window.LogicMap.getDynamicViewportPadding(40, false) : 40 },
                    duration: 400
                });
            });
        }
        if (window.LogicMap && window.LogicMap.applyHeatmapMode) window.LogicMap.applyHeatmapMode();
    }
}
