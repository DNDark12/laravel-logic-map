const RESOURCE_PREFIX = /^(model|table|column|cache|storage):/;

function scopedId(prefix, id) {
    return prefix ? `${prefix}${id}` : id;
}

function humanLabel(id) {
    const value = String(id || 'Workflow');

    if (value.startsWith('route:')) {
        const [, method, ...uriParts] = value.split(':');
        const uri = uriParts.join(':').replace(/^\/+/, '');
        return `${method} /${uri}`;
    }

    const payload = value.includes(':') ? value.slice(value.indexOf(':') + 1) : value;
    const tail = payload.split('\\').at(-1) || payload;
    return tail.replace('::', ' · ');
}

function workflowSemanticRole(step) {
    const nodeId = String(step.node_id || '');
    const nodeKind = String(step.attributes?.node_kind || step.kind || '').toLowerCase();

    if (step.kind === 'entry' || nodeKind === 'route' || nodeId.startsWith('route:')) return 'HTTP entry';
    if (nodeId.startsWith('middleware:') || nodeKind === 'middleware') return 'Request guard';
    if (step.kind === 'async_boundary') return 'Async handoff';
    if (step.kind === 'effect' || RESOURCE_PREFIX.test(nodeId)) return 'Data / external effect';
    if (nodeKind === 'job' || nodeId.includes('\\Jobs\\')) return 'Queue job';
    if (nodeKind === 'event' || nodeId.includes('\\Events\\')) return 'Domain event';
    if (nodeKind === 'listener' || nodeId.includes('\\Listeners\\')) return 'Event listener';
    if (nodeId.includes('\\Http\\Controllers\\')) return 'Controller action';
    if (nodeId.includes('\\Http\\Requests\\')) return 'Request validation';
    if (nodeId.includes('\\Policies\\')) return 'Authorization';
    if (nodeId.includes('\\Services\\') || nodeId.includes('\\Actions\\')) return 'Domain logic';
    if (nodeId.includes('\\Repositories\\')) return 'Data access';
    if (nodeId.includes('\\Models\\') || nodeKind === 'model') return 'Model logic';
    if (nodeId.includes('ApiResponse') || nodeId.includes('\\Traits\\')) return 'Response / helper';
    if (step.kind === 'decision') return 'Decision';
    if (step.kind === 'gap') return 'Unresolved step';

    return nodeKind && nodeKind !== 'symbol'
        ? nodeKind.replaceAll('_', ' ')
        : 'Application step';
}

function workflowStepLabel(step) {
    const label = humanLabel(step.node_id || step.label);
    const role = workflowSemanticRole(step);

    return `${label}\n${role}${step.module ? ` · ${step.module}` : ''}`;
}

export function workflowEntryLabel(entry) {
    const summary = entry.summary || {};
    const areas = summary.module_count || 0;
    return `${humanLabel(entry.entrypoint?.node_id || entry.identity?.workflow_id)}\n${summary.step_count || 0} steps · ${areas} ${areas === 1 ? 'area' : 'areas'}`;
}

function balancedWorkflowCollectionPosition(index, columns) {
    const column = index % columns;
    const row = Math.floor(index / columns);

    return {
        x: (column - (columns - 1) / 2) * 240,
        y: 170 + row * 112,
    };
}

function singleWorkflowElements(workflow, prefix = '') {
    const moduleByStep = new Map();
    const transactionIds = new Map();
    const elements = [];

    (workflow.modules || []).forEach((module, index) => {
        const id = scopedId(prefix, `module-lane:${index}:${module.name}`);
        elements.push({
            data: { id, label: module.name, kind: 'module_lane' },
            classes: 'module-lane',
        });
        (module.step_ids || []).forEach((stepId) => moduleByStep.set(scopedId(prefix, stepId), id));
    });

    (workflow.transactions || []).forEach((transaction) => {
        (transaction.step_ids || []).forEach((stepId) => {
            const scopedStepId = scopedId(prefix, stepId);
            const ids = transactionIds.get(scopedStepId) || [];
            ids.push(transaction.id);
            transactionIds.set(scopedStepId, ids);
        });
    });

    (workflow.steps || []).forEach((step) => {
        const resource = RESOURCE_PREFIX.test(step.node_id || '');
        const shape = step.kind === 'decision' ? 'diamond' : (resource ? 'barrel' : 'ellipse');
        const classes = ['workflow-step', step.kind];
        if (step.kind === 'gap') classes.push('gap');
        if (step.kind === 'cycle') classes.push('cycle');

        const stepId = scopedId(prefix, step.id);
        elements.push({
            data: {
                id: stepId,
                label: workflowStepLabel(step),
                kind: step.kind,
                nodeId: step.node_id,
                module: step.module,
                parent: moduleByStep.get(stepId),
                shape,
                resource,
                evidenceIds: step.evidence_ids || [],
                transactionIds: transactionIds.get(stepId) || [],
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
                id: scopedId(prefix, `transition:${index}:${transition.from}:${transition.to}`),
                source: scopedId(prefix, transition.from),
                target: scopedId(prefix, transition.to),
                label: transition.condition || transition.branch || (async ? `${transition.boundary} handoff` : 'then'),
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

export function aggregateWorkflowElements(workflow) {
    const summaryId = 'workflow-collection-summary';
    const selection = workflow.module || workflow.selection || {};
    const selectionName = selection.name || humanLabel(selection.node_id) || 'Selection';
    const entries = workflow.entry_workflows || [];
    const columns = Math.min(4, Math.max(1, Math.ceil(Math.sqrt(entries.length || 1))));
    const elements = [{
        data: {
            id: summaryId,
            label: `${selectionName}\n${workflow.summary?.entrypoint_count || entries.length} execution paths`,
            kind: 'workflow_collection',
            nodeId: selection.node_id,
            module: selectionName,
        },
        position: { x: 0, y: 0 },
        classes: 'workflow-module',
    }];

    entries.forEach((entry, index) => {
        const id = `workflow-entry-${index}`;
        const nodeId = entry.entrypoint?.node_id;
        const summary = entry.summary || {};
        elements.push({
            data: {
                id,
                label: workflowEntryLabel(entry),
                kind: 'workflow_entry',
                nodeId,
                evidenceIds: entry.entrypoint?.evidence_ids || [],
                attributes: summary,
            },
            position: balancedWorkflowCollectionPosition(index, columns),
            classes: 'workflow-entry',
        });
        elements.push({
            data: {
                id: `workflow-entry-edge-${index}`,
                source: summaryId,
                target: id,
                label: '',
            },
            classes: 'workflow-summary-edge',
        });
    });

    return elements;
}

export function workflowElements(workflow) {
    if (!Array.isArray(workflow.entry_workflows)) {
        return singleWorkflowElements(workflow);
    }

    return aggregateWorkflowElements(workflow);
}

export function workflowEvidenceForSelection(workflow, selection) {
    const ids = new Set(selection?.evidenceIds || []);
    const evidence = Array.isArray(workflow.entry_workflows)
        ? workflow.entry_workflows.flatMap((entry) => entry.evidence || [])
        : (workflow.evidence || []);
    return evidence.filter((record) => ids.has(record.id));
}

export function renderWorkflow(graph, workflow) {
    const collection = Array.isArray(workflow.entry_workflows);
    graph.replace(workflowElements(workflow), collection
        ? { name: 'preset', padding: 54 }
        : {
            name: 'breadthfirst',
            directed: true,
            padding: 54,
            spacingFactor: 1.15,
            avoidOverlap: true,
            roots: workflow.entrypoint?.step_id ? `#${CSS.escape(workflow.entrypoint.step_id)}` : undefined,
        });
}
