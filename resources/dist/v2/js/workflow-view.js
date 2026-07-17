const RESOURCE_PREFIX = /^(model|table|column|cache|storage):/;

export function workflowElements(workflow) {
    const moduleByStep = new Map();
    const transactionIds = new Map();
    const elements = [];

    (workflow.modules || []).forEach((module, index) => {
        const id = `module-lane:${index}:${module.name}`;
        elements.push({
            data: { id, label: module.name, kind: 'module_lane' },
            classes: 'module-lane',
        });
        (module.step_ids || []).forEach((stepId) => moduleByStep.set(stepId, id));
    });

    (workflow.transactions || []).forEach((transaction) => {
        (transaction.step_ids || []).forEach((stepId) => {
            const ids = transactionIds.get(stepId) || [];
            ids.push(transaction.id);
            transactionIds.set(stepId, ids);
        });
    });

    (workflow.steps || []).forEach((step) => {
        const resource = RESOURCE_PREFIX.test(step.node_id || '');
        const shape = step.kind === 'decision' ? 'diamond' : (resource ? 'barrel' : 'ellipse');
        const classes = ['workflow-step', step.kind];
        if (step.kind === 'gap') classes.push('gap');
        if (step.kind === 'cycle') classes.push('cycle');

        elements.push({
            data: {
                id: step.id,
                label: step.label,
                kind: step.kind,
                nodeId: step.node_id,
                module: step.module,
                parent: moduleByStep.get(step.id),
                shape,
                resource,
                evidenceIds: step.evidence_ids || [],
                transactionIds: transactionIds.get(step.id) || [],
                attributes: step.attributes || {},
            },
            classes: classes.join(' '),
        });
    });

    (workflow.transitions || []).forEach((transition, index) => {
        const async = transition.boundary !== 'sync';
        const lineStyle = async || transition.is_cycle ? 'dashed' : 'solid';
        elements.push({
            data: {
                id: `transition:${index}:${transition.from}:${transition.to}`,
                source: transition.from,
                target: transition.to,
                label: transition.condition || transition.branch || (async ? transition.boundary : ''),
                boundary: transition.boundary,
                lineStyle,
                isCycle: Boolean(transition.is_cycle),
                evidenceIds: transition.evidence_ids || [],
            },
            classes: [async ? 'async' : '', transition.is_cycle ? 'cycle' : ''].filter(Boolean).join(' '),
        });
    });

    return elements;
}

export function workflowEvidenceForSelection(workflow, selection) {
    const ids = new Set(selection?.evidenceIds || []);
    return (workflow.evidence || []).filter((record) => ids.has(record.id));
}

export function renderWorkflow(graph, workflow) {
    graph.replace(workflowElements(workflow), {
        name: 'breadthfirst',
        directed: true,
        padding: 54,
        spacingFactor: 1.42,
        roots: workflow.entrypoint?.step_id ? `#${CSS.escape(workflow.entrypoint.step_id)}` : undefined,
    });
}
