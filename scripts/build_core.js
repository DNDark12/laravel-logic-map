const fs = require('fs');
const path = require('path');

let content = fs.readFileSync('resources/dist/js/logic-map.js', 'utf8');

// 1. Remove state variables and simple functions extracted to state.js
const stateRemovals = [
    /const (?:KIND_COLORS|RISK_COLORS|KIND_ORDER|KIND_LABELS|SG_ACTION_COOLDOWN_MS|THEME_TOKENS)\s*=\s*(?:\{[^]*?\n\}|\[[^]*?\]|\d+);/g,
    /function (?:cssVar|readThemeTokens|syncThemeTokens|buildCyStyle|escapeHtml|formatDate|nl2br|getUniqueEdgeId|asNumber|showToast|getNamespace|formatShortLabel|withSnapshot|computeHeatRaw)\s*\([^]*?\)\s*\{[^]*?\n\}/g
];
stateRemovals.forEach(re => content = content.replace(re, ''));

// 2. Remove modes.js functions
const modesRemovals = [
    /function (?:getNodeKind|restoreAllElementVisibility|applyFlowModeVisuals|buildRiskNodeSet|getDirectNeighborIds|expandRiskNeighborhood|applyRiskModeVisuals|inferZone|buildZoneAssignments|worstRisk|buildSupernodes|buildZoneEdges|checkZoneQuality|showZoneWarning|dismissZoneWarning|applyZonesMode|restoreFromZonesMode|getPercentile|getHeatmapNodes|recalculateHeatmapData|syncHeatmapUI|applyHeatmapMode|toggleHeatmap)\s*\([^]*?\)\s*\{[^]*?\n\}/g,
    /let _zoneWarningEl\s*=\s*null;/g
];
modesRemovals.forEach(re => content = content.replace(re, ''));

// 3. Remove health-panel.js functions
const healthRemovals = [
    /function (?:openHealthPanel|closeHealthPanel|hpToggle|renderHealthPanel)\s*\([^]*?\)\s*\{[^]*?\n\}/g
];
healthRemovals.forEach(re => content = content.replace(re, ''));

// 4. Remove subgraph.js functions
const subgraphRemovals = [
    /function (?:getSubgraphSeedLabel|_sgShowUI|_sgHideUI|_getDepth|isSubgraphLocked|syncSubgraphControlsLockState|startSubgraphActionCooldown|setSubgraphBusyState|runSubgraphLayoutAfterLoad|_loadSubgraph|enterSubGraph|rerunSubGraph|exitSubGraph)\s*\([^]*?\)\s*\{[^]*?\n\}/g,
    /let sgRequestSerial\s*=\s*\d+;\nlet sgBusy\s*=\s*false;\nlet sgActionCooldownUntil\s*=\s*\d+;\nlet sgCooldownTimer\s*=\s*null;\nconst SG_STALE_COOLDOWN_MS\s*=\s*\d+;\nconst addedEdgeIds\s*=\s*new Set\(\);/g
];
subgraphRemovals.forEach(re => content = content.replace(re, ''));

// 5. Remove module-explorer.js functions
const moduleRemovals = [
    /function (?:computeCrossModuleEdges|focusModule|buildModuleExplorer)\s*\([^]*?\)\s*\{[^]*?\n\}/g,
    /let crossModuleEdges\s*=\s*\{\};/g
];
moduleRemovals.forEach(re => content = content.replace(re, ''));

// 6. Remove export.js functions
const exportRemovals = [
    /function exportLogicMap\s*\([^]*?\)\s*\{[^]*?\n\}/g
];
exportRemovals.forEach(re => content = content.replace(re, ''));

// Add imports
const imports = `import { 
    S, THEME_TOKENS, KIND_COLORS, RISK_COLORS, KIND_ORDER, KIND_LABELS,
    syncThemeTokens, buildCyStyle, formatShortLabel, 
    getNamespace, getUniqueEdgeId, asNumber, formatDate, withSnapshot, computeHeatRaw
} from './state.js';\n\n`;

content = imports + content;

// Expose LogicMap core globally
const globalSetup = `
window.LogicMap = window.LogicMap || {};
Object.assign(window.LogicMap, {
    currentLayout: 'graph',
    S, getDynamicViewportPadding, applyHighlight,
    openPanel, runLayout, fitView,
    separateOrphanNodes, withSnapshot, showWarning,
    syncMobileSubgraphUI: function() {
        const wrap = document.getElementById('mobile-subgraph-wrap');
        const acts = document.getElementById('mobile-actions-wrap');
        if (!wrap || !acts) return;
        if (S.sgMode) { wrap.classList.add('show'); acts.classList.remove('show'); }
        else { wrap.classList.remove('show'); }
    }
});\n\n`;

content = content.replace(/let allNodesData\s*=\s*\[\];/, globalSetup + 'let allNodesData = [];');

// Add Lazy load stubs
const lazyStubs = `
// --- Lazy Load Stubs ---
window.LogicMap.toggleHeatmap = async function() {
    const m = await import(\`\${window.__LM_BASE || ''}/chunks/modes.js\`);
    Object.assign(window.LogicMap, m);
    m.toggleHeatmap();
};
window.LogicMap.openHealthPanel = async function() {
    const m = await import(\`\${window.__LM_BASE || ''}/chunks/health-panel.js\`);
    Object.assign(window.LogicMap, m);
    if (typeof _healthData !== 'undefined') m.initHealthData(_healthData, _violationsData);
    m.openHealthPanel();
};
window.LogicMap.closeHealthPanel = async function() {
    if (window.LogicMap.openHealthPanel && !window.LogicMap.hpToggle) return; // not loaded
    window.LogicMap.closeHealthPanel();
};
window.LogicMap.hpToggle = async function(id) {
    if (window.LogicMap.hpToggle && !window.LogicMap.openHealthPanel) return;
    window.LogicMap.hpToggle(id);
};
window.LogicMap.enterSubGraph = async function(seeds) {
    const m = await import(\`\${window.__LM_BASE || ''}/chunks/subgraph.js\`);
    Object.assign(window.LogicMap, m);
    m.enterSubGraph(seeds);
};
window.LogicMap.exitSubGraph = async function() {
    if (window.LogicMap.exitSubGraph && !window.LogicMap.enterSubGraph) return;
    window.LogicMap.exitSubGraph();
};
window.LogicMap.rerunSubGraph = async function() {
    if (window.LogicMap.rerunSubGraph && !window.LogicMap.enterSubGraph) return;
    window.LogicMap.rerunSubGraph();
};
window.LogicMap.exportLogicMap = async function(fmt) {
    const m = await import(\`\${window.__LM_BASE || ''}/chunks/export.js\`);
    Object.assign(window.LogicMap, m);
    m.exportLogicMap(fmt);
};
window.LogicMap.toggleModPanel = async function() {
    const m = await import(\`\${window.__LM_BASE || ''}/chunks/module-explorer.js\`);
    Object.assign(window.LogicMap, m);
    if (!S._moduleExplorerBuilt && typeof allNodesData !== 'undefined') {
        m.buildModuleExplorer(allNodesData, allEdgesData);
        if (m.initModuleExplorerUI) m.initModuleExplorerUI();
        S._moduleExplorerBuilt = true;
    }
    const panel = document.getElementById('mod-panel');
    if (panel) panel.classList.toggle('open');
};
window.LogicMap.focusModule = async function(ns) {
    const m = await import(\`\${window.__LM_BASE || ''}/chunks/module-explorer.js\`);
    Object.assign(window.LogicMap, m);
    m.focusModule(ns);
};

// Re-assign runLayout to intercept Modes loading
const _runLayoutActual = window.LogicMap.runLayout || runLayout;
window.LogicMap.runLayout = async function(name, onDone) {
    if (['risk', 'zones', 'flow', 'heatmap'].includes(name) || S.heatmapEnabled) {
        const m = await import(\`\${window.__LM_BASE || ''}/chunks/modes.js\`);
        Object.assign(window.LogicMap, m);
    }
    _runLayoutActual(name, onDone);
};
`;

content += lazyStubs;

// Quick cleanup on runLayout calls to use LogicMap.runLayout properly in internals
content = content.replace(/runLayout\(/g, 'window.LogicMap.runLayout(');
content = content.replace(/window\.LogicMap\.window\.LogicMap\.runLayout/g, 'window.LogicMap.runLayout');

fs.writeFileSync('resources/dist/js/core.js', content, 'utf8');
console.log('Successfully written core.js (' + content.length + ' bytes)');
