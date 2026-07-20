const CATEGORY_SECTIONS = {
    workflow: 'workflows',
    module: 'modules',
    shared_state: 'shared_resources',
    external_contract: 'external_contracts',
    uncertainty: 'uncertainty',
};

export function impactCategories(report) {
    const categories = new Set();
    (report.affected_symbols || []).forEach((symbol) => {
        (symbol.reasons || []).forEach((reason) => categories.add(reason.category));
    });
    Object.entries(CATEGORY_SECTIONS).forEach(([category, section]) => {
        if ((report[section] || []).length > 0) categories.add(category);
    });
    if ((report.tests || []).length > 0) categories.add('test_scope');
    return [...categories].sort();
}

function humanLabel(id) {
    const value = String(id || '');

    if (value.startsWith('workflow:')) return humanLabel(value.slice('workflow:'.length));
    if (value.startsWith('process:')) return humanLabel(value.slice('process:'.length));

    if (value.startsWith('route:')) {
        const [, method, ...uriParts] = value.split(':');
        return `${method} /${uriParts.join(':').replace(/^\/+/, '')}`;
    }

    const payload = value.includes(':') ? value.slice(value.indexOf(':') + 1) : value;
    const tail = payload.split('\\').at(-1) || payload;
    return tail.replace('::', ' · ');
}

function categoryLabel(category) {
    return category.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function truncateLabel(value, maxLength = 27) {
    const label = String(value || '').trim();
    if (label.length <= maxLength) return label;
    return `${label.slice(0, maxLength - 1).trimEnd()}…`;
}

export function impactNodeLabel(id, region, category) {
    const value = String(id || '');

    if (value.startsWith('test:')) {
        const payload = value.slice('test:'.length);
        const separator = payload.includes('::') ? '::' : '@';
        const [file, ...methodParts] = payload.split(separator);
        const testClass = (file.split(/[\\/]/).at(-1) || file).replace(/\.php$/i, '');
        const testMethod = methodParts.join(separator)
            .replace(/^test_?/, '')
            .replaceAll('_', ' ');

        return [truncateLabel(testClass), truncateLabel(testMethod)].filter(Boolean).join('\n');
    }

    if (!region) return humanLabel(value);

    const role = region === 'changed' ? 'Changed symbol'
        : (region === 'reason' ? 'Impact path'
            : `Affected${category ? ` via ${categoryLabel(category)}` : ''}`);

    return `${humanLabel(id)}\n${role}`;
}

export function moduleImpactElements(report, activeCategories = impactCategories(report)) {
    const active = new Set(activeCategories);
    const rootId = 'impact-module-summary';
    const moduleName = report.selection?.name || humanLabel(report.selection?.node_id) || 'Module';
    const categoryNodes = new Map();

    const include = (category, nodeId) => {
        if (!category || !active.has(category)) return;
        if (!categoryNodes.has(category)) categoryNodes.set(category, new Set());
        if (nodeId) categoryNodes.get(category).add(nodeId);
    };

    (report.affected_symbols || []).forEach((symbol) => {
        (symbol.reasons || []).forEach((reason) => include(reason.category, symbol.node_id));
    });
    Object.entries(CATEGORY_SECTIONS).forEach(([category, section]) => {
        (report[section] || []).forEach((row, index) => include(category, row.node_id || `${section}:${index}`));
    });
    (report.tests || []).forEach((test, index) => include('test_scope', test.test_node_id || `test:${index}`));

    const elements = [{
        data: {
            id: rootId,
            label: `${moduleName}\n${report.summary?.changed_symbol_count || 0} changed · ${report.summary?.affected_symbol_count || 0} affected`,
            kind: 'impact_module',
            nodeId: report.selection?.node_id,
        },
        classes: 'changed impact-summary',
    }];

    [...categoryNodes.entries()].sort(([left], [right]) => left.localeCompare(right)).forEach(([category, nodeIds], index) => {
        const id = `impact-category-${index}`;
        elements.push({
            data: {
                id,
                label: `${categoryLabel(category)}\n${nodeIds.size} affected`,
                kind: 'impact_category',
                category,
                affectedCount: nodeIds.size,
                memberIds: [...nodeIds].sort(),
            },
            classes: 'affected impact-summary',
        });
        elements.push({
            data: {
                id: `impact-category-edge-${index}`,
                source: rootId,
                target: id,
                category,
                label: `${nodeIds.size}`,
            },
            classes: 'reason-path impact-summary-edge',
        });
    });

    return elements;
}

export function renderImpactCategory(graph, report, category) {
    const rootId = 'impact-category-selection';
    const memberIds = new Set();

    (report.affected_symbols || []).forEach((symbol) => {
        if ((symbol.reasons || []).some((reason) => reason.category === category)) memberIds.add(symbol.node_id);
    });
    const section = CATEGORY_SECTIONS[category];
    if (section) (report[section] || []).forEach((row) => memberIds.add(row.node_id));
    if (category === 'test_scope') (report.tests || []).forEach((row) => memberIds.add(row.test_node_id));

    const ids = [...memberIds].filter(Boolean).sort().slice(0, 60);
    const columns = Math.min(6, Math.max(3, Math.ceil(Math.sqrt(ids.length || 1))));
    const rows = Math.max(1, Math.ceil(ids.length / columns));
    const elements = [{
        data: {
            id: rootId,
            label: `${categoryLabel(category)}\n${memberIds.size} affected`,
            kind: 'impact_category_root',
            category,
        },
        position: { x: 0, y: Math.max(0, (rows - 1) * 48) },
        classes: 'changed impact-summary',
    }];

    ids.forEach((nodeId, index) => {
        const id = `impact-member-${index}`;
        elements.push({
            data: {
                id,
                nodeId,
                drilldownId: nodeId.startsWith('process:') ? nodeId.slice('process:'.length) : nodeId,
                label: impactNodeLabel(nodeId, 'affected', category),
                kind: 'impact_member',
                category,
            },
            position: { x: 260 + (index % columns) * 220, y: Math.floor(index / columns) * 96 },
            classes: 'affected impact-member',
        });
        elements.push({
            data: { id: `impact-member-edge-${index}`, source: rootId, target: id, category, label: '' },
            classes: 'reason-path impact-summary-edge',
        });
    });

    graph.replace(elements, { name: 'preset', padding: 54 });
}

export function impactElements(report, activeCategories = impactCategories(report)) {
    if (report.selection?.type === 'module') {
        return moduleImpactElements(report, activeCategories);
    }

    const active = new Set(activeCategories);
    const nodes = new Map();
    const edges = new Map();
    const positions = { changed: 0, reason: 0, affected: 0 };
    const sharedResources = new Map();

    (report.shared_resources || []).forEach((row) => {
        sharedResources.set(`${row.node_id}\u0000${row.reason?.sentence || ''}`, row.resource_node_id);
    });

    const addNode = (id, region, extra = {}) => {
        if (!id || nodes.has(id)) return;
        const index = positions[region]++;
        const resource = /^(model|table|column|cache|storage):/.test(id);
        nodes.set(id, {
            data: {
                id,
                nodeId: id,
                label: impactNodeLabel(id, region, extra.category),
                kind: resource ? 'resource' : (extra.kind || 'symbol'),
                region,
                category: extra.category || null,
                level: extra.level || null,
                resource,
                shape: resource ? 'barrel' : 'ellipse',
                evidenceIds: extra.evidenceIds || [],
            },
            position: { x: region === 'changed' ? 0 : (region === 'reason' ? 420 : 840), y: 70 + index * 92 },
            classes: [region, region === 'reason' ? 'reason-path' : '', extra.frontier ? 'frontier' : ''].filter(Boolean).join(' '),
        });
    };

    (report.changed_symbols || []).forEach((change) => {
        const id = change.new_node_id || change.old_node_id;
        addNode(id, 'changed', { evidenceIds: [change.evidence_id] });
    });

    (report.affected_symbols || []).forEach((symbol) => {
        (symbol.reasons || []).forEach((reason) => {
            if (!active.has(reason.category)) return;
            const chain = reason.node_chain || [];
            chain.forEach((id, index) => {
                const region = index === 0 ? 'changed' : (index === chain.length - 1 ? 'affected' : 'reason');
                addNode(id, region, {
                    category: reason.category,
                    level: reason.level,
                    evidenceIds: reason.evidence_ids || [],
                });

                if (index === 0) return;
                const edgeId = reason.edge_chain?.[index - 1] || `reason-path:${reason.category}:${chain[index - 1]}:${id}`;
                if (!edges.has(edgeId)) {
                    edges.set(edgeId, {
                        data: {
                            id: edgeId,
                            source: chain[index - 1],
                            target: id,
                            category: reason.category,
                            level: reason.level,
                            label: '',
                            evidenceIds: reason.evidence_ids || [],
                        },
                        classes: 'reason-path',
                    });
                }
            });

            const resource_node_id = sharedResources.get(`${symbol.node_id}\u0000${reason.sentence || ''}`);
            if (resource_node_id) addNode(resource_node_id, 'reason', { category: reason.category });
        });
    });

    Object.entries(report.truncation || {}).forEach(([category, truncation]) => {
        if (!truncation?.truncated || !active.has(category)) return;
        (truncation.frontier || []).forEach((id) => addNode(id, 'affected', { category, frontier: true }));
    });

    Object.entries(CATEGORY_SECTIONS).forEach(([category, section]) => {
        if (!active.has(category)) return;
        (report[section] || []).forEach((row) => addNode(row.node_id, 'affected', {
            category,
            level: row.reason?.level,
            evidenceIds: row.reason?.evidence_ids || [],
        }));
    });

    (report.tests || []).forEach((test) => addNode(test.test_node_id, 'affected', {
        category: 'test_scope', evidenceIds: test.evidence_ids || [], kind: 'test',
    }));

    return [...nodes.values(), ...edges.values()];
}

export function impactEvidenceForSelection(report, selection) {
    const ids = new Set(selection?.evidenceIds || []);
    return (report.evidence || []).filter((record) => ids.has(record.id));
}

export function renderImpact(graph, report, activeCategories) {
    const moduleImpact = report.selection?.type === 'module';
    graph.replace(impactElements(report, activeCategories), moduleImpact
        ? { name: 'breadthfirst', directed: true, roots: '#impact-module-summary', padding: 54, spacingFactor: 1.3 }
        : { name: 'preset', padding: 54 });
}
