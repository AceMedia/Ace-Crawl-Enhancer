<?php
/**
 * Ace SEO Settings Handler
 * Handles the plugin settings pages and configuration
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.3
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
        add_action( 'wp_ajax_ace_seo_batch_migrate_yoast', array( $this, 'ajax_batch_migrate_yoast_data' ) );
        add_action( 'wp_ajax_ace_seo_get_migration_stats', array( $this, 'ajax_get_migration_stats' ) );
        add_action( 'wp_ajax_ace_seo_optimize_database_manual', array( $this, 'ajax_optimize_database_manual' ) );
        add_action( 'wp_ajax_ace_seo_refresh_dashboard_cache', array( $this, 'ajax_refresh_dashboard_cache' ) );
        add_action( 'wp_ajax_ace_seo_clear_dashboard_cache', array( $this, 'ajax_clear_dashboard_cache' ) );
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
        ?>
        <div class="wrap">
            <h1>Ace SEO Tools</h1>
            
            <!-- Migration Tool -->
            <div class="card">
                <h2>Data Migration</h2>
                <h3>Migrate from Yoast SEO</h3>
                <p>If you have existing Yoast SEO data, you can migrate it to Ace SEO. This will copy your SEO titles, meta descriptions, focus keywords, and other settings while preserving your original Yoast data.</p>
                
                <div id="ace-migration-status">
                    <p><strong>Status:</strong> <span id="migration-status-text">Loading...</span></p>
                    <div id="migration-stats">
                        <!-- Stats will be loaded via AJAX -->
                    </div>
                </div>
                
                <div id="ace-migration-controls">
                    <button type="button" id="start-migration-btn" class="button button-primary" disabled>
                        Start Migration
                    </button>
                    <button type="button" id="pause-migration-btn" class="button button-secondary" style="display: none;">
                        Pause Migration
                    </button>
                    <button type="button" id="resume-migration-btn" class="button button-secondary" style="display: none;">
                        Resume Migration
                    </button>
                    <button type="button" id="cancel-migration-btn" class="button" style="display: none;">
                        Cancel
                    </button>
                </div>
                
                <!-- Progress Bar -->
                <div id="ace-migration-progress" style="display: none;">
                    <div class="migration-progress-container">
                        <div class="migration-progress-bar">
                            <div class="migration-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="migration-progress-text">
                            <span id="migration-progress-current">0</span> / 
                            <span id="migration-progress-total">0</span> posts 
                            (<span id="migration-progress-percent">0</span>%)
                        </div>
                    </div>
                    
                    <div id="migration-current-item" class="migration-current-item">
                        <!-- Current processing item will be shown here -->
                    </div>
                    
                    <div id="migration-log" class="migration-log">
                        <h4>Migration Log:</h4>
                        <div id="migration-log-content"></div>
                    </div>
                </div>
                
                <!-- Results Summary -->
                <div id="ace-migration-results" style="display: none;">
                    <h4>Migration Results:</h4>
                    <div id="migration-results-content"></div>
                </div>
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
            
            <!-- Dashboard Cache Management -->
            <div class="card">
                <h2>Dashboard Cache</h2>
                <h3>Performance Statistics Cache</h3>
                <p>Manage dashboard statistics cache to prevent 504 timeouts on large sites. Statistics are cached for 1 hour to improve performance.</p>
                
                <?php
                // Load dashboard cache class
                if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                    require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
                }
                
                $cache_status = ACE_SEO_Dashboard_Cache::get_cache_status();
                ?>
                
                <p><strong>Cache Status:</strong></p>
                <ul>
                    <li>Statistics Cache: 
                        <?php if ($cache_status['stats_cached']): ?>
                            ✅ Active (<?php echo human_time_diff(time() - $cache_status['stats_age']); ?> old)
                        <?php else: ?>
                            ❌ Empty
                        <?php endif; ?>
                    </li>
                    <li>Recent Posts Cache: 
                        <?php if ($cache_status['recent_cached']): ?>
                            ✅ Active
                        <?php else: ?>
                            ❌ Empty
                        <?php endif; ?>
                    </li>
                    <li>Cache Duration: <?php echo human_time_diff(0, $cache_status['cache_duration']); ?></li>
                </ul>
                
                <p><strong>What this does:</strong></p>
                <ul>
                    <li>Caches expensive dashboard queries for 1 hour</li>
                    <li>Prevents 504 timeouts when loading ACE SEO dashboard</li>
                    <li>Automatically clears when posts are updated</li>
                    <li>Safe operation - only affects dashboard display speed</li>
                </ul>
                
                <button type="button" id="ace-refresh-cache-btn" class="button button-secondary">
                    🔄 Refresh Dashboard Cache
                </button>
                
                <button type="button" id="ace-clear-cache-btn" class="button button-secondary" style="margin-left: 10px;">
                    🗑️ Clear Cache
                </button>
                
                <div id="ace-cache-result" class="ace-optimization-result" style="display: none; margin-top: 15px;"></div>
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
        
        /* Migration Progress Styles */
        .migration-progress-container {
            margin: 20px 0;
        }
        .migration-progress-bar {
            width: 100%;
            height: 25px;
            background-color: #f0f0f1;
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }
        .migration-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #00a32a, #00ba37);
            transition: width 0.3s ease;
            position: relative;
        }
        .migration-progress-fill:after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(45deg, transparent 40%, rgba(255,255,255,0.3) 50%, transparent 60%);
            animation: shine 1.5s infinite;
        }
        @keyframes shine {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .migration-progress-text {
            text-align: center;
            margin-top: 10px;
            font-weight: 600;
            color: #1d2327;
        }
        .migration-current-item {
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 10px;
            margin: 15px 0;
            font-family: monospace;
            font-size: 13px;
        }
        .migration-log {
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        .migration-log h4 {
            margin-bottom: 10px;
        }
        .migration-log-content {
            background: #2c3338;
            color: #f0f0f1;
            padding: 15px;
            border-radius: 4px;
            font-family: "Consolas", "Monaco", "Courier New", monospace;
            font-size: 12px;
            line-height: 1.4;
            max-height: 250px;
            overflow-y: auto;
        }
        .migration-log-entry {
            margin-bottom: 5px;
        }
        .migration-log-entry.success {
            color: #4f9c4f;
        }
        .migration-log-entry.error {
            color: #d63384;
        }
        .migration-log-entry.warning {
            color: #ffc107;
        }
        .migration-log-entry.info {
            color: #0dcaf0;
        }
        #migration-stats {
            margin: 15px 0;
            padding: 15px;
            background: #f6f7f7;
            border-left: 4px solid #0073aa;
            border-radius: 0 4px 4px 0;
        }
        #migration-stats ul {
            margin: 0;
            list-style: none;
        }
        #migration-stats li {
            padding: 5px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #migration-stats .stat-value {
            font-weight: 600;
            color: #0073aa;
        }
        .button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let migrationState = {
                isRunning: false,
                isPaused: false,
                currentBatch: 0,
                totalPosts: 0,
                processedPosts: 0,
                batchSize: 10,
                totalMigrated: 0,
                errors: []
            };
            
            // Load initial migration stats
            loadMigrationStats();
            
            function loadMigrationStats() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_get_migration_stats',
                        nonce: '<?php echo wp_create_nonce('ace_seo_migration_stats'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateMigrationStats(response.data);
                            
                            if (response.data.yoast_posts > 0) {
                                $('#start-migration-btn').prop('disabled', false);
                                $('#migration-status-text').text('Ready to migrate');
                            } else {
                                $('#migration-status-text').text('No Yoast SEO data found to migrate');
                            }
                        } else {
                            $('#migration-status-text').text('Error loading migration stats');
                        }
                    },
                    error: function() {
                        $('#migration-status-text').text('Error loading migration stats');
                    }
                });
            }
            
            function updateMigrationStats(stats) {
                const statsHtml = `
                    <ul>
                        <li>
                            <span>Posts with Yoast SEO data:</span>
                            <span class="stat-value">${stats.yoast_posts}</span>
                        </li>
                        <li>
                            <span>Posts with Ace SEO data:</span>
                            <span class="stat-value">${stats.ace_posts}</span>
                        </li>
                        <li>
                            <span>Taxonomies with Yoast SEO data:</span>
                            <span class="stat-value">${stats.yoast_taxonomies || 0}</span>
                        </li>
                        <li>
                            <span>Taxonomies with Ace SEO data:</span>
                            <span class="stat-value">${stats.ace_taxonomies || 0}</span>
                        </li>
                        <li>
                            <span>Posts ready to migrate:</span>
                            <span class="stat-value">${stats.pending_migration}</span>
                        </li>
                        <li>
                            <span>Taxonomies ready to migrate:</span>
                            <span class="stat-value">${stats.pending_tax_migration || 0}</span>
                        </li>
                    </ul>
                `;
                $('#migration-stats').html(statsHtml);
            }
            
            function logMessage(message, type = 'info') {
                const timestamp = new Date().toLocaleTimeString();
                const logEntry = `<div class="migration-log-entry ${type}">[${timestamp}] ${message}</div>`;
                $('#migration-log-content').append(logEntry);
                
                // Auto scroll to bottom
                const logContent = $('#migration-log-content');
                logContent.scrollTop(logContent[0].scrollHeight);
            }
            
            function updateProgress() {
                const percent = migrationState.totalPosts > 0 ? 
                    Math.round((migrationState.processedPosts / migrationState.totalPosts) * 100) : 0;
                
                $('.migration-progress-fill').css('width', percent + '%');
                $('#migration-progress-current').text(migrationState.processedPosts);
                $('#migration-progress-total').text(migrationState.totalPosts);
                $('#migration-progress-percent').text(percent);
            }
            
            function updateCurrentItem(item) {
                if (item) {
                    const itemHtml = `Processing: <strong>${item.title}</strong> (ID: ${item.id}, Type: ${item.type})`;
                    $('#migration-current-item').html(itemHtml).show();
                } else {
                    $('#migration-current-item').hide();
                }
            }
            
            function processBatch() {
                if (!migrationState.isRunning || migrationState.isPaused) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_batch_migrate_yoast',
                        batch: migrationState.currentBatch,
                        batch_size: migrationState.batchSize,
                        nonce: '<?php echo wp_create_nonce('ace_seo_batch_migrate'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            const data = response.data;
                            
                            // Update progress
                            migrationState.processedPosts += data.processed;
                            migrationState.totalMigrated += data.migrated;
                            migrationState.currentBatch++;
                            
                            // Update UI
                            updateProgress();
                            
                            // Log batch results
                            logMessage(`Batch ${migrationState.currentBatch}: Processed ${data.processed} posts, migrated ${data.migrated} fields`, 'success');
                            
                            // Update current item
                            if (data.current_item) {
                                updateCurrentItem(data.current_item);
                            }
                            
                            // Log any errors
                            if (data.errors && data.errors.length > 0) {
                                data.errors.forEach(error => {
                                    logMessage(error, 'error');
                                    migrationState.errors.push(error);
                                });
                            }
                            
                            // Check if migration is complete
                            if (data.completed) {
                                completeMigration();
                            } else {
                                // Process next batch after a short delay
                                setTimeout(processBatch, 500);
                            }
                        } else {
                            logMessage('Error: ' + response.data, 'error');
                            stopMigration();
                        }
                    },
                    error: function(xhr, status, error) {
                        logMessage(`Network error: ${error}`, 'error');
                        stopMigration();
                    }
                });
            }
            
            function startMigration() {
                migrationState.isRunning = true;
                migrationState.isPaused = false;
                migrationState.currentBatch = 0;
                migrationState.processedPosts = 0;
                migrationState.totalMigrated = 0;
                migrationState.errors = [];
                
                // Update UI
                $('#start-migration-btn').hide();
                $('#pause-migration-btn, #cancel-migration-btn').show();
                $('#ace-migration-progress').show();
                $('#migration-status-text').text('Migration in progress...');
                
                // Clear previous logs
                $('#migration-log-content').empty();
                
                // Get total count and start processing
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_get_migration_stats',
                        nonce: '<?php echo wp_create_nonce('ace_seo_migration_stats'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            migrationState.totalPosts = response.data.pending_migration;
                            updateProgress();
                            logMessage(`Starting migration of ${migrationState.totalPosts} posts...`, 'info');
                            
                            // Start processing
                            processBatch();
                        } else {
                            logMessage('Error getting migration stats: ' + response.data, 'error');
                            stopMigration();
                        }
                    }
                });
            }
            
            function pauseMigration() {
                migrationState.isPaused = true;
                $('#pause-migration-btn').hide();
                $('#resume-migration-btn').show();
                $('#migration-status-text').text('Migration paused');
                logMessage('Migration paused by user', 'warning');
            }
            
            function resumeMigration() {
                migrationState.isPaused = false;
                $('#resume-migration-btn').hide();
                $('#pause-migration-btn').show();
                $('#migration-status-text').text('Migration in progress...');
                logMessage('Migration resumed', 'info');
                
                // Continue processing
                processBatch();
            }
            
            function stopMigration() {
                migrationState.isRunning = false;
                migrationState.isPaused = false;
                
                $('#pause-migration-btn, #resume-migration-btn, #cancel-migration-btn').hide();
                $('#start-migration-btn').show().prop('disabled', false);
                $('#migration-status-text').text('Migration stopped');
                updateCurrentItem(null);
                logMessage('Migration stopped', 'warning');
            }
            
            function completeMigration() {
                migrationState.isRunning = false;
                migrationState.isPaused = false;
                
                $('#pause-migration-btn, #resume-migration-btn, #cancel-migration-btn').hide();
                $('#start-migration-btn').show().prop('disabled', false);
                $('#migration-status-text').text('Migration completed successfully!');
                updateCurrentItem(null);
                
                logMessage(`Migration completed! Migrated ${migrationState.totalMigrated} SEO fields from ${migrationState.processedPosts} posts`, 'success');
                
                if (migrationState.errors.length > 0) {
                    logMessage(`Completed with ${migrationState.errors.length} errors - check log above`, 'warning');
                }
                
                // Show results summary
                showMigrationResults();
                
                // Refresh stats
                setTimeout(loadMigrationStats, 1000);
            }
            
            function showMigrationResults() {
                const resultsHtml = `
                    <div class="ace-optimization-result success">
                        <p><strong>Migration Summary:</strong></p>
                        <ul>
                            <li>Posts processed: ${migrationState.processedPosts}</li>
                            <li>SEO fields migrated: ${migrationState.totalMigrated}</li>
                            <li>Errors encountered: ${migrationState.errors.length}</li>
                            <li>Duration: Started at ${new Date().toLocaleTimeString()}</li>
                        </ul>
                        ${migrationState.errors.length > 0 ? 
                            '<p><em>Some errors occurred during migration. Check the log above for details.</em></p>' : 
                            '<p><em>All data migrated successfully!</em></p>'
                        }
                    </div>
                `;
                $('#migration-results-content').html(resultsHtml);
                $('#ace-migration-results').show();
            }
            
            // Event handlers
            $('#start-migration-btn').on('click', startMigration);
            $('#pause-migration-btn').on('click', pauseMigration);
            $('#resume-migration-btn').on('click', resumeMigration);
            $('#cancel-migration-btn').on('click', function() {
                if (confirm('Are you sure you want to cancel the migration? Progress will be saved and you can resume later.')) {
                    stopMigration();
                }
            });
            
            // Database optimization handler
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
            
            // Cache management handlers
            $('#ace-refresh-cache-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#ace-cache-result');
                
                $btn.prop('disabled', true).text('🔄 Refreshing...');
                $result.hide().removeClass('success error info');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_refresh_dashboard_cache',
                        nonce: '<?php echo wp_create_nonce('ace_seo_admin'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var message = '<strong>Cache refreshed successfully!</strong><br>';
                            message += 'Statistics: ' + response.data.stats.total_posts + ' posts, ';
                            message += response.data.stats.focus_keywords + ' with keywords, ';
                            message += response.data.stats.meta_descriptions + ' with descriptions<br>';
                            message += 'Recent posts cached: ' + response.data.recent_posts_count;
                            
                            $result.addClass('success').html(message).show();
                            
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $result.addClass('error').html('<strong>Error:</strong> ' + response.data).show();
                        }
                    },
                    error: function() {
                        $result.addClass('error').html('<strong>Network error:</strong> Failed to refresh cache.').show();
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('🔄 Refresh Dashboard Cache');
                    }
                });
            });
            
            $('#ace-clear-cache-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#ace-cache-result');
                
                $btn.prop('disabled', true).text('🗑️ Clearing...');
                $result.hide().removeClass('success error info');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_clear_dashboard_cache',
                        nonce: '<?php echo wp_create_nonce('ace_seo_admin'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.addClass('success').html('<strong>Cache cleared successfully!</strong><br>' + response.data.note).show();
                        } else {
                            $result.addClass('error').html('<strong>Error:</strong> ' + response.data).show();
                        }
                    },
                    error: function() {
                        $result.addClass('error').html('<strong>Network error:</strong> Failed to clear cache.').show();
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('🗑️ Clear Cache');
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
    
    /**
     * AJAX handler for refreshing dashboard cache
     */
    public function ajax_refresh_dashboard_cache() {
        // Check permissions and nonce
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        check_ajax_referer('ace_seo_admin', 'nonce');
        
        try {
            // Load dashboard cache class
            if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
            }
            
            // Force regenerate cache
            $result = ACE_SEO_Dashboard_Cache::force_regenerate();
            
            wp_send_json_success(array(
                'message' => 'Dashboard cache refreshed successfully!',
                'stats' => array(
                    'focus_keywords' => $result['stats']['focus_keywords_count'],
                    'meta_descriptions' => $result['stats']['meta_desc_count'],
                    'total_posts' => $result['stats']['total_posts']
                ),
                'recent_posts_count' => count($result['recent_posts']),
                'regenerated_at' => date('Y-m-d H:i:s', $result['regenerated_at'])
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to refresh cache: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for clearing dashboard cache
     */
    public function ajax_clear_dashboard_cache() {
        // Check permissions and nonce
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        check_ajax_referer('ace_seo_admin', 'nonce');
        
        try {
            // Load dashboard cache class
            if (!class_exists('ACE_SEO_Dashboard_Cache')) {
                require_once ACE_SEO_PATH . 'includes/admin/class-ace-seo-dashboard-cache.php';
            }
            
            // Clear all cache
            $cleared = ACE_SEO_Dashboard_Cache::clear_cache();
            
            if ($cleared) {
                wp_send_json_success(array(
                    'message' => 'Dashboard cache cleared successfully!',
                    'note' => 'Cache will be regenerated on next dashboard visit.'
                ));
            } else {
                wp_send_json_error('Failed to clear cache');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to clear cache: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for batch Yoast data migration
     */
    public function ajax_batch_migrate_yoast_data() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ace_seo_batch_migrate')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }
        
        $batch = intval($_POST['batch']);
        $batch_size = intval($_POST['batch_size']) ?: 10;
        $offset = $batch * $batch_size;
        
        global $wpdb;
        
        // Get posts with Yoast data that haven't been fully migrated yet
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.ID, p.post_title, p.post_type 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} ace_check ON (p.ID = ace_check.post_id AND ace_check.meta_key = '_ace_seo_migration_check')
            WHERE pm.meta_key LIKE '_yoast_wpseo_%'
            AND (ace_check.meta_value IS NULL OR ace_check.meta_value < %d)
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ", time() - (7 * DAY_IN_SECONDS), $batch_size, $offset));
        
        $processed = 0;
        $migrated = 0;
        $errors = [];
        $current_item = null;
        
        // Process posts
        foreach ($posts as $post) {
            try {
                $current_item = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => 'post: ' . $post->post_type
                ];
                
                // Migrate this post's Yoast data
                $post_migrated = AceCrawlEnhancer::migrate_yoast_data($post->ID);
                $migrated += $post_migrated;
                $processed++;
                
                // Mark as checked
                update_post_meta($post->ID, '_ace_seo_migration_check', time());
                
                // Add small delay to prevent server overload
                usleep(50000); // 50ms delay
                
            } catch (Exception $e) {
                $errors[] = "Error processing post {$post->ID} ({$post->post_title}): " . $e->getMessage();
            }
        }
        
        // If we processed fewer posts than batch size, start processing taxonomies
        if (count($posts) < $batch_size) {
            $yoast_tax_meta = get_option('wpseo_taxonomy_meta', []);
            $taxonomy_processed = 0;
            $tax_offset = max(0, $offset - $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                LEFT JOIN {$wpdb->postmeta} ace_check ON (p.ID = ace_check.post_id AND ace_check.meta_key = '_ace_seo_migration_check')
                WHERE pm.meta_key LIKE '_yoast_wpseo_%'
                AND (ace_check.meta_value IS NULL OR ace_check.meta_value < %d)
                AND p.post_status IN ('publish', 'draft', 'private', 'future')
            ", time() - (7 * DAY_IN_SECONDS))));
            
            $remaining_batch_size = $batch_size - count($posts);
            $current_tax_count = 0;
            
            foreach ($yoast_tax_meta as $taxonomy => $terms) {
                foreach ($terms as $term_id => $meta_data) {
                    if ($current_tax_count < $tax_offset) {
                        $current_tax_count++;
                        continue;
                    }
                    
                    if ($taxonomy_processed >= $remaining_batch_size) {
                        break 2;
                    }
                    
                    try {
                        // Check if already migrated recently
                        $migration_check = get_term_meta($term_id, '_ace_seo_taxonomy_migration_check', true);
                        if (!empty($migration_check) && (time() - $migration_check) <= (7 * DAY_IN_SECONDS)) {
                            $current_tax_count++;
                            continue;
                        }
                        
                        $term = get_term($term_id, $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            $current_item = [
                                'id' => $term_id,
                                'title' => $term->name,
                                'type' => 'taxonomy: ' . $taxonomy
                            ];
                            
                            // Migrate this term's Yoast data
                            $tax_migrated = AceCrawlEnhancer::migrate_yoast_taxonomy_data($term_id, $taxonomy);
                            $migrated += $tax_migrated;
                            $processed++;
                            $taxonomy_processed++;
                            
                            // Mark as checked
                            update_term_meta($term_id, '_ace_seo_taxonomy_migration_check', time());
                            
                            // Add small delay
                            usleep(50000); // 50ms delay
                        }
                        
                    } catch (Exception $e) {
                        $errors[] = "Error processing taxonomy term {$term_id} ({$taxonomy}): " . $e->getMessage();
                    }
                    
                    $current_tax_count++;
                }
            }
        }
        
        // Check if migration is complete for posts
        $remaining_posts = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} ace_check ON (p.ID = ace_check.post_id AND ace_check.meta_key = '_ace_seo_migration_check')
            WHERE pm.meta_key LIKE '_yoast_wpseo_%'
            AND (ace_check.meta_value IS NULL OR ace_check.meta_value < %d)
            AND p.post_status IN ('publish', 'draft', 'private', 'future')
        ", time() - (7 * DAY_IN_SECONDS)));
        
        // Check remaining taxonomies
        $yoast_tax_meta = get_option('wpseo_taxonomy_meta', []);
        $remaining_taxonomies = 0;
        
        foreach ($yoast_tax_meta as $taxonomy => $terms) {
            foreach ($terms as $term_id => $meta_data) {
                $migration_check = get_term_meta($term_id, '_ace_seo_taxonomy_migration_check', true);
                if (empty($migration_check) || (time() - $migration_check) > (7 * DAY_IN_SECONDS)) {
                    $remaining_taxonomies++;
                }
            }
        }
        
        $remaining = $remaining_posts + $remaining_taxonomies;
        $completed = ($remaining == 0);
        
        wp_send_json_success([
            'processed' => $processed,
            'migrated' => $migrated,
            'errors' => $errors,
            'current_item' => $current_item,
            'completed' => $completed,
            'remaining' => $remaining
        ]);
    }
    
    /**
     * AJAX handler for getting migration statistics
     */
    public function ajax_get_migration_stats() {
        // Security check
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ace_seo_migration_stats')) {
            wp_send_json_error('Insufficient permissions or invalid nonce');
            return;
        }
        
        $stats = AceCrawlEnhancer::get_migration_stats();
        wp_send_json_success($stats);
    }
}

// Initialize the settings class
new AceSEOSettings();
