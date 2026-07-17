import { createApi, LogicMapApiError } from './api.js';
import { createGraph } from './graph.js';
import { renderWorkflow, workflowEvidenceForSelection } from './workflow-view.js';
import { impactCategories, renderImpact, impactEvidenceForSelection } from './impact-view.js';

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
};

const api = createApi(root.dataset.apiBase);
const state = {
    mode: 'symbol', status: null, query: '', searchResults: [], selectedId: null,
    context: null, workflow: null, impact: null, isLoading: false, error: null,
    impactFilters: new Set(),
};
let searchController = null;
let contextController = null;
let searchTimer = null;
let activeResult = -1;

const graph = createGraph(elements.graph, (selection) => {
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
        ? `${status.node_count} symbols · ${status.edge_count} relations`
        : 'Index required: php artisan logic-map:index';
    elements.status.classList.toggle('is-ready', Boolean(status.active));
}

function renderSearchResults() {
    elements.results.replaceChildren();
    elements.results.hidden = state.searchResults.length === 0;

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
    appendField(fields, 'Canonical ID', node.id);
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

    (rows || []).forEach((row) => {
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
    }

    container.append(heading, list);
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
    kind.textContent = 'workflow';
    const heading = document.createElement('h2');
    heading.textContent = workflow.entrypoint?.node_id || 'Workflow';
    elements.detail.append(kind, heading);
    appendSummary(elements.detail, workflow.summary);
    appendList(elements.detail, 'Modules', (workflow.modules || []).map((module) => `${module.name} · ${module.step_ids.length} steps`));
    appendList(elements.detail, 'Gaps', (workflow.gaps || []).map((gap) => gap.reason));
    appendList(elements.detail, `Runtime evidence · ${workflow.runtime?.coverage || 'No runtime data available'}`, runtimeRelationLabels(workflow.runtime));
    const truncation = document.createElement('dl');
    appendField(truncation, 'Truncation', workflow.truncation);
    elements.detail.append(truncation);
    renderExportActions('workflow');
}

function renderImpactDetail(report) {
    elements.detail.replaceChildren();
    const kind = document.createElement('span');
    kind.className = 'lm-kind';
    kind.textContent = 'impact';
    const heading = document.createElement('h2');
    heading.textContent = state.selectedId || 'Git change set';
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

    appendList(elements.detail, 'Affected modules', report.modules);
    appendList(elements.detail, 'Selected tests', report.tests);
    appendList(elements.detail, `Runtime evidence · ${report.runtime?.coverage || 'No runtime data available'}`, runtimeRelationLabels(report.runtime));
    const truncation = document.createElement('dl');
    appendField(truncation, 'Truncation and frontier', report.truncation);
    elements.detail.append(truncation);
    renderExportActions('impact');
}

function renderEvidence(records = state.context?.evidence || []) {
    elements.evidence.replaceChildren();
    elements.evidenceCount.textContent = `${records.length} record${records.length === 1 ? '' : 's'}`;

    records.forEach((record) => {
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

        if (payload.data.selection) {
            await selectSymbol(payload.data.selection.id);
        }
    } catch (error) {
        if (error.name !== 'AbortError') showError(error);
    }
}

async function selectSymbol(id) {
    contextController?.abort();
    contextController = new AbortController();
    state.selectedId = id;
    elements.results.hidden = true;
    clearError();
    setLoading(true);

    try {
        const payload = await api.context(id, { signal: contextController.signal });
        state.context = payload.data;
        graph.replaceContext(payload.data);
        renderNodeDetail(payload.data.symbol);
        renderEvidence(payload.data.evidence);

        if (state.mode === 'workflow') await loadWorkflow();
        if (state.mode === 'impact') await loadImpact();
    } catch (error) {
        if (error.name !== 'AbortError') showError(error);
    } finally {
        if (!contextController.signal.aborted) setLoading(false);
    }
}

async function loadWorkflow() {
    if (!state.selectedId) return;
    setLoading(true);
    clearError();

    try {
        const payload = await api.workflow(state.selectedId);
        state.workflow = payload.data;
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

async function setMode(mode) {
    state.mode = mode;
    root.dataset.mode = mode;
    elements.modes.forEach((button) => button.classList.toggle('is-active', button.dataset.mode === mode));

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

    if (state.query.length < 2) {
        searchController?.abort();
        state.searchResults = [];
        renderSearchResults();
        return;
    }

    searchTimer = setTimeout(() => runSearch(state.query), 220);
});

elements.search.addEventListener('keydown', (event) => {
    if (!state.searchResults.length) return;
    if (event.key === 'ArrowDown') activeResult = Math.min(activeResult + 1, state.searchResults.length - 1);
    else if (event.key === 'ArrowUp') activeResult = Math.max(activeResult - 1, 0);
    else if (event.key === 'Enter' && activeResult >= 0) selectSymbol(state.searchResults[activeResult].id);
    else if (event.key === 'Escape') elements.results.hidden = true;
    else return;
    event.preventDefault();
    renderSearchResults();
});

elements.modes.forEach((button) => button.addEventListener('click', () => setMode(button.dataset.mode)));

async function init() {
    try {
        renderStatus(await api.status());
    } catch (error) {
        showError(error);
        elements.status.textContent = 'Status unavailable';
    }
}

window.LogicMap = Object.freeze({ state, api, graph, selectSymbol, setMode, fit: graph.fit });
init();
