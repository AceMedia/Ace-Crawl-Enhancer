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
                    // Get some basic stats using direct database queries for better performance
                    global $wpdb;
                    
                    // Count posts with focus keywords - much faster direct query
                    $focus_keywords_count = $wpdb->get_var("
                        SELECT COUNT(DISTINCT p.ID) 
                        FROM {$wpdb->posts} p 
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                        WHERE p.post_status = 'publish' 
                        AND p.post_type IN ('post', 'page') 
                        AND pm.meta_key = '_yoast_wpseo_focuskw' 
                        AND pm.meta_value != ''
                        LIMIT 1000
                    ");
                    
                    // Count posts with meta descriptions - much faster direct query
                    $meta_desc_count = $wpdb->get_var("
                        SELECT COUNT(DISTINCT p.ID) 
                        FROM {$wpdb->posts} p 
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                        WHERE p.post_status = 'publish' 
                        AND p.post_type IN ('post', 'page') 
                        AND pm.meta_key = '_yoast_wpseo_metadesc' 
                        AND pm.meta_value != ''
                        LIMIT 1000
                    ");
                    
                    // Get total published posts count
                    $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
                    
                    // Convert to arrays for compatibility with existing code
                    $posts_with_focus_keywords = range(1, $focus_keywords_count ?: 0);
                    $posts_with_meta_desc = range(1, $meta_desc_count ?: 0);
                    ?>
                    
                    <div class="ace-seo-stats">
                        <div class="ace-seo-stat">
                            <div class="ace-seo-stat-number"><?php echo count($posts_with_focus_keywords); ?></div>
                            <div class="ace-seo-stat-label">Posts with Focus Keywords</div>
                        </div>
                        <div class="ace-seo-stat">
                            <div class="ace-seo-stat-number"><?php echo count($posts_with_meta_desc); ?></div>
                            <div class="ace-seo-stat-label">Posts with Meta Descriptions</div>
                        </div>
                        <div class="ace-seo-stat">
                            <div class="ace-seo-stat-number"><?php echo $total_posts; ?></div>
                            <div class="ace-seo-stat-label">Total Published Content</div>
                        </div>
                    </div>
                    
                    <div class="ace-seo-progress">
                        <p><strong>SEO Optimization Progress:</strong></p>
                        <?php 
                        $focus_keyword_percentage = $total_posts > 0 ? round((count($posts_with_focus_keywords) / $total_posts) * 100) : 0;
                        $meta_desc_percentage = $total_posts > 0 ? round((count($posts_with_meta_desc) / $total_posts) * 100) : 0;
                        ?>
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
                    // Get recently optimized posts using optimized query
                    global $wpdb;
                    
                    // Much faster query - get recent posts with SEO data
                    $recent_post_ids = $wpdb->get_results($wpdb->prepare("
                        SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_modified
                        FROM {$wpdb->posts} p 
                        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                        WHERE p.post_status = 'publish' 
                        AND p.post_type IN ('post', 'page') 
                        AND pm.meta_key IN ('_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc')
                        AND pm.meta_value != ''
                        ORDER BY p.post_modified DESC 
                        LIMIT %d
                    ", 5));
                    
                    // Convert to post objects for compatibility
                    $recent_posts = array();
                    foreach ($recent_post_ids as $post_data) {
                        $recent_posts[] = get_post($post_data->ID);
                    }
                    ?>
                    
                    <?php if (!empty($recent_posts)): ?>
                        <ul class="ace-seo-recent-list">
                            <?php foreach ($recent_posts as $post): ?>
                                <li class="ace-seo-recent-item">
                                    <div class="ace-seo-recent-title">
                                        <a href="<?php echo get_edit_post_link($post->ID); ?>">
                                            <?php echo esc_html($post->post_title); ?>
                                        </a>
                                    </div>
                                    <div class="ace-seo-recent-meta">
                                        <?php echo get_post_type($post->ID); ?> • 
                                        <?php echo human_time_diff(strtotime($post->post_modified), current_time('timestamp')); ?> ago
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No SEO-optimized content found. Start optimizing your posts and pages!</p>
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
                            </div>
                        <?php endif; ?>
                    <?php } else { ?>
                        <p>Database optimizer not available.</p>
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
