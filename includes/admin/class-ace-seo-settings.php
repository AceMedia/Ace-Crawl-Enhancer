<?php
/**
 * Ace SEO Settings Handler
 * Handles the plugin settings pages and configuration
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOSettings {
    
    /**
     * Initialize settings functionality
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_ace_seo_migrate_yoast', array( $this, 'ajax_migrate_yoast_data' ) );
        add_action( 'wp_ajax_ace_seo_optimize_database_manual', array( $this, 'ajax_optimize_database_manual' ) );
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            'Ace SEO',
            'Ace SEO',
            'manage_options',
            'ace-seo',
            array( $this, 'render_dashboard_page' ),
            'dashicons-search',
            30
        );
        
        // Dashboard submenu
        add_submenu_page(
            'ace-seo',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'ace-seo',
            array( $this, 'render_dashboard_page' )
        );
        
        // Settings submenu
        add_submenu_page(
            'ace-seo',
            'Settings',
            'Settings',
            'manage_options',
            'ace-seo-settings',
            array( $this, 'render_settings_page' )
        );
        
        // Tools submenu
        add_submenu_page(
            'ace-seo',
            'Tools',
            'Tools',
            'manage_options',
            'ace-seo-tools',
            array( $this, 'render_tools_page' )
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        // General settings
        register_setting( 'ace_seo_general', 'ace_seo_settings' );
        register_setting( 'ace_seo_social', 'ace_seo_social_settings' );
        register_setting( 'ace_seo_advanced', 'ace_seo_advanced_settings' );
        register_setting( 'ace_seo_compatibility', 'ace_seo_compatibility_settings' );
        
        // General settings section
        add_settings_section(
            'ace_seo_general_section',
            'General Settings',
            array( $this, 'general_section_callback' ),
            'ace_seo_general'
        );
        
        // Social settings section
        add_settings_section(
            'ace_seo_social_section',
            'Social Media Settings',
            array( $this, 'social_section_callback' ),
            'ace_seo_social'
        );
        
        // Advanced settings section
        add_settings_section(
            'ace_seo_advanced_section',
            'Advanced Settings',
            array( $this, 'advanced_section_callback' ),
            'ace_seo_advanced'
        );
        
        // Compatibility settings section
        add_settings_section(
            'ace_seo_compatibility_section',
            'Yoast Compatibility',
            array( $this, 'compatibility_section_callback' ),
            'ace_seo_compatibility'
        );
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        include ACE_SEO_PATH . 'includes/admin/views/dashboard.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        include ACE_SEO_PATH . 'includes/admin/views/settings.php';
    }
    
    /**
     * Render tools page
     */
    public function render_tools_page() {
        // Handle migration request
        if (isset($_POST['migrate_yoast_data']) && wp_verify_nonce($_POST['_wpnonce'], 'ace_seo_migrate_yoast')) {
            $migrated = AceCrawlEnhancer::bulk_migrate_yoast_data();
            echo '<div class="notice notice-success"><p><strong>Migration Complete:</strong> Migrated ' . $migrated . ' fields from Yoast SEO.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Ace SEO Tools</h1>
            
            <!-- Migration Tool -->
            <div class="card">
                <h2>Data Migration</h2>
                <h3>Migrate from Yoast SEO</h3>
                <p>If you have existing Yoast SEO data, you can migrate it to Ace SEO. This will copy your SEO titles, meta descriptions, focus keywords, and other settings while preserving your original Yoast data.</p>
                
                <?php
                global $wpdb;
                $yoast_posts_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT post_id) 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key LIKE %s
                ", '_yoast_wpseo_%'));
                
                $ace_posts_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT post_id) 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key LIKE %s
                ", '_ace_seo_%'));
                ?>
                
                <p><strong>Status:</strong></p>
                <ul>
                    <li>Posts with Yoast SEO data: <?php echo $yoast_posts_count; ?></li>
                    <li>Posts with Ace SEO data: <?php echo $ace_posts_count; ?></li>
                </ul>
                
                <?php if ($yoast_posts_count > 0): ?>
                    <form method="post" action="">
                        <?php wp_nonce_field('ace_seo_migrate_yoast'); ?>
                        <input type="submit" name="migrate_yoast_data" class="button button-primary" value="Migrate Yoast SEO Data" onclick="return confirm('This will migrate SEO data from Yoast to Ace SEO. This is safe and won\'t delete your Yoast data. Continue?');">
                    </form>
                <?php else: ?>
                    <p><em>No Yoast SEO data found to migrate.</em></p>
                <?php endif; ?>
            </div>
            
            <!-- Database Optimization Tool -->
            <div class="card">
                <h2>Database Optimization</h2>
                <h3>Performance Indexing</h3>
                <p>Optimize your database with strategic indexes for lightning-fast SEO queries. This is the same optimization that runs automatically when the plugin is activated.</p>
                
                <?php
                // Get database performance analysis
                if (class_exists('ACE_SEO_Database_Optimizer')) {
                    $db_optimizer = new ACE_SEO_Database_Optimizer();
                    $analysis = $db_optimizer->analyze_performance();
                    
                    echo '<p><strong>Current Database Status:</strong></p>';
                    echo '<ul>';
                    echo '<li>SEO Meta Records: ' . number_format($analysis['seo_meta_records']) . '</li>';
                    echo '<li>Total Meta Records: ' . number_format($analysis['postmeta_records']) . '</li>';
                    echo '<li>Active SEO Indexes: ' . count(array_filter($analysis['existing_indexes'], function($index) {
                        return strpos($index, 'ace_seo') === 0;
                    })) . '</li>';
                    
                    $last_optimized = get_option('ace_seo_db_optimized', false);
                    if ($last_optimized) {
                        echo '<li>Last Optimized: ' . human_time_diff(strtotime($last_optimized), current_time('timestamp')) . ' ago</li>';
                    }
                    echo '</ul>';
                    
                    if (!empty($analysis['recommendations'])) {
                        echo '<div class="notice notice-warning inline"><p><strong>Recommendations:</strong></p><ul>';
                        foreach ($analysis['recommendations'] as $recommendation) {
                            echo '<li>' . esc_html($recommendation) . '</li>';
                        }
                        echo '</ul></div>';
                    }
                } else {
                    echo '<p><em>Database optimizer not available.</em></p>';
                }
                ?>
                
                <p><strong>What this does:</strong></p>
                <ul>
                    <li>Creates 5 strategic database indexes for optimal SEO query performance</li>
                    <li>Speeds up dashboard loading by 10-50x on large sites</li>
                    <li>Eliminates MariaDB CPU spikes during SEO operations</li>
                    <li>Safe operation - only affects database indexes, not your content</li>
                </ul>
                
                <button type="button" id="ace-optimize-db-btn" class="button button-primary">
                    Optimize Database Performance
                </button>
                
                <div id="ace-db-optimization-result" class="ace-optimization-result" style="display: none; margin-top: 15px;"></div>
            </div>
            
            <!-- Additional Tools -->
            <div class="card">
                <h2>Additional Tools</h2>
                <p>More SEO tools and utilities:</p>
                <ul>
                    <li>✅ Data Migration (above)</li>
                    <li>✅ Database Optimization (above)</li>
                    <li>🔄 Bulk SEO optimization (coming soon)</li>
                    <li>🔄 Content analysis reports (coming soon)</li>
                    <li>🔄 Broken link checker (coming soon)</li>
                    <li>🔄 Redirect manager (coming soon)</li>
                    <li>🔄 SEO audit tool (coming soon)</li>
                    <li>🔄 Sitemap management (coming soon)</li>
                </ul>
            </div>
        </div>
        
        <style>
        .card {
            max-width: none;
            margin: 20px 0;
        }
        .card h3 {
            margin-top: 0;
        }
        .ace-optimization-result {
            padding: 12px;
            border-radius: 4px;
            margin-top: 15px;
        }
        .ace-optimization-result.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .ace-optimization-result.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .ace-optimization-result.info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .notice.inline {
            display: inline-block;
            margin: 10px 0;
            padding: 10px 15px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#ace-optimize-db-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#ace-db-optimization-result');
                
                $btn.prop('disabled', true).text('Optimizing Database...');
                $result.hide().removeClass('success error info');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_optimize_database_manual',
                        nonce: '<?php echo wp_create_nonce('ace_seo_optimize_db_manual'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var message = '<strong>Database optimization completed!</strong><br><br>';
                            var hasResults = false;
                            
                            $.each(response.data, function(table, indexes) {
                                if (Object.keys(indexes).length > 0) {
                                    hasResults = true;
                                    message += '<strong>' + table.replace('_', ' ').toUpperCase() + ' TABLE:</strong><br>';
                                    $.each(indexes, function(index_name, result) {
                                        var status = result.status === 'created' ? '✅' : 
                                                   result.status === 'exists' ? '✓' : '⚠️';
                                        message += status + ' ' + index_name + ': ' + result.message + '<br>';
                                    });
                                    message += '<br>';
                                }
                            });
                            
                            if (!hasResults) {
                                message = '<strong>✅ Database optimization complete!</strong><br>All indexes are already optimized.';
                            } else {
                                message += '<em>Page will refresh in 3 seconds to show updated statistics...</em>';
                                setTimeout(function() {
                                    location.reload();
                                }, 3000);
                            }
                            
                            $result.addClass('success').html(message).show();
                        } else {
                            $result.addClass('error').html('<strong>Error:</strong> ' + response.data).show();
                        }
                    },
                    error: function() {
                        $result.addClass('error').html('<strong>Network error:</strong> Failed to optimize database. Please try again.').show();
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Optimize Database Performance');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for Yoast data migration
     */
    public function ajax_migrate_yoast_data() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ace_seo_migrate_yoast')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }
        
        $migrated = AceCrawlEnhancer::bulk_migrate_yoast_data();
        
        wp_send_json_success(array(
            'migrated' => $migrated,
            'message' => sprintf('Successfully migrated %d SEO fields from Yoast SEO.', $migrated)
        ));
    }
    
    /**
     * Section callbacks
     */
    public function general_section_callback() {
        echo '<p>Configure general SEO settings for your website.</p>';
    }
    
    public function social_section_callback() {
        echo '<p>Configure social media optimization settings.</p>';
    }
    
    public function advanced_section_callback() {
        echo '<p>Advanced SEO configuration options.</p>';
    }
    
    public function compatibility_section_callback() {
        echo '<p>Settings related to Yoast SEO compatibility and migration.</p>';
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts( $hook ) {
        // Only load on our admin pages
        if ( strpos( $hook, 'ace-seo' ) === false ) {
            return;
        }
        
        wp_enqueue_style(
            'ace-seo-admin',
            ACE_SEO_URL . 'assets/css/admin.css',
            array(),
            ACE_SEO_VERSION
        );
        
        wp_enqueue_script(
            'ace-seo-admin',
            ACE_SEO_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            ACE_SEO_VERSION,
            true
        );
        
        wp_enqueue_media(); // For image selection
    }
    
    /**
     * Get setting value with default
     */
    public static function get_setting( $option_group, $key, $default = '' ) {
        $options = get_option( $option_group, array() );
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }
    
    /**
     * Update setting value
     */
    public static function update_setting( $option_group, $key, $value ) {
        $options = get_option( $option_group, array() );
        $options[ $key ] = $value;
        return update_option( $option_group, $options );
    }
    
    /**
     * AJAX handler for manual database optimization
     */
    public function ajax_optimize_database_manual() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ace_seo_optimize_db_manual')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }
        
        // Include the database optimizer
        if (!class_exists('ACE_SEO_Database_Optimizer')) {
            if (file_exists(ACE_SEO_PATH . 'includes/database/class-database-optimizer.php')) {
                require_once ACE_SEO_PATH . 'includes/database/class-database-optimizer.php';
            } else {
                wp_send_json_error('Database optimizer class not found');
                return;
            }
        }
        
        try {
            $optimizer = new ACE_SEO_Database_Optimizer();
            $results = $optimizer->create_indexes();
            
            // Log the optimization results
            error_log('ACE SEO Manual Optimization: Database indexes updated - ' . print_r($results, true));
            
            // Update optimization status
            update_option('ace_seo_db_optimized', current_time('mysql'));
            update_option('ace_seo_db_optimization_results', $results);
            delete_option('ace_seo_db_optimization_pending');
            
            wp_send_json_success($results);
            
        } catch (Exception $e) {
            error_log('ACE SEO Manual Optimization Error: ' . $e->getMessage());
            wp_send_json_error('Database optimization failed: ' . $e->getMessage());
        }
    }
}

// Initialize the settings class
new AceSEOSettings();
