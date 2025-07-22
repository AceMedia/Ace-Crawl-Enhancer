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
        echo '<div class="wrap">';
        echo '<h1>Ace SEO Tools</h1>';
        echo '<div class="card">';
        echo '<h2>Coming Soon</h2>';
        echo '<p>Advanced SEO tools and utilities will be available in future updates.</p>';
        echo '<ul>';
        echo '<li>Bulk SEO optimization</li>';
        echo '<li>Content analysis reports</li>';
        echo '<li>Broken link checker</li>';
        echo '<li>Redirect manager</li>';
        echo '<li>SEO audit tool</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';
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
