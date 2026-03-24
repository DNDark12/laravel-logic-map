import { S, getNamespace, KIND_COLORS } from '../state.js';

let crossModuleEdges = {};

export function computeCrossModuleEdges(nodes, edges) {
    crossModuleEdges = {};
    const nm = {};
    nodes.forEach(n => { nm[n.id] = getNamespace(n.id); });
    edges.forEach(e => {
        const s = nm[e.source], t = nm[e.target];
        if (s && t && s !== t) {
            const k = `${s}>>>${t}`;
            crossModuleEdges[k] = (crossModuleEdges[k] || 0) + 1;
        }
    });
}

export function focusModule(ns) {
    S.cy.nodes().removeClass('module-focus dimmed');
    S.cy.edges().removeClass('dimmed');
    
    S.cy.nodes().forEach(n => {
        if (n.data('_groupNode')) return;
        if ((n.data('_ns') || getNamespace(n.id())) === ns) {
            n.addClass('module-focus');
        } else {
            n.addClass('dimmed');
        }
    });
    
    S.cy.edges().forEach(e => {
        if (!e.source().hasClass('module-focus') && !e.target().hasClass('module-focus')) {
            e.addClass('dimmed');
        }
    });
}

export function buildModuleExplorer(nodes, edges) {
    const groups = {};
    nodes.forEach(n => {
        const ns = getNamespace(n.id);
        if (!groups[ns]) groups[ns] = [];
        groups[ns].push(n);
    });
    
    computeCrossModuleEdges(nodes, edges);

    const sorted = Object.entries(groups).sort((a, b) => b[1].length - a[1].length);
    const modBody = document.getElementById('mod-body');
    if (!modBody) return;

    modBody.innerHTML = sorted.map(([ns, items]) => {
        const color = KIND_COLORS[items[0]?.kind] || KIND_COLORS.unknown;
        const pills = items.map(n => {
            let short = (n.name || n.label || n.id || '').split('\\').pop().replace(/^[+@\s!#]+/, '').trim();
            return `<span class="mod-pill" onclick="LogicMap.focusNode('${n.id}')" title="${n.id}">${short}</span>`;
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
            out.slice(0, 3).forEach(c => {
                connHtml += `<div class="mod-conn-row"><span class="conn-arr">→</span><span>${c.mod}</span><span class="mod-conn-cnt">${c.count}</span></div>`;
            });
            inc.slice(0, 3).forEach(c => {
                connHtml += `<div class="mod-conn-row"><span class="conn-arr">←</span><span>${c.mod}</span><span class="mod-conn-cnt">${c.count}</span></div>`;
            });
            connHtml += `</div>`;
        }

        return `<div class="mod-card" data-ns="${ns}">
        <div class="mod-hdr-row" onclick="LogicMap.focusModule('${ns}');this.nextElementSibling.classList.toggle('open')">
            <div class="mod-dot" style="background:${color.bd}"></div>
            <span class="mod-name" title="${ns}">${ns.split('\\').pop() || ns}</span>
            <span class="mod-cnt">${items.length}</span>
        </div>
        <div class="mod-pills">${pills}</div>
        ${connHtml}
    </div>`;
    }).join('');
}

export function initModuleExplorerUI() {
    const modFilter = document.getElementById('mod-filter');
    if (modFilter) {
        modFilter.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.mod-card').forEach(card => {
                card.style.display = (!q || (card.dataset.ns || '').toLowerCase().includes(q)) ? '' : 'none';
            });
        });
    }
}
