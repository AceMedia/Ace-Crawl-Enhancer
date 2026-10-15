document.addEventListener('click', function (event) {
    const button = event.target.closest('.ace-redirect-three-months');
    if (!button) return;
    const field = document.getElementById(button.dataset.target);
    if (field) { field.value = button.dataset.date; field.dispatchEvent(new Event('change', {bubbles: true})); }
});

(function () {
    'use strict';
    const requests = new WeakMap();
    function reset(panel) {
        const request = requests.get(panel);
        if (request) request.abort();
        requests.delete(panel);
        panel.querySelector('.ace-destination-results').replaceChildren();
        panel.querySelector('.ace-destination-status').textContent = '';
        panel.querySelector('.ace-destination-find').disabled = false;
    }
    async function search(panel) {
        reset(panel);
        const query = panel.querySelector('.ace-destination-query').value.trim();
        const types = Array.from(panel.querySelectorAll('.ace-destination-types input:checked'));
        const status = panel.querySelector('.ace-destination-status');
        if (query.length < 2 || query.length > 100 || !types.length) {
            status.textContent = 'Enter 2–100 characters and choose at least one type.';
            return;
        }
        const controller = new AbortController();
        requests.set(panel, controller);
        const button = panel.querySelector('.ace-destination-find');
        button.disabled = true;
        status.textContent = 'Searching…';
        const body = new URLSearchParams({action: 'ace_seo_redirect_destinations', nonce: panel.dataset.nonce, search: query});
        types.forEach(input => body.append('types[]', input.value));
        try {
            const response = await fetch(panel.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body, signal: controller.signal});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Search failed. Refresh the page and try again.');
            if (requests.get(panel) !== controller) return;
            const list = panel.querySelector('.ace-destination-results');
            result.data.items.forEach(item => {
                const li = document.createElement('li');
                const choose = document.createElement('button');
                choose.type = 'button';
                choose.className = 'button-link';
                choose.textContent = item.title + ' (' + item.type + ')';
                choose.disabled = item.blocked;
                choose.addEventListener('click', () => {
                    const target = document.getElementById(panel.dataset.target);
                    if (!target) return;
                    target.value = item.url;
                    target.dispatchEvent(new Event('change', {bubbles: true}));
                    status.textContent = 'Selected: ' + item.title + '. Review the destination URL before saving.';
                    target.focus();
                });
                const url = document.createElement('small');
                url.textContent = item.url;
                li.append(choose, url);
                if (item.note) {
                    const note = document.createElement('small');
                    note.textContent = item.note;
                    li.append(note);
                }
                list.append(li);
            });
            status.textContent = result.data.items.length ? result.data.items.length + ' results.' : 'No matching destinations. Try another type or enter a manual URL.';
            if (result.data.more) status.textContent += ' Showing up to 20 content items and 20 archives. Narrow the search for more specific results.';
        } catch (error) {
            if (error.name !== 'AbortError' && requests.get(panel) === controller) status.textContent = error.message;
        } finally {
            if (requests.get(panel) === controller) {
                button.disabled = false;
                requests.delete(panel);
            }
        }
    }
    document.addEventListener('click', event => {
        const button = event.target.closest('.ace-destination-find');
        if (button) search(button.closest('.ace-destination-search'));
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Enter' && event.target.matches('.ace-destination-query')) {
            event.preventDefault();
            search(event.target.closest('.ace-destination-search'));
        }
    });
    document.addEventListener('input', event => {
        const panel = event.target.closest('.ace-destination-search');
        if (panel && (event.target.matches('.ace-destination-query') || event.target.matches('input[type="checkbox"]'))) reset(panel);
    });
}());
