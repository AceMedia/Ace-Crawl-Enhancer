<?php
/**
 * Ace SEO Settings Handler
 * Handles the plugin settings pages and configuration
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.3
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
        add_action( 'wp_ajax_ace_seo_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_ace_seo_migrate_yoast', array( $this, 'ajax_migrate_yoast_data' ) );
        add_action( 'wp_ajax_ace_seo_batch_migrate_yoast', array( $this, 'ajax_batch_migrate_yoast_data' ) );
        add_action( 'wp_ajax_ace_seo_get_migration_stats', array( $this, 'ajax_get_migration_stats' ) );
        add_action( 'wp_ajax_ace_seo_optimize_database_manual', array( $this, 'ajax_optimize_database_manual' ) );
        add_action( 'wp_ajax_ace_seo_refresh_dashboard_cache', array( $this, 'ajax_refresh_dashboard_cache' ) );
        add_action( 'wp_ajax_ace_seo_clear_dashboard_cache', array( $this, 'ajax_clear_dashboard_cache' ) );
        add_action( 'wp_ajax_ace_seo_get_yoast_key_counts', array( $this, 'ajax_get_yoast_key_counts' ) );
        add_action( 'wp_ajax_ace_seo_delete_yoast_keys', array( $this, 'ajax_delete_yoast_keys' ) );
        add_action( 'wp_ajax_ace_seo_recreate_yoast_keys', array( $this, 'ajax_recreate_yoast_keys' ) );
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
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#tools' ) );
        exit;
    }

    private function get_meta_key_count( $table, $key_like ) {
        global $wpdb;
        $like = $wpdb->esc_like( $key_like ) . '%';
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key LIKE %s", $like ) );
    }

    private function get_yoast_key_counts() {
        global $wpdb;
        return array(
            'postmeta' => $this->get_meta_key_count( $wpdb->postmeta, '_yoast_wpseo_' ),
            'termmeta' => $this->get_meta_key_count( $wpdb->termmeta, '_yoast_wpseo_' ),
            'ace_postmeta' => $this->get_meta_key_count( $wpdb->postmeta, ACE_SEO_META_PREFIX ),
            'ace_termmeta' => $this->get_meta_key_count( $wpdb->termmeta, ACE_SEO_META_PREFIX ),
        );
    }

    public function ajax_get_yoast_key_counts() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
        check_ajax_referer( 'ace_seo_yoast_tools', 'nonce' );

        wp_send_json_success( $this->get_yoast_key_counts() );
    }

    public function ajax_delete_yoast_keys() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
        check_ajax_referer( 'ace_seo_yoast_tools', 'nonce' );

        global $wpdb;
        $like = $wpdb->esc_like( '_yoast_wpseo_' ) . '%';
        $deleted_postmeta = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like ) );
        $deleted_termmeta = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $like ) );

        wp_send_json_success( array(
            'deleted_postmeta' => (int) $deleted_postmeta,
            'deleted_termmeta' => (int) $deleted_termmeta,
        ) );
    }

    public function ajax_recreate_yoast_keys() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
        check_ajax_referer( 'ace_seo_yoast_tools', 'nonce' );

        global $wpdb;
        $ace_prefix = ACE_SEO_META_PREFIX;
        $yoast_prefix = '_yoast_wpseo_';

        $like_yoast = $wpdb->esc_like( $yoast_prefix ) . '%';
        $like_ace = $wpdb->esc_like( $ace_prefix ) . '%';

        // Clear existing Yoast keys first to avoid duplicates.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like_yoast ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $like_yoast ) );

        // Recreate from ACE keys.
        $insert_posts = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT post_id,
                    CONCAT(%s, SUBSTRING(meta_key, %d)),
                    meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key LIKE %s",
            $yoast_prefix,
            strlen( $ace_prefix ) + 1,
            $like_ace
        ) );

        $insert_terms = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value)
             SELECT term_id,
                    CONCAT(%s, SUBSTRING(meta_key, %d)),
                    meta_value
             FROM {$wpdb->termmeta}
             WHERE meta_key LIKE %s",
            $yoast_prefix,
            strlen( $ace_prefix ) + 1,
            $like_ace
        ) );

        wp_send_json_success( array(
            'inserted_postmeta' => (int) $insert_posts,
            'inserted_termmeta' => (int) $insert_terms,
        ) );
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

        if ( 'ace-seo_page_ace-seo-settings' === $hook ) {
            wp_enqueue_style(
                'ace-seo-admin-redis',
                ACE_SEO_URL . 'assets/css/admin-redis.css',
                array( 'ace-seo-admin' ),
                ACE_SEO_VERSION
            );
        }
        
        $asset_path = ACE_SEO_PATH . 'build/index.asset.php';
        $asset_data = file_exists( $asset_path ) ? include $asset_path : array(
            'dependencies' => array( 'jquery' ),
            'version' => ACE_SEO_VERSION,
        );

        $script_deps = isset( $asset_data['dependencies'] ) && is_array( $asset_data['dependencies'] )
            ? $asset_data['dependencies']
            : array();

        if ( ! in_array( 'jquery', $script_deps, true ) ) {
            $script_deps[] = 'jquery';
        }

        wp_enqueue_script(
            'ace-seo-admin',
            ACE_SEO_URL . 'build/index.js',
            $script_deps,
            isset( $asset_data['version'] ) ? $asset_data['version'] : ACE_SEO_VERSION,
            true
        );
        
        wp_enqueue_media(); // For image selection
        
        // Localize script for AJAX
        wp_localize_script('ace-seo-admin', 'ace_seo_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url(),
            'nonce' => wp_create_nonce('ace_seo_admin_nonce'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
        ]);
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
    
    /**
     * AJAX handler for saving settings
     */
    public function ajax_save_settings() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ace_seo_admin_nonce')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
        }

        $options = get_option('ace_seo_options', []);
        
        // Ensure we have an array - handle corrupted data
        if (!is_array($options)) {
            $options = [];
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
        
        // Update general settings
        $options['general']['separator'] = sanitize_text_field(wp_unslash($_POST['separator'] ?? '|'));
        $options['general']['site_name'] = sanitize_text_field(wp_unslash($_POST['site_name'] ?? ''));
        $options['general']['home_title'] = sanitize_text_field(wp_unslash($_POST['home_title'] ?? ''));
        $options['general']['home_description'] = sanitize_textarea_field(wp_unslash($_POST['home_description'] ?? ''));
        
        // Update template settings
        $options['templates']['title_template_post'] = sanitize_text_field(wp_unslash($_POST['title_template_post'] ?? ''));
        $options['templates']['meta_template_post'] = sanitize_textarea_field(wp_unslash($_POST['meta_template_post'] ?? ''));
        $options['templates']['title_template_page'] = sanitize_text_field(wp_unslash($_POST['title_template_page'] ?? ''));
        $options['templates']['title_template_category'] = sanitize_text_field(wp_unslash($_POST['title_template_category'] ?? ''));

        // Update title templates for each post type
        $post_types = get_post_types(['public' => true], 'objects');
        foreach ($post_types as $post_type) {
            if ($post_type->name === 'attachment') {
                continue;
            }

            $template_key = 'title_template_' . $post_type->name;
            $meta_template_key = 'meta_template_' . $post_type->name;

            $options['templates'][$template_key] = sanitize_text_field(wp_unslash($_POST[$template_key] ?? ''));
            $options['templates'][$meta_template_key] = sanitize_textarea_field(wp_unslash($_POST[$meta_template_key] ?? ''));
        }

        // Update archive/special page templates
        $special_template_keys = ['archive', 'search', 'author', 'category', 'tag', 'date'];
        foreach ($special_template_keys as $template_type) {
            $template_key = 'title_template_' . $template_type;
            $meta_template_key = 'meta_template_' . $template_type;
            if (isset($_POST[$template_key])) {
                $options['templates'][$template_key] = sanitize_text_field(wp_unslash($_POST[$template_key]));
            }
            if (isset($_POST[$meta_template_key])) {
                $options['templates'][$meta_template_key] = sanitize_textarea_field(wp_unslash($_POST[$meta_template_key]));
            }
        }

        // Update custom post type archive templates
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom_post_types as $post_type) {
            if ($post_type->has_archive) {
                $archive_title_key = 'title_template_archive_' . $post_type->name;
                $archive_meta_key = 'meta_template_archive_' . $post_type->name;

                if (isset($_POST[$archive_title_key])) {
                    $options['templates'][$archive_title_key] = sanitize_text_field(wp_unslash($_POST[$archive_title_key]));
                }
                if (isset($_POST[$archive_meta_key])) {
                    $options['templates'][$archive_meta_key] = sanitize_textarea_field(wp_unslash($_POST[$archive_meta_key]));
                }
            }
        }
        
        // Update social settings
        $options['social']['facebook_app_id'] = sanitize_text_field(wp_unslash($_POST['facebook_app_id'] ?? ''));
        $options['social']['default_image'] = esc_url_raw(wp_unslash($_POST['default_image'] ?? ''));
        
        // Update organization settings
        $options['organization']['name'] = sanitize_text_field(wp_unslash($_POST['organization_name'] ?? ''));
        $options['organization']['url'] = esc_url_raw(wp_unslash($_POST['organization_url'] ?? ''));
        $options['organization']['description'] = sanitize_textarea_field(wp_unslash($_POST['organization_description'] ?? ''));

        $options['organization']['legal_name'] = sanitize_text_field(wp_unslash($_POST['organization_legal_name'] ?? ''));
        $options['organization']['alternate_name'] = sanitize_text_field(wp_unslash($_POST['organization_alternate_name'] ?? ''));
        $options['organization']['logo_id'] = absint($_POST['organization_logo_id'] ?? 0);
        $options['organization']['logo_url'] = esc_url_raw(wp_unslash($_POST['organization_logo_url'] ?? ''));
        $options['organization']['contact_type'] = sanitize_text_field(wp_unslash($_POST['organization_contact_type'] ?? ''));
        $options['organization']['contact_phone'] = sanitize_text_field(wp_unslash($_POST['organization_contact_phone'] ?? ''));
        $options['organization']['contact_email'] = sanitize_email(wp_unslash($_POST['organization_contact_email'] ?? ''));
        $options['organization']['contact_url'] = esc_url_raw(wp_unslash($_POST['organization_contact_url'] ?? ''));

        $twitter_username = sanitize_text_field(wp_unslash($_POST['organization_twitter_username'] ?? ''));
        $twitter_username = preg_replace('/\s+/', '', $twitter_username);
        if (!empty($twitter_username)) {
            $twitter_username = '@' . ltrim($twitter_username, '@');
        }
        $options['organization']['twitter_username'] = $twitter_username;
        $options['organization']['social_facebook'] = esc_url_raw(wp_unslash($_POST['organization_social_facebook'] ?? ''));
        $options['organization']['social_instagram'] = esc_url_raw(wp_unslash($_POST['organization_social_instagram'] ?? ''));
        $options['organization']['social_linkedin'] = esc_url_raw(wp_unslash($_POST['organization_social_linkedin'] ?? ''));
        $options['organization']['social_youtube'] = esc_url_raw(wp_unslash($_POST['organization_social_youtube'] ?? ''));
        $options['organization']['type'] = sanitize_text_field(wp_unslash($_POST['organization_type'] ?? 'organization'));

        if (!empty($twitter_username)) {
            $options['organization']['social_twitter'] = esc_url_raw('https://twitter.com/' . ltrim($twitter_username, '@'));
        } else {
            $options['organization']['social_twitter'] = '';
        }

        // Update person settings
        $options['person']['name'] = sanitize_text_field(wp_unslash($_POST['person_name'] ?? ''));
        $options['person']['job_title'] = sanitize_text_field(wp_unslash($_POST['person_job_title'] ?? ''));
        $options['person']['url'] = esc_url_raw(wp_unslash($_POST['person_url'] ?? ''));
        $options['person']['description'] = sanitize_textarea_field(wp_unslash($_POST['person_description'] ?? ''));
        $options['person']['image_id'] = absint($_POST['person_image_id'] ?? 0);
        $options['person']['image_url'] = esc_url_raw(wp_unslash($_POST['person_image_url'] ?? ''));
        $options['person']['twitter_username'] = sanitize_text_field(wp_unslash($_POST['person_twitter_username'] ?? ''));
        if (!empty($options['person']['twitter_username'])) {
            $options['person']['twitter_username'] = '@' . ltrim($options['person']['twitter_username'], '@');
        }

        $person_same_as_input = array_map('trim', (array) (wp_unslash($_POST['person_same_as'] ?? [])));
        $options['person']['same_as'] = array_values(array_filter(array_unique(array_map('esc_url_raw', $person_same_as_input))));

        // Update webmaster verification codes
        $options['webmaster'] = isset($options['webmaster']) && is_array($options['webmaster']) ? $options['webmaster'] : [];
        $options['webmaster']['ahrefs'] = sanitize_text_field(wp_unslash($_POST['webmaster_ahrefs'] ?? ''));
        $options['webmaster']['baidu'] = sanitize_text_field(wp_unslash($_POST['webmaster_baidu'] ?? ''));
        $options['webmaster']['bing'] = sanitize_text_field(wp_unslash($_POST['webmaster_bing'] ?? ''));
        $options['webmaster']['google'] = sanitize_text_field(wp_unslash($_POST['webmaster_google'] ?? ''));
        $options['webmaster']['pinterest'] = sanitize_text_field(wp_unslash($_POST['webmaster_pinterest'] ?? ''));
        $options['webmaster']['yandex'] = sanitize_text_field(wp_unslash($_POST['webmaster_yandex'] ?? ''));

        // Maintain legacy keys for backwards compatibility
        $options['webmaster']['google_verify'] = $options['webmaster']['google'];
        $options['webmaster']['bing_verify'] = $options['webmaster']['bing'];
        
        // Update advanced settings
        $options['advanced']['enable_breadcrumbs'] = isset($_POST['enable_breadcrumbs']) ? 1 : 0;
        $options['advanced']['clean_permalinks'] = isset($_POST['clean_permalinks']) ? 1 : 0;
        unset($options['advanced']['enable_sitemap']);
        
        // Update AI settings
        $options['ai']['enable_ai_assistant'] = isset($_POST['enable_ai_assistant']) ? 1 : 0;
        $options['ai']['api_key'] = sanitize_text_field(wp_unslash($_POST['ai_api_key'] ?? ''));
        $options['ai']['openai_api_key'] = sanitize_text_field(wp_unslash($_POST['openai_api_key'] ?? ''));
        $options['ai']['ai_content_analysis'] = isset($_POST['ai_content_analysis']) ? 1 : 0;
        $options['ai']['ai_keyword_suggestions'] = isset($_POST['ai_keyword_suggestions']) ? 1 : 0;
        $options['ai']['ai_content_optimization'] = isset($_POST['ai_content_optimization']) ? 1 : 0;
        $options['ai']['ai_image_generation'] = isset($_POST['ai_image_generation']) ? 1 : 0;
        
        // Update performance settings
        $options['performance']['enable_tracking'] = isset($_POST['enable_performance_tracking']) ? 1 : 0;
        $options['performance']['cache_timeout'] = absint($_POST['cache_timeout'] ?? 3600);
        $options['performance']['pagespeed_api_key'] = sanitize_text_field(wp_unslash($_POST['pagespeed_api_key'] ?? ''));
        $options['performance']['pagespeed_monitoring'] = isset($_POST['pagespeed_monitoring']) ? 1 : 0;
        $options['performance']['pagespeed_alerts'] = isset($_POST['pagespeed_alerts']) ? 1 : 0;
        $options['performance']['core_web_vitals'] = isset($_POST['core_web_vitals']) ? 1 : 0;

        $sitemap_options_updated = false;
        if (isset($_POST['ace_sitemap_powertools_options'])) {
            $raw_sitemap_options = wp_unslash($_POST['ace_sitemap_powertools_options']);
            if (!is_array($raw_sitemap_options)) {
                $raw_sitemap_options = [];
            }

            if (function_exists('ace_sitemap_powertools_sanitize_options')) {
                $sanitized_sitemap_options = ace_sitemap_powertools_sanitize_options($raw_sitemap_options);
            } else {
                $sanitized_sitemap_options = $raw_sitemap_options;
            }

            $sitemap_options_updated = update_option('ace_sitemap_powertools_options', $sanitized_sitemap_options);
        }
        
        // Save options
        $updated = update_option('ace_seo_options', $options);
        
        if ($updated || $sitemap_options_updated) {
            wp_send_json_success(['message' => 'Settings saved successfully']);
        } else {
            wp_send_json_error('Failed to save settings');
        }
    }
    
    /**
     * AJAX handler for manual database optimization
     */
    public function ajax_optimize_database_manual() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ace_seo_optimize_db_manual')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }
        
        // Include the database optimizer
        if (!class_exists('ACE_SEO_Database_Optimizer')) {
            if (file_exists(ACE_SEO_PATH . 'includes/database/class-database-optimizer.php')) {
                require_once ACE_SEO_PATH . 'includes/database/class-database-optimizer.php';
            } else {
                wp_send_json_error('Database optimizer class not found');
                return;
            }
        }
        
        try {
            $optimizer = new ACE_SEO_Database_Optimizer();
            $results = $optimizer->create_indexes();
            
            // Log the optimization results
            error_log('ACE SEO Manual Optimization: Database indexes updated - ' . print_r($results, true));
            
            // Update optimization status
            update_option('ace_seo_db_optimized', current_time('mysql'));
            update_option('ace_seo_db_optimization_results', $results);
            delete_option('ace_seo_db_optimization_pending');
            
            wp_send_json_success($results);
            
        } catch (Exception $e) {
            error_log('ACE SEO Manual Optimization Error: ' . $e->getMessage());
            wp_send_json_error('Database optimization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for refreshing dashboard cache
     */
    public function ajax_refresh_dashboard_cache() {
        // Check permissions and nonce
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        check_ajax_referer('ace_seo_admin', 'nonce');
        
        try {
            // Load dashboard cache class
            if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
            }
            
            // Force regenerate cache
            $result = ACE_SEO_Dashboard_Cache::force_regenerate();
            
            // Get updated cache status
            $cache_status = ACE_SEO_Dashboard_Cache::get_cache_status();
            
            wp_send_json_success(array(
                'message' => 'Dashboard cache refreshed successfully!',
                'stats' => array(
                    'focus_keywords' => $result['stats']['focus_keywords_count'],
                    'meta_descriptions' => $result['stats']['meta_desc_count'],
                    'total_posts' => $result['stats']['total_posts']
                ),
                'recent_posts_count' => count($result['recent_posts']),
                'regenerated_at' => date('Y-m-d H:i:s', $result['regenerated_at']),
                'cache_status' => $cache_status
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to refresh cache: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for clearing dashboard cache
     */
    public function ajax_clear_dashboard_cache() {
        // Check permissions and nonce
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        check_ajax_referer('ace_seo_admin', 'nonce');
        
        try {
            // Load dashboard cache class
            if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
            }
            
            // Clear all cache
            $cleared = ACE_SEO_Dashboard_Cache::clear_cache();
            
            // Get updated cache status after clearing
            $cache_status = ACE_SEO_Dashboard_Cache::get_cache_status();
            
            wp_send_json_success(array(
                'message' => 'Dashboard cache cleared successfully!',
                'note' => 'Cache will be regenerated on next dashboard visit.',
                'cache_status' => $cache_status
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to clear cache: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for batch Yoast data migration
     */
    public function ajax_batch_migrate_yoast_data() {
        // Security check
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, 'ace_seo_batch_migrate')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 10;
        if ($batch_size <= 0) {
            $batch_size = 10;
        }
        $batch_size = min($batch_size, 100);
        
        global $wpdb;
        
        // Get posts with Yoast data that haven't been fully migrated yet
        $posts = $wpdb->get_results($wpdb->prepare(" 
            SELECT DISTINCT p.ID, p.post_title, p.post_type 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} ace_check ON (p.ID = ace_check.post_id AND ace_check.meta_key = '_ace_seo_migration_check')
            WHERE pm.meta_key LIKE '_yoast_wpseo_%'
            AND (ace_check.meta_value IS NULL OR ace_check.meta_value < %d)
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
            ORDER BY p.ID ASC
            LIMIT %d
        ", time() - (7 * DAY_IN_SECONDS), $batch_size));
        
        $processed = 0;
        $migrated = 0;
        $errors = [];
        $current_item = null;
        
        // Process posts
        foreach ($posts as $post) {
            try {
                $current_item = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => 'post: ' . $post->post_type
                ];
                
                // Migrate this post's Yoast data
                $post_migrated = AceCrawlEnhancer::migrate_yoast_data($post->ID);
                $migrated += $post_migrated;
                $processed++;
                
                // Mark as checked
                update_post_meta($post->ID, '_ace_seo_migration_check', time());
                
                // Add small delay to prevent server overload
                usleep(50000); // 50ms delay
                
            } catch (Exception $e) {
                $errors[] = "Error processing post {$post->ID} ({$post->post_title}): " . $e->getMessage();
            }
        }
        
        // If we processed fewer posts than batch size, process pending taxonomies.
        if (count($posts) < $batch_size) {
            $yoast_tax_meta = get_option('wpseo_taxonomy_meta', []);
            $taxonomy_processed = 0;
            $remaining_batch_size = $batch_size - count($posts);

            foreach ($yoast_tax_meta as $taxonomy => $terms) {
                foreach ($terms as $term_id => $meta_data) {
                    if ($taxonomy_processed >= $remaining_batch_size) {
                        break 2;
                    }

                    try {
                        // Check if already migrated recently
                        $migration_check = get_term_meta($term_id, '_ace_seo_taxonomy_migration_check', true);
                        if (!empty($migration_check) && (time() - $migration_check) <= (7 * DAY_IN_SECONDS)) {
                            continue;
                        }

                        $term = get_term($term_id, $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            $current_item = [
                                'id' => $term_id,
                                'title' => $term->name,
                                'type' => 'taxonomy: ' . $taxonomy
                            ];
                            
                            // Migrate this term's Yoast data
                            $tax_migrated = AceCrawlEnhancer::migrate_yoast_taxonomy_data($term_id, $taxonomy);
                            $migrated += $tax_migrated;
                            $processed++;
                            $taxonomy_processed++;
                            
                            // Mark as checked
                            update_term_meta($term_id, '_ace_seo_taxonomy_migration_check', time());
                            
                            // Add small delay
                            usleep(50000); // 50ms delay
                        }

                    } catch (Exception $e) {
                        $errors[] = "Error processing taxonomy term {$term_id} ({$taxonomy}): " . $e->getMessage();
                    }
                }
            }
        }
        
        // Check if migration is complete for posts
        $remaining_posts = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} ace_check ON (p.ID = ace_check.post_id AND ace_check.meta_key = '_ace_seo_migration_check')
            WHERE pm.meta_key LIKE '_yoast_wpseo_%'
            AND (ace_check.meta_value IS NULL OR ace_check.meta_value < %d)
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
        ", time() - (7 * DAY_IN_SECONDS)));
        
        // Check remaining taxonomies
        $yoast_tax_meta = get_option('wpseo_taxonomy_meta', []);
        $remaining_taxonomies = 0;
        
        foreach ($yoast_tax_meta as $taxonomy => $terms) {
            foreach ($terms as $term_id => $meta_data) {
                $migration_check = get_term_meta($term_id, '_ace_seo_taxonomy_migration_check', true);
                if (empty($migration_check) || (time() - $migration_check) > (7 * DAY_IN_SECONDS)) {
                    $remaining_taxonomies++;
                }
            }
        }
        
        $remaining = (int) $remaining_posts + (int) $remaining_taxonomies;
        $completed = ($remaining === 0);
        
        wp_send_json_success([
            'processed' => $processed,
            'migrated' => $migrated,
            'errors' => $errors,
            'current_item' => $current_item,
            'completed' => $completed,
            'remaining' => $remaining,
            'remaining_posts' => (int) $remaining_posts,
            'remaining_taxonomies' => (int) $remaining_taxonomies
        ]);
    }
    
    /**
     * AJAX handler for getting migration statistics
     */
    public function ajax_get_migration_stats() {
        // Security check
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, 'ace_seo_migration_stats')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }

        $stats = AceCrawlEnhancer::get_migration_stats();
        $stats['pending_total'] = (int) ($stats['pending_migration'] ?? 0) + (int) ($stats['pending_tax_migration'] ?? 0);
        wp_send_json_success($stats);
    }
}

// Initialize the settings class
new AceSEOSettings();
