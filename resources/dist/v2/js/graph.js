import cytoscape from './vendor/cytoscape.esm.min.js';

const NODE_COLORS = {
    route: '#14b8a6', controller: '#38bdf8', method: '#818cf8', job: '#f59e0b',
    event: '#f472b6', listener: '#c084fc', model: '#34d399', table: '#22c55e',
    module: '#fb7185', process: '#eab308', test: '#a3e635',
};

export function contextElements(context) {
    const nodes = new Map();
    const edges = new Map();

    const addNode = (node) => {
        if (!node?.id || nodes.has(node.id)) return;
        nodes.set(node.id, {
            data: {
                id: node.id,
                encodedId: node.encoded_id || '',
                label: node.name || node.qualified_name || node.id,
                qualifiedName: node.qualified_name || '',
                kind: node.kind || 'unknown',
                location: node.location || null,
            },
        });
    };

    const addEdge = (edge, direction) => {
        addNode(edge?.source);
        addNode(edge?.target);
        if (!edge?.id || edges.has(edge.id)) return;
        edges.set(edge.id, {
            data: {
                id: edge.id,
                source: edge.source.id,
                target: edge.target.id,
                type: edge.type,
                direction,
                evidenceIds: edge.evidence_ids || [],
            },
        });
    };

    addNode(context?.symbol);

    Object.values(context?.incoming || {}).flat().forEach((edge) => addEdge(edge, 'incoming'));
    Object.values(context?.outgoing || {}).flat().forEach((edge) => addEdge(edge, 'outgoing'));

    return [...nodes.values(), ...edges.values()];
}

export function createGraph(container, onSelect = () => {}) {
    const graph = cytoscape({
        container,
        elements: [],
        minZoom: 0.15,
        maxZoom: 3,
        wheelSensitivity: 0.18,
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
                selector: 'node.workflow-step[shape = "diamond"]',
                style: { shape: 'diamond', 'background-color': '#f59e0b', width: 38, height: 38 },
            },
            {
                selector: 'node.workflow-step[shape = "barrel"]',
                style: { shape: 'barrel', 'background-color': '#22c55e', width: 38, height: 32 },
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
                selector: 'node.changed',
                style: { shape: 'roundrectangle', width: 42, height: 34, 'background-color': '#f97316', 'border-color': '#fed7aa' },
            },
            {
                selector: 'node.affected',
                style: { 'background-color': '#ef4444', 'border-color': '#fecaca' },
            },
            {
                selector: 'node.reason-path',
                style: { width: 22, height: 22, 'background-color': '#8b5cf6' },
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
            this.replace(contextElements(context));
        },
        replace(elements, layout = { name: 'breadthfirst', directed: true, padding: 48, spacingFactor: 1.25 }) {
            graph.batch(() => {
                graph.elements().remove();
                graph.add(elements);
            });
            graph.layout(layout).run();
            graph.fit(undefined, 52);
        },
        fit() {
            graph.fit(undefined, 52);
        },
        clear() {
            graph.elements().remove();
        },
        destroy() {
            graph.destroy();
        },
    });
}
