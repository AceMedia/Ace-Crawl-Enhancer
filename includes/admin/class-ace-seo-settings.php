<?php
/**
 * Ace SEO Settings Handler
 * Handles the plugin settings pages and configuration
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOSettings {
    
    /**
     * Initialize settings functionality
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_ace_seo_migrate_yoast', array( $this, 'ajax_migrate_yoast_data' ) );
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            'Ace SEO',
            'Ace SEO',
            'manage_options',
            'ace-seo',
            array( $this, 'render_dashboard_page' ),
            'dashicons-search',
            30
        );
        
        // Dashboard submenu
        add_submenu_page(
            'ace-seo',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'ace-seo',
            array( $this, 'render_dashboard_page' )
        );
        
        // Settings submenu
        add_submenu_page(
            'ace-seo',
            'Settings',
            'Settings',
            'manage_options',
            'ace-seo-settings',
            array( $this, 'render_settings_page' )
        );
        
        // Tools submenu
        add_submenu_page(
            'ace-seo',
            'Tools',
            'Tools',
            'manage_options',
            'ace-seo-tools',
            array( $this, 'render_tools_page' )
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        // General settings
        register_setting( 'ace_seo_general', 'ace_seo_settings' );
        register_setting( 'ace_seo_social', 'ace_seo_social_settings' );
        register_setting( 'ace_seo_advanced', 'ace_seo_advanced_settings' );
        register_setting( 'ace_seo_compatibility', 'ace_seo_compatibility_settings' );
        
        // General settings section
        add_settings_section(
            'ace_seo_general_section',
            'General Settings',
            array( $this, 'general_section_callback' ),
            'ace_seo_general'
        );
        
        // Social settings section
        add_settings_section(
            'ace_seo_social_section',
            'Social Media Settings',
            array( $this, 'social_section_callback' ),
            'ace_seo_social'
        );
        
        // Advanced settings section
        add_settings_section(
            'ace_seo_advanced_section',
            'Advanced Settings',
            array( $this, 'advanced_section_callback' ),
            'ace_seo_advanced'
        );
        
        // Compatibility settings section
        add_settings_section(
            'ace_seo_compatibility_section',
            'Yoast Compatibility',
            array( $this, 'compatibility_section_callback' ),
            'ace_seo_compatibility'
        );
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        include ACE_SEO_PATH . 'includes/admin/views/dashboard.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        include ACE_SEO_PATH . 'includes/admin/views/settings.php';
    }
    
    /**
     * Render tools page
     */
    public function render_tools_page() {
        // Handle migration request
        if (isset($_POST['migrate_yoast_data']) && wp_verify_nonce($_POST['_wpnonce'], 'ace_seo_migrate_yoast')) {
            $migrated = AceCrawlEnhancer::bulk_migrate_yoast_data();
            echo '<div class="notice notice-success"><p><strong>Migration Complete:</strong> Migrated ' . $migrated . ' fields from Yoast SEO.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Ace SEO Tools</h1>
            
            <!-- Migration Tool -->
            <div class="card">
                <h2>Data Migration</h2>
                <h3>Migrate from Yoast SEO</h3>
                <p>If you have existing Yoast SEO data, you can migrate it to Ace SEO. This will copy your SEO titles, meta descriptions, focus keywords, and other settings while preserving your original Yoast data.</p>
                
                <?php
                global $wpdb;
                $yoast_posts_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT post_id) 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key LIKE %s
                ", '_yoast_wpseo_%'));
                
                $ace_posts_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT post_id) 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key LIKE %s
                ", '_ace_seo_%'));
                ?>
                
                <p><strong>Status:</strong></p>
                <ul>
                    <li>Posts with Yoast SEO data: <?php echo $yoast_posts_count; ?></li>
                    <li>Posts with Ace SEO data: <?php echo $ace_posts_count; ?></li>
                </ul>
                
                <?php if ($yoast_posts_count > 0): ?>
                    <form method="post" action="">
                        <?php wp_nonce_field('ace_seo_migrate_yoast'); ?>
                        <input type="submit" name="migrate_yoast_data" class="button button-primary" value="Migrate Yoast SEO Data" onclick="return confirm('This will migrate SEO data from Yoast to Ace SEO. This is safe and won\'t delete your Yoast data. Continue?');">
                    </form>
                <?php else: ?>
                    <p><em>No Yoast SEO data found to migrate.</em></p>
                <?php endif; ?>
            </div>
            
            <!-- Coming Soon Tools -->
            <div class="card">
                <h2>Coming Soon</h2>
                <p>Additional SEO tools and utilities will be available in future updates:</p>
                <ul>
                    <li>Bulk SEO optimization</li>
                    <li>Content analysis reports</li>
                    <li>Broken link checker</li>
                    <li>Redirect manager</li>
                    <li>SEO audit tool</li>
                    <li>Sitemap management</li>
                    <li>Schema markup tools</li>
                </ul>
            </div>
        </div>
        
        <style>
        .card {
            max-width: none;
            margin: 20px 0;
        }
        .card h3 {
            margin-top: 0;
        }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for Yoast data migration
     */
    public function ajax_migrate_yoast_data() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ace_seo_migrate_yoast')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }
        
        $migrated = AceCrawlEnhancer::bulk_migrate_yoast_data();
        
        wp_send_json_success(array(
            'migrated' => $migrated,
            'message' => sprintf('Successfully migrated %d SEO fields from Yoast SEO.', $migrated)
        ));
    }
    
    /**
     * Section callbacks
     */
    public function general_section_callback() {
        echo '<p>Configure general SEO settings for your website.</p>';
    }
    
    public function social_section_callback() {
        echo '<p>Configure social media optimization settings.</p>';
    }
    
    public function advanced_section_callback() {
        echo '<p>Advanced SEO configuration options.</p>';
    }
    
    public function compatibility_section_callback() {
        echo '<p>Settings related to Yoast SEO compatibility and migration.</p>';
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts( $hook ) {
        // Only load on our admin pages
        if ( strpos( $hook, 'ace-seo' ) === false ) {
            return;
        }
        
        wp_enqueue_style(
            'ace-seo-admin',
            ACE_SEO_URL . 'assets/css/admin.css',
            array(),
            ACE_SEO_VERSION
        );
        
        wp_enqueue_script(
            'ace-seo-admin',
            ACE_SEO_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            ACE_SEO_VERSION,
            true
        );
        
        wp_enqueue_media(); // For image selection
    }
    
    /**
     * Get setting value with default
     */
    public static function get_setting( $option_group, $key, $default = '' ) {
        $options = get_option( $option_group, array() );
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }
    
    /**
     * Update setting value
     */
    public static function update_setting( $option_group, $key, $value ) {
        $options = get_option( $option_group, array() );
        $options[ $key ] = $value;
        return update_option( $option_group, $options );
    }
}

// Initialize the settings class
new AceSEOSettings();
