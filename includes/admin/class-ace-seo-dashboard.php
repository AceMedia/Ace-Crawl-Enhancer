<?php
/**
 * Ace SEO Dashboard Widget
 * Displays performance overview on the WordPress dashboard
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEODashboard {
    
    /**
     * Initialize dashboard functionality
     */
    public function __construct() {
        add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );
    }
    
    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets() {
        // Only show to users who can edit posts
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        
        wp_add_dashboard_widget(
            'ace_seo_performance_overview',
            'Ace SEO - Performance Overview',
            array( $this, 'render_performance_widget' )
        );
    }
    
    /**
     * Render performance overview widget
     */
    public function render_performance_widget() {
        if ( ! class_exists( 'AceSEOApiHelper' ) || ! AceSEOApiHelper::is_performance_monitoring_enabled() ) {
            echo '<p>PageSpeed monitoring is not enabled. <a href="' . admin_url( 'admin.php?page=ace-seo-settings' ) . '">Configure it here</a>.</p>';
            return;
        }
        
        $monitoring_data = get_option( 'ace_seo_performance_monitoring', array() );
        
        if ( empty( $monitoring_data ) ) {
            echo '<p>No performance data available yet. Data will appear after the first monitoring cycle.</p>';
            return;
        }
        
        echo '<div class="ace-dashboard-performance">';
        
        // Overall site performance summary
        $total_score = 0;
        $count = 0;
        
        foreach ( $monitoring_data as $data ) {
            if ( isset( $data['mobile_score'] ) ) {
                $total_score += $data['mobile_score'];
                $count++;
            }
        }
        
        $average_score = $count > 0 ? round( $total_score / $count ) : 0;
        $performance_status = $this->get_performance_status( $average_score );
        
        echo '<div class="ace-performance-summary">';
        echo '<h4>Site Performance Overview</h4>';
        echo '<div class="ace-score-display">';
        echo '<div class="ace-score-circle ' . $performance_status['class'] . '">';
        echo '<span class="ace-score-number">' . $average_score . '</span>';
        echo '<span class="ace-score-label">Average Score</span>';
        echo '</div>';
        echo '<div class="ace-score-details">';
        echo '<p><strong>Status:</strong> ' . $performance_status['label'] . '</p>';
        echo '<p><strong>Pages Monitored:</strong> ' . count( $monitoring_data ) . '</p>';
        echo '<p><strong>Last Check:</strong> ' . human_time_diff( strtotime( $monitoring_data[0]['timestamp'] ?? '' ) ) . ' ago</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Individual page performance
        echo '<div class="ace-page-performance">';
        echo '<h4>Recent Page Performance</h4>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>URL</th><th>Score</th><th>Core Web Vitals</th></tr></thead>';
        echo '<tbody>';
        
        foreach ( array_slice( $monitoring_data, 0, 5 ) as $data ) {
            $score = $data['mobile_score'] ?? 0;
            $score_class = $this->get_performance_status( $score )['class'];
            $url = $data['url'] ?? '';
            $url_display = $this->get_url_display( $url );
            
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url_display ) . '</a></td>';
            echo '<td><span class="ace-score-badge ' . $score_class . '">' . $score . '%</span></td>';
            
            // Core Web Vitals summary
            $cwv = $data['core_web_vitals'] ?? array();
            echo '<td>';
            if ( ! empty( $cwv ) ) {
                $cwv_issues = 0;
                foreach ( $cwv as $metric => $values ) {
                    if ( isset( $values['rating'] ) && $values['rating'] === 'poor' ) {
                        $cwv_issues++;
                    }
                }
                
                if ( $cwv_issues === 0 ) {
                    echo '<span class="ace-cwv-status good">✓ All Good</span>';
                } else {
                    echo '<span class="ace-cwv-status warning">' . $cwv_issues . ' Issue' . ( $cwv_issues > 1 ? 's' : '' ) . '</span>';
                }
            } else {
                echo '<span class="ace-cwv-status unknown">—</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        
        // Quick actions
        echo '<div class="ace-dashboard-actions">';
        echo '<a href="' . admin_url( 'admin.php?page=ace-seo-settings' ) . '" class="button">Settings</a>';
        echo '<button type="button" class="button button-primary" onclick="triggerPerformanceCheck()">Run Check Now</button>';
        echo '</div>';
        
        echo '</div>';
        
        // Add inline styles and script
        $this->add_dashboard_styles();
        $this->add_dashboard_script();
    }
    
    /**
     * Get performance status based on score
     */
    private function get_performance_status( $score ) {
        if ( $score >= 90 ) {
            return array( 'class' => 'excellent', 'label' => 'Excellent' );
        } elseif ( $score >= 70 ) {
            return array( 'class' => 'good', 'label' => 'Good' );
        } elseif ( $score >= 50 ) {
            return array( 'class' => 'ok', 'label' => 'Needs Improvement' );
        } else {
            return array( 'class' => 'poor', 'label' => 'Poor' );
        }
    }
    
    /**
     * Get simplified URL display
     */
    private function get_url_display( $url ) {
        $path = parse_url( $url, PHP_URL_PATH );
        
        if ( $path === '/' || empty( $path ) ) {
            return 'Homepage';
        }
        
        return trim( $path, '/' );
    }
    
    /**
     * Add dashboard styles
     */
    private function add_dashboard_styles() {
        echo '<style>
        .ace-dashboard-performance {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .ace-performance-summary {
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 6px;
        }
        
        .ace-score-display {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .ace-score-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            position: relative;
        }
        
        .ace-score-circle.excellent { background: #0f7b0f; }
        .ace-score-circle.good { background: #8b6914; }
        .ace-score-circle.ok { background: #d73502; }
        .ace-score-circle.poor { background: #c7254e; }
        
        .ace-score-number {
            font-size: 24px;
            line-height: 1;
        }
        
        .ace-score-label {
            font-size: 10px;
            text-transform: uppercase;
        }
        
        .ace-score-details p {
            margin: 5px 0;
            font-size: 13px;
        }
        
        .ace-page-performance {
            margin-bottom: 15px;
        }
        
        .ace-score-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            color: white;
        }
        
        .ace-score-badge.excellent { background: #0f7b0f; }
        .ace-score-badge.good { background: #8b6914; }
        .ace-score-badge.ok { background: #d73502; }
        .ace-score-badge.poor { background: #c7254e; }
        
        .ace-cwv-status.good { color: #0f7b0f; font-weight: bold; }
        .ace-cwv-status.warning { color: #d73502; font-weight: bold; }
        .ace-cwv-status.unknown { color: #666; }
        
        .ace-dashboard-actions {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e1e1e1;
        }
        
        .ace-dashboard-actions .button {
            margin-right: 10px;
        }
        </style>';
    }
    
    /**
     * Add dashboard script
     */
    private function add_dashboard_script() {
        echo '<script>
        function triggerPerformanceCheck() {
            var button = event.target;
            button.disabled = true;
            button.textContent = "Running...";
            
            jQuery.ajax({
                url: ajaxurl,
                method: "POST",
                data: {
                    action: "ace_seo_monitor_performance",
                    nonce: "' . wp_create_nonce( 'ace_seo_performance_test' ) . '"
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert("Error: " + (response.data.message || "Unknown error"));
                    }
                },
                error: function() {
                    alert("Network error occurred");
                },
                complete: function() {
                    button.disabled = false;
                    button.textContent = "Run Check Now";
                }
            });
        }
        </script>';
    }
}

// Initialize the dashboard
new AceSEODashboard();
