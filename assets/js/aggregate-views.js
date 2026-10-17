(function () {
    'use strict';
    if (window.aceAggregateViews || !window.aceAggregateViewsConfig) return;
    const endpoint = new URL(window.aceAggregateViewsConfig.endpoint, location.href);
    if (endpoint.origin !== location.origin) return;
    const preference = 'ace_basic_views_off';
    let last = null, pending, stopped = false;
    const routes = new Set([location.pathname + location.search + location.hash]);
    const cookie = name => {
        const match = document.cookie.split(';').map(s => s.trim()).find(s => s.startsWith(name + '='));
        if (!match) return '';
        try { return decodeURIComponent(match.slice(name.length + 1)); } catch (_) { return ''; }
    };
    function optedOut() {
        if (cookie(preference) === '1' || navigator.globalPrivacyControl === true || navigator.doNotTrack === '1' || window.doNotTrack === '1') return true;
        if (window.aceConsent?.answered && !window.aceConsent.has('analytics')) return true;
        if (cookie('viewed_cookie_policy') && cookie('cookielawinfo-checkbox-analytics') === 'no') return true;
        const cy = cookie('cookieyes-consent');
        const answered = /(?:^|,)action:yes(?:,|$)/.test(cy) || (!/(?:^|,)action:/.test(cy) && /(?:^|,)consent:(?:yes|no)(?:,|$)/.test(cy));
        if (answered && /(?:^|,)analytics:no(?:,|$)/.test(cy)) return true;
        return cookie('cmplz_banner-status') === 'dismissed' && cookie('cmplz_statistics') === 'deny';
    }
    function send(force) {
        if (document.visibilityState !== 'visible' || document.prerendering) return;
        const route = location.pathname + location.search + location.hash;
        if (!force && route === last) return;
        last = route;
        if (stopped || optedOut()) return;
        // No URL, referrer, cookie, visitor identifier or consent profile leaves the page.
        // Do not retry: without an identifier, retries could count one view twice.
        fetch(endpoint.href, {method: 'POST', mode: 'same-origin', credentials: 'omit',
            referrerPolicy: 'no-referrer', cache: 'no-store', keepalive: true,
            headers: {'Content-Type': 'application/json'}, body: '{"v":1}'}).catch(() => {});
    }
    function schedule() { clearTimeout(pending); pending = setTimeout(() => send(false), 200); }
    ['pushState', 'replaceState'].forEach(name => {
        const original = history[name];
        history[name] = function () {
            const result = original.apply(this, arguments);
            routes.add(location.pathname + location.search + location.hash);
            schedule(); return result;
        };
    });
    addEventListener('popstate', () => {
        const route = location.pathname + location.search + location.hash;
        // Ordinary in-page anchor jumps are not new pages. App-created history entries are.
        if (routes.has(route) || !last || route.split('#')[0] !== last.split('#')[0]) schedule();
    });
    addEventListener('pageshow', e => { if (e.persisted) send(true); });
    document.addEventListener('visibilitychange', schedule);
    document.addEventListener('prerenderingchange', schedule);
    document.addEventListener('ace:consent', e => { if (e.detail?.analytics === false) stopped = true; else if (e.detail?.analytics === true) stopped = false; });
    window.aceAggregateViews = {
        setOptOut(off) {
            document.cookie = preference + '=' + (off ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
            stopped = !!off;
        }
    };
    const control = document.getElementById('ace-view-optout');
    if (control) { control.checked = optedOut(); control.addEventListener('change', () => window.aceAggregateViews.setOptOut(control.checked)); }
    function start() {
        const banner = document.querySelector?.('.tf-consent__text, .iga-consent__text, .cky-notice-des');
        if (window.aceAggregateViewsConfig?.showPrivacy && banner && !banner.querySelector('[data-ace-basic-notice]')) {
            const note = document.createElement('span');
            note.dataset.aceBasicNotice = '1'; note.style.display = 'block';
            note.textContent = 'We also count basic page views without cookies or visitor IDs before you choose. Rejecting analytics stops this count too. ';
            const link = document.createElement('a'); link.href = '#ace-view-privacy'; link.textContent = 'View-count privacy choices';
            link.addEventListener('click', () => { const panel = document.getElementById('ace-view-privacy'); if (panel) panel.open = true; });
            note.appendChild(link); banner.appendChild(note);
        }
        schedule();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
})();
