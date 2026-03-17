<!DOCTYPE html>
<html lang="en" data-theme="dark-graphite">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laravel Logic Map</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.30.0/cytoscape.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dagre@0.8.5/dist/dagre.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/cytoscape-dagre@2.5.0/cytoscape-dagre.min.js"></script>
    <style>{!! $logicMapCss !!}</style>
    <script>
        window.logicMapConfig = {
            overviewUrl:    '{{ route("logic-map.overview") }}',
            subgraphUrl:    '{{ url("logic-map/subgraph") }}',
            healthUrl:      '{{ route("logic-map.health") }}',
            metaUrl:        '{{ route("logic-map.meta") }}',
            violationsUrl:  '{{ route("logic-map.violations") }}'
        };
    </script>
</head>
<body>

<!-- ── Loading ── -->
<div id="loading">
    <div class="ld-logo">
        <svg width="20" height="20" viewBox="0 0 20 20">
            <polygon points="10,1 19,5.5 19,14.5 10,19 1,14.5 1,5.5" fill="none" stroke="var(--accent)" stroke-width="1.5"/>
            <circle cx="10" cy="10" r="2.5" fill="var(--accent)"/>
        </svg>
        Logic Map
    </div>
    <div class="ld-bw"><div class="ld-b"></div></div>
    <div class="ld-sub" id="ld-msg">Loading graph…</div>
</div>

<!-- ══════════════════════════════════════
     TOP BAR
══════════════════════════════════════ -->
<div id="topbar">

    <!-- [1] Menu toggle -->
    <button class="tb-icon" id="mod-toggle" title="Module Explorer (M)">
        <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>

    <!-- [2] Logo -->
    <div class="logo">
        <svg width="18" height="18" viewBox="0 0 20 20">
            <polygon points="10,1 19,5.5 19,14.5 10,19 1,14.5 1,5.5" fill="none" stroke="var(--accent)" stroke-width="1.5"/>
            <circle cx="10" cy="10" r="2.5" fill="var(--accent)"/>
        </svg>
        <span class="logo-text">LOGIC MAP</span>
    </div>

    <div class="tb-sep"></div>

    <!-- [3] Search -->
    <div class="search-wrap">
        <svg class="search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="si" type="text" placeholder="Search… (⌘K)">
    </div>
    <button class="tb-btn" id="tb-find" onclick="doSearch()">Find</button>

    <div class="tb-sep"></div>

    <!-- [4] Layout -->
    <span class="tb-label-sm">Layout</span>
    <div class="seg-group" id="layout-grp">
        <button class="seg-btn active" data-layout="dagre" title="Flow ↓ (1)">
            <svg viewBox="0 0 16 16"><line x1="8" y1="1" x2="8" y2="7"/><polyline points="5,4 8,7 11,4"/><line x1="4" y1="9" x2="4" y2="15"/><line x1="8" y1="9" x2="8" y2="15"/><line x1="12" y1="9" x2="12" y2="15"/></svg>
            <span class="btn-lbl">Flow ↓</span>
        </button>
        <button class="seg-btn" data-layout="cose" title="Force (2)">
            <svg viewBox="0 0 16 16"><circle cx="8" cy="8" r="1.5" fill="currentColor"/><circle cx="3" cy="3" r="1" fill="currentColor"/><circle cx="13" cy="3" r="1" fill="currentColor"/><circle cx="3" cy="13" r="1" fill="currentColor"/><circle cx="13" cy="13" r="1" fill="currentColor"/><line x1="8" y1="8" x2="3" y2="3"/><line x1="8" y1="8" x2="13" y2="3"/><line x1="8" y1="8" x2="3" y2="13"/><line x1="8" y1="8" x2="13" y2="13"/></svg>
            <span class="btn-lbl">Force</span>
        </button>
        <button class="seg-btn" data-layout="lr" title="LR → (3)">
            <svg viewBox="0 0 16 16"><line x1="1" y1="8" x2="7" y2="8"/><polyline points="4,5 7,8 4,11"/><line x1="9" y1="4" x2="15" y2="4"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="9" y1="12" x2="15" y2="12"/></svg>
            <span class="btn-lbl">LR →</span>
        </button>
        <button class="seg-btn" data-layout="compact" title="Compact (4)">
            <svg viewBox="0 0 16 16"><rect x="1" y="1" width="5" height="4" rx="1"/><rect x="8" y="1" width="7" height="4" rx="1"/><rect x="1" y="7" width="7" height="4" rx="1"/><rect x="10" y="7" width="5" height="4" rx="1"/><rect x="4" y="13" width="8" height="2" rx="1"/></svg>
            <span class="btn-lbl">Compact</span>
        </button>
    </div>

    <div class="tb-sep"></div>

    <!-- [5] Fit / Clear -->
    <button class="tb-btn" onclick="fitView()" title="Fit (F)">
        <svg viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
        <span class="btn-lbl">Fit</span>
    </button>
    <button class="tb-btn" onclick="clearHighlight()" title="Clear selection">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span class="btn-lbl">Clear</span>
    </button>

    <div class="tb-sep"></div>

    <!-- [6] Hops -->
    <span class="tb-label-sm">Hops</span>
    <div class="seg-group" id="hl-hops-grp">
        <button class="seg-btn active" data-hops="1">1</button>
        <button class="seg-btn" data-hops="2">2</button>
        <button class="seg-btn" data-hops="3">3</button>
        <button class="seg-btn" data-hops="99">All</button>
    </div>
    <input type="hidden" id="hl-hops" value="1">

    <!-- [7] SubGraph mode indicator in topbar (just a badge, no controls) -->
    <div id="sg-badge" style="display:none;align-items:center;gap:6px;flex-shrink:0;">
        <div class="tb-sep"></div>
        <span style="font-size:9px;font-weight:800;color:var(--yellow);letter-spacing:.06em;">SUBGRAPH</span>
    </div>

    <!-- right spacer -->
    <div style="flex:1;min-width:4px;"></div>

    <!-- [9] Health (with icon) -->
    <div id="health-display">
        <button class="health-badge" onclick="openHealthPanel()" title="View Health Report">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="12" height="12">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            Health…
        </button>
    </div>

    <div class="tb-sep"></div>

    <!-- [10] Theme picker -->
    <button class="tb-icon" id="theme-btn" onclick="toggleThemePicker()" title="Change theme (T)">
        <svg id="thm-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg id="thm-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>

    <!-- [11] Help -->
    <button class="tb-icon" onclick="document.getElementById('kb-help').style.display='flex'" title="Shortcuts (?)">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </button>

    <!-- Hidden stat sinks for JS compat -->
    <span id="s-nodes" style="display:none">–</span>
    <span id="s-edges" style="display:none">–</span>
</div><!-- /#topbar -->

<!-- ── Theme Picker dropdown ── -->
<div id="theme-picker">
    <div onclick="setTheme('dark-graphite', this)" class="theme-option active" data-theme-val="dark-graphite">
        <div class="theme-dot" style="background:linear-gradient(135deg,#0F131A 50%,#1B2430 50%);"></div>
        <span>Graphite Dark</span>
        <span class="theme-check">✓</span>
    </div>
    <div onclick="setTheme('dark-indigo', this)" class="theme-option" data-theme-val="dark-indigo">
        <div class="theme-dot" style="background:linear-gradient(135deg,#0B1020 50%,#172338 50%);"></div>
        <span>Indigo Dark</span>
        <span class="theme-check">✓</span>
    </div>
    <div onclick="setTheme('dark-ops', this)" class="theme-option" data-theme-val="dark-ops">
        <div class="theme-dot" style="background:linear-gradient(135deg,#0A0C0F 50%,#171A1E 50%);"></div>
        <span>Ops Dark</span>
        <span class="theme-check">✓</span>
    </div>
    <div onclick="setTheme('light', this)" class="theme-option" data-theme-val="light">
        <div class="theme-dot" style="background:linear-gradient(135deg,#F0F2F8 50%,#FFFFFF 50%);border-color:#ccc;"></div>
        <span>Light</span>
        <span class="theme-check">✓</span>
    </div>
</div>

<!-- ── Health Panel ── -->
<div id="health-panel">
    <div id="hp-hdr">
        <div style="display:flex;align-items:center;gap:8px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span>Health Report</span>
        </div>
        <button class="icon-btn" onclick="closeHealthPanel()">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div id="hp-body"></div>
</div>

<!-- ── Module Panel ── -->
<div id="mod-panel">
    <div id="mod-hdr">
        <span>Module Explorer</span>
        <button class="icon-btn" onclick="toggleModPanel()">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div id="mod-search">
        <svg class="search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="mod-filter" type="text" placeholder="Filter modules…">
    </div>
    <div id="mod-body"></div>
</div>

<!-- ── Detail Panel ── -->
<div id="panel">
    <div id="ph">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
            <div id="p-badge"></div>
            <div id="p-risk-badge" style="display:none;font-size:8px;letter-spacing:.1em;padding:2px 7px;border-radius:3px;text-transform:uppercase;border:1px solid;"></div>
        </div>
        <div id="p-name"></div>
        <div id="p-id"></div>
        <button class="icon-btn" id="p-close" onclick="closePanel()">
            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div id="hub-warning">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13" style="flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Utility node — many callers, triggers nothing.
    </div>
    <div id="pbody"></div>
</div>

<!-- ── Large Graph Warning ── -->
<div id="lg-warning">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    Large graph (150+ nodes). Try Compact or SubGraph mode.
</div>

<!-- ── Legend (draggable + collapsible) ── -->
<div id="legend">
    <div id="legend-hdr">
        <span id="legend-title">Legend</span>
        <div class="legend-hdr-btns">
            <button class="icon-btn" id="legend-collapse-btn" onclick="toggleLegend()" title="Collapse/Expand">
                <svg id="legend-chevron" viewBox="0 0 24 24"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <button class="icon-btn" onclick="hideLegend()" title="Hide">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <div id="legend-body">
        <div class="lt">Node Types</div>
        <div class="li"><div class="ld" style="background:#22c55e"></div>Route</div>
        <div class="li"><div class="ld" style="background:#3b82f6"></div>Controller</div>
        <div class="li"><div class="ld" style="background:#f59e0b"></div>Service</div>
        <div class="li"><div class="ld" style="background:#a855f7"></div>Repository</div>
        <div class="li"><div class="ld" style="background:#ec4899"></div>Model</div>
        <div class="li"><div class="ld" style="background:#06b6d4"></div>Event / Job / Listener</div>
        <div class="li"><div class="ld" style="background:#6b7280"></div>Other</div>
        <div class="lt" style="margin-top:5px">Edge Types</div>
        <div class="li"><div class="ld" style="background:#22c55e"></div>Route → Controller</div>
        <div class="li"><div class="ld" style="background:#3b82f6"></div>Method Call</div>
        <div class="li"><div class="ld" style="background:#f59e0b"></div>Use / Import</div>
    </div>
</div>

<!-- Legend restore button -->
<button id="legend-restore" onclick="showLegend()">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
    Legend
</button>

<!-- ── Tooltip ── -->
<div id="tip"></div>

<!-- ── Keyboard Help ── -->
<div id="kb-help">
    <div class="kb-modal">
        <div class="kb-hdr">
            <span>Keyboard Shortcuts</span>
            <button class="icon-btn" onclick="document.getElementById('kb-help').style.display='none'">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="kb-grid">
            <kbd>1–4</kbd><span>Switch layouts</span>
            <kbd>F</kbd><span>Fit graph to view</span>
            <kbd>S</kbd><span>SubGraph — or click "Explore SubGraph" in panel</span>
            <kbd>M</kbd><span>Toggle Module Explorer</span>
            <kbd>T</kbd><span>Theme picker</span>
            <kbd>⌘K</kbd><span>Focus search</span>
            <kbd>ESC</kbd><span>Close panel / Exit SubGraph</span>
            <kbd>?</kbd><span>Show this help</span>
        </div>
        <div class="kb-mouse">
            <strong>Mouse:</strong> Left-click to inspect · Right-click or panel button for SubGraph · Scroll to zoom
        </div>
    </div>
</div>

<!-- ── SubGraph floating controls (shown in subgraph mode) ── -->
<div id="sg-controls-bar">
    <div class="sgc-inner">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13">
            <circle cx="12" cy="12" r="3"/><path d="M19.07 4.93A10 10 0 0 1 21 12M4.93 19.07A10 10 0 0 1 3 12M12 3a10 10 0 0 1 7.07 2.93M12 21a10 10 0 0 1-7.07-2.93"/>
        </svg>
        <span class="sgc-label">Subgraph</span>
        <div class="sgc-sep"></div>
        <span class="sgc-lbl-sm">Depth</span>
        <div class="sgc-depth-grp" id="sg-depth-grp">
            <button class="sgc-depth-btn active" data-depth="1">1</button>
            <button class="sgc-depth-btn" data-depth="2">2</button>
            <button class="sgc-depth-btn" data-depth="3">3</button>
            <button class="sgc-depth-btn" data-depth="99">All</button>
        </div>
        <!-- hidden input keeps value for JS -->
        <input type="hidden" id="sg-hops" value="2">
        <button class="sgc-btn" onclick="rerunSubGraph()" title="Re-fetch with current depth">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
            Re-run
        </button>
        <button class="sgc-exit" onclick="exitSubGraph()" title="Exit SubGraph (ESC)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
            Exit
        </button>
    </div>
</div>

<!-- ── Graph Canvas ── -->
<div id="cy"></div>

<script>{!! $logicMapJs !!}</script>
</body>
</html>