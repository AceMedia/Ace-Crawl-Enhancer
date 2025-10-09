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
    $options['organization'] = isset($options['organization']) && is_array($options['organization']) ? $options['organization'] : [];
    $options['webmaster'] = isset($options['webmaster']) && is_array($options['webmaster']) ? $options['webmaster'] : [];
    $options['person'] = isset($options['person']) && is_array($options['person']) ? $options['person'] : [];
    
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
    $options['social']['default_image'] = esc_url_raw($_POST['default_image'] ?? '');
    
    // Update organization settings
    $options['organization']['name'] = sanitize_text_field($_POST['organization_name'] ?? '');
    $options['organization']['legal_name'] = sanitize_text_field($_POST['organization_legal_name'] ?? '');
    $options['organization']['alternate_name'] = sanitize_text_field($_POST['organization_alternate_name'] ?? '');
    $options['organization']['url'] = esc_url_raw($_POST['organization_url'] ?? '');
    $options['organization']['description'] = sanitize_textarea_field($_POST['organization_description'] ?? '');
    $options['organization']['logo_id'] = absint($_POST['organization_logo_id'] ?? 0);
    $options['organization']['logo_url'] = esc_url_raw($_POST['organization_logo_url'] ?? '');
    $options['organization']['contact_type'] = sanitize_text_field($_POST['organization_contact_type'] ?? '');
    $options['organization']['contact_phone'] = sanitize_text_field($_POST['organization_contact_phone'] ?? '');
    $options['organization']['contact_email'] = sanitize_email($_POST['organization_contact_email'] ?? '');
    $options['organization']['contact_url'] = esc_url_raw($_POST['organization_contact_url'] ?? '');
    $twitter_username = sanitize_text_field($_POST['organization_twitter_username'] ?? '');
    $twitter_username = preg_replace('/\s+/', '', $twitter_username);
    if (!empty($twitter_username)) {
        $twitter_username = '@' . ltrim($twitter_username, '@');
    }
    $options['organization']['twitter_username'] = $twitter_username;

    $options['organization']['social_facebook'] = esc_url_raw($_POST['organization_social_facebook'] ?? '');
    $options['organization']['social_instagram'] = esc_url_raw($_POST['organization_social_instagram'] ?? '');
    $options['organization']['social_linkedin'] = esc_url_raw($_POST['organization_social_linkedin'] ?? '');
    $options['organization']['social_youtube'] = esc_url_raw($_POST['organization_social_youtube'] ?? '');

    if (!empty($twitter_username)) {
        $options['organization']['social_twitter'] = esc_url_raw('https://twitter.com/' . ltrim($twitter_username, '@'));
    } else {
        $options['organization']['social_twitter'] = '';
    }

    $options['organization']['type'] = sanitize_text_field($_POST['organization_type'] ?? 'organization');

    // Update person settings
    $options['person']['name'] = sanitize_text_field($_POST['person_name'] ?? '');
    $options['person']['job_title'] = sanitize_text_field($_POST['person_job_title'] ?? '');
    $options['person']['url'] = esc_url_raw($_POST['person_url'] ?? '');
    $options['person']['description'] = sanitize_textarea_field($_POST['person_description'] ?? '');
    $options['person']['image_id'] = absint($_POST['person_image_id'] ?? 0);
    $options['person']['image_url'] = esc_url_raw($_POST['person_image_url'] ?? '');
    $options['person']['twitter_username'] = sanitize_text_field($_POST['person_twitter_username'] ?? '');

    if (!empty($options['person']['twitter_username'])) {
        $options['person']['twitter_username'] = '@' . ltrim($options['person']['twitter_username'], '@');
    }

    $person_same_as_input = array_map('trim', (array) ($_POST['person_same_as'] ?? []));
    $options['person']['same_as'] = array_values(array_filter(array_unique(array_map('esc_url_raw', $person_same_as_input))));

    // Update webmaster verification codes
    $options['webmaster']['ahrefs'] = sanitize_text_field($_POST['webmaster_ahrefs'] ?? '');
    $options['webmaster']['baidu'] = sanitize_text_field($_POST['webmaster_baidu'] ?? '');
    $options['webmaster']['bing'] = sanitize_text_field($_POST['webmaster_bing'] ?? '');
    $options['webmaster']['google'] = sanitize_text_field($_POST['webmaster_google'] ?? '');
    $options['webmaster']['pinterest'] = sanitize_text_field($_POST['webmaster_pinterest'] ?? '');
    $options['webmaster']['yandex'] = sanitize_text_field($_POST['webmaster_yandex'] ?? '');

    // Maintain legacy keys for backwards compatibility
    $options['webmaster']['google_verify'] = $options['webmaster']['google'];
    $options['webmaster']['bing_verify'] = $options['webmaster']['bing'];

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
$organization = $options['organization'] ?? [];
$webmaster = $options['webmaster'] ?? [];
$person = $options['person'] ?? [];

$organization_logo_url = '';
if (!empty($organization['logo_id'])) {
    $logo_id = absint($organization['logo_id']);
    $organization_logo_url = wp_get_attachment_image_url($logo_id, 'full') ?: '';
}

if (empty($organization_logo_url) && !empty($organization['logo_url'])) {
    $organization_logo_url = $organization['logo_url'];
}

$organization_twitter_username = $organization['twitter_username'] ?? '';

if (empty($organization_twitter_username)) {
    if (!empty($organization['social_twitter'])) {
        $parsed_social_twitter = wp_parse_url($organization['social_twitter']);
        if (!empty($parsed_social_twitter['path'])) {
            $organization_twitter_username = '@' . ltrim($parsed_social_twitter['path'], '/@');
        }
    } elseif (!empty($social['twitter_username'])) {
        $organization_twitter_username = '@' . ltrim($social['twitter_username'], '@');
    }
}

$organization_twitter_username = ltrim($organization_twitter_username ?? '', '@');

$person_image_url = '';
if (!empty($person['image_id'])) {
    $person_image_url = wp_get_attachment_image_url(absint($person['image_id']), 'full') ?: '';
}

if (empty($person_image_url) && !empty($person['image_url'])) {
    $person_image_url = $person['image_url'];
}

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

            <!-- Site Connections / Verification -->
            <div class="ace-seo-settings-section">
                <h2>Site Connections</h2>
                <p class="description">Add verification codes for search engines and analytics platforms. We'll output the correct meta tags on your homepage automatically.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="webmaster_google">Google</label></th>
                        <td>
                            <input type="text" id="webmaster_google" name="webmaster_google" value="<?php echo esc_attr($webmaster['google'] ?? ''); ?>" class="regular-text" placeholder="example-code">
                            <p class="description">Find this in <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webmaster_bing">Bing</label></th>
                        <td>
                            <input type="text" id="webmaster_bing" name="webmaster_bing" value="<?php echo esc_attr($webmaster['bing'] ?? ''); ?>" class="regular-text">
                            <p class="description">Get your verification code from <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster Tools</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webmaster_pinterest">Pinterest</label></th>
                        <td>
                            <input type="text" id="webmaster_pinterest" name="webmaster_pinterest" value="<?php echo esc_attr($webmaster['pinterest'] ?? ''); ?>" class="regular-text">
                            <p class="description">Enter the code from your <a href="https://www.pinterest.com/settings/claim" target="_blank" rel="noopener">Pinterest claim</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webmaster_ahrefs">Ahrefs</label></th>
                        <td>
                            <input type="text" id="webmaster_ahrefs" name="webmaster_ahrefs" value="<?php echo esc_attr($webmaster['ahrefs'] ?? ''); ?>" class="regular-text">
                            <p class="description">Paste the token provided by Ahrefs site verification.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webmaster_yandex">Yandex</label></th>
                        <td>
                            <input type="text" id="webmaster_yandex" name="webmaster_yandex" value="<?php echo esc_attr($webmaster['yandex'] ?? ''); ?>" class="regular-text">
                            <p class="description">Find this in <a href="https://webmaster.yandex.com" target="_blank" rel="noopener">Yandex Webmaster</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webmaster_baidu">Baidu</label></th>
                        <td>
                            <input type="text" id="webmaster_baidu" name="webmaster_baidu" value="<?php echo esc_attr($webmaster['baidu'] ?? ''); ?>" class="regular-text">
                            <p class="description">Retrieve your code from <a href="https://ziyuan.baidu.com" target="_blank" rel="noopener">Baidu Webmaster Tools</a>.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Knowledge Graph Entity -->
            <div class="ace-seo-settings-section">
                <h2>Knowledge Graph Entity</h2>
                <p class="description">Tell search engines whether your site represents an organization or a person. We'll use this information to build structured data and social profiles.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">Site represents</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="organization_type" value="organization" <?php checked(($organization['type'] ?? 'organization'), 'organization'); ?>>
                                    Organization
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="organization_type" value="person" <?php checked(($organization['type'] ?? 'organization'), 'person'); ?>>
                                    Person
                                </label>
                                <p class="description">Choose whether your website primarily represents a company/brand or an individual. Selecting "Person" unlocks profile fields below.</p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Organization Profile -->
            <div class="ace-seo-settings-section ace-seo-organization-settings" data-conditional="organization" style="<?php echo (($organization['type'] ?? 'organization') === 'person') ? 'display:none;' : ''; ?>">
                <h2>Organization Profile</h2>
                <p class="description">Define your organization details for structured data markup and metadata.
                    These details will be used as the publisher information across your site.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="organization_name">Organization Name</label>
                        </th>
                        <td>
                            <input type="text" id="organization_name" name="organization_name" value="<?php echo esc_attr($organization['name'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                            <p class="description">Primary name of your organization. Defaults to the site name if left blank.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="organization_legal_name">Legal Name</label>
                        </th>
                        <td>
                            <input type="text" id="organization_legal_name" name="organization_legal_name" value="<?php echo esc_attr($organization['legal_name'] ?? ''); ?>" class="regular-text">
                            <p class="description">Registered legal name of your company or organization.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="organization_alternate_name">Alternate Name</label>
                        </th>
                        <td>
                            <input type="text" id="organization_alternate_name" name="organization_alternate_name" value="<?php echo esc_attr($organization['alternate_name'] ?? ''); ?>" class="regular-text">
                            <p class="description">Short name, trading name, or commonly used nickname.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="organization_url">Organization URL</label>
                        </th>
                        <td>
                            <input type="url" id="organization_url" name="organization_url" value="<?php echo esc_attr($organization['url'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr(home_url()); ?>">
                            <p class="description">Canonical homepage for your organization. Defaults to the site home URL.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="organization_description">Organization Description</label>
                        </th>
                        <td>
                            <textarea id="organization_description" name="organization_description" rows="3" class="large-text"><?php echo esc_textarea($organization['description'] ?? ''); ?></textarea>
                            <p class="description">A short description or tagline that represents your organization.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="organization_logo_url">Organization Logo</label>
                        </th>
                        <td>
                            <div class="ace-seo-image-field">
                                <input type="hidden" id="organization_logo_id" name="organization_logo_id" value="<?php echo isset($organization['logo_id']) ? absint($organization['logo_id']) : 0; ?>">
                                <input type="url" id="organization_logo_url" name="organization_logo_url" value="<?php echo esc_attr($organization_logo_url); ?>" class="regular-text">
                                <button type="button"
                                        class="button ace-seo-image-select"
                                        data-target="organization_logo_url"
                                        data-target-id="organization_logo_id"
                                        data-preview="organization_logo_preview"
                                        data-clear-button="organization_logo_clear"
                                        data-title="Select Organization Logo"
                                        data-button="Use this logo">
                                    Select Logo
                                </button>
                                <button type="button" class="button button-link" id="organization_logo_clear" style="<?php echo empty($organization_logo_url) ? 'display:none;' : ''; ?>">Remove</button>
                            </div>
                            <p class="description">Upload a square logo at least 112×112px. This logo will be used in structured data.</p>
                            <div class="ace-seo-logo-preview" style="margin-top:10px;">
                                <img id="organization_logo_preview" src="<?php echo esc_url($organization_logo_url); ?>" alt="Organization logo preview" style="max-width:150px; height:auto; <?php echo empty($organization_logo_url) ? 'display:none;' : ''; ?>">
                            </div>
                        </td>
                    </tr>
                </table>

                <h3>Contact Information</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="organization_contact_type">Contact Type</label>
                        </th>
                        <td>
                            <input type="text" id="organization_contact_type" name="organization_contact_type" value="<?php echo esc_attr($organization['contact_type'] ?? ''); ?>" class="regular-text" placeholder="Customer Service">
                            <p class="description">Describe this contact point (e.g., Customer Service, Editorial, Sales).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="organization_contact_phone">Phone Number</label>
                        </th>
                        <td>
                            <input type="text" id="organization_contact_phone" name="organization_contact_phone" value="<?php echo esc_attr($organization['contact_phone'] ?? ''); ?>" class="regular-text" placeholder="+44 20 7946 0958">
                            <p class="description">Primary phone number for this contact point. Include country code.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="organization_contact_email">Email Address</label>
                        </th>
                        <td>
                            <input type="email" id="organization_contact_email" name="organization_contact_email" value="<?php echo esc_attr($organization['contact_email'] ?? ''); ?>" class="regular-text">
                            <p class="description">Email for this contact point. Optional.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="organization_contact_url">Contact URL</label>
                        </th>
                        <td>
                            <input type="url" id="organization_contact_url" name="organization_contact_url" value="<?php echo esc_attr($organization['contact_url'] ?? ''); ?>" class="regular-text">
                            <p class="description">Link to a contact page or help center.</p>
                        </td>
                    </tr>
                </table>

                <h3>Social Profiles</h3>
                <p class="description">Add the primary social media profiles associated with your organization. These will be marked up using <code>sameAs</code> in structured data.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="organization_social_facebook">Facebook</label></th>
                        <td>
                            <input type="url" id="organization_social_facebook" name="organization_social_facebook" value="<?php echo esc_attr($organization['social_facebook'] ?? ''); ?>" class="regular-text" placeholder="https://www.facebook.com/yourpage">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="organization_twitter_username">X / Twitter Handle</label></th>
                        <td>
                            <input type="text" id="organization_twitter_username" name="organization_twitter_username" value="<?php echo esc_attr($organization_twitter_username); ?>" class="regular-text" placeholder="yourhandle">
                            <p class="description">Enter your @username without spaces. We'll automatically use it for Twitter Cards and structured data.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="organization_social_instagram">Instagram</label></th>
                        <td>
                            <input type="url" id="organization_social_instagram" name="organization_social_instagram" value="<?php echo esc_attr($organization['social_instagram'] ?? ''); ?>" class="regular-text" placeholder="https://www.instagram.com/yourprofile">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="organization_social_linkedin">LinkedIn</label></th>
                        <td>
                            <input type="url" id="organization_social_linkedin" name="organization_social_linkedin" value="<?php echo esc_attr($organization['social_linkedin'] ?? ''); ?>" class="regular-text" placeholder="https://www.linkedin.com/company/yourcompany">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="organization_social_youtube">YouTube</label></th>
                        <td>
                            <input type="url" id="organization_social_youtube" name="organization_social_youtube" value="<?php echo esc_attr($organization['social_youtube'] ?? ''); ?>" class="regular-text" placeholder="https://www.youtube.com/@yourchannel">
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Person Profile (if selected) -->
            <div class="ace-seo-settings-section ace-seo-person-settings" data-conditional="person" style="<?php echo (($organization['type'] ?? 'organization') === 'person') ? '' : 'display:none;'; ?>">
                <h2>Person Profile</h2>
                <p class="description">If your site represents a person, fill out their profile for structured data and social cards.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="person_name">Name</label></th>
                        <td>
                            <input type="text" id="person_name" name="person_name" value="<?php echo esc_attr($person['name'] ?? ''); ?>" class="regular-text">
                            <p class="description">Full name of the person.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="person_job_title">Job Title</label></th>
                        <td>
                            <input type="text" id="person_job_title" name="person_job_title" value="<?php echo esc_attr($person['job_title'] ?? ''); ?>" class="regular-text">
                            <p class="description">Optional: e.g., Editor-in-Chief, Lead Writer.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="person_url">Website</label></th>
                        <td>
                            <input type="url" id="person_url" name="person_url" value="<?php echo esc_attr($person['url'] ?? ''); ?>" class="regular-text" placeholder="https://example.com">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="person_description">Bio</label></th>
                        <td>
                            <textarea id="person_description" name="person_description" rows="3" class="large-text"><?php echo esc_textarea($person['description'] ?? ''); ?></textarea>
                            <p class="description">Short biography or tagline.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="person_image_url">Profile Image</label></th>
                        <td>
                            <div class="ace-seo-image-field">
                                <input type="hidden" id="person_image_id" name="person_image_id" value="<?php echo isset($person['image_id']) ? absint($person['image_id']) : 0; ?>">
                                <input type="url" id="person_image_url" name="person_image_url" value="<?php echo esc_attr($person_image_url); ?>" class="regular-text">
                                <button type="button"
                                        class="button ace-seo-image-select"
                                        data-target="person_image_url"
                                        data-target-id="person_image_id"
                                        data-preview="person_image_preview"
                                        data-clear-button="person_image_clear"
                                        data-title="Select Profile Image"
                                        data-button="Use this image">
                                    Select Image
                                </button>
                                <button type="button" class="button button-link" id="person_image_clear" style="<?php echo empty($person_image_url) ? 'display:none;' : ''; ?>">Remove</button>
                            </div>
                            <div class="ace-seo-logo-preview" style="margin-top:10px;">
                                <img id="person_image_preview" src="<?php echo esc_url($person_image_url); ?>" alt="Person image preview" style="max-width:150px; height:auto; <?php echo empty($person_image_url) ? 'display:none;' : ''; ?>">
                            </div>
                            <p class="description">Recommended minimum size: 112×112px.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="person_twitter_username">X / Twitter Handle</label></th>
                        <td>
                            <input type="text" id="person_twitter_username" name="person_twitter_username" value="<?php echo esc_attr(ltrim($person['twitter_username'] ?? '', '@')); ?>" class="regular-text" placeholder="yourhandle">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Other Profiles</th>
                        <td>
                            <div id="person-same-as-list">
                                <?php if (!empty($person['same_as'])): ?>
                                    <?php foreach ($person['same_as'] as $index => $profile_url): ?>
                                        <div class="ace-seo-same-as-item">
                                            <input type="url" name="person_same_as[]" value="<?php echo esc_attr($profile_url); ?>" class="regular-text" placeholder="https://www.linkedin.com/in/example">
                                            <button type="button" class="button button-link ace-remove-same-as">Remove</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="ace-seo-same-as-item ace-seo-same-as-template" style="display:none;">
                                    <input type="url" name="person_same_as[]" value="" class="regular-text" placeholder="https://www.linkedin.com/in/example">
                                    <button type="button" class="button button-link ace-remove-same-as">Remove</button>
                                </div>
                            </div>
                            <button type="button" class="button ace-add-same-as" style="margin-top:10px;">Add Profile</button>
                            <p class="description">Add URLs for other profiles (LinkedIn, Instagram, YouTube, etc.).</p>
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
    function addPersonProfileField() {
        const $list = $('#person-same-as-list');
        const $template = $list.find('.ace-seo-same-as-template').first();
        if ($template.length) {
            const $clone = $template.clone(true);
            $clone.removeClass('ace-seo-same-as-template').show();
            $clone.insertBefore($template);
        } else {
            const $item = $('<div class="ace-seo-same-as-item" />');
            const $input = $('<input type="url" name="person_same_as[]" class="regular-text" placeholder="https://www.linkedin.com/in/example" />');
            const $remove = $('<button type="button" class="button button-link ace-remove-same-as">Remove</button>');
            $item.append($input).append($remove);
            $list.append($item);
        }
    }

    function ensurePersonProfileField() {
        const $list = $('#person-same-as-list');
        if ($list.find('.ace-seo-same-as-item').not('.ace-seo-same-as-template').length === 0) {
            addPersonProfileField();
        }
    }

    $('.ace-seo-image-select').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const targetInput = $button.data('target');
        const targetIdInput = $button.data('target-id');
        const previewId = $button.data('preview');
        const clearButtonId = $button.data('clear-button');

        if (!targetInput) {
            return;
        }

        const $target = $('#' + targetInput);
        const mediaTitle = $button.data('title') || 'Select Image';
        const mediaButton = $button.data('button') || 'Use this image';
        
        if (typeof wp !== 'undefined' && wp.media) {
            const mediaUploader = wp.media({
                title: mediaTitle,
                button: {
                    text: mediaButton
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            mediaUploader.on('select', function() {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                $target.val(attachment.url);
                
                if (targetIdInput) {
                    $('#' + targetIdInput).val(attachment.id);
                }
                
                if (previewId) {
                    $('#' + previewId).attr('src', attachment.url).show();
                }
                
                if (clearButtonId) {
                    $('#' + clearButtonId).show();
                }
            });
            
            mediaUploader.open();
        }
    });

    $('#organization_logo_clear').on('click', function(e) {
        e.preventDefault();
        $('#organization_logo_url').val('');
        $('#organization_logo_id').val('');
        $('#organization_logo_preview').hide().attr('src', '');
        $(this).hide();
    });

    $('#person_image_clear').on('click', function(e) {
        e.preventDefault();
        $('#person_image_url').val('');
        $('#person_image_id').val('');
        $('#person_image_preview').hide().attr('src', '');
        $(this).hide();
    });

    $('input[name="organization_type"]').on('change', function() {
        const value = $(this).val();
        if (value === 'person') {
            $('.ace-seo-person-settings').slideDown();
            $('.ace-seo-organization-settings').slideUp();
            ensurePersonProfileField();
        } else {
            $('.ace-seo-person-settings').slideUp();
            $('.ace-seo-organization-settings').slideDown();
        }
    });

    $(document).on('click', '.ace-remove-same-as', function(e) {
        e.preventDefault();
        $(this).closest('.ace-seo-same-as-item').remove();
        if ($('input[name="organization_type"]:checked').val() === 'person') {
            ensurePersonProfileField();
        }
    });

    $('.ace-add-same-as').on('click', function(e) {
        e.preventDefault();
        addPersonProfileField();
    });

    if ($('input[name="organization_type"]:checked').val() === 'person') {
        ensurePersonProfileField();
    }
    
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
