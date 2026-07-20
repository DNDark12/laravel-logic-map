<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }} Logic Map</title>
    <link rel="icon" href="{{ asset('vendor/logic-map/images/logo.png') }}?v={{ rawurlencode(\DNDark\LogicMap\LogicMapServiceProvider::ASSET_VERSION) }}">
    <link rel="stylesheet" href="{{ asset('vendor/logic-map/css/logic-map.css') }}?v={{ rawurlencode(\DNDark\LogicMap\LogicMapServiceProvider::ASSET_VERSION) }}">
</head>
<body>
<main id="logic-map-app" data-api-base="{{ url('/logic-map/api') }}">
    <header class="lm-command-bar">
        <div class="lm-brand">
            <img src="{{ asset('vendor/logic-map/images/logo.png') }}?v={{ rawurlencode(\DNDark\LogicMap\LogicMapServiceProvider::ASSET_VERSION) }}" alt="Logic Map">
            <div><strong>Logic Map</strong><span>Laravel intelligence</span></div>
        </div>

        <nav class="lm-modes" aria-label="Explorer mode">
            <button id="logic-map-mode-symbol" class="is-active" type="button" data-mode="symbol" title="Inspect direct relationships and evidence">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="12" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M9 11l6-4M9 13l6 4"/></svg><span>Symbol</span>
            </button>
            <button id="logic-map-mode-workflow" type="button" data-mode="workflow" title="Follow execution paths and effects">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h7a4 4 0 014 4v8M4 18h16"/><path d="M17 15l3 3-3 3"/></svg><span>Workflow</span>
            </button>
            <button id="logic-map-mode-impact" type="button" data-mode="impact" title="Explore the potential blast radius">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3M22 12h-3M12 22v-3M2 12h3"/></svg><span>Impact</span>
            </button>
        </nav>

        <div class="lm-interaction-modes" role="group" aria-label="Node interaction mode">
            <button id="logic-map-interaction-view" class="is-active" type="button" data-interaction-mode="view" aria-pressed="true" title="Lock nodes while inspecting">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s4-6 10-6 10 6 10 6-4 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.5"/></svg><span>View</span>
            </button>
            <button id="logic-map-interaction-arrange" type="button" data-interaction-mode="arrange" aria-pressed="false" title="Drag nodes to arrange the current view">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20M2 12h20M12 2l-3 3M12 2l3 3M22 12l-3-3M22 12l-3 3M12 22l-3-3M12 22l3-3M2 12l3-3M2 12l3 3"/></svg><span>Arrange</span>
            </button>
        </div>

        <output id="logic-map-status" class="lm-status" aria-live="polite">Checking index…</output>
        <p id="logic-map-mode-description" class="lm-visually-hidden"></p>
        <span id="logic-map-interaction-description" class="lm-visually-hidden">Node positions are locked.</span>
    </header>

    <section class="lm-discovery" aria-label="Find symbols and modules">
        <div class="lm-search-panel" role="search">
            <label class="lm-visually-hidden" for="logic-map-search">Find a route, method, job, event, model, table, or module</label>
            <div class="lm-search-control">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/></svg>
                <input id="logic-map-search" type="search" autocomplete="off" placeholder="Search routes, classes, methods, or module:Orders" aria-controls="logic-map-search-results" aria-expanded="false">
                <details class="lm-search-help">
                    <summary aria-label="Search help" title="Search help">?</summary>
                    <div>Use <code>module:Orders</code>, a route, class, method, job, event, model, or table. Press Enter to open the highlighted result.</div>
                </details>
            </div>
            <div id="logic-map-search-results" class="lm-search-results" role="listbox" hidden></div>
        </div>

        <details class="lm-module-browser" aria-labelledby="logic-map-module-heading">
            <summary><strong id="logic-map-module-heading">Modules</strong><span id="logic-map-module-count">Browse indexed domains</span></summary>
            <div id="logic-map-module-shortcuts" class="lm-module-shortcuts" aria-live="polite"></div>
        </details>
    </section>

    <div id="logic-map-error" class="lm-error" role="alert" hidden></div>

    <nav class="lm-navigation" aria-label="Graph navigation">
        <button id="logic-map-back" type="button" disabled>← Back</button>
        <select id="logic-map-viewport" aria-label="Graph viewport action">
            <option value="" selected>View graph…</option>
            <option value="focus">Focus main node</option>
            <option value="readable">Readable overview</option>
            <option value="fit">Fit all</option>
        </select>
        <button id="logic-map-toggle-detail" type="button" aria-expanded="true" aria-controls="logic-map-detail">Collapse details</button>
        <ol id="logic-map-breadcrumb" class="lm-breadcrumb">
            <li>Choose a module or search for a symbol</li>
        </ol>
    </nav>

    <section class="lm-workspace" aria-busy="false">
        <div id="logic-map-graph" class="lm-graph" aria-label="Logic graph"></div>

        <aside id="logic-map-detail" class="lm-detail" aria-live="polite">
            <div class="lm-empty">
                <h2>Select a symbol</h2>
                <p>Search to inspect callers, downstream effects, workflows, modules, and evidence.</p>
            </div>
        </aside>
    </section>

    <aside id="logic-map-evidence" class="lm-evidence" aria-live="polite">
        <header>
            <h2>Evidence</h2>
            <span id="logic-map-evidence-count">0 records</span>
        </header>
        <div id="logic-map-evidence-list" class="lm-evidence-list"></div>
    </aside>
</main>

<script type="module" src="{{ asset('vendor/logic-map/js/app.js') }}?v={{ rawurlencode(\DNDark\LogicMap\LogicMapServiceProvider::ASSET_VERSION) }}"></script>
</body>
</html>
