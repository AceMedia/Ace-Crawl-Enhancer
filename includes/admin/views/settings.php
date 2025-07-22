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
    
    // Update general settings
    $options['general']['separator'] = sanitize_text_field($_POST['separator'] ?? '|');
    $options['general']['site_name'] = sanitize_text_field($_POST['site_name'] ?? '');
    $options['general']['home_title'] = sanitize_text_field($_POST['home_title'] ?? '');
    $options['general']['home_description'] = sanitize_textarea_field($_POST['home_description'] ?? '');
    
    // Update social settings
    $options['social']['facebook_app_id'] = sanitize_text_field($_POST['facebook_app_id'] ?? '');
    $options['social']['twitter_username'] = sanitize_text_field($_POST['twitter_username'] ?? '');
    $options['social']['default_image'] = esc_url_raw($_POST['default_image'] ?? '');
    
    // Update advanced settings
    $options['advanced']['breadcrumbs'] = isset($_POST['breadcrumbs']) ? 1 : 0;
    $options['advanced']['xml_sitemap'] = isset($_POST['xml_sitemap']) ? 1 : 0;
    $options['advanced']['clean_permalinks'] = isset($_POST['clean_permalinks']) ? 1 : 0;
    
    update_option('ace_seo_options', $options);
    
    echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
}

$options = get_option('ace_seo_options', []);
$general = $options['general'] ?? [];
$social = $options['social'] ?? [];
$advanced = $options['advanced'] ?? [];
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
                        <th scope="row">Breadcrumbs</th>
                        <td>
                            <label>
                                <input type="checkbox" name="breadcrumbs" value="1" <?php checked($advanced['breadcrumbs'] ?? 0, 1); ?>>
                                Enable breadcrumb navigation
                            </label>
                            <p class="description">Add breadcrumb navigation to your site for better user experience and SEO.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">XML Sitemap</th>
                        <td>
                            <label>
                                <input type="checkbox" name="xml_sitemap" value="1" <?php checked($advanced['xml_sitemap'] ?? 1, 1); ?>>
                                Generate XML sitemap
                            </label>
                            <p class="description">Automatically generate XML sitemaps for search engines.</p>
                        </td>
                    </tr>
                    
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
</div>

<script>
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
</style>
