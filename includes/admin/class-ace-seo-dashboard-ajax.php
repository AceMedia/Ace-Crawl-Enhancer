<?php
/**
 * ACE SEO Dashboard AJAX Handler
 * Progressive loading system for dashboard data to prevent timeouts
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACE_SEO_Dashboard_Ajax {
    
    const BATCH_SIZE = 1000; // Process posts in batches of 1000
    
    public function __construct() {
        // AJAX handlers for authenticated users
        add_action('wp_ajax_ace_seo_load_dashboard_stats', array($this, 'ajax_load_dashboard_stats'));
        add_action('wp_ajax_ace_seo_load_recent_activity', array($this, 'ajax_load_recent_activity'));
        add_action('wp_ajax_ace_seo_load_content_analysis', array($this, 'ajax_load_content_analysis'));
        add_action('wp_ajax_ace_seo_load_post_batch', array($this, 'ajax_load_post_batch'));
        add_action('wp_ajax_ace_seo_load_database_performance', array($this, 'ajax_load_database_performance'));
        
        // Enqueue dashboard scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_scripts'));
    }
    
    /**
     * Enqueue dashboard scripts on ACE SEO pages
     */
    public function enqueue_dashboard_scripts($hook_suffix) {
        // Only load on ACE SEO dashboard page
        if ($hook_suffix !== 'toplevel_page_ace-seo') {
            return;
        }
        
        wp_enqueue_script(
            'ace-seo-dashboard-ajax',
            ACE_SEO_URL . 'assets/js/dashboard-ajax.js',
            array('jquery'),
            ACE_SEO_VERSION,
            true
        );
        
        // Localize script with AJAX data
        wp_localize_script('ace-seo-dashboard-ajax', 'aceSEODashboard', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ace_seo_dashboard_nonce'),
            'batchSize' => self::BATCH_SIZE,
            'strings' => array(
                'loading' => __('Loading...', 'ace-crawl-enhancer'),
                'loadingStats' => __('Loading statistics...', 'ace-crawl-enhancer'),
                'loadingActivity' => __('Loading recent activity...', 'ace-crawl-enhancer'),
                'loadingAnalysis' => __('Analyzing content...', 'ace-crawl-enhancer'),
                'error' => __('Error loading data. Please refresh the page.', 'ace-crawl-enhancer'),
                'complete' => __('Analysis complete!', 'ace-crawl-enhancer'),
                'processingBatch' => __('Processing batch %d of %d...', 'ace-crawl-enhancer')
            )
        ));
    }
    
    /**
     * AJAX handler for dashboard statistics
     */
    public function ajax_load_dashboard_stats() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_seo_dashboard_nonce')) {
            wp_die('Invalid nonce');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        try {
            // Get basic counts first (fast queries)
            $response = array(
                'status' => 'success',
                'data' => array()
            );
            
            // Total published content (cached by WordPress)
            $post_counts = wp_count_posts('post');
            $page_counts = wp_count_posts('page');
            $total_posts = ($post_counts->publish ?? 0) + ($page_counts->publish ?? 0);
            
            // Quick estimate queries with limits for fast response
            $focus_keywords_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_status = 'publish' 
                AND p.post_type IN ('post', 'page') 
                AND pm.meta_key IN ('_ace_seo_focuskw', '_yoast_wpseo_focuskw')
                AND pm.meta_value != ''
                LIMIT %d
            ", self::BATCH_SIZE)) ?: 0;
            
            $meta_desc_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_status = 'publish' 
                AND p.post_type IN ('post', 'page') 
                AND pm.meta_key IN ('_ace_seo_metadesc', '_yoast_wpseo_metadesc')
                AND pm.meta_value != ''
                LIMIT %d
            ", self::BATCH_SIZE)) ?: 0;
            
            // Calculate percentages
            $focus_keyword_percentage = $total_posts > 0 
                ? round(($focus_keywords_count / $total_posts) * 100) 
                : 0;
            
            $meta_desc_percentage = $total_posts > 0 
                ? round(($meta_desc_count / $total_posts) * 100) 
                : 0;
            
            $response['data'] = array(
                'total_posts' => $total_posts,
                'focus_keywords_count' => $focus_keywords_count,
                'meta_desc_count' => $meta_desc_count,
                'focus_keyword_percentage' => $focus_keyword_percentage,
                'meta_desc_percentage' => $meta_desc_percentage,
                'needs_full_analysis' => $total_posts > self::BATCH_SIZE,
                'timestamp' => current_time('mysql')
            );
            
        } catch (Exception $e) {
            $response = array(
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            );
        }
        
        wp_send_json($response);
    }
    
    /**
     * AJAX handler for recent activity
     */
    public function ajax_load_recent_activity() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_seo_dashboard_nonce')) {
            wp_die('Invalid nonce');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $limit = intval($_POST['limit'] ?? 5);
        $limit = min($limit, 20); // Maximum 20 items
        
        global $wpdb;
        
        try {
            // Get recent posts with SEO data
            $recent_posts_data = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_modified, p.post_date
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_status = 'publish' 
                AND p.post_type IN ('post', 'page') 
                AND pm.meta_key IN (
                    '_ace_seo_focuskw', '_yoast_wpseo_focuskw',
                    '_ace_seo_metadesc', '_yoast_wpseo_metadesc',
                    '_ace_seo_title', '_yoast_wpseo_title'
                )
                AND pm.meta_value != ''
                ORDER BY p.post_modified DESC 
                LIMIT %d
            ", $limit));
            
            $recent_posts = array();
            foreach ($recent_posts_data as $post_data) {
                $recent_posts[] = array(
                    'ID' => $post_data->ID,
                    'title' => $post_data->post_title,
                    'type' => ucfirst($post_data->post_type),
                    'modified' => human_time_diff(strtotime($post_data->post_modified)),
                    'edit_link' => get_edit_post_link($post_data->ID),
                    'view_link' => get_permalink($post_data->ID)
                );
            }
            
            wp_send_json(array(
                'status' => 'success',
                'data' => $recent_posts
            ));
            
        } catch (Exception $e) {
            wp_send_json(array(
                'status' => 'error',
                'message' => 'Error loading recent activity: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX handler for content analysis
     */
    public function ajax_load_content_analysis() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_seo_dashboard_nonce')) {
            wp_die('Invalid nonce');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        try {
            // Content analysis queries
            $analysis = array();
            
            // Posts missing focus keywords
            $missing_focus_kw = $wpdb->get_var("
                SELECT COUNT(p.ID)
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    AND pm.meta_key IN ('_ace_seo_focuskw', '_yoast_wpseo_focuskw')
                WHERE p.post_status = 'publish'
                AND p.post_type IN ('post', 'page')
                AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ") ?: 0;
            
            // Posts missing meta descriptions
            $missing_meta_desc = $wpdb->get_var("
                SELECT COUNT(p.ID)
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    AND pm.meta_key IN ('_ace_seo_metadesc', '_yoast_wpseo_metadesc')
                WHERE p.post_status = 'publish'
                AND p.post_type IN ('post', 'page')
                AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ") ?: 0;
            
            // Posts missing SEO titles
            $missing_seo_titles = $wpdb->get_var("
                SELECT COUNT(p.ID)
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    AND pm.meta_key IN ('_ace_seo_title', '_yoast_wpseo_title')
                WHERE p.post_status = 'publish'
                AND p.post_type IN ('post', 'page')
                AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ") ?: 0;
            
            // Content by post type
            $content_types = $wpdb->get_results("
                SELECT post_type, COUNT(*) as count
                FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                AND post_type IN ('post', 'page')
                GROUP BY post_type
            ");
            
            $type_breakdown = array();
            foreach ($content_types as $type) {
                $type_breakdown[$type->post_type] = intval($type->count);
            }
            
            $analysis = array(
                'missing_focus_keywords' => $missing_focus_kw,
                'missing_meta_descriptions' => $missing_meta_desc,
                'missing_seo_titles' => $missing_seo_titles,
                'content_breakdown' => $type_breakdown,
                'timestamp' => current_time('mysql')
            );
            
            wp_send_json(array(
                'status' => 'success',
                'data' => $analysis
            ));
            
        } catch (Exception $e) {
            wp_send_json(array(
                'status' => 'error',
                'message' => 'Error analyzing content: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX handler for batch processing posts (for large sites)
     */
    public function ajax_load_post_batch() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_seo_dashboard_nonce')) {
            wp_die('Invalid nonce');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $batch = intval($_POST['batch'] ?? 1);
        $offset = ($batch - 1) * self::BATCH_SIZE;
        
        global $wpdb;
        
        try {
            // Get batch of posts for detailed analysis
            $posts = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID, p.post_title, p.post_type,
                    GROUP_CONCAT(
                        CASE 
                            WHEN pm.meta_key IN ('_ace_seo_focuskw', '_yoast_wpseo_focuskw') 
                            THEN CONCAT('focus_kw:', pm.meta_value)
                            WHEN pm.meta_key IN ('_ace_seo_metadesc', '_yoast_wpseo_metadesc') 
                            THEN CONCAT('meta_desc:', pm.meta_value)
                            WHEN pm.meta_key IN ('_ace_seo_title', '_yoast_wpseo_title') 
                            THEN CONCAT('seo_title:', pm.meta_value)
                        END SEPARATOR '|'
                    ) as seo_data
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    AND pm.meta_key IN (
                        '_ace_seo_focuskw', '_yoast_wpseo_focuskw',
                        '_ace_seo_metadesc', '_yoast_wpseo_metadesc',
                        '_ace_seo_title', '_yoast_wpseo_title'
                    )
                WHERE p.post_status = 'publish'
                AND p.post_type IN ('post', 'page')
                GROUP BY p.ID
                ORDER BY p.ID
                LIMIT %d OFFSET %d
            ", self::BATCH_SIZE, $offset));
            
            $batch_analysis = array(
                'posts_processed' => count($posts),
                'has_focus_kw' => 0,
                'has_meta_desc' => 0,
                'has_seo_title' => 0,
                'fully_optimized' => 0
            );
            
            foreach ($posts as $post) {
                $seo_data = $post->seo_data ?? '';
                $has_focus_kw = strpos($seo_data, 'focus_kw:') !== false;
                $has_meta_desc = strpos($seo_data, 'meta_desc:') !== false;
                $has_seo_title = strpos($seo_data, 'seo_title:') !== false;
                
                if ($has_focus_kw) $batch_analysis['has_focus_kw']++;
                if ($has_meta_desc) $batch_analysis['has_meta_desc']++;
                if ($has_seo_title) $batch_analysis['has_seo_title']++;
                if ($has_focus_kw && $has_meta_desc && $has_seo_title) {
                    $batch_analysis['fully_optimized']++;
                }
            }
            
            // Check if there are more batches
            $total_posts = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->posts} 
                WHERE post_status = 'publish' 
                AND post_type IN ('post', 'page')
            ");
            
            $batch_analysis['batch_number'] = $batch;
            $batch_analysis['total_batches'] = ceil($total_posts / self::BATCH_SIZE);
            $batch_analysis['has_more'] = ($offset + self::BATCH_SIZE) < $total_posts;
            $batch_analysis['progress_percentage'] = min(100, round(($offset + count($posts)) / $total_posts * 100));
            
            wp_send_json(array(
                'status' => 'success',
                'data' => $batch_analysis
            ));
            
        } catch (Exception $e) {
            wp_send_json(array(
                'status' => 'error',
                'message' => 'Error processing batch: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX handler for loading database performance data
     */
    public function ajax_load_database_performance() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ace_seo_dashboard_nonce')) {
            wp_send_json(array(
                'status' => 'error',
                'message' => 'Invalid nonce'
            ));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json(array(
                'status' => 'error', 
                'message' => 'Insufficient permissions'
            ));
        }
        
        try {
            // Check if optimization is pending
            $optimization_pending = get_option('ace_seo_db_optimization_pending', false);
            $optimization_completed = get_option('ace_seo_db_optimized', false);
            
            // Check current progress
            $progress = get_option('ace_seo_optimization_progress', array(
                'percent' => 0,
                'message' => 'Not started',
                'completed' => false,
                'error' => false
            ));
            
            // Debug logging
            error_log('ACE SEO: Loading database performance - pending: ' . ($optimization_pending ? 'yes' : 'no') . ', progress: ' . $progress['percent'] . '%, message: ' . $progress['message']);
            
            // Reset optimization pending if progress shows completed
            if ($optimization_pending && $progress['completed']) {
                update_option('ace_seo_db_optimization_pending', false);
                $optimization_pending = false;
                error_log('ACE SEO: Reset optimization pending flag - optimization completed');
            }
            
            $html = '';
            
            // Check if optimization is actually running (not just pending)
            $is_running = $optimization_pending && ($progress['percent'] > 0 || $progress['message'] !== 'Not started') && !$progress['completed'];
            
            if ($is_running) {
                // Show detailed progress with progress bar
                $progressClass = 'running';
                if ($progress['error']) {
                    $progressClass = 'error';
                } elseif ($progress['completed']) {
                    $progressClass = 'completed';
                }
                
                $html .= '<div class="ace-optimization-progress ' . $progressClass . '">';
                $html .= '<div class="ace-progress-header">';
                $html .= '<h4>🚀 Database Optimization ' . ($progress['completed'] ? 'Completed' : 'In Progress') . '</h4>';
                $html .= '</div>';
                $html .= '<div class="ace-progress-bar">';
                $html .= '<div class="ace-progress-fill" style="width: ' . $progress['percent'] . '%"></div>';
                $html .= '</div>';
                $html .= '<div class="ace-progress-info">';
                $html .= '<span class="ace-progress-percent">' . $progress['percent'] . '%</span>';
                $html .= '<span class="ace-progress-message">' . esc_html($progress['message']) . '</span>';
                $html .= '</div>';
                if (!$progress['completed'] && !$progress['error']) {
                    $html .= '<p><small>This process improves database performance and may take a few minutes on large sites.</small></p>';
                }
                $html .= '</div>';
            } else {
                // Initialize database optimizer for analysis
                if (class_exists('ACE_SEO_Database_Optimizer')) {
                    $db_optimizer = new ACE_SEO_Database_Optimizer();
                    $analysis = $db_optimizer->analyze_performance();
                    
                    $html .= '<div class="ace-seo-db-stats">';
                    $html .= '<div class="ace-seo-db-stat">';
                    $html .= '<strong>SEO Meta Records:</strong> ' . number_format($analysis['seo_meta_records']);
                    $html .= '</div>';
                    $html .= '<div class="ace-seo-db-stat">';
                    $html .= '<strong>Total Meta Records:</strong> ' . number_format($analysis['postmeta_records']);
                    $html .= '</div>';
                    $html .= '<div class="ace-seo-db-stat">';
                    $html .= '<strong>Active Indexes:</strong> ' . count($analysis['existing_indexes']);
                    $html .= '</div>';
                    
                    if ($optimization_completed) {
                        $html .= '<div class="ace-seo-db-stat">';
                        $html .= '<strong>Last Optimized:</strong> ' . human_time_diff(strtotime($optimization_completed), current_time('timestamp')) . ' ago';
                        $html .= '</div>';
                    }
                    $html .= '</div>';
                    
                    if (!empty($analysis['recommendations'])) {
                        $html .= '<div class="ace-seo-recommendations">';
                        $html .= '<h4>Performance Recommendations:</h4>';
                        foreach ($analysis['recommendations'] as $recommendation) {
                            $html .= '<div class="ace-seo-recommendation">⚠️ ' . esc_html($recommendation) . '</div>';
                        }
                        $html .= '</div>';
                    } else {
                        $html .= '<div class="ace-seo-performance-good">';
                        $html .= '✅ Database performance is optimized!';
                        if ($optimization_completed) {
                            $html .= '<br><small>Optimization completed ' . human_time_diff(strtotime($optimization_completed), current_time('timestamp')) . ' ago</small>';
                        }
                        $html .= '</div>';
                    }
                    
                    // Determine if optimization has ever been run
                    $has_been_optimized = $optimization_completed || $progress['completed'] || 
                                        ($progress['percent'] > 0 && $progress['message'] !== 'Not started');
                    
                    // Show completed optimization status if there is meaningful progress
                    if ($progress['completed'] || $progress['percent'] > 0) {
                        $html .= '<div class="ace-optimization-progress completed" style="margin-top: 15px;">';
                        $html .= '<div class="ace-progress-header">';
                        $html .= '<h4>🚀 Database Optimization ' . ($progress['completed'] ? 'Completed' : 'Status') . '</h4>';
                        $html .= '</div>';
                        if ($progress['percent'] > 0) {
                            $html .= '<div class="ace-progress-bar">';
                            $html .= '<div class="ace-progress-fill" style="width: ' . $progress['percent'] . '%"></div>';
                            $html .= '</div>';
                        }
                        $html .= '<div class="ace-progress-info">';
                        $html .= '<span class="ace-progress-percent">' . $progress['percent'] . '%</span>';
                        $html .= '<span class="ace-progress-message">' . esc_html($progress['message']) . '</span>';
                        $html .= '</div>';
                        $html .= '</div>';
                    }
                    
                    // Show optimization button when not running
                    $html .= '<div class="ace-seo-optimization-controls" style="margin-top: 15px;">';
                    
                    // Use "Restart" if optimization has been run before, "Start" if never run
                    $button_text = $has_been_optimized ? '🔄 Restart Database Optimization' : '🚀 Start Database Optimization';
                    $html .= '<button type="button" id="ace-optimize-database" class="ace-seo-optimize-btn button button-primary">' . $button_text . '</button>';
                    $html .= '<div id="ace-optimize-result" class="ace-optimize-result" style="display: none;"></div>';
                    $html .= '</div>';
                } else {
                    $html .= '<p>Database optimizer not available.</p>';
                }
            }
            
            // Only add refresh button if optimization is actively running
            if ($is_running) {
                $html .= '<br><button type="button" class="ace-refresh-database ace-seo-refresh-btn" style="margin-top: 10px;">Refresh Status</button>';
            }
            
            wp_send_json(array(
                'status' => 'success',
                'data' => array(
                    'html' => $html,
                    'pending' => $optimization_pending,
                    'progress' => $progress
                )
            ));
            
        } catch (Exception $e) {
            wp_send_json(array(
                'status' => 'error',
                'message' => 'Error loading database performance: ' . $e->getMessage()
            ));
        }
    }
}

// Initialize AJAX handler
new ACE_SEO_Dashboard_Ajax();