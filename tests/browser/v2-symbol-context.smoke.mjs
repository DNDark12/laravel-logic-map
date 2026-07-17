import { existsSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

async function loadPlaywright() {
    try {
        return await import('playwright');
    } catch {
        const cacheRoot = join(process.env.HOME || '', '.npm', '_npx');
        const candidates = readdirSync(cacheRoot, { withFileTypes: true })
            .filter((entry) => entry.isDirectory())
            .map((entry) => join(cacheRoot, entry.name, 'node_modules', 'playwright', 'index.mjs'))
            .filter((path) => {
                try { return statSync(path).isFile(); } catch { return false; }
            })
            .sort((left, right) => statSync(right).mtimeMs - statSync(left).mtimeMs);

        if (!candidates.length) {
            throw new Error('Playwright is unavailable. Run with: npx -y -p playwright node <script>');
        }

        return import(pathToFileURL(candidates[0]).href);
    }
}

const { chromium } = await loadPlaywright();
const baseUrl = (process.env.LOGIC_MAP_BASE_URL || 'http://127.0.0.1:8787').replace(/\/$/, '');
const systemChrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const executablePath = process.env.PLAYWRIGHT_CHROME_PATH
    || (existsSync(systemChrome) ? systemChrome : undefined);
const browser = await chromium.launch({ headless: true, executablePath });

try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });

    const envelope = (data, meta = {}) => ({ ok: true, data, message: null, errors: null, meta });
    const symbol = {
        id: 'method:App\\Services\\OrderService::cancel',
        encoded_id: 'bWV0aG9kOkFwcFxcU2VydmljZXNcXE9yZGVyU2VydmljZTo6Y2FuY2Vs',
        kind: 'method',
        name: 'cancel',
        qualified_name: 'App\\Services\\OrderService::cancel',
        location: { file: 'app/Services/OrderService.php', start_line: 24, end_line: 41 },
        attributes: { visibility: 'public' },
    };
    const symbolContext = {
        symbol,
        incoming: { dependency: [{
            id: 'edge-1', type: 'calls', evidence_ids: ['evidence-1'],
            source: { id: 'route:POST:orders/{order}/cancel', kind: 'route', name: 'POST orders cancel' },
            target: symbol,
        }] },
        outgoing: { state: [{
            id: 'edge-2', type: 'writes_table', evidence_ids: ['evidence-2'],
            source: symbol,
            target: { id: 'table:orders', kind: 'table', name: 'orders' },
        }] },
        processes: [], modules: [{ id: 'module:Orders' }], effects: [],
        evidence: [{
            id: 'evidence-1', detector: 'call-target-resolver', origin: 'static_ast',
            certainty: 'certain', expression: 'OrderService::cancel()', condition: null,
            location: { file: 'routes/web.php', start_line: 18, end_line: 18 }, attributes: {},
        }],
    };

    await page.route('**/logic-map/api/status', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(envelope({ active: true, node_count: 42, edge_count: 67 })),
    }));
    await page.route('**/logic-map/api/symbols/search*', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(envelope({ query: 'cancel', selection: null, results: [symbol] })),
    }));
    await page.route('**/logic-map/api/symbols/*/context', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(envelope(symbolContext)),
    }));

    await page.goto(`${baseUrl}/logic-map`, { waitUntil: 'networkidle' });
    await page.locator('#logic-map-search').fill('cancel');
    await page.locator('#logic-map-search-results button').waitFor();
    await page.locator('#logic-map-search-results button').click();
    await page.waitForFunction(() => window.LogicMap?.state?.context?.symbol?.id?.includes('OrderService::cancel'));

    if (await page.locator('#logic-map-graph canvas').count() === 0) throw new Error('Cytoscape canvas was not rendered');
    if (await page.locator('#logic-map-detail').getByText('OrderService::cancel').count() === 0) throw new Error('Symbol detail missing');
    if (await page.locator('#logic-map-evidence').getByText('call-target-resolver').count() === 0) throw new Error('Evidence drawer missing detector');

    const graphBox = await page.locator('#logic-map-graph').boundingBox();
    if (!graphBox || graphBox.width < 500 || graphBox.height < 500) throw new Error('Desktop graph viewport is not usable');

    await page.setViewportSize({ width: 390, height: 844 });
    const bodyWidth = await page.locator('body').evaluate((element) => element.scrollWidth);
    if (bodyWidth > 391) throw new Error('Mobile layout overflows horizontally');
    if (!await page.locator('#logic-map-mode-symbol').isVisible()) throw new Error('Mode controls are not visible on mobile');
    if (!await page.locator('#logic-map-detail').isVisible()) throw new Error('Detail panel is not visible on mobile');
    if (consoleErrors.length) throw new Error(`Browser console contains errors: ${consoleErrors.join(' | ')}`);

    await context.close();
    console.log('[v2-symbol-context] PASS');
} finally {
    await browser.close();
}
