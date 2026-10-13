(function () {
    'use strict';
    const NS = 'http://www.w3.org/2000/svg';
    document.querySelectorAll('.ace-trk-chart').forEach(function (root) {
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
        const colours = {ga: '#2271b1', jp: '#c45116', orders: '#137333', value: '#7c3aad', clarity: '#007c83'};
        let svg, cursor, lines = [], x, y, moneyY, selected = days.length - 1;
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
            const clarity = root.querySelector('[name="clarity_metric"]');
            if (clarity && clarity.value) result.push({id: 'clarity', key: 'clarity_' + clarity.value, label: 'Clarity ' + clarity.selectedOptions[0].textContent.split(' · ')[0].toLowerCase(), snapshot: true});
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
                if (value !== null) element('circle', {cx: x(selected), cy: (line.money ? moneyY : y)(value), r: 4, fill: colours[line.id], stroke: '#fff', 'stroke-width': 2}, cursor);
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
            const counts = [], values = [];
            lines.forEach(line => days.forEach(day => { const v = valueFor(day, line.key); if (v !== null) (line.money ? values : counts).push(v); }));
            const countDomain = domain(counts, true), valueDomain = domain(values, false);
            x = i => left + (days.length > 1 ? i / (days.length - 1) : 0.5) * width;
            const scale = d => v => top + (1 - (v - d.min) / (d.max - d.min)) * height;
            y = scale(countDomain); moneyY = scale(valueDomain);
            svg = element('svg', {viewBox: '0 0 ' + W + ' ' + H, tabindex: 0, role: 'img', 'aria-label': 'Daily tracking chart. Use Left and Right arrow keys to inspect dates.'});
            const cell = width / Math.max(1, days.length - 1);
            days.forEach((day, i) => { if (data.gaps.includes(day.date)) element('rect', {x: Math.max(left, x(i) - cell / 2), y: top, width: Math.min(cell, W - right - Math.max(left, x(i) - cell / 2)), height, fill: '#fde8e6'}, svg); });
            for (let v = countDomain.min; v <= countDomain.max + countDomain.step / 2; v += countDomain.step) {
                element('line', {x1: left, x2: W - right, y1: y(v), y2: y(v), stroke: '#e2e4e7'}, svg);
                element('text', {x: left - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'ace-trk-axis'}, svg, number.format(v));
            }
            element('text', {x: left, y: 15, class: 'ace-trk-axis'}, svg, 'Count');
            if (lines.some(line => line.money)) {
                element('text', {x: W - right, y: 15, class: 'ace-trk-axis'}, svg, data.currency + ' · right axis');
                for (let v = valueDomain.min; v <= valueDomain.max + valueDomain.step / 2; v += valueDomain.step) element('text', {x: W - right + 8, y: moneyY(v) + 4, class: 'ace-trk-axis'}, svg, money.format(v));
            }
            days.forEach((day, i) => {
                const stride = Math.max(1, Math.ceil(days.length / 8));
                if (i === days.length - 1 || (i % stride === 0 && days.length - 1 - i >= stride * 0.75)) element('text', {x: x(i), y: H - 20, 'text-anchor': i === days.length - 1 ? 'end' : 'middle', class: 'ace-trk-axis'}, svg, new Date(day.date + 'T12:00:00').toLocaleDateString(undefined, {day: 'numeric', month: 'short'}));
            });
            lines.forEach(function (line) {
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
        controls.hidden = false;
        controls.addEventListener('change', render);
        render();
    });
})();
