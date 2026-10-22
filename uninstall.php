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
        if ( is_multisite() ) {
            $offset = 0;
            do {
                $ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
                foreach ( $ids as $id ) {
                    switch_to_blog( (int) $id );
                    try {
                        self::remove_site_data();
                    } finally {
                        restore_current_blog();
                    }
                }
                $offset += count( $ids );
            } while ( count( $ids ) === 100 );
        } else {
            self::remove_site_data();
        }
    }

    private static function remove_site_data() {
        self::remove_options();
        self::clean_transients();
        self::remove_custom_tables();
        foreach ( (array) _get_cron_array() as $events ) {
            foreach ( $events as $hook => $instances ) {
                if ( preg_match( '/^ace_(?:seo|sitemap|retention)_/', $hook ) ) {
                    wp_unschedule_hook( $hook );
                }
            }
        }
        // SEO post metadata and shared AI service settings are deliberately retained.
        delete_option( 'rewrite_rules' );
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
            'ace_seo_wizard_completed',
            'ace_seo_options',
            'ace_seo_db_optimized',
            'ace_seo_db_optimization_results',
            'ace_seo_db_optimization_pending',
            'ace_seo_db_optimization_scheduled',
            'ace_seo_optimization_progress',
            'ace_seo_performance_monitoring',
            'ace_sitemap_powertools_options',
            'ace_seo_hits_db_version',
            'ace_seo_whitehat_options',
            'ace_sitemap_powertools_purge_notice',
            'ace_sitemap_powertools_large_site_notice',
            'ace_sitemap_powertools_legacy_cache_purged',
            'ace_sitemap_cache_version'
        );
        
        global $wpdb;
        foreach ( array( 'ace_seo_', 'ace_sitemap_', 'ace_retention_' ) as $prefix ) {
            $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
            $options_to_remove = array_merge( $options_to_remove, $names );
        }
        foreach ( array_unique( $options_to_remove ) as $option ) {
            delete_option( $option );
        }
    }
    
    /**
     * Clean up transients created by the plugin
     */
    private static function clean_transients() {
        global $wpdb;
        
        foreach ( array( 'ace_seo_', 'ace_sitemap_', 'ace_retention_' ) as $prefix ) {
            $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . $prefix ) . '%' ) );
            foreach ( $names as $name ) {
                delete_transient( substr( $name, strlen( '_transient_' ) ) );
            }
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
            $wpdb->prefix . 'ace_seo_crawl_errors',
            $wpdb->prefix . 'ace_seo_post_hits',
            $wpdb->prefix . 'ace_seo_post_referrers',
            $wpdb->prefix . 'ace_seo_daily_views',
            $wpdb->prefix . 'ace_retention_jobs',
            $wpdb->prefix . 'ace_retention_events'
        );
        
        foreach ( $tables_to_remove as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }
    }
    
}

// Run the uninstaller.
AceSEOUninstaller::uninstall();
