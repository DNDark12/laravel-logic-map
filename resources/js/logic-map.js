(function () {
    const init = function () {
        if (window.logicMapInitialized) return;
        window.logicMapInitialized = true;

        if (typeof cytoscape.dagre === 'undefined' && typeof cytoscapeDagre !== 'undefined') {
            cytoscape.use(cytoscapeDagre);
        }

    const KIND_COLORS = {
        route: {bg: '#dcfce7', bd: '#22c55e'},
        controller: {bg: '#dbeafe', bd: '#3b82f6'},
        service: {bg: '#fef3c7', bd: '#f59e0b'},
        repository: {bg: '#f3e8ff', bd: '#a855f7'},
        model: {bg: '#fce7f3', bd: '#ec4899'},
        event: {bg: '#cffafe', bd: '#06b6d4'},
        job: {bg: '#cffafe', bd: '#06b6d4'},
        listener: {bg: '#cffafe', bd: '#06b6d4'},
        command: {bg: '#e0e7ff', bd: '#6366f1'},
        component: {bg: '#fef3c7', bd: '#eab308'},
        unknown: {bg: '#f3f4f6', bd: '#6b7280'}
    };

    let currentLayout = 'dagre';
    let allNodesData = [];
    let allEdgesData = [];
    let sgOriginalElements = null; // Store original elements for SubGraph exit
    let sgLastSeed = null; // Last subgraph seed node
    let crossModuleEdges = {}; // Cross-module edge counts

    // ── Cytoscape Init ──
    const cy = cytoscape({
        container: document.getElementById('cy'),
        pixelRatio: Math.min(window.devicePixelRatio, 2),
        hideEdgesOnViewport: true,
        textureOnViewport: false,
        motionBlur: false,
        style: [
            {
                selector: 'node', style: {
                    'background-color': '#f3f4f6', 'label': 'data(label)', 'color': '#1c2036',
                    'font-size': '9px', 'font-family': '"SF Mono","Fira Code",monospace',
                    'text-valign': 'center', 'text-halign': 'center',
                    'border-width': 1, 'border-color': '#9ca3af',
                    'padding': '8px', 'shape': 'round-rectangle',
                    'width': '120px', 'height': '40px',
                    'text-max-width': '110px', 'text-wrap': 'ellipsis',
                    'text-overflow-wrap': 'anywhere',
                    'min-zoomed-font-size': 6
                }
            },
            {selector: 'node[kind="route"]', style: {'background-color': '#dcfce7', 'border-color': '#22c55e'}},
            {selector: 'node[kind="controller"]', style: {'background-color': '#dbeafe', 'border-color': '#3b82f6'}},
            {selector: 'node[kind="service"]', style: {'background-color': '#fef3c7', 'border-color': '#f59e0b'}},
            {selector: 'node[kind="repository"]', style: {'background-color': '#f3e8ff', 'border-color': '#a855f7'}},
            {selector: 'node[kind="model"]', style: {'background-color': '#fce7f3', 'border-color': '#ec4899'}},
            {selector: 'node[kind="event"]', style: {'background-color': '#cffafe', 'border-color': '#06b6d4'}},
            {selector: 'node[kind="job"]', style: {'background-color': '#cffafe', 'border-color': '#06b6d4'}},
            {selector: 'node[kind="listener"]', style: {'background-color': '#cffafe', 'border-color': '#06b6d4'}},
            {selector: 'node[kind="command"]', style: {'background-color': '#e0e7ff', 'border-color': '#6366f1'}},
            {selector: 'node.highlighted', style: {'border-width': 3, 'border-color': '#dd3585', 'z-index': 999}},
            {selector: 'node.neighbor', style: {'border-width': 2, 'border-color': '#4a7ff5'}},
            {selector: 'node.dimmed', style: {'opacity': 0.15}},
            {
                selector: 'node.module-focus',
                style: {'border-width': 3, 'border-color': '#4a7ff5', 'z-index': 998}
            },
            {
                selector: 'edge', style: {
                    'width': 1.5, 'line-color': 'rgba(156,163,175,0.4)',
                    'target-arrow-color': 'rgba(156,163,175,0.4)',
                    'target-arrow-shape': 'triangle', 'arrow-scale': 0.8,
                    'curve-style': 'bezier'
                }
            },
            {
                selector: 'edge[type="route_to_controller"]',
                style: {'line-color': '#22c55e', 'target-arrow-color': '#22c55e'}
            },
            {
                selector: 'edge[type="call"]',
                style: {'line-color': 'rgba(59,130,246,0.6)', 'target-arrow-color': 'rgba(59,130,246,0.6)'}
            },
            {
                selector: 'edge[type="use"]',
                style: {
                    'line-color': 'rgba(245,158,11,0.5)',
                    'target-arrow-color': 'rgba(245,158,11,0.5)',
                    'line-style': 'dashed'
                }
            },
            {
                selector: 'edge.highlighted', style: {
                    'width': 2.5,
                    'z-index': 999,
                    'line-style': 'dashed',
                    'line-dash-pattern': [8, 4],
                    'line-dash-offset': 0
                }
            },
            {selector: 'edge.dimmed', style: {'opacity': 0.08}},
        ],
        layout: {name: 'preset'},
        minZoom: 0.1, maxZoom: 4
    });

    // Track edge IDs to avoid duplicates
    const addedEdgeIds = new Set();

    // Helper to generate unique edge ID
    function getUniqueEdgeId(e) {
        const baseId = `${e.source}->${e.target}:${e.type}`;
        if (!addedEdgeIds.has(baseId)) {
            addedEdgeIds.add(baseId);
            return baseId;
        }
        // Add index for duplicates
        let idx = 2;
        while (addedEdgeIds.has(`${baseId}#${idx}`)) {
            idx++;
        }
        const uniqueId = `${baseId}#${idx}`;
        addedEdgeIds.add(uniqueId);
        return uniqueId;
    }


    // ── Layout Configs ──
    function getLayoutOpts(name) {
        switch (name) {
            case 'dagre':
                return {name: 'dagre', rankDir: 'TB', nodeSep: 50, rankSep: 100, edgeSep: 20};
            case 'cose':
                return {name: 'cose', idealEdgeLength: 100, nodeRepulsion: 8000, animate: false};
            case 'lr':
                return {name: 'dagre', rankDir: 'LR', nodeSep: 50, rankSep: 100, edgeSep: 20};
            case 'compact':
                return {
                    name: 'cose',
                    idealEdgeLength: 80,
                    nodeRepulsion: 8000,
                    gravity: 2.5,
                    nodeDimensionsIncludeLabels: true,
                    padding: 40,
                    animate: true,
                    animationDuration: 500
                };
            default:
                return {name: 'dagre', rankDir: 'TB', nodeSep: 50, rankSep: 100};
        }
    }

    function runLayout(name) {
        currentLayout = name;
        document.querySelectorAll('.lbtn').forEach(b => b.classList.toggle('active', b.dataset.layout === name));
        cy.startBatch();
        cy.layout(getLayoutOpts(name)).run();
        cy.endBatch();
    }

    document.querySelectorAll('.lbtn').forEach(btn => {
        btn.addEventListener('click', () => runLayout(btn.dataset.layout));
    });

    // ── Theme ──
    window.toggleTheme = function () {
        const html = document.documentElement;
        html.dataset.theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
    };

    // ── Module Panel ──
    window.toggleModPanel = function () {
        const panel = document.getElementById('mod-panel');
        panel.classList.toggle('open');
        const cy$ = document.getElementById('cy');
        cy$.style.left = panel.classList.contains('open') ? '300px' : '0';
        document.getElementById('legend').style.left = panel.classList.contains('open') ? '316px' : '16px';
    };

    const modToggleEl = document.getElementById('mod-toggle');
    if (modToggleEl) {
        modToggleEl.addEventListener('click', toggleModPanel);
    }

    // ── LOD (Level of Detail) on Zoom ──
    let lodTimer = null;
    cy.on('zoom', function () {
        clearTimeout(lodTimer);
        lodTimer = setTimeout(applyLOD, 50);
    });

    function applyLOD() {
        const z = cy.zoom();
        cy.startBatch();
        if (z < 0.25) {
            cy.nodes().style('font-size', 0.01);
            cy.edges().style('font-size', 0.01);
        } else if (z < 0.5) {
            cy.nodes().style('font-size', 8);
            cy.edges().style('font-size', 0.01);
        } else {
            cy.nodes().style('font-size', 9);
            cy.edges().style('font-size', 9);
        }
        cy.endBatch();
    }

    // ── Helper: Get Route Info ──
    function getRouteInfo(nodeData) {
        const meta = nodeData.metadata || {};
        if (meta.route_uri || meta.routeUri) {
            return {
                uri: meta.route_uri || meta.routeUri,
                verb: meta.route_verb || meta.routeVerb || 'GET'
            };
        }
        // Check if this node IS a route
        if (nodeData.kind === 'route') {
            const label = nodeData.label || nodeData.name || '';
            const match = label.match(/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(.+)/i);
            if (match) return {verb: match[1].toUpperCase(), uri: match[2]};
            return {verb: 'GET', uri: label};
        }
        return null;
    }

    // ── Helper: Get Trigger (who calls this) ──
    function getTrigger(nodeId) {
        const incoming = cy.edges(`[target="${nodeId}"]`);
        if (incoming.length === 0) return null;
        const sources = incoming.map(e => {
            const src = e.source();
            return {
                id: src.id(),
                label: src.data('label') || src.id().split('\\').pop(),
                kind: src.data('kind'),
                type: e.data('type')
            };
        });
        return sources;
    }

    // ── Helper: Get Result (what this triggers) ──
    function getResult(nodeId) {
        const outgoing = cy.edges(`[source="${nodeId}"]`);
        if (outgoing.length === 0) return null;
        const targets = outgoing.map(e => {
            const tgt = e.target();
            return {
                id: tgt.id(),
                label: tgt.data('label') || tgt.id().split('\\').pop(),
                kind: tgt.data('kind'),
                type: e.data('type')
            };
        });
        return targets;
    }

    // ── Helper: Build Timeline (DFS) ──
    function buildTimeline(nodeId, maxDepth = 5) {
        const timeline = [];
        const visited = new Set();

        function dfs(id, depth) {
            if (depth > maxDepth || visited.has(id)) return;
            visited.add(id);

            const outgoing = cy.edges(`[source="${id}"]`);
            outgoing.forEach(e => {
                const tgt = e.target();
                const tgtId = tgt.id();
                if (!visited.has(tgtId)) {
                    timeline.push({
                        id: tgtId,
                        label: tgt.data('label') || tgtId.split('\\').pop(),
                        kind: tgt.data('kind'),
                        type: e.data('type'),
                        depth: depth
                    });
                    dfs(tgtId, depth + 1);
                }
            });
        }

        dfs(nodeId, 1);
        return timeline;
    }

    // ── Helper: Check if Hub Utility ──
    function isHubUtility(nodeData) {
        const m = nodeData.metrics || {};
        const fanIn = m.fan_in || 0;
        const fanOut = m.fan_out || 0;
        return fanIn > 5 && fanOut === 0 && nodeData.kind !== 'route';
    }

    // ── Detail Panel ──
    function openPanel(nodeData) {
        const panel = document.getElementById('panel');
        const badge = document.getElementById('p-badge');
        const name = document.getElementById('p-name');
        const pid = document.getElementById('p-id');
        const body = document.getElementById('pbody');
        const hubWarn = document.getElementById('hub-warning');

        const kind = nodeData.kind || 'unknown';
        const kc = KIND_COLORS[kind] || KIND_COLORS.unknown;
        badge.style.color = kc.bd;
        badge.style.borderColor = kc.bd;
        badge.textContent = kind.toUpperCase();
        name.textContent = nodeData.metadata?.shortLabel || nodeData.name || nodeData.label || nodeData.id;
        pid.textContent = nodeData.id;

        // Hub Warning
        if (isHubUtility(nodeData)) {
            hubWarn.classList.add('show');
        } else {
            hubWarn.classList.remove('show');
        }

        let html = '';
        const meta = nodeData.metadata || {};

        // ── Flow Box ──
        const triggers = getTrigger(nodeData.id);
        const results = getResult(nodeData.id);
        const routeInfo = getRouteInfo(nodeData);

        html += `<div class="ps"><div class="sl">Flow</div><div class="flow-box">`;

        // Trigger row
        html += `<div class="flow-row"><span class="flow-ico">⚡</span><div><div class="flow-lbl">Trigger</div><div class="flow-val">`;
        if (triggers && triggers.length > 0) {
            const triggerTxt = triggers.slice(0, 3).map(t => `<strong>${t.label}</strong>`).join(', ');
            html += triggerTxt + (triggers.length > 3 ? ` +${triggers.length - 3} more` : '');
        } else {
            html += meta.trigger || '<span style="color:var(--tx3)">Entry point</span>';
        }
        html += `</div></div></div>`;

        // Arrow
        html += `<div class="flow-arr">↓</div>`;

        // Action row
        html += `<div class="flow-row"><span class="flow-ico">⚙</span><div><div class="flow-lbl">Action</div><div class="flow-val">`;
        html += `<strong>${meta.action || nodeData.name || nodeData.label || '-'}</strong>`;
        if (meta.domain) html += ` ${meta.domain}`;
        if (routeInfo) {
            html += `<div class="uri-pill"><span class="uri-verb">${routeInfo.verb}</span> ${routeInfo.uri}</div>`;
        }
        html += `</div></div></div>`;

        // Arrow
        html += `<div class="flow-arr">↓</div>`;

        // Result row
        html += `<div class="flow-row"><span class="flow-ico">✓</span><div><div class="flow-lbl">Result</div><div class="flow-val">`;
        if (results && results.length > 0) {
            const resultTxt = results.slice(0, 3).map(r => `<strong>${r.label}</strong>`).join(', ');
            html += 'Triggers: ' + resultTxt + (results.length > 3 ? ` +${results.length - 3} more` : '');
        } else {
            html += meta.result || '<span style="color:var(--tx3)">Terminal node</span>';
        }
        html += `</div></div></div>`;

        html += `</div></div>`;

        // ── Timeline: What triggers next ──
        const timeline = buildTimeline(nodeData.id);
        if (timeline.length > 0) {
            html += `<div class="ps"><div class="sl">What it triggers next →</div><div class="tl">`;
            timeline.slice(0, 8).forEach((item, idx) => {
                const dotClass = item.type === 'dispatch' ? 'dispatch' : (item.kind === 'event' ? 'event' : 'call');
                html += `<div class="tli" onclick="focusNode('${item.id}')">
                    <span class="tli-dot ${dotClass}">${idx + 1}</span>
                    <div class="tli-body">
                        <div class="tli-m">${item.label}</div>
                        <div class="tli-sub">${item.kind || 'unknown'} • ${item.type || 'call'}</div>
                    </div>
                </div>`;
            });
            if (timeline.length > 8) {
                html += `<div class="tli"><span class="tli-dot" style="background:var(--tx3)">+</span><div class="tli-body"><div class="tli-m" style="color:var(--tx3)">${timeline.length - 8} more...</div></div></div>`;
            }
            html += `</div></div>`;
        }

        // Metrics section
        const m = nodeData.metrics || {};
        html += `<div class="ps"><div class="sl">Metrics</div><div class="mrow">`;
        html += mc('Fan In', m.fan_in ?? '-');
        html += mc('Fan Out', m.fan_out ?? '-');
        html += mc('Instability', m.instability != null ? m.instability.toFixed(2) : '-');
        html += mc('Coupling', m.coupling ?? '-');
        html += mc('Depth', m.depth ?? '∅');
        html += mc('In°', m.in_degree ?? '-');
        html += mc('Out°', m.out_degree ?? '-');
        html += `</div></div>`;

        // Connections (simplified - already shown in timeline)
        const incoming = cy.edges(`[target="${nodeData.id}"]`);
        const outgoing = cy.edges(`[source="${nodeData.id}"]`);

        if (incoming.length > 0) {
            html += `<div class="ps"><div class="sl">Incoming (${incoming.length})</div><div class="conn-list">`;
            incoming.forEach(e => {
                const src = e.source();
                html += `<div class="conn-item" onclick="focusNode('${src.id()}')">`
                    + `<span class="conn-arr">←</span><span class="conn-name">${src.data('label') || src.id()}</span>`
                    + `<span style="font-size:8px;color:var(--tx3)">${e.data('type')}</span></div>`;
            });
            html += `</div></div>`;
        }

        if (outgoing.length > 0) {
            html += `<div class="ps"><div class="sl">Outgoing (${outgoing.length})</div><div class="conn-list">`;
            outgoing.forEach(e => {
                const tgt = e.target();
                html += `<div class="conn-item" onclick="focusNode('${tgt.id()}')">`
                    + `<span class="conn-arr">→</span><span class="conn-name">${tgt.data('label') || tgt.id()}</span>`
                    + `<span style="font-size:8px;color:var(--tx3)">${e.data('type')}</span></div>`;
            });
            html += `</div></div>`;
        }

        body.innerHTML = html;
        panel.classList.add('open');
    }

    function mc(label, value) {
        return `<div class="mc"><div class="mv">${value}</div><div class="ml">${label}</div></div>`;
    }

    window.closePanel = function () {
        document.getElementById('panel').classList.remove('open');
        document.getElementById('hub-warning').classList.remove('show');
        stopFlow();
        cy.nodes().removeClass('highlighted neighbor dimmed module-focus');
        cy.edges().removeClass('highlighted dimmed');
    };

    window.focusNode = function (id) {
        const node = cy.getElementById(id);
        if (node.length) {
            cy.animate({center: {eles: node}, zoom: 1.5}, {duration: 400});
            node.emit('tap');
        }
    };

    // ── Fit View & Clear ──
    window.fitView = function () {
        cy.animate({fit: {padding: 60}}, {duration: 500});
    };

    window.clearHighlight = function () {
        closePanel();
        cy.nodes().removeClass('highlighted neighbor dimmed module-focus');
        cy.edges().removeClass('highlighted dimmed');
    };

    // ── Edge Flow Animation ──
    let raf = null, doff = 0;

    function startFlow() {
        if (raf) return;

        function tick() {
            const hlEdges = cy.edges('.highlighted');
            if (!hlEdges.length) {
                raf = null;
                doff = 0;
                return;
            }
            doff -= 1.5;
            hlEdges.style('line-dash-offset', doff);
            raf = requestAnimationFrame(tick);
        }

        raf = requestAnimationFrame(tick);
    }

    function stopFlow() {
        if (raf) {
            cancelAnimationFrame(raf);
            raf = null;
            doff = 0;
        }
    }

    // ── Node Click ──
    cy.on('tap', 'node', function (evt) {
        const node = evt.target;
        stopFlow();
        cy.nodes().removeClass('highlighted neighbor dimmed module-focus');
        cy.edges().removeClass('highlighted dimmed');

        node.addClass('highlighted');
        const neighborhood = node.neighborhood();
        neighborhood.nodes().addClass('neighbor');
        neighborhood.edges().addClass('highlighted');

        // Dim non-neighbors
        cy.nodes().not(node).not(neighborhood.nodes()).addClass('dimmed');
        cy.edges().not(neighborhood.edges()).addClass('dimmed');

        startFlow();

        openPanel(node.data());

        // Fetch subgraph for deeper connections
        fetch(window.logicMapConfig.subgraphUrl + '/' + encodeURIComponent(node.id()))
            .then(r => r.json())
            .then(json => {
                if (json.ok && json.data) {
                    cy.startBatch();
                    let added = false;
                    json.data.nodes.forEach(n => {
                        if (!cy.getElementById(n.id).length) {
                            const shortLabel = formatShortLabel(n.name || n.id);
                            cy.add({data: {...n, label: shortLabel, fullLabel: n.name || n.id}});
                            added = true;
                        }
                    });
                    json.data.edges.forEach(e => {
                        const baseEid = `${e.source}->${e.target}:${e.type}`;
                        // Check if this edge already exists (same source/target/type)
                        if (!cy.getElementById(baseEid).length && !addedEdgeIds.has(baseEid)) {
                            const eid = getUniqueEdgeId(e);
                            cy.add({data: {...e, id: eid}});
                            added = true;
                        }
                    });
                    cy.endBatch();
                    if (added) {
                        runLayout(currentLayout);
                        // Re-highlight
                        setTimeout(() => {
                            const n2 = cy.getElementById(node.id());
                            openPanel(n2.data());
                        }, 300);
                    }
                }
            });
    });

    // ── Right-click for SubGraph ──
    cy.on('cxttap', 'node', function (evt) {
        evt.preventDefault();
        const node = evt.target;
        enterSubGraph(node);
    });

    // Tap canvas to clear
    cy.on('tap', function (evt) {
        if (evt.target === cy) closePanel();
    });

    // ── Tooltip ──
    const tip = document.getElementById('tip');
    cy.on('mouseover', 'node', function (evt) {
        const d = evt.target.data();
        const fullName = d.fullLabel || d.label || d.id;
        tip.innerHTML = `<strong>${d.label}</strong><br><span style="color:#7380a0;font-size:9px">${fullName}</span><br><span style="color:#7380a0">${d.kind}</span>`;
        tip.classList.add('show');
    });
    cy.on('mouseout', 'node', () => tip.classList.remove('show'));
    cy.on('mousemove', 'node', function (evt) {
        const p = evt.renderedPosition || evt.position;
        tip.style.left = (p.x + 15) + 'px';
        tip.style.top = (p.y + 60) + 'px';
    });

    // ── Search ──
    window.doSearch = function () {
        const q = document.getElementById('si').value.trim().toLowerCase();
        if (!q) {
            cy.nodes().removeClass('dimmed highlighted');
            return;
        }
        cy.nodes().forEach(node => {
            const match = (node.data('label') || '').toLowerCase().includes(q) || node.id().toLowerCase().includes(q);
            node.toggleClass('dimmed', !match);
            node.toggleClass('highlighted', match);
        });
        const matches = cy.nodes('.highlighted');
        if (matches.length === 1) matches[0].emit('tap');
        else if (matches.length > 0) cy.fit(matches, 50);
    };

    document.getElementById('si').addEventListener('keyup', function (e) {
        if (e.key === 'Enter') doSearch();
        if (e.key === 'Escape') {
            this.value = '';
            cy.nodes().removeClass('dimmed highlighted');
        }
    });

    // ── Keyboard Shortcuts ──
    document.addEventListener('keydown', function (e) {
        // Skip if typing in input
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        try {
            // Ctrl+K or Cmd+K - Focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                document.getElementById('si')?.focus();
                return;
            }

            // Escape - Close panel / Exit SubGraph
            if (e.key === 'Escape') {
                e.preventDefault();
                if (typeof closePanel === 'function') closePanel();
                if (sgMode && typeof exitSubGraph === 'function') exitSubGraph();
                return;
            }

            // Avoid shortcuts if any modifier except Shift is pressed (handled individually below)
            if (e.altKey) return;
            // For Mac, we want to allow Cmd for Ctrl-equivalent but not for single-letter triggers
            if (e.metaKey && e.key !== 'k') return;
            // For Windows/Linux, we want to allow Ctrl only for K
            if (e.ctrlKey && e.key !== 'k') return;

            // M - Toggle module panel
            if (e.key.toLowerCase() === 'm') {
                e.preventDefault();
                if (typeof toggleModPanel === 'function') toggleModPanel();
                return;
            }

            // T - Toggle theme
            if (e.key.toLowerCase() === 't') {
                e.preventDefault();
                if (typeof toggleTheme === 'function') toggleTheme();
                return;
            }

            // F - Fit view
            if (e.key.toLowerCase() === 'f') {
                e.preventDefault();
                if (typeof fitView === 'function') fitView();
                return;
            }

            // 1-4 - Layouts
            if (e.key === '1') {
                e.preventDefault();
                runLayout('dagre');
                return;
            }
            if (e.key === '2') {
                e.preventDefault();
                runLayout('cose');
                return;
            }
            if (e.key === '3') {
                e.preventDefault();
                runLayout('lr');
                return;
            }
            if (e.key === '4') {
                e.preventDefault();
                runLayout('compact');
                return;
            }

            // ? - Help modal
            if (e.key === '?') {
                e.preventDefault();
                const help = document.getElementById('kb-help');
                if (help) {
                    help.style.display = help.style.display === 'flex' ? 'none' : 'flex';
                }
                return;
            }

            // S - SubGraph Toggle
            if (e.key.toLowerCase() === 's') {
                e.preventDefault();
                const selected = cy.nodes(':selected');
                const highlighted = cy.nodes('.highlighted');
                const target = selected.length ? selected : (highlighted.length ? highlighted : null);
                if (target && typeof enterSubGraph === 'function') {
                    enterSubGraph(target);
                }
                return;
            }
        } catch (err) {
            console.error('[LogicMap] Keyboard shortcut error:', err);
        }
    });

    // Close help modal on click outside
    document.getElementById('kb-help')?.addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });

    // ── SubGraph Mode ──
    let sgMode = false;

    function enterSubGraph(seedNodes, depth) {
        depth = depth || parseInt(document.getElementById('sg-hops').value) || 2;
        sgLastSeed = seedNodes;

        // Store original elements if first entry
        if (!sgMode) {
            sgOriginalElements = cy.elements().jsons();
        }

        sgMode = true;

        // Collect neighborhood up to depth hops
        let neighborhood = seedNodes;
        for (let i = 0; i < depth; i++) {
            neighborhood = neighborhood.union(neighborhood.neighborhood());
        }

        cy.startBatch();
        const toRemove = cy.elements().not(neighborhood);
        toRemove.remove();
        cy.endBatch();

        runLayout(currentLayout);
        document.body.classList.add('subgraph-mode');
        document.getElementById('sg-controls').classList.add('show');
        showSgBanner();
    }

    window.rerunSubGraph = function () {
        if (!sgMode || !sgLastSeed) return;

        // Restore then re-enter
        cy.startBatch();
        cy.elements().remove();
        sgOriginalElements.forEach(el => cy.add(el));
        cy.endBatch();

        // Re-select seed
        const seedId = sgLastSeed.id ? sgLastSeed.id() : sgLastSeed[0]?.id();
        const newSeed = cy.getElementById(seedId);
        if (newSeed.length) {
            enterSubGraph(newSeed);
        }
    };

    // Slider change handler
    const sgHopsEl = document.getElementById('sg-hops');
    if (sgHopsEl) {
        sgHopsEl.addEventListener('input', function () {
            const valEl = document.getElementById('sg-hops-val');
            if (valEl) valEl.textContent = this.value;
        });
    }

    function showSgBanner() {
        let banner = document.getElementById('sg-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'sg-banner';
            banner.style = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--yellow);color:#000;padding:8px 16px;border-radius:20px;font-weight:bold;z-index:1000;display:flex;align-items:center;gap:12px;box-shadow:0 4px 12px rgba(0,0,0,0.3)';
            banner.innerHTML = `<span>SUBGRAPH MODE</span><button onclick="exitSubGraph()" style="background:#000;color:#fff;border:none;padding:4px 12px;border-radius:12px;cursor:pointer;font-size:11px">EXIT (ESC)</button>`;
            document.body.appendChild(banner);
        }
        banner.style.display = 'flex';
    }

    window.exitSubGraph = function () {
        if (!sgMode) return;
        sgMode = false;
        document.getElementById('sg-banner').style.display = 'none';
        document.getElementById('sg-controls').classList.remove('show');
        document.body.classList.remove('subgraph-mode');

        // Restore original elements without reload
        if (sgOriginalElements) {
            cy.startBatch();
            cy.elements().remove();
            sgOriginalElements.forEach(el => cy.add(el));
            cy.endBatch();
            runLayout(currentLayout);
            sgOriginalElements = null;
        }
        sgLastSeed = null;
    };

    // ── Compute Cross-Module Edges ──
    function computeCrossModuleEdges(nodes, edges) {
        crossModuleEdges = {};
        const nodeModules = {};

        // Build module map
        nodes.forEach(n => {
            const parts = (n.id || '').split('\\');
            nodeModules[n.id] = parts.length > 1 ? parts.slice(0, -1).join('\\') : '(root)';
        });

        // Count cross-module edges
        edges.forEach(e => {
            const srcMod = nodeModules[e.source];
            const tgtMod = nodeModules[e.target];
            if (srcMod && tgtMod && srcMod !== tgtMod) {
                const key = `${srcMod}>>>${tgtMod}`;
                crossModuleEdges[key] = (crossModuleEdges[key] || 0) + 1;
            }
        });

        return crossModuleEdges;
    }

    // ── Focus Module ──
    window.focusModule = function (namespace) {
        cy.nodes().removeClass('module-focus dimmed');
        cy.edges().removeClass('dimmed');

        cy.nodes().forEach(node => {
            const nodeNs = node.id().split('\\').slice(0, -1).join('\\') || '(root)';
            if (nodeNs === namespace) {
                node.addClass('module-focus');
            } else {
                node.addClass('dimmed');
            }
        });

        cy.edges().forEach(edge => {
            const src = edge.source();
            const tgt = edge.target();
            if (!src.hasClass('module-focus') && !tgt.hasClass('module-focus')) {
                edge.addClass('dimmed');
            }
        });
    };

    // ── Build Module Explorer ──
    function buildModuleExplorer(nodes, edges) {
        const groups = {};
        nodes.forEach(n => {
            const parts = (n.id || '').split('\\');
            const ns = parts.length > 1 ? parts.slice(0, -1).join('\\') : '(root)';
            if (!groups[ns]) groups[ns] = [];
            groups[ns].push(n);
        });

        // Compute cross-module connections
        computeCrossModuleEdges(nodes, edges);

        const body = document.getElementById('mod-body');
        const sorted = Object.entries(groups).sort((a, b) => b[1].length - a[1].length);

        body.innerHTML = sorted.map(([ns, items]) => {
            const color = KIND_COLORS[items[0]?.kind] || KIND_COLORS.unknown;
            const pills = items.map(n =>
                `<span class="mod-pill" onclick="focusNode('${n.id}')" title="${n.id}">${n.name || n.id.split('\\').pop()}</span>`
            ).join('');

            // Build connections HTML
            const outgoing = [];
            const incoming = [];
            Object.entries(crossModuleEdges).forEach(([key, count]) => {
                const [from, to] = key.split('>>>');
                if (from === ns) outgoing.push({mod: to.split('\\').pop(), count});
                if (to === ns) incoming.push({mod: from.split('\\').pop(), count});
            });

            let connHtml = '';
            if (outgoing.length || incoming.length) {
                connHtml = `<div class="mod-conn open">`;
                outgoing.slice(0, 3).forEach(c => {
                    connHtml += `<div class="mod-conn-row"><span class="conn-arr">→</span><span>${c.mod}</span><span class="mod-conn-cnt">${c.count}</span></div>`;
                });
                incoming.slice(0, 3).forEach(c => {
                    connHtml += `<div class="mod-conn-row"><span class="conn-arr">←</span><span>${c.mod}</span><span class="mod-conn-cnt">${c.count}</span></div>`;
                });
                connHtml += `</div>`;
            }

            return `<div class="mod-card">
                <div class="mod-hdr-row" onclick="focusModule('${ns}'); this.nextElementSibling.classList.toggle('open')">
                    <div class="mod-dot" style="background:${color.bd}"></div>
                    <span class="mod-name" title="${ns}">${ns.split('\\').pop() || ns}</span>
                    <span class="mod-cnt">${items.length}</span>
                </div>
                <div class="mod-pills">${pills}</div>
                ${connHtml}
            </div>`;
        }).join('');
    }

    // ── Filter Orphan Nodes ──
    function filterOrphanNodes(nodes, edges) {
        const hasEdge = new Set();
        edges.forEach(e => {
            hasEdge.add(e.source);
            hasEdge.add(e.target);
        });

        return nodes.filter(n => {
            // Keep routes regardless
            if (n.kind === 'route') return true;
            // Keep if has any edges
            if (hasEdge.has(n.id)) return true;
            // Keep if has metrics showing connections
            const m = n.metrics || {};
            if ((m.fan_in || 0) > 0 || (m.fan_out || 0) > 0) return true;
            return false;
        });
    }

    // ── Detect Disconnected Components ──
    function findDisconnectedComponents(nodes, edges) {
        const nodeIds = new Set(nodes.map(n => n.id));
        const adjList = {};

        nodeIds.forEach(id => {
            adjList[id] = [];
        });

        edges.forEach(e => {
            if (nodeIds.has(e.source) && nodeIds.has(e.target)) {
                adjList[e.source].push(e.target);
                adjList[e.target].push(e.source); // undirected for component detection
            }
        });

        const visited = new Set();
        const components = [];

        function bfs(startId) {
            const component = [];
            const queue = [startId];
            visited.add(startId);

            while (queue.length > 0) {
                const id = queue.shift();
                component.push(id);

                (adjList[id] || []).forEach(neighbor => {
                    if (!visited.has(neighbor)) {
                        visited.add(neighbor);
                        queue.push(neighbor);
                    }
                });
            }
            return component;
        }

        nodeIds.forEach(id => {
            if (!visited.has(id)) {
                components.push(bfs(id));
            }
        });

        return components;
    }

    // ── Detect Cyclic Dependencies ──
    function detectCycles(nodes, edges) {
        const nodeIds = new Set(nodes.map(n => n.id));
        const adjList = {};

        nodeIds.forEach(id => {
            adjList[id] = [];
        });

        edges.forEach(e => {
            if (nodeIds.has(e.source) && nodeIds.has(e.target)) {
                adjList[e.source].push(e.target);
            }
        });

        const WHITE = 0, GRAY = 1, BLACK = 2;
        const color = {};
        const cycles = [];

        nodeIds.forEach(id => {
            color[id] = WHITE;
        });

        function dfs(id, path) {
            color[id] = GRAY;
            path.push(id);

            for (const neighbor of (adjList[id] || [])) {
                if (color[neighbor] === GRAY) {
                    // Found cycle
                    const cycleStart = path.indexOf(neighbor);
                    cycles.push(path.slice(cycleStart).concat(neighbor));
                } else if (color[neighbor] === WHITE) {
                    dfs(neighbor, path);
                }
            }

            path.pop();
            color[id] = BLACK;
        }

        nodeIds.forEach(id => {
            if (color[id] === WHITE) {
                dfs(id, []);
            }
        });

        return cycles;
    }

    // ── Show Empty State ──
    function showEmptyState() {
        const loading = document.getElementById('loading');
        loading.innerHTML = `
            <div class="ld-logo">Logic Map</div>
            <div style="margin-top:20px;text-align:center">
                <div style="font-size:40px;margin-bottom:16px">📭</div>
                <div style="font-size:13px;color:var(--tx2);margin-bottom:8px">No nodes found</div>
                <div style="font-size:11px;color:var(--tx3)">Run <code style="background:var(--bg3);padding:2px 6px;border-radius:3px">php artisan logic-map:build</code> to analyze your codebase</div>
            </div>
        `;
        loading.style.opacity = '1';
    }

    // ── Show Single Node State ──
    function showSingleNodeState(node) {
        // Just display the node, but with a helpful message
        const tip = document.createElement('div');
        tip.style = 'position:fixed;bottom:70px;left:50%;transform:translateX(-50%);background:var(--bg2);border:1px solid var(--bdr);padding:8px 16px;border-radius:8px;font-size:11px;color:var(--tx2);z-index:30';
        tip.innerHTML = '💡 Only 1 node found. Add more routes/controllers to see the logic flow.';
        document.body.appendChild(tip);
        setTimeout(() => tip.remove(), 6000);
    }

    // ── Warning message stacking ──
    let warningOffset = 0;

    function showWarning(html, bgColor, borderColor, textColor, duration = 8000) {
        const warn = document.createElement('div');
        warn.className = 'lm-warning';
        warn.style.cssText = `position:fixed;top:${56 + warningOffset}px;right:16px;background:${bgColor};border:1px solid ${borderColor};padding:8px 14px;border-radius:6px;font-size:10px;color:${textColor};z-index:30;max-width:320px;transition:opacity 0.3s`;
        warn.innerHTML = html;
        document.body.appendChild(warn);
        warningOffset += 44;

        setTimeout(() => {
            warn.style.opacity = '0';
            setTimeout(() => {
                warn.remove();
                warningOffset = Math.max(0, warningOffset - 44);
            }, 300);
        }, duration);
    }

    // ── Show Disconnected Components Warning ──
    function showDisconnectedWarning(componentCount) {
        showWarning(
            `⚠ ${componentCount} disconnected components. Some modules may not be linked.`,
            'rgba(245,158,11,.12)', '#ef7d38', '#ef7d38'
        );
    }

    // ── Show Cyclic Dependencies Warning ──
    function showCyclicWarning(cycles) {
        const firstCycle = cycles[0].map(id => formatShortLabel(id)).join(' → ');
        showWarning(
            `🔄 Cycle: <b>${firstCycle}</b>${cycles.length > 1 ? ` (+${cycles.length - 1})` : ''}`,
            'rgba(239,68,68,.12)', '#ef4444', '#ef4444', 10000
        );
    }

    // ── Format short label from full ID ──
    function formatShortLabel(id) {
        if (!id) return '';

        let result = '';

        // Handle method:Namespace\Class@method format
        if (id.includes('@')) {
            const atIndex = id.lastIndexOf('@');
            const methodName = id.substring(atIndex + 1);
            const classPart = id.substring(0, atIndex).replace(/^(method|class):/, '');
            const className = classPart.split('\\').pop();
            result = methodName; // Just show method name, class visible in tooltip
        }
        // Handle class:Namespace\Class format
        else if (id.startsWith('class:')) {
            result = id.replace('class:', '').split('\\').pop();
        }
        // Handle route:VERB /path format
        else if (id.startsWith('route:')) {
            result = id.replace('route:', '');
        }
        // Handle method: without @ (shouldn't happen but just in case)
        else if (id.startsWith('method:')) {
            const clean = id.replace('method:', '');
            result = clean.split('\\').pop() || clean;
        }
        // Default: get last part after backslash
        else {
            const parts = id.split('\\');
            result = parts[parts.length - 1] || id;
        }

        // Remove any remaining prefixes
        result = result.replace(/^(method|class|route):/, '');

        // Truncate if still too long (max 25 chars)
        if (result.length > 25) {
            result = result.substring(0, 22) + '...';
        }

        return result;
    }

    // ── Load Data ──
    const loading = document.getElementById('loading');
    const ldMsg = document.getElementById('ld-msg');

    // Fetch health
    fetch(window.logicMapConfig.healthUrl)
        .then(r => r.json())
        .then(json => {
            if (json.ok) {
                const d = json.data;
                const hd = document.getElementById('health-display');
                hd.innerHTML = `<span class="health-badge grade-${d.grade}">${d.grade} ${d.score}/100</span>`;
            }
        }).catch(() => {
    });

    // Fetch meta
    ldMsg.textContent = 'Loading metadata…';
    fetch(window.logicMapConfig.metaUrl)
        .then(r => r.json())
        .then(json => {
            if (json.ok) {
                document.getElementById('s-nodes').textContent = json.data.node_count;
                document.getElementById('s-edges').textContent = json.data.edge_count;
            }
        });

    // Fetch overview
    ldMsg.textContent = 'Loading graph…';
    fetch(window.logicMapConfig.overviewUrl)
        .then(r => r.json())
        .then(json => {
            if (!json.ok) {
                ldMsg.textContent = 'Error: ' + json.message;
                return;
            }

            allNodesData = json.data.nodes || [];
            allEdgesData = json.data.edges || [];

            // Edge case: Empty graph
            if (allNodesData.length === 0) {
                showEmptyState();
                return;
            }

            // Filter orphan nodes
            const filteredNodes = filterOrphanNodes(allNodesData, allEdgesData);

            // Edge case: Single node
            if (filteredNodes.length === 1) {
                showSingleNodeState(filteredNodes[0]);
            }

            // Large graph warning
            if (filteredNodes.length > 150) {
                document.getElementById('lg-warning').classList.add('show');
                setTimeout(() => document.getElementById('lg-warning').classList.remove('show'), 8000);
            }

            // Add elements
            cy.startBatch();
            filteredNodes.forEach(n => {
                const shortLabel = formatShortLabel(n.name || n.id);
                cy.add({data: {...n, label: shortLabel, fullLabel: n.name || n.id}});
            });
            allEdgesData.forEach(e => {
                // Only add edge if both nodes exist
                if (cy.getElementById(e.source).length && cy.getElementById(e.target).length) {
                    const eid = getUniqueEdgeId(e);
                    cy.add({data: {...e, id: eid}});
                }
            });
            cy.endBatch();

            // Build module explorer
            buildModuleExplorer(filteredNodes, allEdgesData);

            // Detect disconnected components
            const components = findDisconnectedComponents(filteredNodes, allEdgesData);
            if (components.length > 1 && filteredNodes.length > 3) {
                showDisconnectedWarning(components.length);
            }

            // Detect cyclic dependencies
            const cycles = detectCycles(filteredNodes, allEdgesData);
            if (cycles.length > 0) {
                showCyclicWarning(cycles);
                // Highlight cyclic nodes
                cycles.forEach(cycle => {
                    cycle.forEach(id => {
                        const node = cy.getElementById(id);
                        if (node.length) {
                            node.style('border-color', '#ef4444');
                            node.style('border-width', 2);
                        }
                    });
                });
            }

            // Run layout
            ldMsg.textContent = 'Rendering layout…';
            setTimeout(() => {
                runLayout('dagre');

                // Hide loading
                loading.style.opacity = '0';
                setTimeout(() => loading.style.display = 'none', 400);
            }, 100);
        })
        .catch(err => {
            ldMsg.textContent = 'Failed to load: ' + err.message;
        });
    };

    // Initialize on DOMContentLoaded or immediately if already loaded
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})();
