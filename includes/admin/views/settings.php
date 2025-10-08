<?php
/**
 * Settings page template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle form submission
if (isset($_POST['submit']) && wp_verify_nonce($_POST['ace_seo_settings_nonce'], 'ace_seo_settings')) {
    $options = get_option('ace_seo_options', []);
    
    // Ensure we have an array - handle corrupted data
    if (!is_array($options)) {
        $options = [];
        // If data was corrupted, delete the option and start fresh
        delete_option('ace_seo_options');
    }
    
    // Ensure all required array keys exist
    $options['general'] = isset($options['general']) && is_array($options['general']) ? $options['general'] : [];
    $options['templates'] = isset($options['templates']) && is_array($options['templates']) ? $options['templates'] : [];
    $options['social'] = isset($options['social']) && is_array($options['social']) ? $options['social'] : [];
    $options['advanced'] = isset($options['advanced']) && is_array($options['advanced']) ? $options['advanced'] : [];
    $options['ai'] = isset($options['ai']) && is_array($options['ai']) ? $options['ai'] : [];
    $options['performance'] = isset($options['performance']) && is_array($options['performance']) ? $options['performance'] : [];
    
    // Update general settings
    $options['general']['separator'] = sanitize_text_field($_POST['separator'] ?? '|');
    $options['general']['site_name'] = sanitize_text_field($_POST['site_name'] ?? '');
    $options['general']['home_title'] = sanitize_text_field($_POST['home_title'] ?? '');
    $options['general']['home_description'] = sanitize_textarea_field($_POST['home_description'] ?? '');
    
    // Update title templates for each post type
    $post_types = get_post_types(['public' => true], 'objects');
    foreach ($post_types as $post_type) {
        if ($post_type->name === 'attachment') continue;
        
        $template_key = 'title_template_' . $post_type->name;
        $meta_template_key = 'meta_template_' . $post_type->name;
        
        $options['templates'][$template_key] = sanitize_text_field($_POST[$template_key] ?? '');
        $options['templates'][$meta_template_key] = sanitize_textarea_field($_POST[$meta_template_key] ?? '');
    }
    
    // Update archive/special page templates
    $special_template_keys = ['archive', 'search', 'author', 'category', 'tag', 'date'];
    foreach ($special_template_keys as $template_type) {
        $template_key = 'title_template_' . $template_type;
        $meta_template_key = 'meta_template_' . $template_type;
        if (isset($_POST[$template_key])) {
            $options['templates'][$template_key] = sanitize_text_field($_POST[$template_key]);
        }
        if (isset($_POST[$meta_template_key])) {
            $options['templates'][$meta_template_key] = sanitize_textarea_field($_POST[$meta_template_key]);
        }
    }
    
    // Update social settings
    $options['social']['facebook_app_id'] = sanitize_text_field($_POST['facebook_app_id'] ?? '');
    $options['social']['twitter_username'] = sanitize_text_field($_POST['twitter_username'] ?? '');
    $options['social']['default_image'] = esc_url_raw($_POST['default_image'] ?? '');
    
    // Update advanced settings
    $options['advanced']['clean_permalinks'] = isset($_POST['clean_permalinks']) ? 1 : 0;
    
    // Update AI/Performance settings
    $options['ai']['openai_api_key'] = sanitize_text_field($_POST['openai_api_key'] ?? '');
    $options['ai']['ai_content_analysis'] = isset($_POST['ai_content_analysis']) ? 1 : 0;
    $options['ai']['ai_keyword_suggestions'] = isset($_POST['ai_keyword_suggestions']) ? 1 : 0;
    $options['ai']['ai_content_optimization'] = isset($_POST['ai_content_optimization']) ? 1 : 0;
    $options['ai']['ai_image_generation'] = isset($_POST['ai_image_generation']) ? 1 : 0;
    
    $options['performance']['pagespeed_api_key'] = sanitize_text_field($_POST['pagespeed_api_key'] ?? '');
    $options['performance']['pagespeed_monitoring'] = isset($_POST['pagespeed_monitoring']) ? 1 : 0;
    $options['performance']['pagespeed_alerts'] = isset($_POST['pagespeed_alerts']) ? 1 : 0;
    $options['performance']['core_web_vitals'] = isset($_POST['core_web_vitals']) ? 1 : 0;
    
    update_option('ace_seo_options', $options);
    
    echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
}

$options = get_option('ace_seo_options', []);
$general = $options['general'] ?? [];
$social = $options['social'] ?? [];
$advanced = $options['advanced'] ?? [];
$ai = $options['ai'] ?? [];
$performance = $options['performance'] ?? [];
$templates = $options['templates'] ?? [];

// Get default templates
$default_templates = [
    'post' => '{title} {sep} {site_name}',
    'page' => '{title} {sep} {site_name}',
];

// Archive, search, and author templates
$special_templates = [
    'archive' => '{archive_title} {sep} {site_name}',
    'search' => 'Search Results for "{search_term}" {sep} {site_name}',
    'author' => '{author_name} {sep} {site_name}',
    'category' => '{category_name} Archive {sep} {site_name}',
    'tag' => '{tag_name} Archive {sep} {site_name}',
    'date' => '{date_archive} Archive {sep} {site_name}'
];

// Archive, search, and author meta description templates
$special_meta_templates = [
    'archive' => 'Browse {archive_title} content and articles.',
    'search' => 'Search results for "{search_term}" - find relevant content and articles.',
    'author' => 'Articles and content written by {author_name}.',
    'category' => 'Browse articles and content in the {category_name} category.',
    'tag' => 'Articles and content tagged with {tag_name}.',
    'date' => 'Browse articles from {date_archive}.'
];

// Get sample data for each post type
$post_type_samples = [];
$available_post_types = get_post_types(['public' => true], 'objects');
foreach ($available_post_types as $post_type_obj) {
    if ($post_type_obj->name === 'attachment') continue;
    
    $latest_post = get_posts([
        'numberposts' => 1, 
        'post_status' => 'publish', 
        'post_type' => $post_type_obj->name
    ]);
    
    if ($latest_post) {
        $post = $latest_post[0];
        $categories = get_the_category($post->ID);
        $tags = get_the_tags($post->ID);
        
        $post_type_samples[$post_type_obj->name] = [
            'title' => $post->post_title,
            'excerpt' => $post->post_excerpt ?: wp_trim_words(strip_tags($post->post_content), 25),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'date' => get_the_date('F j, Y', $post),
            'category' => $categories ? $categories[0]->name : '',
            'tag' => $tags ? $tags[0]->name : ''
        ];
    } else {
        // Fallback sample data
        $post_type_samples[$post_type_obj->name] = [
            'title' => 'Sample ' . $post_type_obj->labels->singular_name,
            'excerpt' => 'This is a sample excerpt for ' . strtolower($post_type_obj->labels->singular_name) . ' content...',
            'author' => 'Author Name',
            'date' => date('F j, Y'),
            'category' => 'Sample Category',
            'tag' => 'Sample Tag'
        ];
    }
}

// Add sample data for special templates
$post_type_samples['archive'] = [
    'title' => 'Blog Archive',
    'excerpt' => 'Browse all posts in our blog archive',
    'author' => 'Various Authors',
    'date' => date('F j, Y'),
    'category' => 'All Categories',
    'tag' => 'All Tags',
    'archive_title' => 'Blog Archive'
];

$post_type_samples['search'] = [
    'title' => 'Search Results',
    'excerpt' => 'Search results for your query',
    'author' => '',
    'date' => date('F j, Y'),
    'category' => '',
    'tag' => '',
    'search_term' => 'sample search'
];

$post_type_samples['author'] = [
    'title' => 'Author Archive',
    'excerpt' => 'Posts by this author',
    'author' => 'shane',
    'date' => date('F j, Y'),
    'category' => '',
    'tag' => '',
    'author_name' => 'shane'
];

$post_type_samples['category'] = [
    'title' => 'Category Archive',
    'excerpt' => 'Posts in this category',
    'author' => '',
    'date' => date('F j, Y'),
    'category' => 'Uncategorized',
    'tag' => '',
    'category_name' => 'Uncategorized'
];

$post_type_samples['tag'] = [
    'title' => 'Tag Archive',
    'excerpt' => 'Posts with this tag',
    'author' => '',
    'date' => date('F j, Y'),
    'category' => '',
    'tag' => 'Sample Tag',
    'tag_name' => 'Sample Tag'
];

$post_type_samples['date'] = [
    'title' => 'Date Archive',
    'excerpt' => 'Posts from this time period',
    'author' => '',
    'date' => date('F j, Y'),
    'category' => '',
    'tag' => '',
    'date_archive' => date('F Y')
];
?>

<div class="wrap">
    <h1>
        <span class="dashicons dashicons-admin-settings" style="font-size: 30px; margin-right: 10px; color: #a4286a;"></span>
        Ace SEO Settings
    </h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('ace_seo_settings', 'ace_seo_settings_nonce'); ?>
        
        <div class="ace-seo-settings">
            <!-- General Settings -->
            <div class="ace-seo-settings-section">
                <h2>General Settings</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="separator">Title Separator</label>
                        </th>
                        <td>
                            <select id="separator" name="separator" class="regular-text">
                                <option value="|" <?php selected($general['separator'] ?? '|', '|'); ?>>| (pipe)</option>
                                <option value="-" <?php selected($general['separator'] ?? '|', '-'); ?>>- (dash)</option>
                                <option value="–" <?php selected($general['separator'] ?? '|', '–'); ?>>– (en dash)</option>
                                <option value="—" <?php selected($general['separator'] ?? '|', '—'); ?>>— (em dash)</option>
                                <option value="•" <?php selected($general['separator'] ?? '|', '•'); ?>>• (bullet)</option>
                            </select>
                            <p class="description">Choose the separator for page titles.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="site_name">Site Name</label>
                        </th>
                        <td>
                            <input type="text" id="site_name" name="site_name" value="<?php echo esc_attr($general['site_name'] ?? get_bloginfo('name')); ?>" class="regular-text">
                            <p class="description">Your site name as it appears in search results.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="home_title">Homepage Title</label>
                        </th>
                        <td>
                            <input type="text" id="home_title" name="home_title" value="<?php echo esc_attr($general['home_title'] ?? ''); ?>" class="regular-text">
                            <p class="description">Custom title for your homepage. Leave empty to use the default.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="home_description">Homepage Description</label>
                        </th>
                        <td>
                            <textarea id="home_description" name="home_description" rows="3" class="large-text"><?php echo esc_textarea($general['home_description'] ?? ''); ?></textarea>
                            <p class="description">Meta description for your homepage.</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Title Templates -->
            <div class="ace-seo-settings-section">
                <h2>Title & Meta Templates</h2>
                <p>Configure default title and meta description templates for each post type. These templates will be used when no custom SEO title or meta description is set.</p>
                
                <div class="ace-seo-template-variables">
                    <h4>Available Variables:</h4>
                    <div class="ace-seo-variables-grid">
                        <div class="ace-seo-variable">
                            <code>{title}</code>
                            <span>Post/Page title</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{site_name}</code>
                            <span>Site name</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{sep}</code>
                            <span>Title separator</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{excerpt}</code>
                            <span>Post excerpt</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{author}</code>
                            <span>Post author</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{date}</code>
                            <span>Publish date</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{category}</code>
                            <span>Primary category</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{tag}</code>
                            <span>First tag</span>
                        </div>
                    </div>
                </div>
                
                <table class="form-table">
                    <?php 
                    $post_types = get_post_types(['public' => true], 'objects');
                    foreach ($post_types as $post_type):
                        if ($post_type->name === 'attachment') continue;
                        
                        $title_template_key = 'title_template_' . $post_type->name;
                        $current_title_template = $templates[$title_template_key] ?? ($default_templates[$post_type->name] ?? '{title} {sep} {site_name}');
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr($title_template_key); ?>"><?php echo esc_html($post_type->labels->singular_name); ?> Title</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="<?php echo esc_attr($title_template_key); ?>" 
                                   name="<?php echo esc_attr($title_template_key); ?>" 
                                   value="<?php echo esc_attr($current_title_template); ?>" 
                                   class="large-text ace-seo-template-input" 
                                   data-default="<?php echo esc_attr($default_templates[$post_type->name] ?? '{title} {sep} {site_name}'); ?>"
                                   data-post-type="<?php echo esc_attr($post_type->name); ?>">
                            <p class="description">Title template for <?php echo esc_html(strtolower($post_type->labels->name)); ?>.</p>
                            <div class="ace-seo-template-preview" id="preview_<?php echo esc_attr($title_template_key); ?>"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            
            <!-- Archive & Special Page Templates -->
            <div class="ace-seo-settings-section">
                <h2>Archive & Special Page Templates</h2>
                <p class="description">Configure title templates for archive pages, search results, and author pages.</p>
                
                <div class="ace-seo-variables">
                    <h4>Available Variables (in addition to those above):</h4>
                    <div class="ace-seo-variables-grid">
                        <div class="ace-seo-variable">
                            <code>{archive_title}</code>
                            <span>Archive page title</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{search_term}</code>
                            <span>Search query</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{author_name}</code>
                            <span>Author display name</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{category_name}</code>
                            <span>Category name</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{tag_name}</code>
                            <span>Tag name</span>
                        </div>
                        <div class="ace-seo-variable">
                            <code>{date_archive}</code>
                            <span>Date archive title</span>
                        </div>
                    </div>
                </div>
                
                <table class="form-table">
                    <?php foreach ($special_templates as $template_key => $default_template): 
                        $template_name = 'title_template_' . $template_key;
                        $current_template = $templates[$template_name] ?? $default_template;
                        $labels = [
                            'archive' => 'General Archive',
                            'search' => 'Search Results',
                            'author' => 'Author Pages',
                            'category' => 'Category Archives',
                            'tag' => 'Tag Archives',
                            'date' => 'Date Archives'
                        ];
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr($template_name); ?>"><?php echo esc_html($labels[$template_key]); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="<?php echo esc_attr($template_name); ?>" 
                                   name="<?php echo esc_attr($template_name); ?>" 
                                   value="<?php echo esc_attr($current_template); ?>" 
                                   class="large-text ace-seo-template-input" 
                                   data-default="<?php echo esc_attr($default_template); ?>"
                                   data-post-type="<?php echo esc_attr($template_key); ?>">
                            <p class="description">Title template for <?php echo esc_html(strtolower($labels[$template_key])); ?>.</p>
                            <div class="ace-seo-template-preview" id="preview_<?php echo esc_attr($template_name); ?>"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            
            <!-- Social Settings -->
            <div class="ace-seo-settings-section">
                <h2>Social Media</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="facebook_app_id">Facebook App ID</label>
                        </th>
                        <td>
                            <input type="text" id="facebook_app_id" name="facebook_app_id" value="<?php echo esc_attr($social['facebook_app_id'] ?? ''); ?>" class="regular-text">
                            <p class="description">Your Facebook App ID for Open Graph integration.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="twitter_username">Twitter Username</label>
                        </th>
                        <td>
                            <input type="text" id="twitter_username" name="twitter_username" value="<?php echo esc_attr($social['twitter_username'] ?? ''); ?>" class="regular-text" placeholder="@username">
                            <p class="description">Your Twitter username (including @) for Twitter Cards.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="default_image">Default Social Image</label>
                        </th>
                        <td>
                            <div class="ace-seo-image-field">
                                <input type="url" id="default_image" name="default_image" value="<?php echo esc_attr($social['default_image'] ?? ''); ?>" class="regular-text">
                                <button type="button" class="button ace-seo-image-select" data-target="default_image">Select Image</button>
                            </div>
                            <p class="description">Default image for social media sharing when no specific image is set.</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Advanced Settings -->
            <div class="ace-seo-settings-section">
                <h2>Advanced Features</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Clean URLs</th>
                        <td>
                            <label>
                                <input type="checkbox" name="clean_permalinks" value="1" <?php checked($advanced['clean_permalinks'] ?? 0, 1); ?>>
                                Remove unnecessary URL parameters
                            </label>
                            <p class="description">Clean up URLs by removing unnecessary query parameters and tracking codes.</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- AI Integration Settings -->
            <div class="ace-seo-settings-section">
                <h2>AI-Powered Features</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="openai_api_key">OpenAI API Key</label>
                        </th>
                        <td>
                            <input type="password" id="openai_api_key" name="openai_api_key" value="<?php echo esc_attr($ai['openai_api_key'] ?? ''); ?>" class="regular-text">
                            <button type="button" class="button" onclick="togglePasswordVisibility('openai_api_key')">Show/Hide</button>
                            <button type="button" class="button test-api-connection" data-api="openai">Test Connection</button>
                            <div id="openai-test-result" class="api-test-result" style="margin-top: 5px;"></div>
                            <p class="description">
                                Your OpenAI API key for AI-powered content analysis and optimization. 
                                <a href="https://platform.openai.com/api-keys" target="_blank">Get your API key here</a>.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">AI Content Analysis</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ai_content_analysis" value="1" <?php checked($ai['ai_content_analysis'] ?? 0, 1); ?>>
                                Enable AI-powered content analysis
                            </label>
                            <p class="description">Get intelligent SEO recommendations and content quality insights using AI.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">AI Keyword Suggestions</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ai_keyword_suggestions" value="1" <?php checked($ai['ai_keyword_suggestions'] ?? 0, 1); ?>>
                                Enable AI keyword suggestions
                            </label>
                            <p class="description">Get smart keyword recommendations based on your content and industry trends.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">AI Content Optimization</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ai_content_optimization" value="1" <?php checked($ai['ai_content_optimization'] ?? 0, 1); ?>>
                                Enable AI content optimization suggestions
                            </label>
                            <p class="description">Receive AI-generated suggestions to improve your content for better search rankings.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">AI Image Generation</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ai_image_generation" value="1" <?php checked($ai['ai_image_generation'] ?? 0, 1); ?>>
                                Enable AI-powered social media image generation
                            </label>
                            <p class="description">Allow generation of custom social media images using DALL-E 3 for Facebook and Twitter previews.</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Performance Monitoring Settings -->
            <div class="ace-seo-settings-section">
                <h2>Performance Monitoring</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pagespeed_api_key">PageSpeed Insights API Key</label>
                        </th>
                        <td>
                            <input type="password" id="pagespeed_api_key" name="pagespeed_api_key" value="<?php echo esc_attr($performance['pagespeed_api_key'] ?? ''); ?>" class="regular-text">
                            <button type="button" class="button" onclick="togglePasswordVisibility('pagespeed_api_key')">Show/Hide</button>
                            <button type="button" class="button test-api-connection" data-api="pagespeed">Test Connection</button>
                            <div id="pagespeed-test-result" class="api-test-result" style="margin-top: 5px;"></div>
                            <p class="description">
                                Your Google PageSpeed Insights API key for performance monitoring. 
                                <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank">Get your API key here</a>.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">PageSpeed Monitoring</th>
                        <td>
                            <label>
                                <input type="checkbox" name="pagespeed_monitoring" value="1" <?php checked($performance['pagespeed_monitoring'] ?? 0, 1); ?>>
                                Enable automatic PageSpeed monitoring
                            </label>
                            <p class="description">Automatically monitor your site's performance and Core Web Vitals.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Performance Alerts</th>
                        <td>
                            <label>
                                <input type="checkbox" name="pagespeed_alerts" value="1" <?php checked($performance['pagespeed_alerts'] ?? 0, 1); ?>>
                                Send performance alerts
                            </label>
                            <p class="description">Get notified when your site's performance drops below acceptable thresholds.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Core Web Vitals</th>
                        <td>
                            <label>
                                <input type="checkbox" name="core_web_vitals" value="1" <?php checked($performance['core_web_vitals'] ?? 1, 1); ?>>
                                Track Core Web Vitals
                            </label>
                            <p class="description">Monitor LCP, FID, and CLS metrics that directly impact SEO rankings.</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Yoast Migration -->
            <div class="ace-seo-settings-section">
                <h2>Yoast SEO Compatibility</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Meta Data</th>
                        <td>
                            <p class="description">
                                <strong>✅ Fully Compatible:</strong> Ace SEO uses the same meta field structure as Yoast SEO. 
                                All your existing SEO data (titles, descriptions, keywords, social media settings) will work seamlessly.
                            </p>
                            
                            <?php if (is_plugin_active('wordpress-seo/wp-seo.php')): ?>
                                <div class="notice notice-warning inline">
                                    <p>
                                        <strong>Notice:</strong> Yoast SEO is currently active. You can safely deactivate it to avoid conflicts 
                                        while keeping all your SEO data intact.
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <p>
                                <strong>Database Keys Used:</strong><br>
                                <code>_yoast_wpseo_title</code>, <code>_yoast_wpseo_metadesc</code>, <code>_yoast_wpseo_focuskw</code>, 
                                <code>_yoast_wpseo_opengraph-*</code>, <code>_yoast_wpseo_twitter-*</code>, and more.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <?php submit_button('Save Settings', 'primary', 'submit'); ?>
    </form>
    
    <!-- WordPress Sitemap Information -->
    <div class="ace-seo-sitemap-info">
        <div class="ace-seo-info-box">
            <h3>
                <span class="dashicons dashicons-admin-site-alt3" style="color: #00a32a;"></span>
                XML Sitemaps
            </h3>
            <p>Your WordPress site automatically generates optimized XML sitemaps using WordPress core functionality.</p>
            
            <div class="ace-sitemap-links">
                <p><strong>Your sitemap URLs:</strong></p>
                <ul>
                    <li>
                        <code><?php echo esc_url(home_url('/wp-sitemap.xml')); ?></code>
                        <span class="ace-sitemap-badge">WordPress Core (Recommended)</span>
                    </li>
                    <li>
                        <code><?php echo esc_url(home_url('/sitemap.xml')); ?></code>
                        <span class="ace-sitemap-badge ace-sitemap-legacy">Legacy URL</span>
                    </li>
                </ul>
            </div>
            
            <div class="ace-sitemap-features">
                <p><strong>✅ Features automatically included:</strong></p>
                <ul>
                    <li>Proper pagination for large sites (2000+ posts)</li>
                    <li>Automatic updates when content changes</li>
                    <li>Support for posts, pages, categories, tags, and custom post types</li>
                    <li>Optimized for search engine discovery</li>
                    <li>No configuration needed</li>
                </ul>
            </div>
            
            <p class="description">
                <strong>💡 Tip:</strong> Submit <code><?php echo esc_url(home_url('/wp-sitemap.xml')); ?></code> to 
                <a href="https://search.google.com/search-console" target="_blank">Google Search Console</a> 
                and <a href="https://www.bing.com/webmasters" target="_blank">Bing Webmaster Tools</a> for optimal indexing.
            </p>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(fieldId) {
    const field = document.getElementById(fieldId);
    const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
    field.setAttribute('type', type);
}

jQuery(document).ready(function($) {
    $('.ace-seo-image-select').on('click', function(e) {
        e.preventDefault();
        
        const targetInput = $(this).data('target');
        const $target = $('#' + targetInput);
        
        if (typeof wp !== 'undefined' && wp.media) {
            const mediaUploader = wp.media({
                title: 'Select Default Social Image',
                button: {
                    text: 'Use this image'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            mediaUploader.on('select', function() {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                $target.val(attachment.url);
            });
            
            mediaUploader.open();
        }
    });
    
    // API Key validation feedback
    $('#openai_api_key').on('blur', function() {
        const apiKey = $(this).val();
        if (apiKey && !apiKey.startsWith('sk-')) {
            $(this).after('<span class="ace-api-warning" style="color: #d63384; font-size: 12px; margin-left: 5px;">⚠️ OpenAI API keys typically start with "sk-"</span>');
        } else {
            $('.ace-api-warning').remove();
        }
    });
    
    // Test API connections
    $('.test-api-connection').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const apiType = button.data('api');
        const originalText = button.text();
        const resultDiv = $('#' + apiType + '-test-result');
        
        let apiKey;
        if (apiType === 'openai') {
            apiKey = $('#openai_api_key').val();
        } else if (apiType === 'pagespeed') {
            apiKey = $('#pagespeed_api_key').val();
        }
        
        if (!apiKey) {
            resultDiv.html('<span style="color: #d63384;">⚠️ Please enter an API key first</span>');
            return;
        }
        
        button.text('Testing...').prop('disabled', true);
        resultDiv.html('<span style="color: #666;">🔄 Testing connection...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ace_seo_test_api',
                api_type: apiType,
                api_key: apiKey,
                nonce: '<?php echo wp_create_nonce('ace_seo_api_test'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.html('<span style="color: #28a745;">✅ ' + response.message + '</span>');
                } else {
                    const errorMessage = response.message || 'Connection test failed';
                    resultDiv.html('<span style="color: #d63384;">❌ ' + errorMessage + '</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr, status, error); // Debug log
                resultDiv.html('<span style="color: #d63384;">❌ Connection test failed: ' + error + '</span>');
            },
            complete: function() {
                // Ensure button is always re-enabled
                setTimeout(function() {
                    button.text(originalText).prop('disabled', false);
                }, 100);
            }
        });
    });
    
    // Template preview functionality
    $('.ace-seo-template-input').on('input', function() {
        const input = $(this);
        const template = input.val();
        const previewId = 'preview_' + input.attr('id');
        const previewDiv = $('#' + previewId);
        const postType = input.data('post-type') || 'post';
        
        if (template) {
            const preview = processTemplatePreview(template, postType);
            previewDiv.html('<strong>Preview:</strong> ' + preview).show();
        } else {
            previewDiv.hide();
        }
    });
    
    // Process template for preview
    function processTemplatePreview(template, postType) {
        // Post-type specific sample data
        const sampleData = <?php echo json_encode($post_type_samples); ?>;
        const currentTypeData = sampleData[postType] || sampleData['post'];
        
        const variables = {
            '{title}': currentTypeData.title,
            '{site_name}': '<?php echo esc_js($general['site_name'] ?? get_bloginfo('name')); ?>',
            '{sep}': '<?php echo esc_js($general['separator'] ?? '|'); ?>',
            '{excerpt}': currentTypeData.excerpt,
            '{author}': currentTypeData.author,
            '{date}': currentTypeData.date,
            '{category}': currentTypeData.category,
            '{tag}': currentTypeData.tag,
            // Special template variables
            '{archive_title}': currentTypeData.archive_title || currentTypeData.title,
            '{search_term}': currentTypeData.search_term || '',
            '{author_name}': currentTypeData.author_name || currentTypeData.author,
            '{category_name}': currentTypeData.category_name || currentTypeData.category,
            '{tag_name}': currentTypeData.tag_name || currentTypeData.tag,
            '{date_archive}': currentTypeData.date_archive || currentTypeData.date
        };
        
        let result = template;
        for (const [key, value] of Object.entries(variables)) {
            result = result.replace(new RegExp(key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), value || '');
        }
        
        result = result.replace(/\s+/g, ' ').trim();
        
        return result;
    }
    
    // Initialize previews
    $('.ace-seo-template-input').trigger('input');
});
</script>

<style>
.ace-seo-settings {
    background: #fff;
    padding: 0;
}

.ace-seo-settings-section {
    margin-bottom: 40px;
    background: #fff;
    border: 1px solid #e1e1e1;
    border-radius: 8px;
    overflow: hidden;
}

.ace-seo-settings-section h2 {
    margin: 0;
    padding: 16px 20px;
    background: #f9f9f9;
    border-bottom: 1px solid #e1e1e1;
    font-size: 18px;
    font-weight: 600;
    color: #1e1e1e;
}

.ace-seo-settings-section .form-table {
    margin: 0;
    padding: 20px;
}

.ace-seo-settings-section .form-table th {
    width: 200px;
    padding: 15px 0;
    font-weight: 600;
}

.ace-seo-settings-section .form-table td {
    padding: 15px 0;
}

.ace-seo-image-field {
    display: flex;
    gap: 8px;
    align-items: center;
}

.ace-seo-image-field input {
    flex: 1;
}

.notice.inline {
    margin: 10px 0;
    padding: 8px 12px;
}

.api-test-result {
    margin-top: 5px;
    font-size: 13px;
    font-weight: 500;
}

.test-api-connection {
    margin-left: 5px;
}

.test-api-connection:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.ace-seo-settings-section {
    position: relative;
}

.ace-seo-settings-section[data-requires-api="true"] {
    opacity: 0.7;
}

.ace-seo-settings-section[data-requires-api="true"]::before {
    content: "🔑 Requires API key configuration";
    position: absolute;
    top: 10px;
    right: 20px;
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
}

/* Title Template Styles */
.ace-seo-template-variables {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 15px;
    margin: 15px 0 25px 0;
}

.ace-seo-template-variables h4 {
    margin: 0 0 10px 0;
    color: #495057;
    font-size: 14px;
}

.ace-seo-variables-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
}

.ace-seo-variable {
    display: flex;
    flex-direction: column;
    padding: 8px;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 12px;
}

.ace-seo-variable code {
    background: #e9ecef;
    color: #d63384;
    padding: 2px 4px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    margin-bottom: 4px;
}

.ace-seo-variable span {
    color: #6c757d;
    font-size: 11px;
}

.ace-seo-template-input {
    font-family: 'Courier New', monospace;
    font-size: 13px;
}

.ace-seo-template-preview {
    margin-top: 8px;
    padding: 8px;
    background: #f8f9fa;
    border-left: 3px solid #007cba;
    font-size: 12px;
    color: #495057;
    border-radius: 0 4px 4px 0;
    font-style: italic;
}

/* Sitemap Information Box */
.ace-seo-sitemap-info {
    margin-top: 30px;
}

.ace-seo-info-box {
    background: #f0f8ff;
    border: 1px solid #b8deff;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.ace-seo-info-box h3 {
    margin: 0 0 15px 0;
    color: #0073aa;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ace-sitemap-links ul {
    margin: 10px 0;
    padding-left: 0;
    list-style: none;
}

.ace-sitemap-links li {
    margin: 8px 0;
    padding: 8px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
}

.ace-sitemap-links code {
    background: #2271b1;
    color: white;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    word-break: break-all;
}

.ace-sitemap-badge {
    background: #00a32a;
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ace-sitemap-badge.ace-sitemap-legacy {
    background: #f56e28;
}

.ace-sitemap-features {
    background: #fff;
    padding: 15px;
    border-radius: 6px;
    border: 1px solid #ddd;
    margin: 15px 0;
}

.ace-sitemap-features ul {
    margin: 10px 0;
    padding-left: 20px;
}

.ace-sitemap-features li {
    margin: 5px 0;
    font-size: 13px;
    line-height: 1.4;
}

@media (max-width: 768px) {
    .ace-sitemap-links li {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
}
</style>
