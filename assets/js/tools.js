/**
 * ACE SEO Tools Page JavaScript
 * Handles database optimization progress on the tools page
 */

(function($) {
    'use strict';
    
    const Tools = {
        
        init: function() {
            this.bindEvents();
        },
        
        bindEvents: function() {
            // Database optimization button
            $(document).on('click', '#ace-optimize-db-btn', this.startDatabaseOptimization.bind(this));
        },
        
        startDatabaseOptimization: function(e) {
            e.preventDefault();
            
            const $button = $(e.target);
            const $resultContainer = $('#ace-db-optimization-result');
            
            // Disable button and show loading
            $button.prop('disabled', true).text('Starting Optimization...');
            $resultContainer.show().html(this.getLoadingHtml());
            
            // Start optimization
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_optimize_database',
                    nonce: aceToolsData.nonce
                },
                success: (response) => {
                    if (response.success) {
                        // Start polling for progress
                        this.pollProgress($button, $resultContainer);
                    } else {
                        this.showError($resultContainer, response.data || 'Optimization failed');
                        this.resetButton($button);
                    }
                },
                error: (xhr, status, error) => {
                    this.showError($resultContainer, 'Error starting optimization');
                    this.resetButton($button);
                }
            });
        },
        
        pollProgress: function($button, $resultContainer) {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_get_optimization_progress',
                    nonce: aceToolsData.dashboardNonce
                },
                success: (response) => {
                    if (response.success) {
                        const progress = response.data;
                        this.updateProgress($resultContainer, progress);
                        
                        if (progress.completed) {
                            this.resetButton($button, 'Optimization Complete!');
                            setTimeout(() => {
                                this.resetButton($button);
                            }, 3000);
                        } else if (!progress.error) {
                            // Continue polling
                            setTimeout(() => {
                                this.pollProgress($button, $resultContainer);
                            }, 1000); // Poll every second
                        } else {
                            this.showError($resultContainer, progress.message);
                            this.resetButton($button);
                        }
                    } else {
                        this.showError($resultContainer, 'Error getting progress');
                        this.resetButton($button);
                    }
                },
                error: () => {
                    // Continue polling even on error - might be temporary
                    setTimeout(() => {
                        this.pollProgress($button, $resultContainer);
                    }, 2000);
                }
            });
        },
        
        updateProgress: function($container, progress) {
            let statusClass = '';
            let statusIcon = '🚀';
            
            if (progress.error) {
                statusClass = 'error';
                statusIcon = '❌';
            } else if (progress.completed) {
                statusClass = 'completed';
                statusIcon = '✅';
            }
            
            const progressHtml = `
                <div class="ace-optimization-progress ${statusClass}">
                    <div class="ace-progress-header">
                        <h4>${statusIcon} Database Optimization ${progress.completed ? 'Completed' : 'In Progress'}</h4>
                    </div>
                    <div class="ace-progress-bar">
                        <div class="ace-progress-fill" style="width: ${progress.percent}%"></div>
                    </div>
                    <div class="ace-progress-info">
                        <span class="ace-progress-percent">${progress.percent}%</span>
                        <span class="ace-progress-message">${progress.message}</span>
                    </div>
                    ${!progress.completed && !progress.error ? 
                        '<p><small>Creating database indexes for optimal SEO performance. This may take a few minutes on large sites.</small></p>' : 
                        ''
                    }
                    ${progress.completed ? 
                        '<div class="notice notice-success inline"><p><strong>Success!</strong> Database has been optimized for better performance. You should notice faster dashboard loading and improved SEO query speeds.</p></div>' :
                        ''
                    }
                </div>
            `;
            
            $container.html(progressHtml);
        },
        
        getLoadingHtml: function() {
            return `
                <div class="ace-optimization-progress running">
                    <div class="ace-progress-header">
                        <h4>🚀 Starting Database Optimization</h4>
                    </div>
                    <div class="ace-progress-bar">
                        <div class="ace-progress-fill" style="width: 0%"></div>
                    </div>
                    <div class="ace-progress-info">
                        <span class="ace-progress-percent">0%</span>
                        <span class="ace-progress-message">Initializing...</span>
                    </div>
                </div>
            `;
        },
        
        showError: function($container, message) {
            $container.html(`
                <div class="notice notice-error inline">
                    <p><strong>Error:</strong> ${message}</p>
                </div>
            `);
        },
        
        resetButton: function($button, text = 'Optimize Database Performance') {
            $button.prop('disabled', false).text(text);
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        Tools.init();
    });
    
})(jQuery);