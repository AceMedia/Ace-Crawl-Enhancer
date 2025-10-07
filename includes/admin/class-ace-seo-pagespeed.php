<?php
/**
 * Ace SEO PageSpeed Integration Class
 * Handles PageSpeed Insights API integration and performance monitoring
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOPageSpeed {
    
    /**
     * Initialize PageSpeed functionality
     */
    public function __construct() {
        add_action( 'wp_ajax_ace_seo_test_pagespeed', array( $this, 'test_page_performance' ) );
        add_action( 'wp_ajax_ace_seo_get_pagespeed_report', array( $this, 'get_pagespeed_report' ) );
        add_action( 'wp_ajax_ace_seo_monitor_performance', array( $this, 'monitor_site_performance' ) );
        
        // Schedule daily performance checks if monitoring is enabled
        add_action( 'ace_seo_daily_performance_check', array( $this, 'scheduled_performance_check' ) );
        
        // Add performance data to SEO analysis
        add_filter( 'ace_seo_analysis_data', array( $this, 'add_performance_to_analysis' ), 10, 2 );
        
        // Add performance metrics to admin columns
        add_filter( 'manage_posts_columns', array( $this, 'add_performance_column' ) );
        add_filter( 'manage_pages_columns', array( $this, 'add_performance_column' ) );
        add_action( 'manage_posts_custom_column', array( $this, 'populate_performance_column' ), 10, 2 );
        add_action( 'manage_pages_custom_column', array( $this, 'populate_performance_column' ), 10, 2 );
        
        // REST API endpoints for performance data
        add_action( 'rest_api_init', array( $this, 'register_performance_routes' ) );
        
        // Schedule performance monitoring if enabled
        $this->maybe_schedule_monitoring();
    }
    
    /**
     * Test page performance via AJAX
     */
    public function test_page_performance() {
        check_ajax_referer( 'ace_seo_performance_test', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        $url = esc_url_raw( $_POST['url'] );
        $strategy = sanitize_text_field( $_POST['strategy'] ?? 'mobile' );
        $simulate = isset( $_POST['simulate'] ) && $_POST['simulate'] === 'true';
        
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => 'URL is required' ) );
        }
        
        // If it's a local URL and simulate is requested, generate mock data
        if ( $simulate && $this->is_local_url( $url ) ) {
            $result = $this->generate_mock_pagespeed_data( $url );
        } else {
            $result = $this->run_pagespeed_test( $url, $strategy );
        }
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 
                'message' => $result->get_error_message(),
                'is_local' => $result->get_error_data()['is_local'] ?? false,
                'suggestions' => $result->get_error_data()['suggestions'] ?? array()
            ) );
        }
        
        wp_send_json_success( $result );
    }
    
    /**
     * Get comprehensive PageSpeed report
     */
    public function get_pagespeed_report() {
        check_ajax_referer( 'ace_seo_performance_test', 'nonce' );
        
        $post_id = intval( $_POST['post_id'] ?? 0 );
        
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Post ID is required' ) );
        }
        
        $url = get_permalink( $post_id );
        $mobile_results = $this->run_pagespeed_test( $url, 'mobile' );
        $desktop_results = $this->run_pagespeed_test( $url, 'desktop' );
        
        $report = array(
            'url' => $url,
            'mobile' => $mobile_results,
            'desktop' => $desktop_results,
            'recommendations' => $this->generate_performance_recommendations( $mobile_results, $desktop_results ),
            'timestamp' => current_time( 'mysql' ),
        );
        
        // Store report for future reference
        update_post_meta( $post_id, '_ace_seo_pagespeed_report', $report );
        
        wp_send_json_success( $report );
    }
    
    /**
     * Monitor site performance
     */
    public function monitor_site_performance() {
        check_ajax_referer( 'ace_seo_performance_test', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        $urls_to_monitor = array(
            home_url(),
            get_permalink( get_option( 'page_on_front' ) ),
        );
        
        // Add recent popular posts
        $popular_posts = get_posts( array(
            'numberposts' => 5,
            'meta_key' => '_ace_seo_pagespeed_priority',
            'meta_value' => 'high',
            'post_status' => 'publish',
        ) );
        
        foreach ( $popular_posts as $post ) {
            $urls_to_monitor[] = get_permalink( $post->ID );
        }
        
        $monitoring_results = array();
        
        foreach ( $urls_to_monitor as $url ) {
            $mobile_result = $this->run_pagespeed_test( $url, 'mobile' );
            $monitoring_results[] = array(
                'url' => $url,
                'mobile_score' => $mobile_result['performance_score'] ?? 0,
                'core_web_vitals' => $mobile_result['core_web_vitals'] ?? array(),
                'timestamp' => current_time( 'mysql' ),
            );
        }
        
        // Store monitoring data
        update_option( 'ace_seo_performance_monitoring', $monitoring_results );
        
        wp_send_json_success( $monitoring_results );
    }
    
    /**
     * Run PageSpeed Insights test
     */
    private function run_pagespeed_test( $url, $strategy = 'mobile' ) {
        if ( ! class_exists( 'AceSEOApiHelper' ) ) {
            return new WP_Error( 'missing_helper', 'API Helper class not found' );
        }
        
        // Check if URL is local/development
        if ( $this->is_local_url( $url ) ) {
            return new WP_Error( 
                'local_url', 
                'PageSpeed Insights cannot analyze local development sites. Please use a publicly accessible URL or try our suggested alternatives.',
                array( 
                    'is_local' => true,
                    'suggestions' => $this->get_local_development_suggestions()
                )
            );
        }
        
        $pagespeed_data = AceSEOApiHelper::make_pagespeed_request( $url, $strategy );
        
        if ( is_wp_error( $pagespeed_data ) ) {
            return $pagespeed_data;
        }
        
        return $this->parse_pagespeed_data( $pagespeed_data );
    }
    
    /**
     * Parse PageSpeed Insights API response
     */
    private function parse_pagespeed_data( $data ) {
        if ( ! isset( $data['lighthouseResult'] ) ) {
            return new WP_Error( 'invalid_data', 'Invalid PageSpeed response' );
        }
        
        $lighthouse = $data['lighthouseResult'];
        $categories = $lighthouse['categories'] ?? array();
        $audits = $lighthouse['audits'] ?? array();
        
        // Core Web Vitals
        $core_web_vitals = array(
            'lcp' => array(
                'value' => $audits['largest-contentful-paint']['numericValue'] ?? 0,
                'displayValue' => $audits['largest-contentful-paint']['displayValue'] ?? 'N/A',
                'score' => $audits['largest-contentful-paint']['score'] ?? 0,
                'rating' => $this->get_lcp_rating( $audits['largest-contentful-paint']['numericValue'] ?? 0 ),
            ),
            'fid' => array(
                'value' => $audits['max-potential-fid']['numericValue'] ?? 0,
                'displayValue' => $audits['max-potential-fid']['displayValue'] ?? 'N/A',
                'score' => $audits['max-potential-fid']['score'] ?? 0,
                'rating' => $this->get_fid_rating( $audits['max-potential-fid']['numericValue'] ?? 0 ),
            ),
            'cls' => array(
                'value' => $audits['cumulative-layout-shift']['numericValue'] ?? 0,
                'displayValue' => $audits['cumulative-layout-shift']['displayValue'] ?? 'N/A',
                'score' => $audits['cumulative-layout-shift']['score'] ?? 0,
                'rating' => $this->get_cls_rating( $audits['cumulative-layout-shift']['numericValue'] ?? 0 ),
            ),
        );
        
        // Performance metrics
        $performance_metrics = array(
            'first_contentful_paint' => array(
                'value' => $audits['first-contentful-paint']['numericValue'] ?? 0,
                'displayValue' => $audits['first-contentful-paint']['displayValue'] ?? 'N/A',
            ),
            'speed_index' => array(
                'value' => $audits['speed-index']['numericValue'] ?? 0,
                'displayValue' => $audits['speed-index']['displayValue'] ?? 'N/A',
            ),
            'total_blocking_time' => array(
                'value' => $audits['total-blocking-time']['numericValue'] ?? 0,
                'displayValue' => $audits['total-blocking-time']['displayValue'] ?? 'N/A',
            ),
        );
        
        // Opportunities for improvement
        $opportunities = array();
        foreach ( $audits as $audit_key => $audit_data ) {
            if ( isset( $audit_data['details']['overallSavingsMs'] ) && $audit_data['details']['overallSavingsMs'] > 0 ) {
                $opportunities[] = array(
                    'title' => $audit_data['title'] ?? '',
                    'description' => $audit_data['description'] ?? '',
                    'savings_ms' => $audit_data['details']['overallSavingsMs'],
                    'savings_bytes' => $audit_data['details']['overallSavingsBytes'] ?? 0,
                );
            }
        }
        
        return array(
            'performance_score' => round( ( $categories['performance']['score'] ?? 0 ) * 100 ),
            'accessibility_score' => round( ( $categories['accessibility']['score'] ?? 0 ) * 100 ),
            'best_practices_score' => round( ( $categories['best-practices']['score'] ?? 0 ) * 100 ),
            'seo_score' => round( ( $categories['seo']['score'] ?? 0 ) * 100 ),
            'core_web_vitals' => $core_web_vitals,
            'performance_metrics' => $performance_metrics,
            'opportunities' => array_slice( $opportunities, 0, 5 ), // Top 5 opportunities
            'overall_rating' => $this->get_overall_performance_rating( $categories['performance']['score'] ?? 0 ),
        );
    }
    
    /**
     * Generate performance recommendations
     */
    private function generate_performance_recommendations( $mobile_results, $desktop_results ) {
        $recommendations = array();
        
        if ( is_wp_error( $mobile_results ) || is_wp_error( $desktop_results ) ) {
            return array( 'Unable to generate recommendations due to API errors.' );
        }
        
        // Performance score recommendations
        if ( $mobile_results['performance_score'] < 50 ) {
            $recommendations[] = 'Mobile performance is poor. Focus on Core Web Vitals optimization.';
        }
        
        if ( $desktop_results['performance_score'] < 70 ) {
            $recommendations[] = 'Desktop performance needs improvement. Consider image optimization and caching.';
        }
        
        // Core Web Vitals recommendations
        $mobile_cwv = $mobile_results['core_web_vitals'] ?? array();
        
        if ( isset( $mobile_cwv['lcp'] ) && $mobile_cwv['lcp']['rating'] === 'poor' ) {
            $recommendations[] = 'Largest Contentful Paint (LCP) is poor. Optimize images and server response time.';
        }
        
        if ( isset( $mobile_cwv['fid'] ) && $mobile_cwv['fid']['rating'] === 'poor' ) {
            $recommendations[] = 'First Input Delay (FID) needs improvement. Reduce JavaScript execution time.';
        }
        
        if ( isset( $mobile_cwv['cls'] ) && $mobile_cwv['cls']['rating'] === 'poor' ) {
            $recommendations[] = 'Cumulative Layout Shift (CLS) is high. Set dimensions for images and ads.';
        }
        
        // Opportunities-based recommendations
        if ( ! empty( $mobile_results['opportunities'] ) ) {
            foreach ( array_slice( $mobile_results['opportunities'], 0, 3 ) as $opportunity ) {
                if ( $opportunity['savings_ms'] > 1000 ) {
                    $recommendations[] = $opportunity['title'] . ' - Potential savings: ' . round( $opportunity['savings_ms'] / 1000, 1 ) . 's';
                }
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Add performance data to SEO analysis
     */
    public function add_performance_to_analysis( $analysis, $post ) {
        if ( ! $this->is_performance_monitoring_enabled() ) {
            return $analysis;
        }
        
        $performance_data = get_post_meta( $post->ID, '_ace_seo_pagespeed_report', true );
        
        if ( empty( $performance_data ) ) {
            return $analysis;
        }
        
        // Add performance score to overall SEO score calculation
        $mobile_score = $performance_data['mobile']['performance_score'] ?? 0;
        $performance_weight = 0.2; // 20% weight for performance in overall SEO score
        
        if ( $mobile_score > 0 ) {
            $analysis['performance_score'] = $mobile_score;
            $analysis['seo_score'] = round( 
                ( $analysis['seo_score'] * 0.8 ) + ( $mobile_score * $performance_weight ) 
            );
            
            // Add performance recommendations
            if ( $mobile_score < 50 ) {
                $analysis['recommendations'][] = array(
                    'type' => 'error',
                    'text' => 'Page performance is poor (Score: ' . $mobile_score . '). This negatively impacts SEO rankings.',
                );
            } elseif ( $mobile_score < 70 ) {
                $analysis['recommendations'][] = array(
                    'type' => 'warning',
                    'text' => 'Page performance could be improved (Score: ' . $mobile_score . '). Better performance improves SEO.',
                );
            } else {
                $analysis['recommendations'][] = array(
                    'type' => 'good',
                    'text' => 'Excellent page performance (Score: ' . $mobile_score . ')! This positively impacts SEO.',
                );
            }
        }
        
        return $analysis;
    }
    
    /**
     * Add performance column to admin
     */
    public function add_performance_column( $columns ) {
        if ( ! $this->is_performance_monitoring_enabled() ) {
            return $columns;
        }
        
        $new_columns = array();
        
        foreach ( $columns as $key => $title ) {
            $new_columns[ $key ] = $title;
            
            if ( $key === 'ace_seo_score' ) {
                $new_columns['ace_performance_score'] = 'Performance';
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Populate performance column
     */
    public function populate_performance_column( $column, $post_id ) {
        if ( $column !== 'ace_performance_score' ) {
            return;
        }
        
        $performance_data = get_post_meta( $post_id, '_ace_seo_pagespeed_report', true );
        
        if ( empty( $performance_data ) ) {
            echo '<span style="color: #999;">—</span>';
            return;
        }
        
        $mobile_score = $performance_data['mobile']['performance_score'] ?? 0;
        $color = $mobile_score >= 70 ? 'green' : ( $mobile_score >= 50 ? 'orange' : 'red' );
        $icon = $mobile_score >= 70 ? '⚡' : ( $mobile_score >= 50 ? '⚠️' : '🐌' );
        
        echo '<span style="color: ' . $color . '; font-weight: bold;" title="Mobile Performance Score">';
        echo $icon . ' ' . $mobile_score . '%';
        echo '</span>';
    }
    
    /**
     * Register REST API routes for performance data
     */
    public function register_performance_routes() {
        register_rest_route( 'ace-seo/v1', '/performance/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( $this, 'rest_get_performance_data' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ) );
        
        register_rest_route( 'ace-seo/v1', '/performance/test', array(
            'methods' => 'POST',
            'callback' => array( $this, 'rest_test_performance' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }
    
    /**
     * REST: Get performance data
     */
    public function rest_get_performance_data( $request ) {
        $post_id = $request['id'];
        $performance_data = get_post_meta( $post_id, '_ace_seo_pagespeed_report', true );
        
        if ( empty( $performance_data ) ) {
            return new WP_Error( 'no_data', 'No performance data found', array( 'status' => 404 ) );
        }
        
        return rest_ensure_response( $performance_data );
    }
    
    /**
     * REST: Test performance
     */
    public function rest_test_performance( $request ) {
        $params = $request->get_json_params();
        $url = esc_url_raw( $params['url'] ?? '' );
        $strategy = sanitize_text_field( $params['strategy'] ?? 'mobile' );
        
        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', 'URL is required', array( 'status' => 400 ) );
        }
        
        $result = $this->run_pagespeed_test( $url, $strategy );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        return rest_ensure_response( $result );
    }
    
    /**
     * Scheduled performance check
     */
    public function scheduled_performance_check() {
        if ( ! $this->is_performance_monitoring_enabled() ) {
            return;
        }
        
        $this->monitor_site_performance();
        
        // Check for performance alerts
        $this->check_performance_alerts();
    }
    
    /**
     * Check for performance alerts
     */
    private function check_performance_alerts() {
        $options = get_option( 'ace_seo_options', array() );
        $performance_settings = $options['performance'] ?? array();
        
        if ( empty( $performance_settings['pagespeed_alerts'] ) ) {
            return;
        }
        
        $monitoring_data = get_option( 'ace_seo_performance_monitoring', array() );
        
        foreach ( $monitoring_data as $data ) {
            if ( $data['mobile_score'] < 50 ) {
                $this->send_performance_alert( $data );
            }
        }
    }
    
    /**
     * Send performance alert
     */
    private function send_performance_alert( $data ) {
        $admin_email = get_option( 'admin_email' );
        $site_name = get_bloginfo( 'name' );
        
        $subject = '[' . $site_name . '] Performance Alert: Poor PageSpeed Score';
        
        $message = "Performance Alert\n\n";
        $message .= "URL: " . $data['url'] . "\n";
        $message .= "Mobile Performance Score: " . $data['mobile_score'] . "%\n";
        $message .= "Timestamp: " . $data['timestamp'] . "\n\n";
        $message .= "This score is below the recommended threshold of 50%. ";
        $message .= "Poor performance can negatively impact SEO rankings and user experience.\n\n";
        $message .= "Please review and optimize the page performance.";
        
        wp_mail( $admin_email, $subject, $message );
    }
    
    /**
     * Schedule performance monitoring
     */
    private function maybe_schedule_monitoring() {
        if ( $this->is_performance_monitoring_enabled() && ! wp_next_scheduled( 'ace_seo_daily_performance_check' ) ) {
            wp_schedule_event( time(), 'daily', 'ace_seo_daily_performance_check' );
        }
    }
    
    /**
     * Check if performance monitoring is enabled
     */
    private function is_performance_monitoring_enabled() {
        if ( ! class_exists( 'AceSEOApiHelper' ) ) {
            return false;
        }
        
        return AceSEOApiHelper::is_performance_monitoring_enabled();
    }
    
    /**
     * Get LCP rating
     */
    private function get_lcp_rating( $value_ms ) {
        if ( $value_ms <= 2500 ) return 'good';
        if ( $value_ms <= 4000 ) return 'needs-improvement';
        return 'poor';
    }
    
    /**
     * Get FID rating
     */
    private function get_fid_rating( $value_ms ) {
        if ( $value_ms <= 100 ) return 'good';
        if ( $value_ms <= 300 ) return 'needs-improvement';
        return 'poor';
    }
    
    /**
     * Get CLS rating
     */
    private function get_cls_rating( $value ) {
        if ( $value <= 0.1 ) return 'good';
        if ( $value <= 0.25 ) return 'needs-improvement';
        return 'poor';
    }
    
    /**
     * Get overall performance rating
     */
    private function get_overall_performance_rating( $score ) {
        if ( $score >= 0.9 ) return 'good';
        if ( $score >= 0.5 ) return 'needs-improvement';
        return 'poor';
    }
    
    /**
     * Check if URL is local/development environment
     */
    private function is_local_url( $url ) {
        $parsed_url = parse_url( $url );
        $host = $parsed_url['host'] ?? '';
        
        // Common local development indicators
        $local_indicators = array(
            'localhost',
            '127.0.0.1',
            '::1',
            '192.168.',
            '10.',
            '172.16.',
            '172.17.',
            '172.18.',
            '172.19.',
            '172.20.',
            '172.21.',
            '172.22.',
            '172.23.',
            '172.24.',
            '172.25.',
            '172.26.',
            '172.27.',
            '172.28.',
            '172.29.',
            '172.30.',
            '172.31.',
            '.local',
            '.test',
            '.dev',
            '.localhost'
        );
        
        foreach ( $local_indicators as $indicator ) {
            if ( strpos( $host, $indicator ) !== false || $host === $indicator ) {
                return true;
            }
        }
        
        // Check for custom local domains (no public TLD)
        if ( ! strpos( $host, '.' ) || substr_count( $host, '.' ) === 0 ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get suggestions for local development
     */
    private function get_local_development_suggestions() {
        return array(
            'Use a staging site with a public URL',
            'Deploy to a temporary public domain for testing',
            'Use ngrok or similar tunneling service to expose your local site',
            'Test on production after deployment',
            'Use browser developer tools for basic performance insights',
            'Consider local performance testing tools like Lighthouse CLI'
        );
    }
    
    /**
     * Generate mock PageSpeed data for local development
     */
    private function generate_mock_pagespeed_data( $url ) {
        // Generate realistic but random performance metrics for local testing
        $performance_score = rand( 65, 95 );
        $accessibility_score = rand( 85, 100 );
        $best_practices_score = rand( 80, 100 );
        $seo_score = rand( 90, 100 );
        
        // Generate Core Web Vitals based on performance score
        $lcp_good = $performance_score > 80;
        $fid_good = $performance_score > 70;
        $cls_good = $performance_score > 75;
        
        return array(
            'performance_score' => $performance_score,
            'accessibility_score' => $accessibility_score,
            'best_practices_score' => $best_practices_score,
            'seo_score' => $seo_score,
            'core_web_vitals' => array(
                'lcp' => array(
                    'value' => $lcp_good ? rand( 1500, 2400 ) : rand( 2500, 4500 ),
                    'displayValue' => $lcp_good ? '2.1 s' : '3.2 s',
                    'score' => $lcp_good ? 0.9 : 0.4,
                    'rating' => $lcp_good ? 'good' : 'poor',
                ),
                'fid' => array(
                    'value' => $fid_good ? rand( 50, 90 ) : rand( 150, 300 ),
                    'displayValue' => $fid_good ? '70 ms' : '200 ms',
                    'score' => $fid_good ? 0.95 : 0.3,
                    'rating' => $fid_good ? 'good' : 'poor',
                ),
                'cls' => array(
                    'value' => $cls_good ? 0.05 : 0.25,
                    'displayValue' => $cls_good ? '0.05' : '0.25',
                    'score' => $cls_good ? 0.9 : 0.4,
                    'rating' => $cls_good ? 'good' : 'poor',
                ),
            ),
            'performance_metrics' => array(
                'first_contentful_paint' => array(
                    'value' => rand( 1200, 2000 ),
                    'displayValue' => '1.5 s',
                ),
                'speed_index' => array(
                    'value' => rand( 2000, 4000 ),
                    'displayValue' => '3.1 s',
                ),
                'total_blocking_time' => array(
                    'value' => rand( 100, 600 ),
                    'displayValue' => '300 ms',
                ),
            ),
            'opportunities' => array(
                array(
                    'title' => 'Optimize images',
                    'description' => 'Properly size images to save cellular data and improve load time.',
                    'savings_ms' => rand( 500, 2000 ),
                    'savings_bytes' => rand( 50000, 200000 ),
                ),
                array(
                    'title' => 'Eliminate render-blocking resources',
                    'description' => 'Resources are blocking the first paint of your page.',
                    'savings_ms' => rand( 300, 1000 ),
                    'savings_bytes' => 0,
                ),
            ),
            'overall_rating' => $performance_score >= 90 ? 'good' : ( $performance_score >= 50 ? 'needs-improvement' : 'poor' ),
            'is_simulated' => true,
            'simulation_note' => 'This is simulated data for local development. Deploy to a public URL for real PageSpeed analysis.'
        );
    }
}

// Initialize the PageSpeed integration
new AceSEOPageSpeed();
