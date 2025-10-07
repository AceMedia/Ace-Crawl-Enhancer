<?php
/**
 * Database Optimizer Class
 * Handles database indexing and optimization for better SEO plugin performance
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACE_SEO_Database_Optimizer {
    
    /**
     * Initialize database optimizer
     */
    public function __construct() {
        // Hook into plugin activation
        register_activation_hook(ACE_SEO_FILE, array($this, 'create_indexes'));
        
        // Add admin action for manual optimization
        add_action('wp_ajax_ace_seo_optimize_database', array($this, 'ajax_optimize_database'));
    }
    
    /**
     * Create database indexes for better performance
     */
    public function create_indexes() {
        global $wpdb;
        
        $indexes = $this->get_required_indexes();
        $results = array();
        
        foreach ($indexes as $table => $table_indexes) {
            foreach ($table_indexes as $index_name => $index_data) {
                $result = $this->create_index($table, $index_name, $index_data);
                $results[$table][$index_name] = $result;
            }
        }
        
        // Log results
        error_log('ACE SEO: Database indexes created - ' . print_r($results, true));
        
        return $results;
    }
    
    /**
     * Get list of required indexes for SEO optimization
     */
    private function get_required_indexes() {
        global $wpdb;
        
        return array(
            $wpdb->postmeta => array(
                'ace_seo_meta_key_value' => array(
                    'columns' => array('meta_key', 'meta_value'),
                    'type' => 'INDEX',
                    'prefix_length' => array('meta_key' => null, 'meta_value' => 50)
                ),
                'ace_seo_post_meta_key' => array(
                    'columns' => array('post_id', 'meta_key'),
                    'type' => 'INDEX'
                ),
                'ace_seo_yoast_meta' => array(
                    'columns' => array('meta_key', 'post_id'),
                    'type' => 'INDEX'
                )
            ),
            $wpdb->posts => array(
                'ace_seo_post_status_type_modified' => array(
                    'columns' => array('post_status', 'post_type', 'post_modified'),
                    'type' => 'INDEX'
                ),
                'ace_seo_post_type_status' => array(
                    'columns' => array('post_type', 'post_status'),
                    'type' => 'INDEX'
                )
            )
        );
    }
    
    /**
     * Create a single index
     */
    private function create_index($table, $index_name, $index_data) {
        global $wpdb;
        
        try {
            // Check if index already exists
            $existing_indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
            foreach ($existing_indexes as $existing_index) {
                if ($existing_index['Key_name'] === $index_name) {
                    return array('status' => 'exists', 'message' => "Index {$index_name} already exists");
                }
            }
            
            // Build CREATE INDEX statement
            $columns = array();
            foreach ($index_data['columns'] as $col) {
                if (isset($index_data['prefix_length']) && isset($index_data['prefix_length'][$col])) {
                    $prefix = $index_data['prefix_length'][$col];
                    $columns[] = "`{$col}`" . ($prefix ? "({$prefix})" : '');
                } else {
                    $columns[] = "`{$col}`";
                }
            }
            $columns_str = implode(', ', $columns);
            
            $sql = "CREATE {$index_data['type']} `{$index_name}` ON `{$table}` ({$columns_str})";
            
            // Execute the query
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                return array('status' => 'created', 'message' => "Index {$index_name} created successfully");
            } else {
                return array('status' => 'error', 'message' => "Failed to create index {$index_name}: " . $wpdb->last_error);
            }
            
        } catch (Exception $e) {
            return array('status' => 'error', 'message' => "Exception creating index {$index_name}: " . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for manual database optimization
     */
    public function ajax_optimize_database() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'ace_seo_optimize_db') || !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $results = $this->create_indexes();
        
        wp_send_json_success($results);
    }
    
    /**
     * Check database performance and suggest optimizations
     */
    public function analyze_performance() {
        global $wpdb;
        
        $analysis = array();
        
        // Check postmeta table size
        $postmeta_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
        $analysis['postmeta_records'] = $postmeta_count;
        
        // Check for SEO meta records
        $seo_meta_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key LIKE '_yoast_wpseo_%'
        ");
        $analysis['seo_meta_records'] = $seo_meta_count;
        
        // Check existing indexes
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta}", ARRAY_A);
        $analysis['existing_indexes'] = array_column($indexes, 'Key_name');
        
        // Performance recommendations
        $analysis['recommendations'] = array();
        
        if ($postmeta_count > 10000 && !in_array('ace_seo_meta_key_value', $analysis['existing_indexes'])) {
            $analysis['recommendations'][] = 'Add meta_key + meta_value index for better SEO queries';
        }
        
        if ($seo_meta_count > 1000 && !in_array('ace_seo_yoast_meta', $analysis['existing_indexes'])) {
            $analysis['recommendations'][] = 'Add specialized Yoast SEO meta index';
        }
        
        return $analysis;
    }
    
    /**
     * Remove indexes (for uninstall)
     */
    public function remove_indexes() {
        global $wpdb;
        
        $indexes = $this->get_required_indexes();
        $results = array();
        
        foreach ($indexes as $table => $table_indexes) {
            foreach ($table_indexes as $index_name => $index_data) {
                try {
                    $sql = "DROP INDEX `{$index_name}` ON `{$table}`";
                    $result = $wpdb->query($sql);
                    $results[$table][$index_name] = $result !== false ? 'removed' : 'error';
                } catch (Exception $e) {
                    $results[$table][$index_name] = 'not_found';
                }
            }
        }
        
        return $results;
    }
}

// Initialize the database optimizer
new ACE_SEO_Database_Optimizer();
