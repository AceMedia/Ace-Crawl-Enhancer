/**
 * Timing rules manager: a full-screen dialog with categories and tags on the left and the selected
 * one's evidence, rule and choices on the right. "Save and close" posts every choice through
 * admin-ajax and updates the settings page; "Cancel" discards everything changed since opening.
 */
(function () {
    'use strict';
    var modal = document.getElementById('ace-timing-modal');
    var open = document.getElementById('ace-timing-open');
    var dataEl = document.getElementById('ace-timing-data');
    if (!modal || !open || !dataEl) { return; }

    var cfg = window.aceSeoTimingRules || {};
    var items = JSON.parse(dataEl.textContent || '[]');
    var byKey = {};
    items.forEach(function (it) { byKey[it.key] = it; });
    var listEl = document.getElementById('ace-timing-list');
    var detailEl = document.getElementById('ace-timing-detail');
    var filter = document.getElementById('ace-timing-filter');
    var type = document.getElementById('ace-timing-type');
    var count = document.getElementById('ace-timing-count');
    var status = modal.querySelector('.ace-modal-status');
    var changesEl = document.getElementById('ace-timing-changes');
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var SHORT = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];

    // Working state per key: { mode, days, start, end, ignored }. Saved state is kept to detect changes.
    var state = {}, saved = {}, selected = null, samples = {};

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmt(n) { return Number(n || 0).toLocaleString(); }
    function md(s) { var m = /^(\d{2})-(\d{2})$/.exec(s || ''); return m ? parseInt(m[2], 10) + ' ' + MONTHS[parseInt(m[1], 10) - 1] : '?'; }
    function longDate(iso) { var d = new Date(iso + 'T00:00:00Z'); return isNaN(d) ? iso : d.getUTCDate() + ' ' + MONTHS[d.getUTCMonth()] + ' ' + d.getUTCFullYear(); }
    function addDays(iso, n) { var d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }

    function parseRule(text) {
        var m;
        if (!text) { return { mode: 'none' }; }
        if (text === 'evergreen') { return { mode: 'evergreen' }; }
        if ((m = /^event (\d+)$/.exec(text))) { return { mode: 'event', days: parseInt(m[1], 10) }; }
        if ((m = /^season (\d{2}-\d{2}) (\d{2}-\d{2})$/.exec(text))) { return { mode: 'season', start: m[1], end: m[2] }; }
        return { mode: 'none' };
    }
    function ruleText(st) {
        if (st.mode === 'evergreen') { return 'evergreen'; }
        if (st.mode === 'event') { return 'event ' + Math.max(1, parseInt(st.days, 10) || 1); }
        if (st.mode === 'season') { return 'season ' + (st.start || '') + ' ' + (st.end || ''); }
        return '';
    }
    function ruleName(text) {
        var r = parseRule(text);
        if (r.mode === 'evergreen') { return 'Evergreen'; }
        if (r.mode === 'event') { return 'Day-of-event, ' + r.days + ' day' + (r.days === 1 ? '' : 's'); }
        if (r.mode === 'season') { return 'Yearly season, ' + md(r.start) + ' to ' + md(r.end); }
        return 'No rule';
    }
    function meaning(text) {
        var r = parseRule(text);
        if (r.mode === 'evergreen') { return 'Always relevant. Each post is judged on whatever period the report looks at, with no seasonal allowance.'; }
        if (r.mode === 'event') { return 'Each post matters for ' + r.days + ' day' + (r.days === 1 ? '' : 's') + ' from the day it is published. After that the event is over, so the report judges it on how it has been read since. It is never held back just for being old.'; }
        if (r.mode === 'season') { return 'Posts matter every year from ' + md(r.start) + ' to ' + md(r.end) + '. The report only judges them on a period that contains a whole season; outside it they are held rather than called quiet.'; }
        return 'No timing rule. The report judges these posts on its normal traffic period, holding only those whose own dates fall outside it.';
    }
    function example(text, post) {
        var r = parseRule(text);
        if (!post) { return ''; }
        var t = '<strong>' + esc(post.title) + '</strong>, published ' + esc(longDate(post.date));
        if (r.mode === 'event') {
            var end = addDays(post.date, r.days - 1);
            return t + ', matters ' + (r.days === 1 ? 'on ' + esc(longDate(post.date)) : 'from ' + esc(longDate(post.date)) + ' to ' + esc(longDate(end))) + '. From the next day it is judged on how it has been read since.';
        }
        if (r.mode === 'season') { var y = post.date.slice(0, 4); return t + ', belongs to the ' + md(r.start) + ' to ' + md(r.end) + ' season (in ' + y + ', ' + longDate(y + '-' + r.start) + ' to ' + longDate(y + '-' + r.end) + '). The report only judges it on a period that contains a whole season, so a quiet summer never counts against it.'; }
        if (r.mode === 'evergreen') { return t + ', is judged on the report\'s normal period, whenever that is.'; }
        return t + ', is judged on the report\'s normal period.';
    }
    function cadenceText(c) {
        if (c === null || c === undefined) { return 'not enough posts to tell'; }
        if (c < 0.75) { return 'more than once a day'; }
        if (c < 1.5) { return 'about once a day'; }
        if (c < 10) { return 'about every ' + (Math.round(c * 10) / 10) + ' days'; }
        return 'about every ' + Math.round(c) + ' days';
    }
    function statusOf(key) {
        var st = state[key];
        if (st.ignored) { return { cls: 'is-ignored', text: 'Ignored' }; }
        var t = ruleText(st);
        if (!t) { return { cls: 'is-open', text: 'No rule' }; }
        if (t === byKey[key].rule) { return { cls: 'is-suggested', text: 'Suggestion in use' }; }
        return { cls: 'is-custom', text: 'Your rule' };
    }
    function changedKeys() {
        return Object.keys(state).filter(function (k) {
            var a = state[k], b = saved[k];
            return ruleText(a) !== ruleText(b) || !!a.ignored !== !!b.ignored;
        });
    }
    function paintChanges() {
        var n = changedKeys().length;
        changesEl.textContent = n ? n + ' change' + (n === 1 ? '' : 's') + ' not saved yet' : '';
    }

    function resetState() {
        items.forEach(function (it) {
            var cur = parseRule(it.current);
            var st = { mode: cur.mode, days: cur.days || (parseRule(it.rule).days || 3), start: cur.start || (it.season ? it.season.start : ''), end: cur.end || (it.season ? it.season.end : ''), ignored: !!it.ignored };
            state[it.key] = st;
            saved[it.key] = JSON.parse(JSON.stringify(st));
        });
    }

    function visible(it) {
        var q = (filter.value || '').toLowerCase().trim();
        if (q && (it.label + ' ' + it.key).toLowerCase().indexOf(q) === -1) { return false; }
        var t = type.value, st = state[it.key];
        if (!t) { return true; }
        if (t === 'chosen') { return !!ruleText(st); }
        if (t === 'open') { return !ruleText(st) && !st.ignored; }
        if (t === 'ignored') { return !!st.ignored; }
        return it.type === t;
    }

    function renderList() {
        var html = '', shown = 0;
        items.forEach(function (it) {
            if (!visible(it)) { return; }
            shown++;
            var s = statusOf(it.key);
            html += '<li><button type="button" role="option" class="ace-timing-item' + (it.key === selected ? ' is-selected' : '') + '" data-key="' + esc(it.key) + '" aria-selected="' + (it.key === selected) + '">' +
                '<span class="ace-timing-item-name">' + esc(it.label) + '</span>' +
                '<span class="ace-timing-item-meta">' + kindOf(it.key) + ' · ' + fmt(it.posts) + ' posts · ' + (it.type === 'mixed' ? 'no clear shape' : esc(it.ruleName)) + '</span>' +
                '<span class="ace-timing-badge ' + s.cls + '">' + esc(s.text) + '</span></button></li>';
        });
        var empty = 'Nothing matches.';
        if (!html && (type.value === 'event' || type.value === 'evergreen') && modal.dataset.readershipKnown === '0') {
            empty = 'No ' + (type.value === 'event' ? 'day-of-event' : 'evergreen') + ' suggestions on this site: there is not enough readership data to tell which old posts are still read (' + modal.dataset.readershipShare + '% count as read). You can still pick any category and choose that rule yourself.';
        }
        listEl.innerHTML = html || '<li class="ace-timing-empty">' + esc(empty) + '</li>';
        count.textContent = shown + ' of ' + items.length;
    }

    function kindOf(key) {
        var tax = key.split(':')[0];
        return tax === 'category' ? 'Category' : (tax === 'post_tag' ? 'Tag' : tax.replace(/[_-]/g, ' '));
    }
    function monthChart(it) {
        var max = Math.max.apply(null, (it.months || []).concat([1]));
        // Highlight the season chosen, or else the suggested one, so the chart always shows the stretch in question.
        var r = parseRule(ruleText(state[it.key]));
        if (r.mode !== 'season' && it.season) { r = { mode: 'season', start: it.season.start, end: it.season.end }; }
        var inSeason = function (m) {
            if (r.mode !== 'season' || !r.start || !r.end) { return false; }
            var a = parseInt(r.start.slice(0, 2), 10), b = parseInt(r.end.slice(0, 2), 10);
            return a <= b ? (m >= a && m <= b) : (m >= a || m <= b);
        };
        var bars = (it.months || []).map(function (n, i) {
            var h = Math.round(100 * n / max);
            return '<span class="ace-month' + (inSeason(i + 1) ? ' is-season' : '') + '" title="' + MONTHS[i] + ': ' + fmt(n) + ' posts"><i style="height:' + Math.max(2, h) + '%"></i><b>' + SHORT[i] + '</b></span>';
        }).join('');
        return '<div class="ace-month-chart" aria-label="Posts published by month">' + bars + '</div>';
    }

    function isSuggestion(key) {
        var st = state[key], it = byKey[key];
        return it.type !== 'mixed' && st.mode !== 'none' && ruleText(st) === it.rule && st.picked !== 'custom';
    }
    function choice(key, mode, label, body) {
        var st = state[key], sugg = isSuggestion(key);
        var checked = mode === 'suggested' ? sugg : (!sugg && st.mode === mode);
        return '<label class="ace-choice' + (checked ? ' is-checked' : '') + '"><input type="radio" name="ace-timing-choice" value="' + mode + '"' + (checked ? ' checked' : '') + '> <span class="ace-choice-label">' + label + '</span>' + (body || '') + '</label>';
    }

    function renderDetail() {
        var it = byKey[selected];
        if (!it) { detailEl.innerHTML = '<p class="ace-timing-placeholder">Choose a category or tag on the left.</p>'; return; }
        var st = state[it.key];
        var current = ruleText(st);
        var post = (samples[it.key] || [])[0];
        var tiles = [
            ['Posts assessed', fmt(it.posts), it.firstYear ? 'published ' + it.firstYear + (it.lastYear && it.lastYear !== it.firstYear ? ' to ' + it.lastYear : '') : ''],
            ['Still read at least monthly', it.persistence + '%', 'of its older posts, years later'],
            ['Read at all in the period', it.readShare + '%', 'at least one visit or search click'],
            ['Publishes', cadenceText(it.cadence), it.years ? 'across ' + it.years + ' year' + (it.years === 1 ? '' : 's') : '']
        ];
        if (it.season) { tiles.push(['Busiest stretch', md(it.season.start) + ' to ' + md(it.season.end), it.season.share + '% of its posts']); }
        var s0 = statusOf(it.key);
        var html = '<div class="ace-timing-detail-head"><div><h3>' + esc(it.label) + ' <small class="ace-timing-kind-label">' + kindOf(it.key) + '</small></h3><code>' + esc(it.key) + '</code></div>' +
            '<span class="ace-timing-badge ' + s0.cls + '">' + esc(s0.text) + '</span></div>';

        // Row 1: the evidence beside the month chart.
        html += '<div class="ace-detail-row">';
        html += '<section class="ace-panel"><h4>What the data shows</h4><div class="ace-tiles">' + tiles.map(function (t) {
            return '<div class="ace-tile"><span class="ace-tile-label">' + esc(t[0]) + '</span><span class="ace-tile-value">' + esc(t[1]) + '</span><span class="ace-tile-sub">' + esc(t[2]) + '</span></div>';
        }).join('') + '</div></section>';
        html += '<section class="ace-panel"><h4>When its posts are published</h4><p class="ace-panel-sub">All years together' + (it.season ? '; blue is the season' : '') + '.</p>' + monthChart(it) + '</section>';
        html += '</div>';

        // Row 2: the suggestion beside your choice and what it does.
        html += '<div class="ace-detail-row">';
        if (it.type !== 'mixed') {
            html += '<section class="ace-panel ace-suggestion-card"><h4>Suggested rule</h4><p><span class="ace-timing-type ace-timing-type-' + esc(it.type) + '">' + esc(it.ruleName) + '</span> <small>' + it.confidence + '% confidence</small></p>' +
                '<p>' + esc(meaning(it.rule)) + '</p>' + (post ? '<p class="ace-example"><strong>Example:</strong> ' + example(it.rule, post) + '</p>' : '') +
                '<details class="ace-why"><summary>Why the data suggests it</summary><p>' + esc(it.why) + '</p></details></section>';
        } else {
            html += '<section class="ace-panel ace-suggestion-card is-mixed"><h4>Suggested rule</h4><p><span class="ace-timing-type">No clear shape</span></p><p>' + esc(it.why) + '</p></section>';
        }
        html += '<section class="ace-panel"><h4>Your choice</h4><div class="ace-choices">';
        if (it.type !== 'mixed') { html += choice(it.key, 'suggested', 'Use the suggestion', ' <span class="ace-choice-input">' + esc(it.ruleName) + '</span>'); }
        html += choice(it.key, 'event', 'Day-of-event', ' <span class="ace-choice-input">for <input type="number" min="1" max="366" class="small-text" data-field="days" value="' + esc(st.days) + '"> days after publishing</span>');
        html += choice(it.key, 'season', 'Yearly season', ' <span class="ace-choice-input">from <input type="text" class="ace-md" data-field="start" placeholder="MM-DD" value="' + esc(st.start) + '"> to <input type="text" class="ace-md" data-field="end" placeholder="MM-DD" value="' + esc(st.end) + '"></span>');
        html += choice(it.key, 'evergreen', 'Evergreen', ' <span class="ace-choice-input">always relevant</span>');
        html += choice(it.key, 'none', 'No rule');
        html += '</div><div class="ace-effect"><p class="ace-effect-text">' + esc(meaning(current)) + '</p>' +
            '<p class="ace-example" id="ace-timing-example">' + (post ? '<strong>Example:</strong> ' + example(current, post) : (samples[it.key] ? '' : 'Loading an example…')) + '</p></div>' +
            '<label class="ace-ignore"><input type="checkbox" data-field="ignored"' + (st.ignored ? ' checked' : '') + '> Ignore this suggestion <span>(hides it from "no rule yet"; sets no rule)</span></label></section>';
        html += '</div>';

        // Row 3: recent posts, full width.
        html += '<section class="ace-panel"><h4>Latest posts in this ' + (it.key.indexOf('post_tag:') === 0 ? 'tag' : 'category') + '</h4><table class="ace-samples"><tbody id="ace-timing-samples">' + samplesHtml(it.key) + '</tbody></table></section>';
        detailEl.innerHTML = html;
        if (!samples[it.key]) { loadSamples(it.key); }
    }

    function samplesHtml(key) {
        var s = samples[key];
        if (!s) { return '<tr><td class="description">Loading…</td></tr>'; }
        if (!s.length) { return '<tr><td class="description">No assessed posts found.</td></tr>'; }
        return s.map(function (p) { return '<tr><td><a href="' + esc(p.edit) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a></td><td class="ace-samples-date">' + esc(longDate(p.date)) + '</td><td class="ace-samples-group">' + esc(p.group) + '</td></tr>'; }).join('');
    }

    function loadSamples(key) {
        var data = new FormData();
        data.append('action', 'ace_seo_timing_detail');
        data.append('nonce', cfg.nonce || '');
        data.append('key', key);
        fetch(cfg.ajaxUrl || window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                samples[key] = json && json.success ? (json.data.posts || []) : [];
                if (selected === key) { renderDetail(); }
            })
            .catch(function () { samples[key] = []; if (selected === key) { renderDetail(); } });
    }

    function select(key) {
        selected = key;
        renderList();
        renderDetail();
    }

    listEl.addEventListener('click', function (e) {
        var b = e.target.closest('.ace-timing-item');
        if (b) { select(b.dataset.key); }
    });
    filter.addEventListener('input', renderList);
    type.addEventListener('change', renderList);

    detailEl.addEventListener('change', function (e) {
        var t = e.target, st = state[selected], it = byKey[selected];
        if (!st) { return; }
        if (t.name === 'ace-timing-choice') {
            if (t.value === 'suggested') { var r = parseRule(it.rule); st.mode = r.mode; if (r.days) { st.days = r.days; } if (r.start) { st.start = r.start; st.end = r.end; } st.picked = 'suggested'; }
            else { st.mode = t.value; st.picked = 'custom'; }
            if (st.mode !== 'none') { st.ignored = false; }
        } else if (t.dataset.field === 'ignored') {
            st.ignored = t.checked;
            if (t.checked) { st.mode = 'none'; }
        }
        renderDetail(); renderList(); paintChanges();
    });
    detailEl.addEventListener('input', function (e) {
        var t = e.target, st = state[selected];
        if (!st || !t.dataset.field || t.dataset.field === 'ignored') { return; }
        st[t.dataset.field] = t.value;
        st.mode = t.dataset.field === 'days' ? 'event' : 'season';
        st.picked = 'custom';
        // Keep focus while typing: only refresh the explanation, list and change count.
        var eff = detailEl.querySelector('.ace-effect-text');
        if (eff) { eff.textContent = meaning(ruleText(st)); }
        var ex = document.getElementById('ace-timing-example');
        var post = (samples[selected] || [])[0];
        if (ex && post) { ex.innerHTML = '<strong>Example:</strong> ' + example(ruleText(st), post); }
        detailEl.querySelectorAll('input[name="ace-timing-choice"]').forEach(function (r) { r.checked = r.value === st.mode; r.closest('.ace-choice').classList.toggle('is-checked', r.checked); });
        renderList(); paintChanges();
    });

    function show() {
        resetState();
        filter.value = ''; type.value = '';
        selected = items.length ? items[0].key : null;
        if (typeof modal.showModal === 'function') { modal.showModal(); } else { modal.setAttribute('open', ''); }
        renderList(); renderDetail(); paintChanges();
        status.textContent = '';
    }
    function hide() {
        if (typeof modal.close === 'function' && modal.open) { modal.close(); } else { modal.removeAttribute('open'); }
    }
    function cancel() {
        if (changedKeys().length && !window.confirm('Discard ' + changedKeys().length + ' unsaved change(s)?')) { return; }
        hide();
    }
    open.addEventListener('click', show);
    modal.querySelectorAll('[data-ace-modal-cancel]').forEach(function (b) { b.addEventListener('click', cancel); });
    modal.addEventListener('cancel', function (e) { e.preventDefault(); cancel(); });

    document.getElementById('ace-timing-save').addEventListener('click', function () {
        var btn = this, data = new FormData(), bad = [];
        data.append('action', 'ace_seo_timing_rules');
        data.append('nonce', cfg.nonce || '');
        items.forEach(function (it) {
            var st = state[it.key], text = ruleText(st);
            if (st.mode === 'season' && !/^season \d{2}-\d{2} \d{2}-\d{2}$/.test(text)) { bad.push(it.label); }
            data.append('rule[' + it.key + ']', text);
            if (st.ignored) { data.append('ignore[]', it.key); }
        });
        if (bad.length) { status.textContent = 'Season dates are missing for: ' + bad.join(', ') + ' (use MM-DD).'; return; }
        btn.disabled = true;
        status.textContent = 'Saving…';
        fetch(cfg.ajaxUrl || window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || 'Save failed.'); }
                var d = json.data || {};
                items.forEach(function (it) { it.current = ruleText(state[it.key]); it.ignored = !!state[it.key].ignored; });
                var box = document.getElementById('retention-timing-rules');
                if (box && typeof d.rules === 'string') {
                    var bar = window.aceCrawlEnhancerAdmin && window.aceCrawlEnhancerAdmin.saveBar;
                    var form = box.form, wasDirty = bar && form && bar.dirty && bar.dirty['#' + form.id];
                    box.value = d.rules;
                    box.dispatchEvent(new Event('input', { bubbles: true }));
                    if (bar) { if (wasDirty) { bar.checkForChanges(); } else { bar.captureOriginalFormData(); bar.checkForChanges(); } }
                }
                Object.keys(d.overview || {}).forEach(function (k) {
                    var el = document.querySelector('#ace-timing-overview [data-overview="' + k + '"]');
                    if (el) { el.textContent = Number(d.overview[k]).toLocaleString(); }
                });
                hide();
            })
            .catch(function (err) { status.textContent = err.message || 'Save failed.'; })
            .then(function () { btn.disabled = false; });
    });

    // Plain-English reading of the hand-written rules box, under the box itself.
    var box = document.getElementById('retention-timing-rules');
    var preview = document.getElementById('ace-timing-rules-preview');
    if (box && preview) {
        var labels = {};
        items.forEach(function (it) { labels[it.key] = it.label; });
        var paintPreview = function () {
            var lines = box.value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
            if (!lines.length) { preview.innerHTML = '<li class="description">No rules yet.</li>'; return; }
            preview.innerHTML = lines.map(function (l) {
                var m = /^([a-z0-9_-]+:[a-z0-9_-]+)\s*=\s*(.+)$/i.exec(l);
                if (!m) { return '<li class="is-bad"><code>' + esc(l) + '</code> is not understood. Use <code>taxonomy:slug = evergreen</code>, <code>= event N</code> or <code>= season MM-DD MM-DD</code>.</li>'; }
                var name = ruleName(m[2].trim().replace(/\s+/g, ' '));
                var ok = name !== 'No rule';
                return '<li' + (ok ? '' : ' class="is-bad"') + '><strong>' + esc(labels[m[1].toLowerCase()] || m[1]) + '</strong>: ' + esc(ok ? name : 'not understood') + (ok ? ' <span>' + esc(meaning(m[2].trim().replace(/\s+/g, ' '))) + '</span>' : '') + '</li>';
            }).join('');
        };
        box.addEventListener('input', paintPreview);
        paintPreview();
    }
})();
