<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Workflow Trace — {{ $data['targetName'] }} | Logic Map</title>
    <style>
        {!! $logicMapCss !!}
        {!! $reportPageCss !!}
    </style>
</head>
<body>
<div class="rp-wrapper">

    {{-- ── Header ────────────────────────────────────────────────────── --}}
    <div class="rp-card">
        <div class="rp-header">
            <div style="flex:1; min-width:0;">
                <div style="margin-bottom:0.5rem;">
                    <span class="badge badge-kind">{{ $data['targetKindLabel'] }}</span>
                    <span class="badge badge-dir" style="margin-left:0.25rem;">{{ ucfirst($data['summary']['direction'] ?? 'forward') }} trace</span>
                </div>
                <h1>{{ $data['targetName'] }}</h1>
                <div class="rp-subtitle">{{ $data['targetNodeId'] }}</div>
                <div class="rp-header-meta">
                    <span class="badge badge-snapshot">Snapshot: {{ substr($data['snapshot'], 0, 8) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Stat Row ─────────────────────────────────────────────────── --}}
    <div class="stat-row">
        <div class="stat-card">
            <div class="stat-value">{{ $data['statMainSteps'] }}</div>
            <div class="stat-label">Main Steps</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $data['statBranchCount'] }}</div>
            <div class="stat-label">Decision Points</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $data['statAsyncCount'] }}</div>
            <div class="stat-label">Async Handoffs</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $data['statPersistCount'] }}</div>
            <div class="stat-label">Persistence Calls</div>
        </div>
    </div>

    {{-- ── Truncation warning ───────────────────────────────────────── --}}
    @if(!empty($data['summary']['truncated']))
    <div class="warn-box">
        ⚠️ <strong>Trace Truncated.</strong>
        The trace reached the configured boundary ({{ $data['summary']['max_depth'] }} hops) and was cut short. Increase depth for a deeper look.
    </div>
    @endif

    {{-- ── Executive Summary ────────────────────────────────────────── --}}
    @if(!empty($data['humanSummary']))
    <div class="rp-card">
        <h2 style="margin-bottom:0.75rem;">What This Workflow Does</h2>
        <div class="exec-summary">{{ $data['humanSummary'] }}</div>
    </div>
    @endif

    {{-- ── Main Flow Timeline (open by default) ───────────────────── --}}
    <div class="rp-collapse-section">
        <button class="rp-collapse-trigger" data-target="sec-main-flow" data-open="true" aria-expanded="true">
            <span class="section-heading" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <span>Main Flow</span>
                <span class="pill">{{ count($data['segments']) }} steps</span>
            </span>
            <span class="trigger-chevron">▼</span>
        </button>
        <div class="rp-collapse-body open" id="sec-main-flow">
            @if(empty($data['segments']))
                <p class="empty-state">No workflow segments could be traced from this entry point.</p>
            @else
            <div class="timeline">
                @foreach($data['segments'] as $seg)
                <div class="timeline-item">
                    <div class="tl-connector">
                        <div class="tl-dot {{ $seg['step_badge'] }}"></div>
                        @if(!$loop->last)
                        <div class="tl-line"></div>
                        @endif
                    </div>
                    <div class="tl-body">
                        <div style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap; margin-bottom:0.15rem;">
                            <strong>{{ $seg['from_label'] }}</strong>
                            <span style="color: var(--rp-muted);">→</span>
                            <strong>{{ $seg['to_label'] }}</strong>
                            <span class="badge badge-{{ $seg['step_badge'] }}">{{ $seg['step_badge'] }}</span>
                        </div>
                        <div class="tl-arrow rp-mono">{{ $seg['from_node_id'] ?? '' }} → {{ $seg['to_node_id'] ?? '' }}</div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- ── Decision Points (collapsed by default) ─────────────────── --}}
    <div class="rp-collapse-section">
        <button class="rp-collapse-trigger" data-target="sec-decisions" data-open="false">
            <span class="section-heading" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <span>Decision Points</span>
                <span class="pill">{{ count($data['branchPoints']) }}</span>
            </span>
            <span class="trigger-chevron">▼</span>
        </button>
        <div class="rp-collapse-body" id="sec-decisions">
            @if(empty($data['branchPoints']))
                <p class="empty-state">No branching points detected — the workflow follows a linear path.</p>
            @else
            @foreach($data['branchPoints'] as $bp)
            <div class="bp-card" style="border-left: 3px solid var(--rp-accent);">
                <div class="bp-title">
                    {{ $bp['name'] }}
                    <span class="badge badge-kind">{{ $bp['kind_label'] }}</span>
                    <span style="font-size:0.75rem; color:var(--rp-muted); font-weight:normal;">{{ $bp['outgoing_count'] }} branches</span>
                </div>
                @if(!empty($bp['branch_names']))
                <div class="bp-branches">
                    @foreach($bp['branch_names'] as $branchName)
                    <span class="bp-branch-tag">→ {{ $branchName }}</span>
                    @endforeach
                </div>
                @endif
                <div class="rp-mono" style="margin-top:0.35rem;">{{ $bp['node_id'] }}</div>
            </div>
            @endforeach
            @endif
        </div>
    </div>

    {{-- ── Async Handoffs (collapsed by default) ───────────────────── --}}
    <div class="rp-collapse-section">
        <button class="rp-collapse-trigger" data-target="sec-async" data-open="false">
            <span class="section-heading" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <span>Async Handoffs</span>
                <span class="pill">{{ count($data['asyncBoundaries']) }}</span>
            </span>
            <span class="trigger-chevron">▼</span>
        </button>
        <div class="rp-collapse-body" id="sec-async">
            @if(empty($data['asyncBoundaries']))
                <p class="empty-state">No async handoffs detected — everything runs synchronously.</p>
            @else
            @foreach($data['asyncBoundaries'] as $seg)
            <div class="bp-card" style="border-left: 3px solid var(--clr-async);">
                <div class="bp-title">
                    {{ $seg['from_label'] }}
                    <span style="color: var(--clr-async);">⇢</span>
                    {{ $seg['to_label'] }}
                    <span class="badge badge-async">async</span>
                </div>
                <div class="rp-mono">{{ $seg['from_node_id'] ?? '' }} → {{ $seg['to_node_id'] ?? '' }}</div>
            </div>
            @endforeach
            @endif
        </div>
    </div>

    {{-- ── Persistence Touchpoints (collapsed by default) ─────────── --}}
    <div class="rp-collapse-section">
        <button class="rp-collapse-trigger" data-target="sec-persist" data-open="false">
            <span class="section-heading" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <span>Persistence Touchpoints</span>
                <span class="pill">{{ count($data['persistence']) }}</span>
            </span>
            <span class="trigger-chevron">▼</span>
        </button>
        <div class="rp-collapse-body" id="sec-persist">
            @if(empty($data['persistence']))
                <p class="empty-state">No persistence touchpoints were identified here.</p>
            @else
            @foreach($data['persistence'] as $tp)
            <div class="bp-card" style="border-left: 3px solid var(--clr-persist);">
                <div class="bp-title">
                    {{ $tp['name'] }}
                    <span class="badge badge-kind">{{ $tp['kind_label'] }}</span>
                    <span class="badge badge-persist">db</span>
                </div>
                <div class="rp-mono">{{ $tp['node_id'] }}</div>
            </div>
            @endforeach
            @endif
        </div>
    </div>

    {{-- ── Technical Details (collapsed) ──────────────────────────── --}}
    <details>
        <summary>Technical Details</summary>
        <div class="accordion-body">
            <table class="detail-table">
                <tr><td>Node ID</td><td class="rp-mono">{{ $data['targetNodeId'] }}</td></tr>
                <tr><td>Snapshot</td><td class="rp-mono">{{ $data['snapshot'] }}</td></tr>
                <tr><td>Direction</td><td>{{ $data['summary']['direction'] ?? '—' }}</td></tr>
                <tr><td>Max depth</td><td>{{ $data['summary']['max_depth'] ?? '—' }}</td></tr>
                <tr><td>Truncated</td><td>{{ !empty($data['summary']['truncated']) ? 'Yes' : 'No' }}</td></tr>
            </table>
        </div>
    </details>

    {{-- ── Raw JSON (collapsed) ─────────────────────────────────────── --}}
    <details>
        <summary>Raw JSON Payload</summary>
        <div class="accordion-body">
            <pre class="json-block">{{ json_encode($data['rawData'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </details>

    {{-- ── Actions ──────────────────────────────────────────────────── --}}
    <div class="actions-bar">
        <a href="{{ url('/logic-map') }}" class="btn btn-outline">← Back to Map</a>
        <button class="btn btn-ghost" onclick="rpCopyMarkdown('{{ route('logic-map.report.download.trace', ['id' => urlencode($data['targetNodeId']), 'snapshot' => $data['snapshot']]) }}')">Copy Markdown</button>
        <a href="{{ route('logic-map.report.download.json.trace', ['id' => urlencode($data['targetNodeId']), 'snapshot' => $data['snapshot']]) }}" class="btn btn-outline">Download JSON</a>
        <a href="{{ route('logic-map.report.download.trace', ['id' => urlencode($data['targetNodeId']), 'snapshot' => $data['snapshot']]) }}" class="btn btn-outline">Download MD</a>
        @if(config('logic-map.change_intelligence.markdown.save_to_project_docs', false))
        <button id="rp-save-btn" class="btn btn-primary" onclick="rpSaveToDocs('{{ route('logic-map.report.save.trace', ['id' => urlencode($data['targetNodeId'])]) }}', 'rp-save-btn')">Save to Docs</button>
        @endif
    </div>

</div>
<script>{!! $reportPageJs !!}</script>
</body>
</html>
