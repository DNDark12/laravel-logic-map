(function () {
    const init = function () {
        if (window.logicMapInitialized) return;
        window.logicMapInitialized = true;

        if (typeof cytoscape.dagre === 'undefined' && typeof cytoscapeDagre !== 'undefined') {
            cytoscape.use(cytoscapeDagre);
        }

        /* ────────────────────────────────────
           Constants
        ──────────────────────────────────── */
        const KIND_COLORS = {
            route: { bg: '#dcfce7', bd: '#22c55e' },
            controller: { bg: '#dbeafe', bd: '#3b82f6' },
            service: { bg: '#fef3c7', bd: '#f59e0b' },
            repository: { bg: '#f3e8ff', bd: '#a855f7' },
            model: { bg: '#fce7f3', bd: '#ec4899' },
            event: { bg: '#cffafe', bd: '#06b6d4' },
            job: { bg: '#cffafe', bd: '#06b6d4' },
            listener: { bg: '#cffafe', bd: '#06b6d4' },
            command: { bg: '#e0e7ff', bd: '#6366f1' },
            component: { bg: '#fef3c7', bd: '#eab308' },
            unknown: { bg: '#f3f4f6', bd: '#6b7280' }
        };

        let KIND_LABELS = {
            route: 'Routes', controller: 'Controllers', service: 'Services',
            repository: 'Repositories', model: 'Models', event: 'Events',
            job: 'Jobs', listener: 'Listeners', command: 'Commands',
            component: 'Components', unknown: 'Other'
        };

        // Kind display order for orphan groups
        const KIND_ORDER = ['route', 'controller', 'service', 'repository', 'model', 'event', 'job', 'listener', 'command', 'component', 'unknown'];

        let currentLayout = 'dagre';
        let allNodesData = [];
        let allEdgesData = [];
        let sgOriginalElements = null;
        let sgLastSeed = null;
        let sgMode = false;
        let sgBusy = false;
        let sgRequestSerial = 0;
        let sgActionCooldownUntil = 0;
        let sgCooldownTimer = null;
        const SG_ACTION_COOLDOWN_MS = 1200;
        const SG_STALE_COOLDOWN_MS = 600;
        let crossModuleEdges = {};
        let selectedSnapshot = null;
        let heatmapEnabled = false;
        let layoutBusy = false;
        let pendingLayoutRequest = null;
        let fitBusy = false;
        // hiddenModules removed — visibility toggle feature removed

        const initialSnapshot = new URLSearchParams(window.location.search).get('snapshot');
        if (initialSnapshot && initialSnapshot.trim() !== '') {
            selectedSnapshot = initialSnapshot.trim();
        }

        /* ────────────────────────────────────
           Cytoscape
        ──────────────────────────────────── */
        const cy = cytoscape({
            container: document.getElementById('cy'),
            pixelRatio: Math.min(window.devicePixelRatio, 2),
            hideEdgesOnViewport: true,
            textureOnViewport: false,
            motionBlur: false,
            style: [
                {
                    selector: 'node', style: {
                        'background-color': '#f3f4f6', 'label': 'data(label)', 'color': '#1c2036',
                        'font-size': '11px', 'font-family': 'system-ui,-apple-system,sans-serif',
                        'font-weight': '600', 'text-valign': 'center', 'text-halign': 'center',
                        'border-width': 1, 'border-color': '#9ca3af', 'padding': '10px',
                        'shape': 'round-rectangle', 'width': '160px', 'height': '55px',
                        'text-max-width': '150px', 'text-wrap': 'wrap', 'line-height': 1.2,
                        'text-overflow-wrap': 'anywhere', 'min-zoomed-font-size': 6
                    }
                },
                { selector: 'node[kind="route"]', style: { 'background-color': '#dcfce7', 'border-color': '#22c55e' } },
                { selector: 'node[kind="controller"]', style: { 'background-color': '#dbeafe', 'border-color': '#3b82f6' } },
                { selector: 'node[kind="service"]', style: { 'background-color': '#fef3c7', 'border-color': '#f59e0b' } },
                { selector: 'node[kind="repository"]', style: { 'background-color': '#f3e8ff', 'border-color': '#a855f7' } },
                { selector: 'node[kind="model"]', style: { 'background-color': '#fce7f3', 'border-color': '#ec4899' } },
                { selector: 'node[kind="event"]', style: { 'background-color': '#cffafe', 'border-color': '#06b6d4' } },
                { selector: 'node[kind="job"]', style: { 'background-color': '#cffafe', 'border-color': '#06b6d4' } },
                { selector: 'node[kind="listener"]', style: { 'background-color': '#cffafe', 'border-color': '#06b6d4' } },
                { selector: 'node[kind="command"]', style: { 'background-color': '#e0e7ff', 'border-color': '#6366f1' } },
                { selector: 'node[risk="critical"]', style: { 'border-color': '#ef4444', 'border-width': 2 } },
                { selector: 'node[risk="high"]', style: { 'border-color': '#f97316', 'border-width': 2 } },
                { selector: 'node[risk="medium"]', style: { 'border-color': '#eab308', 'border-width': 1.5 } },
                { selector: 'node.heatmap-on[heat_level = 0]', style: { 'background-color': '#dbeafe', 'border-color': '#93c5fd', 'color': '#1e293b' } },
                { selector: 'node.heatmap-on[heat_level = 1]', style: { 'background-color': '#fef3c7', 'border-color': '#facc15', 'color': '#3f2a0d' } },
                { selector: 'node.heatmap-on[heat_level = 2]', style: { 'background-color': '#fdba74', 'border-color': '#fb923c', 'color': '#3f1d06' } },
                { selector: 'node.heatmap-on[heat_level = 3]', style: { 'background-color': '#f97316', 'border-color': '#ea580c', 'color': '#fff' } },
                { selector: 'node.heatmap-on[heat_level = 4]', style: { 'background-color': '#ef4444', 'border-color': '#dc2626', 'color': '#fff' } },
                { selector: 'node.highlighted', style: { 'border-width': 3, 'border-color': '#dd3585', 'z-index': 999 } },
                { selector: 'node.neighbor', style: { 'border-width': 2, 'border-color': '#4a7ff5' } },
                { selector: 'node.dimmed', style: { 'opacity': 0.15, 'events': 'no' } },
                { selector: 'node.module-focus', style: { 'border-width': 3, 'border-color': '#4a7ff5', 'z-index': 998 } },
                {
                    selector: 'edge', style: {
                        'width': 1.5,
                        'line-color': 'rgba(156,163,175,0.4)',
                        'target-arrow-color': 'rgba(156,163,175,0.4)',
                        'target-arrow-shape': 'triangle', 'arrow-scale': 0.8,
                        'curve-style': 'bezier'
                    }
                },
                { selector: 'edge[type="route_to_controller"]', style: { 'line-color': '#22c55e', 'target-arrow-color': '#22c55e' } },
                { selector: 'edge[type="call"]', style: { 'line-color': 'rgba(59,130,246,0.6)', 'target-arrow-color': 'rgba(59,130,246,0.6)' } },
                { selector: 'edge[type="use"]', style: { 'line-color': 'rgba(245,158,11,0.5)', 'target-arrow-color': 'rgba(245,158,11,0.5)', 'line-style': 'dashed' } },
                {
                    selector: 'edge.highlighted', style: {
                        'width': 2.5, 'line-color': '#4a7ff5', 'target-arrow-color': '#4a7ff5',
                        'z-index': 999, 'line-style': 'dashed', 'line-dash-pattern': [8, 4], 'line-dash-offset': 0
                    }
                },
                { selector: 'edge.dimmed', style: { 'opacity': 0.08, 'events': 'no' } },
            ],
            layout: { name: 'preset' },
            minZoom: 0.1, maxZoom: 4
        });

        const addedEdgeIds = new Set();
        function getUniqueEdgeId(e) {
            const base = `${e.source}->${e.target}:${e.type}`;
            if (!addedEdgeIds.has(base)) { addedEdgeIds.add(base); return base; }
            let i = 2;
            while (addedEdgeIds.has(`${base}#${i}`)) i++;
            const uid = `${base}#${i}`;
            addedEdgeIds.add(uid);
            return uid;
        }

        function asNumber(value) {
            const n = Number(value);
            return Number.isFinite(n) ? n : 0;
        }

        function getNodeMetrics(nodeLike) {
            if (!nodeLike) return {};
            if (typeof nodeLike.data === 'function') {
                const m = nodeLike.data('metrics');
                return (m && typeof m === 'object') ? m : {};
            }

            const m = nodeLike.metrics;
            return (m && typeof m === 'object') ? m : {};
        }

        function computeHeatRaw(nodeLike) {
            const m = getNodeMetrics(nodeLike);
            const fanIn = asNumber(m.fan_in ?? m.in_degree);
            const fanOut = asNumber(m.fan_out ?? m.out_degree);
            const coupling = asNumber(m.coupling);
            const depth = asNumber(m.depth);
            const instability = asNumber(m.instability);

            return fanIn * 1.1 + fanOut * 1.4 + coupling * 14 + depth * 1.8 + instability * 20;
        }

        function getPercentile(sorted, ratio) {
            if (!sorted.length) return 0;
            const clamped = Math.max(0, Math.min(1, ratio));
            const idx = Math.floor((sorted.length - 1) * clamped);
            return sorted[idx];
        }

        function getHeatmapNodes() {
            return cy.nodes().filter(n => !n.data('_groupNode'));
        }

        function recalculateHeatmapData() {
            const nodes = getHeatmapNodes();
            if (!nodes.length) return;

            const raws = [];
            nodes.forEach(node => {
                const raw = Number(computeHeatRaw(node).toFixed(2));
                node.data('heat_raw', raw);
                raws.push(raw);
            });

            const sorted = raws.slice().sort((a, b) => a - b);
            const min = sorted[0];
            const max = sorted[sorted.length - 1];

            if (min === max) {
                nodes.forEach(node => node.data('heat_level', 0));
                return;
            }

            const p25 = getPercentile(sorted, 0.25);
            const p50 = getPercentile(sorted, 0.5);
            const p75 = getPercentile(sorted, 0.75);
            const p90 = getPercentile(sorted, 0.9);

            nodes.forEach(node => {
                const raw = asNumber(node.data('heat_raw'));
                let level = 0;
                if (raw > p90) level = 4;
                else if (raw > p75) level = 3;
                else if (raw > p50) level = 2;
                else if (raw > p25) level = 1;
                node.data('heat_level', level);
            });
        }

        function syncHeatmapUI() {
            const btn = document.getElementById('heatmap-toggle');
            if (btn) {
                btn.classList.toggle('active', heatmapEnabled);
                const label = btn.querySelector('.btn-lbl');
                if (label) label.textContent = heatmapEnabled ? 'Heat: On' : 'Heat: Off';
            }

            const mobileLabel = document.getElementById('mobile-heat-label');
            if (mobileLabel) {
                mobileLabel.textContent = heatmapEnabled ? 'Heat: On' : 'Heat: Off';
            }

            const hint = document.getElementById('legend-heatmap-hint');
            if (hint) {
                hint.style.display = heatmapEnabled ? 'block' : 'none';
            }
        }

        function applyHeatmapMode() {
            recalculateHeatmapData();

            const nodes = getHeatmapNodes();
            nodes.removeClass('heatmap-on');
            if (heatmapEnabled) {
                nodes.addClass('heatmap-on');
            }

            syncHeatmapUI();
        }

        window.toggleHeatmap = function () {
            heatmapEnabled = !heatmapEnabled;
            applyHeatmapMode();
        };

        function withSnapshot(baseUrl, params = {}) {
            const url = new URL(baseUrl, window.location.origin);
            if (selectedSnapshot) {
                url.searchParams.set('snapshot', selectedSnapshot);
            }

            Object.entries(params).forEach(([key, value]) => {
                if (value === null || value === undefined || value === '') return;
                url.searchParams.set(key, String(value));
            });

            return url.toString();
        }

        window.exportLogicMap = function (format) {
            const baseUrl = format === 'csv'
                ? window.logicMapConfig.exportCsvUrl
                : window.logicMapConfig.exportJsonUrl;

            if (format === 'json') {
                fetch(withSnapshot(baseUrl))
                    .then(r => r.json())
                    .then(j => {
                        if (!j?.ok || !j?.data) {
                            throw new Error(j?.message || 'Export JSON failed');
                        }

                        const payload = JSON.stringify(j.data, null, 2);
                        const blob = new Blob([payload], { type: 'application/json;charset=utf-8' });
                        const fp = (selectedSnapshot || 'current').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 24) || 'current';
                        const fileName = `logic-map-export-${fp}.json`;

                        const link = document.createElement('a');
                        const objectUrl = URL.createObjectURL(blob);
                        link.href = objectUrl;
                        link.download = fileName;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        URL.revokeObjectURL(objectUrl);
                    })
                    .catch(err => {
                        console.error('[LogicMap] JSON export failed:', err);
                    });
                return;
            }

            window.location.href = withSnapshot(baseUrl);
        };

        window.setSnapshotFingerprint = function (fingerprint) {
            const url = new URL(window.location.href);
            const normalized = (fingerprint || '').trim();
            if (normalized) {
                url.searchParams.set('snapshot', normalized);
            } else {
                url.searchParams.delete('snapshot');
            }

            window.location.assign(url.toString());
        };

        function formatSnapshotLabel(item) {
            const fp = item.fingerprint || '';
            const short = fp.length > 18 ? `${fp.slice(0, 8)}…${fp.slice(-6)}` : fp;
            const tags = [];
            if (item.is_latest) tags.push('latest');
            if (item.is_current) tags.push('current');

            return tags.length ? `${short} (${tags.join(', ')})` : short;
        }

        function setSnapshotTriggerLabel(text) {
            const label = document.getElementById('snapshot-trigger-label');
            if (label) {
                label.textContent = text || 'Current (Latest)';
            }
        }

        function setActiveMobileSnapshotButton(value) {
            const normalized = (value || '').trim();
            const container = document.getElementById('mobile-snapshot-list');
            if (!container) return;

            const buttons = container.querySelectorAll('.mam-action[data-snapshot]');
            let activeBtn = null;
            buttons.forEach(btn => {
                const isActive = (btn.dataset.snapshot || '') === normalized;
                btn.classList.toggle('active', isActive);
                if (isActive) activeBtn = btn;
            });

            if (!activeBtn) {
                activeBtn = container.querySelector('.mam-action[data-snapshot=""]');
                if (activeBtn) activeBtn.classList.add('active');
            }
        }

        function setActiveSnapshotButton(menu, value) {
            const normalized = (value || '').trim();
            const buttons = menu.querySelectorAll('.seg-btn[data-snapshot]');
            let activeBtn = null;

            buttons.forEach(btn => {
                const isActive = (btn.dataset.snapshot || '') === normalized;
                btn.classList.toggle('active', isActive);
                if (isActive) activeBtn = btn;
            });

            if (!activeBtn) {
                activeBtn = menu.querySelector('.seg-btn[data-snapshot=""]');
                if (activeBtn) activeBtn.classList.add('active');
            }

            setSnapshotTriggerLabel(activeBtn?.dataset.label || 'Current (Latest)');
        }

        function createSnapshotButton(value, label) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'seg-btn';
            btn.dataset.snapshot = value;
            btn.dataset.label = label;
            btn.textContent = label;
            return btn;
        }

        function createMobileSnapshotButton(value, label) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mam-action';
            btn.dataset.snapshot = value;
            btn.dataset.label = label;
            btn.textContent = label;
            return btn;
        }

        function renderMobileSnapshotOptions(items) {
            const container = document.getElementById('mobile-snapshot-list');
            if (!container) return;

            container.innerHTML = '';
            items.forEach(item => {
                container.appendChild(createMobileSnapshotButton(item.value, item.label));
            });
            setActiveMobileSnapshotButton(selectedSnapshot || '');
        }

        function initSnapshotDropdown() {
            const menu = document.getElementById('snapshot-menu');
            if (!menu || !window.logicMapConfig.snapshotsUrl) return;

            menu.innerHTML = '';
            menu.appendChild(createSnapshotButton('', 'Current (Latest)'));
            const snapshotItems = [{ value: '', label: 'Current (Latest)' }];
            renderMobileSnapshotOptions(snapshotItems);
            setActiveSnapshotButton(menu, selectedSnapshot || '');
            setActiveMobileSnapshotButton(selectedSnapshot || '');

            menu.addEventListener('click', function (e) {
                const btn = e.target.closest('.seg-btn[data-snapshot]');
                if (!btn) return;

                const value = (btn.dataset.snapshot || '').trim();
                const current = (selectedSnapshot || '').trim();
                if (value === current) {
                    document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
                    return;
                }

                setActiveSnapshotButton(menu, value);
                setActiveMobileSnapshotButton(value);
                window.setSnapshotFingerprint(value);
            });

            const mobileList = document.getElementById('mobile-snapshot-list');
            if (mobileList && mobileList.dataset.bound !== '1') {
                mobileList.dataset.bound = '1';
                mobileList.addEventListener('click', function (e) {
                    const btn = e.target.closest('.mam-action[data-snapshot]');
                    if (!btn) return;

                    const value = (btn.dataset.snapshot || '').trim();
                    const current = (selectedSnapshot || '').trim();
                    if (value === current) {
                        document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
                        return;
                    }

                    setActiveSnapshotButton(menu, value);
                    setActiveMobileSnapshotButton(value);
                    window.setSnapshotFingerprint(value);
                });
            }

            fetch(window.logicMapConfig.snapshotsUrl)
                .then(r => r.json())
                .then(j => {
                    if (!j.ok || !j.data || !Array.isArray(j.data.snapshots)) {
                        return;
                    }

                    const known = new Set(['']);
                    j.data.snapshots.forEach(item => {
                        if (!item || typeof item.fingerprint !== 'string') return;
                        const fp = item.fingerprint.trim();
                        if (!fp || known.has(fp)) return;

                        // Avoid duplicate UI entry for the same default "Current (Latest)" target.
                        if (item.is_latest && item.is_current) return;

                        known.add(fp);
                        const label = formatSnapshotLabel(item);
                        snapshotItems.push({ value: fp, label });
                        menu.appendChild(createSnapshotButton(fp, label));
                    });

                    if (selectedSnapshot && known.has(selectedSnapshot)) {
                        setActiveSnapshotButton(menu, selectedSnapshot);
                        setActiveMobileSnapshotButton(selectedSnapshot);
                    } else {
                        selectedSnapshot = null;
                        setActiveSnapshotButton(menu, '');
                        setActiveMobileSnapshotButton('');
                    }
                    renderMobileSnapshotOptions(snapshotItems);
                })
                .catch(() => { });
        }

        /* ────────────────────────────────────
           FIX: Hops selector — scoped, mousedown, direct call
        ──────────────────────────────────── */
        function setActiveMobileHopsButton(hops) {
            document.querySelectorAll('#mobile-actions-menu .mam-chip[data-mobile-hops]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mobileHops === String(hops));
            });
        }

        function applyHopsSelection(hops, refreshHighlight = true) {
            const normalized = String(hops || '1');
            const grp = document.getElementById('hl-hops-grp');
            if (grp) {
                grp.querySelectorAll('.seg-btn').forEach(b => {
                    b.classList.toggle('active', b.getAttribute('data-hops') === normalized);
                });
            }

            // Keep cloned dropdown hops menu (mobile trigger version) in sync.
            document.querySelectorAll('#hops-menu .seg-btn').forEach(b => {
                b.classList.toggle('active', b.getAttribute('data-hops') === normalized);
            });

            const inp = document.getElementById('hl-hops');
            if (inp) inp.value = normalized;

            const triggerVal = document.getElementById('hops-trigger-val');
            if (triggerVal) triggerVal.textContent = (normalized === '99' ? 'All' : normalized);

            setActiveMobileHopsButton(normalized);

            if (!refreshHighlight) return;
            if (window._hlTimeout) clearTimeout(window._hlTimeout);
            window._hlTimeout = setTimeout(() => {
                const hl = cy.nodes('.highlighted').first();
                if (hl.length) applyHighlight(hl);
            }, 80);
        }

        function initHopsSelector() {
            const grp = document.getElementById('hl-hops-grp');
            if (!grp) return;
            grp.querySelectorAll('.seg-btn').forEach(btn => {
                const fresh = btn.cloneNode(true);
                btn.parentNode.replaceChild(fresh, btn);
                fresh.addEventListener('mousedown', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    if (this.classList.contains('active')) return;
                    applyHopsSelection(this.getAttribute('data-hops'), true);

                    // Close dropdown if in mobile/grouped mode
                    document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
                });
            });

            const current = document.getElementById('hl-hops')?.value || grp.querySelector('.seg-btn.active')?.getAttribute('data-hops') || '1';
            applyHopsSelection(current, false);
        }
        initHopsSelector();

        function initMobileActionsMenu() {
            const menu = document.getElementById('mobile-actions-menu');
            if (!menu || menu.dataset.bound === '1') return;
            menu.dataset.bound = '1';
            syncMobileLayoutButtons(currentLayout);
            setActiveMobileHopsButton(document.getElementById('hl-hops')?.value || '1');

            menu.addEventListener('click', function (e) {
                const layoutBtn = e.target.closest('.mam-chip[data-mobile-layout]');
                if (layoutBtn) {
                    e.preventDefault();
                    runLayout(layoutBtn.dataset.mobileLayout);
                    return;
                }

                const hopsBtn = e.target.closest('.mam-chip[data-mobile-hops]');
                if (hopsBtn) {
                    e.preventDefault();
                    applyHopsSelection(hopsBtn.dataset.mobileHops, true);
                    return;
                }

                const actionBtn = e.target.closest('.mam-action[data-mobile-action]');
                if (!actionBtn) return;
                e.preventDefault();

                switch (actionBtn.dataset.mobileAction) {
                    case 'fit':
                        window.fitView();
                        break;
                    case 'clear':
                        window.clearHighlight();
                        break;
                    case 'heat':
                        window.toggleHeatmap();
                        break;
                    case 'module':
                        window.toggleModPanel();
                        break;
                    case 'theme':
                        window.toggleThemePicker();
                        break;
                    case 'shortcuts': {
                        const kb = document.getElementById('kb-help');
                        if (kb) kb.style.display = 'flex';
                        break;
                    }
                    case 'export-json':
                        window.exportLogicMap('json');
                        break;
                    case 'export-csv':
                        window.exportLogicMap('csv');
                        break;
                    default:
                        break;
                }

                document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
            });
        }
        initMobileActionsMenu();

        /* Shared highlight logic */
        function applyHighlight(node) {
            stopFlow();
            cy.nodes().removeClass('highlighted neighbor dimmed module-focus');
            cy.edges().removeClass('highlighted dimmed');
            node.addClass('highlighted');

            const hops = parseInt((document.getElementById('hl-hops') || {}).value || '99', 10);
            let succ = cy.collection(), pred = cy.collection();

            if (hops >= 99) {
                succ = node.successors();
                pred = node.predecessors();
            } else {
                let curS = node, curP = node;
                for (let i = 0; i < hops; i++) {
                    curS = curS.outgoers().union(curS);
                    curP = curP.incomers().union(curP);
                }
                succ = curS.not(node);
                pred = curP.not(node);
            }

            succ.nodes().addClass('neighbor');
            succ.edges().addClass('highlighted');
            pred.nodes().addClass('neighbor');
            pred.edges().addClass('highlighted');
            cy.elements().not(node).not(succ).not(pred).addClass('dimmed');
            startFlow();
            cy.animate({ fit: { eles: node.union(succ).union(pred), padding: 80 }, duration: 600, easing: 'ease-out-quad' });
        }

        /* ────────────────────────────────────
           Layout
        ──────────────────────────────────── */
        function getLayoutOpts(name) {
            switch (name) {
                case 'dagre': return { name: 'dagre', rankDir: 'TB', nodeSep: 50, rankSep: 100, edgeSep: 20 };
                case 'cose': return { name: 'cose', idealEdgeLength: 100, nodeRepulsion: 45000, gravity: 0.1, numIter: 1000, initialTemp: 200, coolingFactor: 0.99, nodeDimensionsIncludeLabels: true, componentSpacing: 120, animate: true, animationDuration: 500 };
                case 'lr': return { name: 'dagre', rankDir: 'LR', nodeSep: 50, rankSep: 100, edgeSep: 20 };
                case 'compact': return { name: 'dagre', rankDir: 'LR', nodeSep: 40, rankSep: 60, edgeSep: 10, padding: 30, animate: true, animationDuration: 500 };
                default: return { name: 'dagre', rankDir: 'TB', nodeSep: 50, rankSep: 100 };
            }
        }

        function syncMobileLayoutButtons(layoutName) {
            document.querySelectorAll('#mobile-actions-menu .mam-chip[data-mobile-layout]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mobileLayout === layoutName);
            });
        }

        function setToolbarActionBusy(disabled) {
            document.querySelectorAll('#layout-grp .seg-btn').forEach(btn => {
                btn.disabled = disabled;
            });
            const fitBtn = document.getElementById('fit-btn');
            if (fitBtn) {
                fitBtn.disabled = disabled || fitBusy;
            }
        }

        function runLayout(name, onDone = null) {
            if (!name) return;
            if (fitBusy) return;

            // Ignore repeated click on current layout unless caller needs callback after relayout.
            if (!layoutBusy && name === currentLayout && typeof onDone !== 'function') {
                document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
                return;
            }

            if (layoutBusy) {
                pendingLayoutRequest = { name, onDone };
                return;
            }

            layoutBusy = true;
            setToolbarActionBusy(true);
            currentLayout = name;
            const buttons = document.querySelectorAll('#layout-grp .seg-btn');
            buttons.forEach(b => {
                const isActive = b.dataset.layout === name;
                b.classList.toggle('active', isActive);
                if (isActive) {
                    // Update trigger UI
                    const triggerLabel = document.getElementById('layout-trigger-label');
                    const triggerIcon = document.getElementById('layout-trigger-icon');
                    if (triggerLabel) triggerLabel.textContent = b.querySelector('.btn-lbl')?.textContent || name;
                    if (triggerIcon) {
                        const btnSvg = b.querySelector('svg');
                        if (btnSvg) triggerIcon.innerHTML = btnSvg.innerHTML;
                    }
                }
            });
            syncMobileLayoutButtons(name);

            // Temporarily hide orphan nodes so dagre only arranges the connected graph.
            // They will be repositioned manually after layout finishes.
            cy.nodes().forEach(n => {
                if (n.data('_orphan') && !n.data('_groupNode')) {
                    n.style('display', 'none');
                }
            });

            const layout = cy.layout(getLayoutOpts(name));
            layout.on('layoutstop', function () {
                // Restore orphan visibility, then place them in kind-rows/cols
                cy.nodes().forEach(n => {
                    if (n.data('_orphan') && !n.data('_groupNode')) {
                        n.style('display', 'element');
                    }
                });
                positionOrphanGroups();
                layoutBusy = false;
                setToolbarActionBusy(false);

                if (typeof onDone === 'function') {
                    onDone();
                }

                if (pendingLayoutRequest) {
                    const next = pendingLayoutRequest;
                    pendingLayoutRequest = null;
                    runLayout(next.name, next.onDone);
                }
            });
            layout.run();
            
            // Close dropdown if in mobile mode
            document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
        }

        document.querySelectorAll('#layout-grp .seg-btn').forEach(btn => btn.addEventListener('click', () => runLayout(btn.dataset.layout)));

        /* ────────────────────────────────────
           Theme
        ──────────────────────────────────── */
        window.toggleThemePicker = function () {
            const picker = document.getElementById('theme-picker');
            picker.classList.toggle('open');
        };

        window.setTheme = function (theme, el) {
            document.documentElement.dataset.theme = theme;
            // Update active state in picker
            document.querySelectorAll('#theme-picker .theme-option').forEach(o => o.classList.remove('active'));
            if (el) el.classList.add('active');
            // Save preference
            try { localStorage.setItem('lm-theme', theme); } catch (e) { }
            // Close picker
            document.getElementById('theme-picker').classList.remove('open');
            // Update cytoscape bg
            cy.style().selector('#cy').update();
            cy.container().style.background = getComputedStyle(document.documentElement).getPropertyValue('--bg-canvas').trim();
        };

        // Restore saved theme
        try {
            const saved = localStorage.getItem('lm-theme');
            if (saved) {
                document.documentElement.dataset.theme = saved;
                document.querySelectorAll('#theme-picker .theme-option').forEach(o => {
                    o.classList.toggle('active', o.dataset.themeVal === saved);
                });
            }
        } catch (e) { }

        // Close picker when clicking outside
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#theme-picker') && !e.target.closest('#theme-btn')) {
                document.getElementById('theme-picker').classList.remove('open');
            }
        });

        /* ────────────────────────────────────
           Module Panel
        ──────────────────────────────────── */
        window.toggleModPanel = function () {
            const panel = document.getElementById('mod-panel');
            panel.classList.toggle('open');
            const isOpen = panel.classList.contains('open');
            document.getElementById('cy').style.left = isOpen ? '280px' : '0';
            const leg = document.getElementById('legend');
            if (leg && !leg._dragged) {
                leg.style.left = isOpen ? '296px' : '16px';
                document.getElementById('legend-restore').style.left = isOpen ? '296px' : '16px';
            }
        };

        document.getElementById('mod-toggle')?.addEventListener('click', toggleModPanel);

        document.getElementById('mod-filter')?.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.mod-card').forEach(card => {
                card.style.display = (!q || (card.dataset.ns || '').toLowerCase().includes(q)) ? '' : 'none';
            });
        });

        /* ────────────────────────────────────
           LOD
        ──────────────────────────────────── */
        let lodTimer;
        cy.on('zoom', () => { clearTimeout(lodTimer); lodTimer = setTimeout(applyLOD, 50); });
        function applyLOD() {
            const z = cy.zoom();
            cy.startBatch();
            cy.nodes().style('font-size', z < 0.25 ? 0.01 : z < 0.5 ? 8 : 9);
            cy.edges().style('font-size', z < 0.5 ? 0.01 : 9);
            cy.endBatch();
        }

        /* ────────────────────────────────────
           Helpers
        ──────────────────────────────────── */
        function getNamespace(id) {
            if (!id) return '(root)';
            let c = id.replace(/^(class|method|route):/, '');
            if (c.includes('@')) c = c.substring(0, c.lastIndexOf('@'));
            const p = c.split('\\');
            return p.length > 1 ? p.slice(0, -1).join('\\') : '(root)';
        }

        function isHubUtility(d) {
            const m = d.metrics || {};
            return (m.fan_in || 0) > 5 && (m.fan_out || 0) === 0 && d.kind !== 'route';
        }

        function getRouteInfo(d) {
            const m = d.metadata || {};
            if (m.route_uri || m.routeUri) return { uri: m.route_uri || m.routeUri, verb: m.route_verb || m.routeVerb || 'GET' };
            if (d.kind === 'route') {
                const match = (d.label || d.name || '').match(/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(.+)/i);
                if (match) return { verb: match[1].toUpperCase(), uri: match[2] };
                return { verb: 'GET', uri: d.label || '' };
            }
            return null;
        }

        function getTrigger(id) {
            const inc = cy.edges(`[target="${id}"]`);
            if (!inc.length) return null;
            return inc.map(e => { const s = e.source(); return { id: s.id(), label: s.data('label') || s.id().split('\\').pop(), kind: s.data('kind'), type: e.data('type') }; });
        }

        function getResult(id) {
            const out = cy.edges(`[source="${id}"]`);
            if (!out.length) return null;
            return out.map(e => { const t = e.target(); return { id: t.id(), label: t.data('label') || t.id().split('\\').pop(), kind: t.data('kind'), type: e.data('type') }; });
        }

        function buildTimeline(nodeId, maxDepth = 5) {
            const tl = [], vis = new Set();
            function dfs(id, d) {
                if (d > maxDepth || vis.has(id)) return;
                vis.add(id);
                cy.edges(`[source="${id}"]`).forEach(e => {
                    const t = e.target(), tid = t.id();
                    if (!vis.has(tid)) { tl.push({ id: tid, label: t.data('label') || tid.split('\\').pop(), kind: t.data('kind'), type: e.data('type'), depth: d }); dfs(tid, d + 1); }
                });
            }
            dfs(nodeId, 1);
            return tl;
        }

        function formatShortLabel(id) {
            if (!id) return '';
            let r = '';
            if (id.includes('@')) {
                const ai = id.lastIndexOf('@');
                r = `${id.substring(0, ai).replace(/^(method|class):/, '').split('\\').pop()}\n${id.substring(ai + 1)}`;
            } else if (id.startsWith('class:')) { r = id.replace('class:', '').split('\\').pop(); }
            else if (id.startsWith('route:')) { r = id.replace('route:', ''); }
            else if (id.startsWith('method:')) { r = id.replace('method:', '').split('\\').pop(); }
            else { r = id.split('\\').pop() || id; }
            r = r.replace(/^(method|class|route):/, '');
            if (r.length > 25) r = r.substring(0, 22) + '…';
            return r;
        }

        /* ────────────────────────────────────
           Detail Panel
        ──────────────────────────────────── */
        function openPanel(d) {
            if (!d || d._groupNode) return;
            const kind = d.kind || 'unknown';
            const kc = KIND_COLORS[kind] || KIND_COLORS.unknown;

            document.getElementById('p-badge').style.cssText = `color:${kc.bd};border-color:${kc.bd}`;
            document.getElementById('p-badge').textContent = kind.toUpperCase();
            // Risk badge
            const riskColors = { critical: '#ef4444', high: '#f97316', medium: '#eab308', low: '#22c55e', healthy: '' };
            const riskBadge = document.getElementById('p-risk-badge');
            if (riskBadge) {
                const risk = d.risk;
                if (risk && risk !== 'healthy' && riskColors[risk]) {
                    riskBadge.textContent = risk.toUpperCase();
                    riskBadge.style.cssText = `color:${riskColors[risk]};border-color:${riskColors[risk]};background:${riskColors[risk]}18;display:inline-flex`;
                } else {
                    riskBadge.style.display = 'none';
                }
            }
            document.getElementById('p-name').textContent = d.metadata?.shortLabel || d.name || d.label || d.id;
            document.getElementById('p-id').textContent = d.id;
            document.getElementById('hub-warning').classList.toggle('show', isHubUtility(d));

            const meta = d.metadata || {};
            const triggers = getTrigger(d.id);
            const results = getResult(d.id);
            const routeInfo = getRouteInfo(d);
            let h = '';

            h += `<div class="ps"><div class="sl">Flow</div><div class="flow-box">`;
            h += `<div class="flow-row"><span class="flow-ico">⚡</span><div><div class="flow-lbl">Trigger</div><div class="flow-val">`;
            h += triggers?.length ? triggers.slice(0, 3).map(t => `<strong>${t.label}</strong>`).join(', ') + (triggers.length > 3 ? ` +${triggers.length - 3} more` : '') : (meta.trigger || '<span style="color:#8d97b4">Entry point</span>');
            h += `</div></div></div><div class="flow-arr">↓</div>`;
            h += `<div class="flow-row"><span class="flow-ico">⚙</span><div><div class="flow-lbl">Action</div><div class="flow-val"><strong>${meta.action || d.name || d.label || '–'}</strong>`;
            if (meta.domain) h += ` ${meta.domain}`;
            if (routeInfo) h += `<div class="uri-pill"><span class="uri-verb">${routeInfo.verb}</span> ${routeInfo.uri}</div>`;
            h += `</div></div></div><div class="flow-arr">↓</div>`;
            h += `<div class="flow-row"><span class="flow-ico">✓</span><div><div class="flow-lbl">Result</div><div class="flow-val">`;
            h += results?.length ? 'Triggers: ' + results.slice(0, 3).map(r => `<strong>${r.label}</strong>`).join(', ') + (results.length > 3 ? ` +${results.length - 3} more` : '') : (meta.result || '<span style="color:#8d97b4">Terminal node</span>');
            h += `</div></div></div></div></div>`;

            const tl = buildTimeline(d.id);
            if (tl.length) {
                h += `<div class="ps"><div class="sl">Triggers next →</div><div class="tl">`;
                tl.slice(0, 8).forEach((item, i) => {
                    h += `<div class="tli" onclick="focusNode('${item.id}')"><span class="tli-dot">${i + 1}</span><div class="tli-body"><div class="tli-m">${item.label}</div><div class="tli-sub">${item.kind || '?'} · ${item.type || 'call'}</div></div></div>`;
                });
                if (tl.length > 8) h += `<div class="tli"><span class="tli-dot" style="background:var(--bg-elevated)">+</span><div class="tli-body"><div class="tli-m" style="color:var(--tx3)">${tl.length - 8} more…</div></div></div>`;
                h += `</div></div>`;
            }

            const m = d.metrics || {};
            h += `<div class="ps"><div class="sl">Metrics</div><div class="mrow">`;
            [['Fan In', m.fan_in ?? '–'], ['Fan Out', m.fan_out ?? '–'], ['Instability', m.instability != null ? m.instability.toFixed(2) : '–'], ['Coupling', m.coupling ?? '–'], ['Depth', m.depth ?? '∅'], ['In°', m.in_degree ?? '–'], ['Out°', m.out_degree ?? '–']].forEach(([l, v]) => { h += `<div class="mc"><div class="mv">${v}</div><div class="ml">${l}</div></div>`; });
            h += `</div></div>`;

            const coverage = (meta.coverage && typeof meta.coverage === 'object') ? meta.coverage : null;
            if (coverage) {
                const coveragePct = Number.isFinite(Number(coverage.coverage_percent))
                    ? `${Number(coverage.coverage_percent)}%`
                    : 'Unknown';
                const coverageLevel = String(coverage.coverage_level || 'unknown').toUpperCase();
                const coverageScope = coverage.scope === 'class_fallback'
                    ? 'Class fallback'
                    : String(coverage.scope || 'class');
                const coverageAssumed = coverage.assumed ? ' (assumed)' : '';

                h += `<div class="ps"><div class="sl">Test Coverage</div><div class="mrow">`;
                h += `<div class="mc"><div class="mv">${coveragePct}</div><div class="ml">Coverage</div></div>`;
                h += `<div class="mc"><div class="mv">${coverageLevel}</div><div class="ml">Level</div></div>`;
                h += `<div class="mc"><div class="mv">${coverageScope}</div><div class="ml">Scope${coverageAssumed}</div></div>`;
                h += `</div></div>`;
            }

            const inc = cy.edges(`[target="${d.id}"]`), out = cy.edges(`[source="${d.id}"]`);
            if (inc.length) {
                h += `<div class="ps"><div class="sl">Incoming (${inc.length})</div><div class="conn-list">`;
                inc.forEach(e => { const s = e.source(); h += `<div class="conn-item" onclick="focusNode('${s.id()}')"><span class="conn-arr">←</span><span class="conn-name">${s.data('label') || s.id()}</span><span style="font-size:8px;color:var(--tx3);flex-shrink:0">${e.data('type')}</span></div>`; });
                h += `</div></div>`;
            }
            if (out.length) {
                h += `<div class="ps"><div class="sl">Outgoing (${out.length})</div><div class="conn-list">`;
                out.forEach(e => { const t = e.target(); h += `<div class="conn-item" onclick="focusNode('${t.id()}')"><span class="conn-arr">→</span><span class="conn-name">${t.data('label') || t.id()}</span><span style="font-size:8px;color:var(--tx3);flex-shrink:0">${e.data('type')}</span></div>`; });
                h += `</div></div>`;
            }

            if (!sgMode) {
                // SubGraph action button at bottom of panel
                // Pass node id via data attr — window.enterSubGraph handles cy lookup
                h += `<div class="ps panel-actions">
                <button class="panel-action-btn panel-action-sg" data-node-id="${d.id}" onclick="window.enterSubGraph(this.dataset.nodeId)" title="Explore connected subgraph (S)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13">
                        <circle cx="12" cy="12" r="3"/>
                        <circle cx="3" cy="6" r="2"/><circle cx="21" cy="6" r="2"/>
                        <circle cx="3" cy="18" r="2"/><circle cx="21" cy="18" r="2"/>
                        <line x1="5" y1="6" x2="10" y2="11"/><line x1="19" y1="6" x2="14" y2="11"/>
                        <line x1="5" y1="18" x2="10" y2="13"/><line x1="19" y1="18" x2="14" y2="13"/>
                    </svg>
                    Explore SubGraph
                </button>
            </div>`;
            }

            document.getElementById('pbody').innerHTML = h;
            document.getElementById('panel').classList.add('open');
        }

        window.closePanel = function () {
            document.getElementById('panel').classList.remove('open');
            document.getElementById('hub-warning').classList.remove('show');
            stopFlow();
            cy.nodes().removeClass('highlighted neighbor dimmed module-focus');
            cy.edges().removeClass('highlighted dimmed');
        };

        window.focusNode = function (id) {
            const node = cy.getElementById(id);
            if (!node.length || node.data('_groupNode')) return;
            cy.animate({ center: { eles: node }, zoom: 1.5 }, { duration: 300 });
            // Directly highlight + open panel without emitting tap (avoids recursion)
            applyHighlight(node);
            openPanel(node.data());
        };

        window.fitView = function () {
            if (layoutBusy || fitBusy) return;

            fitBusy = true;
            setToolbarActionBusy(true);
            cy.stop();
            cy.animate(
                { fit: { padding: 60 } },
                {
                    duration: 500,
                    complete: () => {
                        fitBusy = false;
                        setToolbarActionBusy(layoutBusy);
                    }
                }
            );
        };
        window.clearHighlight = function () { closePanel(); cy.nodes().removeClass('highlighted neighbor dimmed module-focus'); cy.edges().removeClass('highlighted dimmed'); };

        /* ────────────────────────────────────
           Edge flow animation
        ──────────────────────────────────── */
        let raf = null, doff = 0;
        function startFlow() {
            if (raf) return;
            function tick() {
                const hl = cy.edges('.highlighted');
                if (!hl.length) { raf = null; doff = 0; return; }
                doff -= 1.5;
                hl.style('line-dash-offset', doff);
                raf = requestAnimationFrame(tick);
            }
            raf = requestAnimationFrame(tick);
        }
        function stopFlow() { if (raf) { cancelAnimationFrame(raf); raf = null; doff = 0; } }

        /* ────────────────────────────────────
           Node click
        ──────────────────────────────────── */
        cy.on('tap', 'node', function (evt) {
            const node = evt.target;
            if (node.data('_groupNode')) return;
            applyHighlight(node);
            openPanel(node.data());
        });

        cy.on('cxttap', 'node', function (evt) {
            if (evt.target.data('_groupNode')) return;
            evt.preventDefault(); enterSubGraph(evt.target);
        });

        cy.on('tap', evt => { if (evt.target === cy) closePanel(); });

        /* ────────────────────────────────────
           Tooltip
        ──────────────────────────────────── */
        const tip = document.getElementById('tip');
        cy.on('mouseover', 'node', function (evt) {
            const d = evt.target.data();
            if (d._groupNode) return;
            tip.innerHTML = `<strong>${d.label}</strong><br><span style="color:var(--tx3);font-size:9px">${d.fullLabel || d.label || d.id}</span><br><span style="color:var(--tx3)">${d.kind}</span>`;
            tip.classList.add('show');
        });
        cy.on('mouseout', 'node', () => tip.classList.remove('show'));
        cy.on('mousemove', 'node', evt => {
            const p = evt.renderedPosition || evt.position;
            tip.style.left = (p.x + 15) + 'px'; tip.style.top = (p.y + 60) + 'px';
        });

        /* ────────────────────────────────────
           Search
        ──────────────────────────────────── */
        function debounce(func, wait) {
            let timeout;
            return function (...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        window.doSearch = function () {
            const q = document.getElementById('si').value.trim().toLowerCase();
            if (!q) { cy.nodes().removeClass('dimmed highlighted'); return; }
            cy.nodes().forEach(node => {
                if (node.data('_groupNode')) return;
                const m = (node.data('label') || '').toLowerCase().includes(q) || node.id().toLowerCase().includes(q);
                node.toggleClass('dimmed', !m); node.toggleClass('highlighted', m);
            });
            const matches = cy.nodes('.highlighted');
            if (matches.length === 1) {
                const node = matches[0];
                cy.animate({ center: { eles: node }, zoom: 1.2 }, { duration: 300 });
                applyHighlight(node);
                openPanel(node.data());
            }
            else if (matches.length > 0) cy.fit(matches, 50);
        };

        const debouncedSearch = debounce(doSearch, 300);

        document.getElementById('si').addEventListener('input', debouncedSearch);

        document.getElementById('si').addEventListener('keyup', function (e) {
            if (e.key === 'Enter') doSearch();
            if (e.key === 'Escape') { this.value = ''; cy.nodes().removeClass('dimmed highlighted'); }
        });

        /* ────────────────────────────────────
           Keyboard shortcuts
        ──────────────────────────────────── */
        document.addEventListener('keydown', function (e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            try {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); document.getElementById('si')?.focus(); return; }
                if (e.key === 'Escape') { e.preventDefault(); closePanel(); if (sgMode) exitSubGraph(); return; }
                if (e.altKey || (e.metaKey && e.key !== 'k') || (e.ctrlKey && e.key !== 'k')) return;
                if (e.key.toLowerCase() === 'm') { e.preventDefault(); toggleModPanel(); return; }
                if (e.key.toLowerCase() === 't') { e.preventDefault(); toggleThemePicker(); return; }
                if (e.key.toLowerCase() === 'h') { e.preventDefault(); toggleHeatmap(); return; }
                if (e.key.toLowerCase() === 'f') { e.preventDefault(); fitView(); return; }
                if (e.key === '1') { e.preventDefault(); runLayout('dagre'); return; }
                if (e.key === '2') { e.preventDefault(); runLayout('cose'); return; }
                if (e.key === '3') { e.preventDefault(); runLayout('lr'); return; }
                if (e.key === '4') { e.preventDefault(); runLayout('compact'); return; }
                if (e.key === '?') { e.preventDefault(); const h = document.getElementById('kb-help'); if (h) h.style.display = h.style.display === 'flex' ? 'none' : 'flex'; return; }
                if (e.key.toLowerCase() === 's') {
                    e.preventDefault();
                    const t = cy.nodes(':selected').length ? cy.nodes(':selected') : (cy.nodes('.highlighted').length ? cy.nodes('.highlighted') : null);
                    if (t) enterSubGraph(t);
                }
            } catch (err) { console.error('[LogicMap] shortcut error:', err); }
        });

        document.getElementById('kb-help')?.addEventListener('click', function (e) { if (e.target === this) this.style.display = 'none'; });

        /* ────────────────────────────────────
           SubGraph Mode
        ──────────────────────────────────── */
        /* ── SubGraph Mode ─────────────────────────────────────
           sgLastSeed: always stores a STRING node ID (not a cy collection)
           so it survives elements.remove() / restore cycles.
        ──────────────────────────────────── */

        function _sgShowUI(seedLabel) {
            document.body.classList.add('subgraph-mode');
            // Show floating control bar
            const bar = document.getElementById('sg-controls-bar');
            if (bar) bar.classList.add('show');
            // Show topbar badge
            const badge = document.getElementById('sg-badge');
            if (badge) badge.style.display = 'flex';
        }

        function _sgHideUI() {
            document.body.classList.remove('subgraph-mode');
            const bar = document.getElementById('sg-controls-bar');
            if (bar) bar.classList.remove('show');
            const badge = document.getElementById('sg-badge');
            if (badge) badge.style.display = 'none';
        }

        function _getDepth() {
            const raw = document.getElementById('sg-hops')?.value;
            const val = parseInt(raw) || 2;
            return val >= 99 ? 99 : Math.max(1, val);
        }

        function isSubgraphLocked() {
            return sgBusy || Date.now() < sgActionCooldownUntil;
        }

        function syncSubgraphControlsLockState() {
            const locked = isSubgraphLocked();
            const depthButtons = document.querySelectorAll('#sg-depth-grp .sgc-depth-btn');
            depthButtons.forEach(btn => {
                btn.disabled = locked;
            });

            const rerunBtn = document.querySelector('#sg-controls-bar .sgc-btn');
            if (rerunBtn) {
                rerunBtn.disabled = locked;
                rerunBtn.classList.toggle('is-busy', sgBusy);
            }
        }

        function startSubgraphActionCooldown(ms = SG_ACTION_COOLDOWN_MS) {
            sgActionCooldownUntil = Date.now() + ms;
            if (sgCooldownTimer) clearTimeout(sgCooldownTimer);
            sgCooldownTimer = setTimeout(() => {
                syncSubgraphControlsLockState();
            }, ms + 20);
            syncSubgraphControlsLockState();
        }

        function setSubgraphBusyState(isBusy) {
            sgBusy = isBusy;
            syncSubgraphControlsLockState();
        }

        function runSubgraphLayoutAfterLoad(onDone) {
            const execute = () => runLayout(currentLayout, onDone);
            if (!fitBusy) {
                execute();
                return;
            }

            const waiter = setInterval(() => {
                if (!fitBusy) {
                    clearInterval(waiter);
                    execute();
                }
            }, 60);
        }

        // Core fetch-and-render logic, always takes string seedId
        function _loadSubgraph(seedId) {
            if (!seedId || isSubgraphLocked()) return;
            const requestId = ++sgRequestSerial;
            setSubgraphBusyState(true);
            const depth = _getDepth();
            const isStaleRequest = () => requestId !== sgRequestSerial || !sgMode || sgLastSeed !== seedId;
            const finalize = (focusSeedNode) => {
                if (isStaleRequest()) {
                    setSubgraphBusyState(false);
                    startSubgraphActionCooldown(SG_STALE_COOLDOWN_MS);
                    return;
                }

                separateOrphanNodes();
                runSubgraphLayoutAfterLoad(() => {
                    if (!isStaleRequest()) {
                        focusSeedNode();
                        applyHeatmapMode();
                    }
                    setSubgraphBusyState(false);
                    startSubgraphActionCooldown(SG_ACTION_COOLDOWN_MS);
                });
            };
            const focusSeedNode = () => {
                const seedNode = cy.getElementById(seedId);
                if (!seedNode.length) return;

                applyHighlight(seedNode);
                openPanel(seedNode.data());
                cy.animate({
                    fit: { padding: 50 },
                    duration: 450,
                    easing: 'ease-out-cubic'
                });
            };

            // Dim everything while loading
            cy.startBatch();
            cy.elements().addClass('dimmed');
            cy.endBatch();

            // depth 99 = "All": send large value to BE, local fallback uses 10 hops
            const apiDepth = depth >= 99 ? 10 : depth;
            fetch(withSnapshot(`${window.logicMapConfig.subgraphUrl}/${encodeURIComponent(seedId)}`, { depth: apiDepth }))
                .then(r => r.json())
                .then(json => {
                    // Restore original graph
                    cy.startBatch();
                    cy.elements().remove();
                    sgOriginalElements.forEach(el => cy.add(el));

                    if (json.ok && json.data && json.data.nodes.length) {
                        // Merge extra nodes/edges returned by BE (beyond overview)
                        json.data.nodes.forEach(n => {
                            if (!cy.getElementById(n.id).length) {
                                cy.add({ data: { ...n, label: formatShortLabel(n.name || n.id), fullLabel: n.name || n.id, _ns: getNamespace(n.id) } });
                            }
                        });
                        json.data.edges.forEach(e => {
                            const bid = `${e.source}->${e.target}:${e.type}`;
                            if (!cy.getElementById(bid).length && !addedEdgeIds.has(bid)) {
                                cy.add({ data: { ...e, id: getUniqueEdgeId(e) } });
                            }
                        });

                        // Keep only BE-returned nodes + seed.
                        // Do not force-remove orphan nodes here: a valid subgraph can be a single isolated seed.
                        const keepIds = new Set(json.data.nodes.map(n => n.id));
                        keepIds.add(seedId);
                        cy.nodes().forEach(n => {
                            if (n.data('_groupNode')) return;
                            if (!keepIds.has(n.id())) n.remove();
                        });
                        // Remove dangling edges
                        cy.edges().forEach(e => {
                            if (!e.source().length || !e.target().length) e.remove();
                        });
                    } else {
                        // Fallback: local BFS neighbourhood
                        const seed = cy.getElementById(seedId);
                        if (seed.length) {
                            let nb = seed;
                            for (let i = 0; i < depth; i++) nb = nb.union(nb.neighborhood());
                            // Keep seed + neighbourhood; isolated seed is still valid in subgraph mode
                            cy.nodes().forEach(n => {
                                if (n.data('_groupNode')) return;
                                if (!nb.has(n)) n.remove();
                            });
                            cy.edges().forEach(e => {
                                if (!e.source().length || !e.target().length) e.remove();
                            });
                        }
                    }
                    cy.endBatch();
                    finalize(focusSeedNode);
                })
                .catch(() => {
                    if (isStaleRequest()) {
                        setSubgraphBusyState(false);
                        startSubgraphActionCooldown(SG_STALE_COOLDOWN_MS);
                        return;
                    }
                    // Network error: restore + local BFS
                    cy.startBatch();
                    cy.elements().remove();
                    sgOriginalElements.forEach(el => cy.add(el));
                    const seed = cy.getElementById(seedId);
                        if (seed.length) {
                            let nb = seed;
                            for (let i = 0; i < depth; i++) nb = nb.union(nb.neighborhood());
                            cy.nodes().forEach(n => {
                                if (n.data('_groupNode')) return;
                                if (!nb.has(n)) n.remove();
                            });
                            cy.edges().forEach(e => {
                                if (!e.source().length || !e.target().length) e.remove();
                        });
                    }
                    cy.endBatch();
                    finalize(focusSeedNode);
                });
        }

        window.enterSubGraph = function enterSubGraph(seedNodes) {
            if (isSubgraphLocked()) return;
            // Extract string ID immediately — cy collections become invalid after remove()
            let seedId;
            if (typeof seedNodes === 'string') {
                seedId = seedNodes;
            } else if (seedNodes && seedNodes.id && typeof seedNodes.id === 'function') {
                seedId = seedNodes.id();                 // single cy node
            } else if (seedNodes && seedNodes[0]) {
                seedId = seedNodes[0].id();              // cy collection
            }
            if (!seedId) return;

            // Store as string
            sgLastSeed = seedId;

            if (!sgMode) {
                sgOriginalElements = cy.elements().jsons();
            }
            sgMode = true;

            const shortLabel = cy.getElementById(seedId)?.data('label') || seedId.split('\\').pop() || seedId;
            _sgShowUI(shortLabel);
            _loadSubgraph(seedId);
        }

        window.rerunSubGraph = function () {
            if (!sgMode || !sgLastSeed || isSubgraphLocked()) return;
            // sgLastSeed is always a string ID here
            _loadSubgraph(sgLastSeed);
        };

        // Depth seg-group buttons
        function initDepthSelector() {
            const grp = document.getElementById('sg-depth-grp');
            const inp = document.getElementById('sg-hops');
            if (!grp || !inp) return;
            grp.querySelectorAll('.sgc-depth-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    if (isSubgraphLocked()) return;
                    if (this.classList.contains('active')) return;
                    grp.querySelectorAll('.sgc-depth-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    inp.value = this.dataset.depth;
                    // Auto re-run if already in subgraph mode
                    if (sgMode && sgLastSeed && !isSubgraphLocked()) _loadSubgraph(sgLastSeed);
                });
            });
        }
        initDepthSelector();

        window.exitSubGraph = function () {
            if (!sgMode) return;
            sgRequestSerial += 1; // invalidate in-flight subgraph responses
            sgActionCooldownUntil = 0;
            if (sgCooldownTimer) {
                clearTimeout(sgCooldownTimer);
                sgCooldownTimer = null;
            }
            setSubgraphBusyState(false);
            sgMode = false;
            sgLastSeed = null;
            _sgHideUI();

            if (sgOriginalElements) {
                cy.startBatch();
                cy.elements().remove();
                sgOriginalElements.forEach(el => cy.add(el));
                cy.endBatch();
                sgOriginalElements = null;
                separateOrphanNodes();
                runLayout(currentLayout, () => {
                    cy.animate({
                        fit: { padding: 40 },
                        duration: 400
                    });
                });
                applyHeatmapMode();
            }
        };

        /* ────────────────────────────────────
           Orphan nodes — pure positional layout, no compound nodes.
    
           Layout model:
             Flow ↓ / Force / Compact  →  each kind = 1 horizontal ROW
                                           kinds stacked below connected graph
    
             LR →                       →  each kind = 1 vertical COLUMN
                                           kinds placed side-by-side to the right
    
           No wrapper/border box drawn. Nodes just get plain positions.
        ──────────────────────────────────── */

        // Tag every orphan node with _orphan:true so we can find them later.
        // Called once after data load, and again after subgraph lazy-loads.
        function separateOrphanNodes() {
            if (sgMode) {
                // In subgraph mode, we strictly don't want ANY unintended orphan nodes
                cy.nodes().forEach(n => n.data('_orphan', null));
                return;
            }
            // Build set of node IDs that appear in any edge (raw + cy)
            const nodesWithEdges = new Set();
            allEdgesData.forEach(e => {
                nodesWithEdges.add(e.source);
                nodesWithEdges.add(e.target);
            });
            cy.edges().forEach(e => {
                nodesWithEdges.add(e.source().id());
                nodesWithEdges.add(e.target().id());
            });

            // Tag / untag
            cy.nodes().forEach(n => {
                if (n.data('_groupNode')) return;
                const isOrphan = !nodesWithEdges.has(n.id());
                n.data('_orphan', isOrphan ? true : null);
            });
        }

        // Called after every layout run. Positions orphan nodes in kind-rows/cols.
        function positionOrphanGroups() {
            if (sgMode) return;
            // Collect visible orphan nodes grouped by kind
            const byKind = {};
            cy.nodes().forEach(n => {
                if (!n.data('_orphan') || n.data('_groupNode')) return;
                const k = n.data('kind') || 'unknown';
                if (!byKind[k]) byKind[k] = [];
                byKind[k].push(n);
            });

            const kinds = KIND_ORDER.filter(k => byKind[k] && byKind[k].length > 0);
            if (!kinds.length) return;

            // Bounding box of connected (non-orphan) nodes
            const connected = cy.nodes().filter(n => !n.data('_orphan') && !n.data('_groupNode'));
            const bb = connected.length > 0
                ? connected.boundingBox()
                : { x1: 0, y1: 0, x2: 600, y2: 400 };

            const isLR = (currentLayout === 'lr' || currentLayout === 'compact');
            const nodeW = 160, nodeH = 55;
            const gapX = 40, gapY = 30;   // gap between nodes
            const kindGap = 60;               // gap between kind groups

            if (isLR) {
                // ── LR mode: kinds as columns, placed to the right of the graph ──
                // Each column = one kind.  Nodes go top → bottom in each column.
                let colX = bb.x2 + kindGap * 2 + nodeW / 2;

                kinds.forEach(kind => {
                    const nodes = byKind[kind];
                    nodes.forEach((n, i) => {
                        n.position({
                            x: colX,
                            y: bb.y1 + i * (nodeH + gapY) + nodeH / 2
                        });
                    });
                    colX += nodeW + kindGap;
                });
            } else {
                // ── Flow / Force / Compact: kinds as rows, placed below the graph ──
                // Each row = one kind.  Nodes go left → right in each row.
                let rowY = bb.y2 + kindGap * 2 + nodeH / 2;

                kinds.forEach(kind => {
                    const nodes = byKind[kind];
                    nodes.forEach((n, i) => {
                        n.position({
                            x: bb.x1 + i * (nodeW + gapX) + nodeW / 2,
                            y: rowY
                        });
                    });
                    rowY += nodeH + kindGap;
                });
            }
        }

        /* ────────────────────────────────────
           Cross-module edges
        ──────────────────────────────────── */
        function computeCrossModuleEdges(nodes, edges) {
            crossModuleEdges = {};
            const nm = {};
            nodes.forEach(n => { nm[n.id] = getNamespace(n.id); });
            edges.forEach(e => {
                const s = nm[e.source], t = nm[e.target];
                if (s && t && s !== t) { const k = `${s}>>>${t}`; crossModuleEdges[k] = (crossModuleEdges[k] || 0) + 1; }
            });
        }

        window.focusModule = function (ns) {
            cy.nodes().removeClass('module-focus dimmed');
            cy.edges().removeClass('dimmed');
            cy.nodes().forEach(n => {
                if (n.data('_groupNode')) return;
                if ((n.data('_ns') || getNamespace(n.id())) === ns) n.addClass('module-focus');
                else n.addClass('dimmed');
            });
            cy.edges().forEach(e => {
                if (!e.source().hasClass('module-focus') && !e.target().hasClass('module-focus')) e.addClass('dimmed');
            });
        };

        /* ────────────────────────────────────
           Module Explorer builder
        ──────────────────────────────────── */
        function buildModuleExplorer(nodes, edges) {
            const groups = {};
            nodes.forEach(n => { const ns = getNamespace(n.id); if (!groups[ns]) groups[ns] = []; groups[ns].push(n); });
            computeCrossModuleEdges(nodes, edges);

            const sorted = Object.entries(groups).sort((a, b) => b[1].length - a[1].length);
            document.getElementById('mod-body').innerHTML = sorted.map(([ns, items]) => {
                const color = KIND_COLORS[items[0]?.kind] || KIND_COLORS.unknown;
                const pills = items.map(n => {
                    let short = (n.name || n.label || n.id || '').split('\\').pop().replace(/^[+@\s!#]+/, '').trim();
                    return `<span class="mod-pill" onclick="focusNode('${n.id}')" title="${n.id}">${short}</span>`;
                }).join('');

                const out = [], inc = [];
                Object.entries(crossModuleEdges).forEach(([k, c]) => {
                    const [f, t] = k.split('>>>');
                    if (f === ns) out.push({ mod: t.split('\\').pop(), count: c });
                    if (t === ns) inc.push({ mod: f.split('\\').pop(), count: c });
                });
                let connHtml = '';
                if (out.length || inc.length) {
                    connHtml = `<div class="mod-conns open">`;
                    out.slice(0, 3).forEach(c => { connHtml += `<div class="mod-conn-row"><span class="conn-arr">→</span><span>${c.mod}</span><span class="mod-conn-cnt">${c.count}</span></div>`; });
                    inc.slice(0, 3).forEach(c => { connHtml += `<div class="mod-conn-row"><span class="conn-arr">←</span><span>${c.mod}</span><span class="mod-conn-cnt">${c.count}</span></div>`; });
                    connHtml += `</div>`;
                }

                return `<div class="mod-card" data-ns="${ns}">
                <div class="mod-hdr-row" onclick="focusModule('${ns}');this.nextElementSibling.classList.toggle('open')">
                    <div class="mod-dot" style="background:${color.bd}"></div>
                    <span class="mod-name" title="${ns}">${ns.split('\\').pop() || ns}</span>
                    <span class="mod-cnt">${items.length}</span>
                </div>
                <div class="mod-pills">${pills}</div>
                ${connHtml}
            </div>`;
            }).join('');
        }

        // Module visibility toggle removed.

        /* ────────────────────────────────────
           Graph analysis helpers
        ──────────────────────────────────── */
        function filterOrphanNodes(nodes, edges) {
            const hasEdge = new Set();
            edges.forEach(e => { hasEdge.add(e.source); hasEdge.add(e.target); });
            return nodes.filter(n => {
                if (n.kind === 'route') return true;
                if (hasEdge.has(n.id)) return true;
                const m = n.metrics || {};
                return (m.fan_in || 0) > 0 || (m.fan_out || 0) > 0;
            });
        }

        function findDisconnectedComponents(nodes, edges) {
            const ids = new Set(nodes.map(n => n.id)), adj = {};
            ids.forEach(id => { adj[id] = []; });
            edges.forEach(e => { if (ids.has(e.source) && ids.has(e.target)) { adj[e.source].push(e.target); adj[e.target].push(e.source); } });
            const vis = new Set(), comps = [];
            function bfs(s) { const c = [], q = [s]; vis.add(s); while (q.length) { const id = q.shift(); c.push(id); (adj[id] || []).forEach(nb => { if (!vis.has(nb)) { vis.add(nb); q.push(nb); } }); } return c; }
            ids.forEach(id => { if (!vis.has(id)) comps.push(bfs(id)); });
            return comps;
        }

        function detectCycles(nodes, edges) {
            const ids = new Set(nodes.map(n => n.id)), adj = {};
            ids.forEach(id => { adj[id] = []; });
            edges.forEach(e => { if (ids.has(e.source) && ids.has(e.target)) adj[e.source].push(e.target); });
            const W = 0, G = 1, B = 2, color = {}, cycles = [];
            ids.forEach(id => { color[id] = W; });
            function dfs(id, path) {
                color[id] = G; path.push(id);
                for (const nb of (adj[id] || [])) {
                    if (color[nb] === G) { const ci = path.indexOf(nb); cycles.push(path.slice(ci).concat(nb)); }
                    else if (color[nb] === W) dfs(nb, path);
                }
                path.pop(); color[id] = B;
            }
            ids.forEach(id => { if (color[id] === W) dfs(id, []); });
            return cycles;
        }

        let warnOffset = 0;
        function showWarning(html, bg, bd, tx, dur = 5000) {
            const w = document.createElement('div');
            w.style.cssText = `position:fixed;top:${60 + warnOffset}px;right:16px;background:${bg};border:1px solid ${bd};padding:7px 12px;border-radius:6px;font-size:9.5px;color:${tx};z-index:30;max-width:300px;transition:opacity .3s;display:flex;align-items:center;gap:6px;font-family:inherit;`;
            w.innerHTML = html; document.body.appendChild(w); warnOffset += 42;
            setTimeout(() => { w.style.opacity = '0'; setTimeout(() => { w.remove(); warnOffset = Math.max(0, warnOffset - 42); }, 300); }, dur);
        }

        /* ────────────────────────────────────
           Legend: draggable + collapsible
        ──────────────────────────────────── */
        function initLegend() {
            const leg = document.getElementById('legend');
            const hdr = document.getElementById('legend-hdr');
            if (!leg || !hdr) return;

            let drag = false, sx, sy, ol, ob;
            hdr.addEventListener('mousedown', function (e) {
                if (e.target.closest('.icon-btn')) return;
                drag = true; leg._dragged = true; leg.classList.add('dragging');
                sx = e.clientX; sy = e.clientY;
                const r = leg.getBoundingClientRect();
                ol = r.left; ob = window.innerHeight - r.bottom;
                e.preventDefault();
            });
            document.addEventListener('mousemove', function (e) {
                if (!drag) return;
                const nl = Math.max(0, Math.min(window.innerWidth - leg.offsetWidth, ol + (e.clientX - sx)));
                const nb = Math.max(0, Math.min(window.innerHeight - leg.offsetHeight, ob - (e.clientY - sy)));
                leg.style.left = nl + 'px'; leg.style.bottom = nb + 'px'; leg.style.right = 'auto';
            });
            document.addEventListener('mouseup', () => { if (drag) { drag = false; leg.classList.remove('dragging'); } });
        }

        window.toggleLegend = function () {
            const body = document.getElementById('legend-body');
            const chev = document.getElementById('legend-chevron');
            if (!body) return;
            const c = body.classList.toggle('collapsed');
            if (chev) chev.style.transform = c ? 'rotate(180deg)' : '';
        };

        window.hideLegend = function () {
            const leg = document.getElementById('legend');
            const r = document.getElementById('legend-restore');
            if (!leg || !r) return;
            // Mirror legend's current position to restore button
            const rect = leg.getBoundingClientRect();
            r.style.left = leg.style.left || '16px';
            r.style.bottom = leg.style.bottom || '16px';
            leg.style.display = 'none';
            r.style.display = 'flex';
        };

        window.showLegend = function () {
            const leg = document.getElementById('legend');
            const r = document.getElementById('legend-restore');
            if (!leg || !r) return;
            leg.style.display = 'block'; // override CSS display:none
            r.style.display = 'none';
        };

        initLegend();

        /* ────────────────────────────────────
           Load data
        ──────────────────────────────────── */
        const ldMsg = document.getElementById('ld-msg');
        initSnapshotDropdown();
        syncHeatmapUI();

        /* ────────────────────────────────────
           Health Panel
        ──────────────────────────────────── */
        let _healthData = null;
        let _violationsData = null;

        fetch(withSnapshot(window.logicMapConfig.healthUrl)).then(r => r.json()).then(j => {
            if (!j.ok) return;
            _healthData = j.data;
            const d = j.data;
            document.getElementById('health-display').innerHTML =
                `<button class="health-badge grade-${d.grade}" onclick="openHealthPanel()" title="View health details">
                ${d.grade} <span style="opacity:.7">${d.score}/100</span>
            </button>`;
        }).catch(() => { });

        if (window.logicMapConfig.violationsUrl) {
            fetch(withSnapshot(window.logicMapConfig.violationsUrl)).then(r => r.json()).then(j => {
                if (j.ok) { _violationsData = j.data; }
            }).catch(() => { });
        }

        window.openHealthPanel = function () {
            const panel = document.getElementById('health-panel');
            if (!panel) return;
            if (panel.classList.contains('open')) {
                panel.classList.remove('open');
            } else {
                renderHealthPanel();
                panel.classList.add('open');
            }
        };

        window.closeHealthPanel = function () {
            document.getElementById('health-panel')?.classList.remove('open');
        };

        // Toggle collapse state of a section in the health panel
        window.hpToggle = function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.toggle('hp-collapsed');
        };

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
                        ? Object.entries(d.summary).map(([k, v]) => `${k.replace(/_/g, ' ')}: ${v}`).join(' · ')
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

            // ── Scoring Guide (shown first) ──
            html += `<div class="hp-section hp-collapsible hp-collapsed" id="hps-guide">
            <div class="hp-section-title hp-toggle" onclick="hpToggle('hps-guide')">
                <span>Scoring Guide</span>
                <svg class="hp-chev" viewBox="0 0 16 16"><polyline points="4 6 8 10 12 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </div>
            <div class="hp-collapsible-body">
                <div class="hp-guide">
                    <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.critical.bd}">Critical</span><span>−${d.weights.critical || 25} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.critical || 'Circular deps, breaking issues'}</span></div>
                    <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.high.bd}">High</span><span>−${d.weights.high || 10} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.high || 'Fat controllers, structural debt'}</span></div>
                    <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.medium.bd}">Medium</span><span>−${d.weights.medium || 5} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.medium || 'High instability / coupling'}</span></div>
                    <div class="hp-guide-row"><span class="hp-guide-sev" style="color:${SEVER_COLORS.low.bd}">Low</span><span>−${d.weights.low || 1} pts</span><span class="hp-guide-desc">${d.severity_descriptions?.low || 'Orphan / dead-code signals, minor issues'}</span></div>
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

            // ── Violations (collapsible groups) ──
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
                    // Auto-expand critical, collapse others by default
                    const defaultCollapsed = (sev !== 'critical') ? 'hp-collapsed' : '';
                    html += `<div class="hp-sev-group hp-collapsible ${defaultCollapsed}" id="${gid}">
                    <div class="hp-sev-hdr hp-toggle" style="color:${sc.tx}" onclick="hpToggle('${gid}')">
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

                        // Format circular dependency messages: split long paths into visual chain
                        let formattedMsg = '';
                        if (rawMsg) {
                            // Extract node names from full qualified paths (e.g. App\Services\Foo@bar)
                            const shortened = rawMsg.replace(
                                /[A-Za-z0-9_\\]+\\([A-Za-z0-9_]+)(@[A-Za-z0-9_]+)?/g,
                                (match, cls, method) => method ? `${cls}${method}` : cls
                            );
                            // Split "Part of circular dependency:" prefix
                            if (shortened.startsWith('Part of circular dependency:')) {
                                const chain = shortened.replace('Part of circular dependency:', '').trim();
                                // Split on arrows (→ or ->) and render as badge chain
                                const steps = chain.split(/\s*(?:→|->)\s*/).filter(Boolean);
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

                        // Short node name for the footer
                        const shortNode = nodeId ? nodeId.replace(/^(method|class|route):/, '').split('\\').pop().split('@').pop() : '';

                        html += `<div class="hp-viol" style="border-left-color:${sc.bd}${nodeId ? ';cursor:pointer' : ''}" ${nodeId ? `onclick="focusNode('${nodeId}');closeHealthPanel()"` : ''}>
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

        fetch(withSnapshot(window.logicMapConfig.metaUrl)).then(r => r.json()).then(j => {
            if (j.ok) { 
                document.getElementById('s-nodes').textContent = j.data.node_count; 
                document.getElementById('s-edges').textContent = j.data.edge_count; 
                window.metaData = j.data;
                if (j.data.kind_labels) KIND_LABELS = Object.assign(KIND_LABELS, j.data.kind_labels);
            }
        }).catch(() => { });

        ldMsg.textContent = 'Loading graph…';
        fetch(withSnapshot(window.logicMapConfig.overviewUrl)).then(r => r.json()).then(json => {
            if (!json.ok) { ldMsg.textContent = 'Error: ' + json.message; return; }
            allNodesData = json.data.nodes || [];
            allEdgesData = json.data.edges || [];

            if (!allNodesData.length) {
                const l = document.getElementById('loading');
                l.innerHTML = `<div class="ld-logo">Logic Map</div><div style="margin-top:18px;text-align:center"><div style="font-size:32px;margin-bottom:12px">📭</div><div style="color:var(--tx2);margin-bottom:8px">No nodes found</div><div style="font-size:10px;color:var(--tx3)">Run <code style="background:var(--bg-elevated);padding:2px 6px;border-radius:3px">php artisan logic-map:build</code></div></div>`;
                l.style.opacity = '1'; return;
            }

            const filteredNodes = filterOrphanNodes(allNodesData, allEdgesData);
            const largeThreshold = (window.metaData?.ui_thresholds?.large_graph || 150);
            if (filteredNodes.length > largeThreshold) { document.getElementById('lg-warning').classList.add('show'); setTimeout(() => document.getElementById('lg-warning').classList.remove('show'), 5000); }

            cy.startBatch();
            filteredNodes.forEach(n => cy.add({ data: { ...n, label: formatShortLabel(n.name || n.id), fullLabel: n.name || n.id, _ns: getNamespace(n.id) } }));
            allEdgesData.forEach(e => {
                if (cy.getElementById(e.source).length && cy.getElementById(e.target).length)
                    cy.add({ data: { ...e, id: getUniqueEdgeId(e) } });
            });
            cy.endBatch();

            separateOrphanNodes();
            buildModuleExplorer(filteredNodes, allEdgesData);
            applyHeatmapMode();

            const comps = findDisconnectedComponents(filteredNodes, allEdgesData);
            if (comps.length > 1 && filteredNodes.length > 3) showWarning(`⚠ ${comps.length} disconnected components`, 'rgba(249,115,22,.1)', '#f97316', '#f97316');

            const cycles = detectCycles(filteredNodes, allEdgesData);
            if (cycles.length) {
                showWarning(`🔄 Cycle: <b>${cycles[0].map(id => formatShortLabel(id)).join(' → ')}</b>${cycles.length > 1 ? ` (+${cycles.length - 1})` : ''}`, 'rgba(239,68,68,.1)', '#ef4444', '#ef4444', 5000);
                cycles.forEach(c => c.forEach(id => { const n = cy.getElementById(id); if (n.length) { n.style('border-color', '#ef4444'); n.style('border-width', 2); } }));
            }

            ldMsg.textContent = 'Rendering layout…';
            setTimeout(() => {
                // Force first render layout; without callback, runLayout short-circuits
                // when currentLayout is already "dagre", causing all nodes to overlap at origin.
                runLayout('dagre', () => { });
                const l = document.getElementById('loading');
                l.style.opacity = '0'; setTimeout(() => l.style.display = 'none', 400);
            }, 100);
        }).catch(err => { ldMsg.textContent = 'Failed: ' + err.message; });


        /* ────────────────────────────────────
           Dropdowns
        ──────────────────────────────────── */
        window.toggleDropdown = function (e, id) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            const el = document.getElementById(id);
            if (!el) { console.warn('[LogicMap] Dropdown not found:', id); return; }
            const wasOpen = el.classList.contains('show');
            document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
            if (!wasOpen) el.classList.add('show');
        };

        // Legacy alias for export
        window.toggleExportMenu = function (e) {
            toggleDropdown(e, 'export-dropdown');
        };

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.dropdown-grp')) {
                document.querySelectorAll('.dropdown-grp').forEach(d => d.classList.remove('show'));
            }
        });

        /* ────────────────────────────────────
           Init
        ──────────────────────────────────── */
        // Initialize active triggers
        function initTriggers() {
            // Layout (Clone to mobile menu if exists)
            const layoutGrp = document.getElementById('layout-grp');
            const layoutMenu = document.getElementById('layout-menu');
            if (layoutGrp && layoutMenu) {
                layoutMenu.innerHTML = layoutGrp.innerHTML;
            }

            const activeLayout = document.querySelector('#layout-grp .seg-btn.active');
            if (activeLayout) {
                const triggerLabel = document.getElementById('layout-trigger-label');
                const triggerIcon = document.getElementById('layout-trigger-icon');
                if (triggerLabel) triggerLabel.textContent = activeLayout.querySelector('.btn-lbl')?.textContent || activeLayout.dataset.layout;
                if (triggerIcon) {
                    const btnSvg = activeLayout.querySelector('svg');
                    if (btnSvg) triggerIcon.innerHTML = btnSvg.innerHTML;
                }
            }
            // Hops (Clone to mobile menu if exists)
            const hopsGrp = document.getElementById('hl-hops-grp');
            const hopsMenu = document.getElementById('hops-menu');
            if (hopsGrp && hopsMenu) {
                hopsMenu.innerHTML = hopsGrp.innerHTML;
            }

            const activeHops = document.querySelector('#hl-hops-grp .seg-btn.active');
            if (activeHops) {
                const triggerVal = document.getElementById('hops-trigger-val');
                if (triggerVal) {
                    const h = activeHops.getAttribute('data-hops');
                    triggerVal.textContent = (h === '99' ? 'All' : h);
                }
            }
        }
        setTimeout(initTriggers, 200); // Slight delay to ensure DOM and data are ready

    }; // end init

    if (document.readyState === 'complete' || document.readyState === 'interactive') init();
    else document.addEventListener('DOMContentLoaded', init);
})();
