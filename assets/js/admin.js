/**
 * Ace SEO Admin JavaScript - Modern Interface with Real-time Analysis
 */

(function($) {
    'use strict';

    const AceSeo = {
        currentModal: null,
        selectedSuggestion: null,
        aiData: {},
        
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initCounters();
            this.initImageSelectors();
            this.initRealTimeAnalysis();
            this.initPageSpeed();
            this.initAiAssistant();
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
            
            // AI Assistant events
            $(document).on('click', '.ace-ai-button', this.handleAiButtonClick.bind(this));
            $(document).on('click', '.ace-modal-close, .ace-modal-overlay', this.closeModal.bind(this));
            $(document).on('click', '.ace-ai-suggestion-item', this.selectSuggestion.bind(this));
            $(document).on('click', '#ace-ai-apply-suggestion', this.applySuggestion.bind(this));
            
            // Content Analysis events
            $('#ace-analyze-content').on('click', this.handleAnalyzeContent.bind(this));
            $('#ace-get-topic-suggestions').on('click', this.handleTopicSuggestions.bind(this));
            $('#ace-improve-content').on('click', this.handleImproveContent.bind(this));
            
            // Comprehensive AI Analysis (sidebar)
            $('#ace-analyze-all-content').on('click', this.handleComprehensiveAnalysis.bind(this));
            
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

        // AI Assistant Functions
        initAiAssistant: function() {
            // Initialize AI functionality if available
            this.currentModal = null;
            this.selectedSuggestion = null;
            this.aiData = {};
        },

        handleAiButtonClick: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const action = $button.data('action');
            
            if ($button.hasClass('loading')) {
                return;
            }
            
            this.setButtonLoading($button, true);
            
            // Get current content data
            const contentData = this.getContentData();
            
            switch (action) {
                case 'generate_titles':
                    this.generateTitles(contentData, $button);
                    break;
                case 'generate_descriptions':
                    this.generateDescriptions(contentData, $button);
                    break;
                case 'generate_keywords':
                    this.generateKeywords(contentData, $button);
                    break;
                case 'analyze_content':
                    this.analyzeContent(contentData, $button);
                    break;
                case 'improve_content':
                    this.improveContent(contentData, $button);
                    break;
                case 'suggest_topics':
                    this.suggestTopics(contentData, $button);
                    break;
                default:
                    this.setButtonLoading($button, false);
                    console.warn('Unknown AI action:', action);
            }
        },

        getContentData: function() {
            const content = this.getContentText();
            const title = $('#yoast_wpseo_title').val() || $('input[name="post_title"]').val() || $('#title').val() || '';
            const focusKeyword = $('#yoast_wpseo_focuskw').val() || '';
            
            return {
                content: content,
                current_title: title,
                focus_keyword: focusKeyword,
                nonce: $('#ace_seo_ai_nonce').val()
            };
        },

        generateTitles: function(contentData, $button) {
            console.log('Generating titles with data:', contentData); // Debug log
            
            $.post(ajaxurl, {
                action: 'ace_seo_generate_titles',
                ...contentData
            })
            .done((response) => {
                console.log('Titles response:', response); // Debug log
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.showTitleSuggestions(response.data.titles);
                } else {
                    this.showAiError(response.data || 'Failed to generate titles');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        generateDescriptions: function(contentData, $button) {
            $.post(ajaxurl, {
                action: 'ace_seo_generate_descriptions',
                ...contentData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.showDescriptionSuggestions(response.data.descriptions);
                } else {
                    this.showAiError(response.data || 'Failed to generate descriptions');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        generateKeywords: function(contentData, $button) {
            $.post(ajaxurl, {
                action: 'ace_seo_generate_keywords',
                ...contentData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.showKeywordSuggestions(response.data.keywords);
                } else {
                    this.showAiError(response.data || 'Failed to generate keywords');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        analyzeContent: function(contentData, $button) {
            // Show loading state in sidebar
            this.showAnalysisLoading(true);
            
            $.post(ajaxurl, {
                action: 'ace_seo_analyze_content',
                ...contentData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                this.showAnalysisLoading(false);
                
                if (response.success) {
                    this.populateContentAnalysis(response.data.analysis);
                } else {
                    this.showAnalysisError(response.data || 'Failed to analyze content');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAnalysisLoading(false);
                this.showAnalysisError('Network error occurred');
            });
        },

        improveContent: function(contentData, $button) {
            // Show loading state in sidebar
            this.showAnalysisLoading(true);
            
            $.post(ajaxurl, {
                action: 'ace_seo_improve_content',
                ...contentData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                this.showAnalysisLoading(false);
                
                if (response.success) {
                    this.populateContentImprovements(response.data.improvements);
                } else {
                    this.showAnalysisError(response.data || 'Failed to get improvement suggestions');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAnalysisLoading(false);
                this.showAnalysisError('Network error occurred');
            });
        },

        suggestTopics: function(contentData, $button) {
            // Show loading state in sidebar
            this.showAnalysisLoading(true);
            
            $.post(ajaxurl, {
                action: 'ace_seo_suggest_topics',
                ...contentData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                this.showAnalysisLoading(false);
                
                if (response.success) {
                    this.populateTopicSuggestions(response.data.suggestions);
                } else {
                    this.showAnalysisError(response.data || 'Failed to generate topic suggestions');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAnalysisLoading(false);
                this.showAnalysisError('Network error occurred');
            });
        },

        showTitleSuggestions: function(titles) {
            console.log('Showing title suggestions:', titles); // Debug log
            
            let html = '<div class="ace-ai-suggestions-list">';
            
            titles.forEach((titleData, index) => {
                // Handle both old format (string) and new format (object)
                const title = typeof titleData === 'string' ? titleData : titleData.title;
                const reason = typeof titleData === 'object' ? titleData.reason : (index === 0 ? 'AI recommended best option' : 'Alternative suggestion');
                const isRecommended = index === 0;
                
                const charCount = title.length;
                const charClass = charCount <= 60 ? 'optimal' : charCount <= 70 ? 'warning' : 'error';
                
                html += `
                    <div class="ace-ai-suggestion-item ${isRecommended ? 'recommended' : ''}" data-suggestion="${this.escapeHtml(title)}" data-type="title">
                        ${isRecommended ? '<div class="ace-ai-recommended-badge">✨ AI Recommended</div>' : ''}
                        <div class="ace-ai-suggestion-text">${this.escapeHtml(title)}</div>
                        <div class="ace-ai-suggestion-reason">${this.escapeHtml(reason)}</div>
                        <div class="ace-ai-suggestion-meta">
                            <span class="ace-ai-char-count ${charClass}">${charCount} characters</span>
                            <span class="ace-ai-score">${charCount <= 60 ? '✓ Optimal' : charCount <= 70 ? '⚠ Long' : '❌ Too long'}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal('AI Title Suggestions', html, 'title');
        },

        showDescriptionSuggestions: function(descriptions) {
            let html = '<div class="ace-ai-suggestions-list">';
            
            descriptions.forEach((descData, index) => {
                // Handle both old format (string) and new format (object)
                const description = typeof descData === 'string' ? descData : descData.description;
                const reason = typeof descData === 'object' ? descData.reason : (index === 0 ? 'AI recommended best option' : 'Alternative suggestion');
                const isRecommended = index === 0;
                
                const charCount = description.length;
                const charClass = charCount >= 120 && charCount <= 160 ? 'optimal' : charCount < 120 ? 'warning' : 'error';
                
                html += `
                    <div class="ace-ai-suggestion-item ${isRecommended ? 'recommended' : ''}" data-suggestion="${this.escapeHtml(description)}" data-type="description">
                        ${isRecommended ? '<div class="ace-ai-recommended-badge">✨ AI Recommended</div>' : ''}
                        <div class="ace-ai-suggestion-text">${this.escapeHtml(description)}</div>
                        <div class="ace-ai-suggestion-reason">${this.escapeHtml(reason)}</div>
                        <div class="ace-ai-suggestion-meta">
                            <span class="ace-ai-char-count ${charClass}">${charCount} characters</span>
                            <span class="ace-ai-score">${charCount >= 120 && charCount <= 160 ? '✓ Optimal' : charCount < 120 ? '⚠ Too short' : '❌ Too long'}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal('AI Meta Description Suggestions', html, 'description');
        },

        showKeywordSuggestions: function(keywords) {
            let html = '<div class="ace-ai-suggestions-list">';
            
            keywords.forEach((keyword, index) => {
                html += `
                    <div class="ace-ai-suggestion-item" data-suggestion="${this.escapeHtml(keyword)}" data-type="keyword">
                        <div class="ace-ai-suggestion-title">Keyword ${index + 1}</div>
                        <p class="ace-ai-suggestion-text">${this.escapeHtml(keyword)}</p>
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal('AI Keyword Suggestions', html, 'keyword');
        },

        showContentAnalysis: function(analysis) {
            let html = '<div class="ace-ai-analysis-results">';
            
            Object.keys(analysis).forEach(category => {
                const categoryTitle = category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const suggestions = Array.isArray(analysis[category]) ? analysis[category] : [analysis[category]];
                
                html += `
                    <div class="ace-ai-analysis-item">
                        <span class="dashicons dashicons-analytics"></span>
                        <div class="ace-ai-analysis-content">
                            <h5>${categoryTitle}</h5>
                            <ul>
                `;
                
                suggestions.forEach(suggestion => {
                    if (suggestion && suggestion.trim()) {
                        html += `<li>${this.escapeHtml(suggestion)}</li>`;
                    }
                });
                
                html += `
                            </ul>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal('AI Content Analysis', html, 'analysis');
        },

        showContentImprovements: function(improvements) {
            let html = '<div class="ace-ai-improvements-list">';
            
            improvements.forEach((improvement, index) => {
                html += `
                    <div class="ace-ai-improvement-item">
                        <span class="dashicons dashicons-lightbulb"></span>
                        <strong>Improvement ${index + 1}:</strong> ${this.escapeHtml(improvement)}
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal('AI Content Improvements', html, 'improvements');
        },

        showTopicSuggestions: function(suggestions) {
            let html = '';
            
            if (suggestions.topics && suggestions.topics.length > 0) {
                html += `
                    <div class="ace-ai-topics-section">
                        <h4><span class="dashicons dashicons-lightbulb"></span> Related Topics</h4>
                        <ul class="ace-ai-topic-list">
                `;
                suggestions.topics.forEach(topic => {
                    html += `<li class="ace-ai-topic-item">${this.escapeHtml(topic)}</li>`;
                });
                html += '</ul></div>';
            }
            
            if (suggestions.questions && suggestions.questions.length > 0) {
                html += `
                    <div class="ace-ai-questions-section">
                        <h4><span class="dashicons dashicons-editor-help"></span> People Also Ask</h4>
                        <ul class="ace-ai-question-list">
                `;
                suggestions.questions.forEach(question => {
                    html += `<li class="ace-ai-question-item">${this.escapeHtml(question)}</li>`;
                });
                html += '</ul></div>';
            }
            
            if (suggestions.keywords && suggestions.keywords.length > 0) {
                html += `
                    <div class="ace-ai-keywords-section">
                        <h4><span class="dashicons dashicons-search"></span> Related Keywords</h4>
                        <ul class="ace-ai-keyword-list">
                `;
                suggestions.keywords.forEach(keyword => {
                    html += `<li class="ace-ai-keyword-item">${this.escapeHtml(keyword)}</li>`;
                });
                html += '</ul></div>';
            }
            
            this.showModal('AI Topic Suggestions', html, 'topics');
        },

        // Sidebar analysis functions
        showAnalysisLoading: function(show) {
            const $loading = $('#ace-analysis-loading');
            const $results = $('#ace-ai-analysis-results');
            const $status = $('#ace-analysis-status');
            
            if (show) {
                $loading.show();
                $results.show();
                $status.hide();
                // Switch to content analysis tab
                this.switchToAnalysisTab();
            } else {
                $loading.hide();
            }
        },

        showAnalysisError: function(message) {
            const $content = $('#ace-analysis-content');
            $content.html(`
                <div class="ace-analysis-error">
                    <span class="dashicons dashicons-warning"></span>
                    <p>${this.escapeHtml(message)}</p>
                </div>
            `);
        },

        switchToAnalysisTab: function() {
            // Switch to the content analysis tab
            $('.ace-seo-tab-item').removeClass('active');
            $('.ace-seo-tab-content').removeClass('active');
            $('[data-tab="content-analysis"]').addClass('active');
            $('#tab-content-analysis').addClass('active');
        },

        populateContentAnalysis: function(analysis) {
            const $content = $('#ace-analysis-content');
            const $results = $('#ace-ai-analysis-results');
            
            let html = '<div class="ace-analysis-categories">';
            
            Object.keys(analysis).forEach(category => {
                const categoryTitle = category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const suggestions = Array.isArray(analysis[category]) ? analysis[category] : [analysis[category]];
                
                html += `
                    <div class="ace-analysis-category">
                        <h5 class="ace-analysis-category-title">
                            <span class="dashicons dashicons-analytics"></span>
                            ${categoryTitle}
                        </h5>
                        <div class="ace-analysis-category-content">
                `;
                
                suggestions.forEach(suggestion => {
                    if (suggestion && suggestion.trim()) {
                        html += `<div class="ace-analysis-suggestion">${this.escapeHtml(suggestion)}</div>`;
                    }
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            $content.html(html);
            $results.show();
            $('#ace-analysis-status').hide();
            
            // Update analysis score indicator
            this.updateAnalysisScore('good');
        },

        populateContentImprovements: function(improvements) {
            const $list = $('#ace-improvements-list');
            const $container = $('#ace-content-improvements');
            
            let html = '';
            
            improvements.forEach((improvement, index) => {
                html += `
                    <div class="ace-improvement-item">
                        <div class="ace-improvement-number">${index + 1}</div>
                        <div class="ace-improvement-content">
                            <span class="dashicons dashicons-lightbulb"></span>
                            ${this.escapeHtml(improvement)}
                        </div>
                    </div>
                `;
            });
            
            $list.html(html);
            $container.show();
            $('#ace-analysis-status').hide();
            
            // Switch to analysis tab and scroll to improvements
            this.switchToAnalysisTab();
        },

        populateTopicSuggestions: function(suggestions) {
            const $content = $('#ace-topic-content');
            const $container = $('#ace-topic-suggestions');
            
            let html = '';
            
            if (suggestions.topics && suggestions.topics.length > 0) {
                html += '<div class="ace-topic-section"><h6>Related Topics</h6><ul>';
                suggestions.topics.forEach(topic => {
                    html += `<li>${this.escapeHtml(topic)}</li>`;
                });
                html += '</ul></div>';
            }
            
            if (suggestions.questions && suggestions.questions.length > 0) {
                html += '<div class="ace-topic-section"><h6>People Also Ask</h6><ul>';
                suggestions.questions.forEach(question => {
                    html += `<li>${this.escapeHtml(question)}</li>`;
                });
                html += '</ul></div>';
            }
            
            if (suggestions.keywords && suggestions.keywords.length > 0) {
                html += '<div class="ace-topic-section"><h6>Long-tail Keywords</h6><ul>';
                suggestions.keywords.forEach(keyword => {
                    html += `<li>${this.escapeHtml(keyword)}</li>`;
                });
                html += '</ul></div>';
            }
            
            $content.html(html);
            $container.show();
            $('#ace-analysis-status').hide();
            
            // Switch to analysis tab and scroll to topics
            this.switchToAnalysisTab();
        },

        updateAnalysisScore: function(rating) {
            const $score = $('#ace-ai-analysis-score');
            const scoreText = rating === 'good' ? '✓' : rating === 'needs-improvement' ? '!' : '—';
            $score.text(scoreText).removeClass('good needs-improvement poor').addClass(rating);
        },

        // Content Analysis Button Handlers
        handleAnalyzeContent: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            
            if ($button.hasClass('loading')) {
                return;
            }
            
            this.setButtonLoading($button, true);
            const contentData = this.getContentData();
            this.analyzeContent(contentData, $button);
        },

        handleTopicSuggestions: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            
            if ($button.hasClass('loading')) {
                return;
            }
            
            this.setButtonLoading($button, true);
            const contentData = this.getContentData();
            this.suggestTopics(contentData, $button);
        },

        handleImproveContent: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            
            if ($button.hasClass('loading')) {
                return;
            }
            
            this.setButtonLoading($button, true);
            const contentData = this.getContentData();
            this.improveContent(contentData, $button);
        },

        handleComprehensiveAnalysis: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            
            if ($button.hasClass('loading')) {
                return;
            }
            
            // Show loading state in sidebar
            this.showSidebarLoading(true);
            this.setButtonLoading($button, true);
            
            const contentData = this.getContentData();
            
            // Perform all three analyses in sequence
            this.performComprehensiveAnalysis(contentData, $button);
        },

        performComprehensiveAnalysis: function(contentData, $button) {
            let analysisResults = {
                contentAnalysis: null,
                topicSuggestions: null,
                contentImprovements: null
            };
            
            let completedRequests = 0;
            const totalRequests = 3;
            
            const checkCompletion = () => {
                completedRequests++;
                if (completedRequests === totalRequests) {
                    this.setButtonLoading($button, false);
                    this.showSidebarLoading(false);
                    this.displayComprehensiveResults(analysisResults);
                }
            };
            
            // 1. Content Analysis
            $.post(ajaxurl, {
                action: 'ace_seo_analyze_content',
                ...contentData
            })
            .done((response) => {
                if (response.success) {
                    analysisResults.contentAnalysis = response.data.analysis;
                }
                checkCompletion();
            })
            .fail(() => {
                checkCompletion();
            });
            
            // 2. Topic Suggestions
            $.post(ajaxurl, {
                action: 'ace_seo_suggest_topics',
                ...contentData
            })
            .done((response) => {
                if (response.success) {
                    analysisResults.topicSuggestions = response.data.suggestions;
                }
                checkCompletion();
            })
            .fail(() => {
                checkCompletion();
            });
            
            // 3. Content Improvements
            $.post(ajaxurl, {
                action: 'ace_seo_improve_content',
                ...contentData
            })
            .done((response) => {
                if (response.success) {
                    analysisResults.contentImprovements = response.data.improvements;
                }
                checkCompletion();
            })
            .fail(() => {
                checkCompletion();
            });
        },

        showSidebarLoading: function(show) {
            const $loading = $('#ace-analysis-loading');
            const $results = $('#ace-analysis-results');
            const $error = $('#ace-analysis-error');
            
            if (show) {
                $loading.show();
                $results.hide();
                $error.hide();
            } else {
                $loading.hide();
            }
        },

        displayComprehensiveResults: function(results) {
            const $results = $('#ace-analysis-results');
            
            // Show results container
            $results.show();
            
            // 1. Display Content Analysis
            if (results.contentAnalysis) {
                this.populateContentAnalysisSidebar(results.contentAnalysis);
                $('#ace-content-analysis-section').show();
            }
            
            // 2. Display Topic Suggestions
            if (results.topicSuggestions) {
                this.populateTopicSuggestionsSidebar(results.topicSuggestions);
                $('#ace-topic-ideas-section').show();
            }
            
            // 3. Display Content Improvements
            if (results.contentImprovements) {
                this.populateContentImprovementsSidebar(results.contentImprovements);
                $('#ace-content-improvements-section').show();
            }
            
            // If all failed, show error
            if (!results.contentAnalysis && !results.topicSuggestions && !results.contentImprovements) {
                this.showSidebarError('All analysis requests failed. Please try again.');
            }
        },

        populateContentAnalysisSidebar: function(analysis) {
            const $content = $('#ace-content-analysis-content');
            let html = '';
            
            Object.keys(analysis).forEach(category => {
                const categoryTitle = category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const suggestions = Array.isArray(analysis[category]) ? analysis[category] : [analysis[category]];
                
                html += `<div style="margin-bottom: 10px;"><strong>${categoryTitle}:</strong>`;
                
                if (suggestions.length > 0) {
                    html += '<ol style="margin: 5px 0 0 15px;">';
                    suggestions.forEach(suggestion => {
                        if (suggestion && suggestion.trim()) {
                            html += `<li style="font-size: 13px; margin-bottom: 3px;">${this.escapeHtml(suggestion)}</li>`;
                        }
                    });
                    html += '</ol>';
                }
                
                html += '</div>';
            });
            
            $content.html(html);
        },

        populateTopicSuggestionsSidebar: function(suggestions) {
            const $content = $('#ace-topic-ideas-content');
            let html = '';
            
            if (suggestions.topics && suggestions.topics.length > 0) {
                html += '<div style="margin-bottom: 10px;"><strong>Related Topics:</strong><ul style="margin: 5px 0 0 15px;">';
                suggestions.topics.forEach(topic => {
                    html += `<li style="font-size: 13px; margin-bottom: 3px;">${this.escapeHtml(topic)}</li>`;
                });
                html += '</ul></div>';
            }
            
            if (suggestions.questions && suggestions.questions.length > 0) {
                html += '<div style="margin-bottom: 10px;"><strong>People Also Ask:</strong><ul style="margin: 5px 0 0 15px;">';
                suggestions.questions.forEach(question => {
                    html += `<li style="font-size: 13px; margin-bottom: 3px;">${this.escapeHtml(question)}</li>`;
                });
                html += '</ul></div>';
            }
            
            if (suggestions.keywords && suggestions.keywords.length > 0) {
                html += '<div style="margin-bottom: 10px;"><strong>Keywords:</strong><ul style="margin: 5px 0 0 15px;">';
                suggestions.keywords.forEach(keyword => {
                    html += `<li style="font-size: 13px; margin-bottom: 3px;">${this.escapeHtml(keyword)}</li>`;
                });
                html += '</ul></div>';
            }
            
            $content.html(html);
        },

        populateContentImprovementsSidebar: function(improvements) {
            const $content = $('#ace-content-improvements-content');
            let html = '<ol style="margin: 0; padding-left: 20px;">';
            
            improvements.forEach((improvement) => {
                html += `<li style="font-size: 13px; margin-bottom: 8px; line-height: 1.4;">${this.escapeHtml(improvement)}</li>`;
            });
            
            html += '</ol>';
            $content.html(html);
        },

        showSidebarError: function(message) {
            $('#ace-analysis-error-message').text(message);
            $('#ace-analysis-error').show();
        },

        showModal: function(title, content, type) {
            console.log('Showing modal:', title, type); // Debug log
            
            const $modal = $('#ace-ai-suggestions-modal');
            console.log('Modal element found:', $modal.length); // Debug log
            
            // Fallback: If modal doesn't exist, use browser prompt for simple selections
            if ($modal.length === 0) {
                console.log('Modal not found, using fallback'); // Debug log
                this.showModalFallback(title, content, type);
                return;
            }
            
            const $title = $('#ace-ai-modal-title');
            const $content = $('#ace-ai-suggestions-content');
            const $applyButton = $('#ace-ai-apply-suggestion');
            
            $title.text(title);
            $content.html(content);
            
            // Hide apply button - clicking suggestions will apply directly
            $applyButton.hide();
            
            $modal.show();
            console.log('Modal shown'); // Debug log
            this.currentModal = type;
            this.selectedSuggestion = null;
        },

        showModalFallback: function(title, content, type) {
            // Extract suggestions from HTML content
            const tempDiv = $('<div>').html(content);
            const suggestions = [];
            
            tempDiv.find('.ace-ai-suggestion-item').each(function() {
                const suggestion = $(this).data('suggestion');
                if (suggestion) {
                    suggestions.push(suggestion);
                }
            });
            
            if (suggestions.length === 0) {
                alert('No suggestions generated. Please try again.');
                return;
            }
            
            // For keywords, auto-apply the first (best) suggestion
            if (type === 'keyword' && suggestions.length > 0) {
                $('#yoast_wpseo_focuskw').val(suggestions[0]).trigger('input');
                this.showSuccessMessage('Best AI keyword applied: ' + suggestions[0]);
                return;
            }
            
            // For titles and descriptions, show selection
            let message = title + '\n\nChoose an option:\n\n';
            suggestions.forEach((suggestion, index) => {
                message += `${index + 1}. ${suggestion}\n\n`;
            });
            message += 'Enter the number of your choice (1-' + suggestions.length + '):';
            
            const choice = prompt(message);
            const choiceIndex = parseInt(choice) - 1;
            
            if (choiceIndex >= 0 && choiceIndex < suggestions.length) {
                const selectedText = suggestions[choiceIndex];
                
                switch (type) {
                    case 'title':
                        $('#yoast_wpseo_title').val(selectedText).trigger('input');
                        break;
                    case 'description':
                        $('#yoast_wpseo_metadesc').val(selectedText).trigger('input');
                        break;
                }
                
                this.showSuccessMessage('AI suggestion applied successfully!');
            }
        },

        closeModal: function(e) {
            if (e.target === e.currentTarget) {
                $('#ace-ai-suggestions-modal').hide();
                this.currentModal = null;
                this.selectedSuggestion = null;
            }
        },

        selectSuggestion: function(e) {
            const $item = $(e.currentTarget);
            const suggestion = $item.data('suggestion');
            const type = $item.data('type');
            
            console.log('Suggestion clicked:', suggestion, type); // Debug log
            
            // Apply suggestion immediately
            switch (type) {
                case 'title':
                    $('#yoast_wpseo_title').val(suggestion).trigger('input');
                    break;
                case 'description':
                    $('#yoast_wpseo_metadesc').val(suggestion).trigger('input');
                    break;
                case 'keyword':
                    $('#yoast_wpseo_focuskw').val(suggestion).trigger('input');
                    break;
            }
            
            // Close modal
            this.closeModal({ target: $('#ace-ai-suggestions-modal')[0], currentTarget: $('#ace-ai-suggestions-modal')[0] });
            
            // Show success message
            this.showSuccessMessage('AI suggestion applied successfully!');
        },

        applySuggestion: function() {
            console.log('Applying suggestion:', this.selectedSuggestion); // Debug log
            
            if (!this.selectedSuggestion) {
                console.log('No suggestion selected'); // Debug log
                return;
            }
            
            const { text, type } = this.selectedSuggestion;
            console.log('Applying:', type, text); // Debug log
            
            switch (type) {
                case 'title':
                    console.log('Setting title field'); // Debug log
                    $('#yoast_wpseo_title').val(text).trigger('input');
                    break;
                case 'description':
                    console.log('Setting description field'); // Debug log
                    $('#yoast_wpseo_metadesc').val(text).trigger('input');
                    break;
                case 'keyword':
                    console.log('Setting keyword field'); // Debug log
                    $('#yoast_wpseo_focuskw').val(text).trigger('input');
                    break;
            }
            
            this.closeModal({ target: $('#ace-ai-suggestions-modal')[0], currentTarget: $('#ace-ai-suggestions-modal')[0] });
            
            // Show success message
            this.showSuccessMessage('AI suggestion applied successfully!');
        },

        setButtonLoading: function($button, loading) {
            if (loading) {
                $button.addClass('loading');
                $button.find('.ace-ai-button-text').hide();
                $button.find('.ace-ai-loading').show();
                $button.prop('disabled', true);
            } else {
                $button.removeClass('loading');
                $button.find('.ace-ai-button-text').show();
                $button.find('.ace-ai-loading').hide();
                $button.prop('disabled', false);
            }
        },

        showAiError: function(message) {
            const $content = $('#ace-ai-suggestions-content');
            $content.html(`
                <div style="text-align: center; padding: 40px 20px;">
                    <span class="dashicons dashicons-warning" style="font-size: 48px; color: #dc3232; margin-bottom: 16px;"></span>
                    <h3 style="color: #dc3232; margin-bottom: 8px;">AI Request Failed</h3>
                    <p style="color: #666;">${this.escapeHtml(message)}</p>
                    <p style="font-size: 12px; color: #999; margin-top: 16px;">
                        Make sure your OpenAI API key is configured correctly in the settings.
                    </p>
                </div>
            `);
            
            this.showModal('AI Error', '', 'error');
        },

        showSuccessMessage: function(message) {
            // Create temporary success notification
            const $notification = $('<div class="notice notice-success is-dismissible" style="position: fixed; top: 32px; right: 20px; z-index: 100001; min-width: 300px;"><p>' + message + '</p></div>');
            $('body').append($notification);
            
            setTimeout(() => {
                $notification.fadeOut(() => $notification.remove());
            }, 3000);
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
