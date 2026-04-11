<?php
/**
 * Settings page template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current settings
$options = get_option('ace_seo_options', []);
$sitemap_options = function_exists('ace_sitemap_powertools_get_options') ? ace_sitemap_powertools_get_options() : [];
$redis_cache_present = function_exists('ace_sitemap_powertools_has_redis_cache_plugin') ? ace_sitemap_powertools_has_redis_cache_plugin() : false;
$redis_cache_available = function_exists('ace_sitemap_powertools_redis_cache_available') ? ace_sitemap_powertools_redis_cache_available() : false;
global $wpdb;
$has_yoast_data = false;
if ($wpdb) {
    $yoast_like = $wpdb->esc_like('_yoast_wpseo_') . '%';
    $has_yoast_data = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $yoast_like));
    if (!$has_yoast_data) {
        $has_yoast_data = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->termmeta} WHERE meta_key LIKE %s LIMIT 1", $yoast_like));
    }
}
if (!$has_yoast_data) {
    $yoast_tax_meta = get_option('wpseo_taxonomy_meta', []);
    $has_yoast_data = !empty($yoast_tax_meta);
}

$base_template_tokens = [
    '{site_name}',
    '{sep}',
];

$template_tokens_by_context = [
    'post_title' => ['{title}', '{author}', '{date}', '{category}', '{tag}'],
    'post_meta' => ['{title}', '{excerpt}', '{author}', '{date}', '{category}', '{tag}'],
    'page_title' => ['{title}'],
    'archive_title' => ['{archive_title}', '{title}'],
    'archive_meta' => ['{archive_title}', '{title}', '{excerpt}'],
    'search_title' => ['{search_term}'],
    'search_meta' => ['{search_term}', '{excerpt}'],
    'author_title' => ['{author_name}'],
    'author_meta' => ['{author_name}', '{excerpt}'],
    'category_title' => ['{category_name}', '{term_name}', '{taxonomy_name}'],
    'category_meta' => ['{category_name}', '{term_name}', '{taxonomy_name}', '{excerpt}'],
    'tag_title' => ['{tag_name}', '{term_name}', '{taxonomy_name}'],
    'tag_meta' => ['{tag_name}', '{term_name}', '{taxonomy_name}', '{excerpt}'],
    'date_title' => ['{date_archive}', '{date}'],
    'date_meta' => ['{date_archive}', '{date}', '{excerpt}'],
    'post_type_archive_title' => ['{archive_title}', '{post_type_name}', '{post_type_singular}', '{post_type_slug}'],
    'post_type_archive_meta' => ['{archive_title}', '{post_type_name}', '{post_type_singular}', '{post_type_slug}', '{excerpt}'],
    'default' => ['{title}', '{excerpt}'],
];

$render_template_tokens = static function ($target_id, $context = 'default') use ($base_template_tokens, $template_tokens_by_context) {
    $context_tokens = $template_tokens_by_context[$context] ?? $template_tokens_by_context['default'];
    $template_tokens = array_values(array_unique(array_merge($base_template_tokens, $context_tokens)));
    echo '<div class="ace-template-token-list" aria-label="Available template tags">';
    foreach ($template_tokens as $token) {
        echo '<span class="ace-template-tag" role="button" tabindex="0" data-template-target="' . esc_attr($target_id) . '" data-template-token="' . esc_attr($token) . '">' . esc_html($token) . '</span>';
    }
    echo '</div>';
};
?>

<div class="wrap ace-redis-settings">
    
    <!-- Yoast-style Two-Column Layout -->
    <div class="ace-redis-container">

        <!-- Left Sidebar Navigation -->
        <div class="ace-redis-sidebar">
            <h1 class="ace-sidebar-title"><span class="ace-sidebar-logo" aria-hidden="true">🕷️</span><span>Ace Crawl Enhancer</span></h1>
            <nav class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>General</a>
                <div class="ace-tab-subnav" data-tab="general">
                    <a href="#general-core" class="ace-subtab-link" data-target-tab="general" data-target-group="general-core"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>Core Metadata</a>
                </div>
                <a href="#templates" class="nav-tab"><span class="dashicons dashicons-media-text" aria-hidden="true"></span>Templates</a>
                <div class="ace-tab-subnav" data-tab="templates">
                    <a href="#tpl-content-types" class="ace-subtab-link" data-target-tab="templates" data-target-group="tpl-content-types"><span class="dashicons dashicons-media-document" aria-hidden="true"></span>Content Types</a>
                    <a href="#tpl-special-pages" class="ace-subtab-link" data-target-tab="templates" data-target-group="tpl-special-pages"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span>Special Pages</a>
                    <a href="#tpl-custom-archives" class="ace-subtab-link" data-target-tab="templates" data-target-group="tpl-custom-archives"><span class="dashicons dashicons-archive" aria-hidden="true"></span>Custom Archives</a>
                </div>
                <a href="#social" class="nav-tab"><span class="dashicons dashicons-share" aria-hidden="true"></span>Social</a>
                <div class="ace-tab-subnav" data-tab="social">
                    <a href="#social-sharing" class="ace-subtab-link" data-target-tab="social" data-target-group="social-sharing"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span>Sharing</a>
                    <a href="#social-webmaster" class="ace-subtab-link" data-target-tab="social" data-target-group="social-webmaster"><span class="dashicons dashicons-shield" aria-hidden="true"></span>Webmaster Tools</a>
                </div>
                <a href="#schema" class="nav-tab"><span class="dashicons dashicons-networking" aria-hidden="true"></span>Schema</a>
                <div class="ace-tab-subnav" data-tab="schema">
                    <a href="#schema-entity" class="ace-subtab-link" data-target-tab="schema" data-target-group="schema-entity"><span class="dashicons dashicons-id" aria-hidden="true"></span>Entity Type</a>
                    <a href="#schema-organization" class="ace-subtab-link" data-target-tab="schema" data-target-group="schema-organization"><span class="dashicons dashicons-building" aria-hidden="true"></span>Organization</a>
                    <a href="#schema-person" class="ace-subtab-link" data-target-tab="schema" data-target-group="schema-person"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span>Person</a>
                </div>
                <a href="#sitemaps" class="nav-tab"><span class="dashicons dashicons-networking" aria-hidden="true"></span>Sitemaps</a>
                <div class="ace-tab-subnav" data-tab="sitemaps">
                    <a href="#sitemaps-routing" class="ace-subtab-link" data-target-tab="sitemaps" data-target-group="sitemaps-routing"><span class="dashicons dashicons-randomize" aria-hidden="true"></span>Routing</a>
                    <a href="#sitemaps-providers" class="ace-subtab-link" data-target-tab="sitemaps" data-target-group="sitemaps-providers"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>Providers</a>
                    <a href="#sitemaps-display" class="ace-subtab-link" data-target-tab="sitemaps" data-target-group="sitemaps-display"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>Display</a>
                    <a href="#sitemaps-cache" class="ace-subtab-link" data-target-tab="sitemaps" data-target-group="sitemaps-cache"><span class="dashicons dashicons-database" aria-hidden="true"></span>Caching</a>
                </div>
                <a href="#advanced" class="nav-tab"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>Advanced</a>
                <div class="ace-tab-subnav" data-tab="advanced">
                    <a href="#advanced-core" class="ace-subtab-link" data-target-tab="advanced" data-target-group="advanced-core"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>Core Features</a>
                </div>
                <a href="#ai" class="nav-tab"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>AI Features</a>
                <div class="ace-tab-subnav" data-tab="ai">
                    <a href="#ai-core" class="ace-subtab-link" data-target-tab="ai" data-target-group="ai-core"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>Core Access</a>
                    <a href="#ai-automation" class="ace-subtab-link" data-target-tab="ai" data-target-group="ai-automation"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>Automation</a>
                </div>
                <a href="#performance" class="nav-tab"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span>Performance</a>
                <div class="ace-tab-subnav" data-tab="performance">
                    <a href="#performance-tracking" class="ace-subtab-link" data-target-tab="performance" data-target-group="performance-tracking"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span>Tracking</a>
                    <a href="#performance-pagespeed" class="ace-subtab-link" data-target-tab="performance" data-target-group="performance-pagespeed"><span class="dashicons dashicons-performance" aria-hidden="true"></span>PageSpeed</a>
                </div>
                <a href="#tools" class="nav-tab"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>Tools</a>
                <div class="ace-tab-subnav" data-tab="tools">
                    <a href="#tools-database" class="ace-subtab-link" data-target-tab="tools" data-target-group="tools-database"><span class="dashicons dashicons-database" aria-hidden="true"></span>Database</a>
                    <a href="#tools-dashboard-cache" class="ace-subtab-link" data-target-tab="tools" data-target-group="tools-dashboard-cache"><span class="dashicons dashicons-update" aria-hidden="true"></span>Dashboard Cache</a>
                    <a href="#tools-roadmap" class="ace-subtab-link" data-target-tab="tools" data-target-group="tools-roadmap"><span class="dashicons dashicons-info" aria-hidden="true"></span>Roadmap</a>
                </div>
                <?php if ($has_yoast_data) : ?>
                <a href="#yoast" class="nav-tab"><span class="dashicons dashicons-backup" aria-hidden="true"></span>Yoast</a>
                <div class="ace-tab-subnav" data-tab="yoast">
                    <a href="#yoast-migration" class="ace-subtab-link" data-target-tab="yoast" data-target-group="yoast-migration"><span class="dashicons dashicons-database-import" aria-hidden="true"></span>Migration</a>
                    <a href="#yoast-keys" class="ace-subtab-link" data-target-tab="yoast" data-target-group="yoast-keys"><span class="dashicons dashicons-list-view" aria-hidden="true"></span>Meta Keys</a>
                </div>
                <?php endif; ?>
            </nav>
        </div>
        
        <!-- Main Content Area -->
        <div class="ace-redis-content">

            <?php settings_errors(); ?>
            <!-- Settings Success/Error Messages -->
            <div id="ace-redis-messages" style="display: none;"></div>
            
            <form id="ace-redis-settings-form" method="post" class="ace-redis-form">
                <?php wp_nonce_field('ace_seo_admin_nonce', 'ace_seo_nonce'); ?>
                
                <!-- General Tab -->
                <div id="general" class="tab-content active">
                    <h2>General Settings</h2>
                    
                    <div class="settings-form">
                        <fieldset id="general-core" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>Core Metadata</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="separator">Title Separator</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="separator" id="separator" value="<?php echo esc_attr($options['general']['separator'] ?? '|'); ?>" class="regular-text" />
                                <p class="description">Character used to separate title parts</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="site_name">Site Name</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="site_name" id="site_name" value="<?php echo esc_attr($options['general']['site_name'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your site name for SEO titles</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="home_title">Homepage Title</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="home_title" id="home_title" value="<?php echo esc_attr($options['general']['home_title'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Custom title for your homepage</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="home_description">Homepage Description</label>
                            </div>
                            <div class="setting-field">
                                <textarea name="home_description" id="home_description" rows="3" class="large-text"><?php echo esc_textarea($options['general']['home_description'] ?? ''); ?></textarea>
                                <p class="description">Meta description for your homepage</p>
                            </div>
                        </div>
                        </fieldset>
                    </div>
                </div>

                <!-- Templates Tab -->
                <div id="templates" class="tab-content">
                    <h2>Title & Meta Templates</h2>
                    
                    <div class="settings-form">
                        <fieldset id="tpl-content-types" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-media-document" aria-hidden="true"></span>Content Types</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="title_template_post">Post Title Template</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="title_template_post" id="title_template_post" value="<?php echo esc_attr($options['templates']['title_template_post'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Template for post titles</p>
                                <?php $render_template_tokens('title_template_post', 'post_title'); ?>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="meta_template_post">Post Meta Description Template</label>
                            </div>
                            <div class="setting-field">
                                <textarea name="meta_template_post" id="meta_template_post" rows="2" class="large-text"><?php echo esc_textarea($options['templates']['meta_template_post'] ?? ''); ?></textarea>
                                <p class="description">Template for post meta descriptions</p>
                                <?php $render_template_tokens('meta_template_post', 'post_meta'); ?>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="title_template_page">Page Title Template</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="title_template_page" id="title_template_page" value="<?php echo esc_attr($options['templates']['title_template_page'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Template for page titles</p>
                                <?php $render_template_tokens('title_template_page', 'page_title'); ?>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="title_template_category">Category Title Template</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="title_template_category" id="title_template_category" value="<?php echo esc_attr($options['templates']['title_template_category'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Template for category archive titles</p>
                                <?php $render_template_tokens('title_template_category', 'category_title'); ?>
                            </div>
                        </div>
                        </fieldset>

                        <fieldset id="tpl-special-pages" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-admin-page" aria-hidden="true"></span>Special Pages</legend>
                        <?php $special_template_keys = ['archive', 'search', 'author', 'category', 'tag', 'date']; ?>
                        <?php foreach ($special_template_keys as $template_type): ?>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="title_template_<?php echo esc_attr($template_type); ?>"><?php echo esc_html(ucfirst($template_type)); ?> Title Template</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="title_template_<?php echo esc_attr($template_type); ?>" id="title_template_<?php echo esc_attr($template_type); ?>" value="<?php echo esc_attr($options['templates']['title_template_' . $template_type] ?? ''); ?>" class="regular-text" />
                                <?php
                                $title_token_context = [
                                    'archive' => 'archive_title',
                                    'search' => 'search_title',
                                    'author' => 'author_title',
                                    'category' => 'category_title',
                                    'tag' => 'tag_title',
                                    'date' => 'date_title',
                                ][$template_type] ?? 'default';
                                $render_template_tokens('title_template_' . $template_type, $title_token_context);
                                ?>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="meta_template_<?php echo esc_attr($template_type); ?>"><?php echo esc_html(ucfirst($template_type)); ?> Meta Template</label>
                            </div>
                            <div class="setting-field">
                                <textarea name="meta_template_<?php echo esc_attr($template_type); ?>" id="meta_template_<?php echo esc_attr($template_type); ?>" rows="2" class="large-text"><?php echo esc_textarea($options['templates']['meta_template_' . $template_type] ?? ''); ?></textarea>
                                <?php
                                $meta_token_context = [
                                    'archive' => 'archive_meta',
                                    'search' => 'search_meta',
                                    'author' => 'author_meta',
                                    'category' => 'category_meta',
                                    'tag' => 'tag_meta',
                                    'date' => 'date_meta',
                                ][$template_type] ?? 'default';
                                $render_template_tokens('meta_template_' . $template_type, $meta_token_context);
                                ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </fieldset>

                        <fieldset id="tpl-custom-archives" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-archive" aria-hidden="true"></span>Custom Post Type Archives</legend>
                        <?php $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects'); ?>
                        <?php foreach ($custom_post_types as $post_type): ?>
                            <?php if (!$post_type->has_archive) continue; ?>
                            <div class="setting-row">
                                <div class="setting-label">
                                    <label for="title_template_archive_<?php echo esc_attr($post_type->name); ?>"><?php echo esc_html($post_type->labels->name); ?> Archive Title</label>
                                </div>
                                <div class="setting-field">
                                    <input type="text" name="title_template_archive_<?php echo esc_attr($post_type->name); ?>" id="title_template_archive_<?php echo esc_attr($post_type->name); ?>" value="<?php echo esc_attr($options['templates']['title_template_archive_' . $post_type->name] ?? ''); ?>" class="regular-text" />
                                    <?php $render_template_tokens('title_template_archive_' . $post_type->name, 'post_type_archive_title'); ?>
                                </div>
                            </div>
                            <div class="setting-row">
                                <div class="setting-label">
                                    <label for="meta_template_archive_<?php echo esc_attr($post_type->name); ?>"><?php echo esc_html($post_type->labels->name); ?> Archive Meta</label>
                                </div>
                                <div class="setting-field">
                                    <textarea name="meta_template_archive_<?php echo esc_attr($post_type->name); ?>" id="meta_template_archive_<?php echo esc_attr($post_type->name); ?>" rows="2" class="large-text"><?php echo esc_textarea($options['templates']['meta_template_archive_' . $post_type->name] ?? ''); ?></textarea>
                                    <?php $render_template_tokens('meta_template_archive_' . $post_type->name, 'post_type_archive_meta'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </fieldset>
                    </div>
                </div>

                <!-- Social Tab -->
                <div id="social" class="tab-content">
                    <h2>Social Media</h2>
                    
                    <div class="settings-form">
                        <fieldset id="social-sharing" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-megaphone" aria-hidden="true"></span>Sharing</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="facebook_app_id">Facebook App ID</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="facebook_app_id" id="facebook_app_id" value="<?php echo esc_attr($options['social']['facebook_app_id'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Facebook App ID for Open Graph meta tags</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="default_image">Default Social Image</label>
                            </div>
                            <div class="setting-field">
                                <input type="url" name="default_image" id="default_image" value="<?php echo esc_attr($options['social']['default_image'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Default image URL for social media sharing</p>
                            </div>
                        </div>
                        </fieldset>

                        <fieldset id="social-webmaster" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-shield" aria-hidden="true"></span>Webmaster Tools</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="webmaster_google">Google Verification</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="webmaster_google" id="webmaster_google" value="<?php echo esc_attr($options['webmaster']['google'] ?? ''); ?>" class="regular-text" />
                            </div>
                        </div>

                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="webmaster_bing">Bing Verification</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="webmaster_bing" id="webmaster_bing" value="<?php echo esc_attr($options['webmaster']['bing'] ?? ''); ?>" class="regular-text" />
                            </div>
                        </div>

                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="webmaster_pinterest">Pinterest Verification</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="webmaster_pinterest" id="webmaster_pinterest" value="<?php echo esc_attr($options['webmaster']['pinterest'] ?? ''); ?>" class="regular-text" />
                            </div>
                        </div>

                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="webmaster_ahrefs">Ahrefs Verification</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="webmaster_ahrefs" id="webmaster_ahrefs" value="<?php echo esc_attr($options['webmaster']['ahrefs'] ?? ''); ?>" class="regular-text" />
                            </div>
                        </div>

                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="webmaster_yandex">Yandex Verification</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="webmaster_yandex" id="webmaster_yandex" value="<?php echo esc_attr($options['webmaster']['yandex'] ?? ''); ?>" class="regular-text" />
                            </div>
                        </div>

                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="webmaster_baidu">Baidu Verification</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="webmaster_baidu" id="webmaster_baidu" value="<?php echo esc_attr($options['webmaster']['baidu'] ?? ''); ?>" class="regular-text" />
                            </div>
                        </div>
                        </fieldset>
                    </div>
                </div>

                <!-- Schema Tab -->
                <div id="schema" class="tab-content">
                    <h2>Schema Markup</h2>
                    
                    <div class="settings-form">
                        <fieldset id="schema-entity" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-id" aria-hidden="true"></span>Entity Type</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="organization_type">Entity Type</label>
                            </div>
                            <div class="setting-field">
                                <label><input type="radio" name="organization_type" value="organization" <?php checked(($options['organization']['type'] ?? 'organization'), 'organization'); ?> /> Organization</label>
                                <label style="margin-left:12px;"><input type="radio" name="organization_type" value="person" <?php checked(($options['organization']['type'] ?? 'organization'), 'person'); ?> /> Person</label>
                            </div>
                        </div>
                        </fieldset>

                        <fieldset id="schema-organization" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-building" aria-hidden="true"></span>Organization</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="organization_name">Organization Name</label>
                            </div>
                            <div class="setting-field">
                                <input type="text" name="organization_name" id="organization_name" value="<?php echo esc_attr($options['organization']['name'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your organization name for schema markup</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="organization_url">Organization URL</label>
                            </div>
                            <div class="setting-field">
                                <input type="url" name="organization_url" id="organization_url" value="<?php echo esc_attr($options['organization']['url'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your organization website URL</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="organization_description">Organization Description</label>
                            </div>
                            <div class="setting-field">
                                <textarea name="organization_description" id="organization_description" rows="3" class="large-text"><?php echo esc_textarea($options['organization']['description'] ?? ''); ?></textarea>
                                <p class="description">Description of your organization</p>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_legal_name">Organization Legal Name</label></div>
                            <div class="setting-field"><input type="text" name="organization_legal_name" id="organization_legal_name" value="<?php echo esc_attr($options['organization']['legal_name'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_alternate_name">Organization Alternate Name</label></div>
                            <div class="setting-field"><input type="text" name="organization_alternate_name" id="organization_alternate_name" value="<?php echo esc_attr($options['organization']['alternate_name'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_logo_id">Organization Logo ID</label></div>
                            <div class="setting-field"><input type="number" name="organization_logo_id" id="organization_logo_id" value="<?php echo esc_attr($options['organization']['logo_id'] ?? ''); ?>" class="small-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_logo_url">Organization Logo URL</label></div>
                            <div class="setting-field"><input type="url" name="organization_logo_url" id="organization_logo_url" value="<?php echo esc_attr($options['organization']['logo_url'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_contact_type">Organization Contact Type</label></div>
                            <div class="setting-field"><input type="text" name="organization_contact_type" id="organization_contact_type" value="<?php echo esc_attr($options['organization']['contact_type'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_contact_phone">Organization Contact Phone</label></div>
                            <div class="setting-field"><input type="text" name="organization_contact_phone" id="organization_contact_phone" value="<?php echo esc_attr($options['organization']['contact_phone'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_contact_email">Organization Contact Email</label></div>
                            <div class="setting-field"><input type="email" name="organization_contact_email" id="organization_contact_email" value="<?php echo esc_attr($options['organization']['contact_email'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_contact_url">Organization Contact URL</label></div>
                            <div class="setting-field"><input type="url" name="organization_contact_url" id="organization_contact_url" value="<?php echo esc_attr($options['organization']['contact_url'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_twitter_username">Organization Twitter Username</label></div>
                            <div class="setting-field"><input type="text" name="organization_twitter_username" id="organization_twitter_username" value="<?php echo esc_attr(ltrim($options['organization']['twitter_username'] ?? '', '@')); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_social_facebook">Organization Facebook URL</label></div>
                            <div class="setting-field"><input type="url" name="organization_social_facebook" id="organization_social_facebook" value="<?php echo esc_attr($options['organization']['social_facebook'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_social_instagram">Organization Instagram URL</label></div>
                            <div class="setting-field"><input type="url" name="organization_social_instagram" id="organization_social_instagram" value="<?php echo esc_attr($options['organization']['social_instagram'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_social_linkedin">Organization LinkedIn URL</label></div>
                            <div class="setting-field"><input type="url" name="organization_social_linkedin" id="organization_social_linkedin" value="<?php echo esc_attr($options['organization']['social_linkedin'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="organization_social_youtube">Organization YouTube URL</label></div>
                            <div class="setting-field"><input type="url" name="organization_social_youtube" id="organization_social_youtube" value="<?php echo esc_attr($options['organization']['social_youtube'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        </fieldset>

                        <fieldset id="schema-person" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-admin-users" aria-hidden="true"></span>Person</legend>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_name">Person Name</label></div>
                            <div class="setting-field"><input type="text" name="person_name" id="person_name" value="<?php echo esc_attr($options['person']['name'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_job_title">Person Job Title</label></div>
                            <div class="setting-field"><input type="text" name="person_job_title" id="person_job_title" value="<?php echo esc_attr($options['person']['job_title'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_url">Person URL</label></div>
                            <div class="setting-field"><input type="url" name="person_url" id="person_url" value="<?php echo esc_attr($options['person']['url'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_description">Person Description</label></div>
                            <div class="setting-field"><textarea name="person_description" id="person_description" rows="2" class="large-text"><?php echo esc_textarea($options['person']['description'] ?? ''); ?></textarea></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_image_id">Person Image ID</label></div>
                            <div class="setting-field"><input type="number" name="person_image_id" id="person_image_id" value="<?php echo esc_attr($options['person']['image_id'] ?? ''); ?>" class="small-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_image_url">Person Image URL</label></div>
                            <div class="setting-field"><input type="url" name="person_image_url" id="person_image_url" value="<?php echo esc_attr($options['person']['image_url'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_twitter_username">Person Twitter Username</label></div>
                            <div class="setting-field"><input type="text" name="person_twitter_username" id="person_twitter_username" value="<?php echo esc_attr(ltrim($options['person']['twitter_username'] ?? '', '@')); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-label"><label for="person_same_as_0">Person Profile URL</label></div>
                            <div class="setting-field"><input type="url" name="person_same_as[]" id="person_same_as_0" value="<?php echo esc_attr(!empty($options['person']['same_as'][0]) ? $options['person']['same_as'][0] : ''); ?>" class="regular-text" /></div>
                        </div>
                        </fieldset>
                    </div>
                </div>

                <!-- Sitemaps Tab -->
                <div id="sitemaps" class="tab-content">
                    <h2>Sitemaps</h2>

                    <div class="settings-form">
                        <fieldset id="sitemaps-routing" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-randomize" aria-hidden="true"></span>Routing</legend>
                        <div class="setting-row">
                            <div class="setting-label"><label for="sitemap_enable_custom_routes">Clean sitemap URLs</label></div>
                            <div class="setting-field">
                                <input type="checkbox" name="ace_sitemap_powertools_options[enable_custom_routes]" id="sitemap_enable_custom_routes" value="1" <?php checked(!empty($sitemap_options['enable_custom_routes'])); ?> />
                                <p class="description">Enable clean sitemap URLs (e.g. /sitemap.xml, /news-1.xml).</p>
                                <?php if (function_exists('ace_sitemap_powertools_clean_urls_description')) : ?>
                                    <?php echo wp_kses_post(ace_sitemap_powertools_clean_urls_description()); ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="setting-row"><div class="setting-label"><label for="sitemap_serve_custom_routes">Serve clean routes</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[serve_custom_routes]" id="sitemap_serve_custom_routes" value="1" <?php checked(!empty($sitemap_options['serve_custom_routes'])); ?> /><p class="description">Serve clean routes directly (fallback if rewrites fail).</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_legacy_redirects">Legacy redirects</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_legacy_redirects]" id="sitemap_enable_legacy_redirects" value="1" <?php checked(!empty($sitemap_options['enable_legacy_redirects'])); ?> /><p class="description">Redirect legacy wp-sitemap URLs to clean URLs.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_disable_canonical_redirects">Disable canonical redirects</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[disable_canonical_redirects]" id="sitemap_disable_canonical_redirects" value="1" <?php checked(!empty($sitemap_options['disable_canonical_redirects'])); ?> /><p class="description">Prevent canonical redirects for sitemap URLs.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_root_tag_archive_fallback">Root tag archive fallback</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_root_tag_archive_fallback]" id="sitemap_enable_root_tag_archive_fallback" value="1" <?php checked(!empty($sitemap_options['enable_root_tag_archive_fallback'])); ?> /><p class="description">Allow root-level tag archives like <code>/slug/</code> when there is no slug collision.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_root_tag_links">Root tag links</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_root_tag_links]" id="sitemap_enable_root_tag_links" value="1" <?php checked(!empty($sitemap_options['enable_root_tag_links'])); ?> /><p class="description">Generate root-level tag links when a tag slug is safe to use.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_tag_base_redirect">Tag base redirect</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_tag_base_redirect]" id="sitemap_enable_tag_base_redirect" value="1" <?php checked(!empty($sitemap_options['enable_tag_base_redirect'])); ?> /><p class="description">Redirect legacy <code>/tag/slug/</code> requests to root-level tag URLs. Default off for crawl-audit friendliness.</p></div></div>
                        </fieldset>

                        <fieldset id="sitemaps-providers" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>Provider URLs</legend>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_news_provider">News URL</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_news_provider]" id="sitemap_enable_news_provider" value="1" <?php checked(!empty($sitemap_options['enable_news_provider'])); ?> /><p class="description">Rename the posts sitemap URL to /news-*.xml.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_authors_provider">Authors URL</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_authors_provider]" id="sitemap_enable_authors_provider" value="1" <?php checked(!empty($sitemap_options['enable_authors_provider'])); ?> /><p class="description">Rename the users sitemap URL to /authors-*.xml.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_categories_route">Categories URL</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_categories_route]" id="sitemap_enable_categories_route" value="1" <?php checked(!empty($sitemap_options['enable_categories_route'])); ?> /><p class="description">Rename the categories sitemap URL to /categories-*.xml.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_tags_route">Tags URL</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_tags_route]" id="sitemap_enable_tags_route" value="1" <?php checked(!empty($sitemap_options['enable_tags_route'])); ?> /><p class="description">Rename the tags sitemap URL to /tags-*.xml.</p></div></div>
                        </fieldset>

                        <fieldset id="sitemaps-display" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-visibility" aria-hidden="true"></span>Display</legend>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_custom_header">Header branding</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_custom_header]" id="sitemap_enable_custom_header" value="1" <?php checked(!empty($sitemap_options['enable_custom_header'])); ?> /><p class="description">Show logo, title, and description in the XSL header.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_index_link">Index link</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_index_link]" id="sitemap_enable_index_link" value="1" <?php checked(!empty($sitemap_options['enable_index_link'])); ?> /><p class="description">Show a link back to the sitemap index on sub-sitemaps.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_recent_marker">Most recent marker</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_recent_marker]" id="sitemap_enable_recent_marker" value="1" <?php checked(!empty($sitemap_options['enable_recent_marker'])); ?> /><p class="description">Highlight page 1 as “most recent” when a sitemap has 5+ pages.</p></div></div>
                        </fieldset>

                        <fieldset id="sitemaps-cache" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-database" aria-hidden="true"></span>Caching</legend>
                        <?php if (!$redis_cache_present) : ?>
                        <div class="notice notice-warning inline"><p>Sitemap cache controls are available only when <strong>Ace Redis Cache</strong> is installed. Sitemap generation still works, but this plugin no longer owns persistent sitemap caching by itself.</p></div>
                        <?php elseif (!$redis_cache_available) : ?>
                        <div class="notice notice-warning inline"><p><strong>Ace Redis Cache</strong> is installed, but a persistent object cache is not active yet. These controls define Redis-backed sitemap caching and will take effect once the Redis object cache path is available.</p></div>
                        <?php else : ?>
                        <div class="notice notice-info inline"><p>Sitemap cache storage and warming are handled by <strong>Ace Redis Cache</strong>. These options act as sitemap-specific inputs for the Redis-backed cache layer.</p></div>
                        <?php endif; ?>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_post_max_urls">Post URLs per sitemap</label></div><div class="setting-field"><input type="number" name="ace_sitemap_powertools_options[post_max_urls]" id="sitemap_post_max_urls" min="0" value="<?php echo esc_attr((int)($sitemap_options['post_max_urls'] ?? 0)); ?>" class="small-text" /><p class="description">Max URLs per posts sitemap (0 = WordPress default).</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_sitemap_cache">Enable sitemap cache</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_sitemap_cache]" id="sitemap_enable_sitemap_cache" value="1" <?php checked(!empty($sitemap_options['enable_sitemap_cache']) && $redis_cache_present); ?> <?php disabled(!$redis_cache_present); ?> /><p class="description">Use Ace Redis Cache for sitemap object-cache storage and keep sitemap URLs out of Redis full-page cache.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_enable_index_lastmod">Index lastmod</label></div><div class="setting-field"><input type="checkbox" name="ace_sitemap_powertools_options[enable_index_lastmod]" id="sitemap_enable_index_lastmod" value="1" <?php checked(!empty($sitemap_options['enable_index_lastmod'])); ?> /><p class="description">Adds <code>lastmod</code> to the sitemap index for posts/pages/news entries only. Disabling reduces heavy cold-load queries on large sites while keeping per-URL <code>lastmod</code> in the actual post sitemaps.</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_cache_ttl">Sitemap cache TTL (seconds)</label></div><div class="setting-field"><input type="number" name="ace_sitemap_powertools_options[sitemap_cache_ttl]" id="sitemap_cache_ttl" min="60" value="<?php echo esc_attr((int)($sitemap_options['sitemap_cache_ttl'] ?? 600)); ?>" class="small-text" <?php disabled(!$redis_cache_present); ?> /><p class="description">TTL for Redis-backed sitemap object cache and sitemap cache headers (minimum 60 seconds).</p></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="sitemap_purge_cache">Purge sitemap cache</label></div><div class="setting-field"><?php wp_nonce_field('ace_sitemap_purge_cache', 'ace_sitemap_powertools_purge_cache_nonce'); ?><button type="button" name="ace_sitemap_powertools_purge_cache" id="sitemap_purge_cache" class="button" <?php disabled(!$redis_cache_present); ?>>Purge sitemap cache</button><p class="description">Bumps the sitemap cache version so Ace Redis Cache rebuilds sitemap objects on the next request.</p><p class="description"><?php $cache_version = function_exists('ace_sitemap_powertools_get_cache_version') ? (int) ace_sitemap_powertools_get_cache_version() : 0; $cache_ttl = isset($sitemap_options['sitemap_cache_ttl']) ? (int) $sitemap_options['sitemap_cache_ttl'] : 0; if ($cache_ttl < 60) { $cache_ttl = 60; } echo 'Cache version: <code>' . esc_html($cache_version) . '</code> · TTL: <code>' . esc_html($cache_ttl) . 's</code> · Index lastmod: <code>' . esc_html(!empty($sitemap_options['enable_index_lastmod']) ? 'on' : 'off') . '</code>'; ?></p><div id="sitemap-purge-result" class="ace-optimization-result" style="display: none; margin-top: 10px;"></div></div></div>
                        </fieldset>

                        <fieldset id="sitemaps-detected" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-search" aria-hidden="true"></span>Detected Plugin Sitemaps</legend>
                            <?php
                            $detected_provider_choices = function_exists('ace_sitemap_powertools_detected_custom_providers') ? ace_sitemap_powertools_detected_custom_providers() : [];
                            $detected_taxonomy_choices = function_exists('ace_sitemap_powertools_detected_custom_taxonomies') ? ace_sitemap_powertools_detected_custom_taxonomies() : [];
                            $detected_post_type_choices = function_exists('ace_sitemap_powertools_detected_custom_post_types') ? ace_sitemap_powertools_detected_custom_post_types() : [];
                            $excluded_providers = isset($sitemap_options['excluded_sitemap_providers']) && is_array($sitemap_options['excluded_sitemap_providers']) ? array_map('sanitize_key', $sitemap_options['excluded_sitemap_providers']) : [];
                            $excluded_taxonomies = isset($sitemap_options['excluded_sitemap_taxonomies']) && is_array($sitemap_options['excluded_sitemap_taxonomies']) ? array_map('sanitize_key', $sitemap_options['excluded_sitemap_taxonomies']) : [];
                            $excluded_post_types = isset($sitemap_options['excluded_sitemap_post_types']) && is_array($sitemap_options['excluded_sitemap_post_types']) ? array_map('sanitize_key', $sitemap_options['excluded_sitemap_post_types']) : [];
                            ?>

                            <div class="setting-row">
                                <div class="setting-label"><label>Third-party providers</label></div>
                                <div class="setting-field">
                                    <?php if (empty($detected_provider_choices)) : ?>
                                        <p class="description">No third-party sitemap providers detected.</p>
                                    <?php else : ?>
                                        <?php foreach ($detected_provider_choices as $value => $choice) : ?>
                                            <input type="hidden" name="ace_sitemap_powertools_options[detected_sitemap_providers][]" value="<?php echo esc_attr($value); ?>" />
                                            <label style="display:block;margin-bottom:8px;">
                                                <input type="checkbox" name="ace_sitemap_powertools_options[enabled_sitemap_providers][]" value="<?php echo esc_attr($value); ?>" <?php checked(!in_array(sanitize_key($value), $excluded_providers, true)); ?> />
                                                <?php echo esc_html($choice['label']); ?>
                                                <span class="description">(<?php echo esc_html($choice['description']); ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <p class="description">Unchecked providers are excluded from the sitemap index entirely.</p>
                                </div>
                            </div>

                            <div class="setting-row">
                                <div class="setting-label"><label>Custom taxonomy sitemaps</label></div>
                                <div class="setting-field">
                                    <?php if (empty($detected_taxonomy_choices)) : ?>
                                        <p class="description">No custom public taxonomies detected.</p>
                                    <?php else : ?>
                                        <?php foreach ($detected_taxonomy_choices as $value => $choice) : ?>
                                            <input type="hidden" name="ace_sitemap_powertools_options[detected_sitemap_taxonomies][]" value="<?php echo esc_attr($value); ?>" />
                                            <label style="display:block;margin-bottom:8px;">
                                                <input type="checkbox" name="ace_sitemap_powertools_options[enabled_sitemap_taxonomies][]" value="<?php echo esc_attr($value); ?>" <?php checked(!in_array(sanitize_key($value), $excluded_taxonomies, true)); ?> />
                                                <?php echo esc_html($choice['label']); ?>
                                                <span class="description">(<?php echo esc_html($choice['description']); ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <p class="description">Unchecked taxonomy sitemaps are excluded from both the sitemap index and clean routes.</p>
                                </div>
                            </div>

                            <div class="setting-row">
                                <div class="setting-label"><label>Custom post type sitemaps</label></div>
                                <div class="setting-field">
                                    <?php if (empty($detected_post_type_choices)) : ?>
                                        <p class="description">No custom public post types detected.</p>
                                    <?php else : ?>
                                        <?php foreach ($detected_post_type_choices as $value => $choice) : ?>
                                            <input type="hidden" name="ace_sitemap_powertools_options[detected_sitemap_post_types][]" value="<?php echo esc_attr($value); ?>" />
                                            <label style="display:block;margin-bottom:8px;">
                                                <input type="checkbox" name="ace_sitemap_powertools_options[enabled_sitemap_post_types][]" value="<?php echo esc_attr($value); ?>" <?php checked(!in_array(sanitize_key($value), $excluded_post_types, true)); ?> />
                                                <?php echo esc_html($choice['label']); ?>
                                                <span class="description">(<?php echo esc_html($choice['description']); ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <p class="description">Unchecked post type sitemaps are excluded from both the sitemap index and clean routes.</p>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                </div>

                <!-- Advanced Tab -->
                <div id="advanced" class="tab-content">
                    <h2>Advanced Features</h2>
                    
                    <div class="settings-form">
                        <fieldset id="advanced-core" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>Core Features</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="enable_breadcrumbs">Enable Breadcrumbs</label>
                            </div>
                            <div class="setting-field">
                                <label class="ace-switch">
                                    <input type="checkbox" name="enable_breadcrumbs" id="enable_breadcrumbs" value="1" <?php checked($options['advanced']['enable_breadcrumbs'] ?? 0); ?> />
                                    <span class="ace-slider"></span>
                                </label>
                                <p class="description">Enable breadcrumb navigation</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="clean_permalinks">Clean Permalinks</label>
                            </div>
                            <div class="setting-field">
                                <label class="ace-switch">
                                    <input type="checkbox" name="clean_permalinks" id="clean_permalinks" value="1" <?php checked($options['advanced']['clean_permalinks'] ?? 0); ?> />
                                    <span class="ace-slider"></span>
                                </label>
                            </div>
                        </div>
                        </fieldset>
                    </div>
                </div>

                <!-- AI Features Tab -->
                <div id="ai" class="tab-content">
                    <h2>AI-Powered Features</h2>
                    
                    <div class="settings-form">
                        <fieldset id="ai-core" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>Core Access</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="enable_ai_assistant">Enable AI Assistant</label>
                            </div>
                            <div class="setting-field">
                                <label class="ace-switch">
                                    <input type="checkbox" name="enable_ai_assistant" id="enable_ai_assistant" value="1" <?php checked($options['ai']['enable_ai_assistant'] ?? 0); ?> />
                                    <span class="ace-slider"></span>
                                </label>
                                <p class="description">Enable AI-powered content suggestions</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="ai_api_key">AI API Key</label>
                            </div>
                            <div class="setting-field">
                                <input type="password" name="ai_api_key" id="ai_api_key" value="<?php echo esc_attr($options['ai']['api_key'] ?? ''); ?>" class="regular-text" />
                                <p class="description">API key for AI services</p>
                            </div>
                        </div>

                        <div class="setting-row">
                            <div class="setting-label"><label for="openai_api_key">OpenAI API Key</label></div>
                            <div class="setting-field"><input type="password" name="openai_api_key" id="openai_api_key" value="<?php echo esc_attr($options['ai']['openai_api_key'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        </fieldset>

                        <fieldset id="ai-automation" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>Automation</legend>
                        <div class="setting-row"><div class="setting-label"><label for="ai_content_analysis">AI Content Analysis</label></div><div class="setting-field"><input type="checkbox" name="ai_content_analysis" id="ai_content_analysis" value="1" <?php checked($options['ai']['ai_content_analysis'] ?? 0); ?> /></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="ai_keyword_suggestions">AI Keyword Suggestions</label></div><div class="setting-field"><input type="checkbox" name="ai_keyword_suggestions" id="ai_keyword_suggestions" value="1" <?php checked($options['ai']['ai_keyword_suggestions'] ?? 0); ?> /></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="ai_content_optimization">AI Content Optimization</label></div><div class="setting-field"><input type="checkbox" name="ai_content_optimization" id="ai_content_optimization" value="1" <?php checked($options['ai']['ai_content_optimization'] ?? 0); ?> /></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="ai_image_generation">AI Image Generation</label></div><div class="setting-field"><input type="checkbox" name="ai_image_generation" id="ai_image_generation" value="1" <?php checked($options['ai']['ai_image_generation'] ?? 0); ?> /></div></div>
                        </fieldset>
                    </div>
                </div>

                <!-- Performance Tab -->
                <div id="performance" class="tab-content">
                    <h2>Performance Monitoring</h2>
                    
                    <div class="settings-form">
                        <fieldset id="performance-tracking" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-chart-line" aria-hidden="true"></span>Tracking</legend>
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="enable_performance_tracking">Enable Performance Tracking</label>
                            </div>
                            <div class="setting-field">
                                <label class="ace-switch">
                                    <input type="checkbox" name="enable_performance_tracking" id="enable_performance_tracking" value="1" <?php checked($options['performance']['enable_tracking'] ?? 1); ?> />
                                    <span class="ace-slider"></span>
                                </label>
                                <p class="description">Track SEO performance metrics</p>
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-label">
                                <label for="cache_timeout">Cache Timeout (seconds)</label>
                            </div>
                            <div class="setting-field">
                                <input type="number" name="cache_timeout" id="cache_timeout" value="<?php echo esc_attr($options['performance']['cache_timeout'] ?? 3600); ?>" min="300" max="86400" class="small-text" />
                                <p class="description">How long to cache SEO data (default: 3600 seconds)</p>
                            </div>
                        </div>
                        </fieldset>

                        <fieldset id="performance-pagespeed" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-performance" aria-hidden="true"></span>PageSpeed</legend>
                        <div class="setting-row">
                            <div class="setting-label"><label for="pagespeed_api_key">PageSpeed API Key</label></div>
                            <div class="setting-field"><input type="password" name="pagespeed_api_key" id="pagespeed_api_key" value="<?php echo esc_attr($options['performance']['pagespeed_api_key'] ?? ''); ?>" class="regular-text" /></div>
                        </div>
                        <div class="setting-row"><div class="setting-label"><label for="pagespeed_monitoring">PageSpeed Monitoring</label></div><div class="setting-field"><input type="checkbox" name="pagespeed_monitoring" id="pagespeed_monitoring" value="1" <?php checked($options['performance']['pagespeed_monitoring'] ?? 0); ?> /></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="pagespeed_alerts">PageSpeed Alerts</label></div><div class="setting-field"><input type="checkbox" name="pagespeed_alerts" id="pagespeed_alerts" value="1" <?php checked($options['performance']['pagespeed_alerts'] ?? 0); ?> /></div></div>
                        <div class="setting-row"><div class="setting-label"><label for="core_web_vitals">Core Web Vitals</label></div><div class="setting-field"><input type="checkbox" name="core_web_vitals" id="core_web_vitals" value="1" <?php checked($options['performance']['core_web_vitals'] ?? 0); ?> /></div></div>
                        </fieldset>
                    </div>
                </div>

                <!-- Tools Tab -->
                <div id="tools" class="tab-content">
                    <h2>Tools</h2>

                    <div class="settings-form">
                        <fieldset id="tools-database" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-database" aria-hidden="true"></span>Database Optimization</legend>
                            <p>Optimize your database with strategic indexes for faster SEO queries. This is the same optimization that runs on plugin activation.</p>

                            <?php
                            if (class_exists('ACE_SEO_Database_Optimizer')) {
                                $db_optimizer = new ACE_SEO_Database_Optimizer();
                                $analysis = $db_optimizer->analyze_performance();

                                echo '<p><strong>Current Database Status:</strong></p>';
                                echo '<ul>';
                                echo '<li>SEO Meta Records: ' . number_format($analysis['seo_meta_records']) . '</li>';
                                echo '<li>Total Meta Records: ' . number_format($analysis['postmeta_records']) . '</li>';
                                echo '<li>Active SEO Indexes: ' . count(array_filter($analysis['existing_indexes'], function($index) {
                                    return strpos($index, 'ace_seo') === 0;
                                })) . '</li>';

                                $last_optimized = get_option('ace_seo_db_optimized', false);
                                if ($last_optimized) {
                                    echo '<li>Last Optimized: ' . human_time_diff(strtotime($last_optimized), current_time('timestamp')) . ' ago</li>';
                                }
                                echo '</ul>';

                                if (!empty($analysis['recommendations'])) {
                                    echo '<div class="notice notice-warning inline"><p><strong>Recommendations:</strong></p><ul>';
                                    foreach ($analysis['recommendations'] as $recommendation) {
                                        echo '<li>' . esc_html($recommendation) . '</li>';
                                    }
                                    echo '</ul></div>';
                                }
                            } else {
                                echo '<p><em>Database optimizer not available.</em></p>';
                            }
                            ?>

                            <p><strong>What this does:</strong></p>
                            <ul>
                                <li>Creates strategic database indexes for optimal SEO query performance</li>
                                <li>Speeds up dashboard loading on large sites</li>
                                <li>Reduces database CPU spikes during SEO operations</li>
                                <li>Safe operation - only affects database indexes</li>
                            </ul>

                            <button type="button" id="ace-optimize-db-btn" class="button button-primary">
                                Optimize Database Performance
                            </button>

                            <div id="ace-db-optimization-result" class="ace-optimization-result" style="display: none; margin-top: 15px;"></div>
                        </fieldset>

                        <fieldset id="tools-dashboard-cache" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-update" aria-hidden="true"></span>Dashboard Cache</legend>
                            <p>Manage dashboard statistics cache to prevent 504 timeouts on large sites. Statistics are cached for 1 hour to improve performance.</p>

                            <?php
                            if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                                require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
                            }

                            $cache_status = ACE_SEO_Dashboard_Cache::get_cache_status();
                            ?>

                            <p><strong>Cache Status:</strong></p>
                            <ul class="ace-cache-status-list">
                                <li>Statistics Cache:
                                    <?php if ($cache_status['stats_cached']): ?>
                                        ✅ Active (<?php echo human_time_diff(time() - $cache_status['stats_age']); ?> old)
                                    <?php else: ?>
                                        ⏳ Not generated yet - click "Refresh Dashboard Cache" to generate
                                    <?php endif; ?>
                                </li>
                                <li>Recent Posts Cache:
                                    <?php if ($cache_status['recent_cached']): ?>
                                        ✅ Active
                                    <?php else: ?>
                                        ⏳ Not generated yet - will be created with statistics cache
                                    <?php endif; ?>
                                </li>
                                <li>Cache Duration: <?php echo human_time_diff(0, $cache_status['cache_duration']); ?></li>
                            </ul>

                            <?php if (isset($cache_status['needs_generation']) && $cache_status['needs_generation']): ?>
                            <div class="notice notice-info ace-cache-info-notice">
                                <p><strong>ℹ️ Cache Not Generated:</strong> The dashboard cache hasn't been created yet. Click "Refresh Dashboard Cache" to generate it and improve dashboard performance. The cache will also be automatically created the first time you visit the ACE SEO Dashboard.</p>
                            </div>
                            <?php endif; ?>

                            <p><strong>What this does:</strong></p>
                            <ul>
                                <li>Caches expensive dashboard queries for 1 hour</li>
                                <li>Prevents 504 timeouts when loading ACE SEO dashboard</li>
                                <li>Automatically clears when posts are updated</li>
                                <li>Safe operation - only affects dashboard display speed</li>
                            </ul>

                            <button type="button" id="ace-refresh-cache-btn" class="button button-secondary">
                                Refresh Dashboard Cache
                            </button>
                            <button type="button" id="ace-clear-cache-btn" class="button button-secondary" style="margin-left: 10px;">
                                Clear Cache
                            </button>

                            <div id="ace-cache-result" class="ace-optimization-result" style="display: none; margin-top: 15px;"></div>
                        </fieldset>

                        <fieldset id="tools-roadmap" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-info" aria-hidden="true"></span>Additional Tools</legend>
                            <p>More SEO utilities:</p>
                            <ul>
                                <li>✅ Data Migration (see Yoast tab when available)</li>
                                <li>✅ Database Optimization (above)</li>
                                <li>🔄 Bulk SEO optimization (coming soon)</li>
                                <li>🔄 Content analysis reports (coming soon)</li>
                                <li>🔄 Broken link checker (coming soon)</li>
                                <li>🔄 Redirect manager (coming soon)</li>
                                <li>🔄 SEO audit tool (coming soon)</li>
                            </ul>
                        </fieldset>
                    </div>
                </div>

                <?php if ($has_yoast_data) : ?>
                <!-- Yoast Tab -->
                <div id="yoast" class="tab-content">
                    <h2>Yoast Tools</h2>

                    <div class="settings-form">
                        <fieldset id="yoast-migration" class="ace-settings-group">
                            <legend><span class="dashicons dashicons-database-import" aria-hidden="true"></span>Data Migration</legend>
                            <p>If you have existing Yoast SEO data, you can migrate it to Ace SEO. This will copy your SEO titles, meta descriptions, focus keywords, and other settings while preserving your original Yoast data.</p>

                            <div id="ace-migration-status">
                                <p><strong>Status:</strong> <span id="migration-status-text">Loading...</span></p>
                                <div id="migration-stats"></div>
                            </div>

                            <div id="ace-migration-controls">
                                <button type="button" id="start-migration-btn" class="button button-primary" disabled>
                                    Start Migration
                                </button>
                                <button type="button" id="pause-migration-btn" class="button button-secondary" style="display: none;">
                                    Pause Migration
                                </button>
                                <button type="button" id="resume-migration-btn" class="button button-secondary" style="display: none;">
                                    Resume Migration
                                </button>
                                <button type="button" id="cancel-migration-btn" class="button" style="display: none;">
                                    Cancel
                                </button>
                            </div>

                            <div id="ace-migration-progress" style="display: none;">
                                <div class="migration-progress-container">
                                    <div class="migration-progress-bar">
                                        <div class="migration-progress-fill" style="width: 0%;"></div>
                                    </div>
                                    <div class="migration-progress-text">
                                        <span id="migration-progress-current">0</span> /
                                        <span id="migration-progress-total">0</span> items
                                        (<span id="migration-progress-percent">0</span>%)
                                    </div>
                                </div>

                                <div id="migration-current-item" class="migration-current-item"></div>

                                <div id="migration-log" class="migration-log">
                                    <h4>Migration Log:</h4>
                                    <div id="migration-log-content"></div>
                                </div>
                            </div>

                            <div id="ace-migration-results" style="display: none;">
                                <h4>Migration Results:</h4>
                                <div id="migration-results-content"></div>
                            </div>
                        </fieldset>

                        <fieldset id="yoast-keys" class="ace-settings-group" data-yoast-tools="1" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-yoast-nonce="<?php echo esc_attr(wp_create_nonce('ace_seo_yoast_tools')); ?>">
                            <legend><span class="dashicons dashicons-list-view" aria-hidden="true"></span>Yoast Meta Keys</legend>
                            <p>View remaining Yoast meta keys and optionally clean or rebuild them from ACE meta.</p>
                            <p>
                                Post meta keys: <strong id="ace-yoast-count-postmeta">—</strong><br>
                                Term meta keys: <strong id="ace-yoast-count-termmeta">—</strong><br>
                                ACE post meta keys: <strong id="ace-yoast-count-ace-postmeta">—</strong><br>
                                ACE term meta keys: <strong id="ace-yoast-count-ace-termmeta">—</strong>
                            </p>
                            <p id="ace-yoast-ajax-status" class="description" style="display:none;"></p>
                            <p class="description">Counts are current on page load; use refresh if needed.</p>
                            <p>
                                <button type="button" class="button" id="ace-yoast-refresh" onclick="window.aceYoastRefresh && window.aceYoastRefresh(); return false;">Refresh Counts</button>
                                <button type="button" class="button button-secondary" id="ace-yoast-delete">Remove Yoast Keys</button>
                                <button type="button" class="button button-primary" id="ace-yoast-recreate">Recreate Yoast Keys from ACE</button>
                            </p>
                            <p class="description">Recreate will remove existing Yoast keys first, then rebuild from ACE meta.</p>
                        </fieldset>
                        <script>
                        (function(){
                            var fieldset = document.getElementById('yoast-keys');
                            if (!fieldset) return;
                            var ajaxUrl = fieldset.getAttribute('data-ajax-url') || '';
                            var nonce = fieldset.getAttribute('data-yoast-nonce') || '';
                            var statusEl = document.getElementById('ace-yoast-ajax-status');
                            var setStatus = function(msg){
                                if (!statusEl) return;
                                statusEl.textContent = msg;
                                statusEl.style.display = msg ? 'block' : 'none';
                            };

                            var updateCounts = function(data){
                                var byId = function(id){ return document.getElementById(id); };
                                var postmeta = byId('ace-yoast-count-postmeta');
                                var termmeta = byId('ace-yoast-count-termmeta');
                                var acePost = byId('ace-yoast-count-ace-postmeta');
                                var aceTerm = byId('ace-yoast-count-ace-termmeta');
                                var postVal = (data && data.postmeta != null) ? data.postmeta : '0';
                                var termVal = (data && data.termmeta != null) ? data.termmeta : '0';
                                var acePostVal = (data && data.ace_postmeta != null) ? data.ace_postmeta : '0';
                                var aceTermVal = (data && data.ace_termmeta != null) ? data.ace_termmeta : '0';
                                if (postmeta) postmeta.textContent = postVal;
                                if (termmeta) termmeta.textContent = termVal;
                                if (acePost) acePost.textContent = acePostVal;
                                if (aceTerm) aceTerm.textContent = aceTermVal;
                            };

                            var postAjax = function(action, extra){
                                if (!ajaxUrl) {
                                    setStatus('AJAX URL missing.');
                                    return Promise.reject();
                                }
                                var formData = new FormData();
                                formData.append('action', action);
                                formData.append('nonce', nonce);
                                if (extra) {
                                    Object.keys(extra).forEach(function(key){
                                        formData.append(key, extra[key]);
                                    });
                                }
                                return fetch(ajaxUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    body: formData
                                }).then(function(resp){ return resp.json(); });
                            };

                            var refreshCounts = function(){
                                setStatus('Loading counts...');
                                return postAjax('ace_seo_get_yoast_key_counts').then(function(resp){
                                    if (resp && resp.success) {
                                        updateCounts(resp.data || {});
                                        setStatus('');
                                    } else {
                                        setStatus('Failed to load Yoast counts.');
                                    }
                                }).catch(function(){
                                    setStatus('Request failed.');
                                });
                            };

                            var refreshBtn = document.getElementById('ace-yoast-refresh');
                            if (refreshBtn) {
                                refreshBtn.addEventListener('click', function(e){
                                    e.preventDefault();
                                    refreshCounts();
                                });
                            }

                            var deleteBtn = document.getElementById('ace-yoast-delete');
                            if (deleteBtn) {
                                deleteBtn.addEventListener('click', function(e){
                                    e.preventDefault();
                                    if (!confirm('Remove all Yoast SEO meta keys from the database? This cannot be undone.')) {
                                        return;
                                    }
                                    postAjax('ace_seo_delete_yoast_keys').then(function(resp){
                                        if (resp && resp.success) {
                                            refreshCounts();
                                            alert('Yoast keys removed.');
                                        } else {
                                            alert('Failed to remove Yoast keys.');
                                        }
                                    });
                                });
                            }

                            var recreateBtn = document.getElementById('ace-yoast-recreate');
                            if (recreateBtn) {
                                recreateBtn.addEventListener('click', function(e){
                                    e.preventDefault();
                                    if (!confirm('Recreate Yoast SEO meta keys from ACE data? This will overwrite existing Yoast keys.')) {
                                        return;
                                    }
                                    postAjax('ace_seo_recreate_yoast_keys').then(function(resp){
                                        if (resp && resp.success) {
                                            refreshCounts();
                                            alert('Yoast keys recreated from ACE.');
                                        } else {
                                            alert('Failed to recreate Yoast keys.');
                                        }
                                    });
                                });
                            }

                            refreshCounts();
                        })();
                        </script>
                    </div>
                </div>
                <?php endif; ?>

            </form>
        </div> <!-- .ace-seo-content -->
    </div> <!-- .ace-seo-container -->
</div>

<style>
.ace-optimization-result {
    padding: 12px;
    border-radius: 4px;
    margin-top: 15px;
}
.ace-optimization-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}
.ace-optimization-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}
.ace-optimization-result.info {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
}
.notice.inline {
    display: inline-block;
    margin: 10px 0;
    padding: 10px 15px;
}
.migration-progress-container {
    margin: 20px 0;
}
.migration-progress-bar {
    width: 100%;
    height: 25px;
    background-color: #f0f0f1;
    border-radius: 3px;
    overflow: hidden;
    position: relative;
}
.migration-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #00a32a, #00ba37);
    transition: width 0.3s ease;
    position: relative;
}
.migration-progress-fill:after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    bottom: 0;
    right: 0;
    background: linear-gradient(45deg, transparent 40%, rgba(255,255,255,0.3) 50%, transparent 60%);
    animation: aceMigrationShine 1.5s infinite;
}
@keyframes aceMigrationShine {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}
.migration-progress-text {
    text-align: center;
    margin-top: 10px;
    font-weight: 600;
    color: #1d2327;
}
.migration-current-item {
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    padding: 10px;
    margin: 15px 0;
    font-family: monospace;
    font-size: 13px;
}
.migration-log {
    margin-top: 20px;
    max-height: 300px;
    overflow-y: auto;
}
.migration-log h4 {
    margin-bottom: 10px;
}
.migration-log-content {
    background: #2c3338;
    color: #f0f0f1;
    padding: 15px;
    border-radius: 4px;
    font-family: "Consolas", "Monaco", "Courier New", monospace;
    font-size: 12px;
    line-height: 1.4;
    max-height: 250px;
    overflow-y: auto;
}
.migration-log-entry {
    margin-bottom: 5px;
}
.migration-log-entry.success {
    color: #4f9c4f;
}
.migration-log-entry.error {
    color: #d63384;
}
.migration-log-entry.warning {
    color: #ffc107;
}
.migration-log-entry.info {
    color: #0dcaf0;
}
#migration-stats {
    margin: 15px 0;
    padding: 15px;
    background: #f6f7f7;
    border-left: 4px solid #0073aa;
    border-radius: 0 4px 4px 0;
}
#migration-stats ul {
    margin: 0;
    list-style: none;
}
#migration-stats li {
    padding: 5px 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
#migration-stats .stat-value {
    font-weight: 600;
    color: #0073aa;
}
</style>

<script>
jQuery(document).ready(function($) {
    var ajaxurl = window.ajaxurl || (window.ace_seo_admin && ace_seo_admin.ajax_url) || "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
    var yoastNonce = '<?php echo wp_create_nonce('ace_seo_yoast_tools'); ?>';
    var migrationStatsNonce = '<?php echo wp_create_nonce('ace_seo_migration_stats'); ?>';
    var migrationBatchNonce = '<?php echo wp_create_nonce('ace_seo_batch_migrate'); ?>';
    var optimizeDbNonce = '<?php echo wp_create_nonce('ace_seo_optimize_db_manual'); ?>';
    var adminNonce = '<?php echo wp_create_nonce('ace_seo_admin'); ?>';
    var sitemapPurgeNonce = '<?php echo wp_create_nonce('ace_sitemap_purge_cache'); ?>';

    var migrationState = {
        isRunning: false,
        isPaused: false,
        currentBatch: 0,
        totalPosts: 0,
        processedPosts: 0,
        batchSize: 10,
        totalMigrated: 0,
        errors: []
    };
    var migrationInitialized = false;

    window.aceYoastRefresh = fetchYoastCounts;

    // Kick off migration stats when the tab is first visited (or immediately if already visible).
    function initMigrationSection() {
        if (migrationInitialized || !$('#migration-status-text').length) {
            return;
        }
        migrationInitialized = true;

        // Fallback: if AJAX fails silently, surface an error and let the button be clicked.
        var fallbackTimer = setTimeout(function() {
            var statusText = ($('#migration-status-text').text() || '').trim().toLowerCase();
            if (statusText === 'loading...' || !statusText) {
                $('#migration-status-text').text('Unable to load migration stats. You can still try starting the migration.');
                $('#start-migration-btn').prop('disabled', false);
            }
        }, 5000);

        loadMigrationStats().always(function() {
            clearTimeout(fallbackTimer);
        });
    }

    // Initialize immediately if the section is in view on load, and again when the Yoast tab is clicked.
    initMigrationSection();
    $(document).on('click', 'a[href="#yoast"], .ace-subtab-link[data-target-group="yoast-migration"]', initMigrationSection);
    if ($('#ace-yoast-count-postmeta').length) {
        fetchYoastCounts();
    }

    function updateMigrationStats(stats) {
        var pendingTotal = getPendingTotal(stats);
        var statsHtml = '<ul>' +
            '<li><span>Posts with Yoast SEO data:</span><span class="stat-value">' + stats.yoast_posts + '</span></li>' +
            '<li><span>Posts with Ace SEO data:</span><span class="stat-value">' + stats.ace_posts + '</span></li>' +
            '<li><span>Taxonomies with Yoast SEO data:</span><span class="stat-value">' + (stats.yoast_taxonomies || 0) + '</span></li>' +
            '<li><span>Taxonomies with Ace SEO data:</span><span class="stat-value">' + (stats.ace_taxonomies || 0) + '</span></li>' +
            '<li><span>Posts ready to migrate:</span><span class="stat-value">' + stats.pending_migration + '</span></li>' +
            '<li><span>Taxonomies ready to migrate:</span><span class="stat-value">' + (stats.pending_tax_migration || 0) + '</span></li>' +
            '<li><span>Total items ready to migrate:</span><span class="stat-value">' + pendingTotal + '</span></li>' +
            '</ul>';
        $('#migration-stats').html(statsHtml);
    }

    function getPendingTotal(stats) {
        var pendingPosts = Number((stats && stats.pending_migration) || 0);
        var pendingTaxonomies = Number((stats && stats.pending_tax_migration) || 0);
        var pendingTotal = Number((stats && stats.pending_total) || 0);

        if (pendingTotal > 0) {
            return pendingTotal;
        }

        return pendingPosts + pendingTaxonomies;
    }

    function loadMigrationStats() {
        return $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_get_migration_stats',
                nonce: migrationStatsNonce
            }
        }).done(function(response) {
            if (response && response.success) {
                var stats = response.data || {};
                var pendingTotal = getPendingTotal(stats);
                var hasYoastData = Number(stats.yoast_posts || 0) > 0 || Number(stats.yoast_taxonomies || 0) > 0;
                var hasAceData = Number(stats.ace_posts || 0) > 0 || Number(stats.ace_taxonomies || 0) > 0;

                updateMigrationStats(stats);

                if (pendingTotal > 0) {
                    $('#start-migration-btn').prop('disabled', false);
                    $('#migration-status-text').text('Ready to migrate');
                } else if (hasAceData && hasYoastData) {
                    $('#start-migration-btn').prop('disabled', false);
                    $('#migration-status-text').text('Migration complete');
                } else if (hasAceData) {
                    $('#start-migration-btn').prop('disabled', false);
                    $('#migration-status-text').text('No pending migration (already migrated)');
                } else {
                    $('#migration-status-text').text('No Yoast SEO data found to migrate');
                    $('#start-migration-btn').prop('disabled', true);
                }
            } else {
                $('#migration-status-text').text('Error loading migration stats');
                $('#start-migration-btn').prop('disabled', false);
            }
        }).fail(function() {
            $('#migration-status-text').text('Error loading migration stats');
            $('#start-migration-btn').prop('disabled', false);
        });
    }

    function updateYoastCounts(data) {
        var postmeta = data && data.postmeta != null ? data.postmeta : '0';
        var termmeta = data && data.termmeta != null ? data.termmeta : '0';
        var acePost = data && data.ace_postmeta != null ? data.ace_postmeta : '0';
        var aceTerm = data && data.ace_termmeta != null ? data.ace_termmeta : '0';
        $('#ace-yoast-count-postmeta').text(postmeta);
        $('#ace-yoast-count-termmeta').text(termmeta);
        $('#ace-yoast-count-ace-postmeta').text(acePost);
        $('#ace-yoast-count-ace-termmeta').text(aceTerm);
    }

    function fetchYoastCounts() {
        if (!ajaxurl) {
            updateYoastCounts({ postmeta: 'error', termmeta: 'error', ace_postmeta: 'error', ace_termmeta: 'error' });
            $('#ace-yoast-ajax-status').text('AJAX URL missing.').show();
            return $.Deferred().reject().promise();
        }
        return $.post(ajaxurl, {
            action: 'ace_seo_get_yoast_key_counts',
            nonce: yoastNonce
        }).done(function(resp) {
            if (resp && resp.success) {
                updateYoastCounts(resp.data);
                $('#ace-yoast-ajax-status').hide().text('');
            } else {
                var message = resp && resp.data && resp.data.message ? resp.data.message : 'Failed to load Yoast counts.';
                $('#ace-yoast-ajax-status').text(message).show();
            }
        }).fail(function() {
            updateYoastCounts({ postmeta: 'error', termmeta: 'error', ace_postmeta: 'error', ace_termmeta: 'error' });
            $('#ace-yoast-ajax-status').text('Request failed.').show();
        });
    }

    $(document).on('click', '#ace-yoast-refresh', function() {
        fetchYoastCounts();
    });

    $(document).on('click', '#ace-yoast-delete', function() {
        if (!confirm('Remove all Yoast SEO meta keys from the database? This cannot be undone.')) {
            return;
        }
        $.post(ajaxurl, {
            action: 'ace_seo_delete_yoast_keys',
            nonce: yoastNonce
        }).done(function(resp) {
            if (resp && resp.success) {
                fetchYoastCounts();
                alert('Yoast keys removed.');
            } else {
                alert('Failed to remove Yoast keys.');
            }
        });
    });

    $(document).on('click', '#ace-yoast-recreate', function() {
        if (!confirm('Recreate Yoast SEO meta keys from ACE data? This will overwrite existing Yoast keys.')) {
            return;
        }
        $.post(ajaxurl, {
            action: 'ace_seo_recreate_yoast_keys',
            nonce: yoastNonce
        }).done(function(resp) {
            if (resp && resp.success) {
                fetchYoastCounts();
                alert('Yoast keys recreated from ACE.');
            } else {
                alert('Failed to recreate Yoast keys.');
            }
        });
    });

    function logMessage(message, type) {
        var entryType = type || 'info';
        var timestamp = new Date().toLocaleTimeString();
        var logEntry = '<div class="migration-log-entry ' + entryType + '">[' + timestamp + '] ' + message + '</div>';
        $('#migration-log-content').append(logEntry);
        var logContent = $('#migration-log-content');
        if (logContent.length) {
            logContent.scrollTop(logContent[0].scrollHeight);
        }
    }

    function updateProgress() {
        var percent = migrationState.totalPosts > 0 ?
            Math.round((migrationState.processedPosts / migrationState.totalPosts) * 100) : 0;
        $('.migration-progress-fill').css('width', percent + '%');
        $('#migration-progress-current').text(migrationState.processedPosts);
        $('#migration-progress-total').text(migrationState.totalPosts);
        $('#migration-progress-percent').text(percent);
    }

    function updateCurrentItem(item) {
        if (item) {
            var itemHtml = 'Processing: <strong>' + item.title + '</strong> (ID: ' + item.id + ', Type: ' + item.type + ')';
            $('#migration-current-item').html(itemHtml).show();
        } else {
            $('#migration-current-item').hide();
        }
    }

    function processBatch() {
        if (!migrationState.isRunning || migrationState.isPaused) {
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_batch_migrate_yoast',
                batch: migrationState.currentBatch,
                batch_size: migrationState.batchSize,
                nonce: migrationBatchNonce
            }
        }).done(function(response) {
            if (response.success) {
                var data = response.data;
                migrationState.processedPosts += data.processed;
                migrationState.totalMigrated += data.migrated;
                migrationState.currentBatch++;
                updateProgress();
                logMessage('Batch ' + migrationState.currentBatch + ': Processed ' + data.processed + ' posts, migrated ' + data.migrated + ' fields', 'success');
                if (data.current_item) {
                    updateCurrentItem(data.current_item);
                }
                if (data.errors && data.errors.length > 0) {
                    data.errors.forEach(function(error) {
                        logMessage(error, 'error');
                        migrationState.errors.push(error);
                    });
                }
                if (data.completed) {
                    completeMigration();
                } else {
                    setTimeout(processBatch, 500);
                }
            } else {
                logMessage('Error: ' + response.data, 'error');
                stopMigration();
            }
        }).fail(function(xhr, status, error) {
            logMessage('Network error: ' + error, 'error');
            stopMigration();
        });
    }

    function startMigration() {
        migrationState.isRunning = true;
        migrationState.isPaused = false;
        migrationState.currentBatch = 0;
        migrationState.processedPosts = 0;
        migrationState.totalMigrated = 0;
        migrationState.errors = [];

        $('#start-migration-btn').hide().prop('disabled', true);
        $('#pause-migration-btn, #cancel-migration-btn').show();
        $('#ace-migration-progress').show();
        $('#ace-migration-results').hide();
        $('#migration-status-text').text('Migration in progress...');
        $('#migration-log-content').empty();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_get_migration_stats',
                nonce: migrationStatsNonce
            }
        }).done(function(response) {
            if (response.success) {
                migrationState.totalPosts = getPendingTotal(response.data);

                if (migrationState.totalPosts <= 0) {
                    logMessage('No pending Yoast items were found to migrate.', 'warning');
                    completeMigration();
                    return;
                }

                updateProgress();
                logMessage('Starting migration of ' + migrationState.totalPosts + ' items...', 'info');
                processBatch();
            } else {
                logMessage('Error getting migration stats: ' + response.data, 'error');
                stopMigration();
            }
        });
    }

    function pauseMigration() {
        migrationState.isPaused = true;
        $('#pause-migration-btn').hide();
        $('#resume-migration-btn').show();
        $('#migration-status-text').text('Migration paused');
        logMessage('Migration paused by user', 'warning');
    }

    function resumeMigration() {
        migrationState.isPaused = false;
        $('#resume-migration-btn').hide();
        $('#pause-migration-btn').show();
        $('#migration-status-text').text('Migration in progress...');
        logMessage('Migration resumed', 'info');
        processBatch();
    }

    function stopMigration() {
        migrationState.isRunning = false;
        migrationState.isPaused = false;
        $('#pause-migration-btn, #resume-migration-btn, #cancel-migration-btn').hide();
        $('#start-migration-btn').show().prop('disabled', false);
        $('#migration-status-text').text('Migration stopped');
        updateCurrentItem(null);
        logMessage('Migration stopped', 'warning');
    }

    function completeMigration() {
        migrationState.isRunning = false;
        migrationState.isPaused = false;
        $('#pause-migration-btn, #resume-migration-btn, #cancel-migration-btn').hide();
        $('#start-migration-btn').show().prop('disabled', false);
        $('#migration-status-text').text('Migration completed successfully!');
        updateCurrentItem(null);
        logMessage('Migration completed! Migrated ' + migrationState.totalMigrated + ' SEO fields from ' + migrationState.processedPosts + ' posts', 'success');
        if (migrationState.errors.length > 0) {
            logMessage('Completed with ' + migrationState.errors.length + ' errors - check log above', 'warning');
        }
        showMigrationResults();
        setTimeout(loadMigrationStats, 1000);
    }

    function showMigrationResults() {
        var resultsHtml = '<div class="ace-optimization-result success">' +
            '<p><strong>Migration Summary:</strong></p>' +
            '<ul>' +
            '<li>Posts processed: ' + migrationState.processedPosts + '</li>' +
            '<li>SEO fields migrated: ' + migrationState.totalMigrated + '</li>' +
            '<li>Errors encountered: ' + migrationState.errors.length + '</li>' +
            '<li>Duration: Started at ' + new Date().toLocaleTimeString() + '</li>' +
            '</ul>' +
            (migrationState.errors.length > 0 ?
                '<p><em>Some errors occurred during migration. Check the log above for details.</em></p>' :
                '<p><em>All data migrated successfully!</em></p>') +
            '</div>';
        $('#migration-results-content').html(resultsHtml);
        $('#ace-migration-results').show();
    }

    $('#start-migration-btn').on('click', startMigration);
    $('#pause-migration-btn').on('click', pauseMigration);
    $('#resume-migration-btn').on('click', resumeMigration);
    $('#cancel-migration-btn').on('click', function() {
        if (confirm('Are you sure you want to cancel the migration? Progress will be saved and you can resume later.')) {
            stopMigration();
        }
    });

    $('#ace-optimize-db-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#ace-db-optimization-result');

        $btn.prop('disabled', true).text('Optimizing Database...');
        $result.hide().removeClass('success error info');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_optimize_database_manual',
                nonce: optimizeDbNonce
            }
        }).done(function(response) {
            if (response.success) {
                var message = '<strong>Database optimization completed!</strong><br><br>';
                var hasResults = false;

                $.each(response.data, function(table, indexes) {
                    if (Object.keys(indexes).length > 0) {
                        hasResults = true;
                        message += '<strong>' + table.replace('_', ' ').toUpperCase() + ' TABLE:</strong><br>';
                        $.each(indexes, function(index_name, result) {
                            var status = result.status === 'created' ? '✅' :
                                result.status === 'exists' ? '✓' : '⚠️';
                            message += status + ' ' + index_name + ': ' + result.message + '<br>';
                        });
                        message += '<br>';
                    }
                });

                if (!hasResults) {
                    message = '<strong>✅ Database optimization complete!</strong><br>All indexes are already optimized.';
                } else {
                    message += '<em>Page will refresh in 3 seconds to show updated statistics...</em>';
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                }

                $result.addClass('success').html(message).show();
            } else {
                $result.addClass('error').html('<strong>Error:</strong> ' + response.data).show();
            }
        }).fail(function() {
            $result.addClass('error').html('<strong>Network error:</strong> Failed to optimize database. Please try again.').show();
        }).always(function() {
            $btn.prop('disabled', false).text('Optimize Database Performance');
        });
    });

    $('#ace-refresh-cache-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#ace-cache-result');

        $btn.prop('disabled', true).text('Refreshing...');
        $result.hide().removeClass('success error info');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_refresh_dashboard_cache',
                nonce: adminNonce
            }
        }).done(function(response) {
            if (response.success) {
                var message = '<strong>Cache refreshed successfully!</strong><br>';
                message += 'Statistics: ' + response.data.stats.total_posts + ' posts, ';
                message += response.data.stats.focus_keywords + ' with keywords, ';
                message += response.data.stats.meta_descriptions + ' with descriptions<br>';
                message += 'Recent posts cached: ' + response.data.recent_posts_count;
                $result.addClass('success').html(message).show();
                updateCacheStatusDisplay(response.data.cache_status);
            } else {
                $result.addClass('error').html('<strong>Error:</strong> ' + response.data).show();
            }
        }).fail(function() {
            $result.addClass('error').html('<strong>Network error:</strong> Failed to refresh cache.').show();
        }).always(function() {
            $btn.prop('disabled', false).text('Refresh Dashboard Cache');
        });
    });

    $('#ace-clear-cache-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#ace-cache-result');

        $btn.prop('disabled', true).text('Clearing...');
        $result.hide().removeClass('success error info');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_clear_dashboard_cache',
                nonce: adminNonce
            }
        }).done(function(response) {
            if (response.success) {
                $result.addClass('success').html('<strong>Cache cleared successfully!</strong><br>' + response.data.note).show();
                updateCacheStatusDisplay(response.data.cache_status);
            } else {
                $result.addClass('error').html('<strong>Error:</strong> ' + response.data).show();
            }
        }).fail(function() {
            $result.addClass('error').html('<strong>Network error:</strong> Failed to clear cache.').show();
        }).always(function() {
            $btn.prop('disabled', false).text('Clear Cache');
        });
    });

    $('#sitemap_purge_cache').on('click', function() {
        var $btn = $(this);
        var $result = $('#sitemap-purge-result');
        if (!$btn.length) return;

        $btn.prop('disabled', true).text('Purging...');
        $result.hide().removeClass('success error info');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_sitemap_purge_cache',
                nonce: sitemapPurgeNonce
            }
        }).done(function(response) {
            if (response.success) {
                var message = '<strong>Sitemap cache cleared.</strong><br>New cache version: <code>' + response.data.cache_version + '</code>';
                $result.addClass('success').html(message).show();
            } else {
                var errorMessage = response && response.data && response.data.message ? response.data.message : response.data;
                $result.addClass('error').html('<strong>Error:</strong> ' + errorMessage).show();
            }
        }).fail(function() {
            $result.addClass('error').html('<strong>Network error:</strong> Failed to purge sitemap cache.').show();
        }).always(function() {
            $btn.prop('disabled', false).text('Purge sitemap cache');
        });
    });

    function updateCacheStatusDisplay(cacheStatus) {
        var $statusList = $('.ace-cache-status-list');
        if (!$statusList.length) return;

        var statusHtml = '<li>Statistics Cache: ';
        if (cacheStatus.stats_cached) {
            var ageText = cacheStatus.stats_age > 0 ? humanTimeDiff(cacheStatus.stats_age) + ' old' : 'just generated';
            statusHtml += '✅ Active (' + ageText + ')';
        } else {
            statusHtml += '⏳ Not generated yet - click "Refresh Dashboard Cache" to generate';
        }
        statusHtml += '</li>';

        statusHtml += '<li>Recent Posts Cache: ';
        if (cacheStatus.recent_cached) {
            statusHtml += '✅ Active';
        } else {
            statusHtml += '⏳ Not generated yet - will be created with statistics cache';
        }
        statusHtml += '</li>';

        statusHtml += '<li>Cache Duration: ' + humanTimeDiff(cacheStatus.cache_duration) + '</li>';

        $statusList.html(statusHtml);

        var $infoNotice = $('.ace-cache-info-notice');
        if (cacheStatus.needs_generation && !cacheStatus.stats_cached) {
            if (!$infoNotice.length) {
                $statusList.after('<div class="notice notice-info ace-cache-info-notice"><p><strong>ℹ️ Cache Not Generated:</strong> The dashboard cache hasn\'t been created yet. Click "Refresh Dashboard Cache" to generate it and improve dashboard performance. The cache will also be automatically created the first time you visit the ACE SEO Dashboard.</p></div>');
            }
        } else {
            $infoNotice.remove();
        }
    }

    function humanTimeDiff(seconds) {
        if (seconds < 60) return seconds + 's';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h';
        var days = Math.floor(hours / 24);
        return days + 'd';
    }
});
</script>

<script>
// Fallback initializer: if main admin JS fails to bind tabs.
(function(){
    if (window.AceCrawlEnhancerAdminFallbackApplied) return;
    window.AceCrawlEnhancerAdminFallbackApplied = true;

    var tabs = document.querySelectorAll('.ace-redis-sidebar .nav-tab');
    if (!tabs.length) return;

    function updateSubnav(tabId) {
        var subnavBlocks = document.querySelectorAll('.ace-redis-sidebar .ace-tab-subnav');
        subnavBlocks.forEach(function(block){
            block.classList.remove('active');
        });
        var activeBlock = document.querySelector('.ace-redis-sidebar .ace-tab-subnav[data-tab="' + tabId + '"]');
        if (activeBlock) activeBlock.classList.add('active');
    }

    function setActiveSubtabLink(tabId, sectionId) {
        document.querySelectorAll('.ace-subtab-link').forEach(function(link){
            link.classList.remove('is-active');
        });

        if (!tabId || !sectionId) return;

        var activeLink = document.querySelector('.ace-subtab-link[data-target-tab="' + tabId + '"][data-target-group="' + sectionId + '"]');
        if (activeLink) activeLink.classList.add('is-active');
    }

    function activateTab(targetHash) {
        if (!targetHash || targetHash.charAt(0) !== '#') return;

        document.querySelectorAll('.ace-redis-sidebar .nav-tab').forEach(function(t){
            t.classList.remove('nav-tab-active');
        });

        var activeTab = document.querySelector('.ace-redis-sidebar .nav-tab[href="' + targetHash + '"]');
        if (activeTab) activeTab.classList.add('nav-tab-active');

        document.querySelectorAll('.tab-content').forEach(function(panel){
            panel.classList.remove('active');
        });

        var panel = document.querySelector(targetHash);
        if (panel) panel.classList.add('active');

        updateSubnav(targetHash.replace('#', ''));
        setActiveSubtabLink('', '');

        if (history && history.replaceState) {
            history.replaceState(null, '', targetHash);
        }
    }

    function activateSubSection(tabId, sectionId) {
        var tabHash = '#' + tabId;
        activateTab(tabHash);

        if (!sectionId) return;

        var section = document.getElementById(sectionId);
        if (section) {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        setActiveSubtabLink(tabId, sectionId);

        if (history && history.replaceState) {
            history.replaceState(null, '', tabHash + '/' + sectionId);
        }
    }

    var anyActive = document.querySelector('.tab-content.active');
    if (!anyActive) {
        var first = tabs[0];
        var firstHref = first.getAttribute('href');
        if (firstHref) {
            activateTab(firstHref);
        }
    } else {
        var activeTab = document.querySelector('.ace-redis-sidebar .nav-tab.nav-tab-active');
        if (activeTab) {
            updateSubnav((activeTab.getAttribute('href') || '').replace('#', ''));
        }
    }

    tabs.forEach(function(tab){
        tab.addEventListener('click', function(e){
            if (e.defaultPrevented) return;
            e.preventDefault();
            activateTab(tab.getAttribute('href'));
        });
    });

    document.querySelectorAll('.ace-subtab-link').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            var tabId = link.getAttribute('data-target-tab');
            var sectionId = link.getAttribute('data-target-group');
            if (!tabId) return;
            activateSubSection(tabId, sectionId);
        });
    });

    var hash = window.location.hash || '';
    if (hash.indexOf('/') > -1) {
        var parts = hash.replace('#', '').split('/');
        if (parts.length >= 1 && parts[0]) {
            activateSubSection(parts[0], parts[1] || '');
        }
    } else if (hash) {
        activateTab(hash);
    }
})();
</script>
