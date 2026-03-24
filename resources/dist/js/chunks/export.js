import { S, formatDate } from '../state.js';

export function exportLogicMap(format) {
    if (format === 'analysis' || format === 'graph') {
        const url = format === 'analysis' ? window.logicMapConfig.analysisUrl : window.logicMapConfig.overviewUrl;
        const btn = Array.from(document.querySelectorAll('.dropdown-item')).find(b => b.textContent.toLowerCase().includes(format));
        const origText = btn ? btn.innerHTML : '';
        if (btn) btn.innerHTML = '<span class="spinner"></span> Exporting...';
        
        fetch(window.LogicMap.withSnapshot(url))
            .then(res => res.json())
            .then(json => {
                if (btn) btn.innerHTML = origText;
                if (!json.ok) { window.LogicMap.showWarning && window.LogicMap.showWarning('Export failed: ' + json.message, '#fee2e2', '#ef4444', '#b91c1c'); return; }
                const blob = new Blob([JSON.stringify(json.data, null, 2)], { type: 'application/json' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `logic-map-${format}-${formatDate(new Date())}.json`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            })
            .catch(err => {
                if (btn) btn.innerHTML = origText;
                window.LogicMap.showWarning && window.LogicMap.showWarning('Export failed: ' + err.message, '#fee2e2', '#ef4444', '#b91c1c');
            });
    } else if (format === 'csv') {
        const nodes = S.cy.nodes().map(n => n.data());
        let csv = 'ID,Name,Kind,Domain,Action,Risk,Coverage,FanIn,FanOut,Depth\n';
        nodes.forEach(n => {
            if (n._groupNode) return; // Skip group zones
            const cov = n.metadata?.coverage?.coverage_percent ?? '';
            csv += `"${n.id}","${n.name || n.label || ''}","${n.kind || ''}","${n.metadata?.domain || ''}","${n.metadata?.action || ''}","${n.risk || ''}","${cov}","${n.metrics?.fan_in || 0}","${n.metrics?.fan_out || 0}","${n.metrics?.depth || ''}"\n`;
        });
        const blob = new Blob([csv], { type: 'text/csv' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `logic-map-nodes-${formatDate(new Date())}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    } else if (format === 'bundle') {
        // Zip export (if supported by BE)
        const btn = Array.from(document.querySelectorAll('.dropdown-item')).find(b => b.textContent.toLowerCase().includes('bundle'));
        const origText = btn ? btn.innerHTML : '';
        if (btn) btn.innerHTML = '<span class="spinner"></span> Bundling...';

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.LogicMap.withSnapshot(window.logicMapConfig.bundleExportUrl || '/logic-map/api/export/bundle');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_token';
            input.value = csrfToken;
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
        
        setTimeout(() => { if (btn) btn.innerHTML = origText; document.body.removeChild(form); }, 2000);
    }
}
