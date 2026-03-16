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
    {!! file_get_contents(__DIR__ . '/../css/logic-map.css') !!}
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
    {!! file_get_contents(__DIR__ . '/../js/logic-map.js') !!}
  </script>
</body>
</html>
