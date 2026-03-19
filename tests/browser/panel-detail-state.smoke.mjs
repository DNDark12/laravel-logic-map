#!/usr/bin/env node

import assert from 'node:assert/strict';

const BASE_URL = process.env.LOGIC_MAP_BASE_URL || 'http://127.0.0.1:8000/logic-map';
const ROUTE_ID = process.env.LOGIC_MAP_ROUTE_ID || 'route:logic-map/overview';

const VIEWPORTS = [
    { name: 'desktop', width: 1366, height: 900, expectedState: 'expanded' },
    { name: 'tablet', width: 768, height: 900, expectedState: 'peek' },
    { name: 'mobile', width: 430, height: 932, expectedState: 'peek' },
];

async function loadPlaywright() {
    try {
        return await import('playwright');
    } catch (error) {
        console.error('[panel-detail-state] Missing "playwright" package.');
        console.error('[panel-detail-state] Run with: npx -y -p playwright node tests/browser/panel-detail-state.smoke.mjs');
        throw error;
    }
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function waitForViewer(page) {
    await page.goto(BASE_URL, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => typeof window.enterSubGraph === 'function' && typeof window.clearHighlight === 'function');
    await delay(400);
}

async function runPanelFlow(page, viewport) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await waitForViewer(page);

    const state = await page.evaluate(async ({ routeId }) => {
        const readPanel = () => {
            const panel = document.getElementById('panel');
            const restore = document.getElementById('panel-restore');
            const rect = panel.getBoundingClientRect();

            return {
                panelState: panel?.dataset.state || null,
                panelHidden: panel?.getAttribute('aria-hidden') === 'true',
                restoreHidden: restore?.hidden ?? true,
                restoreLabel: document.getElementById('panel-restore-label')?.textContent || '',
                sgMode: document.body.classList.contains('subgraph-mode'),
                panelRect: {
                    width: Math.round(rect.width),
                    height: Math.round(rect.height),
                    top: Math.round(rect.top),
                    left: Math.round(rect.left),
                },
                viewport: {
                    width: window.innerWidth,
                    height: window.innerHeight,
                },
            };
        };

        window.clearHighlight();
        await new Promise(resolve => setTimeout(resolve, 120));

        window.enterSubGraph(routeId);
        await new Promise(resolve => setTimeout(resolve, 1600));
        const before = readPanel();

        window.hidePanel();
        await new Promise(resolve => setTimeout(resolve, 120));
        const hidden = readPanel();

        window.restorePanel();
        await new Promise(resolve => setTimeout(resolve, 120));
        const restored = readPanel();

        window.clearHighlight();
        await new Promise(resolve => setTimeout(resolve, 120));
        const cleared = readPanel();

        return { before, hidden, restored, cleared };
    }, { routeId: ROUTE_ID });

    assert.equal(state.before.sgMode, true, `${viewport.name}: subgraph mode should be active`);
    assert.equal(state.before.panelState, viewport.expectedState, `${viewport.name}: wrong default panel state`);
    assert.equal(state.hidden.panelState, 'hidden', `${viewport.name}: hide should move panel to hidden`);
    assert.equal(state.hidden.restoreHidden, false, `${viewport.name}: hide should expose restore affordance`);
    assert.equal(state.hidden.sgMode, true, `${viewport.name}: hide must not exit subgraph mode`);
    assert.equal(state.restored.panelState, viewport.expectedState, `${viewport.name}: restore should reopen prior panel state`);
    assert.equal(state.restored.restoreHidden, true, `${viewport.name}: restore button should hide after reopen`);
    assert.equal(state.cleared.panelState, 'hidden', `${viewport.name}: clear should hide panel`);
    assert.equal(state.cleared.restoreHidden, true, `${viewport.name}: clear should drop restore affordance`);

    if (viewport.expectedState === 'peek') {
        assert.ok(
            state.before.panelRect.height < (state.before.viewport.height * 0.75),
            `${viewport.name}: peek panel should not occupy most of the viewport height`
        );
    }

    return {
        viewport: viewport.name,
        expectedState: viewport.expectedState,
        panelRect: state.before.panelRect,
    };
}

async function main() {
    const { chromium } = await loadPlaywright();
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        const results = [];

        for (const viewport of VIEWPORTS) {
            results.push(await runPanelFlow(page, viewport));
        }

        console.log('[panel-detail-state] PASS');
        console.log(JSON.stringify(results, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch(error => {
    console.error('[panel-detail-state] FAIL');
    console.error(error?.stack || error);
    process.exit(1);
});
