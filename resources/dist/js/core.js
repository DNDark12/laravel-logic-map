const EMBEDDED_MANIFEST = __LM_MANIFEST__;
const EMBEDDED_ASSET_VERSION = __LM_ASSET_VERSION__;

const MANIFEST = (EMBEDDED_MANIFEST && typeof EMBEDDED_MANIFEST === 'object') ? EMBEDDED_MANIFEST : {};
const VERSION_FALLBACK = typeof EMBEDDED_ASSET_VERSION === 'string' && EMBEDDED_ASSET_VERSION !== ''
    ? EMBEDDED_ASSET_VERSION
    : 'dev';

const BASE_URL = normalizeBase(
    typeof window.__LM_BASE === 'string' && window.__LM_BASE.trim() !== ''
        ? window.__LM_BASE
        : '/vendor/logic-map'
);

const warnedKeys = new Set();
const chunkPromises = new Map();
const originalGlobals = new Map();

let stateLoadPromise = null;
let modesPrimed = false;

function normalizeBase(input) {
    const trimmed = String(input || '/vendor/logic-map').trim();
    const withoutFragment = trimmed.split('#')[0].split('?')[0];

    return withoutFragment.replace(/\/+$/, '') || '/vendor/logic-map';
}

function warnOnce(key, message, extra = null) {
    if (warnedKeys.has(key)) {
        return;
    }

    warnedKeys.add(key);

    if (extra) {
        console.warn(message, extra);
        return;
    }

    console.warn(message);
}

function versionFor(relativePath) {
    const hash = MANIFEST[relativePath];

    if (typeof hash === 'string' && hash !== '') {
        return hash;
    }

    warnOnce(
        `manifest:${relativePath}`,
        `[LogicMap] Missing manifest key for "${relativePath}". Falling back to asset version.`
    );

    return VERSION_FALLBACK;
}

function buildAssetUrl(relativePath) {
    const cleanPath = String(relativePath || '').replace(/^\/+/, '');

    return `${BASE_URL}/${cleanPath}?v=${encodeURIComponent(versionFor(cleanPath))}`;
}

function loadStateScript() {
    if (stateLoadPromise) {
        return stateLoadPromise;
    }

    stateLoadPromise = new Promise((resolve, reject) => {
        if (window.__LM_STATE_READY === true) {
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = buildAssetUrl('state.js');
        script.async = false;

        script.onload = () => {
            window.__LM_STATE_READY = true;
            resolve();
        };

        script.onerror = (error) => {
            warnOnce('state-load-failed', '[LogicMap] Failed to load state.js.', error);
            reject(error);
        };

        document.head.appendChild(script);
    });

    return stateLoadPromise;
}

function loadChunk(relativePath) {
    const url = buildAssetUrl(relativePath);

    if (!chunkPromises.has(url)) {
        const promise = import(/* @vite-ignore */ url).catch((error) => {
            chunkPromises.delete(url);
            throw error;
        });

        chunkPromises.set(url, promise);
    }

    return chunkPromises.get(url);
}

function captureOriginalGlobal(name) {
    if (originalGlobals.has(name)) {
        return;
    }

    const fn = window[name];

    if (typeof fn === 'function') {
        originalGlobals.set(name, fn);
    }
}

function callOriginalGlobal(name, args) {
    captureOriginalGlobal(name);

    const fn = originalGlobals.get(name);

    if (typeof fn !== 'function') {
        throw new Error(`[LogicMap] Missing global function: ${name}`);
    }

    return fn.apply(window, args);
}

function createDirectMethod(globalName) {
    return async (...args) => {
        await loadStateScript();
        return callOriginalGlobal(globalName, args);
    };
}

function createLazyMethod(methodName, chunkPath, globalName) {
    let resolved = null;

    return async (...args) => {
        await loadStateScript();

        if (!resolved) {
            try {
                await loadChunk(chunkPath);
            } catch (error) {
                warnOnce(`chunk:${chunkPath}`, `[LogicMap] Failed to load chunk "${chunkPath}".`, error);
            }

            resolved = (...innerArgs) => callOriginalGlobal(globalName, innerArgs);
            logicMap[methodName] = resolved;
            window.LogicMap[methodName] = resolved;
        }

        return resolved(...args);
    };
}

function openShortcuts() {
    const modal = document.getElementById('kb-help');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeShortcuts() {
    const modal = document.getElementById('kb-help');
    if (modal) {
        modal.style.display = 'none';
    }
}

function primeModesChunk() {
    if (modesPrimed) {
        return;
    }

    modesPrimed = true;

    loadChunk('chunks/modes.js').catch((error) => {
        warnOnce('chunk:modes.js', '[LogicMap] Failed to pre-load modes chunk.', error);
    });
}

function bindModeChunkPrimer() {
    document.addEventListener(
        'click',
        (event) => {
            const trigger = event.target.closest('[data-layout],[data-mobile-layout]');

            if (trigger) {
                primeModesChunk();
            }
        },
        true
    );

    document.addEventListener('keydown', (event) => {
        if (event.key === '2' || event.key === '3' || event.key === '4') {
            primeModesChunk();
        }
    });
}

function patchLegacyGlobal(name, logicMapMethod) {
    captureOriginalGlobal(name);

    if (!originalGlobals.has(name)) {
        return;
    }

    window[name] = (...args) => window.LogicMap[logicMapMethod](...args);
}

const logicMap = window.LogicMap || {};

logicMap.fitView = createDirectMethod('fitView');
logicMap.clearHighlight = createDirectMethod('clearHighlight');
logicMap.toggleHeatmap = createDirectMethod('toggleHeatmap');
logicMap.toggleDropdown = createDirectMethod('toggleDropdown');
logicMap.toggleThemePicker = createDirectMethod('toggleThemePicker');
logicMap.setTheme = createDirectMethod('setTheme');
logicMap.peekPanel = createDirectMethod('peekPanel');
logicMap.expandPanel = createDirectMethod('expandPanel');
logicMap.restorePanel = createDirectMethod('restorePanel');
logicMap.toggleLegend = createDirectMethod('toggleLegend');
logicMap.hideLegend = createDirectMethod('hideLegend');
logicMap.showLegend = createDirectMethod('showLegend');

logicMap.openHealthPanel = createLazyMethod('openHealthPanel', 'chunks/health-panel.js', 'openHealthPanel');
logicMap.closeHealthPanel = createLazyMethod('closeHealthPanel', 'chunks/health-panel.js', 'closeHealthPanel');
logicMap.toggleModPanel = createLazyMethod('toggleModPanel', 'chunks/module-explorer.js', 'toggleModPanel');
logicMap.exportLogicMap = createLazyMethod('exportLogicMap', 'chunks/export.js', 'exportLogicMap');
logicMap.enterSubGraph = createLazyMethod('enterSubGraph', 'chunks/subgraph.js', 'enterSubGraph');
logicMap.rerunSubGraph = createLazyMethod('rerunSubGraph', 'chunks/subgraph.js', 'rerunSubGraph');
logicMap.exitSubGraph = createLazyMethod('exitSubGraph', 'chunks/subgraph.js', 'exitSubGraph');

logicMap.openShortcuts = openShortcuts;
logicMap.closeShortcuts = closeShortcuts;
logicMap.assetBase = BASE_URL;
logicMap.ready = loadStateScript;

window.LogicMap = logicMap;

loadStateScript()
    .then(() => {
        bindModeChunkPrimer();

        patchLegacyGlobal('openHealthPanel', 'openHealthPanel');
        patchLegacyGlobal('closeHealthPanel', 'closeHealthPanel');
        patchLegacyGlobal('toggleModPanel', 'toggleModPanel');
        patchLegacyGlobal('exportLogicMap', 'exportLogicMap');
        patchLegacyGlobal('enterSubGraph', 'enterSubGraph');
        patchLegacyGlobal('rerunSubGraph', 'rerunSubGraph');
        patchLegacyGlobal('exitSubGraph', 'exitSubGraph');
    })
    .catch((error) => {
        warnOnce('state-boot-failed', '[LogicMap] Failed to bootstrap state runtime.', error);
    });
