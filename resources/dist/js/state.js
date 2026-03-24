// ═══════════════════════════════════════════════════════
// shared state & utilities
// ═══════════════════════════════════════════════════════

export const S = {
    cy: null,
    currentLayout: 'graph',
    allNodesData: [],
    allEdgesData: [],
    sgOriginalElements: null,
    sgLastSeed: null,
    sgMode: false,
    sgBusy: false,
    sgRequestSerial: 0,
    sgActionCooldownUntil: 0,
    sgCooldownTimer: null,
    crossModuleEdges: {},
    selectedSnapshot: new URLSearchParams(window.location.search).get('snapshot') || null,
    heatmapEnabled: false,
    layoutBusy: false,
    pendingLayoutRequest: null,
    fitBusy: false,
    activePanelNodeId: null,
    panelState: 'hidden',
    panelRestoreState: 'expanded',
    
    _zonesOriginalElements: null,
    _zonesSubnodes: null,
    _hlTimeout: null,
    _modeSwitchRestore: null
};

export const KIND_LABELS = {
    route: 'Routes', controller: 'Controllers', service: 'Services',
    repository: 'Repositories', model: 'Models', event: 'Events',
    job: 'Jobs', listener: 'Listeners', command: 'Commands',
    component: 'Components', action: 'Actions', helper: 'Helpers', 
    observer: 'Observers', policy: 'Policies', middleware: 'Middleware', 
    rule: 'Rules', exception: 'Exceptions', provider: 'Providers', 
    resource: 'Resources', console: 'Console', unknown: 'Other'
};

export const KIND_ORDER = [
    'route', 'controller', 'middleware', 'action', 'service', 'job', 
    'event', 'listener', 'observer', 'repository', 'model', 'policy', 
    'rule', 'resource', 'provider', 'console', 'command', 'helper', 
    'exception', 'component', 'unknown'
];

export const SG_ACTION_COOLDOWN_MS = 1200;
export const SG_STALE_COOLDOWN_MS = 600;

export let KIND_COLORS = {};
export let RISK_COLORS = {};
export let THEME_TOKENS = {};

export function cssVar(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

export function readThemeTokens() {
    return {
        canvas: cssVar('--bg-canvas', '#181E28'),
        text: cssVar('--tx', '#E8EDF5'),
        accent: cssVar('--accent', '#5080E8'),
        nodeDefaultBg: cssVar('--kind-unknown-bg', '#f3f4f6'),
        nodeDefaultBorder: cssVar('--kind-unknown-bd', '#6b7280'),
        nodeDefaultText: cssVar('--node-text', '#1c2036'),
        kindColors: {
            route: { bg: cssVar('--kind-route-bg', '#dcfce7'), bd: cssVar('--kind-route-bd', '#22c55e') },
            controller: { bg: cssVar('--kind-controller-bg', '#dbeafe'), bd: cssVar('--kind-controller-bd', '#3b82f6') },
            service: { bg: cssVar('--kind-service-bg', '#fef3c7'), bd: cssVar('--kind-service-bd', '#f59e0b') },
            repository: { bg: cssVar('--kind-repository-bg', '#f3e8ff'), bd: cssVar('--kind-repository-bd', '#a855f7') },
            model: { bg: cssVar('--kind-model-bg', '#fce7f3'), bd: cssVar('--kind-model-bd', '#ec4899') },
            event: { bg: cssVar('--kind-event-bg', '#cffafe'), bd: cssVar('--kind-event-bd', '#06b6d4') },
            job: { bg: cssVar('--kind-job-bg', '#cffafe'), bd: cssVar('--kind-job-bd', '#06b6d4') },
            listener: { bg: cssVar('--kind-listener-bg', '#cffafe'), bd: cssVar('--kind-listener-bd', '#06b6d4') },
            command: { bg: cssVar('--kind-command-bg', '#e0e7ff'), bd: cssVar('--kind-command-bd', '#6366f1') },
            component: { bg: cssVar('--kind-component-bg', '#fef3c7'), bd: cssVar('--kind-component-bd', '#eab308') },
            action: { bg: cssVar('--kind-service-bg', '#fef3c7'), bd: cssVar('--kind-service-bd', '#f59e0b') },
            helper: { bg: cssVar('--kind-unknown-bg', '#f3f4f6'), bd: cssVar('--kind-unknown-bd', '#6b7280') },
            observer: { bg: cssVar('--kind-event-bg', '#cffafe'), bd: cssVar('--kind-event-bd', '#06b6d4') },
            policy: { bg: cssVar('--kind-controller-bg', '#dbeafe'), bd: cssVar('--kind-controller-bd', '#3b82f6') },
            middleware: { bg: cssVar('--kind-controller-bg', '#dbeafe'), bd: cssVar('--kind-controller-bd', '#3b82f6') },
            rule: { bg: cssVar('--kind-repository-bg', '#f3e8ff'), bd: cssVar('--kind-repository-bd', '#a855f7') },
            exception: { bg: cssVar('--kind-model-bg', '#fce7f3'), bd: cssVar('--kind-model-bd', '#ec4899') },
            provider: { bg: cssVar('--kind-component-bg', '#fef3c7'), bd: cssVar('--kind-component-bd', '#eab308') },
            resource: { bg: cssVar('--kind-controller-bg', '#dbeafe'), bd: cssVar('--kind-controller-bd', '#3b82f6') },
            console: { bg: cssVar('--kind-command-bg', '#e0e7ff'), bd: cssVar('--kind-command-bd', '#6366f1') },
            unknown: { bg: cssVar('--kind-unknown-bg', '#f3f4f6'), bd: cssVar('--kind-unknown-bd', '#6b7280') }
        },
        riskColors: {
            critical: cssVar('--risk-critical', '#ef4444'),
            high: cssVar('--risk-high', '#f97316'),
            medium: cssVar('--risk-medium', '#eab308'),
            low: cssVar('--risk-low', '#22c55e'),
            healthy: ''
        },
        edge: {
            base: cssVar('--edge-default', 'rgba(156,163,175,0.4)'),
            route: cssVar('--edge-route', '#22c55e'),
            call: cssVar('--edge-call', 'rgba(59,130,246,0.6)'),
            use: cssVar('--edge-use', 'rgba(245,158,11,0.5)'),
            highlight: cssVar('--edge-highlight', '#4a7ff5')
        },
        heatmap: {
            0: { bg: cssVar('--heat-0-bg', '#dbeafe'), bd: cssVar('--heat-0-bd', '#93c5fd'), tx: cssVar('--heat-0-tx', '#1e293b') },
            1: { bg: cssVar('--heat-1-bg', '#fef3c7'), bd: cssVar('--heat-1-bd', '#facc15'), tx: cssVar('--heat-1-tx', '#3f2a0d') },
            2: { bg: cssVar('--heat-2-bg', '#fdba74'), bd: cssVar('--heat-2-bd', '#fb923c'), tx: cssVar('--heat-2-tx', '#3f1d06') },
            3: { bg: cssVar('--heat-3-bg', '#f97316'), bd: cssVar('--heat-3-bd', '#ea580c'), tx: cssVar('--heat-3-tx', '#ffffff') },
            4: { bg: cssVar('--heat-4-bg', '#ef4444'), bd: cssVar('--heat-4-bd', '#dc2626'), tx: cssVar('--heat-4-tx', '#ffffff') }
        }
    };
}

export function syncThemeTokens() {
    THEME_TOKENS = readThemeTokens();
    KIND_COLORS = THEME_TOKENS.kindColors;
    RISK_COLORS = THEME_TOKENS.riskColors;
}

export function buildCyStyle() {
    const tokens = THEME_TOKENS;
    const style = [
        {
            selector: 'node', style: {
                'background-color': tokens.nodeDefaultBg, 'label': 'data(label)', 'color': tokens.nodeDefaultText,
                'font-size': '11px', 'font-family': 'system-ui,-apple-system,sans-serif',
                'font-weight': 'bold', 'text-valign': 'center', 'text-halign': 'center',
                'border-width': 1, 'border-color': tokens.nodeDefaultBorder, 'padding': '10px',
                'shape': 'round-rectangle', 'width': '160px', 'height': '55px',
                'text-max-width': '150px', 'text-wrap': 'wrap', 'line-height': 1.2,
                'text-overflow-wrap': 'anywhere', 'min-zoomed-font-size': 6
            }
        },
        { selector: 'node[risk="critical"]', style: { 'border-color': tokens.riskColors.critical, 'border-width': 2 } },
        { selector: 'node[risk="high"]', style: { 'border-color': tokens.riskColors.high, 'border-width': 2 } },
        { selector: 'node[risk="medium"]', style: { 'border-color': tokens.riskColors.medium, 'border-width': 1.5 } },

        { 
            selector: 'node.highlighted', 
            style: { 
                'border-width': 4, 
                'border-color': tokens.accent, 
                'underlay-color': tokens.accent,
                'underlay-padding': 10,
                'underlay-opacity': 0.35,
                'z-index': 999 
            } 
        },
        { 
            selector: 'node.neighbor', 
            style: { 
                'border-width': 3, 
                'border-color': tokens.edge.highlight,
                'underlay-color': tokens.edge.highlight,
                'underlay-padding': 5,
                'underlay-opacity': 0.15,
                'z-index': 998
            } 
        },
        { selector: 'node.dimmed', style: { 'opacity': 0.08, 'events': 'no' } },
        { selector: 'node.module-focus', style: { 'border-width': 3, 'border-color': tokens.edge.highlight, 'z-index': 998 } },
        {
            selector: 'edge',
            style: {
                'curve-style': 'bezier',
                'width': 1.5,
                'line-color': tokens.edge.base,
                'target-arrow-color': tokens.edge.base,
                'target-arrow-shape': 'triangle',
                'arrow-scale': 0.8
            }
        },
        {
            selector: 'edge:loop',
            style: {
                'curve-style': 'bezier',
                'loop-direction': '-45deg',
                'loop-sweep': '90deg'
            }
        },
        { selector: 'edge[type="route_to_controller"]', style: { 'line-color': tokens.edge.route, 'target-arrow-color': tokens.edge.route } },
        { selector: 'edge[type="call"]', style: { 'line-color': tokens.edge.call, 'target-arrow-color': tokens.edge.call } },
        { selector: 'edge[type="use"]', style: { 'line-color': tokens.edge.use, 'target-arrow-color': tokens.edge.use, 'line-style': 'dashed' } },
        {
            selector: 'edge.highlighted', style: {
                'width': 3.5, 'line-color': tokens.edge.highlight, 'target-arrow-color': tokens.edge.highlight,
                'z-index': 999, 'line-style': 'solid'
            }
        },
        { selector: 'edge.highlighted[type="route_to_controller"]', style: { 'line-color': tokens.edge.route, 'target-arrow-color': tokens.edge.route } },
        { selector: 'edge.highlighted[type="call"]', style: { 'line-color': tokens.edge.call, 'target-arrow-color': tokens.edge.call } },
        { selector: 'edge.highlighted[type="use"]', style: { 'line-color': tokens.edge.use, 'target-arrow-color': tokens.edge.use } },
        { selector: 'edge.dimmed', style: { 'opacity': 0.05, 'events': 'no' } },
        // Risk mode: primary hotspots vs expanded context nodes
        { selector: 'node.risk-primary', style: { 'opacity': 1, 'border-style': 'solid' } },
        { selector: 'node.risk-context', style: { 'opacity': 0.6, 'border-style': 'dashed' } },
        // Zones mode: zone supernode style
        { selector: 'node[kind="zone"]', style: { 'width': '180px', 'height': '70px', 'font-size': '13px', 'font-weight': 'bold', 'border-width': 2 } },
    ];

    Object.entries(tokens.kindColors).forEach(([kind, colors]) => {
        style.push({
            selector: `node[kind="${kind}"]`,
            style: { 'background-color': colors.bg, 'border-color': colors.bd }
        });
    });

    // Heatmap styles go last so they override kind colors when active
    style.push(
        { selector: 'node.heatmap-on[heat_level = 0]', style: { 'background-color': tokens.heatmap[0].bg, 'border-color': tokens.heatmap[0].bd, 'color': tokens.heatmap[0].tx } },
        { selector: 'node.heatmap-on[heat_level = 1]', style: { 'background-color': tokens.heatmap[1].bg, 'border-color': tokens.heatmap[1].bd, 'color': tokens.heatmap[1].tx } },
        { selector: 'node.heatmap-on[heat_level = 2]', style: { 'background-color': tokens.heatmap[2].bg, 'border-color': tokens.heatmap[2].bd, 'color': tokens.heatmap[2].tx } },
        { selector: 'node.heatmap-on[heat_level = 3]', style: { 'background-color': tokens.heatmap[3].bg, 'border-color': tokens.heatmap[3].bd, 'color': tokens.heatmap[3].tx } },
        { selector: 'node.heatmap-on[heat_level = 4]', style: { 'background-color': tokens.heatmap[4].bg, 'border-color': tokens.heatmap[4].bd, 'color': tokens.heatmap[4].tx } }
    );

    return style;
}

export function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

export function formatDate(isoString) {
    if (!isoString) return 'Never';
    const d = new Date(isoString);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

export function nl2br(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br>$2');
}

export function getUniqueEdgeId(source, target, type) {
    return [source, target, type].join('__');
}

export function asNumber(val) {
    return typeof val === 'number' ? val : 0;
}

export function getPercentile(value, allValues) {
    if (!allValues.length) return 0;
    const count = allValues.filter(v => v <= value).length;
    return (count / allValues.length) * 100;
}

export function getNodeMetrics(node) {
    const data = node.data();
    return {
        lines: asNumber(data.loc),
        complexity: asNumber(data.complexity),
        methods: asNumber(data.methods),
        inDegree: node.indegree(false),
        outDegree: node.outdegree(false)
    };
}

export function computeHeatRaw(node) {
    const m = getNodeMetrics(node);
    const locScore = m.lines / 1000;
    const compScore = m.complexity / 20;
    const inScore = m.inDegree / 15;
    const outScore = m.outDegree / 10;
    return (locScore * 0.3) + (compScore * 0.3) + (inScore * 0.2) + (outScore * 0.2);
}

export function showToast(message) {
    let t = document.getElementById('logic-map-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'logic-map-toast';
        document.body.appendChild(t);
        const style = document.createElement('style');
        style.innerHTML = `
            #logic-map-toast {
                position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(100px);
                background: var(--bg-elevated, #263145); color: var(--tx, #fff); padding: 10px 20px;
                border: 1px solid var(--bdr, rgba(255,255,255,0.1)); border-radius: 6px;
                font-size: 11px; z-index: 9999; opacity: 0; transition: transform 0.3s, opacity 0.3s;
                font-family: inherit; box-shadow: 0 4px 12px rgba(0,0,0,0.5);
            }
            #logic-map-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        `;
        document.head.appendChild(style);
    }
    t.textContent = message;
    t.classList.add('show');
    if (t._timer) clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 3000);
}

export function getNamespace(id) {
    if (!id) return '';
    const parts = id.split('\\');
    if (parts.length <= 1) return '';
    parts.pop();
    return parts.join('\\');
}

export function formatShortLabel(label) {
    if (!label) return '';
    const parts = label.split('\\');
    return parts[parts.length - 1];
}

export function withSnapshot(url) {
    if (!S.selectedSnapshot || S.selectedSnapshot === '') return url;
    try {
        const u = new URL(url, window.location.origin);
        u.searchParams.set('snapshot', S.selectedSnapshot);
        return u.toString();
    } catch (e) {
        return url + (url.includes('?') ? '&' : '?') + 'snapshot=' + encodeURIComponent(S.selectedSnapshot);
    }
}