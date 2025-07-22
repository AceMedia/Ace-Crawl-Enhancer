<?php
/**
 * Uninstall functionality for Ace Crawl Enhancer
 * This file is called when the plugin is deleted from WordPress admin
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

// If uninstall not called from WordPress, then exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up plugin data on uninstall
 * Note: We preserve Yoast-compatible meta data so users can switch back or to other Yoast-compatible plugins
 */
class AceSEOUninstaller {
    
    /**
     * Run uninstall process
     */
    public static function uninstall() {
        // Remove plugin options (but preserve post meta for compatibility)
        self::remove_options();
        
        // Clean up transients
        self::clean_transients();
        
        // Remove custom database tables if any were created
        self::remove_custom_tables();
        
        // Clear any cached data
        self::clear_cache();
    }
    
    /**
     * Remove plugin options from database
     */
    private static function remove_options() {
        $options_to_remove = array(
            'ace_seo_settings',
            'ace_seo_social_settings', 
            'ace_seo_advanced_settings',
            'ace_seo_compatibility_settings',
            'ace_seo_sitemap_settings',
            'ace_seo_dashboard_settings',
            'ace_seo_version',
            'ace_seo_activation_date',
            'ace_seo_usage_stats',
            'ace_seo_wizard_completed'
        );
        
        foreach ( $options_to_remove as $option ) {
            delete_option( $option );
            delete_site_option( $option ); // For multisite
        }
    }
    
    /**
     * Clean up transients created by the plugin
     */
    private static function clean_transients() {
        global $wpdb;
        
        // Remove transients with our prefix
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ace_seo_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ace_seo_%'" );
        
        // For multisite
        if ( is_multisite() ) {
            $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_ace_seo_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_timeout_ace_seo_%'" );
        }
    }
    
    /**
     * Remove custom database tables
     */
    private static function remove_custom_tables() {
        global $wpdb;
        
        // If we had custom tables for analytics, sitemaps, etc.
        $tables_to_remove = array(
            $wpdb->prefix . 'ace_seo_analytics',
            $wpdb->prefix . 'ace_seo_redirects',
            $wpdb->prefix . 'ace_seo_crawl_errors'
        );
        
        foreach ( $tables_to_remove as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }
    }
    
    /**
     * Clear WordPress cache
     */
    private static function clear_cache() {
        // Clear WordPress object cache
        wp_cache_flush();
        
        // Clear rewrite rules
        flush_rewrite_rules();
        
        // Clear any third-party cache if functions exist
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }
        
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
    }
    
    /**
     * Preserve important SEO meta data
     * This ensures users can switch to other Yoast-compatible plugins
     */
    private static function preserve_seo_data() {
        // We intentionally do NOT remove Yoast-compatible meta fields:
        // _yoast_wpseo_title, _yoast_wpseo_metadesc, etc.
        // This allows users to switch between Yoast-compatible SEO plugins
        
        // Only remove our plugin-specific meta fields
        global $wpdb;
        
        $plugin_specific_meta = array(
            '_ace_seo_last_analysis',
            '_ace_seo_analysis_cache',
            '_ace_seo_internal_notes'
        );
        
        foreach ( $plugin_specific_meta as $meta_key ) {
            $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
        }
    }
}

// Run the uninstaller
AceSEOUninstaller::uninstall();
