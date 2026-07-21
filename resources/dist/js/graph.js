import cytoscape from './vendor/cytoscape.esm.min.js';

const MIN_READABLE_ZOOM = 0.82;

const NODE_COLORS = {
    route: '#14b8a6', controller: '#38bdf8', method: '#818cf8', job: '#f59e0b',
    event: '#f472b6', listener: '#c084fc', model: '#34d399', table: '#22c55e',
    module: '#fb7185', process: '#eab308', test: '#a3e635',
};

function relationLabel(type) {
    const labels = {
        defines: 'contains', member_of_module: 'belongs to module', handles_route: 'handles route',
        validates_with: 'validates with', authorizes_with: 'authorizes with', covered_by_test: 'covered by test',
        step_in_process: 'step in workflow', reads_model: 'reads model', writes_model: 'writes model',
        reads_table: 'reads table', writes_table: 'writes table', calls_external: 'calls external API',
        sends_notification: 'sends notification', sends_mail: 'sends mail', applies_middleware: 'middleware',
    };

    return labels[type] || String(type || 'relates to').replaceAll('_', ' ');
}

function semanticNodeLabel(node, role) {
    const name = node.name || node.qualified_name?.split('\\').at(-1) || node.id;
    const kind = String(node.kind || 'symbol').replaceAll('_', ' ');
    const context = role === 'selected' ? 'SELECTED'
        : (role === 'module-member' ? 'IN MODULE'
            : (role === 'class-member' ? 'DEFINED METHOD'
                : (role === 'upstream' ? 'CALLER' : (role === 'downstream' ? 'NEXT' : 'CONNECTED'))));

    return `${name}\n${context} · ${kind}`;
}

function mainNode(graph) {
    const preferred = graph.nodes('.selected-symbol, .workflow-module, .entry, .changed, .impact-summary').first();
    return preferred.nonempty() ? preferred : graph.nodes().filter(':childless').first();
}

function centerAtZoom(graph, zoom) {
    const focus = mainNode(graph);
    graph.zoom(zoom);
    if (focus.nonempty()) graph.center(focus);
}

function readableViewport(graph) {
    const workflowRoot = graph.nodes('.workflow-module, .entry').first();
    if (workflowRoot.empty()) {
        centerAtZoom(graph, MIN_READABLE_ZOOM);
        return;
    }

    const viewport = graph.container().getBoundingClientRect();
    const position = workflowRoot.position();
    graph.zoom(MIN_READABLE_ZOOM);
    graph.pan({
        x: viewport.width / 2 - position.x * MIN_READABLE_ZOOM,
        y: viewport.height * 0.18 - position.y * MIN_READABLE_ZOOM,
    });
}

function ensureReadableViewport(graph) {
    if (graph.nodes().empty() || graph.zoom() >= MIN_READABLE_ZOOM) return;

    readableViewport(graph);
}

function contextEdgeVisible(context, edge, direction) {
    if (context?.symbol?.kind !== 'module') return true;

    const member = direction === 'incoming' ? edge?.source : edge?.target;
    return member?.kind !== 'method';
}

function contextGridPositions(elements, selectedId) {
    const nodes = elements.filter((element) => !element.data.source);
    const members = nodes.filter((element) => element.data.id !== selectedId);
    const columns = Math.min(4, Math.max(2, Math.ceil(Math.sqrt(members.length || 1))));
    const rows = Math.max(1, Math.ceil(members.length / columns));

    nodes.find((element) => element.data.id === selectedId).position = { x: 0, y: (rows - 1) * 55 };
    members.forEach((element, index) => {
        element.position = {
            x: 270 + (index % columns) * 230,
            y: Math.floor(index / columns) * 110,
        };
    });

    return elements;
}

function moduleContextPositions(elements, selectedId) {
    return contextGridPositions(elements, selectedId);
}

export function contextElements(context) {
    const nodes = new Map();
    const edges = new Map();

    const addNode = (node, role = 'connected') => {
        if (!node?.id) return;
        const existing = nodes.get(node.id);
        if (existing) {
            if (role === 'selected') {
                existing.data.label = semanticNodeLabel(node, role);
                existing.classes = 'symbol-node selected-symbol';
            }
            return;
        }
        const memberClass = role.endsWith('-member') ? ' member' : '';
        nodes.set(node.id, {
            data: {
                id: node.id,
                encodedId: node.encoded_id || '',
                label: semanticNodeLabel(node, role),
                qualifiedName: node.qualified_name || '',
                kind: node.kind || 'unknown',
                role,
                location: node.location || null,
            },
            classes: `symbol-node ${role}${memberClass}${role === 'selected' ? ' selected-symbol' : ''}`,
        });
    };

    const addEdge = (edge, direction) => {
        const selectedId = context?.symbol?.id;
        const sourceRole = edge?.source?.id === selectedId ? 'selected'
            : (edge?.type === 'member_of_module' ? 'module-member' : 'upstream');
        const targetRole = edge?.target?.id === selectedId ? 'selected'
            : (edge?.type === 'defines' ? 'class-member' : 'downstream');
        addNode(edge?.source, sourceRole);
        addNode(edge?.target, targetRole);
        if (!edge?.id || edges.has(edge.id)) return;
        edges.set(edge.id, {
            data: {
                id: edge.id,
                source: edge.source.id,
                target: edge.target.id,
                type: edge.type,
                label: relationLabel(edge.type),
                direction,
                evidenceIds: edge.evidence_ids || [],
            },
            classes: `context-edge ${direction}`,
        });
    };

    addNode(context?.symbol, 'selected');

    Object.values(context?.incoming || {}).flat()
        .filter((edge) => contextEdgeVisible(context, edge, 'incoming'))
        .forEach((edge) => addEdge(edge, 'incoming'));
    Object.values(context?.outgoing || {}).flat()
        .filter((edge) => contextEdgeVisible(context, edge, 'outgoing'))
        .forEach((edge) => addEdge(edge, 'outgoing'));

    const elements = [...nodes.values(), ...edges.values()];
    if (context?.symbol?.kind === 'module') return moduleContextPositions(elements, context.symbol.id);
    return nodes.size > 4 ? contextGridPositions(elements, context.symbol.id) : elements;
}

export function createGraph(container, onSelect = () => {}) {
    const graph = cytoscape({
        container,
        elements: [],
        minZoom: 0.15,
        maxZoom: 3,
        autoungrabify: true,
        style: [
            {
                selector: 'node',
                style: {
                    'background-color': (node) => NODE_COLORS[node.data('kind')] || '#64748b',
                    'border-color': '#020617',
                    'border-width': 2,
                    color: '#e2e8f0',
                    label: 'data(label)',
                    'font-size': 10,
                    'text-wrap': 'ellipsis',
                    'text-overflow-wrap': 'anywhere',
                    'text-max-width': 130,
                    'text-valign': 'bottom',
                    'text-margin-y': 7,
                    width: 28,
                    height: 28,
                },
            },
            {
                selector: 'node.module-lane',
                style: {
                    shape: 'roundrectangle',
                    'background-color': '#172033',
                    'background-opacity': .42,
                    'border-color': '#475569',
                    'border-style': 'dashed',
                    'border-width': 1,
                    color: '#94a3b8',
                    'font-size': 11,
                    'text-valign': 'top',
                    'text-halign': 'center',
                    'text-margin-y': -8,
                    padding: 24,
                },
            },
            {
                selector: 'node.symbol-node',
                style: {
                    shape: 'roundrectangle', width: 176, height: 58, 'font-size': 11,
                    'font-weight': 600, 'text-wrap': 'wrap', 'text-max-width': 158,
                    'text-valign': 'center', 'text-halign': 'center', 'text-margin-y': 0,
                    'border-color': '#475569', 'border-width': 2,
                },
            },
            {
                selector: 'node.symbol-node.selected-symbol',
                style: { width: 196, height: 66, 'border-color': '#38bdf8', 'border-width': 4, 'background-color': '#0e7490' },
            },
            {
                selector: 'node.symbol-node.upstream',
                style: { 'background-color': '#4338ca' },
            },
            {
                selector: 'node.symbol-node.downstream',
                style: { 'background-color': '#0f766e' },
            },
            {
                selector: 'node.symbol-node.member',
                style: { 'background-color': '#334155', 'border-color': '#94a3b8' },
            },
            {
                selector: 'node.workflow-step',
                style: {
                    shape: 'roundrectangle', width: 190, height: 68, 'font-size': 11,
                    'font-weight': 600, 'text-wrap': 'wrap', 'text-max-width': 172,
                    'text-valign': 'center', 'text-halign': 'center', 'text-margin-y': 0,
                    'border-color': '#a5b4fc', 'border-width': 2,
                },
            },
            {
                selector: 'node.workflow-step[shape = "diamond"]',
                style: { shape: 'diamond', 'background-color': '#b45309', width: 118, height: 100, 'text-max-width': 82, 'font-size': 9 },
            },
            {
                selector: 'node.workflow-step[shape = "barrel"]',
                style: { shape: 'barrel', 'background-color': '#047857', width: 180, height: 66, 'text-max-width': 150 },
            },
            {
                selector: 'node.workflow-step.gap',
                style: { shape: 'roundrectangle', 'border-style': 'dashed', 'border-color': '#fb7185', 'background-color': '#4c1d2f' },
            },
            {
                selector: 'node.workflow-step.cycle',
                style: { shape: 'ellipse', 'border-style': 'double', 'border-color': '#f97316', 'background-color': '#431407' },
            },
            {
                selector: 'node.workflow-module',
                style: { shape: 'roundrectangle', width: 170, height: 66, 'background-color': '#fb7185', 'border-color': '#fecdd3', 'font-size': 13, 'text-wrap': 'wrap', 'text-max-width': 150, 'text-valign': 'center', 'text-margin-y': 0 },
            },
            {
                selector: 'node.workflow-entry',
                style: { shape: 'roundrectangle', width: 180, height: 64, 'background-color': '#38bdf8', 'border-color': '#bae6fd', color: '#06101f', 'font-size': 12, 'font-weight': 600, 'text-wrap': 'wrap', 'text-max-width': 164, 'text-valign': 'center', 'text-margin-y': 0 },
            },
            {
                selector: 'node.changed, node.affected, node.reason-path',
                style: {
                    shape: 'roundrectangle', width: 176, height: 58, 'font-size': 11,
                    'font-weight': 600, 'text-wrap': 'wrap', 'text-max-width': 158,
                    'text-valign': 'center', 'text-halign': 'center', 'text-margin-y': 0,
                },
            },
            {
                selector: 'node.changed',
                style: { 'background-color': '#f97316', 'border-color': '#fed7aa' },
            },
            {
                selector: 'node.affected',
                style: { 'background-color': '#ef4444', 'border-color': '#fecaca' },
            },
            {
                selector: 'node.reason-path',
                style: { 'background-color': '#8b5cf6' },
            },
            {
                selector: 'node.impact-summary',
                style: { shape: 'roundrectangle', width: 150, height: 62, 'font-size': 12, 'font-weight': 600, 'text-wrap': 'wrap', 'text-max-width': 138, 'text-valign': 'center', 'text-margin-y': 0 },
            },
            {
                selector: 'node.impact-member',
                style: { shape: 'roundrectangle', width: 180, height: 62, 'font-size': 10, 'line-height': 1.15, 'text-wrap': 'wrap', 'text-max-width': 164, 'text-valign': 'center', 'text-margin-y': 0 },
            },
            {
                selector: 'node.frontier',
                style: { shape: 'roundrectangle', 'border-style': 'dashed', 'background-opacity': .35, 'border-color': '#facc15' },
            },
            {
                selector: 'edge',
                style: {
                    width: 1.5,
                    'line-color': '#475569',
                    'target-arrow-color': '#64748b',
                    'target-arrow-shape': 'triangle',
                    'curve-style': 'bezier',
                    opacity: 0.76,
                    label: 'data(label)',
                    color: '#cbd5e1',
                    'font-size': 8,
                    'text-background-color': '#0b1220',
                    'text-background-opacity': .88,
                    'text-background-padding': 2,
                    'text-rotation': 'autorotate',
                },
            },
            {
                selector: 'edge.async',
                style: { 'line-style': 'dashed', 'line-color': '#f59e0b', 'target-arrow-color': '#f59e0b' },
            },
            {
                selector: 'edge.cycle',
                style: { 'line-style': 'dashed', 'line-color': '#f97316', 'target-arrow-color': '#f97316', width: 3 },
            },
            {
                selector: 'edge.reason-path',
                style: { 'line-color': '#8b5cf6', 'target-arrow-color': '#8b5cf6' },
            },
            {
                selector: 'edge[type = "dispatches"], edge[type = "queues"], edge[type = "listens_to"]',
                style: { 'line-style': 'dashed', 'line-color': '#f59e0b', 'target-arrow-color': '#f59e0b' },
            },
            {
                selector: ':selected',
                style: { 'border-color': '#f8fafc', 'border-width': 4, 'overlay-opacity': 0.08 },
            },
        ],
    });

    graph.on('tap', 'node', (event) => onSelect(event.target.data()));
    graph.on('tap', 'edge', (event) => onSelect(event.target.data()));

    return Object.freeze({
        instance: graph,
        replaceContext(context) {
            const elements = contextElements(context);
            const positioned = elements.some((element) => element.position);
            this.replace(elements, positioned ? { name: 'preset', padding: 48 } : undefined);
        },
        replace(elements, layout = { name: 'breadthfirst', directed: true, padding: 48, spacingFactor: 1.65, avoidOverlap: true }) {
            graph.batch(() => {
                graph.elements().remove();
                graph.add(elements);
            });
            graph.layout(layout).run();
            graph.fit(undefined, 52);
            ensureReadableViewport(graph);
        },
        fit() {
            graph.fit(undefined, 52);
        },
        focusMain() {
            centerAtZoom(graph, 1.15);
        },
        readable() {
            readableViewport(graph);
        },
        setInteractionMode(mode) {
            const arrange = mode === 'arrange';
            graph.autoungrabify(!arrange);
            graph.nodes().ungrabify();
            if (arrange) graph.nodes().grabify();
        },
        resize() {
            graph.resize();
        },
        clear() {
            graph.elements().remove();
        },
        destroy() {
            graph.destroy();
        },
    });
}
