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
class AceCrawlUninstaller {
    
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
            'acecrawl_settings',
            'acecrawl_social_settings', 
            'acecrawl_advanced_settings',
            'acecrawl_compatibility_settings',
            'acecrawl_version',
            'acecrawl_activation_date',
            'acecrawl_options',
            'acecrawl_db_optimized',
            'acecrawl_db_optimization_results',
            'acecrawl_db_optimization_pending',
            'acecrawl_db_optimization_scheduled',
            'acecrawl_optimization_progress',
            'acecrawl_performance_monitoring',
            'acecrawl_sitemap_options',
            'acecrawl_hits_db_version',
            'acecrawl_whitehat_options',
            'acecrawl_sitemap_purge_notice',
            'acecrawl_sitemap_large_site_notice',
            'acecrawl_sitemap_legacy_cache_purged',
            'acecrawl_sitemap_cache_version'
        );
        
        global $wpdb;
        // Only this plugin's own prefix: other Ace plugins keep options that start with ace_seo_.
        foreach ( array( 'acecrawl_' ) as $prefix ) {
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
        
        // Only this plugin's own prefix: other Ace plugins cache under names that start with ace_seo_.
        foreach ( array( 'acecrawl_' ) as $prefix ) {
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
AceCrawlUninstaller::uninstall();
