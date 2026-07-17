import { existsSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

async function loadPlaywright() {
    try { return await import('playwright'); } catch {
        const cacheRoot = join(process.env.HOME || '', '.npm', '_npx');
        const candidates = readdirSync(cacheRoot, { withFileTypes: true })
            .filter((entry) => entry.isDirectory())
            .map((entry) => join(cacheRoot, entry.name, 'node_modules', 'playwright', 'index.mjs'))
            .filter((path) => { try { return statSync(path).isFile(); } catch { return false; } })
            .sort((left, right) => statSync(right).mtimeMs - statSync(left).mtimeMs);
        if (!candidates.length) throw new Error('Playwright is unavailable. Run with npx -y -p playwright.');
        return import(pathToFileURL(candidates[0]).href);
    }
}

const { chromium } = await loadPlaywright();
const baseUrl = (process.env.LOGIC_MAP_BASE_URL || 'http://127.0.0.1:8787').replace(/\/$/, '');
const chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.PLAYWRIGHT_CHROME_PATH || (existsSync(chrome) ? chrome : undefined),
});

try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 850 } });
    const page = await context.newPage();
    const errors = [];
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
    const envelope = (data, meta = {}) => ({ ok: true, data, message: null, errors: null, meta });
    const symbol = {
        id: 'method:App\\Services\\OrderService::cancel', kind: 'method', name: 'cancel',
        qualified_name: 'App\\Services\\OrderService::cancel',
        location: { file: 'app/Services/OrderService.php', start_line: 20, end_line: 44 }, attributes: {},
    };
    const evidence = [{
        id: 'ev-1', detector: 'call-target-resolver', origin: 'static_ast', certainty: 'certain',
        location: { file: 'app/Services/OrderService.php', start_line: 25, end_line: 25 },
        expression: '$order->canBeCancelled()', condition: null, attributes: {},
    }];
    const workflow = {
        identity: { schema_version: 2, snapshot_id: 'smoke', workflow_id: 'workflow:cancel' },
        entrypoint: { node_id: 'route:POST:orders/{order}/cancel', step_id: 'step:entry' },
        summary: { step_count: 6, module_count: 2, branch_count: 1, async_boundary_count: 1, transaction_count: 1, effect_count: 1, gap_count: 1 },
        steps: [
            { id: 'step:entry', kind: 'entry', label: 'POST cancel', node_id: 'route:POST:orders/{order}/cancel', module: 'Orders', evidence_ids: ['ev-1'], attributes: {} },
            { id: 'step:decision', kind: 'decision', label: 'canBeCancelled?', node_id: null, module: 'Orders', evidence_ids: ['ev-1'], attributes: {} },
            { id: 'step:table', kind: 'effect', label: 'orders.status', node_id: 'table:orders', module: 'Orders', evidence_ids: ['ev-1'], attributes: {} },
            { id: 'step:async', kind: 'async_boundary', label: 'queued', node_id: null, module: 'Integration', evidence_ids: ['ev-1'], attributes: {} },
            { id: 'step:cycle', kind: 'cycle', label: 'cycle detected', node_id: null, module: 'Integration', evidence_ids: ['ev-1'], attributes: {} },
            { id: 'step:gap', kind: 'gap', label: 'dynamic target', node_id: null, module: 'Integration', evidence_ids: [], attributes: {} },
        ],
        transitions: [
            { from: 'step:entry', to: 'step:decision', boundary: 'sync', condition: null, branch: null, is_cycle: false, evidence_ids: ['ev-1'] },
            { from: 'step:decision', to: 'step:table', boundary: 'sync', condition: 'true', branch: 'truthy', is_cycle: false, evidence_ids: ['ev-1'] },
            { from: 'step:decision', to: 'step:async', boundary: 'async', condition: 'false', branch: 'falsy', is_cycle: false, evidence_ids: ['ev-1'] },
            { from: 'step:async', to: 'step:cycle', boundary: 'async', condition: null, branch: null, is_cycle: true, evidence_ids: ['ev-1'] },
            { from: 'step:cycle', to: 'step:gap', boundary: 'sync', condition: null, branch: null, is_cycle: false, evidence_ids: [] },
        ],
        transactions: [{ id: 'txn:1', step_ids: ['step:table'], evidence_ids: ['ev-1'] }],
        modules: [
            { name: 'Orders', step_ids: ['step:entry', 'step:decision', 'step:table'] },
            { name: 'Integration', step_ids: ['step:async', 'step:cycle', 'step:gap'] },
        ],
        gaps: [{ step_id: 'step:gap', reason: 'dynamic target', evidence_ids: [], attributes: {} }],
        truncation: { truncated: true, omitted_count: 2, frontier: ['step:frontier'] }, evidence,
    };
    const reason = (category, chain) => ({
        category, level: category === 'shared_state' ? 'shared_resource' : 'transitive',
        node_chain: chain, edge_chain: chain.slice(1).map((_, index) => `impact-edge-${category}-${index}`),
        evidence_ids: ['ev-1'], sentence: `${chain.at(-1)} affected through ${category}.`,
    });
    const impact = {
        change_set: { count: 1, by_type: { added: 0, modified: 1, deleted: 0, renamed: 0 } },
        summary: { changed_symbol_count: 1, affected_symbol_count: 3, affected_module_count: 1, selected_test_count: 1, uncertainty_count: 1 },
        changed_symbols: [{ change_type: 'modified', old_node_id: null, new_node_id: symbol.id, evidence_id: 'ev-1' }],
        affected_symbols: [
            { node_id: 'method:App\\Services\\ShippingService::ship', reasons: [reason('hard_dependency', [symbol.id, 'method:App\\Services\\ShippingService::ship'])] },
            { node_id: 'method:App\\Reports\\OrderReport::count', reasons: [reason('shared_state', [symbol.id, 'table:orders', 'method:App\\Reports\\OrderReport::count'])] },
            { node_id: 'module:Orders', reasons: [reason('module', [symbol.id, 'module:Orders'])] },
        ],
        workflows: [], modules: [{ node_id: 'module:Orders', reason: reason('module', [symbol.id, 'module:Orders']) }],
        shared_resources: [{
            node_id: 'method:App\\Reports\\OrderReport::count', resource_node_id: 'table:orders',
            reason: reason('shared_state', [symbol.id, 'table:orders', 'method:App\\Reports\\OrderReport::count']),
        }],
        external_contracts: [], tests: [{ test_node_id: 'test:tests/Feature/CancelOrderTest.php::test_cancel', rank: 1, reason: 'direct', evidence_ids: ['ev-1'] }],
        uncertainty: [], truncation: {
            hard_dependency: { truncated: true, frontier: ['method:App\\Unknown::dynamic'] },
            shared_state: { truncated: false, frontier: [] }, module: { truncated: false, frontier: [] },
        }, evidence,
    };

    await page.route('**/logic-map/api/status', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(envelope({ active: true, node_count: 20, edge_count: 30 })) }));
    await page.route('**/logic-map/api/symbols/search*', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(envelope({ query: 'cancel', selection: null, results: [symbol] })) }));
    await page.route('**/logic-map/api/symbols/*/context', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(envelope({ symbol, incoming: {}, outgoing: {}, processes: [], modules: [], effects: [], evidence })) }));
    await page.route('**/logic-map/api/workflows/*', (route) => {
        const url = new URL(route.request().url());
        const format = url.searchParams.get('format');
        const data = format ? { format, content: format === 'mermaid' ? 'flowchart TD\n  a --> b\n' : '# Workflow\n' } : workflow;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify(envelope(data)) });
    });
    await page.route('**/logic-map/api/impact', async (route) => {
        const body = route.request().postDataJSON();
        const data = body?.format ? { format: body.format, content: '# Change impact\n' } : impact;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify(envelope(data)) });
    });

    await page.goto(`${baseUrl}/logic-map`, { waitUntil: 'networkidle' });
    await page.locator('#logic-map-search').fill('cancel');
    await page.locator('#logic-map-search-results button').click();
    await page.waitForFunction(() => window.LogicMap?.state?.context);

    await page.locator('#logic-map-mode-workflow').click();
    await page.waitForFunction(() => window.LogicMap?.state?.workflow?.identity?.workflow_id === 'workflow:cancel');
    const workflowContract = await page.evaluate(() => ({
        decisions: window.LogicMap.graph.instance.nodes('.decision').length,
        asyncEdges: window.LogicMap.graph.instance.edges('.async').length,
        resources: window.LogicMap.graph.instance.nodes('[shape = "barrel"]').length,
        modules: window.LogicMap.graph.instance.nodes('.module-lane').length,
        cycles: window.LogicMap.graph.instance.elements('.cycle').length,
        gaps: window.LogicMap.graph.instance.nodes('.gap').length,
    }));
    Object.entries(workflowContract).forEach(([name, count]) => { if (count < 1) throw new Error(`Workflow ${name} mapping missing`); });
    if (!await page.getByRole('button', { name: 'Download Mermaid' }).isVisible()) throw new Error('Workflow export controls missing');

    await page.locator('#logic-map-mode-impact').click();
    await page.waitForFunction(() => window.LogicMap?.state?.impact?.change_set?.count === 1);
    const impactContract = await page.evaluate(() => ({
        changed: window.LogicMap.graph.instance.nodes('.changed').length,
        affected: window.LogicMap.graph.instance.nodes('.affected').length,
        reasons: window.LogicMap.graph.instance.edges('.reason-path').length,
        frontier: window.LogicMap.graph.instance.nodes('.frontier').length,
        filters: window.LogicMap.state.impactFilters.size,
    }));
    Object.entries(impactContract).forEach(([name, count]) => { if (count < 1) throw new Error(`Impact ${name} mapping missing`); });
    if (!await page.getByText('Affected modules').isVisible()) throw new Error('Affected module list missing');
    if (!await page.getByText('Selected tests').isVisible()) throw new Error('Selected test list missing');

    for (const viewport of [{ width: 820, height: 1000 }, { width: 390, height: 844 }]) {
        await page.setViewportSize(viewport);
        const width = await page.locator('body').evaluate((element) => element.scrollWidth);
        if (width > viewport.width + 1) throw new Error(`Layout overflow at ${viewport.width}px`);
        if (!await page.locator('#logic-map-graph').isVisible()) throw new Error(`Graph hidden at ${viewport.width}px`);
    }

    if (errors.length) throw new Error(`Browser console contains errors: ${errors.join(' | ')}`);
    await context.close();
    console.log('[v2-workflow-impact] PASS');
} finally {
    await browser.close();
}
