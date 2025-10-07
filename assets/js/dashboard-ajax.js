/**
 * ACE SEO Dashboard AJAX Handler
 * Progressive loading system for dashboard data
 */

(function($) {
    'use strict';
    
    const Dashboard = {
        
        init: function() {
            this.bindEvents();
            this.startProgressiveLoad();
        },
        
        bindEvents: function() {
            // Refresh button handlers
            $(document).on('click', '.ace-refresh-stats', this.refreshStats.bind(this));
            $(document).on('click', '.ace-refresh-activity', this.refreshActivity.bind(this));
            $(document).on('click', '.ace-refresh-analysis', this.refreshAnalysis.bind(this));
            $(document).on('click', '.ace-refresh-database', this.refreshDatabase.bind(this));
            
            // Database optimization handlers
            $(document).on('click', '#ace-optimize-database', this.startDatabaseOptimization.bind(this));
            $(document).on('click', '#ace-optimize-db-btn', this.startDatabaseOptimization.bind(this));
        },
        
        startProgressiveLoad: function() {
            // Load components in sequence for better UX
            this.loadDashboardStats();
            
            // Delay subsequent loads to prevent server overload
            setTimeout(() => this.loadRecentActivity(), 500);
            setTimeout(() => this.loadContentAnalysis(), 1000);
            setTimeout(() => this.loadDatabasePerformance(), 1500);
        },
        
        loadDashboardStats: function() {
            const $container = $('#ace-seo-stats-container');
            
            if (!$container.length) return;
            
            this.showLoading($container, aceSEODashboard.strings.loadingStats);
            
            $.ajax({
                url: aceSEODashboard.ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_load_dashboard_stats',
                    nonce: aceSEODashboard.nonce
                },
                success: (response) => {
                    if (response.status === 'success') {
                        this.renderStats(response.data);
                        
                        // If site is large, offer detailed analysis
                        if (response.data.needs_full_analysis) {
                            this.showFullAnalysisOption(response.data.total_posts);
                        }
                    } else {
                        this.showError($container, response.message || aceSEODashboard.strings.error);
                    }
                },
                error: () => {
                    this.showError($container, aceSEODashboard.strings.error);
                }
            });
        },
        
        loadRecentActivity: function() {
            const $container = $('#ace-recent-activity-container');
            
            if (!$container.length) return;
            
            this.showLoading($container, aceSEODashboard.strings.loadingActivity);
            
            $.ajax({
                url: aceSEODashboard.ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_load_recent_activity',
                    nonce: aceSEODashboard.nonce,
                    limit: 5
                },
                success: (response) => {
                    if (response.status === 'success') {
                        this.renderRecentActivity(response.data);
                    } else {
                        this.showError($container, response.message || aceSEODashboard.strings.error);
                    }
                },
                error: () => {
                    this.showError($container, aceSEODashboard.strings.error);
                }
            });
        },
        
        loadContentAnalysis: function() {
            const $container = $('#ace-content-analysis-container');
            
            if (!$container.length) return;
            
            this.showLoading($container, aceSEODashboard.strings.loadingAnalysis);
            
            $.ajax({
                url: aceSEODashboard.ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_load_content_analysis',
                    nonce: aceSEODashboard.nonce
                },
                success: (response) => {
                    if (response.status === 'success') {
                        this.renderContentAnalysis(response.data);
                    } else {
                        this.showError($container, response.message || aceSEODashboard.strings.error);
                    }
                },
                error: () => {
                    this.showError($container, aceSEODashboard.strings.error);
                }
            });
        },
        
        runFullAnalysis: function() {
            const $button = $('.ace-run-full-analysis');
            const $progress = $('#ace-analysis-progress');
            
            $button.prop('disabled', true).text(aceSEODashboard.strings.loading);
            $progress.show();
            
            this.processBatch(1, {
                total_focus_kw: 0,
                total_meta_desc: 0,
                total_seo_title: 0,
                total_optimized: 0,
                total_processed: 0
            });
        },
        
        processBatch: function(batchNumber, cumulativeData) {
            const $progress = $('#ace-analysis-progress');
            const $progressBar = $progress.find('.ace-progress-bar-fill');
            const $progressText = $progress.find('.ace-progress-text');
            
            $.ajax({
                url: aceSEODashboard.ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_load_post_batch',
                    nonce: aceSEODashboard.nonce,
                    batch: batchNumber
                },
                success: (response) => {
                    if (response.status === 'success') {
                        const data = response.data;
                        
                        // Update cumulative data
                        cumulativeData.total_focus_kw += data.has_focus_kw;
                        cumulativeData.total_meta_desc += data.has_meta_desc;
                        cumulativeData.total_seo_title += data.has_seo_title;
                        cumulativeData.total_optimized += data.fully_optimized;
                        cumulativeData.total_processed += data.posts_processed;
                        
                        // Update progress
                        $progressBar.css('width', data.progress_percentage + '%');
                        $progressText.text(
                            aceSEODashboard.strings.processingBatch
                                .replace('%d', data.batch_number)
                                .replace('%d', data.total_batches)
                        );
                        
                        // Continue or finish
                        if (data.has_more) {
                            setTimeout(() => {
                                this.processBatch(batchNumber + 1, cumulativeData);
                            }, 100); // Small delay to prevent server overload
                        } else {
                            this.finishFullAnalysis(cumulativeData);
                        }
                    } else {
                        this.showAnalysisError(response.message);
                    }
                },
                error: () => {
                    this.showAnalysisError(aceSEODashboard.strings.error);
                }
            });
        },
        
        finishFullAnalysis: function(data) {
            const $button = $('.ace-run-full-analysis');
            const $progress = $('#ace-analysis-progress');
            const $results = $('#ace-full-analysis-results');
            
            $button.prop('disabled', false).text('Run Full Analysis Again');
            $progress.hide();
            
            // Calculate percentages
            const focusKwPercentage = Math.round((data.total_focus_kw / data.total_processed) * 100);
            const metaDescPercentage = Math.round((data.total_meta_desc / data.total_processed) * 100);
            const seoTitlePercentage = Math.round((data.total_seo_title / data.total_processed) * 100);
            const fullyOptimizedPercentage = Math.round((data.total_optimized / data.total_processed) * 100);
            
            // Display results
            $results.html(`
                <div class="ace-analysis-results">
                    <h4>✅ ${aceSEODashboard.strings.complete}</h4>
                    <div class="ace-analysis-grid">
                        <div class="ace-analysis-item">
                            <div class="ace-analysis-number">${data.total_focus_kw}</div>
                            <div class="ace-analysis-label">Focus Keywords (${focusKwPercentage}%)</div>
                        </div>
                        <div class="ace-analysis-item">
                            <div class="ace-analysis-number">${data.total_meta_desc}</div>
                            <div class="ace-analysis-label">Meta Descriptions (${metaDescPercentage}%)</div>
                        </div>
                        <div class="ace-analysis-item">
                            <div class="ace-analysis-number">${data.total_seo_title}</div>
                            <div class="ace-analysis-label">SEO Titles (${seoTitlePercentage}%)</div>
                        </div>
                        <div class="ace-analysis-item">
                            <div class="ace-analysis-number">${data.total_optimized}</div>
                            <div class="ace-analysis-label">Fully Optimized (${fullyOptimizedPercentage}%)</div>
                        </div>
                    </div>
                    <p class="ace-analysis-summary">
                        Analyzed ${data.total_processed} posts in total.
                    </p>
                </div>
            `).show();
        },
        
        renderStats: function(data) {
            const $container = $('#ace-seo-stats-container');
            
            const html = `
                <div class="ace-seo-stats">
                    <div class="ace-seo-stat">
                        <div class="ace-seo-stat-number">${data.focus_keywords_count}</div>
                        <div class="ace-seo-stat-label">Posts with Focus Keywords</div>
                    </div>
                    <div class="ace-seo-stat">
                        <div class="ace-seo-stat-number">${data.meta_desc_count}</div>
                        <div class="ace-seo-stat-label">Posts with Meta Descriptions</div>
                    </div>
                    <div class="ace-seo-stat">
                        <div class="ace-seo-stat-number">${data.total_posts}</div>
                        <div class="ace-seo-stat-label">Total Published Content</div>
                    </div>
                </div>
                
                <div class="ace-seo-progress">
                    <p><strong>SEO Optimization Progress:</strong> 
                        <small style="color: #666;">(${data.timestamp})</small>
                        <button type="button" class="button-link ace-refresh-stats">Refresh</button>
                    </p>
                    <div class="ace-seo-progress-item">
                        <span>Focus Keywords: ${data.focus_keyword_percentage}%</span>
                        <div class="ace-seo-progress-bar">
                            <div class="ace-seo-progress-fill" style="width: ${data.focus_keyword_percentage}%;"></div>
                        </div>
                    </div>
                    <div class="ace-seo-progress-item">
                        <span>Meta Descriptions: ${data.meta_desc_percentage}%</span>
                        <div class="ace-seo-progress-bar">
                            <div class="ace-seo-progress-fill" style="width: ${data.meta_desc_percentage}%;"></div>
                        </div>
                    </div>
                </div>
            `;
            
            $container.html(html);
        },
        
        renderRecentActivity: function(posts) {
            const $container = $('#ace-recent-activity-container');
            
            if (posts.length === 0) {
                $container.html('<p>No optimized content found yet. Start optimizing your posts for better SEO!</p>');
                return;
            }
            
            let html = '<div class="ace-seo-recent-posts">';
            
            posts.forEach(post => {
                html += `
                    <div class="ace-seo-recent-post">
                        <div class="ace-seo-recent-post-title">
                            <a href="${post.edit_link}">${post.title}</a>
                            <span class="ace-seo-post-type">${post.type}</span>
                        </div>
                        <div class="ace-seo-recent-post-date">
                            Modified: ${post.modified} ago
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            html += '<button type="button" class="button-link ace-refresh-activity">Refresh Activity</button>';
            
            $container.html(html);
        },
        
        renderContentAnalysis: function(data) {
            const $container = $('#ace-content-analysis-container');
            
            const html = `
                <div class="ace-content-analysis">
                    <div class="ace-analysis-overview">
                        <h4>📋 Content Overview</h4>
                        <div class="ace-content-breakdown">
                            ${Object.entries(data.content_breakdown).map(([type, count]) => 
                                `<span class="ace-content-type">${count} ${type}s</span>`
                            ).join(' • ')}
                        </div>
                    </div>
                    
                    <div class="ace-missing-optimization">
                        <h4>⚠️ Missing Optimization</h4>
                        <ul>
                            <li>${data.missing_focus_keywords} posts missing focus keywords</li>
                            <li>${data.missing_meta_descriptions} posts missing meta descriptions</li>
                            <li>${data.missing_seo_titles} posts missing SEO titles</li>
                        </ul>
                    </div>
                    
                    <button type="button" class="button-link ace-refresh-analysis">Refresh Analysis</button>
                </div>
            `;
            
            $container.html(html);
        },
        
        showFullAnalysisOption: function(totalPosts) {
            const $container = $('#ace-seo-stats-container');
            
            const notice = `
                <div class="notice notice-info ace-seo-tip ace-large-site-notice">
                    <p><strong>Large Site Detected:</strong> You have ${totalPosts} posts. 
                    The quick analysis shows estimates based on the first ${aceSEODashboard.batchSize} posts.</p>
                    <p>
                        <button type="button" class="button button-primary ace-run-full-analysis">
                            Run Full Analysis
                        </button>
                        <span class="description">This will analyze all your content in batches for accurate statistics.</span>
                    </p>
                    
                    <div id="ace-analysis-progress" style="display: none;">
                        <div class="ace-progress-bar">
                            <div class="ace-progress-bar-fill"></div>
                        </div>
                        <p class="ace-progress-text">Starting analysis...</p>
                    </div>
                    
                    <div id="ace-full-analysis-results" style="display: none;"></div>
                </div>
            `;
            
            $container.append(notice);
            
            // Bind full analysis handler
            $(document).on('click', '.ace-run-full-analysis', this.runFullAnalysis.bind(this));
        },
        
        showLoading: function($container, message) {
            $container.html(`
                <div class="ace-loading">
                    <div class="ace-spinner"></div>
                    <p>${message}</p>
                </div>
            `);
        },
        
        showError: function($container, message) {
            $container.html(`
                <div class="ace-error">
                    <p>❌ ${message}</p>
                    <button type="button" class="button" onclick="location.reload();">Reload Page</button>
                </div>
            `);
        },
        
        showAnalysisError: function(message) {
            const $progress = $('#ace-analysis-progress');
            const $button = $('.ace-run-full-analysis');
            
            $progress.hide();
            $button.prop('disabled', false).text('Run Full Analysis');
            
            alert('Analysis Error: ' + message);
        },
        
        refreshStats: function(e) {
            e.preventDefault();
            this.loadDashboardStats();
        },
        
        refreshActivity: function(e) {
            e.preventDefault();
            this.loadRecentActivity();
        },
        
        refreshAnalysis: function(e) {
            e.preventDefault();
            this.loadContentAnalysis();
        },
        
        loadDatabasePerformance: function() {
            const $container = $('#ace-seo-database-container');
            
            if (!$container.length) return;
            
            this.showLoading($container, 'Loading database performance...');
            
            $.ajax({
                url: aceSEODashboard.ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_load_database_performance',
                    nonce: aceSEODashboard.nonce
                },
                success: (response) => {
                    if (response.status === 'success') {
                        $container.html(response.data.html);
                        
                        // Only auto-refresh if optimization is actually running (not just "not started")
                        if (response.data.progress && response.data.progress.percent > 0 && !response.data.progress.completed) {
                            this.showOptimizationProgress($container, response.data.progress);
                            
                            // Poll for progress updates every 2 seconds only when actually running
                            setTimeout(() => {
                                this.loadDatabasePerformance();
                            }, 2000);
                        } else if (response.data.pending && response.data.progress && response.data.progress.message !== 'Not started') {
                            // Only refresh if we have a meaningful progress status
                            this.showOptimizationProgress($container, response.data.progress);
                            
                            setTimeout(() => {
                                this.loadDatabasePerformance();
                            }, 2000);
                        }
                        // If progress is "Not started" or completed, don't auto-refresh
                    } else {
                        this.showError($container, response.message);
                    }
                },
                error: (xhr, status, error) => {
                    this.showError($container, 'Error loading database performance');
                }
            });
        },
        
        refreshDatabase: function(e) {
            e.preventDefault();
            this.loadDatabasePerformance();
        },
        
        showOptimizationProgress: function($container, progress) {
            let progressClass = '';
            if (progress.error) {
                progressClass = 'error';
            } else if (progress.completed) {
                progressClass = 'completed';
            } else {
                progressClass = 'running';
            }
            
            const progressHtml = `
                <div class="ace-optimization-progress ${progressClass}">
                    <div class="ace-progress-header">
                        <h4>🚀 Database Optimization ${progress.completed ? 'Completed' : 'In Progress'}</h4>
                    </div>
                    <div class="ace-progress-bar">
                        <div class="ace-progress-fill" style="width: ${progress.percent}%"></div>
                    </div>
                    <div class="ace-progress-info">
                        <span class="ace-progress-percent">${progress.percent}%</span>
                        <span class="ace-progress-message">${progress.message}</span>
                    </div>
                    ${!progress.completed && !progress.error ? 
                        '<p><small>This process improves database performance and may take a few minutes on large sites.</small></p>' : 
                        ''
                    }
                    <button type="button" class="ace-refresh-database ace-seo-refresh-btn" style="margin-top: 10px;">Refresh Status</button>
                </div>
            `;
            
            $container.html(progressHtml);
        },
        
        startDatabaseOptimization: function(e) {
            e.preventDefault();
            
            const $container = $('#ace-seo-database-container');
            const $button = $('#ace-optimize-database');
            const originalButtonText = $button.text();
            
            // Disable button and show starting state
            $button.prop('disabled', true).text('Starting...');
            
            // Show immediate loading feedback
            this.showOptimizationProgress($container, {
                percent: 0,
                message: 'Starting database optimization...',
                completed: false,
                error: false
            });
            
            $.ajax({
                url: aceSEODashboard.ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_optimize_database',
                    nonce: aceSEODashboard.nonce
                },
                success: (response) => {
                    if (response.success || response.status === 'success') {
                        // Start polling for progress immediately
                        setTimeout(() => {
                            this.loadDatabasePerformance();
                        }, 1000);
                    } else {
                        this.showError($container, response.message || response.data || 'Optimization failed');
                        $button.prop('disabled', false).text(originalButtonText);
                    }
                },
                error: (xhr, status, error) => {
                    this.showError($container, 'Error starting optimization');
                    $button.prop('disabled', false).text(originalButtonText);
                }
            });
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        Dashboard.init();
    });
    
})(jQuery);