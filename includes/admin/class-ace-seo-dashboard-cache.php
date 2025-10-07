<?php
/**
 * ACE SEO Dashboard Cache
 * Handles caching of dashboard statistics to prevent 504 timeouts
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACE_SEO_Dashboard_Cache {
    
    const CACHE_DURATION = 3600; // 1 hour
    const STATS_TRANSIENT = 'ace_seo_dashboard_stats';
    const RECENT_POSTS_TRANSIENT = 'ace_seo_recent_posts';
    
    /**
     * Get cached dashboard statistics
     */
    public static function get_dashboard_stats() {
        $cached_stats = get_transient(self::STATS_TRANSIENT);
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        return self::generate_dashboard_stats();
    }
    
    /**
     * Generate fresh dashboard statistics
     */
    private static function generate_dashboard_stats() {
        global $wpdb;
        
        // Use optimized queries with limits to prevent timeouts
        $stats = array();
        
        try {
            // Count posts with focus keywords - with timeout protection
            $stats['focus_keywords_count'] = $wpdb->get_var("
                SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_status = 'publish' 
                AND p.post_type IN ('post', 'page') 
                AND pm.meta_key IN ('_ace_seo_focuskw', '_yoast_wpseo_focuskw')
                AND pm.meta_value != ''
                LIMIT 10000
            ") ?: 0;
            
            // Count posts with meta descriptions - with timeout protection
            $stats['meta_desc_count'] = $wpdb->get_var("
                SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_status = 'publish' 
                AND p.post_type IN ('post', 'page') 
                AND pm.meta_key IN ('_ace_seo_metadesc', '_yoast_wpseo_metadesc')
                AND pm.meta_value != ''
                LIMIT 10000
            ") ?: 0;
            
            // Get total published posts count (WordPress cache should make this fast)
            $post_counts = wp_count_posts('post');
            $page_counts = wp_count_posts('page');
            $stats['total_posts'] = ($post_counts->publish ?? 0) + ($page_counts->publish ?? 0);
            
            // Calculate percentages
            $stats['focus_keyword_percentage'] = $stats['total_posts'] > 0 
                ? round(($stats['focus_keywords_count'] / $stats['total_posts']) * 100) 
                : 0;
            
            $stats['meta_desc_percentage'] = $stats['total_posts'] > 0 
                ? round(($stats['meta_desc_count'] / $stats['total_posts']) * 100) 
                : 0;
            
            // Add timestamp for cache validation
            $stats['generated_at'] = time();
            $stats['cache_key'] = self::STATS_TRANSIENT;
            
        } catch (Exception $e) {
            // Fallback values in case of database errors
            error_log('ACE SEO Dashboard Cache Error: ' . $e->getMessage());
            $stats = array(
                'focus_keywords_count' => 0,
                'meta_desc_count' => 0,
                'total_posts' => 0,
                'focus_keyword_percentage' => 0,
                'meta_desc_percentage' => 0,
                'generated_at' => time(),
                'cache_key' => self::STATS_TRANSIENT,
                'error' => true
            );
        }
        
        // Cache for 1 hour
        set_transient(self::STATS_TRANSIENT, $stats, self::CACHE_DURATION);
        
        return $stats;
    }
    
    /**
     * Get cached recent posts
     */
    public static function get_recent_posts($limit = 5) {
        $cache_key = self::RECENT_POSTS_TRANSIENT . '_' . $limit;
        $cached_posts = get_transient($cache_key);
        
        if ($cached_posts !== false) {
            return $cached_posts;
        }
        
        return self::generate_recent_posts($limit, $cache_key);
    }
    
    /**
     * Generate recent posts data
     */
    private static function generate_recent_posts($limit = 5, $cache_key = null) {
        global $wpdb;
        
        if (!$cache_key) {
            $cache_key = self::RECENT_POSTS_TRANSIENT . '_' . $limit;
        }
        
        try {
            // Optimized query for recent posts with SEO data
            $recent_post_data = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_modified, p.post_date
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_status = 'publish' 
                AND p.post_type IN ('post', 'page') 
                AND pm.meta_key IN (
                    '_ace_seo_focuskw', '_yoast_wpseo_focuskw',
                    '_ace_seo_metadesc', '_yoast_wpseo_metadesc'
                )
                AND pm.meta_value != ''
                ORDER BY p.post_modified DESC 
                LIMIT %d
            ", $limit));
            
            // Convert to simplified array for caching
            $recent_posts = array();
            foreach ($recent_post_data as $post_data) {
                $recent_posts[] = array(
                    'ID' => $post_data->ID,
                    'post_title' => $post_data->post_title,
                    'post_type' => $post_data->post_type,
                    'post_modified' => $post_data->post_modified,
                    'post_date' => $post_data->post_date,
                    'edit_link' => get_edit_post_link($post_data->ID),
                    'permalink' => get_permalink($post_data->ID)
                );
            }
            
        } catch (Exception $e) {
            error_log('ACE SEO Recent Posts Cache Error: ' . $e->getMessage());
            $recent_posts = array();
        }
        
        // Cache for 1 hour
        set_transient($cache_key, $recent_posts, self::CACHE_DURATION);
        
        return $recent_posts;
    }
    
    /**
     * Clear all dashboard caches
     */
    public static function clear_cache() {
        delete_transient(self::STATS_TRANSIENT);
        
        // Clear recent posts caches (common sizes)
        for ($i = 1; $i <= 10; $i++) {
            delete_transient(self::RECENT_POSTS_TRANSIENT . '_' . $i);
        }
        
        return true;
    }
    
    /**
     * Clear cache when posts are updated
     */
    public static function clear_cache_on_post_update($post_id) {
        // Only clear if it's a published post/page
        $post = get_post($post_id);
        if ($post && $post->post_status === 'publish' && in_array($post->post_type, array('post', 'page'))) {
            self::clear_cache();
        }
    }
    
    /**
     * Get cache status for admin display
     */
    public static function get_cache_status() {
        $stats_cache = get_transient(self::STATS_TRANSIENT);
        $recent_cache = get_transient(self::RECENT_POSTS_TRANSIENT . '_5');
        
        // If viewing tools page and no cache exists, show that cache needs to be generated
        // Don't auto-generate here as it could cause performance issues on tools page load
        
        return array(
            'stats_cached' => $stats_cache !== false && is_array($stats_cache),
            'recent_cached' => $recent_cache !== false && is_array($recent_cache),
            'stats_age' => ($stats_cache && isset($stats_cache['generated_at'])) ? (time() - $stats_cache['generated_at']) : 0,
            'cache_duration' => self::CACHE_DURATION,
            'needs_generation' => ($stats_cache === false)
        );
    }
    
    /**
     * Force regenerate cache (for manual refresh)
     */
    public static function force_regenerate() {
        self::clear_cache();
        $stats = self::generate_dashboard_stats();
        $recent = self::generate_recent_posts(5);
        
        return array(
            'stats' => $stats,
            'recent_posts' => $recent,
            'regenerated_at' => time()
        );
    }
}

// Hook into post updates to clear cache
add_action('save_post', array('ACE_SEO_Dashboard_Cache', 'clear_cache_on_post_update'));
add_action('delete_post', array('ACE_SEO_Dashboard_Cache', 'clear_cache_on_post_update'));
