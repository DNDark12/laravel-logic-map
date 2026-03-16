<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Laravel Logic Map</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.30.0/cytoscape.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/dagre@0.8.5/dist/dagre.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/cytoscape-dagre@2.5.0/cytoscape-dagre.min.js"></script>
  <style>
    :root {
      --bg: #0c0e13; --bg2: #12151f; --bg3: #181c28;
      --bdr: rgba(255,255,255,0.07); --bdr2: rgba(255,255,255,0.14);
      --tx: #d8deee; --tx2: #7380a0; --tx3: #363e58;
      --accent: #4a7ff5; --green: #24c472; --pink: #dd3585;
      --orange: #ef7d38; --yellow: #e5b535; --red: #ef4444;
      --cy-bg: #0c0e13;
    }
    [data-theme="light"] {
      --bg: #edf0f8; --bg2: #fff; --bg3: #e4e7f2;
      --bdr: rgba(0,0,0,0.07); --bdr2: rgba(0,0,0,0.14);
      --tx: #1c2036; --tx2: #46506e; --tx3: #8d97b4;
      --accent: #2455e0; --green: #0a9954; --pink: #be1d6a;
      --orange: #d66018; --yellow: #b08510; --red: #dc2626;
      --cy-bg: #edf0f8;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body,html{width:100%;height:100%;background:var(--bg);color:var(--tx);font-family:"SF Mono","Fira Code","Cascadia Code",ui-monospace,monospace;overflow:hidden;transition:background .3s,color .3s}

    /* ── Cytoscape ── */
    #cy{position:absolute;inset:0;z-index:1;background:var(--cy-bg);transition:background .3s,left .28s}

    /* ── Top Bar ── */
    #topbar{position:absolute;top:0;left:0;right:0;height:48px;background:var(--bg2);border-bottom:1px solid var(--bdr);display:flex;align-items:center;padding:0 14px;gap:8px;z-index:20}
    .logo{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:800;letter-spacing:.07em;flex-shrink:0}
    .logo svg{animation:spin-s 10s linear infinite}
    @keyframes spin-s{to{transform:rotate(360deg)}}
    .sep{width:1px;height:18px;background:var(--bdr);flex-shrink:0}

    /* Search */
    #sw{flex:1;max-width:260px;position:relative}
    #si-ico{position:absolute;left:8px;top:50%;transform:translateY(-50%);color:var(--tx3);font-size:11px;pointer-events:none}
    #si{width:100%;background:var(--bg3);border:1px solid var(--bdr);color:var(--tx);font-family:inherit;font-size:11px;padding:5px 8px 5px 24px;border-radius:5px;outline:none;transition:border-color .2s}
    #si:focus{border-color:var(--accent)}
    #si::placeholder{color:var(--tx3)}
    .tb{background:var(--bg3);border:1px solid var(--bdr);color:var(--tx2);font-family:inherit;font-size:10px;padding:4px 9px;border-radius:5px;cursor:pointer;transition:all .15s;white-space:nowrap}
    .tb:hover{border-color:var(--bdr2);color:var(--tx)}
    .tb.on{border-color:var(--accent);color:var(--accent)}

    /* Layout buttons */
    #layout-grp{display:flex;gap:3px}
    .lbtn{background:var(--bg3);border:1px solid var(--bdr);color:var(--tx3);font-family:inherit;font-size:9.5px;padding:3px 8px;border-radius:4px;cursor:pointer;transition:all .15s}
    .lbtn:hover{color:var(--tx2)}
    .lbtn.active{background:var(--accent);border-color:var(--accent);color:#fff}

    /* Theme toggle */
    #thm{width:32px;height:17px;border-radius:9px;background:var(--bg3);border:1px solid var(--bdr);cursor:pointer;position:relative;flex-shrink:0}
    #thm-t{position:absolute;top:2px;left:2px;width:11px;height:11px;border-radius:50%;background:var(--tx2);transition:transform .25s cubic-bezier(.34,1.56,.64,1),background .3s}
    [data-theme="light"] #thm-t{transform:translateX(15px);background:var(--accent)}

    /* Stats */
    #stats{margin-left:auto;display:flex;gap:10px;font-size:10px;color:var(--tx3);flex-shrink:0}
    .sv{color:var(--tx2);font-weight:700}

    /* Health badge */
    .health-badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;padding:2px 8px;border-radius:10px;letter-spacing:.05em}
    .grade-A{background:rgba(36,196,114,.15);color:var(--green)}
    .grade-B{background:rgba(36,196,114,.1);color:var(--green)}
    .grade-C{background:rgba(229,181,53,.15);color:var(--yellow)}
    .grade-D{background:rgba(239,125,56,.15);color:var(--orange)}
    .grade-F{background:rgba(239,68,68,.15);color:var(--red)}

    /* ── Legend ── */
    #legend{position:absolute;bottom:18px;left:16px;z-index:10;background:var(--bg2);border:1px solid var(--bdr);border-radius:8px;padding:10px 13px;display:flex;flex-direction:column;gap:5px;box-shadow:0 4px 16px rgba(0,0,0,.22);transition:left .28s}
    .lt{font-size:8.5px;letter-spacing:.1em;color:var(--tx3);text-transform:uppercase}
    .li{display:flex;align-items:center;gap:6px;font-size:10px;color:var(--tx2)}
    .ld{width:7px;height:7px;border-radius:50%;flex-shrink:0}

    /* ── Module Panel (left) ── */
    #mod-panel{position:absolute;top:48px;left:0;bottom:0;width:300px;background:var(--bg2);border-right:1px solid var(--bdr);z-index:12;display:flex;flex-direction:column;transform:translateX(-100%);transition:transform .28s cubic-bezier(.22,1,.36,1)}
    #mod-panel.open{transform:translateX(0)}
    #mod-hdr{padding:11px 13px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
    #mod-hdr span{font-size:11px;font-weight:800;color:var(--tx)}
    .icon-btn{width:22px;height:22px;background:var(--bg3);border:1px solid var(--bdr);border-radius:4px;color:var(--tx3);font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
    .icon-btn:hover{color:var(--tx)}
    #mod-body{flex:1;overflow-y:auto;padding:8px}
    #mod-body::-webkit-scrollbar{width:3px}
    #mod-body::-webkit-scrollbar-thumb{background:var(--bdr2);border-radius:2px}
    .mod-card{border:1px solid var(--bdr);border-radius:8px;margin-bottom:8px;overflow:hidden}
    .mod-hdr-row{padding:9px 11px;display:flex;align-items:center;gap:8px;cursor:pointer}
    .mod-hdr-row:hover{opacity:.85}
    .mod-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
    .mod-name{font-size:11px;font-weight:700;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .mod-cnt{font-size:8.5px;color:var(--tx3);background:var(--bg3);border:1px solid var(--bdr);border-radius:3px;padding:1px 5px}
    .mod-pills{padding:0 11px 8px;display:none;flex-wrap:wrap;gap:3px}
    .mod-pills.open{display:flex}
    .mod-pill{font-size:9px;color:var(--tx2);background:var(--bg3);border:1px solid var(--bdr);border-radius:10px;padding:2px 7px;cursor:pointer;transition:all .12s;white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis}
    .mod-pill:hover{color:var(--tx);border-color:var(--bdr2)}

    /* ── Detail Panel (right) ── */
    #panel{position:absolute;top:48px;right:0;bottom:0;width:375px;background:var(--bg2);border-left:1px solid var(--bdr);z-index:15;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .28s cubic-bezier(.22,1,.36,1)}
    #panel.open{transform:translateX(0)}
    #ph{padding:14px;border-bottom:1px solid var(--bdr);flex-shrink:0;position:relative}
    #p-badge{display:inline-flex;align-items:center;gap:3px;font-size:8.5px;letter-spacing:.1em;padding:2px 6px;border-radius:3px;margin-bottom:6px;text-transform:uppercase;border:1px solid}
    #p-name{font-size:14px;font-weight:800;color:var(--tx);line-height:1.25;word-break:break-word;padding-right:24px}
    #p-id{font-size:9px;color:var(--tx3);margin-top:2px}
    #p-close{position:absolute;top:12px;right:12px}
    #pbody{flex:1;overflow-y:auto;padding:0}
    #pbody::-webkit-scrollbar{width:3px}
    #pbody::-webkit-scrollbar-thumb{background:var(--bdr2);border-radius:2px}
    .ps{padding:11px 14px;border-bottom:1px solid var(--bdr)}
    .ps:last-child{border-bottom:none}
    .sl{font-size:8.5px;letter-spacing:.1em;color:var(--tx3);text-transform:uppercase;margin-bottom:8px}
    .mrow{display:flex;gap:6px;flex-wrap:wrap}
    .mc{flex:1;min-width:70px;background:var(--bg3);border:1px solid var(--bdr);border-radius:6px;padding:8px;text-align:center}
    .mv{font-size:16px;font-weight:800;color:var(--tx);line-height:1}
    .ml{font-size:8px;color:var(--tx3);margin-top:3px}
    .conn-list{display:flex;flex-direction:column;gap:2px}
    .conn-item{display:flex;align-items:center;gap:5px;font-size:10px;color:var(--tx2);padding:4px 0;border-bottom:1px solid var(--bdr);cursor:pointer;transition:opacity .15s}
    .conn-item:hover{opacity:.65}
    .conn-item:last-child{border-bottom:none}
    .conn-arr{color:var(--tx3);font-size:9px;flex-shrink:0}
    .conn-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* ── Tooltip ── */
    #tip{position:absolute;z-index:25;pointer-events:none;background:var(--bg2);border:1px solid var(--bdr);border-radius:5px;padding:5px 9px;font-size:10px;color:var(--tx2);max-width:300px;word-break:break-all;box-shadow:0 4px 16px rgba(0,0,0,.28);opacity:0;transition:opacity .12s}
    #tip.show{opacity:1}

    /* ── Loading ── */
    #loading{position:fixed;inset:0;background:var(--bg);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px;z-index:100;transition:opacity .4s}
    .ld-logo{font-size:16px;font-weight:800;color:var(--tx);letter-spacing:.05em}
    .ld-sub{font-size:10px;color:var(--tx3)}
    .ld-bw{width:150px;height:2px;background:var(--bdr);border-radius:1px;overflow:hidden}
    .ld-b{height:100%;width:0%;background:var(--accent);border-radius:1px;animation:ldp 2s ease-out forwards}
    @keyframes ldp{0%{width:0%}50%{width:60%}100%{width:100%}}

    /* Risk borders */
    .risk-critical{border:2px solid var(--red) !important}
    .risk-high{border:2px solid var(--orange) !important}
    .risk-medium{border:2px solid var(--yellow) !important}
  </style>
</head>
<body>

  <!-- Loading -->
  <div id="loading">
    <div class="ld-logo">Logic Map</div>
    <div class="ld-bw"><div class="ld-b"></div></div>
    <div class="ld-sub" id="ld-msg">Loading graph…</div>
  </div>

  <!-- Top Bar -->
  <div id="topbar">
    <div class="logo">
      <svg width="20" height="20" viewBox="0 0 20 20"><polygon points="10,1 19,5.5 19,14.5 10,19 1,14.5 1,5.5" fill="none" stroke="var(--accent)" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" fill="var(--accent)"/></svg>
      LOGIC MAP
    </div>
    <button class="tb" id="mod-toggle" title="Module Explorer (M)">☰ Modules</button>
    <div class="sep"></div>
    <div id="sw"><span id="si-ico">⌕</span><input id="si" type="text" placeholder="Search… (Ctrl+K)"></div>
    <button class="tb" onclick="doSearch()">Find</button>
    <div class="sep"></div>
    <div id="layout-grp">
      <div class="lbtn active" data-layout="dagre">Flow ↓</div>
      <div class="lbtn" data-layout="cose">Force</div>
      <div class="lbtn" data-layout="lr">LR →</div>
      <div class="lbtn" data-layout="breadthfirst">Tree</div>
    </div>
    <div class="sep"></div>
    <div id="thm" onclick="toggleTheme()" title="Toggle theme"><div id="thm-t"></div></div>
    <div id="health-display"></div>
    <div id="stats">
      <span>Nodes <span class="sv" id="s-nodes">-</span></span>
      <span>Edges <span class="sv" id="s-edges">-</span></span>
    </div>
  </div>

  <!-- Module Panel (left) -->
  <div id="mod-panel">
    <div id="mod-hdr"><span>Module Explorer</span><button class="icon-btn" onclick="toggleModPanel()">✕</button></div>
    <div id="mod-body"></div>
  </div>

  <!-- Detail Panel (right) -->
  <div id="panel">
    <div id="ph">
      <div id="p-badge"></div>
      <div id="p-name"></div>
      <div id="p-id"></div>
      <button class="icon-btn" id="p-close" onclick="closePanel()">✕</button>
    </div>
    <div id="pbody"></div>
  </div>

  <!-- Legend -->
  <div id="legend">
    <div class="lt">Node Types</div>
    <div class="li"><div class="ld" style="background:#22c55e"></div> Route</div>
    <div class="li"><div class="ld" style="background:#3b82f6"></div> Controller</div>
    <div class="li"><div class="ld" style="background:#f59e0b"></div> Service</div>
    <div class="li"><div class="ld" style="background:#a855f7"></div> Repository</div>
    <div class="li"><div class="ld" style="background:#ec4899"></div> Model</div>
    <div class="li"><div class="ld" style="background:#06b6d4"></div> Event / Job / Listener</div>
    <div class="li"><div class="ld" style="background:#6b7280"></div> Other</div>
    <div class="lt" style="margin-top:6px">Edge Types</div>
    <div class="li"><div class="ld" style="background:#22c55e"></div> Route → Controller</div>
    <div class="li"><div class="ld" style="background:#3b82f6"></div> Method Call</div>
    <div class="li"><div class="ld" style="background:#f59e0b"></div> Use / Import</div>
  </div>

  <!-- Tooltip -->
  <div id="tip"></div>

  <!-- Graph -->
  <div id="cy"></div>

  <script>
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
          { selector: 'edge.highlighted', style: {'width':2.5,'z-index':999} },
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
        name.textContent = nodeData.name || nodeData.label || nodeData.id;
        pid.textContent = nodeData.id;

        // Metrics section
        const m = nodeData.metrics || {};
        let html = `<div class="ps"><div class="sl">Metrics</div><div class="mrow">`;
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

      // ── Node Click ──
      cy.on('tap', 'node', function(evt) {
        const node = evt.target;
        cy.nodes().removeClass('highlighted neighbor dimmed');
        cy.edges().removeClass('highlighted dimmed');

        node.addClass('highlighted');
        const neighborhood = node.neighborhood();
        neighborhood.nodes().addClass('neighbor');
        neighborhood.edges().addClass('highlighted');

        // Dim non-neighbors
        cy.nodes().not(node).not(neighborhood.nodes()).addClass('dimmed');
        cy.edges().not(neighborhood.edges()).addClass('dimmed');

        openPanel(node.data());

        // Fetch subgraph for deeper connections
        fetch('{{ url("logic-map/subgraph") }}/' + encodeURIComponent(node.id()))
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
        if (e.ctrlKey && e.key === 'k') { e.preventDefault(); document.getElementById('si').focus(); }
        if (e.key === 'Escape') closePanel();
        if (e.key === 'm' && !e.ctrlKey && document.activeElement.tagName !== 'INPUT') toggleModPanel();
      });

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
      fetch('{{ route("logic-map.health") }}')
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
      fetch('{{ route("logic-map.meta") }}')
        .then(r => r.json())
        .then(json => {
          if (json.ok) {
            document.getElementById('s-nodes').textContent = json.data.node_count;
            document.getElementById('s-edges').textContent = json.data.edge_count;
          }
        });

      // Fetch overview
      ldMsg.textContent = 'Loading graph…';
      fetch('{{ route("logic-map.overview") }}')
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
  </script>
</body>
</html>
