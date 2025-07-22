<?php
/**
 * Ace SEO Admin Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class AceSeoAdmin {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('plugin_action_links_' . ACE_SEO_BASENAME, [$this, 'plugin_action_links']);
    }
    
    public function admin_init() {
        // Check for Yoast SEO and show compatibility notice
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
            add_action('admin_notices', [$this, 'yoast_compatibility_notice']);
        }
    }
    
    public function admin_notices() {
        // Show welcome notice on first activation
        if (get_transient('ace_seo_activation_notice')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong>Ace SEO</strong> has been activated! 
                    <a href="<?php echo admin_url('admin.php?page=ace-seo'); ?>">Configure settings</a> 
                    or start optimizing your content with our modern SEO interface.
                </p>
            </div>
            <?php
            delete_transient('ace_seo_activation_notice');
        }
    }
    
    public function yoast_compatibility_notice() {
        ?>
        <div class="notice notice-info">
            <p>
                <strong>Ace SEO:</strong> We've detected Yoast SEO is also active. 
                Ace SEO uses the same meta field structure for seamless compatibility. 
                You can safely disable Yoast SEO to avoid conflicts while keeping all your existing data.
            </p>
        </div>
        <?php
    }
    
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=ace-seo-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}
