<?php
/**
 * Frontend Performance Optimizer for ACE SEO
 * Optimizes loading for non-admin users, especially logged-out guests
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACE_SEO_Frontend_Performance {
    
    private static $instance = null;
    private $cached_meta = array();
    private $is_guest = false;
    
    public function __construct() {
        $this->is_guest = !is_user_logged_in();
        // Initialize optimizations will be called via init() method
    }
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize frontend performance optimizations
     */
    public function init() {
        // Only run on frontend
        if (is_admin()) {
            return;
        }
        
        // Optimize hook loading
        $this->optimize_hooks();
        
        // Pre-cache common queries for better performance
        add_action('template_redirect', array($this, 'preload_common_data'), 5);
        
        // Cache meta for posts being displayed
        add_action('wp', array($this, 'cache_current_page_meta'), 10);
        
        // Add schema optimization
        add_action('wp_head', array($this, 'output_optimized_schema'), 1);
    }    /**
     * Preload meta cache for current post/page
     */
    public function preload_meta_cache() {
        if (is_singular()) {
            global $post;
            $this->batch_load_meta($post->ID);
        } elseif (is_home() && !is_front_page()) {
            // Cache for blog homepage
            $posts_page_id = get_option('page_for_posts');
            if ($posts_page_id) {
                $this->batch_load_meta($posts_page_id);
            }
        } elseif (is_front_page()) {
            // Cache for front page
            if ($front_page_id = get_option('page_on_front')) {
                $this->batch_load_meta($front_page_id);
            }
        }
    }
    
    /**
     * Batch load all SEO meta for a post to minimize queries
     */
    private function batch_load_meta($post_id) {
        if (!$post_id || isset($this->cached_meta[$post_id])) {
            return;
        }
        
        global $wpdb;
        
        // Single query to get all SEO meta for this post
        $meta_keys = array(
            '_ace_seo_title', '_ace_seo_metadesc', '_ace_seo_canonical',
            '_ace_seo_meta-robots-noindex', '_ace_seo_meta-robots-nofollow',
            '_ace_seo_opengraph-title', '_ace_seo_opengraph-description', '_ace_seo_opengraph-image',
            '_ace_seo_twitter-title', '_ace_seo_twitter-description', '_ace_seo_twitter-image',
            // Fallback to Yoast if ACE data doesn't exist
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_canonical',
            '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_meta-robots-nofollow'
        );
        
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $query = $wpdb->prepare("
            SELECT meta_key, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id = %d 
            AND meta_key IN ({$placeholders})
        ", array_merge(array($post_id), $meta_keys));
        
        $results = $wpdb->get_results($query);
        
        $this->cached_meta[$post_id] = array();
        foreach ($results as $row) {
            $this->cached_meta[$post_id][$row->meta_key] = $row->meta_value;
        }
        
        // Set a flag that this post is cached
        $this->cached_meta[$post_id]['_cached'] = true;
    }
    
    /**
     * Use cached meta instead of individual get_post_meta calls
     */
    public function use_cached_meta($use_cache, $post_id) {
        return isset($this->cached_meta[$post_id]['_cached']);
    }
    
    /**
     * Get cached meta value
     */
    public function get_cached_meta($post_id, $key, $single = true) {
        if (!isset($this->cached_meta[$post_id])) {
            return null;
        }
        
        // Try ACE key first
        $ace_key = '_ace_seo_' . $key;
        if (isset($this->cached_meta[$post_id][$ace_key]) && !empty($this->cached_meta[$post_id][$ace_key])) {
            return $this->cached_meta[$post_id][$ace_key];
        }
        
        // Fallback to Yoast key
        $yoast_key = '_yoast_wpseo_' . $key;
        if (isset($this->cached_meta[$post_id][$yoast_key])) {
            return $this->cached_meta[$post_id][$yoast_key];
        }
        
        return null;
    }
    
    /**
     * Preload common data to minimize database queries
     */
    public function preload_common_data() {
        global $wp_query;
        
        // For logged-out users, aggressively cache common queries
        if (!is_user_logged_in()) {
            // Cache options that are commonly accessed
            wp_cache_add_multiple(array(
                'ace_seo_site_title' => get_bloginfo('name'),
                'ace_seo_site_description' => get_bloginfo('description'),
                'ace_seo_home_url' => home_url(),
                'ace_seo_site_icon' => get_site_icon_url()
            ), 'ace_seo');
            
            // For single posts, preload featured image and author data
            if (is_singular()) {
                $post_id = get_the_ID();
                if ($post_id) {
                    // Preload featured image data
                    $featured_id = get_post_thumbnail_id($post_id);
                    if ($featured_id) {
                        wp_get_attachment_image_src($featured_id, 'full');
                    }
                    
                    // Preload author data
                    $author_id = get_post_field('post_author', $post_id);
                    if ($author_id) {
                        get_userdata($author_id);
                    }
                }
            }
        }
    }
    
    /**
     * Static method to get cached meta value for external access
     *
     * @param int    $post_id The post ID
     * @param string $key     The meta key
     * @return mixed|null     The cached value or null if not cached
     */
    public static function get_meta($post_id, $key) {
        if (isset(self::$instance)) {
            return self::$instance->get_cached_meta($post_id, $key);
        }
        
        return null;
    }
    
    /**
     * Optimize hooks for frontend performance
     */
    private function optimize_hooks() {
        // Remove unnecessary admin hooks on frontend
        remove_action('load-post.php', array('AceCrawlEnhancer', 'maybe_migrate_post_data'));
        remove_action('load-post-new.php', array('AceCrawlEnhancer', 'maybe_migrate_post_data'));
        
        // Skip admin columns on frontend
        if (apply_filters('ace_seo_skip_admin_columns', false)) {
            remove_filter('manage_posts_columns', array('AceCrawlEnhancer', 'add_admin_columns'));
            remove_filter('manage_pages_columns', array('AceCrawlEnhancer', 'add_admin_columns'));
            remove_action('manage_posts_custom_column', array('AceCrawlEnhancer', 'populate_admin_columns'));
            remove_action('manage_pages_custom_column', array('AceCrawlEnhancer', 'populate_admin_columns'));
        }
    }
    
    /**
     * Output deferred schema markup in footer for better performance
     */
    public function output_deferred_schema() {
        // Only for guests to maximize performance
        if (!$this->is_guest) {
            return;
        }
        
        // Move non-critical schema to footer
        if (is_singular()) {
            global $post;
            $schema = $this->get_cached_article_schema($post->ID);
            if ($schema) {
                echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES) . '</script>';
            }
        }
    }
    
    /**
     * Get cached article schema
     */
    private function get_cached_article_schema($post_id) {
        $cache_key = 'ace_seo_schema_' . $post_id;
        $cached_schema = wp_cache_get($cache_key, 'ace_seo');
        
        if ($cached_schema !== false) {
            return $cached_schema;
        }
        
        // Generate schema using cached meta
        $title = $this->get_cached_meta($post_id, 'title') ?: get_the_title($post_id);
        $description = $this->get_cached_meta($post_id, 'metadesc') ?: wp_trim_words(get_post_field('post_content', $post_id), 20);
        
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'description' => $description,
            'datePublished' => get_the_date('c', $post_id),
            'dateModified' => get_the_modified_date('c', $post_id),
            'author' => array(
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', get_post_field('post_author', $post_id))
            )
        );
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $schema, 'ace_seo', 3600);
        
        return $schema;
    }
    
    /**
     * Clear cache when post is updated
     */
    public static function clear_post_cache($post_id) {
        wp_cache_delete('ace_seo_schema_' . $post_id, 'ace_seo');
    }
}

// Initialize performance optimizations
ACE_SEO_Frontend_Performance::get_instance();

// Clear cache on post update
add_action('save_post', array('ACE_SEO_Frontend_Performance', 'clear_post_cache'));
