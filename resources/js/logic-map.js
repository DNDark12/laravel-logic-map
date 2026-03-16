document.addEventListener('DOMContentLoaded', function() {
    cytoscape.use(cytoscapeDagre);

    const KIND_COLORS = {
        route:      {bg:'#dcfce7',bd:'#22c55e'},
        controller: {bg:'#dbeafe',bd:'#3b82f6'},
        service:    {bg:'#fef3c7',bd:'#f59e0b'},
        repository: {bg:'#f3e8ff',bd:'#a855f7'},
        model:      {bg:'#fce7f3',bd:'#ec4899'},
        event:      {bg:'#cffafe',bd:'#06b6d4'},
        job:        {bg:'#cffafe',bd:'#06b6d4'},
        listener:   {bg:'#cffafe',bd:'#06b6d4'},
        command:    {bg:'#e0e7ff',bd:'#6366f1'},
        component:  {bg:'#fef3c7',bd:'#eab308'},
        unknown:    {bg:'#f3f4f6',bd:'#6b7280'}
    };

    let currentLayout = 'dagre';
    let allNodesData = [];
    let allEdgesData = [];

    // ── Cytoscape Init ──
    const cy = cytoscape({
        container: document.getElementById('cy'),
        style: [
            { selector: 'node', style: {
                'background-color': '#f3f4f6', 'label': 'data(label)', 'color': 'var(--tx)',
                'font-size': '9px', 'font-family': '"SF Mono","Fira Code",monospace',
                'text-valign': 'center', 'text-halign': 'center',
                'border-width': 1, 'border-color': '#9ca3af',
                'padding': '6px', 'shape': 'round-rectangle',
                'width': 'label', 'height': 'label',
                'text-max-width': '160px', 'text-wrap': 'ellipsis',
                'min-zoomed-font-size': 6
            }},
            { selector: 'node[kind="route"]', style: {'background-color':'#dcfce7','border-color':'#22c55e'} },
            { selector: 'node[kind="controller"]', style: {'background-color':'#dbeafe','border-color':'#3b82f6'} },
            { selector: 'node[kind="service"]', style: {'background-color':'#fef3c7','border-color':'#f59e0b'} },
            { selector: 'node[kind="repository"]', style: {'background-color':'#f3e8ff','border-color':'#a855f7'} },
            { selector: 'node[kind="model"]', style: {'background-color':'#fce7f3','border-color':'#ec4899'} },
            { selector: 'node[kind="event"]', style: {'background-color':'#cffafe','border-color':'#06b6d4'} },
            { selector: 'node[kind="job"]', style: {'background-color':'#cffafe','border-color':'#06b6d4'} },
            { selector: 'node[kind="listener"]', style: {'background-color':'#cffafe','border-color':'#06b6d4'} },
            { selector: 'node[kind="command"]', style: {'background-color':'#e0e7ff','border-color':'#6366f1'} },
            { selector: 'node.highlighted', style: {'border-width':3,'border-color':'var(--pink)','z-index':999} },
            { selector: 'node.neighbor', style: {'border-width':2,'border-color':'var(--accent)'} },
            { selector: 'node.dimmed', style: {'opacity':0.15} },
            { selector: 'edge', style: {
                'width': 1.5, 'line-color': 'rgba(156,163,175,0.4)',
                'target-arrow-color': 'rgba(156,163,175,0.4)',
                'target-arrow-shape': 'triangle', 'arrow-scale': 0.8,
                'curve-style': 'bezier'
            }},
            { selector: 'edge[type="route_to_controller"]', style: {'line-color':'#22c55e','target-arrow-color':'#22c55e'} },
            { selector: 'edge[type="call"]', style: {'line-color':'rgba(59,130,246,0.6)','target-arrow-color':'rgba(59,130,246,0.6)'} },
            { selector: 'edge[type="use"]', style: {'line-color':'rgba(245,158,11,0.5)','target-arrow-color':'rgba(245,158,11,0.5)','line-style':'dashed'} },
            { selector: 'edge.highlighted', style: {
                'width': 2.5, 
                'z-index': 999,
                'line-style': 'dashed',
                'line-dash-pattern': [8, 4],
                'line-dash-offset': 0
            }},
            { selector: 'edge.dimmed', style: {'opacity':0.08} },
        ],
        layout: { name: 'preset' },
        minZoom: 0.1, maxZoom: 4, wheelSensitivity: 0.3
    });

    // ── Layout Configs ──
    function getLayoutOpts(name) {
        switch(name) {
            case 'dagre': return { name:'dagre', rankDir:'TB', nodeSep:30, rankSep:60, edgeSep:15 };
            case 'cose': return { name:'cose', idealEdgeLength:100, nodeRepulsion:8000, animate:false };
            case 'lr': return { name:'dagre', rankDir:'LR', nodeSep:30, rankSep:80, edgeSep:15 };
            case 'breadthfirst': return { name:'breadthfirst', directed:true, spacingFactor:1.2, avoidOverlap:true };
            default: return { name:'dagre', rankDir:'TB', nodeSep:30, rankSep:60 };
        }
    }

    function runLayout(name) {
        currentLayout = name;
        document.querySelectorAll('.lbtn').forEach(b => b.classList.toggle('active', b.dataset.layout === name));
        cy.layout(getLayoutOpts(name)).run();
    }

    document.querySelectorAll('.lbtn').forEach(btn => {
        btn.addEventListener('click', () => runLayout(btn.dataset.layout));
    });

    // ── Theme ──
    window.toggleTheme = function() {
        const html = document.documentElement;
        html.dataset.theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
    };

    // ── Module Panel ──
    window.toggleModPanel = function() {
        const panel = document.getElementById('mod-panel');
        panel.classList.toggle('open');
        const cy$ = document.getElementById('cy');
        cy$.style.left = panel.classList.contains('open') ? '300px' : '0';
        document.getElementById('legend').style.left = panel.classList.contains('open') ? '316px' : '16px';
    };

    document.getElementById('mod-toggle').addEventListener('click', toggleModPanel);

    // ── Detail Panel ──
    function openPanel(nodeData) {
        const panel = document.getElementById('panel');
        const badge = document.getElementById('p-badge');
        const name = document.getElementById('p-name');
        const pid = document.getElementById('p-id');
        const body = document.getElementById('pbody');

        const kind = nodeData.kind || 'unknown';
        const kc = KIND_COLORS[kind] || KIND_COLORS.unknown;
        badge.style.color = kc.bd;
        badge.style.borderColor = kc.bd;
        badge.textContent = kind.toUpperCase();
        name.textContent = nodeData.metadata?.shortLabel || nodeData.name || nodeData.label || nodeData.id;
        pid.textContent = nodeData.id;

        let html = '';

        // Intent / What happens
        const meta = nodeData.metadata || {};
        if (meta.action || meta.domain || meta.result || meta.trigger) {
            html += `<div class="ps" style="background:var(--bg2)"><div class="sl">Business Intent</div>`;
            html += `<div style="font-size:11px; margin-bottom: 4px; color:var(--tx2)"><b>Trigger:</b> ${meta.trigger || '-'}</div>`;
            html += `<div style="font-size:11px; margin-bottom: 4px; color:var(--tx2)"><b>Action:</b> <strong style="color:var(--tx)">${meta.action || '-'}</strong> ${meta.domain || ''}</div>`;
            html += `<div style="font-size:11px; margin-bottom: 4px; color:var(--tx2)"><b>Result:</b> ${meta.result || '-'}</div>`;
            html += `</div>`;
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

        // Connections
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

    window.closePanel = function() {
        document.getElementById('panel').classList.remove('open');
        stopFlow();
        cy.nodes().removeClass('highlighted neighbor dimmed');
        cy.edges().removeClass('highlighted dimmed');
    };

    window.focusNode = function(id) {
        const node = cy.getElementById(id);
        if (node.length) {
            cy.animate({ center: { eles: node }, zoom: 1.5 }, { duration: 400 });
            node.emit('tap');
        }
    };

    // ── Edge Flow Animation ──
    let raf = null, doff = 0;
    function startFlow() {
        if (raf) return;
        function tick() {
            const hlEdges = cy.edges('.highlighted');
            if (!hlEdges.length) { raf = null; doff = 0; return; }
            doff -= 1.5;
            hlEdges.style('line-dash-offset', doff);
            raf = requestAnimationFrame(tick);
        }
        raf = requestAnimationFrame(tick);
    }
    function stopFlow() {
        if (raf) { cancelAnimationFrame(raf); raf = null; doff = 0; }
    }

    // ── Node Click ──
    cy.on('tap', 'node', function(evt) {
        const node = evt.target;
        stopFlow();
        cy.nodes().removeClass('highlighted neighbor dimmed');
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
                    let added = false;
                    json.data.nodes.forEach(n => {
                        if (!cy.getElementById(n.id).length) {
                            cy.add({ data: { ...n, label: n.name || n.id } });
                            added = true;
                        }
                    });
                    json.data.edges.forEach(e => {
                        const eid = `${e.source}->${e.target}:${e.type}`;
                        if (!cy.getElementById(eid).length) {
                            cy.add({ data: { ...e, id: eid } });
                            added = true;
                        }
                    });
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

    // Tap canvas to clear
    cy.on('tap', function(evt) {
        if (evt.target === cy) closePanel();
    });

    // ── Tooltip ──
    const tip = document.getElementById('tip');
    cy.on('mouseover', 'node', function(evt) {
        const d = evt.target.data();
        tip.innerHTML = `<strong>${d.label || d.id}</strong><br><span style="color:var(--tx3)">${d.kind}</span>`;
        tip.classList.add('show');
    });
    cy.on('mouseout', 'node', () => tip.classList.remove('show'));
    cy.on('mousemove', 'node', function(evt) {
        const p = evt.renderedPosition || evt.position;
        tip.style.left = (p.x + 15) + 'px';
        tip.style.top = (p.y + 60) + 'px';
    });

    // ── Search ──
    window.doSearch = function() {
        const q = document.getElementById('si').value.trim().toLowerCase();
        if (!q) { cy.nodes().removeClass('dimmed highlighted'); return; }
        cy.nodes().forEach(node => {
            const match = (node.data('label') || '').toLowerCase().includes(q) || node.id().toLowerCase().includes(q);
            node.toggleClass('dimmed', !match);
            node.toggleClass('highlighted', match);
        });
        const matches = cy.nodes('.highlighted');
        if (matches.length === 1) matches[0].emit('tap');
        else if (matches.length > 0) cy.fit(matches, 50);
    };

    document.getElementById('si').addEventListener('keyup', function(e) {
        if (e.key === 'Enter') doSearch();
        if (e.key === 'Escape') { this.value = ''; cy.nodes().removeClass('dimmed highlighted'); }
    });

    // ── Keyboard Shortcuts ──
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        if (e.ctrlKey && e.key === 'k') { e.preventDefault(); document.getElementById('si').focus(); }
        if (e.key === 'Escape') closePanel();
        if (e.key === 'm') { e.preventDefault(); toggleModPanel(); }
        if (e.key === 't') { e.preventDefault(); toggleTheme(); }
        if (e.key === 'f') { e.preventDefault(); cy.animate({ fit: { padding: 60 } }, { duration: 500 }); }

        // Layouts
        if (e.key === '1') { e.preventDefault(); runLayout('dagre'); }
        if (e.key === '2') { e.preventDefault(); runLayout('cose'); }
        if (e.key === '3') { e.preventDefault(); runLayout('lr'); }
        if (e.key === '4') { e.preventDefault(); runLayout('breadthfirst'); }

        // SubGraph Toggle
        if (e.key === 's') {
            e.preventDefault();
            const selected = cy.nodes(':selected');
            if (selected.length) {
                enterSubGraph(selected);
            } else {
                console.log('Select nodes first to enter SubGraph mode');
            }
        }
    });

    // ── SubGraph Mode ──
    let sgMode = false;
    function enterSubGraph(nodes) {
        sgMode = true;
        const neighborhood = nodes.union(nodes.neighborhood());
        const toRemove = cy.elements().not(neighborhood);
        toRemove.remove();
        runLayout(currentLayout);
        document.body.classList.add('subgraph-mode');
        // Add a temporary exit button or banner if not present
        showSgBanner();
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

    window.exitSubGraph = function() {
        if (!sgMode) return;
        sgMode = false;
        document.getElementById('sg-banner').style.display = 'none';
        // Reload full graph
        location.reload(); 
    };

    // ── Build Module Explorer ──
    function buildModuleExplorer(nodes) {
        const groups = {};
        nodes.forEach(n => {
            const parts = (n.id || '').split('\\');
            const ns = parts.length > 1 ? parts.slice(0, -1).join('\\') : '(root)';
            if (!groups[ns]) groups[ns] = [];
            groups[ns].push(n);
        });

        const body = document.getElementById('mod-body');
        const sorted = Object.entries(groups).sort((a, b) => b[1].length - a[1].length);

        body.innerHTML = sorted.map(([ns, items]) => {
            const color = KIND_COLORS[items[0]?.kind] || KIND_COLORS.unknown;
            const pills = items.map(n =>
                `<span class="mod-pill" onclick="focusNode('${n.id}')" title="${n.id}">${n.name || n.id.split('\\').pop()}</span>`
            ).join('');
            return `<div class="mod-card">
                <div class="mod-hdr-row" onclick="this.nextElementSibling.classList.toggle('open')">
                    <div class="mod-dot" style="background:${color.bd}"></div>
                    <span class="mod-name" title="${ns}">${ns.split('\\').pop() || ns}</span>
                    <span class="mod-cnt">${items.length}</span>
                </div>
                <div class="mod-pills">${pills}</div>
            </div>`;
        }).join('');
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
        }).catch(() => {});

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
            if (!json.ok) { ldMsg.textContent = 'Error: ' + json.message; return; }

            allNodesData = json.data.nodes;
            allEdgesData = json.data.edges;

            // Add elements
            json.data.nodes.forEach(n => {
                cy.add({ data: { ...n, label: n.name || n.id } });
            });
            json.data.edges.forEach(e => {
                const eid = `${e.source}->${e.target}:${e.type}`;
                cy.add({ data: { ...e, id: eid } });
            });

            // Build module explorer
            buildModuleExplorer(json.data.nodes);

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
});
