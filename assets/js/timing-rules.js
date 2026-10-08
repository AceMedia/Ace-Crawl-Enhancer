/**
 * Timing rules modal: each row picks the suggested rule, a rule of its own, or none; "Save and close"
 * posts the choices through admin-ajax and updates the overview and the hand-written rules box;
 * "Cancel" puts every row back as it was.
 */
(function () {
    'use strict';
    var modal = document.getElementById('ace-timing-modal');
    var open = document.getElementById('ace-timing-open');
    if (!modal || !open) { return; }
    var cfg = window.aceSeoTimingRules || {};
    var rows = Array.prototype.slice.call(modal.querySelectorAll('tbody tr'));
    var status = modal.querySelector('.ace-modal-status');
    var count = document.getElementById('ace-timing-count');
    var filter = document.getElementById('ace-timing-filter');
    var type = document.getElementById('ace-timing-type');
    var snapshot = [];
    var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function mdLabel(md) {
        var m = /^(\d{2})-(\d{2})$/.exec(md || '');
        return m ? (parseInt(m[2], 10) + ' ' + (MONTHS[parseInt(m[1], 10) - 1] || '?')) : '?';
    }
    function ruleText(row) {
        var mode = row.querySelector('.ace-timing-mode').value;
        if (mode === 'suggested') { return row.dataset.suggested || ''; }
        if (mode === 'evergreen') { return 'evergreen'; }
        if (mode === 'event') { return 'event ' + Math.max(1, parseInt(row.querySelector('.ace-timing-days').value, 10) || 1); }
        if (mode === 'season') { return 'season ' + (row.querySelector('.ace-timing-start').value || '') + ' ' + (row.querySelector('.ace-timing-end').value || ''); }
        return '';
    }
    function explain(text) {
        var m;
        if (!text) { return 'No rule: judged on the traffic period alone, holding only posts whose own dates fall outside it.'; }
        if (text === 'evergreen') { return 'Always relevant: judged on any period, with no seasonal allowance.'; }
        if ((m = /^event (\d+)$/.exec(text))) { return 'Day-of-event content: each post matters for ' + m[1] + ' day' + (m[1] === '1' ? '' : 's') + ' from the day it was published; afterwards, how it is read since is a fair judgement, so old posts are never held.'; }
        if ((m = /^season (\d{2}-\d{2}) (\d{2}-\d{2})$/.exec(text))) { return 'A yearly season from ' + mdLabel(m[1]) + ' to ' + mdLabel(m[2]) + ': judged only on a period that contains a whole season; outside it, held rather than called quiet.'; }
        return 'Incomplete: an event needs a number of days; a season needs two dates as MM-DD.';
    }
    function paint(row) {
        var mode = row.querySelector('.ace-timing-mode').value;
        row.querySelector('.ace-timing-custom-event').hidden = mode !== 'event';
        row.querySelector('.ace-timing-custom-season').hidden = mode !== 'season';
        var chosen = row.querySelector('.ace-timing-chosen');
        var text = ruleText(row);
        chosen.textContent = mode === 'suggested' ? '' : explain(text);
        row.dataset.rule = text;
    }
    function remember() {
        snapshot = rows.map(function (r) {
            return { mode: r.querySelector('.ace-timing-mode').value, days: r.querySelector('.ace-timing-days').value, start: r.querySelector('.ace-timing-start').value, end: r.querySelector('.ace-timing-end').value, ignore: (r.querySelector('.ace-timing-ignore') || {}).checked };
        });
    }
    function restore() {
        rows.forEach(function (r, n) {
            var s = snapshot[n]; if (!s) { return; }
            r.querySelector('.ace-timing-mode').value = s.mode;
            r.querySelector('.ace-timing-days').value = s.days;
            r.querySelector('.ace-timing-start').value = s.start;
            r.querySelector('.ace-timing-end').value = s.end;
            var i = r.querySelector('.ace-timing-ignore'); if (i) { i.checked = !!s.ignore; }
            paint(r);
        });
    }
    function applyFilter() {
        var q = (filter.value || '').toLowerCase().trim(), t = type.value, shown = 0;
        rows.forEach(function (r) {
            var ok = (!q || r.dataset.label.indexOf(q) !== -1);
            if (ok && t) {
                if (t === 'rule') { ok = !!ruleText(r); }
                else if (t === 'ignored') { var i = r.querySelector('.ace-timing-ignore'); ok = !!(i && i.checked); }
                else { ok = r.dataset.type === t; }
            }
            r.hidden = !ok;
            if (ok) { shown++; }
        });
        count.textContent = shown + ' of ' + rows.length + ' shown';
    }
    function show() {
        rows.forEach(paint);
        remember();
        if (typeof modal.showModal === 'function') { modal.showModal(); } else { modal.setAttribute('open', ''); }
        applyFilter();
        status.textContent = '';
    }
    function hide() {
        if (typeof modal.close === 'function' && modal.open) { modal.close(); } else { modal.removeAttribute('open'); }
    }
    open.addEventListener('click', show);
    modal.querySelectorAll('[data-ace-modal-cancel]').forEach(function (b) { b.addEventListener('click', function () { restore(); hide(); }); });
    modal.addEventListener('cancel', function (e) { e.preventDefault(); restore(); hide(); });
    filter.addEventListener('input', applyFilter);
    type.addEventListener('change', applyFilter);
    modal.addEventListener('input', function (e) { var r = e.target.closest('tr'); if (r) { paint(r); } });
    modal.addEventListener('change', function (e) {
        var t = e.target, r = t.closest('tr'); if (!r) { return; }
        if (t.classList.contains('ace-timing-ignore') && t.checked) { r.querySelector('.ace-timing-mode').value = 'none'; }
        if (t.classList.contains('ace-timing-mode') && t.value !== 'none') { var i = r.querySelector('.ace-timing-ignore'); if (i) { i.checked = false; } }
        paint(r);
    });

    document.getElementById('ace-timing-save').addEventListener('click', function () {
        var btn = this, data = new FormData(), bad = [];
        data.append('action', 'ace_seo_timing_rules');
        data.append('nonce', cfg.nonce || '');
        rows.forEach(function (r) {
            var text = ruleText(r), mode = r.querySelector('.ace-timing-mode').value;
            if (mode === 'season' && !/^season \d{2}-\d{2} \d{2}-\d{2}$/.test(text)) { bad.push(r.querySelector('th strong').textContent); }
            data.append('rule[' + r.dataset.key + ']', text);
            var i = r.querySelector('.ace-timing-ignore');
            if (i && i.checked) { data.append('ignore[]', r.dataset.key); }
        });
        if (bad.length) { status.textContent = 'Season dates are missing for: ' + bad.join(', ') + ' (use MM-DD).'; return; }
        btn.disabled = true;
        status.textContent = 'Saving…';
        fetch(cfg.ajaxUrl || window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || 'Save failed.'); }
                var d = json.data || {};
                var box = document.getElementById('retention-timing-rules');
                if (box && typeof d.rules === 'string') {
                    var bar = window.aceCrawlEnhancerAdmin && window.aceCrawlEnhancerAdmin.saveBar;
                    var form = box.form, wasDirty = bar && form && bar.dirty && bar.dirty['#' + form.id];
                    box.value = d.rules;
                    if (bar) { if (wasDirty) { bar.checkForChanges(); } else { bar.captureOriginalFormData(); bar.checkForChanges(); } }
                }
                Object.keys(d.overview || {}).forEach(function (k) {
                    var el = document.querySelector('#ace-timing-overview [data-overview="' + k + '"]');
                    if (el) { el.textContent = Number(d.overview[k]).toLocaleString(); }
                });
                remember();
                hide();
            })
            .catch(function (err) { status.textContent = err.message || 'Save failed.'; })
            .then(function () { btn.disabled = false; });
    });
})();
