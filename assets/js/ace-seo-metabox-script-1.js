(function() {
            var el = document.getElementById('ace-gsc-perf');
            if (!el || typeof aceSeoAdmin === 'undefined' || !aceSeoAdmin.restUrl) { return; }
            var url = el.getAttribute('data-url');
            var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
            fetch(aceSeoAdmin.restUrl + 'google/search-console/page-metrics?days=28&url=' + encodeURIComponent(url), {
                headers: { 'X-WP-Nonce': aceSeoAdmin.nonce }
            }).then(function(r) { return r.json(); }).then(function(d) {
                if (!d || d.connected === false) {
                    el.innerHTML = '<p class="description">' + esc((d && d.message) || 'Search performance is unavailable.') + '</p>';
                    return;
                }
                if (!d.impressions) {
                    el.innerHTML = '<p class="description">No search impressions recorded for this page in the last 28 days.</p>';
                    return;
                }
                var html = '<p style="margin:0 0 8px;"><strong>Last 28 days</strong></p>' +
                    '<ul style="margin:0 0 10px;list-style:none;padding:0;">' +
                    '<li>Impressions: <strong>' + esc(d.impressions.toLocaleString()) + '</strong></li>' +
                    '<li>Clicks: <strong>' + esc(d.clicks.toLocaleString()) + '</strong></li>' +
                    '<li>CTR: <strong>' + esc(d.ctr) + '%</strong></li>' +
                    '<li>Avg position: <strong>' + esc(d.position) + '</strong></li>' +
                    '</ul>';
                if (d.top_queries && d.top_queries.length) {
                    html += '<p style="margin:0 0 4px;"><strong>Top queries</strong></p><ol style="margin:0 0 10px;padding-left:18px;">';
                    d.top_queries.slice(0, 5).forEach(function(q) {
                        html += '<li>' + esc(q.query) + ' <span class="description">(pos ' + esc(q.position) + ', ' + esc(q.impressions) + ' impr)</span></li>';
                    });
                    html += '</ol>';
                }
                if (d.hint) {
                    html += '<p class="description" style="border-left:3px solid #a4286a;padding-left:8px;">' + esc(d.hint) + '</p>';
                }
                el.innerHTML = html;
            }).catch(function() {
                el.innerHTML = '<p class="description">Could not load search performance.</p>';
            });
        })();
