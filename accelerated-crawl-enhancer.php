<?php
/**
 * Ace Crawl Enhancer - Advanced SEO Plugin
 *
 * @package AceCrawlEnhancer
 * @version 1.0.2
 * @author AceMedia
 * @description A modern SEO plugin with seamless Yoast migration, AI-powered optimization, and advanced performance features
 * 
 * @wordpress-plugin
 * Plugin Name: Ace Crawl Enhancer
 * Plugin URI: https://acemedia.com/ace-crawl-enhancer
 * Description: Advanced SEO plugin with seamless Yoast migration, modern interface, AI-powered optimization, and comprehensive SEO features.
 * Version: 1.0.2
 * Author: AceMedia
 * Text Domain: ace-crawl-enhancer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ACE_SEO_VERSION', '1.0.2');
define('ACE_SEO_FILE', __FILE__);
define('ACE_SEO_PATH', plugin_dir_path(__FILE__));
define('ACE_SEO_URL', plugin_dir_url(__FILE__));
define('ACE_SEO_BASENAME', plugin_basename(__FILE__));

// Plugin-specific meta keys - no longer use Yoast keys for storage
define('ACE_SEO_META_PREFIX', '_ace_seo_');
define('ACE_SEO_FORM_PREFIX', 'yoast_wpseo_'); // Keep form prefix for compatibility with existing templates

/**
 * Main plugin class
 */
class AceCrawlEnhancer {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Meta fields definition - compatible with Yoast structure
     */
    public static $meta_fields = [
        'general' => [
            'focuskw' => [
                'type' => 'text',
                'title' => 'Focus Keyword',
                'description' => 'The main keyword you want this content to rank for',
                'default_value' => '',
            ],
            'title' => [
                'type' => 'text',
                'title' => 'SEO Title',
                'description' => 'The title that appears in search engines',
                'default_value' => '',
                'maxlength' => 60,
            ],
            'metadesc' => [
                'type' => 'textarea',
                'title' => 'Meta Description',
                'description' => 'The description that appears in search engines',
                'default_value' => '',
                'maxlength' => 160,
                'rows' => 3,
            ],
            'linkdex' => [
                'type' => 'hidden',
                'default_value' => '0',
            ],
            'content_score' => [
                'type' => 'hidden',
                'default_value' => '0',
            ],
            'is_cornerstone' => [
                'type' => 'checkbox',
                'title' => 'Cornerstone Content',
                'description' => 'Mark this as cornerstone content',
                'default_value' => 'false',
            ],
        ],
        'advanced' => [
            'meta-robots-noindex' => [
                'type' => 'select',
                'title' => 'Search Engine Visibility',
                'description' => 'Allow search engines to show this content in search results?',
                'default_value' => '0',
                'options' => [
                    '0' => 'Default (Index)',
                    '2' => 'Yes (Index)',
                    '1' => 'No (No-index)',
                ],
            ],
            'meta-robots-nofollow' => [
                'type' => 'select',
                'title' => 'Follow Links',
                'description' => 'Should search engines follow links on this content?',
                'default_value' => '0',
                'options' => [
                    '0' => 'Yes (Follow)',
                    '1' => 'No (No-follow)',
                ],
            ],
            'meta-robots-adv' => [
                'type' => 'multiselect',
                'title' => 'Advanced Meta Robots',
                'description' => 'Advanced robots meta settings',
                'default_value' => '',
                'options' => [
                    'noimageindex' => 'No Image Index',
                    'noarchive' => 'No Archive',
                    'nosnippet' => 'No Snippet',
                ],
            ],
            'canonical' => [
                'type' => 'url',
                'title' => 'Canonical URL',
                'description' => 'The canonical URL for this content',
                'default_value' => '',
            ],
            'bctitle' => [
                'type' => 'text',
                'title' => 'Breadcrumbs Title',
                'description' => 'Title to use in breadcrumb navigation',
                'default_value' => '',
            ],
        ],
        'social' => [
            'opengraph-title' => [
                'type' => 'text',
                'title' => 'Facebook Title',
                'description' => 'Title for Facebook sharing',
                'default_value' => '',
                'maxlength' => 95,
            ],
            'opengraph-description' => [
                'type' => 'textarea',
                'title' => 'Facebook Description',
                'description' => 'Description for Facebook sharing',
                'default_value' => '',
                'maxlength' => 300,
                'rows' => 3,
            ],
            'opengraph-image' => [
                'type' => 'image',
                'title' => 'Facebook Image',
                'description' => 'Image for Facebook sharing',
                'default_value' => '',
            ],
            'twitter-title' => [
                'type' => 'text',
                'title' => 'Twitter Title',
                'description' => 'Title for Twitter sharing',
                'default_value' => '',
                'maxlength' => 70,
            ],
            'twitter-description' => [
                'type' => 'textarea',
                'title' => 'Twitter Description',
                'description' => 'Description for Twitter sharing',
                'default_value' => '',
                'maxlength' => 200,
                'rows' => 3,
            ],
            'twitter-image' => [
                'type' => 'image',
                'title' => 'Twitter Image',
                'description' => 'Image for Twitter sharing',
                'default_value' => '',
            ],
        ],
    ];
    
    /**
     * Get meta fields array
     */
    public static function get_meta_fields() {
        return self::$meta_fields;
    }
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', [$this, 'init']);
        
        // Conditional loading for better performance
        if (!is_admin()) {
            // Frontend-only hooks
            add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
            add_action('wp_head', [$this, 'output_head_tags']);
            
            // Skip REST API for logged-out users unless needed
            if (is_user_logged_in() || !apply_filters('ace_seo_skip_rest_api', false)) {
                add_action('rest_api_init', [$this, 'register_rest_routes']);
            }
            
            // Frontend SEO output
            add_filter('wp_title', [$this, 'filter_title'], 50);
            add_filter('document_title_parts', [$this, 'filter_document_title_parts'], 50);
            add_action('wp_head', [$this, 'output_meta_description'], 1);
            add_action('wp_head', [$this, 'output_canonical'], 2);
            add_action('wp_head', [$this, 'output_robots_meta'], 3);
            add_action('wp_head', [$this, 'output_opengraph_tags'], 10);
            add_action('wp_head', [$this, 'output_twitter_tags'], 11);
        } else {
            // Admin-only hooks
            add_action('load-post.php', [$this, 'maybe_migrate_post_data']);
            add_action('load-post-new.php', [$this, 'maybe_migrate_post_data']);
            
            // Admin columns (skip for frontend users)
            if (!apply_filters('ace_seo_skip_admin_columns', false)) {
                add_filter('manage_posts_columns', [$this, 'add_admin_columns']);
                add_filter('manage_pages_columns', [$this, 'add_admin_columns']);
                add_action('manage_posts_custom_column', [$this, 'populate_admin_columns'], 10, 2);
                add_action('manage_pages_custom_column', [$this, 'populate_admin_columns'], 10, 2);
            }
        }
        
        // Always needed
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('init', [$this, 'register_meta_fields']);
        add_action('ace_seo_optimize_database', [$this, 'run_background_optimization']);
        
        // Homepage synchronization hook
        add_action('updated_post_meta', [$this, 'sync_homepage_meta'], 10, 4);
        add_action('updated_option', [$this, 'sync_homepage_settings_to_page'], 10, 3);
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('ace-crawl-enhancer', false, dirname(ACE_SEO_BASENAME) . '/languages');
        
        // Initialize components
        $this->init_admin();
        $this->init_frontend();
    }
    
    /**
     * Initialize admin components
     */
    private function init_admin() {
        if (is_admin()) {
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-admin.php';
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-metabox.php';
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-settings.php';
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-api-helper.php';
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-ai-assistant.php';
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-pagespeed.php';
            require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard.php';
        }
        
        // Always load database optimizer for performance
        require_once ACE_SEO_PATH . 'includes/database/class-database-optimizer.php';
    }
    
    /**
     * Initialize frontend components
     */
    private function init_frontend() {
        if (!is_admin()) {
            // Load performance optimizer first for guests
            require_once ACE_SEO_PATH . 'includes/frontend/class-ace-seo-performance.php';
            
            // Load core frontend components
            require_once ACE_SEO_PATH . 'includes/frontend/class-ace-seo-frontend.php';
            require_once ACE_SEO_PATH . 'includes/frontend/class-ace-seo-schema.php';
        }
        
        // Always load sitemap functionality (but initialize conditionally)
        require_once ACE_SEO_PATH . 'includes/class-ace-seo-sitemap.php';
        
        // Only initialize sitemap if needed (not for all guest requests)
        if (is_admin() || is_user_logged_in() || $this->is_sitemap_request()) {
            new AceSEOSitemap();
        }
    }
    
    /**
     * Check if this is a sitemap request
     */
    private function is_sitemap_request() {
        return isset($_GET['ace_seo_sitemap']) || 
               (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'sitemap') !== false);
    }
    
    /**
     * Add query vars for sitemap functionality
     */
    public function add_query_vars($vars) {
        $vars[] = 'ace_seo_sitemap';
        return $vars;
    }
    
    /**
     * Register meta fields
     */
    public function register_meta_fields() {
        foreach (self::$meta_fields as $group => $fields) {
            foreach ($fields as $key => $field) {
                register_meta('post', ACE_SEO_META_PREFIX . $key, [
                    'type' => 'string',
                    'single' => true,
                    'show_in_rest' => true,
                    'sanitize_callback' => [$this, 'sanitize_meta_value'],
                ]);
            }
        }
    }
    
    /**
     * Sanitize meta values
     */
    public function sanitize_meta_value($value, $meta_key, $object_type) {
        // Remove the prefix to get the field key
        $field_key = str_replace(ACE_SEO_META_PREFIX, '', $meta_key);
        
        // Find the field definition
        $field_def = null;
        foreach (self::$meta_fields as $group => $fields) {
            if (isset($fields[$field_key])) {
                $field_def = $fields[$field_key];
                break;
            }
        }
        
        if (!$field_def) {
            return sanitize_text_field($value);
        }
        
        switch ($field_def['type']) {
            case 'textarea':
                return sanitize_textarea_field($value);
            case 'url':
                return esc_url_raw($value);
            case 'email':
                return sanitize_email($value);
            case 'multiselect':
                if (is_array($value)) {
                    return implode(',', array_map('sanitize_text_field', $value));
                }
                return sanitize_text_field($value);
            default:
                return sanitize_text_field($value);
        }
    }
    
    /**
     * Get meta value with fallback to default and Yoast migration
     * Optimized to use cached data on frontend for better performance
     */
    public static function get_meta_value($post_id, $key) {
        // On frontend, try to use cached data first for better performance
        if (!is_admin() && class_exists('ACE_SEO_Performance')) {
            $cached_value = ACE_SEO_Performance::get_meta($post_id, $key);
            if ($cached_value !== null) {
                return $cached_value;
            }
        }
        
        // First try to get plugin-specific meta
        $value = get_post_meta($post_id, ACE_SEO_META_PREFIX . $key, true);
        
        // If no plugin meta exists, check for Yoast data and migrate it
        if (empty($value)) {
            $yoast_value = get_post_meta($post_id, '_yoast_wpseo_' . $key, true);
            
            if (!empty($yoast_value)) {
                // Migrate Yoast data to plugin meta
                update_post_meta($post_id, ACE_SEO_META_PREFIX . $key, $yoast_value);
                $value = $yoast_value;
            }
        }
        
        // If still empty, try default value
        if (empty($value)) {
            // Find default value
            foreach (self::$meta_fields as $group => $fields) {
                if (isset($fields[$key]) && isset($fields[$key]['default_value'])) {
                    return $fields[$key]['default_value'];
                }
            }
            return '';
        }
        
        return $value;
    }
    
    /**
     * Migrate Yoast SEO data for a specific post
     */
    public static function migrate_yoast_data($post_id) {
        $migrated_count = 0;
        
        foreach (self::$meta_fields as $group => $fields) {
            foreach ($fields as $key => $field) {
                // Check if plugin meta already exists
                $plugin_value = get_post_meta($post_id, ACE_SEO_META_PREFIX . $key, true);
                
                if (empty($plugin_value)) {
                    // Check for Yoast data
                    $yoast_value = get_post_meta($post_id, '_yoast_wpseo_' . $key, true);
                    
                    if (!empty($yoast_value)) {
                        update_post_meta($post_id, ACE_SEO_META_PREFIX . $key, $yoast_value);
                        $migrated_count++;
                    }
                }
            }
        }
        
        // Handle special fields
        $cornerstone = get_post_meta($post_id, '_yoast_wpseo_is_cornerstone', true);
        if (!empty($cornerstone) && empty(get_post_meta($post_id, ACE_SEO_META_PREFIX . 'is_cornerstone', true))) {
            update_post_meta($post_id, ACE_SEO_META_PREFIX . 'is_cornerstone', $cornerstone);
            $migrated_count++;
        }
        
        return $migrated_count;
    }
    
    /**
     * Get migration statistics
     */
    public static function get_migration_stats() {
        global $wpdb;
        
        // Count posts with Yoast data
        $yoast_posts = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key LIKE %s
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
        ", '_yoast_wpseo_%'));
        
        // Count posts with Ace SEO data
        $ace_posts = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key LIKE %s
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
        ", '_ace_seo_%'));
        
        // Count posts that need migration
        $pending_migration = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} ace_check ON (p.ID = ace_check.post_id AND ace_check.meta_key = '_ace_seo_migration_check')
            WHERE pm.meta_key LIKE '_yoast_wpseo_%'
            AND (ace_check.meta_value IS NULL OR ace_check.meta_value < %d)
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
        ", time() - (7 * DAY_IN_SECONDS)));
        
        return [
            'yoast_posts' => intval($yoast_posts),
            'ace_posts' => intval($ace_posts),
            'pending_migration' => intval($pending_migration)
        ];
    }
    
    /**
     * Bulk migrate all Yoast SEO data (legacy method - kept for compatibility)
     */
    public static function bulk_migrate_yoast_data() {
        global $wpdb;
        
        // Get all posts that have Yoast meta and haven't been migrated recently
        $yoast_posts = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} ace_check ON (p.ID = ace_check.post_id AND ace_check.meta_key = '_ace_seo_migration_check')
            WHERE pm.meta_key LIKE '_yoast_wpseo_%'
            AND (ace_check.meta_value IS NULL OR ace_check.meta_value < %d)
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
        ", time() - (7 * DAY_IN_SECONDS)));
        
        $total_migrated = 0;
        
        foreach ($yoast_posts as $post_id) {
            $migrated = self::migrate_yoast_data($post_id);
            $total_migrated += $migrated;
            
            // Mark as migrated
            update_post_meta($post_id, '_ace_seo_migration_check', time());
        }
        
        return $total_migrated;
    }
    
    /**
     * Maybe migrate post data when loading post in admin
     */
    public function maybe_migrate_post_data() {
        global $post;
        
        if (!is_admin() || !$post || !$post->ID) {
            return;
        }
        
        // Check if we've already migrated this post recently (to avoid repeated migrations)
        $migration_check = get_post_meta($post->ID, '_ace_seo_migration_check', true);
        
        // If no migration check or it's been more than a day, check for migration
        if (empty($migration_check) || (time() - $migration_check) > DAY_IN_SECONDS) {
            $migrated = self::migrate_yoast_data($post->ID);
            
            // Mark this post as checked (whether or not anything was migrated)
            update_post_meta($post->ID, '_ace_seo_migration_check', time());
            
            if ($migrated > 0) {
                add_action('admin_notices', function() use ($migrated) {
                    echo '<div class="notice notice-info is-dismissible">';
                    echo '<p><strong>Ace SEO:</strong> Migrated ' . $migrated . ' SEO fields from Yoast SEO for this post.</p>';
                    echo '</div>';
                });
            }
        }
    }
    
    /**
     * Frontend scripts
     */
    public function frontend_scripts() {
        // Frontend scripts if needed
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('ace-seo/v1', '/analyze/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_analyze_content'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
        ]);
        
        register_rest_route('ace-seo/v1', '/preview/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_preview'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
        ]);
    }
    
    /**
     * REST: Analyze content
     */
    public function rest_analyze_content($request) {
        $post_id = $request['id'];
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found', ['status' => 404]);
        }
        
        $analysis = [
            'seo_score' => $this->calculate_seo_score($post),
            'readability_score' => $this->calculate_readability_score($post),
            'recommendations' => $this->get_seo_recommendations($post),
        ];
        
        return rest_ensure_response($analysis);
    }
    
    /**
     * REST: Get preview
     */
    public function rest_get_preview($request) {
        $post_id = $request['id'];
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found', ['status' => 404]);
        }
        
        $preview = [
            'title' => $this->get_seo_title($post),
            'description' => $this->get_meta_description($post),
            'url' => get_permalink($post),
        ];
        
        return rest_ensure_response($preview);
    }
    
    /**
     * Calculate SEO score
     */
    private function calculate_seo_score($post) {
        $score = 0;
        $total_checks = 0;
        
        // Check if focus keyword is set
        $focus_keyword = self::get_meta_value($post->ID, 'focuskw');
        if (!empty($focus_keyword)) {
            $score += 10;
            
            // Check keyword in title
            $title = $this->get_seo_title($post);
            if (stripos($title, $focus_keyword) !== false) {
                $score += 15;
            }
            
            // Check keyword in content
            if (stripos($post->post_content, $focus_keyword) !== false) {
                $score += 15;
            }
            
            // Check keyword in meta description
            $meta_desc = $this->get_meta_description($post);
            if (stripos($meta_desc, $focus_keyword) !== false) {
                $score += 10;
            }
        }
        $total_checks += 50;
        
        // Check meta description length
        $meta_desc = $this->get_meta_description($post);
        if (strlen($meta_desc) >= 120 && strlen($meta_desc) <= 160) {
            $score += 15;
        }
        $total_checks += 15;
        
        // Check title length
        $title = $this->get_seo_title($post);
        if (strlen($title) >= 30 && strlen($title) <= 60) {
            $score += 15;
        }
        $total_checks += 15;
        
        // Check content length
        if (str_word_count($post->post_content) >= 300) {
            $score += 10;
        }
        $total_checks += 10;
        
        // Check images have alt text
        if (preg_match_all('/<img[^>]+>/i', $post->post_content, $matches)) {
            $images_with_alt = preg_match_all('/<img[^>]+alt=["\'][^"\']*["\'][^>]*>/i', $post->post_content);
            if ($images_with_alt === count($matches[0])) {
                $score += 10;
            }
        } else {
            $score += 10; // No images, so this check passes
        }
        $total_checks += 10;
        
        // Include PageSpeed performance data if available
        $performance_data = get_post_meta($post->ID, '_ace_seo_pagespeed_report', true);
        if (!empty($performance_data) && isset($performance_data['mobile']['performance_score'])) {
            $performance_score = $performance_data['mobile']['performance_score'];
            
            // Add performance score to SEO calculation (0-20 points based on performance)
            if ($performance_score >= 90) {
                $score += 20; // Excellent performance
            } elseif ($performance_score >= 70) {
                $score += 15; // Good performance
            } elseif ($performance_score >= 50) {
                $score += 10; // Average performance
            } elseif ($performance_score >= 30) {
                $score += 5; // Poor performance
            }
            // 0 points for very poor performance (< 30)
            
            $total_checks += 20;
        }
        
        return round(($score / $total_checks) * 100);
    }
    
    /**
     * Calculate readability score
     */
    private function calculate_readability_score($post) {
        // Simple readability check based on sentence and word length
        $content = strip_tags($post->post_content);
        $sentences = preg_split('/[.!?]+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $words = str_word_count($content);
        
        if (count($sentences) === 0) {
            return 0;
        }
        
        $avg_words_per_sentence = $words / count($sentences);
        
        // Score based on average words per sentence (optimal: 15-20 words)
        if ($avg_words_per_sentence >= 15 && $avg_words_per_sentence <= 20) {
            return 85;
        } elseif ($avg_words_per_sentence >= 10 && $avg_words_per_sentence <= 25) {
            return 70;
        } else {
            return 50;
        }
    }
    
    /**
     * Get SEO recommendations
     */
    private function get_seo_recommendations($post) {
        $recommendations = [];
        $focus_keyword = self::get_meta_value($post->ID, 'focuskw');
        
        if (empty($focus_keyword)) {
            $recommendations[] = [
                'type' => 'warning',
                'text' => 'No focus keyword set. Add a focus keyword to improve SEO.',
            ];
        }
        
        $meta_desc = $this->get_meta_description($post);
        if (empty($meta_desc)) {
            $recommendations[] = [
                'type' => 'error',
                'text' => 'No meta description set. Add a meta description to improve search appearance.',
            ];
        } elseif (strlen($meta_desc) < 120) {
            $recommendations[] = [
                'type' => 'warning',
                'text' => 'Meta description is too short. Aim for 120-160 characters.',
            ];
        } elseif (strlen($meta_desc) > 160) {
            $recommendations[] = [
                'type' => 'warning',
                'text' => 'Meta description is too long. Keep it under 160 characters.',
            ];
        }
        
        $title = $this->get_seo_title($post);
        if (strlen($title) > 60) {
            $recommendations[] = [
                'type' => 'warning',
                'text' => 'SEO title is too long. Keep it under 60 characters.',
            ];
        }
        
        if (str_word_count($post->post_content) < 300) {
            $recommendations[] = [
                'type' => 'warning',
                'text' => 'Content is quite short. Consider adding more content for better SEO.',
            ];
        }
        
        // PageSpeed performance recommendations
        $performance_data = get_post_meta($post->ID, '_ace_seo_pagespeed_report', true);
        if (!empty($performance_data)) {
            $mobile_score = $performance_data['mobile']['performance_score'] ?? 0;
            
            if ($mobile_score < 30) {
                $recommendations[] = [
                    'type' => 'error',
                    'text' => 'Page performance is very poor (Score: ' . $mobile_score . '%). This severely impacts SEO and user experience.',
                ];
            } elseif ($mobile_score < 50) {
                $recommendations[] = [
                    'type' => 'warning',
                    'text' => 'Page performance is poor (Score: ' . $mobile_score . '%). Consider optimizing images and reducing server response time.',
                ];
            } elseif ($mobile_score < 70) {
                $recommendations[] = [
                    'type' => 'warning',
                    'text' => 'Page performance could be improved (Score: ' . $mobile_score . '%). This affects SEO rankings.',
                ];
            } elseif ($mobile_score >= 90) {
                $recommendations[] = [
                    'type' => 'good',
                    'text' => 'Excellent page performance (Score: ' . $mobile_score . '%)! This positively impacts SEO.',
                ];
            }
            
            // Core Web Vitals specific recommendations
            $cwv = $performance_data['mobile']['core_web_vitals'] ?? array();
            
            if (isset($cwv['lcp']) && $cwv['lcp']['rating'] === 'poor') {
                $recommendations[] = [
                    'type' => 'warning',
                    'text' => 'Largest Contentful Paint (LCP) is poor: ' . $cwv['lcp']['displayValue'] . '. Optimize images and server response.',
                ];
            }
            
            if (isset($cwv['fid']) && $cwv['fid']['rating'] === 'poor') {
                $recommendations[] = [
                    'type' => 'warning',
                    'text' => 'First Input Delay (FID) is poor: ' . $cwv['fid']['displayValue'] . '. Reduce JavaScript execution time.',
                ];
            }
            
            if (isset($cwv['cls']) && $cwv['cls']['rating'] === 'poor') {
                $recommendations[] = [
                    'type' => 'warning',
                    'text' => 'Cumulative Layout Shift (CLS) is high: ' . $cwv['cls']['displayValue'] . '. Set dimensions for images and ads.',
                ];
            }
        }
        
        // AI-powered recommendations if available
        if ($this->is_ai_enabled()) {
            $ai_recommendations = $this->get_ai_recommendations($post);
            if (!is_wp_error($ai_recommendations)) {
                $recommendations = array_merge($recommendations, $ai_recommendations);
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Check if AI features are enabled
     */
    private function is_ai_enabled() {
        $options = get_option('ace_seo_options', []);
        $ai_settings = $options['ai'] ?? [];
        
        return !empty($ai_settings['openai_api_key']) && 
               ($ai_settings['ai_content_analysis'] ?? 0);
    }
    
    /**
     * Get AI-powered recommendations
     */
    private function get_ai_recommendations($post) {
        if (!class_exists('AceSEOApiHelper')) {
            return [];
        }
        
        $prompt = "Analyze this content for SEO improvements:\n\n";
        $prompt .= "Title: " . $post->post_title . "\n";
        $prompt .= "Content: " . wp_trim_words(strip_tags($post->post_content), 200) . "\n\n";
        $prompt .= "Provide 3-5 specific, actionable SEO recommendations in this format:\n";
        $prompt .= "- [Recommendation text]\n";
        $prompt .= "Focus on: keyword optimization, content structure, readability, and technical SEO.";
        
        $response = AceSEOApiHelper::make_openai_request($prompt);
        
        if (is_wp_error($response)) {
            return [];
        }
        
        // Parse AI response into recommendations array
        $lines = explode("\n", $response);
        $ai_recommendations = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '- ') === 0) {
                $ai_recommendations[] = [
                    'type' => 'ai',
                    'text' => substr($line, 2), // Remove "- " prefix
                ];
            }
        }
        
        return $ai_recommendations;
    }
    
    /**
     * Get SEO title for post
     */
    private function get_seo_title($post) {
        $seo_title = self::get_meta_value($post->ID, 'title');
        
        if (empty($seo_title)) {
            // Use template system
            return $this->process_title_template($post);
        }
        
        return $seo_title;
    }
    
    /**
     * Process title template for a post
     */
    private function process_title_template($post) {
        $options = get_option('ace_seo_options', []);
        $templates = $options['templates'] ?? [];
        
        $template_key = 'title_template_' . $post->post_type;
        $template = $templates[$template_key] ?? '{title} {sep} {site_name}';
        
        return $this->replace_template_variables($template, $post);
    }
    
    /**
     * Process title template for special pages (archive, search, author)
     */
    private function process_special_page_title() {
        $options = get_option('ace_seo_options', []);
        $templates = $options['templates'] ?? [];
        
        $template = '{archive_title} {sep} {site_name}'; // Default fallback
        
        if (is_search()) {
            $template = $templates['title_template_search'] ?? 'Search results for "{search_term}" {sep} {site_name}';
        } elseif (is_author()) {
            $template = $templates['title_template_author'] ?? 'Articles by {author_name} {sep} {site_name}';
        } elseif (is_category()) {
            $template = $templates['title_template_category'] ?? '{category_name} archives {sep} {site_name}';
        } elseif (is_tag()) {
            $template = $templates['title_template_tag'] ?? '{tag_name} archives {sep} {site_name}';
        } elseif (is_date()) {
            $template = $templates['title_template_date'] ?? '{date_archive} archives {sep} {site_name}';
        } elseif (is_archive()) {
            $template = $templates['title_template_archive'] ?? '{archive_title} {sep} {site_name}';
        }
        
        return $this->replace_template_variables($template);
    }
    
    /**
     * Replace template variables with actual values
     */
    private function replace_template_variables($template, $post = null) {
        $options = get_option('ace_seo_options', []);
        $general = $options['general'] ?? [];
        
        $variables = [
            '{site_name}' => $general['site_name'] ?? get_bloginfo('name'),
            '{sep}' => ' ' . ($general['separator'] ?? '|') . ' ',
        ];
        
        // Add post-specific variables if post is provided
        if ($post) {
            $variables['{title}'] = $post->post_title;
            $variables['{excerpt}'] = $this->get_post_excerpt($post);
            $variables['{author}'] = get_the_author_meta('display_name', $post->post_author);
            $variables['{date}'] = get_the_date('', $post);
            $variables['{category}'] = $this->get_primary_category($post);
            $variables['{tag}'] = $this->get_primary_tag($post);
        }
        
        // Add special page variables
        if (is_archive()) {
            $variables['{archive_title}'] = get_the_archive_title();
        }
        
        if (is_search()) {
            $variables['{search_term}'] = get_search_query();
        }
        
        if (is_author()) {
            $author = get_queried_object();
            $variables['{author_name}'] = $author ? $author->display_name : '';
        }
        
        if (is_category()) {
            $category = get_queried_object();
            $variables['{category_name}'] = $category ? $category->name : '';
        }
        
        if (is_tag()) {
            $tag = get_queried_object();
            $variables['{tag_name}'] = $tag ? $tag->name : '';
        }
        
        if (is_date()) {
            if (is_year()) {
                $variables['{date_archive}'] = get_query_var('year');
            } elseif (is_month()) {
                $variables['{date_archive}'] = get_query_var('monthnum') . '/' . get_query_var('year');
            } elseif (is_day()) {
                $variables['{date_archive}'] = get_query_var('day') . '/' . get_query_var('monthnum') . '/' . get_query_var('year');
            }
        }
        
        return str_replace(array_keys($variables), array_values($variables), $template);
    }
    
    /**
     * Get post excerpt for templates
     */
    private function get_post_excerpt($post) {
        if (!empty($post->post_excerpt)) {
            return wp_trim_words($post->post_excerpt, 25);
        } else {
            // Extract only from paragraph blocks to avoid JavaScript/custom block code
            $clean_content = $this->extract_paragraph_content($post->post_content);
            return wp_trim_words($clean_content, 25);
        }
    }
    
    /**
     * Extract content only from paragraph blocks (Gutenberg)
     */
    private function extract_paragraph_content($content) {
        // Handle Gutenberg paragraph blocks
        if (has_blocks($content)) {
            $blocks = parse_blocks($content);
            $paragraph_content = '';
            
            foreach ($blocks as $block) {
                // Only get content from paragraph blocks
                if ($block['blockName'] === 'core/paragraph' && !empty($block['innerHTML'])) {
                    $paragraph_content .= $block['innerHTML'] . ' ';
                }
            }
            
            if (!empty($paragraph_content)) {
                return strip_tags($paragraph_content);
            }
        }
        
        // Fallback for non-Gutenberg content - extract first paragraph
        $content = strip_tags($content);
        $paragraphs = preg_split('/\n\s*\n/', trim($content));
        
        if (!empty($paragraphs[0])) {
            return $paragraphs[0];
        }
        
        return wp_trim_words($content, 30);
    }
    
    /**
     * Get primary category for post
     */
    private function get_primary_category($post) {
        $categories = get_the_category($post->ID);
        if (!empty($categories)) {
            return $categories[0]->name;
        }
        return '';
    }
    
    /**
     * Get primary tag for post
     */
    private function get_primary_tag($post) {
        $tags = get_the_tags($post->ID);
        if (!empty($tags)) {
            return $tags[0]->name;
        }
        return '';
    }
    
    /**
     * Get meta description for post
     */
    private function get_meta_description($post) {
        $meta_desc = self::get_meta_value($post->ID, 'metadesc');
        
        if (empty($meta_desc)) {
            // Use template system
            return $this->process_meta_template($post);
        }
        
        return $meta_desc;
    }
    
    /**
     * Process meta description template for a post
     */
    private function process_meta_template($post) {
        $options = get_option('ace_seo_options', []);
        $templates = $options['templates'] ?? [];
        
        $template_key = 'meta_template_' . $post->post_type;
        $template = $templates[$template_key] ?? '{excerpt}';
        
        return $this->replace_template_variables($template, $post);
    }
    
    /**
     * Filter document title
     */
    public function filter_document_title_parts($title_parts) {
        if (is_singular()) {
            global $post;
            $seo_title = $this->get_seo_title($post);
            if (!empty($seo_title)) {
                $title_parts['title'] = $seo_title;
            }
        } elseif (is_home() || is_front_page()) {
            // Handle homepage title with synchronization
            $home_title = $this->get_homepage_title();
            if (!empty($home_title)) {
                $title_parts = [
                    'title' => $home_title
                ];
            }
        } elseif (is_search() || is_archive() || is_author()) {
            // Handle special pages (search, archive, author)
            $special_title = $this->process_special_page_title();
            if (!empty($special_title)) {
                // Replace the entire title with our processed template
                $title_parts = [
                    'title' => $special_title
                ];
            }
        }
        
        return $title_parts;
    }
    
    /**
     * Get homepage title with synchronization between settings and page meta
     */
    private function get_homepage_title() {
        $options = get_option('ace_seo_options', []);
        $settings_title = $options['general']['home_title'] ?? '';
        
        // Check if homepage is a static page
        $page_on_front = get_option('page_on_front');
        $show_on_front = get_option('show_on_front');
        
        if ($show_on_front === 'page' && $page_on_front) {
            // Static homepage - check page meta first, then plugin settings
            $page_meta_title = self::get_meta_value($page_on_front, 'title');
            
            if (!empty($page_meta_title)) {
                // Sync page meta to plugin settings if different
                if ($page_meta_title !== $settings_title) {
                    $options['general']['home_title'] = $page_meta_title;
                    update_option('ace_seo_options', $options);
                }
                return $page_meta_title;
            } elseif (!empty($settings_title)) {
                // Sync plugin settings to page meta
                update_post_meta($page_on_front, ACE_SEO_META_PREFIX . 'title', $settings_title);
                return $settings_title;
            }
        } else {
            // Blog homepage - use plugin settings
            if (!empty($settings_title)) {
                return $settings_title;
            }
        }
        
        // Fallback to site name
        return get_bloginfo('name');
    }
    
    /**
     * Output meta description
     */
    public function output_meta_description() {
        if (is_singular()) {
            global $post;
            $meta_desc = $this->get_meta_description($post);
            if (!empty($meta_desc)) {
                echo '<meta name="description" content="' . esc_attr($meta_desc) . '">' . "\n";
            }
        } elseif (is_home() || is_front_page()) {
            // Handle homepage meta description with synchronization
            $home_desc = $this->get_homepage_meta_description();
            
            if (!empty($home_desc)) {
                echo '<meta name="description" content="' . esc_attr($home_desc) . '">' . "\n";
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            // Handle taxonomy pages
            $tax_desc = $this->get_taxonomy_meta_description();
            if (!empty($tax_desc)) {
                echo '<meta name="description" content="' . esc_attr($tax_desc) . '">' . "\n";
            }
        } elseif (is_author()) {
            // Handle author pages
            $author_desc = $this->get_author_meta_description();
            if (!empty($author_desc)) {
                echo '<meta name="description" content="' . esc_attr($author_desc) . '">' . "\n";
            }
        } elseif (is_search()) {
            // Handle search pages
            $search_desc = $this->get_search_meta_description();
            if (!empty($search_desc)) {
                echo '<meta name="description" content="' . esc_attr($search_desc) . '">' . "\n";
            }
        } elseif (is_archive()) {
            // Handle other archive pages
            $archive_desc = $this->get_archive_meta_description();
            if (!empty($archive_desc)) {
                echo '<meta name="description" content="' . esc_attr($archive_desc) . '">' . "\n";
            }
        }
    }
    
    /**
     * Get homepage meta description with synchronization between settings and page meta
     */
    private function get_homepage_meta_description() {
        $options = get_option('ace_seo_options', []);
        $settings_desc = $options['general']['home_description'] ?? '';
        
        // Check if homepage is a static page
        $page_on_front = get_option('page_on_front');
        $show_on_front = get_option('show_on_front');
        
        if ($show_on_front === 'page' && $page_on_front) {
            // Static homepage - check page meta first, then plugin settings
            $page_meta_desc = self::get_meta_value($page_on_front, 'metadesc');
            
            if (!empty($page_meta_desc)) {
                // Sync page meta to plugin settings if different
                if ($page_meta_desc !== $settings_desc) {
                    $options['general']['home_description'] = $page_meta_desc;
                    update_option('ace_seo_options', $options);
                }
                return $page_meta_desc;
            } elseif (!empty($settings_desc)) {
                // Sync plugin settings to page meta
                update_post_meta($page_on_front, ACE_SEO_META_PREFIX . 'metadesc', $settings_desc);
                return $settings_desc;
            } else {
                // Generate from page content
                $page = get_post($page_on_front);
                if ($page) {
                    $auto_desc = $this->extract_paragraph_content($page->post_content);
                    $auto_desc = wp_trim_words($auto_desc, 25);
                    if (!empty($auto_desc)) {
                        // Save auto-generated description to both places
                        update_post_meta($page_on_front, ACE_SEO_META_PREFIX . 'metadesc', $auto_desc);
                        $options['general']['home_description'] = $auto_desc;
                        update_option('ace_seo_options', $options);
                        return $auto_desc;
                    }
                }
            }
        } else {
            // Blog homepage - use plugin settings or site tagline
            if (!empty($settings_desc)) {
                return $settings_desc;
            }
        }
        
        // Final fallback to site tagline
        return get_bloginfo('description');
    }
    
    /**
     * Sync homepage meta between page fields and plugin settings
     */
    public function sync_homepage_meta($meta_id, $post_id, $meta_key, $meta_value) {
        // Check if this is a homepage meta update
        $page_on_front = get_option('page_on_front');
        $show_on_front = get_option('show_on_front');
        
        if ($show_on_front !== 'page' || $page_on_front != $post_id) {
            return; // Not the homepage
        }
        
        // Only sync ACE SEO meta fields
        if (strpos($meta_key, ACE_SEO_META_PREFIX) !== 0) {
            return; // Not our meta field
        }
        
        $options = get_option('ace_seo_options', []);
        
        // Sync title changes
        if ($meta_key === ACE_SEO_META_PREFIX . 'title') {
            $options['general']['home_title'] = $meta_value;
            update_option('ace_seo_options', $options);
        }
        
        // Sync description changes
        if ($meta_key === ACE_SEO_META_PREFIX . 'metadesc') {
            $options['general']['home_description'] = $meta_value;
            update_option('ace_seo_options', $options);
        }
    }
    
    /**
     * Sync homepage settings to page meta when plugin settings are updated
     */
    public function sync_homepage_settings_to_page($option_name, $old_value, $new_value) {
        // Only sync ACE SEO options
        if ($option_name !== 'ace_seo_options') {
            return;
        }
        
        // Check if homepage is a static page
        $page_on_front = get_option('page_on_front');
        $show_on_front = get_option('show_on_front');
        
        if ($show_on_front !== 'page' || !$page_on_front) {
            return; // Not a static homepage
        }
        
        // Get the new homepage settings
        $new_home_title = $new_value['general']['home_title'] ?? '';
        $new_home_desc = $new_value['general']['home_description'] ?? '';
        
        // Get the old homepage settings for comparison
        $old_home_title = $old_value['general']['home_title'] ?? '';
        $old_home_desc = $old_value['general']['home_description'] ?? '';
        
        // Sync title if it changed in settings
        if ($new_home_title !== $old_home_title && !empty($new_home_title)) {
            update_post_meta($page_on_front, ACE_SEO_META_PREFIX . 'title', $new_home_title);
        }
        
        // Sync description if it changed in settings
        if ($new_home_desc !== $old_home_desc && !empty($new_home_desc)) {
            update_post_meta($page_on_front, ACE_SEO_META_PREFIX . 'metadesc', $new_home_desc);
        }
    }
    
    /**
     * Output canonical URL
     */
    public function output_canonical() {
        if (is_singular()) {
            global $post;
            $canonical = self::get_meta_value($post->ID, 'canonical');
            
            if (empty($canonical)) {
                $canonical = get_permalink($post);
            }
            
            if (!empty($canonical)) {
                echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
            }
        } elseif (is_home()) {
            // Blog homepage
            $canonical = home_url('/');
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        } elseif (is_front_page()) {
            // Static front page
            $page_on_front = get_option('page_on_front');
            if ($page_on_front) {
                $canonical = get_permalink($page_on_front);
            } else {
                $canonical = home_url('/');
            }
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        } elseif (is_category() || is_tag() || is_tax()) {
            // Taxonomy pages
            $term = get_queried_object();
            if ($term && isset($term->term_id)) {
                $canonical = get_term_link($term);
                if (!is_wp_error($canonical)) {
                    echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
                }
            }
        } elseif (is_author()) {
            // Author pages
            $author = get_queried_object();
            if ($author && isset($author->ID)) {
                $canonical = get_author_posts_url($author->ID);
                echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
            }
        } elseif (is_search()) {
            // Search pages
            $canonical = home_url('/') . '?s=' . urlencode(get_search_query());
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        } elseif (is_archive()) {
            // Other archive pages
            $canonical = get_pagenum_link(get_query_var('paged') ?: 1, false);
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        }
    }
    
    /**
     * Output robots meta tags
     */
    public function output_robots_meta() {
        if (is_singular()) {
            global $post;
            
            $robots = [];
            
            // Check noindex
            $noindex = self::get_meta_value($post->ID, 'meta-robots-noindex');
            if ($noindex === '1') {
                $robots[] = 'noindex';
            }
            
            // Check nofollow
            $nofollow = self::get_meta_value($post->ID, 'meta-robots-nofollow');
            if ($nofollow === '1') {
                $robots[] = 'nofollow';
            }
            
            // Check advanced robots
            $robots_adv = self::get_meta_value($post->ID, 'meta-robots-adv');
            if (!empty($robots_adv)) {
                $adv_robots = explode(',', $robots_adv);
                $robots = array_merge($robots, $adv_robots);
            }
            
            if (!empty($robots)) {
                echo '<meta name="robots" content="' . esc_attr(implode(', ', $robots)) . '">' . "\n";
            }
        }
    }
    
    /**
     * Output Open Graph tags
     */
    public function output_opengraph_tags() {
        if (is_singular()) {
            global $post;
            
            // OG Title
            $og_title = self::get_meta_value($post->ID, 'opengraph-title');
            if (empty($og_title)) {
                $og_title = $this->get_seo_title($post);
            }
            if (!empty($og_title)) {
                echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
            }
            
            // OG Description
            $og_desc = self::get_meta_value($post->ID, 'opengraph-description');
            if (empty($og_desc)) {
                $og_desc = $this->get_meta_description($post);
            }
            if (!empty($og_desc)) {
                echo '<meta property="og:description" content="' . esc_attr($og_desc) . '">' . "\n";
            }
            
            // OG Image
            $og_image = self::get_meta_value($post->ID, 'opengraph-image');
            if (empty($og_image) && has_post_thumbnail($post->ID)) {
                $og_image = get_the_post_thumbnail_url($post->ID, 'large');
            }
            if (!empty($og_image)) {
                echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
            }
            
            // OG URL
            echo '<meta property="og:url" content="' . esc_url(get_permalink($post)) . '">' . "\n";
            echo '<meta property="og:type" content="article">' . "\n";
        } elseif (is_home() || is_front_page()) {
            // Handle homepage Open Graph tags
            // OG Title for homepage - use synchronized title
            $og_title = $this->get_homepage_title();
            if (!empty($og_title)) {
                echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
            }
            
            // OG Description for homepage - use synchronized description
            $og_desc = $this->get_homepage_meta_description();
            if (!empty($og_desc)) {
                echo '<meta property="og:description" content="' . esc_attr($og_desc) . '">' . "\n";
            }
            
            // OG URL for homepage
            echo '<meta property="og:url" content="' . esc_url(home_url()) . '">' . "\n";
            echo '<meta property="og:type" content="website">' . "\n";
        } elseif (is_search() || is_archive() || is_author()) {
            // Handle special pages
            $og_title = $this->process_special_page_title();
            if (!empty($og_title)) {
                echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
            }
            
            // OG URL for special pages
            $current_url = home_url(add_query_arg(null, null));
            echo '<meta property="og:url" content="' . esc_url($current_url) . '">' . "\n";
            echo '<meta property="og:type" content="website">' . "\n";
        }
    }
    
    /**
     * Output Twitter Card tags
     */
    public function output_twitter_tags() {
        if (is_singular()) {
            global $post;
            
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
            
            // Twitter Title
            $twitter_title = self::get_meta_value($post->ID, 'twitter-title');
            if (empty($twitter_title)) {
                $twitter_title = $this->get_seo_title($post);
            }
            if (!empty($twitter_title)) {
                echo '<meta name="twitter:title" content="' . esc_attr($twitter_title) . '">' . "\n";
            }
            
            // Twitter Description
            $twitter_desc = self::get_meta_value($post->ID, 'twitter-description');
            if (empty($twitter_desc)) {
                $twitter_desc = $this->get_meta_description($post);
            }
            if (!empty($twitter_desc)) {
                echo '<meta name="twitter:description" content="' . esc_attr($twitter_desc) . '">' . "\n";
            }
            
            // Twitter Image
            $twitter_image = self::get_meta_value($post->ID, 'twitter-image');
            if (empty($twitter_image) && has_post_thumbnail($post->ID)) {
                $twitter_image = get_the_post_thumbnail_url($post->ID, 'large');
            }
            if (!empty($twitter_image)) {
                echo '<meta name="twitter:image" content="' . esc_url($twitter_image) . '">' . "\n";
            }
        } elseif (is_home() || is_front_page()) {
            // Handle homepage Twitter cards
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
            
            // Twitter Title for homepage - use synchronized title
            $twitter_title = $this->get_homepage_title();
            if (!empty($twitter_title)) {
                echo '<meta name="twitter:title" content="' . esc_attr($twitter_title) . '">' . "\n";
            }
            
            // Twitter Description for homepage - use synchronized description
            $twitter_desc = $this->get_homepage_meta_description();
            if (!empty($twitter_desc)) {
                echo '<meta name="twitter:description" content="' . esc_attr($twitter_desc) . '">' . "\n";
            }
        } elseif (is_search() || is_archive() || is_author()) {
            // Handle special pages
            echo '<meta name="twitter:card" content="summary">' . "\n";
            
            $twitter_title = $this->process_special_page_title();
            if (!empty($twitter_title)) {
                echo '<meta name="twitter:title" content="' . esc_attr($twitter_title) . '">' . "\n";
            }
        }
    }
    
    /**
     * Run background database optimization
     */
    public function run_background_optimization() {
        // Include the database optimizer
        if ( file_exists( ACE_SEO_PATH . 'includes/database/class-database-optimizer.php' ) ) {
            require_once ACE_SEO_PATH . 'includes/database/class-database-optimizer.php';
            
            if ( class_exists( 'ACE_SEO_Database_Optimizer' ) ) {
                $optimizer = new ACE_SEO_Database_Optimizer();
                $results = $optimizer->create_indexes();
                
                // Log the optimization results
                error_log( 'ACE SEO Background Task: Database optimization completed - ' . print_r( $results, true ) );
                
                // Clear pending flag and store completion status
                delete_option( 'ace_seo_db_optimization_pending' );
                update_option( 'ace_seo_db_optimized', current_time( 'mysql' ) );
                update_option( 'ace_seo_db_optimization_results', $results );
                
                // Optionally send a notification (if user wants it)
                $this->send_optimization_notification( $results );
            }
        }
    }
    
    /**
     * Send optimization completion notification
     */
    private function send_optimization_notification( $results ) {
        // Only send if user has enabled notifications
        $send_notifications = get_option( 'ace_seo_send_notifications', false );
        if ( ! $send_notifications ) {
            return;
        }
        
        $admin_email = get_option( 'admin_email' );
        $site_name = get_bloginfo( 'name' );
        
        $subject = sprintf( '[%s] ACE SEO Database Optimization Complete', $site_name );
        
        $message = "Database optimization has been completed for your ACE SEO plugin.\n\n";
        $message .= "Optimization Results:\n";
        
        foreach ( $results as $table => $indexes ) {
            $message .= "\n" . strtoupper( str_replace( '_', ' ', $table ) ) . ":\n";
            foreach ( $indexes as $index_name => $result ) {
                $message .= "  • " . $index_name . ": " . $result['message'] . "\n";
            }
        }
        
        $message .= "\nYour SEO dashboard should now load significantly faster.\n";
        $message .= "\nThis optimization runs automatically when the plugin is activated.";
        
        wp_mail( $admin_email, $subject, $message );
    }
    
    /**
     * Add admin columns
     */
    public function add_admin_columns($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            
            if ($key === 'title') {
                $new_columns['ace_seo_score'] = 'SEO Score';
                $new_columns['ace_seo_focus'] = 'Focus Keyword';
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Populate admin columns
     */
    public function populate_admin_columns($column, $post_id) {
        switch ($column) {
            case 'ace_seo_score':
                $post = get_post($post_id);
                $score = $this->calculate_seo_score($post);
                $color = $score >= 80 ? 'green' : ($score >= 60 ? 'orange' : 'red');
                echo '<span style="color: ' . $color . '; font-weight: bold;">' . $score . '%</span>';
                break;
                
            case 'ace_seo_focus':
                $focus_keyword = self::get_meta_value($post_id, 'focuskw');
                echo !empty($focus_keyword) ? esc_html($focus_keyword) : '—';
                break;
        }
    }
    
    /**
     * Output head tags (compatibility method)
     */
    public function output_head_tags() {
        // This method exists for compatibility but individual methods handle output
    }
    
    /**
     * Filter title (legacy compatibility)
     */
    public function filter_title($title) {
        return $title; // Document title parts filter handles this now
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    AceCrawlEnhancer::get_instance();
});

// Include activator class
require_once ACE_SEO_PATH . 'includes/class-ace-seo-activator.php';

// Activation hook
register_activation_hook(__FILE__, array('AceSEOActivator', 'activate'));

// Deactivation hook
register_deactivation_hook(__FILE__, array('AceSEODeactivator', 'deactivate'));
