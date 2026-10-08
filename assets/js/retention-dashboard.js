/**
 * Retention dashboard: keeps the progress panel live while a check or a Sheet refresh runs, reloads
 * once everything has finished, and lets notes in the sidebar be dismissed.
 */
(function () {
    'use strict';
    var cfg = window.aceSeoRetentionLive || {};
    var panel = document.getElementById('ace-retention-progress');

    function fmt(n) { return Number(n || 0).toLocaleString(); }
    function ago(ts, now) {
        var s = Math.max(0, (now || Math.floor(Date.now() / 1000)) - ts);
        if (s < 60) { return s + 's ago'; }
        if (s < 3600) { return Math.floor(s / 60) + ' min ago'; }
        return Math.floor(s / 3600) + ' h ago';
    }
    function set(name, text) { var el = panel && panel.querySelector('[data-field="' + name + '"]'); if (el) { el.textContent = text; } }

    function paint(d) {
        if (!panel) { return; }
        var build = panel.querySelector('[data-part="build"]');
        if (build) {
            var pct = d.total ? Math.min(100, Math.round(100 * d.offset / d.total)) : 0;
            var bar = panel.querySelector('[data-field="bar"]');
            if (bar) { bar.style.width = pct + '%'; bar.parentNode.setAttribute('aria-valuenow', pct); }
            set('label', d.label || (cfg.labels && cfg.labels[d.phase]) || d.phase);
            set('count', d.total ? fmt(Math.min(d.offset, d.total)) + ' of ' + fmt(d.total) : '');
            set('meta', d.tick_at ? '· last step ' + ago(d.tick_at, d.now) : '');
            var st = panel.querySelector('[data-field="state"]');
            if (st) { st.textContent = (cfg.states && cfg.states[d.state]) || d.state; st.className = 'ace-retention-state is-' + d.state; }
            if (d.phase === 'score') {
                var t = d.tiers || {};
                set('sofar', 'So far: ' + fmt(t.retained) + ' still being read, ' + fmt(t.unknown) + ' not ready to judge, ' + fmt((t.dormant || 0) + (t.candidate || 0)) + ' with no readers.');
            }
            if (d.error) { var e = panel.querySelector('[data-field="error"]'); if (e) { e.textContent = 'Last error: ' + d.error; } }
        }
        var sheet = panel.querySelector('[data-part="sheet"]');
        if (sheet && d.sheet) {
            var sp = d.sheet.total ? Math.min(100, Math.round(100 * d.sheet.written / d.sheet.total)) : 0;
            var sb = panel.querySelector('[data-field="sheet-bar"]');
            if (sb) { sb.style.width = sp + '%'; sb.parentNode.setAttribute('aria-valuenow', sp); }
            set('sheet-count', fmt(d.sheet.written) + ' of ' + fmt(d.sheet.total) + ' rows');
            set('sheet-state', d.sheet.status.charAt(0).toUpperCase() + d.sheet.status.slice(1));
        }
    }

    function poll() {
        var data = new FormData();
        data.append('action', 'ace_seo_retention_progress');
        data.append('nonce', cfg.nonce || '');
        fetch(cfg.ajaxUrl || window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) { return; }
                var d = json.data;
                paint(d);
                var stillBuilding = !!d.building;
                var sheetActive = !!(d.sheet && d.sheet.active);
                var hadSheet = !!panel.querySelector('[data-part="sheet"]');
                // A refresh that starts after the check (refresh-after-build) appears on reload; stop
                // polling only once both are quiet, then show the finished picture.
                if (!stillBuilding && !sheetActive) {
                    setTimeout(function () { window.location.reload(); }, 1500);
                    return;
                }
                if ((sheetActive && !hadSheet) || (!stillBuilding && panel.querySelector('[data-part="build"]') && d.state !== 'error')) {
                    window.location.reload();
                    return;
                }
                setTimeout(poll, 5000);
            })
            .catch(function () { setTimeout(poll, 10000); });
    }
    if (panel && panel.getAttribute('data-live') === '1') {
        setTimeout(poll, 5000);
    }

    // Dismissible notes in the sidebar.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.ace-retention-dismiss, .ace-retention-undismiss');
        if (!btn) { return; }
        var undo = btn.classList.contains('ace-retention-undismiss');
        var note = btn.closest('.ace-retention-note');
        var data = new FormData();
        data.append('action', 'ace_seo_retention_dismiss');
        data.append('nonce', cfg.nonce || '');
        if (undo) { data.append('undo', '1'); } else { data.append('hash', note ? note.dataset.hash : ''); }
        btn.disabled = true;
        fetch(cfg.ajaxUrl || window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) { btn.disabled = false; return; }
                if (undo) { window.location.reload(); return; }
                if (note) { note.remove(); }
                var acc = document.querySelector('.ace-retention-aside .ace-retention-acc-count');
                var left = document.querySelectorAll('.ace-retention-note').length;
                if (acc) { acc.textContent = left; }
            })
            .catch(function () { btn.disabled = false; });
    });
})();
