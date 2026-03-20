/* report-page.js — Shared JS for Impact & Trace report pages */

/* ── Collapsible section toggle ─────────────────────────────── */
document.querySelectorAll('.rp-collapse-trigger').forEach(function (btn) {
    var targetId = btn.getAttribute('data-target');
    var body = document.getElementById(targetId);

    // default state driven by data-open attribute
    if (btn.getAttribute('data-open') === 'true') {
        btn.setAttribute('aria-expanded', 'true');
        if (body) body.classList.add('open');
    } else {
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function () {
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        if (body) body.classList.toggle('open', !expanded);
    });
});

/* ── Copy Markdown ──────────────────────────────────────────── */
window.rpCopyMarkdown = async function (downloadUrl) {
    try {
        var resp = await fetch(downloadUrl);
        var text = await resp.text();
        await navigator.clipboard.writeText(text);
        alert('Markdown copied to clipboard.');
    } catch (e) {
        alert('Could not copy to clipboard.');
    }
};

/* ── Save to Project Docs (Phase D) ────────────────────────── */
window.rpSaveToDocs = function (saveUrl, btnId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Saving\u2026';
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    fetch(saveUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = data.ok ? '\u2713 Saved' : '\u2717 Failed';
            if (data.message) alert(data.message);
            setTimeout(function () { btn.disabled = false; btn.textContent = 'Save to Docs'; }, 3000);
        })
        .catch(function () {
            btn.textContent = '\u2717 Error';
            setTimeout(function () { btn.disabled = false; btn.textContent = 'Save to Docs'; }, 3000);
        });
};
