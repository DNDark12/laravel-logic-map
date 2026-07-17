<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }} Logic Map</title>
    <link rel="stylesheet" href="{{ asset('vendor/logic-map/v2/css/logic-map.css') }}?v={{ rawurlencode(\DNDark\LogicMap\LogicMapServiceProvider::ASSET_VERSION) }}">
</head>
<body>
<main id="logic-map-app" data-api-base="{{ url('/logic-map/api') }}">
    <header class="lm-header">
        <div>
            <p class="lm-eyebrow">Laravel semantic impact engine</p>
            <h1>Logic Map</h1>
        </div>
        <output id="logic-map-status" class="lm-status" aria-live="polite">Checking index…</output>
    </header>

    <nav class="lm-modes" aria-label="Explorer mode">
        <button id="logic-map-mode-symbol" class="is-active" type="button" data-mode="symbol">Symbol</button>
        <button id="logic-map-mode-workflow" type="button" data-mode="workflow">Workflow</button>
        <button id="logic-map-mode-impact" type="button" data-mode="impact">Impact</button>
    </nav>

    <section class="lm-search-panel" aria-label="Symbol search">
        <label for="logic-map-search">Find a route, method, job, event, model, table, or module</label>
        <input id="logic-map-search" type="search" autocomplete="off" placeholder="Search canonical ID or qualified name" aria-controls="logic-map-search-results">
        <div id="logic-map-search-results" class="lm-search-results" role="listbox" hidden></div>
    </section>

    <div id="logic-map-error" class="lm-error" role="alert" hidden></div>

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

<script type="module" src="{{ asset('vendor/logic-map/v2/js/app.js') }}?v={{ rawurlencode(\DNDark\LogicMap\LogicMapServiceProvider::ASSET_VERSION) }}"></script>
</body>
</html>
