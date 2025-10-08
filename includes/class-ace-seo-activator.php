<?php
/**
 * Plugin activation and deactivation hooks
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activation handler
 */
class AceSEOActivator {
    
    /**
     * Run on plugin activation
     */
    public static function activate() {
        // Check WordPress version
        if ( ! self::check_requirements() ) {
            return;
        }
        
        // Set default options
        self::set_default_options();
        
        // Create necessary database tables
        self::create_tables();
        
        // Schedule database optimization for background processing
        self::schedule_database_optimization();
        
        // Set up rewrite rules
        self::setup_rewrites();
        
        // Schedule any cron jobs
        self::schedule_events();
        
        // Set activation flag
        update_option( 'ace_seo_activation_date', current_time( 'mysql' ) );
        update_option( 'ace_seo_version', ACE_SEO_VERSION );
    }
    
    /**
     * Check if requirements are met
     */
    private static function check_requirements() {
        global $wp_version;
        
        // Check WordPress version
        if ( version_compare( $wp_version, '6.0', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 
                __( 'Ace SEO requires WordPress 6.0 or higher.', 'ace-crawl-enhancer' ),
                __( 'Plugin Activation Error', 'ace-crawl-enhancer' ),
                array( 'back_link' => true )
            );
            return false;
        }
        
        // Check PHP version
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 
                __( 'Ace SEO requires PHP 7.4 or higher.', 'ace-crawl-enhancer' ),
                __( 'Plugin Activation Error', 'ace-crawl-enhancer' ),
                array( 'back_link' => true )
            );
            return false;
        }
        
        return true;
    }
    
    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        // Unified settings structure
        if ( ! get_option( 'ace_seo_options' ) ) {
            $default_options = array(
                'general' => array(
                    'separator' => '|',
                    'site_name' => get_bloginfo( 'name' ),
                    'home_title' => '',
                    'home_description' => get_bloginfo( 'description' ),
                ),
                'social' => array(
                    'facebook_app_id' => '',
                    'twitter_username' => '',
                    'default_image' => '',
                ),
                'advanced' => array(
                    'xml_sitemap' => 1,
                    'clean_permalinks' => 0,
                ),
                'ai' => array(
                    'openai_api_key' => '',
                    'ai_content_analysis' => 0,
                    'ai_keyword_suggestions' => 0,
                    'ai_content_optimization' => 0,
                    'ai_web_search' => 0,
                ),
                'performance' => array(
                    'pagespeed_api_key' => '',
                    'pagespeed_monitoring' => 0,
                    'pagespeed_alerts' => 0,
                    'core_web_vitals' => 1,
                ),
            );
            update_option( 'ace_seo_options', $default_options );
        }
        
        // Legacy settings for backward compatibility
        if ( ! get_option( 'ace_seo_settings' ) ) {
            $default_settings = array(
                'site_name' => get_bloginfo( 'name' ),
                'site_description' => get_bloginfo( 'description' ),
                'separator' => '-',
                'schema_enabled' => true,
                'sitemap_enabled' => true,
                'conflict_detection' => true
            );
            update_option( 'ace_seo_settings', $default_settings );
        }
        
        // Social settings
        if ( ! get_option( 'ace_seo_social_settings' ) ) {
            $social_settings = array(
                'og_enabled' => true,
                'twitter_enabled' => true,
                'default_og_image' => '',
                'facebook_app_id' => '',
                'twitter_username' => '',
                'twitter_card_type' => 'summary_large_image'
            );
            update_option( 'ace_seo_social_settings', $social_settings );
        }
        
        // Advanced settings  
        if ( ! get_option( 'ace_seo_advanced_settings' ) ) {
            $advanced_settings = array(
                'noindex_empty_categories' => true,
                'noindex_paginated_pages' => false,
                'force_rewrite_titles' => true,
                'disable_attachment_pages' => true,
                'redirect_attachment_urls' => true,
                'clean_head' => true
            );
            update_option( 'ace_seo_advanced_settings', $advanced_settings );
        }
    }
    
    /**
     * Create necessary database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Analytics table for tracking SEO performance
        $analytics_table = $wpdb->prefix . 'ace_seo_analytics';
        $analytics_sql = "CREATE TABLE IF NOT EXISTS $analytics_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            date_recorded datetime DEFAULT CURRENT_TIMESTAMP,
            seo_score int(3) DEFAULT 0,
            readability_score int(3) DEFAULT 0,
            focus_keyword varchar(255) DEFAULT '',
            title_length int(3) DEFAULT 0,
            description_length int(3) DEFAULT 0,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY date_recorded (date_recorded)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $analytics_sql );
    }
    
    /**
     * Setup rewrite rules for sitemaps
     */
    private static function setup_rewrites() {
        // Add sitemap rewrite rules
        add_rewrite_rule( 
            '^sitemap\.xml$', 
            'index.php?ace_seo_sitemap=index', 
            'top' 
        );
        add_rewrite_rule( 
            '^sitemap-([^/]+)\.xml$', 
            'index.php?ace_seo_sitemap=$matches[1]', 
            'top' 
        );
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Schedule database optimization for background processing
     */
    private static function schedule_database_optimization() {
        // Schedule immediate background optimization
        if ( ! wp_next_scheduled( 'ace_seo_optimize_database' ) ) {
            // Schedule for 30 seconds from now to allow activation to complete
            wp_schedule_single_event( time() + 30, 'ace_seo_optimize_database' );
            
            // Set a flag that optimization is pending
            update_option( 'ace_seo_db_optimization_pending', true );
            update_option( 'ace_seo_db_optimization_scheduled', current_time( 'mysql' ) );
        }
    }
    
    /**
     * Optimize database performance with indexes (background task)
     */
    private static function optimize_database() {
        // Include the database optimizer
        if ( file_exists( ACE_SEO_PATH . 'includes/database/class-database-optimizer.php' ) ) {
            require_once ACE_SEO_PATH . 'includes/database/class-database-optimizer.php';
            
            if ( class_exists( 'ACE_SEO_Database_Optimizer' ) ) {
                $optimizer = new ACE_SEO_Database_Optimizer();
                $results = $optimizer->create_indexes();
                
                // Log the optimization results
                error_log( 'ACE SEO Background: Database optimization completed - ' . print_r( $results, true ) );
                
                // Clear pending flag and store completion status
                delete_option( 'ace_seo_db_optimization_pending' );
                update_option( 'ace_seo_db_optimized', current_time( 'mysql' ) );
                update_option( 'ace_seo_db_optimization_results', $results );
            }
        }
    }
    
    /**
     * Schedule cron events
     */
    private static function schedule_events() {
        // Schedule sitemap generation
        if ( ! wp_next_scheduled( 'ace_seo_generate_sitemap' ) ) {
            wp_schedule_event( time(), 'daily', 'ace_seo_generate_sitemap' );
        }
        
        // Schedule analytics cleanup
        if ( ! wp_next_scheduled( 'ace_seo_cleanup_analytics' ) ) {
            wp_schedule_event( time(), 'weekly', 'ace_seo_cleanup_analytics' );
        }
        
        // Hook the background database optimization task
        add_action( 'ace_seo_optimize_database', array( __CLASS__, 'optimize_database' ) );
    }
}

/**
 * Deactivation handler
 */
class AceSEODeactivator {
    
    /**
     * Run on plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        self::clear_scheduled_events();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Clear cache
        wp_cache_flush();
    }
    
    /**
     * Clear scheduled cron events
     */
    private static function clear_scheduled_events() {
        wp_clear_scheduled_hook( 'ace_seo_generate_sitemap' );
        wp_clear_scheduled_hook( 'ace_seo_cleanup_analytics' );
        wp_clear_scheduled_hook( 'ace_seo_optimize_database' );
        
        // Clear optimization flags
        delete_option( 'ace_seo_db_optimization_pending' );
    }
}
