<?php
/**
 * Test script for background database optimization
 * Add this to your functions.php temporarily to test background optimization
 */

// Test the background optimization scheduling
add_action('init', 'test_ace_seo_background_optimization');

function test_ace_seo_background_optimization() {
    // Only run once for testing
    if (get_option('ace_seo_test_bg_optimization_done')) {
        return;
    }
    
    // Only run for administrators
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Schedule the background optimization
    if (!wp_next_scheduled('ace_seo_optimize_database')) {
        wp_schedule_single_event(time() + 5, 'ace_seo_optimize_database');
        update_option('ace_seo_db_optimization_pending', true);
        
        // Add admin notice
        add_action('admin_notices', function() {
            echo '<div class="notice notice-info"><p><strong>ACE SEO:</strong> Background database optimization scheduled in 5 seconds. Check the SEO Dashboard for progress.</p></div>';
        });
    }
    
    // Mark as done so it doesn't run again
    update_option('ace_seo_test_bg_optimization_done', true);
}
