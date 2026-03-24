import { S } from '../state.js';

let _healthData = null;
let _violationsData = null;

export function initHealthData(healthData, violationsData) {
    _healthData = healthData;
    _violationsData = violationsData;
}

export function openHealthPanel() {
    const panel = document.getElementById('health-panel');
    if (!panel) return;
    if (panel.classList.contains('open')) {
        panel.classList.remove('open');
    } else {
        renderHealthPanel();
        panel.classList.add('open');
    }
}

export function closeHealthPanel() {
    document.getElementById('health-panel')?.classList.remove('open');
}

export function hpToggle(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('hp-collapsed');
}

function renderHealthPanel() {
    const d = _healthData;
    const v = _violationsData;
    if (!d) return;

    const SEVER_COLORS = Object.assign({
        critical: { bg: 'rgba(239,68,68,.12)', bd: '#ef4444', tx: '#ef4444' },
        high: { bg: 'rgba(249,115,22,.12)', bd: '#f97316', tx: '#f97316' },
        medium: { bg: 'rgba(234,179,8,.12)', bd: '#eab308', tx: '#ca8a04' },
        low: { bg: 'rgba(34,197,94,.1)', bd: '#22c55e', tx: '#16a34a' },
    }, d.colors?.severities || {});

    const VIOLATION_LABELS = Object.assign({
        circular_dependency: 'Circular Dependency',
        fat_controller: 'Fat Controller',
        orphan: 'Orphan Node',
        dead_code: 'Dead Code',
        high_instability: 'High Instability',
        high_coupling: 'High Coupling',
    }, d.labels || {});

    const VIOLATION_DESCRIPTIONS = Object.assign({
        circular_dependency: 'Recursive dependency chain found (A → B → A). Fix by extracting shared logic to a lower-level service or interface.',
        fat_controller: 'Controller exceeds dependency threshold. Refactor by delegating business logic to Services or Actions.',
        orphan: 'Module is not called by or connected to any other parts. May be dead code or incomplete integration.',
        dead_code: 'Node is unreachable from configured route entrypoints (depth = null). Candidate for cleanup or wiring.',
        high_instability: 'Fragile component that depends on many changing parts but is not depended upon by others.',
        high_coupling: 'Tightly coupled module with high connectivity. Hard to test and isolate.',
    }, d.descriptions || {});

    // Score ring
    const pct = Math.max(0, Math.min(100, d.score));
    const r = 36, circ = 2 * Math.PI * r;
    const dash = (pct / 100) * circ;
    const gradeColors = Object.assign(
        { S: '#16a34a', A: '#22c55e', B: '#84cc16', C: '#eab308', D: '#f97316', F: '#ef4444' },
        d.colors?.grades || {}
    );
    const gc = gradeColors[d.grade] || '#6b7280';

    let html = `
<div class="hp-top">
    <div class="hp-score-wrap">
        <svg width="96" height="96" viewBox="0 0 96 96">
            <circle cx="48" cy="48" r="${r}" fill="none" stroke="var(--bdr)" stroke-width="7"/>
            <circle cx="48" cy="48" r="${r}" fill="none" stroke="${gc}" stroke-width="7"
                stroke-dasharray="${dash.toFixed(1)} ${(circ - dash).toFixed(1)}"
                stroke-dashoffset="${(circ * 0.25).toFixed(1)}"
                stroke-linecap="round"/>
        </svg>
        <div class="hp-score-inner">
            <div class="hp-score-val" style="color:${gc}">${d.score}</div>
            <div class="hp-score-lbl">/ 100</div>
        </div>
    </div>
    <div class="hp-meta">
        <div class="hp-grade" style="color:${gc}">Grade ${d.grade}</div>
        <div class="hp-summary">${typeof d.summary === 'string' ? d.summary
            : typeof d.summary === 'object' && d.summary !== null
                ? Object.entries(d.summary).map(([k, val]) => `${k.replace(/_/g, ' ')}: ${val}`).join(' · ')
                : ''
        }</div>
    </div>
</div>`;

    // Graph stats
    if (d.graph_stats) {
        const gs = d.graph_stats;
        html += `<div class="hp-section">
        <div class="hp-section-title">Graph Stats</div>
        <div class="hp-stats-grid">
            <div class="hp-stat"><div class="hp-sv">${gs.total_nodes}</div><div class="hp-sl">Nodes</div></div>
            <div class="hp-stat"><div class="hp-sv">${gs.total_edges}</div><div class="hp-sl">Edges</div></div>
            <div class="hp-stat"><div class="hp-sv">${gs.avg_fan_out}</div><div class="hp-sl">Avg Fan-out</div></div>
            <div class="hp-stat"><div class="hp-sv">${gs.max_depth}</div><div class="hp-sl">Max Depth</div></div>
        </div>
    </div>`;
    }

    if (d.coverage_correlation && d.coverage_correlation.enabled) {
        const c = d.coverage_correlation;
        const avgKnown = Number.isFinite(Number(c.avg_known_coverage_percent))
            ? `${Number(c.avg_known_coverage_percent).toFixed(1)}%`
            : 'n/a';
        const offenders = Array.isArray(c.top_offenders) ? c.top_offenders : [];
        const lowRate = Number.isFinite(Number(c.high_risk_low_coverage_rate))
            ? `${Number(c.high_risk_low_coverage_rate).toFixed(1)}%`
            : '0%';

        html += `<div class="hp-section">
        <div class="hp-section-title">Coverage Correlation</div>
        <div class="hp-stats-grid">
            <div class="hp-stat"><div class="hp-sv">${c.eligible_nodes ?? 0}</div><div class="hp-sl">Eligible</div></div>
            <div class="hp-stat"><div class="hp-sv">${avgKnown}</div><div class="hp-sl">Avg Known</div></div>
            <div class="hp-stat"><div class="hp-sv">${c.high_risk_nodes ?? 0}</div><div class="hp-sl">High Risk</div></div>
            <div class="hp-stat"><div class="hp-sv">${c.high_risk_low_coverage ?? 0}</div><div class="hp-sl">High Risk + Low Cov</div></div>
        </div>
        <div class="hp-cov-meta">
            <span>Low coverage threshold: ${(Number(c.low_threshold ?? 0.5) * 100).toFixed(0)}%</span>
            <span>High-risk low-coverage rate: ${lowRate}</span>
            <span>Unknown coverage nodes: ${c.unknown_coverage_nodes ?? 0}</span>
        </div>`;

        if (offenders.length) {
            html += `<div class="hp-cov-list">`;
            offenders.forEach(item => {
                const lvl = String(item.coverage_level || 'unknown').toLowerCase();
                html += `<div class="hp-cov-item">
                <div class="hp-cov-main">
                    <span class="hp-cov-name">${item.name || item.node_id}</span>
                    <span class="hp-cov-pill lv-${lvl}">${item.coverage_percent}%</span>
                </div>
                <div class="hp-cov-sub">${item.kind || 'unknown'} · ${item.risk || 'n/a'} · ${item.node_id || ''}</div>
            </div>`;
            });
            html += `</div>`;
        }

        html += `</div>`;
    }

    // ── Scoring Guide ──
    html += `<div class="hp-section hp-collapsible hp-collapsed" id="hps-guide">
    <div class="hp-section-title hp-toggle" onclick="LogicMap.hpToggle('hps-guide')">
        <span>Scoring Guide</span>
        <svg class="hp-chev" viewBox="0 0 16 16"><polyline points="4 6 8 10 12 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
    </div>
    <div class="hp-collapsible-body">
        <div class="hp-guide">
            <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.critical.bd}">Critical</span><span>−${d.weights?.critical || 25} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.critical || 'Circular deps, breaking issues'}</span></div>
            <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.high.bd}">High</span><span>−${d.weights?.high || 10} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.high || 'Fat controllers, structural debt'}</span></div>
            <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.medium.bd}">Medium</span><span>−${d.weights?.medium || 5} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.medium || 'High instability / coupling'}</span></div>
            <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.low.bd}">Low</span><span>−${d.weights?.low || 1} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.low || 'Orphan / dead-code signals, minor issues'}</span></div>
        </div>
        <div class="hp-grades">
            ${Object.entries(d.grade_scales || {90:'A',80:'B',70:'C',60:'D',0:'F'})
                .sort((a,b) => b[0]-a[0])
                .map(([score, grade], i, arr) => {
                    const next = arr[i-1] ? (parseInt(arr[i-1][0])-1) : 100;
                    return `<span class="hp-grade-pill g${grade}">${grade} ${score}${score < 100 ? `–${next}` : ''}</span>`;
                }).join('')}
        </div>
    </div>
</div>`;

    // ── Violations ──
    if (v && v.violations && v.violations.length) {
        const groups = { critical: [], high: [], medium: [], low: [] };
        v.violations.forEach(viol => { (groups[viol.severity] || groups.low).push(viol); });
        const total = v.violations.length;

        html += `<div class="hp-section" id="hps-violations">
        <div class="hp-section-title">
            Violations
            <span class="hp-vcount">${total}</span>
        </div>`;

        ['critical', 'high', 'medium', 'low'].forEach(sev => {
            if (!groups[sev].length) return;
            const sc = SEVER_COLORS[sev];
            const gid = `hpg-${sev}`;
            const defaultCollapsed = (sev !== 'critical') ? 'hp-collapsed' : '';
            html += `<div class="hp-sev-group hp-collapsible ${defaultCollapsed}" id="${gid}">
            <div class="hp-sev-hdr hp-toggle" style="color:${sc.tx}" onclick="LogicMap.hpToggle('${gid}')">
                <span class="hp-sev-dot" style="background:${sc.bd}"></span>
                <span>${sev.charAt(0).toUpperCase() + sev.slice(1)}</span>
                <span class="hp-sev-cnt">${groups[sev].length}</span>
                <svg class="hp-chev" viewBox="0 0 16 16"><polyline points="4 6 8 10 12 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </div>
            <div class="hp-collapsible-body">`;
            groups[sev].forEach(viol => {
                const label = VIOLATION_LABELS[viol.type] || viol.type;
                const desc = VIOLATION_DESCRIPTIONS[viol.type] || '';
                const nodeId = viol.node_id || viol.nodeId || '';
                const rawMsg = viol.message || viol.description || '';

                let formattedMsg = '';
                if (rawMsg) {
                    const shortened = rawMsg.replace(
                        /[A-Za-z0-9_\\]+\\([A-Za-z0-9_]+)(@[A-Za-z0-9_]+)?/g,
                        (match, cls, method) => method ? `${cls}${method}` : cls
                    );
                    if (shortened.startsWith('Part of circular dependency:')) {
                        const chain = shortened.replace('Part of circular dependency:', '').trim();
                        const steps = chain.split(/\\s*(?:→|->)\\s*/).filter(Boolean);
                        if (steps.length > 1) {
                            formattedMsg = `<div class="hp-viol-chain">${steps.map((s, i) =>
                                `<span class="hp-chain-step">${s.trim()}</span>${i < steps.length - 1 ? '<span class="hp-chain-arr">→</span>' : ''}`
                            ).join('')
                                }</div>`;
                        } else {
                            formattedMsg = `<div class="hp-viol-msg">${shortened}</div>`;
                        }
                    } else {
                        formattedMsg = `<div class="hp-viol-msg">${shortened}</div>`;
                    }
                }

                const shortNode = nodeId ? nodeId.replace(/^(method|class|route):/, '').split('\\\\').pop().split('@').pop() : '';

                html += `<div class="hp-viol" style="border-left-color:${sc.bd}${nodeId ? ';cursor:pointer' : ''}" ${nodeId ? `onclick="LogicMap.focusNode('${nodeId}');LogicMap.closeHealthPanel()"` : ''}>
                <div class="hp-viol-type">${label}</div>
                ${desc ? `<div class="hp-viol-desc">${desc}</div>` : ''}
                ${formattedMsg}
                ${shortNode ? `<div class="hp-viol-node"><svg viewBox="0 0 16 16" width="10" height="10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="8" cy="8" r="5.5"/><polyline points="8 5 8 8 10 10"/></svg>${shortNode}</div>` : ''}
            </div>`;
            });
            html += `</div></div>`;
        });
        html += `</div>`;
    } else if (v) {
        html += `<div class="hp-section">
        <div class="hp-section-title">Violations</div>
        <div class="hp-empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="20" height="20"><polyline points="20 6 9 17 4 12"/></svg>
            No violations found
        </div>
    </div>`;
    }

    document.getElementById('hp-body').innerHTML = html;
}
