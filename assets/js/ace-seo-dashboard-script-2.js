jQuery(document).ready(function($) {
    var $panel = $('#ace-gsc-panel');
    if (!$panel.length) return;

    var restBase = $panel.data('rest');
    var nonce = $panel.data('nonce');

    function api(path, method, body) {
        return fetch(restBase + path, {
            method: method || 'GET',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json'
            },
            body: body ? JSON.stringify(body) : undefined
        }).then(function(r) { return r.json(); });
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    // Site-wide search totals.
    api('totals?days=28').then(function(d) {
        var $t = $('#ace-gsc-totals');
        if (!d || d.connected === false) {
            $t.html('<p class="description">' + esc((d && d.message) || 'Search performance is unavailable.') + '</p>');
            return;
        }
        $t.html(
            '<div class="ace-seo-stats" style="display:flex;gap:24px;flex-wrap:wrap;">' +
            '<div class="ace-seo-stat"><div class="ace-seo-stat-number">' + esc(d.impressions.toLocaleString()) + '</div><div class="ace-seo-stat-label">Impressions</div></div>' +
            '<div class="ace-seo-stat"><div class="ace-seo-stat-number">' + esc(d.clicks.toLocaleString()) + '</div><div class="ace-seo-stat-label">Clicks</div></div>' +
            '<div class="ace-seo-stat"><div class="ace-seo-stat-number">' + esc(d.ctr) + '%</div><div class="ace-seo-stat-label">CTR</div></div>' +
            '<div class="ace-seo-stat"><div class="ace-seo-stat-number">' + esc(d.position) + '</div><div class="ace-seo-stat-label">Avg position</div></div>' +
            '</div><p class="description">Search Console, last 28 days.</p>'
        );
    }).catch(function() {
        $('#ace-gsc-totals').html('<p class="ace-error">Could not load search performance.</p>');
    });

    function renderSitemaps(d) {
        var $s = $('#ace-gsc-sitemaps');
        if (!d || d.connected === false) {
            $s.html('<p class="description">' + esc((d && d.message) || 'Sitemaps are unavailable.') + '</p>');
            return;
        }
        if (!d.sitemaps || !d.sitemaps.length) {
            $s.html('<p class="description">No sitemaps have been submitted to Search Console yet.</p>');
            return;
        }
        var html = '<table class="wp-list-table widefat fixed striped"><thead><tr>' +
            '<th>Path</th><th>Submitted</th><th>Indexed</th><th>Errors</th><th>Last downloaded</th><th></th>' +
            '</tr></thead><tbody>';
        d.sitemaps.forEach(function(row) {
            var submitted = 0, indexed = 0;
            (row.contents || []).forEach(function(c) { submitted += c.submitted; indexed += c.indexed; });
            html += '<tr>' +
                '<td><a href="' + esc(row.path) + '" target="_blank" rel="noopener">' + esc(row.path) + '</a>' + (row.isPending ? ' <em>(pending)</em>' : '') + '</td>' +
                '<td>' + esc(submitted.toLocaleString()) + '</td>' +
                '<td>' + esc(indexed.toLocaleString()) + '</td>' +
                '<td>' + (row.errors > 0 ? '<span style="color:#dc3232;">' + esc(row.errors) + '</span>' : '0') + '</td>' +
                '<td>' + esc((row.lastDownloaded || '').substring(0, 10)) + '</td>' +
                '<td><button type="button" class="button-link ace-gsc-submit-one" data-feed="' + esc(row.path) + '">Resubmit</button></td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        $s.html(html);
    }

    function loadSitemaps() {
        $('#ace-gsc-sitemaps').html('<div class="ace-loading"><div class="ace-spinner"></div><p>Loading sitemaps...</p></div>');
        api('sitemaps').then(renderSitemaps).catch(function() {
            $('#ace-gsc-sitemaps').html('<p class="ace-error">Could not load sitemaps.</p>');
        });
    }
    loadSitemaps();

    $(document).on('click', '.ace-gsc-submit-one', function() {
        var $btn = $(this).prop('disabled', true).text('Submitting...');
        api('sitemaps/submit', 'POST', { feed: $btn.data('feed') }).then(function(d) {
            $btn.text(d && d.ok ? 'Done' : 'Failed');
            setTimeout(loadSitemaps, 800);
        }).catch(function() { $btn.prop('disabled', false).text('Resubmit'); });
    });

    $('#ace-gsc-resubmit-all').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Resubmitting...');
        var $out = $('#ace-gsc-resubmit-result').text('');
        api('sitemaps/resubmit-all', 'POST').then(function(d) {
            $btn.prop('disabled', false).text('Resubmit all sitemaps');
            if (!d || d.connected === false) { $out.html('<span class="ace-error">' + esc((d && d.message) || 'Failed.') + '</span>'); return; }
            var ok = (d.results || []).filter(function(r) { return r.ok; }).length;
            $out.text(ok + ' of ' + (d.results || []).length + ' sitemap(s) resubmitted.');
            setTimeout(loadSitemaps, 800);
        }).catch(function() { $btn.prop('disabled', false).text('Resubmit all sitemaps'); });
    });

    $('#ace-gsc-inspect-btn').on('click', function() {
        var url = $('#ace-gsc-inspect-url').val();
        if (!url) return;
        var $btn = $(this).prop('disabled', true).text('Inspecting...');
        var $out = $('#ace-gsc-inspect-result').html('<div class="ace-loading"><div class="ace-spinner"></div><p>Inspecting...</p></div>');
        api('inspect', 'POST', { url: url }).then(function(d) {
            $btn.prop('disabled', false).text('Inspect');
            if (!d || d.connected === false) { $out.html('<p class="ace-error">' + esc((d && d.message) || 'Inspection failed.') + '</p>'); return; }
            $out.html(
                '<table class="wp-list-table widefat striped"><tbody>' +
                '<tr><th style="width:180px;">Verdict</th><td>' + esc(d.verdict) + '</td></tr>' +
                '<tr><th>Coverage</th><td>' + esc(d.coverageState) + '</td></tr>' +
                '<tr><th>Indexing</th><td>' + esc(d.indexingState) + '</td></tr>' +
                '<tr><th>robots.txt</th><td>' + esc(d.robotsTxtState) + '</td></tr>' +
                '<tr><th>Last crawl</th><td>' + esc((d.lastCrawlTime || '').substring(0, 19).replace('T', ' ')) + '</td></tr>' +
                '</tbody></table>'
            );
        }).catch(function() { $btn.prop('disabled', false).text('Inspect'); $out.html('<p class="ace-error">Inspection failed.</p>'); });
    });
});
