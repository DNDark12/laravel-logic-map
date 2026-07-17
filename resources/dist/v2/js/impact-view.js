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
    return [...categories].sort();
}

export function impactElements(report, activeCategories = impactCategories(report)) {
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
                label: id,
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
    graph.replace(impactElements(report, activeCategories), { name: 'preset', padding: 54 });
}
