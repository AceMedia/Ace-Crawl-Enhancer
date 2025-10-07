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
        <div class="ace-seo-cards">
            <!-- SEO Overview Card -->
            <div class="ace-seo-card">
                <div class="ace-seo-card-header">
                    <h3>📊 SEO Overview</h3>
                </div>
                <div class="ace-seo-card-body">
                    <?php
                    // Load dashboard cache class if not loaded
                    if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                        require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
                    }
                    
                    // Get cached statistics to prevent 504 timeouts
                    $stats = ACE_SEO_Dashboard_Cache::get_dashboard_stats();
                    
                    // Use cached values with error handling
                    $focus_keywords_count = $stats['focus_keywords_count'] ?? 0;
                    $meta_desc_count = $stats['meta_desc_count'] ?? 0;
                    $total_posts = $stats['total_posts'] ?? 0;
                    $focus_keyword_percentage = $stats['focus_keyword_percentage'] ?? 0;
                    $meta_desc_percentage = $stats['meta_desc_percentage'] ?? 0;
                    
                    // Show cache status for debugging
                    $cache_status = ACE_SEO_Dashboard_Cache::get_cache_status();
                    ?>
                    
                    <div class="ace-seo-stats">
                        <div class="ace-seo-stat">
                            <div class="ace-seo-stat-number"><?php echo $focus_keywords_count; ?></div>
                            <div class="ace-seo-stat-label">Posts with Focus Keywords</div>
                        </div>
                        <div class="ace-seo-stat">
                            <div class="ace-seo-stat-number"><?php echo $meta_desc_count; ?></div>
                            <div class="ace-seo-stat-label">Posts with Meta Descriptions</div>
                        </div>
                        <div class="ace-seo-stat">
                            <div class="ace-seo-stat-number"><?php echo $total_posts; ?></div>
                            <div class="ace-seo-stat-label">Total Published Content</div>
                        </div>
                    </div>
                    
                    <?php if (isset($stats['error']) && $stats['error']): ?>
                        <div class="notice notice-warning">
                            <p><strong>⚠️ Performance Notice:</strong> Dashboard stats are using cached fallback due to database timeout protection.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="ace-seo-progress">
                        <p><strong>SEO Optimization Progress:</strong> 
                            <?php if ($cache_status['stats_cached']): ?>
                                <small style="color: #666;">(Cached - <?php echo human_time_diff($stats['generated_at'] ?? time()); ?> ago)</small>
                            <?php endif; ?>
                        </p>
                        <div class="ace-seo-progress-item">
                            <span>Focus Keywords: <?php echo $focus_keyword_percentage; ?>%</span>
                            <div class="ace-seo-progress-bar">
                                <div class="ace-seo-progress-fill" style="width: <?php echo $focus_keyword_percentage; ?>%;"></div>
                            </div>
                        </div>
                        <div class="ace-seo-progress-item">
                            <span>Meta Descriptions: <?php echo $meta_desc_percentage; ?>%</span>
                            <div class="ace-seo-progress-bar">
                                <div class="ace-seo-progress-fill" style="width: <?php echo $meta_desc_percentage; ?>%;"></div>
                            </div>
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
                    <?php
                    // Get cached recent posts to prevent 504 timeouts
                    if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                        require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
                    }
                    
                    $recent_posts = ACE_SEO_Dashboard_Cache::get_recent_posts(5);
                    
                    if (!empty($recent_posts)): ?>
                        <div class="ace-seo-recent-posts">
                            <?php foreach ($recent_posts as $post_data): ?>
                                <div class="ace-seo-recent-post">
                                    <div class="ace-seo-recent-post-title">
                                        <a href="<?php echo esc_url($post_data['edit_link']); ?>">
                                            <?php echo esc_html($post_data['post_title']); ?>
                                        </a>
                                        <span class="ace-seo-post-type"><?php echo esc_html(ucfirst($post_data['post_type'])); ?></span>
                                    </div>
                                    <div class="ace-seo-recent-post-date">
                                        Modified: <?php echo human_time_diff(strtotime($post_data['post_modified'])); ?> ago
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>No optimized content found yet. Start optimizing your posts for better SEO!</p>
                    <?php endif; ?>
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
                    <?php
                    // Check if optimization is pending
                    $optimization_pending = get_option( 'ace_seo_db_optimization_pending', false );
                    $optimization_completed = get_option( 'ace_seo_db_optimized', false );
                    
                    if ( $optimization_pending ) {
                        ?>
                        <div class="ace-seo-optimization-pending">
                            <div class="ace-seo-spinner"></div>
                            <h4>🚀 Database Optimization In Progress</h4>
                            <p>Database indexes are being created in the background to improve performance. This may take a few minutes on large sites.</p>
                            <p><small>This process started when the plugin was activated and runs automatically.</small></p>
                            <button type="button" onclick="location.reload()" class="ace-seo-refresh-btn">Refresh Status</button>
                        </div>
                        <?php
                    } else {
                        // Initialize database optimizer for analysis
                        if (class_exists('ACE_SEO_Database_Optimizer')) {
                            $db_optimizer = new ACE_SEO_Database_Optimizer();
                            $analysis = $db_optimizer->analyze_performance();
                        ?>
                            <div class="ace-seo-db-stats">
                                <div class="ace-seo-db-stat">
                                    <strong>SEO Meta Records:</strong> <?php echo number_format($analysis['seo_meta_records']); ?>
                                </div>
                                <div class="ace-seo-db-stat">
                                    <strong>Total Meta Records:</strong> <?php echo number_format($analysis['postmeta_records']); ?>
                                </div>
                                <div class="ace-seo-db-stat">
                                    <strong>Active Indexes:</strong> <?php echo count($analysis['existing_indexes']); ?>
                                </div>
                                <?php if ( $optimization_completed ) { ?>
                                    <div class="ace-seo-db-stat">
                                        <strong>Last Optimized:</strong> <?php echo human_time_diff( strtotime( $optimization_completed ), current_time( 'timestamp' ) ); ?> ago
                                    </div>
                                <?php } ?>
                            </div>
                            
                            <?php if (!empty($analysis['recommendations'])): ?>
                                <div class="ace-seo-recommendations">
                                    <h4>Performance Recommendations:</h4>
                                    <?php foreach ($analysis['recommendations'] as $recommendation): ?>
                                        <div class="ace-seo-recommendation">⚠️ <?php echo esc_html($recommendation); ?></div>
                                    <?php endforeach; ?>
                                    
                                    <button type="button" id="ace-optimize-database" class="ace-seo-optimize-btn">
                                        Optimize Database Indexes
                                    </button>
                                    <div id="ace-optimize-result" class="ace-optimize-result" style="display: none;"></div>
                                </div>
                            <?php else: ?>
                                <div class="ace-seo-performance-good">
                                    ✅ Database performance is optimized!
                                    <?php if ( $optimization_completed ) { ?>
                                        <br><small>Optimization completed <?php echo human_time_diff( strtotime( $optimization_completed ), current_time( 'timestamp' ) ); ?> ago</small>
                                    <?php } ?>
                                </div>
                            <?php endif; ?>
                        <?php } else { ?>
                            <p>Database optimizer not available.</p>
                        <?php } ?>
                    <?php } ?>
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
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
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
