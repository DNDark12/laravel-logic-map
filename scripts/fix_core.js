const fs = require('fs');

let code = fs.readFileSync('resources/dist/js/logic-map-original.js', 'utf8');

const functionsToRemove = [
    // state.js
    'cssVar', 'readThemeTokens', 'syncThemeTokens', 'buildCyStyle', 'escapeHtml', 'formatDate', 'nl2br', 'getUniqueEdgeId', 'asNumber', 'showToast', 'getNamespace', 'formatShortLabel', 'withSnapshot', 'computeHeatRaw',
    // modes.js
    'getNodeKind', 'restoreAllElementVisibility', 'applyFlowModeVisuals', 'buildRiskNodeSet', 'getDirectNeighborIds', 'expandRiskNeighborhood', 'applyRiskModeVisuals', 'inferZone', 'buildZoneAssignments', 'worstRisk', 'buildSupernodes', 'buildZoneEdges', 'checkZoneQuality', 'showZoneWarning', 'dismissZoneWarning', 'applyZonesMode', 'restoreFromZonesMode', 'getPercentile', 'getHeatmapNodes', 'recalculateHeatmapData', 'syncHeatmapUI', 'applyHeatmapMode', 'toggleHeatmap',
    // health-panel.js
    'openHealthPanel', 'closeHealthPanel', 'hpToggle', 'renderHealthPanel',
    // subgraph.js
    'getSubgraphSeedLabel', '_sgShowUI', '_sgHideUI', '_getDepth', 'isSubgraphLocked', 'syncSubgraphControlsLockState', 'startSubgraphActionCooldown', 'setSubgraphBusyState', 'runSubgraphLayoutAfterLoad', '_loadSubgraph', 'enterSubGraph', 'rerunSubGraph', 'exitSubGraph',
    // module-explorer.js
    'computeCrossModuleEdges', 'focusModule', 'buildModuleExplorer',
    // export.js
    'exportLogicMap'
];

function removeFunction(fnName) {
    let searchStrs = [
        `function ${fnName}(`,
        `function ${fnName} (`,
        `const ${fnName} = function(`,
        `const ${fnName} = function (`
    ];
    let idx = -1;
    for (const str of searchStrs) {
        idx = code.indexOf(str);
        if (idx !== -1) break;
    }
    
    if (idx === -1) {
        console.log(`Warning: function ${fnName} not found!`);
        return;
    }
    
    let braceIdx = code.indexOf('{', idx);
    if (braceIdx === -1) return;
    
    let endIdx = braceIdx + 1;
    let count = 1;
    
    while (count > 0 && endIdx < code.length) {
        if (code[endIdx] === '{') count++;
        else if (code[endIdx] === '}') count--;
        endIdx++;
    }
    
    code = code.substring(0, idx) + code.substring(endIdx);
}

functionsToRemove.forEach(removeFunction);

// Remove extracted variables
const variablesToRemove = [
    'KIND_COLORS', 'RISK_COLORS', 'KIND_ORDER', 'KIND_LABELS', 'THEME_TOKENS',
    '_zoneWarningEl', 'sgRequestSerial', 'sgBusy', 'sgActionCooldownUntil', 'sgCooldownTimer', 'addedEdgeIds', 'crossModuleEdges', 'SG_STALE_COOLDOWN_MS'
];

variablesToRemove.forEach(v => {
    let re = new RegExp(`(?:const|let|var)\\s+${v}\\s*=\\s*[^;]+;`, 'g');
    code = code.replace(re, '');
});

// Edge case removals
removeFunction('initHealthData');
code = code.replace(/window\.LogicMap\.window\.LogicMap\.runLayout/g, 'window.LogicMap.runLayout');

// Add imports
const imports = `import { 
    S, THEME_TOKENS, KIND_COLORS, RISK_COLORS, KIND_ORDER, KIND_LABELS,
    syncThemeTokens, buildCyStyle, formatShortLabel, 
    getNamespace, getUniqueEdgeId, asNumber, formatDate, withSnapshot, computeHeatRaw
} from './state.js';\n\n`;

code = imports + code;

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

code = code.replace(/let allNodesData\s*=\s*\[\];/, globalSetup + 'let allNodesData = [];');

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
    if (window.LogicMap.openHealthPanel && !window.LogicMap.hpToggle) return;
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

// Insert lazy stubs right before the global assignments at the bottom
let stubIdx = code.indexOf('window.fitView =');
if (stubIdx !== -1) {
    code = code.substring(0, stubIdx) + lazyStubs + '\\n\\n' + code.substring(stubIdx);
} else {
    code += lazyStubs;
}

// Convert internal runLayout calls to LogicMap ones because modes.js needs to be lazy loaded smoothly
code = code.replace(/runLayout\(/g, 'window.LogicMap.runLayout(');

fs.writeFileSync('resources/dist/js/core.js', code, 'utf8');
console.log('Successfully written core.js (' + code.split('\\n').length + ' lines)');
