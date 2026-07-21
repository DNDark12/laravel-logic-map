import { createApi, LogicMapApiError } from './api.js';
import { createGraph } from './graph.js';
import { renderWorkflow, workflowEvidenceForSelection } from './workflow-view.js';
import { impactCategories, renderImpact, renderImpactCategory, impactEvidenceForSelection } from './impact-view.js';

const MAX_DETAIL_ROWS = 50;
const MAX_EVIDENCE_ROWS = 100;
const TECHNICAL_MODULES = new Set([
    'Broadcasting', 'Console', 'Core', 'Exceptions', 'Helpers', 'Http', 'Listeners',
    'Models', 'Providers', 'Repositories', 'Services', 'Traits', 'View',
]);

const root = document.getElementById('logic-map-app');
const elements = {
    status: document.getElementById('logic-map-status'),
    search: document.getElementById('logic-map-search'),
    results: document.getElementById('logic-map-search-results'),
    error: document.getElementById('logic-map-error'),
    workspace: document.querySelector('.lm-workspace'),
    graph: document.getElementById('logic-map-graph'),
    detail: document.getElementById('logic-map-detail'),
    evidence: document.getElementById('logic-map-evidence-list'),
    evidenceCount: document.getElementById('logic-map-evidence-count'),
    modes: [...document.querySelectorAll('[data-mode]')],
    interactionModes: [...document.querySelectorAll('[data-interaction-mode]')],
    interactionDescription: document.getElementById('logic-map-interaction-description'),
    modeDescription: document.getElementById('logic-map-mode-description'),
    searchHelp: document.querySelector('.lm-search-help'),
    moduleBrowser: document.querySelector('.lm-module-browser'),
    moduleCount: document.getElementById('logic-map-module-count'),
    moduleShortcuts: document.getElementById('logic-map-module-shortcuts'),
    back: document.getElementById('logic-map-back'),
    viewport: document.getElementById('logic-map-viewport'),
    detailToggle: document.getElementById('logic-map-toggle-detail'),
    breadcrumb: document.getElementById('logic-map-breadcrumb'),
};

const modeDescriptions = {
    symbol: 'Direct relationships: click a class to inspect its methods, callers, dependencies, effects, and evidence.',
    workflow: 'Execution paths: click a path or step to drill down; pan the canvas to follow services, decisions, async work, and data effects.',
    impact: 'Potential blast radius: click a category to reveal affected symbols, workflows, modules, shared resources, and tests.',
};

const api = createApi(root.dataset.apiBase);
const state = {
    mode: 'symbol', status: null, query: '', searchResults: [], selectedId: null,
    context: null, workflow: null, workflowMeta: null, impact: null, impactMeta: null,
    isLoading: false, error: null,
    impactFilters: new Set(),
    modules: [], navigationStack: [], currentLabel: null,
    interactionMode: 'view',
};
let searchController = null;
let contextController = null;
let searchTimer = null;
let activeResult = -1;

const graph = createGraph(elements.graph, (selection) => {
    if (state.mode === 'impact' && selection.kind === 'impact_category') {
        if (state.navigationStack.at(-1)?.type !== 'impact_summary') {
            state.navigationStack.push({ type: 'impact_summary', id: state.selectedId, label: 'Impact summary' });
        }
        renderImpactCategory(graph, state.impact || {}, selection.category);
        renderNodeDetail(selection);
        renderNavigation();
        return;
    }

    if (state.mode === 'impact' && selection.kind === 'impact_module') {
        if (state.navigationStack.at(-1)?.type === 'impact_summary') state.navigationStack.pop();
        renderImpact(graph, state.impact || {}, state.impactFilters);
        renderImpactDetail(state.impact || {});
        renderNavigation();
        return;
    }

    if (state.mode === 'impact' && selection.kind === 'impact_member' && selection.nodeId) {
        if (state.navigationStack.at(-1)?.type === 'impact_summary') state.navigationStack.pop();
        const previous = state.selectedId ? {
            type: 'symbol',
            id: state.selectedId,
            label: state.currentLabel || navigationLabel(state.context?.symbol, state.selectedId),
            mode: state.mode,
        } : null;
        if (previous) state.navigationStack.push(previous);
        renderModeChrome(selection.category === 'workflow' ? 'workflow' : state.mode);
        selectSymbol(selection.drilldownId || selection.nodeId, { recordHistory: false });
        return;
    }

    if (state.mode === 'workflow' && selection.kind === 'workflow_entry' && selection.nodeId) {
        selectSymbol(selection.nodeId);
        return;
    }

    const targetId = selection.nodeId || (state.mode === 'symbol' ? selection.id : null);
    if (targetId) {

        if (!selection.source && !selection.target && targetId !== state.selectedId
            && !['workflow_collection', 'impact_category_root'].includes(selection.kind)) {
            selectSymbol(targetId);
            return;
        }
    }

    const evidence = state.mode === 'workflow'
        ? workflowEvidenceForSelection(state.workflow || {}, selection)
        : (state.mode === 'impact'
            ? impactEvidenceForSelection(state.impact || {}, selection)
            : (state.context?.evidence || []).filter((row) => selection.evidenceIds?.includes(row.id)));

    if (evidence.length) renderEvidence(evidence);

    if (selection.source && selection.target) {
        return;
    }

    renderNodeDetail(selection);
});

function setInteractionMode(mode) {
    const interactionMode = mode === 'arrange' ? 'arrange' : 'view';
    state.interactionMode = interactionMode;
    root.dataset.interactionMode = interactionMode;
    graph.setInteractionMode(interactionMode);

    elements.interactionModes.forEach((button) => {
        const active = button.dataset.interactionMode === interactionMode;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', String(active));
    });

    if (elements.interactionDescription) {
        elements.interactionDescription.textContent = interactionMode === 'view'
            ? 'Node positions are locked. Click to inspect; pan and zoom remain available.'
            : 'Drag nodes to arrange this temporary view. Switch back to View to lock them.';
    }
}

function setLoading(loading) {
    state.isLoading = loading;
    elements.workspace.setAttribute('aria-busy', String(loading));
    root.classList.toggle('is-loading', loading);
}

function showError(error) {
    state.error = error;
    elements.error.textContent = error instanceof LogicMapApiError
        ? `${error.message}${error.errors?.code ? ` (${error.errors.code})` : ''}`
        : error?.message || 'Unexpected Logic Map error.';
    elements.error.hidden = false;
}

function clearError() {
    state.error = null;
    elements.error.hidden = true;
    elements.error.textContent = '';
}

function renderStatus(payload) {
    state.status = payload.data;
    const status = payload.data;
    elements.status.textContent = status.active
        ? `${status.counts?.nodes ?? 0} symbols · ${status.counts?.edges ?? 0} relations`
        : 'Index required: php artisan logic-map:index';
    elements.status.classList.toggle('is-ready', Boolean(status.active));
}

function renderModeDescription(mode = state.mode) {
    if (elements.modeDescription) elements.modeDescription.textContent = modeDescriptions[mode] || '';
}

function navigationLabel(node, fallback = state.selectedId) {
    if (!node) return fallback || 'Selection';
    if (node.kind === 'route') return String(node.id || fallback).replace(/^route:/, '').replace(':', ' /');
    if (node.kind === 'module') return node.name || String(node.id || fallback).replace(/^module:/, '');

    const qualified = node.qualified_name || node.qualifiedName || '';
    if (qualified.includes('::')) {
        const [owner, method] = qualified.split('::');
        return `${owner.split('\\').at(-1)} · ${method}()`;
    }

    return node.name || qualified.split('\\').at(-1) || fallback || 'Selection';
}

function renderNavigation() {
    const target = state.navigationStack.at(-1);
    elements.back.disabled = !target;
    elements.back.textContent = target ? `← Back to ${target.label}` : '← Back';
    elements.breadcrumb.replaceChildren();

    const trail = [
        ...state.navigationStack.filter((entry) => entry.type === 'symbol').slice(-3),
        ...(state.selectedId ? [{ id: state.selectedId, label: state.currentLabel || state.selectedId }] : []),
    ];

    if (!trail.length) {
        const empty = document.createElement('li');
        empty.textContent = 'Choose a module or search for a symbol';
        elements.breadcrumb.append(empty);
        return;
    }

    trail.forEach((entry) => {
        const item = document.createElement('li');
        item.textContent = entry.label;
        item.title = entry.id || entry.label;
        elements.breadcrumb.append(item);
    });
}

function toggleDetailPanel() {
    const collapsed = elements.workspace.classList.toggle('is-detail-collapsed');
    elements.detailToggle.textContent = collapsed ? 'Show details' : 'Collapse details';
    elements.detailToggle.setAttribute('aria-expanded', String(!collapsed));
    requestAnimationFrame(() => {
        graph.resize();
        graph.readable();
    });
}

async function navigateBack() {
    const target = state.navigationStack.pop();
    if (!target) return;

    if (target.type === 'impact_summary') {
        renderImpact(graph, state.impact || {}, state.impactFilters);
        renderImpactDetail(state.impact || {});
        renderNavigation();
        return;
    }

    if (target.mode && target.mode !== state.mode) renderModeChrome(target.mode);
    const restored = await selectSymbol(target.id, { recordHistory: false });
    if (!restored) state.navigationStack.push(target);
    renderNavigation();
}

function renderModuleShortcuts() {
    elements.moduleShortcuts.replaceChildren();

    const modules = state.modules
        .filter((module) => (module.member_count || 0) > 0 && !TECHNICAL_MODULES.has(module.name))
        .sort((left, right) => left.name.localeCompare(right.name));

    if (elements.moduleCount) elements.moduleCount.textContent = `${modules.length} indexed domains`;

    modules.forEach((module) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.id = module.id;
            button.classList.toggle('is-current', module.id === state.selectedId);
            const name = document.createElement('span');
            name.textContent = module.name;
            const count = document.createElement('small');
            count.textContent = `${module.member_count || 0}`;
            button.title = `Open ${module.name} (${module.member_count || 0} indexed symbols)`;
            button.append(name, count);
            button.addEventListener('click', () => {
                elements.moduleBrowser?.removeAttribute('open');
                selectSymbol(module.id);
            });
            elements.moduleShortcuts.append(button);
        });
}

function renderSearchResults() {
    elements.results.replaceChildren();
    elements.results.hidden = state.searchResults.length === 0;
    elements.search.setAttribute('aria-expanded', String(state.searchResults.length > 0));

    state.searchResults.forEach((result, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.role = 'option';
        button.className = index === activeResult ? 'is-active' : '';
        button.dataset.id = result.id;

        const title = document.createElement('strong');
        title.textContent = result.qualified_name || result.name || result.id;
        const meta = document.createElement('span');
        meta.textContent = `${result.kind} · ${result.location?.file || result.id}`;
        button.append(title, meta);
        button.addEventListener('click', () => selectSymbol(result.id));
        elements.results.append(button);
    });
}

function clearSearchResults() {
    state.searchResults = [];
    activeResult = -1;
    renderSearchResults();
}

function appendField(container, label, value) {
    if (value === null || value === undefined || value === '') return;
    const row = document.createElement('div');
    row.className = 'lm-field';
    const key = document.createElement('dt');
    key.textContent = label;
    const data = document.createElement('dd');
    data.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
    row.append(key, data);
    container.append(row);
}

function renderNodeDetail(node = state.context?.symbol) {
    elements.detail.replaceChildren();
    if (!node) return;

    const heading = document.createElement('h2');
    heading.textContent = node.qualified_name || node.label || node.name || node.id;
    const kind = document.createElement('span');
    kind.className = 'lm-kind';
    kind.textContent = node.kind || 'relation';
    const fields = document.createElement('dl');
    appendField(fields, 'Canonical ID', node.nodeId || node.id);
    appendField(fields, 'File', node.location?.file);
    appendField(fields, 'Lines', node.location ? `${node.location.start_line}–${node.location.end_line}` : null);
    appendField(fields, 'Attributes', node.attributes);
    appendField(fields, 'Runtime evidence', state.context?.runtime?.coverage);
    elements.detail.append(kind, heading, fields);

    if (state.context?.runtime) {
        appendList(elements.detail, 'Runtime relations', runtimeRelationLabels(state.context.runtime));
    }
}

function runtimeRelationLabels(runtime) {
    return (runtime?.relations || []).map((relation) => (
        `${relation.source_node_id} —${relation.type}→ ${relation.target_node_id} · ${relation.coverage}`
    ));
}

function appendSummary(container, values) {
    const summary = document.createElement('dl');
    summary.className = 'lm-summary';
    Object.entries(values || {}).forEach(([label, value]) => appendField(summary, label.replaceAll('_', ' '), value));
    container.append(summary);
}

function appendList(container, title, rows) {
    const heading = document.createElement('h3');
    heading.textContent = title;
    const list = document.createElement('ul');
    list.className = 'lm-compact-list';

    const allRows = rows || [];
    allRows.slice(0, MAX_DETAIL_ROWS).forEach((row) => {
        const item = document.createElement('li');
        item.textContent = typeof row === 'string'
            ? row
            : (row.node_id || row.test_node_id || row.reason?.sentence || JSON.stringify(row));
        list.append(item);
    });

    if (!list.children.length) {
        const item = document.createElement('li');
        item.textContent = 'None';
        list.append(item);
    } else if (allRows.length > MAX_DETAIL_ROWS) {
        const item = document.createElement('li');
        item.textContent = `Showing first ${MAX_DETAIL_ROWS} of ${allRows.length}`;
        list.append(item);
    }

    container.append(heading, list);
}

function uniqueNodeIds(rows, key = 'node_id') {
    return [...new Set((rows || []).map((row) => row?.[key]).filter(Boolean))];
}

export function downloadText(filename, content, mime = 'text/plain') {
    const url = URL.createObjectURL(new Blob([content], { type: `${mime};charset=utf-8` }));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

export async function copyText(content) {
    await navigator.clipboard.writeText(content);
}

function exportButton(label, action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.addEventListener('click', async () => {
        clearError();
        button.disabled = true;
        try { await action(); } catch (error) { showError(error); } finally { button.disabled = false; }
    });
    return button;
}

function renderExportActions(kind) {
    const actions = document.createElement('div');
    actions.className = 'lm-export-actions';
    const report = kind === 'workflow' ? state.workflow : state.impact;
    const json = `${JSON.stringify(report, null, 2)}\n`;
    actions.append(
        exportButton('Copy JSON', () => copyText(json)),
        exportButton('Download JSON', () => downloadText(`${kind}.json`, json, 'application/json')),
    );

    if (kind === 'workflow') {
        if (!Array.isArray(report.entry_workflows)) {
            actions.append(
                exportButton('Download Markdown', async () => {
                    const payload = await api.workflowExport(state.selectedId, 'markdown');
                    downloadText('workflow.md', payload.data.content, 'text/markdown');
                }),
                exportButton('Download Mermaid', async () => {
                    const payload = await api.workflowExport(state.selectedId, 'mermaid');
                    downloadText('workflow.mmd', payload.data.content);
                }),
            );
        }
    } else {
        actions.append(exportButton('Download Markdown', async () => {
            const payload = await api.impactExport({ symbol: state.selectedId }, 'markdown');
            downloadText('impact.md', payload.data.content, 'text/markdown');
        }));
    }

    elements.detail.append(actions);
}

function renderWorkflowDetail(workflow) {
    elements.detail.replaceChildren();
    const kind = document.createElement('span');
    kind.className = 'lm-kind';
    kind.textContent = Array.isArray(workflow.entry_workflows) ? 'workflow collection' : 'workflow';
    const heading = document.createElement('h2');
    heading.textContent = workflow.module?.name || workflow.selection?.name || workflow.entrypoint?.node_id || 'Workflow';
    elements.detail.append(kind, heading);
    appendSummary(elements.detail, workflow.summary);
    if (Array.isArray(workflow.entry_workflows)) {
        appendList(elements.detail, 'Entry workflows', workflow.entry_workflows.map((entry) => (
            `${entry.entrypoint?.node_id || entry.identity?.workflow_id} · ${entry.summary?.step_count || 0} steps`
        )));
    } else {
        appendList(elements.detail, 'Modules', (workflow.modules || []).map((module) => `${module.name} · ${module.step_ids.length} steps`));
    }
    appendList(elements.detail, 'Gaps', (workflow.gaps || []).map((gap) => gap.reason || gap.message || gap.step_id));
    appendList(elements.detail, `Runtime evidence · ${workflow.runtime?.coverage || 'No runtime data available'}`, runtimeRelationLabels(workflow.runtime));
    const truncation = document.createElement('dl');
    appendField(truncation, 'Response limited', state.workflowMeta?.truncated
        ? `Showing ${workflow.entry_workflows?.length || 0} of ${workflow.summary?.entrypoint_count || 0} entry workflows`
        : null);
    appendField(truncation, 'Truncation', workflow.truncation || null);
    elements.detail.append(truncation);
    renderExportActions('workflow');
}

function renderImpactDetail(report) {
    elements.detail.replaceChildren();
    const kind = document.createElement('span');
    kind.className = 'lm-kind';
    kind.textContent = 'impact';
    const heading = document.createElement('h2');
    heading.textContent = report.selection?.name || state.selectedId || 'Git change set';
    elements.detail.append(kind, heading);
    appendSummary(elements.detail, report.summary);

    const filters = document.createElement('fieldset');
    filters.className = 'lm-impact-filters';
    const legend = document.createElement('legend');
    legend.textContent = 'Impact categories';
    filters.append(legend);

    impactCategories(report).forEach((category) => {
        const label = document.createElement('label');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = state.impactFilters.has(category);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked) state.impactFilters.add(category);
            else state.impactFilters.delete(category);
            renderImpact(graph, state.impact, state.impactFilters);
        });
        const text = document.createElement('span');
        text.textContent = category.replaceAll('_', ' ');
        label.append(checkbox, text);
        filters.append(label);
    });
    elements.detail.append(filters);

    appendList(elements.detail, 'Changed symbols', (report.changed_symbols || []).map((change) => (
        change.new_node_id || change.old_node_id || change.after_id || change.before_id || change.node_id || JSON.stringify(change)
    )));
    appendList(elements.detail, 'Affected symbols', report.affected_symbols);
    appendList(elements.detail, 'Affected workflows', uniqueNodeIds(report.workflows));
    appendList(elements.detail, 'Affected modules', uniqueNodeIds(report.modules));
    appendList(elements.detail, 'Selected tests', uniqueNodeIds(report.tests, 'test_node_id'));
    appendList(elements.detail, `Runtime evidence · ${report.runtime?.coverage || 'No runtime data available'}`, runtimeRelationLabels(report.runtime));
    const truncation = document.createElement('dl');
    appendField(truncation, 'Response limited', state.impactMeta?.truncated
        ? `${report.affected_symbols?.length || 0} of ${report.summary?.affected_symbol_count || 0} affected symbols returned`
        : null);
    appendField(truncation, 'Truncation and frontier', report.truncation);
    elements.detail.append(truncation);
    renderExportActions('impact');
}

function renderEvidence(records = state.context?.evidence || []) {
    elements.evidence.replaceChildren();
    elements.evidenceCount.textContent = `${records.length} record${records.length === 1 ? '' : 's'}`;

    records.slice(0, MAX_EVIDENCE_ROWS).forEach((record) => {
        const card = document.createElement('article');
        const heading = document.createElement('h3');
        heading.textContent = record.detector || record.origin || 'Evidence';
        const fields = document.createElement('dl');
        appendField(fields, 'Origin', record.origin);
        appendField(fields, 'Certainty', record.certainty);
        appendField(fields, 'Location', record.location ? `${record.location.file}:${record.location.start_line}` : null);
        appendField(fields, 'Expression', record.expression);
        appendField(fields, 'Condition', record.condition);
        appendField(fields, 'Attributes', record.attributes);
        card.append(heading, fields);
        elements.evidence.append(card);
    });

    if (records.length > MAX_EVIDENCE_ROWS) {
        const note = document.createElement('p');
        note.textContent = `Showing first ${MAX_EVIDENCE_ROWS} of ${records.length} evidence records.`;
        elements.evidence.append(note);
    }
}

async function runSearch(query) {
    searchController?.abort();
    searchController = new AbortController();
    clearError();

    try {
        const payload = await api.search(query, { signal: searchController.signal });
        state.searchResults = payload.data.results;
        activeResult = state.searchResults.length ? 0 : -1;
        renderSearchResults();
    } catch (error) {
        if (error.name !== 'AbortError') showError(error);
    }
}

async function selectSymbol(id, { recordHistory = true } = {}) {
    contextController?.abort();
    const controller = new AbortController();
    contextController = controller;
    const previous = state.selectedId && state.selectedId !== id ? {
        type: 'symbol',
        id: state.selectedId,
        label: state.currentLabel || navigationLabel(state.context?.symbol, state.selectedId),
        mode: state.mode,
    } : null;
    elements.results.hidden = true;
    clearError();
    setLoading(true);

    try {
        const payload = await api.context(id, { signal: controller.signal });
        if (recordHistory && previous) {
            const last = state.navigationStack.at(-1);
            if (last?.type !== 'symbol' || last.id !== previous.id) state.navigationStack.push(previous);
            if (state.navigationStack.length > 30) state.navigationStack.shift();
        }
        state.selectedId = id;
        state.context = payload.data;
        state.currentLabel = navigationLabel(payload.data.symbol, id);
        graph.replaceContext(payload.data);
        renderNodeDetail(payload.data.symbol);
        renderEvidence(payload.data.evidence);

        if (state.mode === 'workflow') await loadWorkflow();
        if (state.mode === 'impact') await loadImpact();
        renderNavigation();
        renderModuleShortcuts();
        return true;
    } catch (error) {
        if (error.name !== 'AbortError') showError(error);
        return false;
    } finally {
        if (!controller.signal.aborted) setLoading(false);
    }
}

async function loadWorkflow() {
    if (!state.selectedId) return;
    setLoading(true);
    clearError();

    try {
        const payload = await api.workflow(state.selectedId);
        state.workflow = payload.data;
        state.workflowMeta = payload.meta;
        renderWorkflow(graph, state.workflow);
        renderWorkflowDetail(state.workflow);
        renderEvidence(state.workflow.evidence || []);
    } catch (error) {
        showError(error);
        graph.clear();
    } finally {
        setLoading(false);
    }
}

async function loadImpact() {
    if (!state.selectedId) return;
    setLoading(true);
    clearError();

    try {
        const payload = await api.impact({ symbol: state.selectedId });
        state.impact = payload.data;
        state.impactMeta = payload.meta;
        state.impactFilters = new Set(impactCategories(state.impact));
        renderImpact(graph, state.impact, state.impactFilters);
        renderImpactDetail(state.impact);
        renderEvidence(state.impact.evidence || []);
    } catch (error) {
        showError(error);
        graph.clear();
    } finally {
        setLoading(false);
    }
}

function renderModeChrome(mode) {
    state.mode = mode;
    root.dataset.mode = mode;
    elements.modes.forEach((button) => button.classList.toggle('is-active', button.dataset.mode === mode));
    renderModeDescription(mode);
    renderNavigation();
}

async function setMode(mode) {
    state.navigationStack = state.navigationStack.filter((entry) => entry.type === 'symbol');
    renderModeChrome(mode);

    if (!state.selectedId) {
        elements.detail.replaceChildren();
        const message = document.createElement('div');
        message.className = 'lm-empty';
        const title = document.createElement('h2');
        title.textContent = `${mode[0].toUpperCase()}${mode.slice(1)} explorer`;
        const body = document.createElement('p');
        body.textContent = 'Select a symbol to continue.';
        message.append(title, body);
        elements.detail.append(message);
        graph.clear();
    } else if (mode === 'symbol' && state.context) {
        graph.replaceContext(state.context);
        renderNodeDetail();
        renderEvidence();
    } else if (mode === 'workflow') {
        await loadWorkflow();
    } else if (mode === 'impact') {
        await loadImpact();
    }
}

elements.search.addEventListener('input', () => {
    state.query = elements.search.value.trim();
    clearTimeout(searchTimer);
    searchController?.abort();
    clearSearchResults();

    if (state.query.length < 2) {
        return;
    }

    searchTimer = setTimeout(() => runSearch(state.query), 220);
});

elements.search.addEventListener('focus', () => {
    if (state.searchResults.length) renderSearchResults();
});

elements.search.addEventListener('click', () => {
    if (state.searchResults.length) renderSearchResults();
});

elements.search.addEventListener('keydown', async (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        clearTimeout(searchTimer);

        if (!state.searchResults.length && state.query.length >= 2) {
            await runSearch(state.query);
        }

        const result = state.searchResults[activeResult >= 0 ? activeResult : 0];

        if (result) {
            elements.results.hidden = true;
            elements.search.setAttribute('aria-expanded', 'false');
            await selectSymbol(result.id);
        }

        return;
    }

    if (!state.searchResults.length) return;

    if (event.key === 'ArrowDown') activeResult = Math.min(activeResult + 1, state.searchResults.length - 1);
    else if (event.key === 'ArrowUp') activeResult = Math.max(activeResult - 1, 0);
    else if (event.key === 'Escape') {
        elements.results.hidden = true;
        elements.search.setAttribute('aria-expanded', 'false');
        event.preventDefault();
        return;
    } else return;

    event.preventDefault();
    renderSearchResults();
});

document.addEventListener('pointerdown', (event) => {
    if (!event.target.closest('.lm-search-panel')) {
        elements.results.hidden = true;
        elements.search.setAttribute('aria-expanded', 'false');
    }
    if (!event.target.closest('.lm-search-help')) elements.searchHelp?.removeAttribute('open');
    if (!event.target.closest('.lm-module-browser')) elements.moduleBrowser?.removeAttribute('open');
});

elements.back.addEventListener('click', navigateBack);
elements.viewport.addEventListener('change', () => {
    if (elements.viewport.value === 'focus') graph.focusMain();
    else if (elements.viewport.value === 'readable') graph.readable();
    else if (elements.viewport.value === 'fit') graph.fit();
    elements.viewport.value = '';
});
elements.detailToggle.addEventListener('click', toggleDetailPanel);
elements.modes.forEach((button) => button.addEventListener('click', () => setMode(button.dataset.mode)));
elements.interactionModes.forEach((button) => button.addEventListener('click', () => {
    setInteractionMode(button.dataset.interactionMode);
}));

async function init() {
    renderModeDescription();
    renderNavigation();
    setInteractionMode(state.interactionMode);
    try {
        const [status, modules] = await Promise.all([api.status(), api.modules()]);
        renderStatus(status);
        state.modules = modules.data.modules || [];
        renderModuleShortcuts();
    } catch (error) {
        showError(error);
        elements.status.textContent = 'Status unavailable';
    }
}

window.LogicMap = Object.freeze({ state, api, graph, selectSymbol, setMode, setInteractionMode, navigateBack, fit: graph.fit });
init();
