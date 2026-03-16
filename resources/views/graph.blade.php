<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laravel Logic Map</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.30.0/cytoscape.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/elkjs@0.9.1/lib/elk.bundled.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/cytoscape-elk@2.1.0/dist/cytoscape-elk.min.js"></script>
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; display: flex; height: 100vh; overflow: hidden; }
        #sidebar { width: 300px; background: #f8f9fa; border-right: 1px solid #dee2e6; padding: 1.5rem; display: flex; flex-direction: column; overflow-y: auto; }
        #graph-container { flex: 1; position: relative; background: #fdfdfd; }
        #cy { width: 100%; height: 100%; position: absolute; left: 0; top: 0; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem; color: #ef4444; }
        .section-title { font-size: 0.875rem; text-transform: uppercase; font-weight: 600; color: #4b5563; margin-bottom: 0.5rem; letter-spacing: 0.05em; }
        .section { margin-bottom: 1.5rem; }
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        .stat-item { background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0.75rem; }
        .stat-value { font-size: 1.25rem; font-weight: 700; color: #111827; }
        .stat-label { font-size: 0.75rem; color: #6b7280; }
        .search-input { width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; }
        .search-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
        .kind-filters { display: flex; flex-wrap: wrap; gap: 0.25rem; }
        .kind-badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; cursor: pointer; border: 1px solid transparent; }
        .kind-badge.active { border-color: #3b82f6; }
        .kind-badge.route { background: #dcfce7; color: #166534; }
        .kind-badge.controller { background: #dbeafe; color: #1e40af; }
        .kind-badge.service { background: #fef3c7; color: #92400e; }
        .kind-badge.repository { background: #f3e8ff; color: #6b21a8; }
        .kind-badge.model { background: #fce7f3; color: #9d174d; }
        .kind-badge.unknown { background: #f3f4f6; color: #374151; }
        .loading { color: #9ca3af; font-style: italic; font-size: 0.875rem; }
        .node-info { background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0.75rem; font-size: 0.875rem; }
        .node-info-title { font-weight: 600; color: #111827; margin-bottom: 0.25rem; word-break: break-all; }
        .node-info-kind { display: inline-block; padding: 0.125rem 0.375rem; border-radius: 4px; font-size: 0.75rem; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div id="sidebar">
        <h1>Logic Map</h1>

        <div class="section">
            <div class="section-title">Overview</div>
            <div id="stats" class="stat-grid">
                <div class="stat-item">
                    <div class="stat-value" id="node-count">-</div>
                    <div class="stat-label">Nodes</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="edge-count">-</div>
                    <div class="stat-label">Edges</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Search</div>
            <input type="text" id="search-input" class="search-input" placeholder="Search nodes...">
        </div>

        <div class="section">
            <div class="section-title">Filter by Kind</div>
            <div id="kind-filters" class="kind-filters"></div>
        </div>

        <div class="section">
            <div class="section-title">Selected Node</div>
            <div id="node-info" class="node-info" style="display: none;"></div>
            <div id="no-selection" class="loading">Click a node to see details</div>
        </div>
    </div>
    <div id="graph-container">
        <div id="cy"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            cytoscape.use(cytoscapeElk);

            // Track loaded edges to prevent duplicates
            const loadedEdges = new Set();
            const loadedNodes = new Set();

            const cy = cytoscape({
                container: document.getElementById('cy'),
                style: [
                    {
                        selector: 'node',
                        style: {
                            'background-color': '#f3f4f6',
                            'label': 'data(name)',
                            'color': '#111827',
                            'font-size': '10px',
                            'text-valign': 'center',
                            'text-halign': 'center',
                            'border-width': 1,
                            'border-color': '#9ca3af',
                            'padding': '8px',
                            'shape': 'round-rectangle',
                            'width': 'label',
                            'height': 'label'
                        }
                    },
                    {
                        selector: 'node[kind="route"]',
                        style: { 'background-color': '#dcfce7', 'border-color': '#22c55e' }
                    },
                    {
                        selector: 'node[kind="controller"]',
                        style: { 'background-color': '#dbeafe', 'border-color': '#3b82f6' }
                    },
                    {
                        selector: 'node[kind="service"]',
                        style: { 'background-color': '#fef3c7', 'border-color': '#f59e0b' }
                    },
                    {
                        selector: 'node[kind="repository"]',
                        style: { 'background-color': '#f3e8ff', 'border-color': '#a855f7' }
                    },
                    {
                        selector: 'node[kind="model"]',
                        style: { 'background-color': '#fce7f3', 'border-color': '#ec4899' }
                    },
                    {
                        selector: 'node.highlighted',
                        style: { 'border-width': 3, 'border-color': '#ef4444' }
                    },
                    {
                        selector: 'edge',
                        style: {
                            'width': 2,
                            'line-color': '#cbd5e1',
                            'target-arrow-color': '#cbd5e1',
                            'target-arrow-shape': 'triangle',
                            'curve-style': 'bezier'
                        }
                    },
                    {
                        selector: 'edge[type="route_to_controller"]',
                        style: { 'line-color': '#22c55e', 'target-arrow-color': '#22c55e' }
                    },
                    {
                        selector: 'edge[type="call"]',
                        style: { 'line-color': '#3b82f6', 'target-arrow-color': '#3b82f6' }
                    },
                    {
                        selector: 'edge[type="use"]',
                        style: { 'line-color': '#f59e0b', 'target-arrow-color': '#f59e0b', 'line-style': 'dashed' }
                    }
                ],
                layout: { name: 'elk', elk: { 'algorithm': 'layered', 'direction': 'RIGHT', 'spacing.nodeNodeBetweenLayers': 100, 'spacing.nodeNode': 50 } }
            });

            // Helper: Generate edge ID
            function edgeId(edge) {
                return `${edge.source}->${edge.target}:${edge.type}`;
            }

            // Helper: Add nodes and edges without duplicates
            function addGraphElements(data) {
                const newNodes = [];
                const newEdges = [];

                data.nodes.forEach(n => {
                    if (!loadedNodes.has(n.id)) {
                        loadedNodes.add(n.id);
                        newNodes.push({ data: n });
                    }
                });

                data.edges.forEach(e => {
                    const eid = edgeId(e);
                    if (!loadedEdges.has(eid)) {
                        loadedEdges.add(eid);
                        newEdges.push({ data: { ...e, id: eid } });
                    }
                });

                if (newNodes.length > 0 || newEdges.length > 0) {
                    cy.add(newNodes);
                    cy.add(newEdges);
                    return true;
                }
                return false;
            }

            // Fetch Meta for stats
            fetch('{{ route("logic-map.meta") }}')
                .then(res => res.json())
                .then(json => {
                    if (json.ok) {
                        document.getElementById('node-count').textContent = json.data.node_count;
                        document.getElementById('edge-count').textContent = json.data.edge_count;

                        // Build kind filters
                        const kindFilters = document.getElementById('kind-filters');
                        Object.entries(json.data.kinds).forEach(([kind, count]) => {
                            const badge = document.createElement('span');
                            badge.className = `kind-badge ${kind} active`;
                            badge.textContent = `${kind} (${count})`;
                            badge.dataset.kind = kind;
                            badge.onclick = () => {
                                badge.classList.toggle('active');
                                applyFilters();
                            };
                            kindFilters.appendChild(badge);
                        });
                    }
                });

            // Fetch Overview
            fetch('{{ route("logic-map.overview") }}')
                .then(res => res.json())
                .then(json => {
                    if (json.ok) {
                        addGraphElements(json.data);
                        cy.layout({ name: 'elk' }).run();
                    }
                });

            // Handle Node Click -> Subgraph
            cy.on('tap', 'node', function(evt) {
                const node = evt.target;
                const id = node.id();

                // Show node info
                const infoDiv = document.getElementById('node-info');
                const noSelection = document.getElementById('no-selection');
                infoDiv.style.display = 'block';
                noSelection.style.display = 'none';
                infoDiv.innerHTML = `
                    <div class="node-info-title">${node.data('name') || id}</div>
                    <span class="node-info-kind kind-badge ${node.data('kind')}">${node.data('kind')}</span>
                    <div style="margin-top: 0.5rem; color: #6b7280; font-size: 0.75rem;">
                        ID: ${id}
                    </div>
                `;

                // Highlight
                cy.nodes().removeClass('highlighted');
                node.addClass('highlighted');

                // Fetch subgraph
                fetch('{{ url("logic-map/subgraph") }}/' + encodeURIComponent(id))
                    .then(res => res.json())
                    .then(json => {
                        if (json.ok && addGraphElements(json.data)) {
                            cy.layout({ name: 'elk' }).run();
                        }
                    });
            });

            // Search functionality
            const searchInput = document.getElementById('search-input');
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const query = this.value.trim().toLowerCase();
                    cy.nodes().forEach(node => {
                        const name = (node.data('name') || '').toLowerCase();
                        const id = node.id().toLowerCase();
                        if (query === '' || name.includes(query) || id.includes(query)) {
                            node.style('opacity', 1);
                        } else {
                            node.style('opacity', 0.2);
                        }
                    });
                }, 300);
            });

            // Filter by kind
            function applyFilters() {
                const activeKinds = [...document.querySelectorAll('.kind-badge.active')].map(b => b.dataset.kind);
                cy.nodes().forEach(node => {
                    if (activeKinds.length === 0 || activeKinds.includes(node.data('kind'))) {
                        node.style('display', 'element');
                    } else {
                        node.style('display', 'none');
                    }
                });
            }
        });
    </script>
</body>
</html>
