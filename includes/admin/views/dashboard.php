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
                    // Get some basic stats
                    $posts_with_focus_keywords = get_posts([
                        'post_type' => ['post', 'page'],
                        'meta_query' => [
                            [
                                'key' => '_yoast_wpseo_focuskw',
                                'value' => '',
                                'compare' => '!='
                            ]
                        ],
                        'posts_per_page' => -1,
                        'fields' => 'ids'
                    ]);
                    
                    $posts_with_meta_desc = get_posts([
                        'post_type' => ['post', 'page'],
                        'meta_query' => [
                            [
                                'key' => '_yoast_wpseo_metadesc',
                                'value' => '',
                                'compare' => '!='
                            ]
                        ],
                        'posts_per_page' => -1,
                        'fields' => 'ids'
                    ]);
                    
                    $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
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
                    // Get recently optimized posts
                    $recent_posts = get_posts([
                        'post_type' => ['post', 'page'],
                        'posts_per_page' => 5,
                        'meta_query' => [
                            'relation' => 'OR',
                            [
                                'key' => '_yoast_wpseo_focuskw',
                                'value' => '',
                                'compare' => '!='
                            ],
                            [
                                'key' => '_yoast_wpseo_metadesc',
                                'value' => '',
                                'compare' => '!='
                            ]
                        ],
                        'orderby' => 'modified',
                        'order' => 'DESC'
                    ]);
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
</style>
