<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laravel Logic Map</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.30.0/cytoscape.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dagre@0.8.5/dist/dagre.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/cytoscape-dagre@2.5.0/cytoscape-dagre.min.js"></script>

    <style>
        {!! $logicMapCss !!}
    </style>

    <script>
        window.logicMapConfig = {
            overviewUrl: '{{ route("logic-map.overview") }}',
            subgraphUrl: '{{ url("logic-map/subgraph") }}',
            healthUrl: '{{ route("logic-map.health") }}',
            metaUrl: '{{ route("logic-map.meta") }}'
        };
    </script>
</head>
<body>

<!-- Loading -->
<div id="loading">
    <div class="ld-logo">Logic Map</div>
    <div class="ld-bw">
        <div class="ld-b"></div>
    </div>
    <div class="ld-sub" id="ld-msg">Loading graph…</div>
</div>

<!-- Top Bar -->
<div id="topbar">
    <div class="logo">
        <svg width="20" height="20" viewBox="0 0 20 20">
            <polygon points="10,1 19,5.5 19,14.5 10,19 1,14.5 1,5.5" fill="none" stroke="var(--accent)"
                     stroke-width="1.5"/>
            <circle cx="10" cy="10" r="2.5" fill="var(--accent)"/>
        </svg>
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
        <div class="lbtn" data-layout="compact">Compact</div>
    </div>
    <div class="sep"></div>
    <button class="tb" onclick="fitView()" title="Fit to view (F)">Fit</button>
    <button class="tb" onclick="clearHighlight()" title="Clear highlights">Clear</button>
    <div id="sg-controls">
        <label>Depth: <input type="range" id="sg-hops" min="1" max="5" value="2"><span id="sg-hops-val">2</span></label>
        <button class="tb" onclick="rerunSubGraph()">Re-run</button>
    </div>
    <div class="sep"></div>
    <div id="thm" onclick="toggleTheme()" title="Toggle theme">
        <div id="thm-t"></div>
    </div>
    <button class="tb" onclick="document.getElementById('kb-help').style.display='flex'" title="Keyboard shortcuts (?)">
        ?
    </button>
    <div id="health-display"></div>
    <div id="stats">
        <span>Nodes <span class="sv" id="s-nodes">-</span></span>
        <span>Edges <span class="sv" id="s-edges">-</span></span>
    </div>
</div>

<!-- Module Panel (left) -->
<div id="mod-panel">
    <div id="mod-hdr"><span>Module Explorer</span>
        <button class="icon-btn" onclick="toggleModPanel()">✕</button>
    </div>
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
    <div id="hub-warning">⚠ Utility node — called by many but triggers nothing. Consider filtering if not relevant.
    </div>
    <div id="pbody"></div>
</div>

<!-- Large Graph Warning -->
<div id="lg-warning">⚠ Large graph (150+ nodes). Consider using SubGraph mode or Compact layout for better
    performance.
</div>

<!-- Legend -->
<div id="legend">
    <div class="lt">Node Types</div>
    <div class="li">
        <div class="ld" style="background:#22c55e"></div>
        Route
    </div>
    <div class="li">
        <div class="ld" style="background:#3b82f6"></div>
        Controller
    </div>
    <div class="li">
        <div class="ld" style="background:#f59e0b"></div>
        Service
    </div>
    <div class="li">
        <div class="ld" style="background:#a855f7"></div>
        Repository
    </div>
    <div class="li">
        <div class="ld" style="background:#ec4899"></div>
        Model
    </div>
    <div class="li">
        <div class="ld" style="background:#06b6d4"></div>
        Event / Job / Listener
    </div>
    <div class="li">
        <div class="ld" style="background:#6b7280"></div>
        Other
    </div>
    <div class="lt" style="margin-top:6px">Edge Types</div>
    <div class="li">
        <div class="ld" style="background:#22c55e"></div>
        Route → Controller
    </div>
    <div class="li">
        <div class="ld" style="background:#3b82f6"></div>
        Method Call
    </div>
    <div class="li">
        <div class="ld" style="background:#f59e0b"></div>
        Use / Import
    </div>
</div>

<!-- Tooltip -->
<div id="tip"></div>

<!-- Keyboard Shortcuts Help -->
<div id="kb-help"
     style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;display:none;align-items:center;justify-content:center">
    <div
        style="background:var(--bg2);border:1px solid var(--bdr);border-radius:12px;padding:20px 28px;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.4)">
        <div
            style="font-size:13px;font-weight:800;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center">
            <span>Keyboard Shortcuts</span>
            <button onclick="document.getElementById('kb-help').style.display='none'"
                    style="background:none;border:none;color:var(--tx3);cursor:pointer;font-size:16px">✕
            </button>
        </div>
        <div style="display:grid;grid-template-columns:60px 1fr;gap:8px 16px;font-size:11px">
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">1-4</kbd><span
                style="color:var(--tx2)">Switch layouts (Flow/Force/LR/Compact)</span>
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">F</kbd><span
                style="color:var(--tx2)">Fit graph to view</span>
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">S</kbd><span
                style="color:var(--tx2)">Enter SubGraph mode (node selected)</span>
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">M</kbd><span
                style="color:var(--tx2)">Toggle Module Explorer</span>
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">T</kbd><span
                style="color:var(--tx2)">Toggle dark/light theme</span>
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">Ctrl+K</kbd><span
                style="color:var(--tx2)">Focus search box</span>
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">ESC</kbd><span
                style="color:var(--tx2)">Close panel / Exit SubGraph</span>
            <kbd
                style="background:var(--bg3);border:1px solid var(--bdr);padding:2px 6px;border-radius:3px;text-align:center">?</kbd><span
                style="color:var(--tx2)">Show this help</span>
        </div>
        <div style="margin-top:16px;font-size:10px;color:var(--tx3)">
            <strong>Mouse:</strong> Left-click node to view details • Right-click to enter SubGraph • Scroll to zoom
        </div>
    </div>
</div>

<!-- Graph -->
<div id="cy"></div>

<script>
    {!! $logicMapJs !!}
</script>
</body>
</html>
