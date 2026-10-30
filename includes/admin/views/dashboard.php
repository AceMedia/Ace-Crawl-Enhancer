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
                        <a href="<?php echo esc_url( admin_url('edit.php?post_type=post') ); ?>" class="ace-seo-action-button">
                            <span class="dashicons dashicons-edit"></span>
                            Optimize Posts
                        </a>
                        <a href="<?php echo esc_url( admin_url('edit.php?post_type=page') ); ?>" class="ace-seo-action-button">
                            <span class="dashicons dashicons-admin-page"></span>
                            Optimize Pages
                        </a>
                        <a href="<?php echo esc_url( admin_url('admin.php?page=ace-seo-settings') ); ?>" class="ace-seo-action-button">
                            <span class="dashicons dashicons-admin-settings"></span>
                            Plugin Settings
                        </a>
                        <a href="<?php echo esc_url( site_url() ); ?>" target="_blank" class="ace-seo-action-button">
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
            
            <!-- Reachability / Orphan Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>🧭 Content reachability</h3>
                </div>
                <div class="ace-seo-card-body">
                    <div id="ace-orphan-report-container">
                        <div class="ace-loading">
                            <div class="ace-spinner"></div>
                            <p><?php esc_html_e( 'Checking reachability...', 'ace-crawl-enhancer' ); ?></p>
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

<?php AceSeoAssets::style( 'ace-seo-dashboard-style-1', 'assets/css/ace-seo-dashboard-style-1.css' ); ?>

<?php AceSeoAssets::inline_script( 'ace-seo-dashboard-script-1', '
jQuery(document).ready(function($) {
    $(\'#ace-optimize-database\').on(\'click\', function() {
        var $btn = $(this);
        var $result = $(\'#ace-optimize-result\');
        
        $btn.prop(\'disabled\', true).text(\'Optimizing...\');
        $result.hide().removeClass(\'success error\');
        
        $.ajax({
            url: ajaxurl,
            type: \'POST\',
            data: {
                action: \'ace_seo_optimize_database\',
                nonce: \'' . (esc_js( wp_create_nonce('ace_seo_optimize_db') )) . '\'
            },
            success: function(response) {
                if (response.success) {
                    var message = \'<strong>Database optimization completed!</strong><br>\';
                    var hasResults = false;
                    
                    $.each(response.data, function(table, indexes) {
                        $.each(indexes, function(index_name, result) {
                            hasResults = true;
                            message += \'• \' + index_name + \': \' + result.message + \'<br>\';
                        });
                    });
                    
                    if (!hasResults) {
                        message = \'All indexes are already optimized.\';
                    }
                    
                    $result.addClass(\'success\').html(message).show();
                    
                    // Refresh the page after 3 seconds to show updated stats
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $result.addClass(\'error\').html(\'Error optimizing database: \' + response.data).show();
                }
            },
            error: function() {
                $result.addClass(\'error\').html(\'Network error occurred during optimization.\').show();
            },
            complete: function() {
                $btn.prop(\'disabled\', false).text(\'Optimize Database Indexes\');
            }
        });
    });
});
' ); ?>

<?php AceSeoAssets::script( 'ace-seo-dashboard-script-2', 'assets/js/ace-seo-dashboard-script-2.js' ); ?>
