<?php
/**
 * Ace SEO Admin Class
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 * @license GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class AceSeoAdmin {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('plugin_action_links_' . ACE_SEO_BASENAME, [$this, 'plugin_action_links']);
        
        // Add bulk actions for PageSpeed analysis
        add_filter('bulk_actions-edit-post', [$this, 'add_bulk_actions']);
        add_filter('bulk_actions-edit-page', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_actions'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_notices']);
    }
    
    public function admin_init() {
        // Check for Yoast SEO and show compatibility notice
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
            add_action('admin_notices', [$this, 'yoast_compatibility_notice']);
        }
    }
    
    public function admin_notices() {
        // Show welcome notice on first activation
        if (get_transient('ace_seo_activation_notice')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong>Ace SEO</strong> has been activated! 
                    <a href="<?php echo admin_url('admin.php?page=ace-seo'); ?>">Configure settings</a> 
                    or start optimizing your content with our modern SEO interface.
                </p>
            </div>
            <?php
            delete_transient('ace_seo_activation_notice');
        }
    }
    
    public function yoast_compatibility_notice() {
        ?>
        <div class="notice notice-info">
            <p>
                <strong>Ace SEO:</strong> We've detected Yoast SEO is also active. 
                Ace SEO uses the same meta field structure for seamless compatibility. 
                You can safely disable Yoast SEO to avoid conflicts while keeping all your existing data.
            </p>
        </div>
        <?php
    }
    
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=ace-seo-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Add bulk actions for PageSpeed analysis
     */
    public function add_bulk_actions($bulk_actions) {
        if (!class_exists('AceSEOApiHelper') || !AceSEOApiHelper::is_performance_monitoring_enabled()) {
            return $bulk_actions;
        }
        
        $bulk_actions['ace_seo_analyze_performance'] = 'Analyze PageSpeed Performance';
        return $bulk_actions;
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'ace_seo_analyze_performance') {
            return $redirect_to;
        }
        
        if (!current_user_can('edit_posts')) {
            return $redirect_to;
        }
        
        $processed = 0;
        $errors = 0;
        
        foreach ($post_ids as $post_id) {
            $url = get_permalink($post_id);
            
            if (!$url) {
                $errors++;
                continue;
            }
            
            // Run PageSpeed test for mobile
            if (class_exists('AceSEOPageSpeed')) {
                $pagespeed = new AceSEOPageSpeed();
                $result = $this->run_pagespeed_for_post($url, $post_id);
                
                if (!is_wp_error($result)) {
                    $processed++;
                } else {
                    $errors++;
                }
            }
        }
        
        $redirect_to = add_query_arg([
            'ace_seo_bulk_performance' => $processed,
            'ace_seo_bulk_errors' => $errors
        ], $redirect_to);
        
        return $redirect_to;
    }
    
    /**
     * Run PageSpeed test for a specific post
     */
    private function run_pagespeed_for_post($url, $post_id) {
        if (!class_exists('AceSEOApiHelper')) {
            return new WP_Error('missing_helper', 'API Helper not available');
        }
        
        $pagespeed_data = AceSEOApiHelper::make_pagespeed_request($url, 'mobile');
        
        if (is_wp_error($pagespeed_data)) {
            return $pagespeed_data;
        }
        
        // Parse and store the data (simplified version)
        $parsed_data = $this->parse_simple_pagespeed_data($pagespeed_data);
        
        if (!is_wp_error($parsed_data)) {
            $report = array(
                'url' => $url,
                'mobile' => $parsed_data,
                'timestamp' => current_time('mysql'),
            );
            
            update_post_meta($post_id, '_ace_seo_pagespeed_report', $report);
        }
        
        return $parsed_data;
    }
    
    /**
     * Parse PageSpeed data (simplified version)
     */
    private function parse_simple_pagespeed_data($data) {
        if (!isset($data['lighthouseResult'])) {
            return new WP_Error('invalid_data', 'Invalid PageSpeed response');
        }
        
        $lighthouse = $data['lighthouseResult'];
        $categories = $lighthouse['categories'] ?? array();
        
        return array(
            'performance_score' => round(($categories['performance']['score'] ?? 0) * 100),
            'accessibility_score' => round(($categories['accessibility']['score'] ?? 0) * 100),
            'best_practices_score' => round(($categories['best-practices']['score'] ?? 0) * 100),
            'seo_score' => round(($categories['seo']['score'] ?? 0) * 100),
        );
    }
    
    /**
     * Show bulk action notices
     */
    public function bulk_action_notices() {
        if (!empty($_REQUEST['ace_seo_bulk_performance'])) {
            $processed = intval($_REQUEST['ace_seo_bulk_performance']);
            $errors = intval($_REQUEST['ace_seo_bulk_errors'] ?? 0);
            
            $message = sprintf(
                _n(
                    'PageSpeed analysis completed for %d post.',
                    'PageSpeed analysis completed for %d posts.',
                    $processed,
                    'ace-seo'
                ),
                $processed
            );
            
            if ($errors > 0) {
                $message .= ' ' . sprintf(
                    _n(
                        '%d post failed to analyze.',
                        '%d posts failed to analyze.',
                        $errors,
                        'ace-seo'
                    ),
                    $errors
                );
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
}
