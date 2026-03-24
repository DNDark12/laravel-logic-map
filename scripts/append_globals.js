const fs = require('fs');
const path = require('path');

// 1. Append globals to core.js
const corePath = path.join(__dirname, '../resources/dist/js/core.js');
let core = fs.readFileSync(corePath, 'utf8');

const globals = `
// Expose Blade-bound globals
window.fitView = typeof fitView !== 'undefined' ? fitView : undefined;
window.toggleDropdown = typeof toggleDropdown !== 'undefined' ? toggleDropdown : undefined;
window.startFlow = typeof startFlow !== 'undefined' ? startFlow : undefined;
window.stopFlow = typeof stopFlow !== 'undefined' ? stopFlow : undefined;
window.toggleLegend = typeof toggleLegend !== 'undefined' ? toggleLegend : undefined;
window.hideLegend = typeof hideLegend !== 'undefined' ? hideLegend : undefined;
window.showLegend = typeof showLegend !== 'undefined' ? showLegend : undefined;

window.LogicMap = window.LogicMap || {};
window.toggleModPanel = window.LogicMap.toggleModPanel;
window.enterSubGraph = window.LogicMap.enterSubGraph;
window.exitSubGraph = window.LogicMap.exitSubGraph;
window.rerunSubGraph = window.LogicMap.rerunSubGraph;
window.exportLogicMap = window.LogicMap.exportLogicMap;
window.openHealthPanel = window.LogicMap.openHealthPanel;
window.closeHealthPanel = window.LogicMap.closeHealthPanel;
window.hpToggle = window.LogicMap.hpToggle;
`;

if (!core.includes('// Expose Blade-bound globals')) {
    fs.appendFileSync(corePath, '\n' + globals);
    console.log('Appended globals to core.js');
} else {
    console.log('Globals already present in core.js');
}

// 2. Replace script tag in graph.blade.php
const bladePath = path.join(__dirname, '../resources/views/graph.blade.php');
let blade = fs.readFileSync(bladePath, 'utf8');

const scriptTag = /<script>\{\!\! \$logicMapJs \!\!\}\<\/script>/g;
const replacement = `
@if(isset($logicMapJsInline) && $logicMapJsInline)
<script>{!! $logicMapJsInline !!}</script>
@else
<script>
    window.__LM_BASE = "{{ rtrim($logicMapJsBase, '/') }}";
</script>
<script type="module" src="{{ $logicMapJsUrl }}"></script>
@endif
`.trim();

if (blade.match(scriptTag)) {
    blade = blade.replace(scriptTag, replacement);
    fs.writeFileSync(bladePath, blade);
    console.log('Updated script tags in graph.blade.php');
} else {
    console.log('Script tags in graph.blade.php already updated or not found');
}
