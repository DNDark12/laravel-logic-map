<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Impact Analysis — {{ $data['targetName'] }} | Logic Map</title>
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
                </div>
                <h1>{{ $data['targetName'] }}</h1>
                <div class="rp-subtitle">{{ $data['targetNodeId'] }}</div>
                <div class="rp-header-meta">
                    <span class="badge badge-snapshot">Snapshot: {{ substr($data['snapshot'], 0, 8) }}</span>
                </div>
            </div>
            <div style="flex-shrink:0; text-align:right;">
                <span class="badge {{ $data['riskBadgeClass'] }}" style="font-size:0.8rem; padding:0.3rem 0.75rem;">
                    {{ $data['riskBucket'] }} Risk
                </span>
                <div class="risk-expl">{{ $data['riskExplanation'] }}</div>
            </div>
        </div>
    </div>

    {{-- ── Stat Row ─────────────────────────────────────────────────── --}}
    <div class="stat-row">
        <div class="stat-card">
            <div class="stat-value">{{ $data['blastRadius'] }}</div>
            <div class="stat-label">Blast Radius</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $data['statAreasAffected'] }}</div>
            <div class="stat-label">Areas Affected</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $data['statIncoming'] }}</div>
            <div class="stat-label">Incoming Deps</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ $data['statDownstream'] }}</div>
            <div class="stat-label">Downstream Steps</div>
        </div>
    </div>

    {{-- ── Executive Summary ────────────────────────────────────────── --}}
    @if(!empty($data['humanSummary']))
    <div class="rp-card">
        <h2 style="margin-bottom:0.75rem;">What You Need to Know</h2>
        <div class="exec-summary">{{ $data['humanSummary'] }}</div>
    </div>
    @endif

    {{-- ── Must Review (open by default) ──────────────────────────── --}}
    <div class="rp-collapse-section">
        <button class="rp-collapse-trigger" data-target="sec-must-review" data-open="true" aria-expanded="true">
            <span class="section-heading" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <span>Must Review</span>
                <span class="pill">{{ count($data['mustReview']) }}</span>
            </span>
            <span class="trigger-chevron">▼</span>
        </button>
        <div class="rp-collapse-body open" id="sec-must-review">
            @if(empty($data['mustReview']))
                <p class="empty-state">No critical review targets identified for this component.</p>
            @else
            <div class="rec-list">
                @foreach($data['mustReview'] as $row)
                <div class="rec-card" style="border-left: 3px solid var(--clr-critical);">
                    <div class="rec-title">
                        {{ $row['name'] }}
                        <span class="badge badge-kind">{{ $row['kind_label'] }}</span>
                        @if(!empty($row['risk']) && strtolower($row['risk']) !== 'none')
                        <span class="badge risk-{{ strtolower($row['risk']) }}">{{ $row['risk_formatted'] }}</span>
                        @endif
                    </div>
                    <div class="rec-reason">{{ $row['why_included_formatted'] }}</div>
                    <div class="rec-id">{{ $row['node_id'] }}</div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- ── Also Check (open by default) ───────────────────────────── --}}
    <div class="rp-collapse-section">
        <button class="rp-collapse-trigger" data-target="sec-also-check" data-open="true" aria-expanded="true">
            <span class="section-heading" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <span>Also Check</span>
                <span class="pill">{{ count($data['shouldReview']) }}</span>
            </span>
            <span class="trigger-chevron">▼</span>
        </button>
        <div class="rp-collapse-body open" id="sec-also-check">
            @if(empty($data['shouldReview']))
                <p class="empty-state">No secondary review items identified.</p>
            @else
            <div class="rec-list">
                @foreach($data['shouldReview'] as $row)
                <div class="rec-card">
                    <div class="rec-title">
                        {{ $row['name'] }}
                        <span class="badge badge-kind">{{ $row['kind_label'] }}</span>
                    </div>
                    <div class="rec-reason">{{ $row['why_included_formatted'] }}</div>
                    <div class="rec-id">{{ $row['node_id'] }}</div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- ── Test Focus (collapsed by default) ─────────────────────── --}}
    @if(!empty($data['testFocus']))
    <div class="rp-collapse-section">
        <button class="rp-collapse-trigger" data-target="sec-test-focus" data-open="false">
            <span class="section-heading" style="display:inline-flex;align-items:center;gap:0.5rem;">
                <span>Suggested Test Focus</span>
                <span class="pill">{{ count($data['testFocus']) }}</span>
            </span>
            <span class="trigger-chevron">▼</span>
        </button>
        <div class="rp-collapse-body" id="sec-test-focus">
            <div class="rec-list">
                @foreach($data['testFocus'] as $row)
                <div class="rec-card" style="border-left: 3px solid var(--clr-medium);">
                    <div class="rec-title">
                        {{ $row['name'] }}
                        <span class="badge badge-kind">{{ $row['kind_label'] }}</span>
                    </div>
                    @if(!empty($row['coverage_level']))
                    <div class="rec-reason">Coverage: <strong>{{ $row['coverage_level'] }}</strong> — {{ $row['why_included_formatted'] }}</div>
                    @else
                    <div class="rec-reason">{{ $row['why_included_formatted'] }}</div>
                    @endif
                    <div class="rec-id">{{ $row['node_id'] }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- ── Technical Details (collapsed) ──────────────────────────── --}}
    <details>
        <summary>Technical Details</summary>
        <div class="accordion-body">
            <table class="detail-table">
                <tr><td>Node ID</td><td class="rp-mono">{{ $data['targetNodeId'] }}</td></tr>
                <tr><td>Snapshot</td><td class="rp-mono">{{ $data['snapshot'] }}</td></tr>
                <tr><td>Risk bucket</td><td>{{ $data['riskBucket'] }}</td></tr>
                <tr><td>Blast radius</td><td>{{ $data['blastRadius'] }}</td></tr>
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
        <button class="btn btn-ghost" onclick="rpCopyMarkdown('{{ route('logic-map.report.download.impact', ['id' => urlencode($data['targetNodeId']), 'snapshot' => $data['snapshot']]) }}')">Copy Markdown</button>
        <a href="{{ route('logic-map.report.download.json.impact', ['id' => urlencode($data['targetNodeId']), 'snapshot' => $data['snapshot']]) }}" class="btn btn-outline">Download JSON</a>
        <a href="{{ route('logic-map.report.download.impact', ['id' => urlencode($data['targetNodeId']), 'snapshot' => $data['snapshot']]) }}" class="btn btn-outline">Download MD</a>
        @if(config('logic-map.change_intelligence.markdown.save_to_project_docs', false))
        <button id="rp-save-btn" class="btn btn-primary" onclick="rpSaveToDocs('{{ route('logic-map.report.save.impact', ['id' => urlencode($data['targetNodeId'])]) }}', 'rp-save-btn')">Save to Docs</button>
        @endif
    </div>

</div>
<script>{!! $reportPageJs !!}</script>
</body>
</html>
