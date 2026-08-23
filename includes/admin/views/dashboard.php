<?php
/**
 * Dashboard page template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>
        <span class="dashicons dashicons-search" style="font-size: 30px; margin-right: 10px; color: #a4286a;"></span>
        Ace SEO Dashboard
    </h1>
    
    <div class="ace-seo-dashboard">
        <!-- First Row: 3 Cards -->
        <div class="ace-seo-cards ace-seo-row-1">
            <!-- SEO Overview Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>📊 SEO Overview</h3>
                </div>
                <div class="ace-seo-card-body">
                    <!-- AJAX Loading Container for Statistics -->
                    <div id="ace-seo-stats-container">
                        <div class="ace-loading">
                            <div class="ace-spinner"></div>
                            <p>Loading SEO statistics...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>⚡ Quick Actions</h3>
                </div>
                <div class="ace-seo-card-body">
                    <div class="ace-seo-quick-actions">
                        <a href="<?php echo admin_url('edit.php?post_type=post'); ?>" class="ace-seo-action-button">
                            <span class="dashicons dashicons-edit"></span>
                            Optimize Posts
                        </a>
                        <a href="<?php echo admin_url('edit.php?post_type=page'); ?>" class="ace-seo-action-button">
                            <span class="dashicons dashicons-admin-page"></span>
                            Optimize Pages
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=ace-seo-settings'); ?>" class="ace-seo-action-button">
                            <span class="dashicons dashicons-admin-settings"></span>
                            Plugin Settings
                        </a>
                        <a href="<?php echo site_url(); ?>" target="_blank" class="ace-seo-action-button">
                            <span class="dashicons dashicons-external"></span>
                            View Site
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>📈 Recent SEO Activity</h3>
                </div>
                <div class="ace-seo-card-body">
                    <!-- AJAX Loading Container for Recent Activity -->
                    <div id="ace-recent-activity-container">
                        <div class="ace-loading">
                            <div class="ace-spinner"></div>
                            <p>Loading recent activity...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Second Row: 3 Cards -->
        <div class="ace-seo-cards ace-seo-row-2">
            <!-- Content Analysis Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>🔍 Content Analysis</h3>
                </div>
                <div class="ace-seo-card-body">
                    <!-- AJAX Loading Container for Content Analysis -->
                    <div id="ace-content-analysis-container">
                        <div class="ace-loading">
                            <div class="ace-spinner"></div>
                            <p>Analyzing content...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tips & Best Practices Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>💡 SEO Tips</h3>
                </div>
                <div class="ace-seo-card-body">
                    <div class="ace-seo-tips">
                        <div class="ace-seo-tip">
                            <strong>🎯 Focus Keywords:</strong> Choose specific, relevant keywords that your audience actually searches for.
                        </div>
                        <div class="ace-seo-tip">
                            <strong>📝 Meta Descriptions:</strong> Write compelling descriptions between 120-160 characters that encourage clicks.
                        </div>
                        <div class="ace-seo-tip">
                            <strong>📱 Social Sharing:</strong> Optimize your Open Graph and Twitter Card settings for better social media appearance.
                        </div>
                        <div class="ace-seo-tip">
                            <strong>🔗 Internal Links:</strong> Link to related content on your site to help search engines understand your content structure.
                        </div>
                        <div class="ace-seo-tip">
                            <strong>📊 Monitor Performance:</strong> Regularly check your content's performance and update optimization as needed.
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Database Performance Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>🚀 Database Performance</h3>
                </div>
                <div class="ace-seo-card-body">
                    <!-- AJAX Loading Container for Database Performance -->
                    <div id="ace-seo-database-container">
                        <div class="ace-loading">
                            <div class="ace-spinner"></div>
                            <p>Loading database performance status...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Google Signals Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>Google Signals</h3>
                </div>
                <div class="ace-seo-card-body">
                    <div id="ace-google-signals-container">
                        <div class="ace-loading">
                            <div class="ace-spinner"></div>
                            <p>Loading Google connection status...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Console (Site Kit) -->
        <div class="ace-seo-cards ace-seo-row-search-console" style="grid-template-columns: 1fr;">
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>🔗 Search Console</h3>
                </div>
                <div class="ace-seo-card-body">
<?php if ( class_exists( 'AceSEOSearchConsole' ) && AceSEOSearchConsole::is_ready() ) : ?>
                    <div id="ace-gsc-panel" data-rest="<?php echo esc_url( rest_url( 'ace-seo/v1/google/search-console/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
                        <div class="ace-gsc-totals" id="ace-gsc-totals">
                            <div class="ace-loading"><div class="ace-spinner"></div><p>Loading search performance...</p></div>
                        </div>

                        <h4 style="margin:18px 0 8px;">Sitemaps</h4>
                        <div id="ace-gsc-sitemaps">
                            <div class="ace-loading"><div class="ace-spinner"></div><p>Loading sitemaps...</p></div>
                        </div>
                        <p style="margin-top:10px;">
                            <button type="button" class="ace-seo-refresh-btn" id="ace-gsc-resubmit-all">Resubmit all sitemaps</button>
                            <span id="ace-gsc-resubmit-result" style="margin-left:10px;"></span>
                        </p>

                        <h4 style="margin:18px 0 8px;">Inspect a URL</h4>
                        <p>
                            <input type="url" id="ace-gsc-inspect-url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" style="min-width:280px;" />
                            <button type="button" class="ace-seo-refresh-btn" id="ace-gsc-inspect-btn">Inspect</button>
                        </p>
                        <div id="ace-gsc-inspect-result"></div>
                    </div>
<?php else : ?>
                    <p class="description">Connect Google Search Console in <strong>Site Kit</strong> to manage sitemaps, inspect URLs and see search performance here.</p>
<?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ace-seo-dashboard {
    margin-top: 20px;
}

.ace-seo-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

/* Responsive adjustments for smaller screens */
@media (max-width: 1200px) {
    .ace-seo-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .ace-seo-cards {
        grid-template-columns: 1fr;
    }
}

.ace-seo-card {
    background: #fff;
    border: 1px solid #e1e1e1;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.ace-seo-card-header {
    background: #f9f9f9;
    padding: 16px 20px;
    border-bottom: 1px solid #e1e1e1;
}

.ace-seo-card-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #1e1e1e;
}

.ace-seo-card-body {
    padding: 20px;
}

/* AJAX Loading States */
.ace-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    color: #666;
}

.ace-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #a4286a;
    border-radius: 50%;
    animation: ace-spin 1s linear infinite;
    margin-bottom: 10px;
}

@keyframes ace-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.ace-error {
    text-align: center;
    color: #dc3232;
    padding: 20px;
}

/* Large Site Notice */
.ace-large-site-notice {
    margin-top: 15px;
}

.ace-progress-bar {
    width: 100%;
    height: 20px;
    background: #f3f4f6;
    border-radius: 10px;
    overflow: hidden;
    margin: 10px 0;
}

.ace-progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #a4286a 0%, #667eea 100%);
    width: 0%;
    transition: width 0.3s ease;
}

.ace-progress-text {
    font-size: 14px;
    color: #666;
    margin: 5px 0;
}

/* Analysis Results */
.ace-analysis-results {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-top: 15px;
}

.ace-analysis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.ace-analysis-item {
    text-align: center;
}

.ace-analysis-number {
    font-size: 24px;
    font-weight: bold;
    color: #a4286a;
    margin-bottom: 5px;
}

.ace-analysis-label {
    font-size: 12px;
    color: #666;
    font-weight: 500;
}

/* Content Analysis Styles */
.ace-content-analysis .ace-content-breakdown {
    margin: 10px 0;
    color: #666;
}

.ace-content-type {
    display: inline-block;
    padding: 2px 8px;
    background: #f0f0f1;
    border-radius: 12px;
    font-size: 12px;
    margin: 2px;
}

.ace-missing-optimization ul {
    list-style: none;
    padding: 0;
    margin: 10px 0;
}

.ace-missing-optimization li {
    padding: 5px 0;
    color: #d63638;
    font-size: 14px;
}

/* Refresh buttons */
.button-link.ace-refresh-stats,
.button-link.ace-refresh-activity, 
.button-link.ace-refresh-analysis {
    color: #0073aa;
    text-decoration: none;
    font-size: 12px;
    margin-left: 10px;
}

.button-link.ace-refresh-stats:hover,
.button-link.ace-refresh-activity:hover,
.button-link.ace-refresh-analysis:hover {
    color: #005177;
    text-decoration: underline;
}

.ace-seo-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.ace-seo-stat {
    text-align: center;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 6px;
}

.ace-seo-stat-number {
    font-size: 28px;
    font-weight: bold;
    color: #a4286a;
    margin-bottom: 4px;
}

.ace-seo-stat-label {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ace-seo-progress-item {
    margin-bottom: 12px;
}

.ace-seo-progress-bar {
    height: 8px;
    background: #e1e1e1;
    border-radius: 4px;
    margin-top: 4px;
}

.ace-seo-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #a4286a, #d63384);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.ace-seo-quick-actions {
    display: grid;
    gap: 12px;
}

.ace-seo-action-button {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    background: #f8f9fa;
    border: 1px solid #e1e1e1;
    border-radius: 6px;
    text-decoration: none;
    color: #444;
    transition: all 0.2s ease;
}

.ace-seo-action-button:hover {
    background: #a4286a;
    color: #fff;
    text-decoration: none;
}

.ace-seo-action-button .dashicons {
    margin-right: 8px;
}

.ace-seo-recent-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.ace-seo-recent-item {
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.ace-seo-recent-item:last-child {
    border-bottom: none;
}

.ace-seo-recent-title a {
    font-weight: 500;
    text-decoration: none;
}

.ace-seo-recent-title a:hover {
    color: #a4286a;
}

.ace-seo-recent-meta {
    font-size: 12px;
    color: #666;
    margin-top: 4px;
}

.ace-seo-tips {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.ace-seo-tip {
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    font-size: 14px;
    line-height: 1.5;
    border-left: 3px solid #a4286a;
}

.ace-seo-db-stats {
    display: grid;
    gap: 8px;
    margin-bottom: 16px;
}

.ace-seo-db-stat {
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 4px;
    font-size: 14px;
}

.ace-seo-recommendations {
    margin-top: 16px;
    padding: 16px;
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
}

.ace-seo-recommendations h4 {
    margin: 0 0 12px 0;
    color: #856404;
}

.ace-seo-recommendation {
    margin: 8px 0;
    color: #856404;
    font-size: 14px;
}

.ace-seo-optimize-btn {
    background: #a4286a;
    color: white;
    border: none;
    padding: 10px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    margin-top: 12px;
}

.ace-seo-optimize-btn:hover {
    background: #8a2258;
}

.ace-seo-optimize-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.ace-optimize-result {
    margin-top: 12px;
    padding: 12px;
    border-radius: 4px;
}

.ace-optimize-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.ace-optimize-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.ace-seo-performance-good {
    text-align: center;
    padding: 20px;
    color: #155724;
    font-weight: 500;
}

.ace-seo-optimization-pending {
    text-align: center;
    padding: 30px 20px;
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
}

.ace-seo-optimization-pending h4 {
    margin: 10px 0;
    color: #856404;
}

.ace-seo-optimization-pending p {
    color: #856404;
    margin: 8px 0;
}

.ace-seo-spinner {
    width: 40px;
    height: 40px;
    margin: 0 auto 15px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #a4286a;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.ace-seo-refresh-btn {
    background: #a4286a;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    margin-top: 10px;
}

.ace-seo-refresh-btn:hover {
    background: #8a2258;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#ace-optimize-database').on('click', function() {
        var $btn = $(this);
        var $result = $('#ace-optimize-result');
        
        $btn.prop('disabled', true).text('Optimizing...');
        $result.hide().removeClass('success error');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_optimize_database',
                nonce: '<?php echo wp_create_nonce('ace_seo_optimize_db'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var message = '<strong>Database optimization completed!</strong><br>';
                    var hasResults = false;
                    
                    $.each(response.data, function(table, indexes) {
                        $.each(indexes, function(index_name, result) {
                            hasResults = true;
                            message += '• ' + index_name + ': ' + result.message + '<br>';
                        });
                    });
                    
                    if (!hasResults) {
                        message = 'All indexes are already optimized.';
                    }
                    
                    $result.addClass('success').html(message).show();
                    
                    // Refresh the page after 3 seconds to show updated stats
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $result.addClass('error').html('Error optimizing database: ' + response.data).show();
                }
            },
            error: function() {
                $result.addClass('error').html('Network error occurred during optimization.').show();
            },
            complete: function() {
                $btn.prop('disabled', false).text('Optimize Database Indexes');
            }
        });
    });
});
</script>

<script>
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
</script>
