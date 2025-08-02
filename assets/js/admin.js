/**
 * Ace SEO Admin JavaScript - Modern Interface with Real-time Analysis
 */

(function($) {
    'use strict';

    const AceSeo = {
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initCounters();
            this.initImageSelectors();
            this.initRealTimeAnalysis();
            this.initPageSpeed();
            this.updatePreviews();
            
            // Initial analysis
            setTimeout(() => {
                this.performSeoAnalysis();
                this.performReadabilityAnalysis();
            }, 1000);
        },

        bindEvents: function() {
            // Tab switching
            $(document).on('click', '.ace-seo-tab-item', this.switchTab);
            
            // Real-time updates
            $('#yoast_wpseo_title, #yoast_wpseo_metadesc, #yoast_wpseo_focuskw').on('input', this.debounce(this.updateAnalysis.bind(this), 500));
            
            // Preview updates
            $('#yoast_wpseo_title').on('input', this.updateGooglePreview.bind(this));
            $('#yoast_wpseo_metadesc').on('input', this.updateGooglePreview.bind(this));
            
            // Social preview updates
            $('#yoast_wpseo_opengraph-title, #yoast_wpseo_opengraph-description, #yoast_wpseo_opengraph-image').on('input', this.updateFacebookPreview.bind(this));
            $('#yoast_wpseo_twitter-title, #yoast_wpseo_twitter-description, #yoast_wpseo_twitter-image').on('input', this.updateTwitterPreview.bind(this));
            
            // Character counters
            $('#yoast_wpseo_title').on('input', () => this.updateCounter('title', 60));
            $('#yoast_wpseo_metadesc').on('input', () => this.updateCounter('description', 160));
            $('#yoast_wpseo_opengraph-title').on('input', () => this.updateCounter('og-title', 95));
            $('#yoast_wpseo_opengraph-description').on('input', () => this.updateCounter('og-description', 300));
            $('#yoast_wpseo_twitter-title').on('input', () => this.updateCounter('twitter-title', 70));
            $('#yoast_wpseo_twitter-description').on('input', () => this.updateCounter('twitter-description', 200));
            
            // PageSpeed events
            $('#ace-test-performance').on('click', this.testPageSpeed.bind(this));
            $('#ace-simulate-performance').on('click', () => this.testPageSpeed('mobile', true));
            $('#ace-test-mobile').on('click', () => this.testPageSpeed('mobile'));
            $('#ace-test-desktop').on('click', () => this.testPageSpeed('desktop'));
            $('#ace-view-full-report').on('click', this.viewFullReport.bind(this));
        },

        initTabs: function() {
            // Set initial active tab
            $('.ace-seo-tab-item').first().addClass('active');
            $('.ace-seo-tab-content').first().addClass('active');
        },

        switchTab: function(e) {
            e.preventDefault();
            
            const $tab = $(this);
            const tabId = $tab.data('tab');
            
            // Remove active classes
            $('.ace-seo-tab-item').removeClass('active');
            $('.ace-seo-tab-content').removeClass('active');
            
            // Add active classes
            $tab.addClass('active');
            $('#tab-' + tabId).addClass('active');
        },

        initCounters: function() {
            // Initialize all character counters
            this.updateCounter('title', 60);
            this.updateCounter('description', 160);
            this.updateCounter('og-title', 95);
            this.updateCounter('og-description', 300);
            this.updateCounter('twitter-title', 70);
            this.updateCounter('twitter-description', 200);
        },

        updateCounter: function(field, maxLength) {
            let inputId, counterId, progressId;
            
            switch(field) {
                case 'title':
                    inputId = '#yoast_wpseo_title';
                    counterId = '#title-counter';
                    progressId = '#title-progress';
                    break;
                case 'description':
                    inputId = '#yoast_wpseo_metadesc';
                    counterId = '#description-counter';
                    progressId = '#description-progress';
                    break;
                case 'og-title':
                    inputId = '#yoast_wpseo_opengraph-title';
                    counterId = '#og-title-counter';
                    break;
                case 'og-description':
                    inputId = '#yoast_wpseo_opengraph-description';
                    counterId = '#og-description-counter';
                    break;
                case 'twitter-title':
                    inputId = '#yoast_wpseo_twitter-title';
                    counterId = '#twitter-title-counter';
                    break;
                case 'twitter-description':
                    inputId = '#yoast_wpseo_twitter-description';
                    counterId = '#twitter-description-counter';
                    break;
            }
            
            const $input = $(inputId);
            const $counter = $(counterId);
            const $progress = $(progressId);
            
            if (!$input.length || !$counter.length) return;
            
            const currentLength = $input.val().length;
            const percentage = (currentLength / maxLength) * 100;
            
            // Update counter text
            $counter.text(currentLength + ' / ' + maxLength);
            
            // Update counter color
            $counter.removeClass('warning error');
            if (currentLength > maxLength) {
                $counter.addClass('error');
            } else if (currentLength > maxLength * 0.9) {
                $counter.addClass('warning');
            }
            
            // Update progress bar if exists
            if ($progress.length) {
                $progress.css('width', Math.min(percentage, 100) + '%');
                $progress.removeClass('warning error');
                
                if (currentLength > maxLength) {
                    $progress.addClass('error');
                } else if (currentLength > maxLength * 0.9) {
                    $progress.addClass('warning');
                }
            }
        },

        initImageSelectors: function() {
            $('.ace-seo-image-button').on('click', function(e) {
                e.preventDefault();
                
                const targetInput = $(this).data('target');
                const $target = $('#' + targetInput);
                
                if (typeof wp !== 'undefined' && wp.media) {
                    const mediaUploader = wp.media({
                        title: 'Select Image',
                        button: {
                            text: 'Use this image'
                        },
                        multiple: false,
                        library: {
                            type: 'image'
                        }
                    });
                    
                    mediaUploader.on('select', function() {
                        const attachment = mediaUploader.state().get('selection').first().toJSON();
                        $target.val(attachment.url).trigger('input');
                    });
                    
                    mediaUploader.open();
                }
            });
        },

        initRealTimeAnalysis: function() {
            // Listen for content changes in the editor
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                wp.data.subscribe(() => {
                    this.debounce(this.updateAnalysis.bind(this), 1000)();
                });
            }
            
            // Listen for classic editor changes
            if (typeof tinymce !== 'undefined') {
                $(document).on('tinymce-editor-init', (event, editor) => {
                    editor.on('input keyup', this.debounce(this.updateAnalysis.bind(this), 1000));
                });
            }
        },

        updateAnalysis: function() {
            this.performSeoAnalysis();
            this.performReadabilityAnalysis();
            this.updatePreviews();
        },

        performSeoAnalysis: function() {
            const postId = aceSeoAdmin.postId;
            
            if (!postId) return;
            
            $('.ace-seo-analysis-loading').show();
            $('.ace-seo-analysis-results').hide();
            
            $.ajax({
                url: aceSeoAdmin.restUrl + 'analyze/' + postId,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', aceSeoAdmin.nonce);
                },
                success: (data) => {
                    this.displaySeoResults(data);
                },
                error: (xhr, status, error) => {
                    console.error('SEO Analysis Error:', error);
                    this.displaySeoError();
                }
            });
        },

        performReadabilityAnalysis: function() {
            const postId = aceSeoAdmin.postId;
            
            if (!postId) return;
            
            $('#ace-readability-analysis .ace-seo-analysis-loading').show();
            $('#ace-readability-analysis-results').hide();
            
            // Simulate readability analysis (you can replace with actual API call)
            setTimeout(() => {
                const results = this.analyzeReadability();
                this.displayReadabilityResults(results);
            }, 1500);
        },

        analyzeReadability: function() {
            // Simple readability analysis
            const content = this.getContentText();
            const sentences = content.split(/[.!?]+/).filter(s => s.trim().length > 0);
            const words = content.split(/\s+/).filter(w => w.trim().length > 0);
            const paragraphs = content.split(/\n\s*\n/).filter(p => p.trim().length > 0);
            
            const results = {
                score: 70,
                recommendations: []
            };
            
            // Check sentence length
            if (sentences.length > 0) {
                const avgWordsPerSentence = words.length / sentences.length;
                if (avgWordsPerSentence > 25) {
                    results.recommendations.push({
                        type: 'warning',
                        text: `Average sentence length is ${Math.round(avgWordsPerSentence)} words. Try to keep sentences under 20 words.`
                    });
                    results.score -= 10;
                } else if (avgWordsPerSentence > 20) {
                    results.recommendations.push({
                        type: 'warning',
                        text: 'Some sentences are quite long. Consider breaking them up for better readability.'
                    });
                    results.score -= 5;
                } else {
                    results.recommendations.push({
                        type: 'good',
                        text: 'Good sentence length! Most sentences are easy to read.'
                    });
                }
            }
            
            // Check paragraph length
            const longParagraphs = paragraphs.filter(p => p.split(/\s+/).length > 150);
            if (longParagraphs.length > 0) {
                results.recommendations.push({
                    type: 'warning',
                    text: 'Some paragraphs are quite long. Consider breaking them up with subheadings.'
                });
                results.score -= 10;
            }
            
            // Check subheadings
            const hasSubheadings = /<h[2-6]/.test(content);
            if (!hasSubheadings && words.length > 300) {
                results.recommendations.push({
                    type: 'warning',
                    text: 'Consider adding subheadings to break up your content.'
                });
                results.score -= 10;
            } else if (hasSubheadings) {
                results.recommendations.push({
                    type: 'good',
                    text: 'Good use of subheadings to structure your content!'
                });
            }
            
            // Check passive voice (simple detection)
            const passiveIndicators = /\b(is|are|was|were|been|being)\s+\w+ed\b/gi;
            const passiveMatches = content.match(passiveIndicators) || [];
            const passivePercentage = (passiveMatches.length / sentences.length) * 100;
            
            if (passivePercentage > 25) {
                results.recommendations.push({
                    type: 'warning',
                    text: 'Try to use more active voice for clearer, more engaging writing.'
                });
                results.score -= 5;
            }
            
            return results;
        },

        displaySeoResults: function(data) {
            $('.ace-seo-analysis-loading').hide();
            
            // Update score in tab
            const scoreElement = $('#ace-seo-score');
            scoreElement.text(data.seo_score + '%');
            scoreElement.removeClass('good ok bad');
            
            if (data.seo_score >= 80) {
                scoreElement.addClass('good');
            } else if (data.seo_score >= 60) {
                scoreElement.addClass('ok');
            } else {
                scoreElement.addClass('bad');
            }
            
            // Display recommendations
            const $results = $('#ace-seo-analysis-results');
            $results.empty();
            
            if (data.recommendations && data.recommendations.length > 0) {
                data.recommendations.forEach(rec => {
                    const icon = rec.type === 'good' ? '✓' : rec.type === 'warning' ? '⚠' : '✗';
                    $results.append(`
                        <div class="ace-seo-analysis-item ${rec.type}">
                            <span class="ace-seo-analysis-icon">${icon}</span>
                            <span>${rec.text}</span>
                        </div>
                    `);
                });
            } else {
                $results.append('<div class="ace-seo-analysis-item good"><span class="ace-seo-analysis-icon">✓</span><span>No issues found!</span></div>');
            }
            
            $results.show();
            
            // Update hidden score field
            $('#yoast_wpseo_linkdex').val(data.seo_score);
        },

        displayReadabilityResults: function(data) {
            $('#ace-readability-analysis .ace-seo-analysis-loading').hide();
            
            // Update score in tab
            const scoreElement = $('#ace-readability-score');
            scoreElement.text(data.score + '%');
            scoreElement.removeClass('good ok bad');
            
            if (data.score >= 80) {
                scoreElement.addClass('good');
            } else if (data.score >= 60) {
                scoreElement.addClass('ok');
            } else {
                scoreElement.addClass('bad');
            }
            
            // Display recommendations
            const $results = $('#ace-readability-analysis-results');
            $results.empty();
            
            if (data.recommendations && data.recommendations.length > 0) {
                data.recommendations.forEach(rec => {
                    const icon = rec.type === 'good' ? '✓' : rec.type === 'warning' ? '⚠' : '✗';
                    $results.append(`
                        <div class="ace-seo-analysis-item ${rec.type}">
                            <span class="ace-seo-analysis-icon">${icon}</span>
                            <span>${rec.text}</span>
                        </div>
                    `);
                });
            } else {
                $results.append('<div class="ace-seo-analysis-item good"><span class="ace-seo-analysis-icon">✓</span><span>Content is highly readable!</span></div>');
            }
            
            $results.show();
            
            // Update hidden score field
            $('#yoast_wpseo_content_score').val(data.score);
        },

        displaySeoError: function() {
            $('.ace-seo-analysis-loading').hide();
            $('#ace-seo-analysis-results').html(
                '<div class="ace-seo-analysis-item error"><span class="ace-seo-analysis-icon">✗</span><span>Unable to analyze content. Please try again.</span></div>'
            ).show();
        },

        updatePreviews: function() {
            this.updateGooglePreview();
            this.updateFacebookPreview();
            this.updateTwitterPreview();
        },

        updateGooglePreview: function() {
            const title = $('#yoast_wpseo_title').val() || $('#title').val() || 'Untitled';
            const description = $('#yoast_wpseo_metadesc').val() || this.getExcerpt();
            
            $('#preview-title').text(title);
            $('#preview-description').text(description);
        },

        updateFacebookPreview: function() {
            const title = $('#yoast_wpseo_opengraph-title').val() || $('#yoast_wpseo_title').val() || $('#title').val() || 'Untitled';
            const description = $('#yoast_wpseo_opengraph-description').val() || $('#yoast_wpseo_metadesc').val() || this.getExcerpt();
            const image = $('#yoast_wpseo_opengraph-image').val();
            
            $('#facebook-preview-title').text(title);
            $('#facebook-preview-description').text(description);
            
            const $imageContainer = $('#facebook-preview-image');
            if (image) {
                $imageContainer.html(`<img src="${image}" alt="Facebook preview">`);
            } else {
                $imageContainer.html('<div class="ace-seo-placeholder-image">📷</div>');
            }
        },

        updateTwitterPreview: function() {
            const title = $('#yoast_wpseo_twitter-title').val() || $('#yoast_wpseo_opengraph-title').val() || $('#yoast_wpseo_title').val() || $('#title').val() || 'Untitled';
            const description = $('#yoast_wpseo_twitter-description').val() || $('#yoast_wpseo_opengraph-description').val() || $('#yoast_wpseo_metadesc').val() || this.getExcerpt();
            const image = $('#yoast_wpseo_twitter-image').val() || $('#yoast_wpseo_opengraph-image').val();
            
            $('#twitter-preview-title').text(title);
            $('#twitter-preview-description').text(description);
            
            const $imageContainer = $('#twitter-preview-image');
            if (image) {
                $imageContainer.html(`<img src="${image}" alt="Twitter preview">`);
            } else {
                $imageContainer.html('<div class="ace-seo-placeholder-image">📷</div>');
            }
        },

        getContentText: function() {
            // Try to get content from Gutenberg editor
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
                const content = wp.data.select('core/editor').getEditedPostContent();
                if (content) {
                    return content.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                }
            }
            
            // Try to get content from classic editor
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                const content = tinymce.get('content').getContent();
                if (content) {
                    return content.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                }
            }
            
            // Fallback to textarea
            const content = $('#content').val();
            if (content) {
                return content.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            }
            
            return '';
        },

        getExcerpt: function() {
            const content = this.getContentText();
            const words = content.split(/\s+/).slice(0, 25);
            return words.join(' ') + (words.length === 25 ? '...' : '');
        },

        // PageSpeed functionality
        initPageSpeed: function() {
            this.loadExistingPageSpeedData();
        },

        loadExistingPageSpeedData: function() {
            const postId = aceSeoAdmin.postId;
            if (!postId) return;

            $.ajax({
                url: aceSeoAdmin.restUrl + 'performance/' + postId,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', aceSeoAdmin.nonce);
                },
                success: (response) => {
                    this.displayPageSpeedData(response);
                },
                error: (xhr) => {
                    if (xhr.status === 404) {
                        this.showNoDataMessage();
                    }
                }
            });
        },

        testPageSpeed: function(strategy = 'mobile', simulate = false) {
            const $button = simulate ? $('#ace-simulate-performance') : $('#ace-test-performance');
            const $status = $('#ace-performance-status');
            
            // Show loading state
            $button.prop('disabled', true);
            if (simulate) {
                $button.text('Generating...');
                $status.find('.ace-performance-text').text('Generating sample performance data...');
            } else {
                $button.text('Testing...');
                $status.find('.ace-performance-text').text('Running PageSpeed test...');
            }
            
            const currentUrl = window.location.origin + '/' + $('#post_name').val() || window.location.href;
            
            $.ajax({
                url: aceSeoAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'ace_seo_test_pagespeed',
                    url: currentUrl,
                    strategy: strategy,
                    simulate: simulate,
                    nonce: aceSeoAdmin.performanceNonce
                },
                success: (response) => {
                    if (response.success) {
                        this.displayPageSpeedData(response.data);
                        this.updatePerformanceScore(response.data.performance_score);
                        
                        if (response.data.is_simulated) {
                            this.showSimulationNotice(response.data.simulation_note);
                        }
                    } else {
                        if (response.data && response.data.is_local) {
                            this.showLocalDevelopmentMessage(response.data);
                        } else {
                            this.showErrorMessage(response.data.message || 'Test failed');
                        }
                    }
                },
                error: () => {
                    this.showErrorMessage('Network error during test');
                },
                complete: () => {
                    $button.prop('disabled', false);
                    if (simulate) {
                        $button.text('📊 Simulate Data');
                    } else {
                        $button.text('Test Performance');
                    }
                }
            });
        },

        displayPageSpeedData: function(data) {
            const $results = $('#ace-performance-results');
            const $status = $('#ace-performance-status');
            
            // Hide status, show results
            $status.hide();
            $results.show();
            
            // Update scores
            $('#performance-score').text(data.performance_score + '%').attr('class', 'ace-score-value ' + this.getScoreClass(data.performance_score));
            $('#accessibility-score').text(data.accessibility_score + '%').attr('class', 'ace-score-value ' + this.getScoreClass(data.accessibility_score));
            $('#best-practices-score').text(data.best_practices_score + '%').attr('class', 'ace-score-value ' + this.getScoreClass(data.best_practices_score));
            $('#seo-score').text(data.seo_score + '%').attr('class', 'ace-score-value ' + this.getScoreClass(data.seo_score));
            
            // Update Core Web Vitals
            if (data.core_web_vitals) {
                const cwv = data.core_web_vitals;
                
                if (cwv.lcp) {
                    $('#lcp-value').text(cwv.lcp.displayValue);
                    $('#lcp-rating').text(cwv.lcp.rating).attr('class', 'ace-cwv-rating rating-' + cwv.lcp.rating);
                }
                
                if (cwv.fid) {
                    $('#fid-value').text(cwv.fid.displayValue);
                    $('#fid-rating').text(cwv.fid.rating).attr('class', 'ace-cwv-rating rating-' + cwv.fid.rating);
                }
                
                if (cwv.cls) {
                    $('#cls-value').text(cwv.cls.displayValue);
                    $('#cls-rating').text(cwv.cls.rating).attr('class', 'ace-cwv-rating rating-' + cwv.cls.rating);
                }
            }
            
            // Update recommendations
            this.displayRecommendations(data.opportunities || []);
            
            // Update tab score
            this.updatePerformanceScore(data.performance_score);
        },

        displayRecommendations: function(opportunities) {
            const $list = $('#performance-recommendations-list');
            $list.empty();
            
            if (opportunities.length === 0) {
                $list.append('<p>No specific recommendations available.</p>');
                return;
            }
            
            opportunities.forEach(opportunity => {
                const savings = opportunity.savings_ms > 1000 
                    ? Math.round(opportunity.savings_ms / 1000 * 10) / 10 + 's'
                    : opportunity.savings_ms + 'ms';
                    
                $list.append(`
                    <div class="ace-recommendation-item">
                        <h6>${opportunity.title}</h6>
                        <p>${opportunity.description}</p>
                        <span class="ace-savings">Potential savings: ${savings}</span>
                    </div>
                `);
            });
        },

        updatePerformanceScore: function(score) {
            const $scoreElement = $('#ace-performance-score');
            const scoreClass = this.getScoreClass(score);
            
            $scoreElement.text(score + '%').attr('class', 'ace-seo-tab-score ' + scoreClass);
        },

        getScoreClass: function(score) {
            if (score >= 90) return 'excellent';
            if (score >= 70) return 'good';
            if (score >= 50) return 'ok';
            if (score >= 30) return 'poor';
            return 'bad';
        },

        showNoDataMessage: function() {
            const $status = $('#ace-performance-status');
            $status.find('.ace-performance-text').text('No performance data available. Click "Test Performance" to analyze this page.');
        },

        showErrorMessage: function(message) {
            const $status = $('#ace-performance-status');
            $status.find('.ace-performance-text').text('Error: ' + message);
        },

        showLocalDevelopmentMessage: function(data) {
            const $status = $('#ace-performance-status');
            const $results = $('#ace-performance-results');
            
            // Hide results, show status with helpful message
            $results.hide();
            $status.show();
            
            let message = '<div class="ace-local-dev-notice">';
            message += '<h4>🔧 Local Development Detected</h4>';
            message += '<p>PageSpeed Insights requires a publicly accessible URL. Here are your options:</p>';
            message += '<ul>';
            
            if (data.suggestions) {
                data.suggestions.forEach(suggestion => {
                    message += '<li>' + suggestion + '</li>';
                });
            }
            
            message += '</ul>';
            message += '<div class="ace-local-dev-actions">';
            message += '<button type="button" class="button" onclick="aceShowNgrokInstructions()">📋 Setup ngrok Instructions</button>';
            message += '<button type="button" class="button" onclick="aceShowLighthouseInstructions()">🚀 Use Lighthouse Locally</button>';
            message += '</div>';
            message += '</div>';
            
            $status.html(message);
        },

        showSimulationNotice: function(note) {
            const $results = $('#ace-performance-results');
            
            // Add simulation notice to results
            const notice = '<div class="ace-simulation-notice">' +
                          '<p><strong>ℹ️ Simulated Data:</strong> ' + note + '</p>' +
                          '</div>';
            
            $results.prepend(notice);
        },

        viewFullReport: function() {
            const currentUrl = window.location.origin + '/' + $('#post_name').val() || window.location.href;
            const pagespeedUrl = `https://pagespeed.web.dev/analysis?url=${encodeURIComponent(currentUrl)}`;
            window.open(pagespeedUrl, '_blank');
        },

        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

    // Global functions for local development help
    window.aceShowNgrokInstructions = function() {
        const instructions = `
🔧 Expose Local Site with ngrok:

1. Install ngrok: https://ngrok.com/download
2. Run your local server on port 80 or 443
3. In terminal: ngrok http 80
4. Copy the public URL (e.g., https://abc123.ngrok.io)
5. Test that URL in PageSpeed Insights
6. Use that URL for testing in this plugin

💡 Tip: ngrok free tier has limitations, but works great for testing!
        `;
        
        alert(instructions);
    };

    window.aceShowLighthouseInstructions = function() {
        const instructions = `
🚀 Use Lighthouse Locally:

1. Open Chrome DevTools (F12)
2. Go to "Lighthouse" tab
3. Select "Performance" audit
4. Click "Generate report"
5. Get Core Web Vitals data instantly!

Alternative - Lighthouse CLI:
1. npm install -g lighthouse
2. lighthouse http://localhost:3000 --output html
3. Open the generated HTML report

💡 This gives you the same metrics as PageSpeed Insights!
        `;
        
        alert(instructions);
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        // Only initialize on post edit pages
        if ($('#ace-seo-metabox').length > 0) {
            AceSeo.init();
        }
    });

    // Make AceSeo globally available for debugging
    window.AceSeo = AceSeo;

})(jQuery);
