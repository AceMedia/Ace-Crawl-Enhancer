(function () {
    'use strict';
    const NS = 'http://www.w3.org/2000/svg';
    document.querySelectorAll('.ace-trk-chart').forEach(function (root, chartIndex) {
        const source = root.querySelector('script[type="application/json"]');
        if (!source) return;
        let data;
        try { data = JSON.parse(source.textContent); } catch (_) { return; }
        const days = data.days || [];
        if (!days.length) return;
        const stage = root.querySelector('.ace-trk-chart-stage');
        const controls = root.querySelector('.ace-trk-chart-controls');
        const tooltip = root.querySelector('.ace-trk-tooltip');
        const number = new Intl.NumberFormat(undefined, {maximumFractionDigits: 0});
        let money;
        try { money = new Intl.NumberFormat(undefined, {style: 'currency', currency: data.currency || 'GBP'}); }
        catch (_) { money = new Intl.NumberFormat(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
        const colours = {ga: '#2271b1', jp: '#c45116', orders: '#137333', value: '#7c3aad', clarity_sessions: '#007c83', clarity_bots: '#65717b', clarity_users: '#7250a1', clarity_dead: '#b32d59', clarity_rage: '#934200', clarity_quickback: '#536e00', clarity_errors: '#3653a5', clarity_error_clicks: '#9b387f'};
        let svg, cursor, lines = [], stacks = {}, x, y, moneyY, selected = days.length - 1;
        const W = 1100, H = 340, left = 62, right = 108, top = 28, bottom = 46;
        const width = W - left - right, height = H - top - bottom;
        function element(tag, attrs, parent, text) {
            const node = document.createElementNS(NS, tag);
            Object.entries(attrs || {}).forEach(([key, value]) => node.setAttribute(key, value));
            if (text !== undefined) node.textContent = text;
            if (parent) parent.appendChild(node);
            return node;
        }
        function checked(name) { return !!root.querySelector('[name="' + name + '"]:checked'); }
        function valueFor(day, key) {
            const value = day[key];
            return value === null || value === undefined || !Number.isFinite(Number(value)) ? null : Number(value);
        }
        function domain(values, integer) {
            const min = Math.min(0, ...values), max = Math.max(1, ...values);
            const raw = (max - min) / 5, power = Math.pow(10, Math.floor(Math.log10(raw)));
            const step = Math.max(integer ? 1 : 0, [1, 2, 5, 10].find(n => n * power >= raw) * power);
            return {min: Math.floor(min / step) * step, max: Math.ceil(max / step) * step, step};
        }
        function series() {
            const views = root.querySelector('[name="traffic_metric"]').value === 'views';
            const result = [];
            if (checked('ga')) result.push({id: 'ga', key: views ? 'ga_views' : 'ga_users', label: views ? 'GA4 page views' : 'GA4 active users'});
            if (checked('jp')) result.push({id: 'jp', key: views ? 'jp_views' : 'jp_visitors', label: views ? 'Jetpack views' : 'Jetpack visitors'});
            if (checked('orders')) result.push({id: 'orders', key: 'wc_orders', label: 'Orders'});
            if (checked('value')) result.push({id: 'value', key: 'wc_value', label: 'Order value', money: true});
            root.querySelectorAll('input[data-clarity-label]:checked').forEach(input => {
                result.push({id: input.name, key: input.name, label: 'Clarity ' + input.dataset.clarityLabel.toLowerCase(), snapshot: true});
            });
            root.querySelectorAll('input[data-google-label]:checked').forEach(input => {
                result.push({id: input.name, key: input.name, label: input.dataset.googleLabel, area: !!input.dataset.area});
            });
            return result;
        }
        function show(index) {
            selected = Math.max(0, Math.min(days.length - 1, index));
            const day = days[selected];
            cursor.replaceChildren();
            element('line', {x1: x(selected), x2: x(selected), y1: top, y2: H - bottom, stroke: '#50575e', 'stroke-dasharray': '4 3'}, cursor);
            tooltip.replaceChildren();
            const title = document.createElement('strong');
            title.textContent = new Date(day.date + 'T12:00:00').toLocaleDateString(undefined, {weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'});
            tooltip.appendChild(title);
            lines.forEach(function (line) {
                const value = valueFor(day, line.key);
                const row = document.createElement('div');
                const label = document.createElement('span'); label.textContent = line.label; label.style.color = colours[line.id];
                const amount = document.createElement('b'); amount.textContent = value === null ? 'Unavailable' : (line.money ? money : number).format(value);
                row.append(label, amount); tooltip.appendChild(row);
                if (value !== null && (!stacks[line.id] || stacks[line.id][selected])) element('circle', {cx: x(selected), cy: line.area && stacks[line.id] ? y(stacks[line.id][selected].top) : (line.money ? moneyY : y)(value), r: 4, fill: colours[line.id], stroke: '#fff', 'stroke-width': 2}, cursor);
            });
            if (lines.some(line => line.snapshot) && day.clarity_window) {
                const note = document.createElement('small'); note.textContent = 'Clarity rolling 24h: ' + day.clarity_window; tooltip.appendChild(note);
            }
            if (day.date === data.today || data.gaps.includes(day.date)) {
                const note = document.createElement('small');
                note.textContent = day.date === data.today ? 'Today is incomplete; reporting may be delayed.' : 'Possible Analytics gap.';
                tooltip.appendChild(note);
            }
            if (!lines.length) { const note = document.createElement('small'); note.textContent = 'Select a series to plot.'; tooltip.appendChild(note); }
            tooltip.hidden = false;
            const px = x(selected) / W * stage.clientWidth;
            tooltip.style.left = Math.max(0, Math.min(stage.clientWidth - tooltip.offsetWidth, px + 14)) + 'px';
            tooltip.style.top = '10px';
        }
        function render() {
            lines = series();
            updateGuides();
            help.hidden = true;
            const counts = [], values = [];
            lines.forEach(line => days.forEach(day => { const v = valueFor(day, line.key); if (v !== null) (line.money ? values : counts).push(v); }));
            stacks = {};
            const areas = root.querySelector('[name=source_style]')?.value === 'area' ? lines.filter(line => line.area) : [];
            const totals = days.map(() => 0);
            areas.forEach(line => {
                stacks[line.id] = days.map((day, i) => {
                    if (areas.some(other => valueFor(day, other.key) === null)) return null;
                    const base = totals[i]; totals[i] += valueFor(day, line.key);
                    return {base, top: totals[i]};
                });
            });
            if (areas.length) counts.push(...totals);
            const countDomain = domain(counts, true), valueDomain = domain(values, false);
            x = i => left + (days.length > 1 ? i / (days.length - 1) : 0.5) * width;
            const scale = d => v => top + (1 - (v - d.min) / (d.max - d.min)) * height;
            y = scale(countDomain); moneyY = scale(valueDomain);
            svg = element('svg', {viewBox: '0 0 ' + W + ' ' + H, tabindex: 0, role: 'img', 'aria-label': 'Daily tracking chart. Use Left and Right arrow keys to inspect dates.'});
            const cell = width / Math.max(1, days.length - 1);
            days.forEach((day, i) => { if (data.gaps.includes(day.date)) element('rect', {x: Math.max(left, x(i) - cell / 2), y: top, width: Math.min(cell, W - right - Math.max(left, x(i) - cell / 2)), height, fill: '#fde8e6'}, svg); });
            const hasCounts = lines.some(line => !line.money);
            for (let v = countDomain.min; hasCounts && v <= countDomain.max + countDomain.step / 2; v += countDomain.step) {
                element('line', {x1: left, x2: W - right, y1: y(v), y2: y(v), stroke: '#e2e4e7'}, svg);
                element('text', {x: left - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'ace-trk-axis'}, svg, number.format(v));
            }
            if (hasCounts) element('text', {x: left, y: 15, class: 'ace-trk-axis'}, svg, lines.filter(line => !line.money).every(line => line.area) ? 'Sessions' : 'Count');
            if (lines.some(line => line.money)) {
                element('text', {x: W - right, y: 15, class: 'ace-trk-axis'}, svg, data.currency + ' · right axis');
                for (let v = valueDomain.min; v <= valueDomain.max + valueDomain.step / 2; v += valueDomain.step) element('text', {x: W - right + 8, y: moneyY(v) + 4, class: 'ace-trk-axis'}, svg, money.format(v));
            }
            days.forEach((day, i) => {
                const stride = Math.max(1, Math.ceil(days.length / 8));
                if (i === days.length - 1 || (i % stride === 0 && days.length - 1 - i >= stride * 0.75)) element('text', {x: x(i), y: H - 20, 'text-anchor': i === days.length - 1 ? 'end' : 'middle', class: 'ace-trk-axis'}, svg, new Date(day.date + 'T12:00:00').toLocaleDateString(undefined, {day: 'numeric', month: 'short'}));
            });
            areas.forEach(line => {
                let segment = [];
                function flushArea() {
                    if (segment.length > 1) {
                        const topPoints = segment.map(p => x(p.i) + ',' + y(p.top));
                        const basePoints = segment.slice().reverse().map(p => x(p.i) + ',' + y(p.base));
                        element('polygon', {points: topPoints.concat(basePoints).join(' '), fill: colours[line.id], 'fill-opacity': 0.45, 'data-series': line.id, 'data-area': 'true'}, svg);
                        element('polyline', {points: topPoints.join(' '), fill: 'none', stroke: colours[line.id], 'stroke-width': 1.4}, svg);
                    } else if (segment.length) element('circle', {cx: x(segment[0].i), cy: y(segment[0].top), r: 3, fill: colours[line.id], 'data-series': line.id}, svg);
                    segment = [];
                }
                stacks[line.id].forEach((point, i) => { if (point === null) flushArea(); else segment.push({...point, i}); });
                flushArea();
            });
            lines.forEach(function (line) {
                if (stacks[line.id]) return;
                let segment = [];
                function flush() {
                    if (segment.length > 1) element('polyline', {points: segment.join(' '), fill: 'none', stroke: colours[line.id], 'stroke-width': 2.4, 'stroke-linejoin': 'round', 'stroke-dasharray': line.snapshot ? '6 4' : 'none', 'data-series': line.id}, svg);
                    else if (segment.length) { const point = segment[0].split(','); element('circle', {cx: point[0], cy: point[1], r: 3, fill: colours[line.id], 'data-series': line.id}, svg); }
                    segment = [];
                }
                days.forEach((day, i) => { const v = valueFor(day, line.key); if (v === null) flush(); else segment.push(x(i) + ',' + (line.money ? moneyY : y)(v)); });
                flush();
            });
            cursor = element('g', {'pointer-events': 'none'}, svg);
            const old = stage.querySelector('svg'); if (old) old.replaceWith(svg); else stage.prepend(svg);
            svg.addEventListener('pointermove', function (event) {
                const rect = svg.getBoundingClientRect();
                show(Math.round(((event.clientX - rect.left) / rect.width * W - left) / width * (days.length - 1)));
            });
            svg.addEventListener('pointerleave', function () { if (document.activeElement !== svg) { tooltip.hidden = true; cursor.replaceChildren(); } });
            svg.addEventListener('focus', () => show(selected));
            svg.addEventListener('keydown', function (event) {
                const keys = {ArrowLeft: selected - 1, ArrowRight: selected + 1, Home: 0, End: days.length - 1};
                if (Object.prototype.hasOwnProperty.call(keys, event.key)) { event.preventDefault(); show(keys[event.key]); }
            });
            tooltip.hidden = true;
        }
        // Move the actual checkbox, preserving one control per series and its checked state.
        const inputs = Array.from(controls.querySelectorAll('input[type="checkbox"]'));
        const active = document.createElement('div'); active.className = 'ace-trk-active-series';
        active.setAttribute('role', 'group'); active.setAttribute('aria-label', 'Active chart series');
        const empty = document.createElement('span'); empty.className = 'ace-trk-no-series'; empty.textContent = 'No series selected';
        const picker = document.createElement('details'); picker.className = 'ace-trk-series-picker';
        const summary = document.createElement('summary');
        const menu = document.createElement('div'); menu.className = 'ace-trk-series-menu';
        menu.setAttribute('role', 'group'); menu.setAttribute('aria-label', 'Available chart series');
        const menuEmpty = document.createElement('p'); menuEmpty.textContent = 'All available series are shown.';
        const groups = {};
        const groupNames = ['Traffic', 'Sales', 'Clarity · rolling 24h', 'Traffic sources', 'Engagement', 'Advertising', 'Google Search'];
        groupNames.forEach(name => {
            const group = document.createElement('fieldset');
            const legend = document.createElement('legend'); legend.textContent = name;
            group.appendChild(legend); menu.appendChild(group); groups[name] = group;
        });
        menu.appendChild(menuEmpty); picker.append(summary, menu);
        controls.prepend(active); controls.append(picker);
        const preferenceKey = 'ace-tracking-series:' + (data.preferences || location.pathname);
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(preferenceKey)); } catch (_) { /* Storage may be blocked. */ }
        if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
            inputs.forEach(input => { if (typeof saved[input.name] === 'boolean') input.checked = saved[input.name]; });
        }
        const palette = ['#4776a8', '#2e8669', '#a55a2a', '#8152a0', '#b33d63', '#627824', '#25818a', '#77675c', '#4657a0', '#965e00', '#965b91', '#5b707b', '#936a4c', '#426da6', '#748d36', '#ab4769', '#406d69', '#735587', '#7d7136', '#427889'];
        inputs.filter(input => input.dataset.googleLabel).forEach((input, i) => { colours[input.name] = palette[i % palette.length]; });
        const help = document.createElement('div'); help.className = 'ace-trk-series-help';
        help.id = 'ace-trk-series-help-' + chartIndex; help.setAttribute('role', 'tooltip'); help.hidden = true; root.appendChild(help);
        const descriptions = {
            ga: 'GA4 active users or page views, chosen using the Traffic selector. Users and sessions are different measures.',
            jp: 'Jetpack visitors or views, chosen using the Traffic selector. This is independent of Google Analytics.',
            orders: 'WooCommerce orders in processing, completed or refunded status, grouped by creation date in the site timezone.',
            value: 'WooCommerce order totals less refunds, including tax and delivery. Refunds reduce the original order day. Uses the right currency axis.',
            ga_engaged: 'GA4 sessions lasting more than 10 seconds, containing a key event, or containing at least two page or screen views.',
            ga_key_events: 'Events marked as key events in GA4. These can include leads or purchases, depending on the property setup.',
            ads_clicks: 'Actual advertising clicks reported through linked integrations or imported data in GA4. Not a count of paid site visits.',
            ads_impressions: 'Advertising impressions reported through linked integrations or imported data in GA4.',
            search_clicks: 'Clicks from Google organic web-search results. Final Search Console data, using Pacific dates; recent days can be delayed.',
            search_impressions: 'Appearances in Google organic web-search results. Final Search Console data, using Pacific dates.',
            source_ai: 'Sessions attributed to Google’s AI Assistant channel or a recognised AI referral host. AI visits without identifiable sources cannot be separated.'
        };
        function describe(input) {
            if (input.dataset.clarityLabel) return input.dataset.description + ' Each point is a saved rolling 24-hour snapshot, not a calendar-day total.';
            return descriptions[input.name] || 'GA4 sessions in this traffic-source channel. A session may include multiple page views. Filled source bands stack to the combined session total.';
        }
        function showHelp(input) {
            help.replaceChildren();
            const title = document.createElement('strong'); title.textContent = input.closest('label').querySelector('.ace-trk-series-label').textContent;
            const text = document.createElement('span'); text.textContent = describe(input);
            help.append(title, text); help.hidden = false;
            const rect = input.closest('label').getBoundingClientRect();
            const left = Math.max(10, Math.min(window.innerWidth - help.offsetWidth - 10, rect.left));
            let top = rect.bottom + 7;
            if (top + help.offsetHeight > window.innerHeight - 10) top = Math.max(10, rect.top - help.offsetHeight - 7);
            help.style.left = left + 'px'; help.style.top = top + 'px';
        }
        inputs.forEach(input => {
            const label = input.closest('label'); label.classList.add('ace-trk-series-chip');
            const text = document.createElement('span'); text.className = 'ace-trk-series-label';
            Array.from(label.childNodes).filter(node => node.nodeType === Node.TEXT_NODE).forEach(node => text.appendChild(node));
            label.appendChild(text); input.setAttribute('aria-describedby', help.id);
            label.addEventListener('pointerenter', () => showHelp(input));
            label.addEventListener('pointerleave', () => { help.hidden = true; });
            input.addEventListener('focus', () => showHelp(input));
            input.addEventListener('blur', () => { help.hidden = true; });
            input.addEventListener('keydown', event => { if (event.key === 'Escape') help.hidden = true; });
            label.style.setProperty('--series-colour', colours[input.name]);
            if (input.dataset.clarityLabel) label.classList.add('ace-trk-series-snapshot');
        });
        function arrange(changed) {
            const wasInMenu = changed && menu.contains(changed);
            const hadFocus = changed && document.activeElement === changed;
            inputs.forEach(input => {
                const group = input.dataset.group || (input.dataset.clarityLabel ? 'Clarity · rolling 24h' : (['orders', 'value'].includes(input.name) ? 'Sales' : 'Traffic'));
                const target = input.checked ? active : groups[group];
                const label = input.closest('label');
                if (label.parentNode !== target) target.appendChild(label);
            });
            const count = inputs.filter(input => !input.checked).length;
            summary.textContent = 'Add series' + (count ? ' (' + count + ')' : '');
            Object.values(groups).forEach(group => { group.hidden = !group.querySelector('input'); });
            menuEmpty.hidden = count !== 0;
            empty.hidden = inputs.some(input => input.checked); active.appendChild(empty);
            if (hadFocus) {
                if (wasInMenu && changed.checked) (menu.querySelector('input') || summary).focus();
                else if (!changed.checked && !picker.open) summary.focus();
            }
        }
        function updateGuides() {
            const hasSource = lines.some(line => line.area);
            const show = {
                ga: checked('ga') || lines.some(line => ['Traffic sources', 'Engagement', 'Advertising'].includes(root.querySelector('input[name="' + line.id + '"]')?.dataset.group)),
                sources: hasSource, ai: checked('source_ai'), advertising: checked('ads_clicks') || checked('ads_impressions'),
                search: checked('search_clicks') || checked('search_impressions'), clarity: lines.some(line => line.snapshot),
                commerce: checked('orders') || checked('value'), value: checked('value')
            };
            root.querySelectorAll('[data-guide]').forEach(note => { note.hidden = !show[note.dataset.guide]; });
            const sourceStyle = root.querySelector('[name=source_style]');
            if (sourceStyle) sourceStyle.closest('label').hidden = !hasSource;
            root.querySelector('.ace-trk-traffic-control').hidden = !checked('ga') && !checked('jp');
            const explanation = root.querySelector('.ace-trk-source-explanation');
            if (explanation) explanation.textContent = sourceStyle?.value === 'area' ? 'The coloured bands stack session counts. Their combined height is the total for the selected sources, not unique visitors.' : 'Each line counts sessions from that source. Values are separate, not stacked, and are not unique visitors.';
            const mix = root.querySelector('.ace-trk-show-sources');
            if (mix) {
                const all = inputs.filter(input => input.dataset.area).every(input => input.checked);
                mix.textContent = all ? 'Traffic mix shown' : 'Show traffic mix'; mix.setAttribute('aria-pressed', String(all));
            }
            root.querySelector('.ace-trk-chart-hint').textContent = lines.length ? 'Hover or touch to inspect a date. Use the arrow keys when the chart is focused. Missing data stays blank.' : 'Choose a series from Add series to start plotting.';
        }
        window.addEventListener('scroll', () => { help.hidden = true; }, true);
        window.addEventListener('resize', () => { help.hidden = true; });
        controls.hidden = false;
        controls.addEventListener('change', event => {
            const changed = inputs.includes(event.target) ? event.target : null;
            arrange(changed);
            if (changed) {
                // Store booleans so a temporarily unavailable series keeps its preference.
                const preferences = saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
                inputs.forEach(input => { preferences[input.name] = input.checked; });
                try { localStorage.setItem(preferenceKey, JSON.stringify(preferences)); saved = preferences; } catch (_) {}
            }
            render();
        });
        root.querySelector('.ace-trk-show-sources')?.addEventListener('click', () => {
            inputs.filter(input => input.dataset.area).forEach(input => { input.checked = true; });
            const first = inputs.find(input => input.dataset.area);
            if (first) first.dispatchEvent(new Event('change', {bubbles: true}));
        });
        picker.addEventListener('keydown', event => { if (event.key === 'Escape') { picker.open = false; summary.focus(); } });
        document.addEventListener('pointerdown', event => { if (!picker.contains(event.target)) picker.open = false; });
        arrange();
        render();
    });
})();
