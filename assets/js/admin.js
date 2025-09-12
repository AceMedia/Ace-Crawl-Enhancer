/**
 * Ace SEO Admin JavaScript - Modern Interface with Real-time Analysis
 */

(function($) {
    'use strict';

    const AceSeo = {
        currentModal: null,
        selectedSuggestion: null,
        aiData: {},
        aiCache: {}, // Cache for AI responses
        
        init: function() {
            this.loadAiCache(); // Load cached responses
            this.bindEvents();
            this.initTabs();
            this.initCounters();
            this.initImageSelectors();
            this.initRealTimeAnalysis();
            this.initPageSpeed();
            this.initAiAssistant();
            this.initSocialDefaults();
            this.updatePreviews();
            
            // Initial analysis
            setTimeout(() => {
                this.performClientSideAnalysis(); // Use client-side for immediate feedback
                this.performReadabilityAnalysis();
            }, 1000);
        },

        // Cache management functions
        loadAiCache: function() {
            try {
                const cached = localStorage.getItem('ace_seo_ai_cache_' + (aceSeoAdmin.postId || 'new'));
                if (cached) {
                    this.aiCache = JSON.parse(cached);
                }
            } catch (e) {
                // Could not load AI cache
                this.aiCache = {};
            }
        },

        saveAiCache: function() {
            try {
                localStorage.setItem('ace_seo_ai_cache_' + (aceSeoAdmin.postId || 'new'), JSON.stringify(this.aiCache));
            } catch (e) {
                // Could not save AI cache
            }
        },

        getCacheKey: function(type, content, extraParams = {}) {
            // Create a simple hash of the content and parameters
            const dataString = type + content + JSON.stringify(extraParams);
            let hash = 0;
            for (let i = 0; i < dataString.length; i++) {
                const char = dataString.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash; // Convert to 32-bit integer
            }
            return type + '_' + Math.abs(hash);
        },

        getCachedResponse: function(cacheKey) {
            const cached = this.aiCache[cacheKey];
            if (cached && (Date.now() - cached.timestamp) < 3600000) { // 1 hour cache
                return cached.data;
            }
            return null;
        },

        setCachedResponse: function(cacheKey, data) {
            this.aiCache[cacheKey] = {
                data: data,
                timestamp: Date.now()
            };
            this.saveAiCache();
        },

        invalidateImageCaches: function(fieldType) {
            // Invalidate all caches related to the field type when an image is actually set
            const platform = fieldType.replace('_image', ''); // 'facebook' or 'twitter'
            
            // Clear cache for this platform's image generation
            Object.keys(this.aiCache).forEach(key => {
                if (key.includes(`${platform}_image`) || key.includes(`${platform}_more_images`)) {
                    delete this.aiCache[key];
                }
            });
            
            this.saveAiCache();
            // Invalidated image caches due to manual image selection
        },
        
        init: function() {
            // Load cached AI responses from localStorage
            this.loadAiCache();
            
            this.bindEvents();
            this.initTabs();
            this.initCounters();
            this.initImageSelectors();
            this.initRealTimeAnalysis();
            this.initPageSpeed();
            this.initAiAssistant();
            this.initSocialDefaults();
            this.updatePreviews();
            
            // Initial analysis
            setTimeout(() => {
                this.performClientSideAnalysis(); // Use client-side for immediate feedback
                this.performReadabilityAnalysis();
            }, 1000);
        },

        bindEvents: function() {
            // Tab switching
            $(document).on('click', '.ace-seo-tab-item', this.switchTab);
            
            // Real-time SEO analysis - only for title and meta description with longer debounce
            $('#yoast_wpseo_title, #yoast_wpseo_metadesc').on('input', this.debounce(this.updateSeoAnalysisOnly.bind(this), 1500));
            
            // Also trigger analysis on focus to ensure it runs
            $('#yoast_wpseo_title, #yoast_wpseo_metadesc').on('focus', this.updateSeoAnalysisOnly.bind(this));
            
            // Force analysis on page ready
            $(document).ready(() => {
                setTimeout(() => {
                    this.performClientSideAnalysis();
                }, 500);
            });
            
            // Preview updates - these should remain responsive
            $('#yoast_wpseo_title').on('input', this.updateGooglePreview.bind(this));
            $('#yoast_wpseo_metadesc').on('input', this.updateGooglePreview.bind(this));
            
            // Listen for post title changes to update previews and counters
            $('#title, .editor-post-title__input, input[name="post_title"]').on('input', this.debounce(() => {
                this.updateGooglePreview();
                this.updateFacebookPreview();
                this.updateTwitterPreview();
                // Update counters since title template might change
                this.updateCounter('title', 60);
            }, 300));
            
            // Listen for content changes to update meta description placeholder and counter
            $(document).on('input', '#content, .editor-rich-text__tinymce, .wp-block-post-content', this.debounce(() => {
                this.updateMetaDescriptionPlaceholder();
                // Update counter since meta description placeholder might change
                this.updateCounter('description', 160);
            }, 500));
            
            // Social preview updates
            $('#yoast_wpseo_opengraph-title, #yoast_wpseo_opengraph-description, #yoast_wpseo_opengraph-image').on('input', this.updateFacebookPreview.bind(this));
            $('#yoast_wpseo_twitter-title, #yoast_wpseo_twitter-description, #yoast_wpseo_twitter-image').on('input', this.updateTwitterPreview.bind(this));
            
            // Cache invalidation when image fields are manually changed
            $('#yoast_wpseo_opengraph-image').on('input change', this.debounce((e) => {
                if ($(e.target).val().trim()) {
                    this.invalidateImageCaches('facebook_image');
                }
            }, 1000));
            
            $('#yoast_wpseo_twitter-image').on('input change', this.debounce((e) => {
                if ($(e.target).val().trim()) {
                    this.invalidateImageCaches('twitter_image');
                }
            }, 1000));
            
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
            
            let currentLength = $input.val().length;
            let displayText = currentLength + ' / ' + maxLength;
            
            // For main SEO title and description, show effective length if field is empty
            if (field === 'title' && currentLength === 0) {
                const postTitle = $('#title').val() || $('.editor-post-title__input').val() || $('input[name="post_title"]').val() || aceSeoAdmin.postTitle || 'Untitled';
                
                if (aceSeoAdmin.titleTemplate) {
                    const effectiveTitle = this.processTemplate(aceSeoAdmin.titleTemplate, {
                        title: postTitle,
                        site_name: aceSeoAdmin.siteName,
                        sep: aceSeoAdmin.separator,
                        excerpt: this.getExcerpt()
                    });
                    currentLength = effectiveTitle.length;
                    displayText = '0 (' + currentLength + ') / ' + maxLength;
                } else {
                    currentLength = postTitle.length;
                    displayText = '0 (' + currentLength + ') / ' + maxLength;
                }
            } else if (field === 'description' && currentLength === 0) {
                const excerpt = this.getExcerpt();
                let effectiveDesc = '';
                
                if (excerpt) {
                    effectiveDesc = excerpt;
                } else if (aceSeoAdmin.postContent) {
                    effectiveDesc = aceSeoAdmin.postContent + '...';
                }
                
                if (effectiveDesc) {
                    currentLength = effectiveDesc.length;
                    displayText = '0 (' + currentLength + ') / ' + maxLength;
                }
            }
            
            const percentage = (currentLength / maxLength) * 100;
            
            // Update counter text
            $counter.text(displayText);
            
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

        // New method for SEO-only updates (no readability analysis)
        updateSeoAnalysisOnly: function() {
            // Always use client-side analysis for real-time updates
            this.performClientSideAnalysis();
            this.updatePreviews();
        },

        performSeoAnalysis: function() {
            // Always use client-side analysis for consistency
            // performSeoAnalysis called - using client-side analysis
            this.performClientSideAnalysis();
        },

        performClientSideAnalysis: function() {
            $('.ace-seo-analysis-loading').hide();
            
            // Get actual field values
            const titleFieldValue = $('#yoast_wpseo_title').val() || '';
            const metaDescFieldValue = $('#yoast_wpseo_metadesc').val() || '';
            
            // Calculate effective title (what would actually be displayed)
            let effectiveTitle = titleFieldValue;
            if (!titleFieldValue) {
                // Get post title from multiple sources
                const postTitle = $('#title').val() || $('.editor-post-title__input').val() || $('input[name="post_title"]').val() || aceSeoAdmin.postTitle || 'Untitled';
                
                // Use template system if available
                if (aceSeoAdmin.titleTemplate) {
                    effectiveTitle = this.processTemplate(aceSeoAdmin.titleTemplate, {
                        title: postTitle,
                        site_name: aceSeoAdmin.siteName,
                        sep: aceSeoAdmin.separator,
                        excerpt: this.getExcerpt()
                    });
                } else {
                    effectiveTitle = postTitle;
                }
            }
            
            // Calculate effective meta description (what would actually be displayed)
            let effectiveMetaDesc = metaDescFieldValue;
            if (!metaDescFieldValue) {
                // Use excerpt/content as placeholder
                const excerpt = this.getExcerpt();
                if (excerpt) {
                    effectiveMetaDesc = excerpt;
                } else if (aceSeoAdmin.postContent) {
                    effectiveMetaDesc = aceSeoAdmin.postContent + '...';
                } else {
                    effectiveMetaDesc = '';
                }
            }
            
            // Client-side analysis - Title/Meta fields
            
            const recommendations = [];
            let score = 100;
            
            // Title analysis - use effective title for length calculations
            if (!titleFieldValue && effectiveTitle.length === 0) {
                recommendations.push({
                    type: 'warning',
                    text: 'SEO title is empty. Add a title to improve your SEO.'
                });
                score -= 20;
            } else if (effectiveTitle.length > 60) {
                if (titleFieldValue) {
                    recommendations.push({
                        type: 'warning',
                        text: 'SEO title is too long. Keep it under 60 characters.'
                    });
                } else {
                    recommendations.push({
                        type: 'warning',
                        text: 'Generated SEO title is too long (' + effectiveTitle.length + ' chars). Consider setting a custom title.'
                    });
                }
                score -= 10;
            } else if (effectiveTitle.length < 30) {
                if (titleFieldValue) {
                    recommendations.push({
                        type: 'warning',
                        text: 'SEO title is quite short. Consider adding more descriptive words.'
                    });
                } else {
                    recommendations.push({
                        type: 'warning',
                        text: 'Generated SEO title is short (' + effectiveTitle.length + ' chars). Consider setting a custom title.'
                    });
                }
                score -= 5;
            } else {
                if (titleFieldValue) {
                    recommendations.push({
                        type: 'good',
                        text: 'SEO title length is good.'
                    });
                } else {
                    recommendations.push({
                        type: 'good',
                        text: 'Generated SEO title length is good (' + effectiveTitle.length + ' chars).'
                    });
                }
            }
            
            // Meta description analysis - use effective meta description for length calculations
            if (!metaDescFieldValue && effectiveMetaDesc.length === 0) {
                recommendations.push({
                    type: 'warning',
                    text: 'Meta description is empty. Add a description to improve your SEO.'
                });
                score -= 15;
            } else if (effectiveMetaDesc.length > 160) {
                if (metaDescFieldValue) {
                    recommendations.push({
                        type: 'warning',
                        text: 'Meta description is too long. Keep it under 160 characters.'
                    });
                } else {
                    recommendations.push({
                        type: 'warning',
                        text: 'Generated meta description is too long (' + effectiveMetaDesc.length + ' chars). Consider setting a custom description.'
                    });
                }
                score -= 10;
            } else if (effectiveMetaDesc.length < 120) {
                if (metaDescFieldValue) {
                    recommendations.push({
                        type: 'warning',
                        text: 'Meta description is quite short. Consider adding more details.'
                    });
                } else {
                    recommendations.push({
                        type: 'warning',
                        text: 'Generated meta description is short (' + effectiveMetaDesc.length + ' chars). Consider setting a custom description.'
                    });
                }
                score -= 5;
            } else {
                if (metaDescFieldValue) {
                    recommendations.push({
                        type: 'good',
                        text: 'Meta description length is good.'
                    });
                } else {
                    recommendations.push({
                        type: 'good',
                        text: 'Generated meta description length is good (' + effectiveMetaDesc.length + ' chars).'
                    });
                }
            }
            
            // Client-side analysis results
            
            this.displaySeoResults({
                seo_score: Math.max(0, score),
                recommendations: recommendations
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
            this.updateMetaDescriptionPlaceholder();
        },

        updateGooglePreview: function() {
            // Get the post title from multiple sources, with reliable fallback from PHP
            let postTitle = $('#title').val() || $('.editor-post-title__input').val() || $('input[name="post_title"]').val() || aceSeoAdmin.postTitle || 'Untitled';
            
            const customTitle = $('#yoast_wpseo_title').val();
            let title;
            
            if (customTitle) {
                title = customTitle;
            } else {
                // Use template system
                title = this.processTemplate(aceSeoAdmin.titleTemplate, {
                    title: postTitle,
                    site_name: aceSeoAdmin.siteName,
                    sep: aceSeoAdmin.separator,
                    excerpt: this.getExcerpt()
                });
            }
            
            const description = $('#yoast_wpseo_metadesc').val() || this.processTemplate(aceSeoAdmin.metaTemplate, {
                title: postTitle,
                site_name: aceSeoAdmin.siteName,
                sep: aceSeoAdmin.separator,
                excerpt: this.getExcerpt()
            });
            
            $('#preview-title').text(title);
            $('#preview-description').text(description);
        },

        updateFacebookPreview: function() {
            // Get the post title from multiple sources, with reliable fallback from PHP
            let postTitle = $('#title').val() || $('.editor-post-title__input').val() || $('input[name="post_title"]').val() || aceSeoAdmin.postTitle || 'Untitled';
            
            const customOgTitle = $('#yoast_wpseo_opengraph-title').val();
            const customSeoTitle = $('#yoast_wpseo_title').val();
            
            let title;
            if (customOgTitle) {
                title = customOgTitle;
            } else if (customSeoTitle) {
                title = customSeoTitle;
            } else {
                // Use template system
                title = this.processTemplate(aceSeoAdmin.titleTemplate, {
                    title: postTitle,
                    site_name: aceSeoAdmin.siteName,
                    sep: aceSeoAdmin.separator,
                    excerpt: this.getExcerpt()
                });
            }
            
            const customOgDesc = $('#yoast_wpseo_opengraph-description').val();
            const customMetaDesc = $('#yoast_wpseo_metadesc').val();
            
            let description;
            if (customOgDesc) {
                description = customOgDesc;
            } else if (customMetaDesc) {
                description = customMetaDesc;
            } else {
                description = this.processTemplate(aceSeoAdmin.metaTemplate, {
                    title: postTitle,
                    site_name: aceSeoAdmin.siteName,
                    sep: aceSeoAdmin.separator,
                    excerpt: this.getExcerpt()
                });
            }
            
            const image = $('#yoast_wpseo_opengraph-image').val();
            const featuredImage = $('#yoast_wpseo_opengraph-image').data('featured-image');
            
            $('#facebook-preview-title').text(title);
            $('#facebook-preview-description').text(description);
            
            const $imageContainer = $('#facebook-preview-image');
            const imageToShow = image || featuredImage;
            if (imageToShow) {
                const altText = image ? 'Facebook preview' : 'Facebook preview (featured image)';
                $imageContainer.html(`<img src="${imageToShow}" alt="${altText}">`);
            } else {
                $imageContainer.html('<div class="ace-seo-placeholder-image">📷</div>');
            }
        },

        updateTwitterPreview: function() {
            // Get the post title from multiple sources, with reliable fallback from PHP
            let postTitle = $('#title').val() || $('.editor-post-title__input').val() || $('input[name="post_title"]').val() || aceSeoAdmin.postTitle || 'Untitled';
            
            const customTwitterTitle = $('#yoast_wpseo_twitter-title').val();
            const customOgTitle = $('#yoast_wpseo_opengraph-title').val();
            const customSeoTitle = $('#yoast_wpseo_title').val();
            
            let title;
            if (customTwitterTitle) {
                title = customTwitterTitle;
            } else if (customOgTitle) {
                title = customOgTitle;
            } else if (customSeoTitle) {
                title = customSeoTitle;
            } else {
                // Use template system
                title = this.processTemplate(aceSeoAdmin.titleTemplate, {
                    title: postTitle,
                    site_name: aceSeoAdmin.siteName,
                    sep: aceSeoAdmin.separator,
                    excerpt: this.getExcerpt()
                });
            }
            
            const customTwitterDesc = $('#yoast_wpseo_twitter-description').val();
            const customOgDesc = $('#yoast_wpseo_opengraph-description').val();
            const customMetaDesc = $('#yoast_wpseo_metadesc').val();
            
            let description;
            if (customTwitterDesc) {
                description = customTwitterDesc;
            } else if (customOgDesc) {
                description = customOgDesc;
            } else if (customMetaDesc) {
                description = customMetaDesc;
            } else {
                description = this.processTemplate(aceSeoAdmin.metaTemplate, {
                    title: postTitle,
                    site_name: aceSeoAdmin.siteName,
                    sep: aceSeoAdmin.separator,
                    excerpt: this.getExcerpt()
                });
            }
            
            const twitterImage = $('#yoast_wpseo_twitter-image').val();
            const featuredImage = $('#yoast_wpseo_twitter-image').data('featured-image');
            
            $('#twitter-preview-title').text(title);
            $('#twitter-preview-description').text(description);
            
            const $imageContainer = $('#twitter-preview-image');
            const imageToShow = twitterImage || featuredImage;
            if (imageToShow) {
                let altText = 'Twitter preview';
                if (!twitterImage && featuredImage) {
                    altText = 'Twitter preview (featured image)';
                }
                $imageContainer.html(`<img src="${imageToShow}" alt="${altText}">`);
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
            if (content) {
                const words = content.split(/\s+/).slice(0, 25);
                return words.join(' ') + (words.length === 25 ? '...' : '');
            }
            
            // Fallback to excerpt from PHP if available
            if (aceSeoAdmin.postContent) {
                return aceSeoAdmin.postContent + '...';
            }
            
            return '';
        },

        updateMetaDescriptionPlaceholder: function() {
            const excerpt = this.getExcerpt();
            const placeholder = excerpt || aceSeoAdmin.postContent + '...' || 'Enter a compelling description for search engines';
            $('#yoast_wpseo_metadesc').attr('placeholder', placeholder);
        },

        processTemplate: function(template, variables) {
            let result = template;
            
            // Replace each variable
            for (const [key, value] of Object.entries(variables)) {
                const placeholder = '{' + key + '}';
                result = result.replace(new RegExp(placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), value || '');
            }
            
            // Clean up extra spaces and trim
            result = result.replace(/\s+/g, ' ').trim();
            
            return result;
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
            // Ensure all AI buttons have spinner markup and correct label
            $('.ace-ai-button').each(function() {
                var $btn = $(this);
                // If not already present, add spinner markup
                if ($btn.find('.ace-ai-loading').length === 0) {
                    $btn.append('<span class="ace-ai-loading" style="display:none;"><span class="ace-seo-spinner"></span></span>');
                }
                // If not already present, add text span
                if ($btn.find('.ace-ai-button-text').length === 0) {
                    var label = $btn.text().trim() || 'AI';
                    $btn.html('<span class="ace-ai-button-text">✨ <strong><em>' + label + '</em></strong></span>' + $btn.html());
                }
            });
        },

        initSocialDefaults: function() {
            // Set up featured image data for social media defaults
            if (aceSeoAdmin.featuredImage) {
                // Set data attributes for Facebook and Twitter image fields
                $('#yoast_wpseo_opengraph-image').attr('data-featured-image', aceSeoAdmin.featuredImage);
                $('#yoast_wpseo_twitter-image').attr('data-featured-image', aceSeoAdmin.featuredImage);
            }
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
                case 'generate_facebook_titles':
                    this.generateFacebookTitles(contentData, $button);
                    break;
                case 'generate_facebook_descriptions':
                    this.generateFacebookDescriptions(contentData, $button);
                    break;
                case 'generate_twitter_titles':
                    this.generateTwitterTitles(contentData, $button);
                    break;
                case 'generate_twitter_descriptions':
                    this.generateTwitterDescriptions(contentData, $button);
                    break;
                case 'generate_facebook_image':
                    this.generateFacebookImage(contentData, $button);
                    break;
                case 'generate_twitter_image':
                    this.generateTwitterImage(contentData, $button);
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
                nonce: $('#ace_seo_ai_nonce').val(),
                post_id: (typeof aceSeoAdmin !== 'undefined' && aceSeoAdmin.postId) ? aceSeoAdmin.postId : ($('input#post_ID').val() || 0)
            };
        },

        generateTitles: function(contentData, $button) {
            // Generating titles
            // Cache logic
            const cacheKey = this.getCacheKey('titles', contentData.content, contentData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached AI titles response
                this.setButtonLoading($button, false);
                this.showTitleSuggestions(cachedResponse);
                return;
            }
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_titles',
                ...contentData
            })
            .done((response) => {
                // Titles response
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.setCachedResponse(cacheKey, response.data.titles);
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
            // Cache logic
            const cacheKey = this.getCacheKey('descriptions', contentData.content, contentData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached AI descriptions response
                this.setButtonLoading($button, false);
                this.showDescriptionSuggestions(cachedResponse);
                return;
            }
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_descriptions',
                ...contentData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.setCachedResponse(cacheKey, response.data.descriptions);
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
            // Cache logic
            const cacheKey = this.getCacheKey('keywords', contentData.content, contentData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached AI keywords response
                this.setButtonLoading($button, false);
                this.showKeywordSuggestions(cachedResponse);
                return;
            }
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_keywords',
                ...contentData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.setCachedResponse(cacheKey, response.data.keywords);
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

        generateFacebookTitles: function(contentData, $button) {
            // Add current Facebook title to the data
            const facebookData = {
                ...contentData,
                facebook_title: $('#yoast_wpseo_opengraph-title').val(),
                seo_title: $('#yoast_wpseo_title').val()
            };
            // Cache logic
            const cacheKey = this.getCacheKey('facebook_titles', contentData.content, facebookData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached Facebook titles response
                this.setButtonLoading($button, false);
                this.showTitleSuggestions(cachedResponse, 'facebook');
                return;
            }
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_facebook_titles',
                ...facebookData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.setCachedResponse(cacheKey, response.data.titles);
                    this.showTitleSuggestions(response.data.titles, 'facebook');
                } else {
                    this.showAiError(response.data || 'Failed to generate Facebook titles');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        generateFacebookDescriptions: function(contentData, $button) {
            const facebookData = {
                ...contentData,
                facebook_description: $('#yoast_wpseo_opengraph-description').val(),
                meta_description: $('#yoast_wpseo_metadesc').val()
            };
            // Cache logic
            const cacheKey = this.getCacheKey('facebook_descriptions', contentData.content, facebookData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached Facebook descriptions response
                this.setButtonLoading($button, false);
                this.showDescriptionSuggestions(cachedResponse, 'facebook');
                return;
            }
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_facebook_descriptions',
                ...facebookData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.setCachedResponse(cacheKey, response.data.descriptions);
                    this.showDescriptionSuggestions(response.data.descriptions, 'facebook');
                } else {
                    this.showAiError(response.data || 'Failed to generate Facebook descriptions');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        generateTwitterTitles: function(contentData, $button) {
            const twitterData = {
                ...contentData,
                twitter_title: $('#yoast_wpseo_twitter-title').val(),
                facebook_title: $('#yoast_wpseo_opengraph-title').val(),
                seo_title: $('#yoast_wpseo_title').val()
            };
            // Cache logic
            const cacheKey = this.getCacheKey('twitter_titles', contentData.content, twitterData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached Twitter titles response
                this.setButtonLoading($button, false);
                this.showTitleSuggestions(cachedResponse, 'twitter');
                return;
            }
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_twitter_titles',
                ...twitterData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.setCachedResponse(cacheKey, response.data.titles);
                    this.showTitleSuggestions(response.data.titles, 'twitter');
                } else {
                    this.showAiError(response.data || 'Failed to generate Twitter titles');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        generateTwitterDescriptions: function(contentData, $button) {
            const twitterData = {
                ...contentData,
                twitter_description: $('#yoast_wpseo_twitter-description').val(),
                facebook_description: $('#yoast_wpseo_opengraph-description').val(),
                meta_description: $('#yoast_wpseo_metadesc').val()
            };
            // Cache logic
            const cacheKey = this.getCacheKey('twitter_descriptions', contentData.content, twitterData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached Twitter descriptions response
                this.setButtonLoading($button, false);
                this.showDescriptionSuggestions(cachedResponse, 'twitter');
                return;
            }
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_twitter_descriptions',
                ...twitterData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    this.setCachedResponse(cacheKey, response.data.descriptions);
                    this.showDescriptionSuggestions(response.data.descriptions, 'twitter');
                } else {
                    this.showAiError(response.data || 'Failed to generate Twitter descriptions');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        generateFacebookImage: function(contentData, $button) {
            const facebookData = {
                ...contentData,
                facebook_title: $('#yoast_wpseo_opengraph-title').val(),
                facebook_description: $('#yoast_wpseo_opengraph-description').val(),
                featured_image_url: aceSeoAdmin.featuredImage || ''
            };
            
            // Check cache first
            const cacheKey = this.getCacheKey('facebook_image', contentData.content, facebookData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            
            if (cachedResponse) {
                // Using cached Facebook image response
                this.setButtonLoading($button, false);
                this.showImageSuggestions(cachedResponse, 'facebook');
                return;
            }
            
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_facebook_image',
                ...facebookData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    // Cache the response
                    this.setCachedResponse(cacheKey, response.data.image_suggestions);
                    this.showImageSuggestions(response.data.image_suggestions, 'facebook');
                } else {
                    this.showAiError(response.data || 'Failed to generate Facebook image suggestions');
                }
            })
            .fail(() => {
                this.setButtonLoading($button, false);
                this.showAiError('Network error occurred');
            });
        },

        generateTwitterImage: function(contentData, $button) {
            const twitterData = {
                ...contentData,
                twitter_title: $('#yoast_wpseo_twitter-title').val(),
                twitter_description: $('#yoast_wpseo_twitter-description').val(),
                featured_image_url: aceSeoAdmin.featuredImage || ''
            };
            
            // Check cache first
            const cacheKey = this.getCacheKey('twitter_image', contentData.content, twitterData);
            const cachedResponse = this.getCachedResponse(cacheKey);
            
            if (cachedResponse) {
                // Using cached Twitter image response
                this.setButtonLoading($button, false);
                this.showImageSuggestions(cachedResponse, 'twitter');
                return;
            }
            
            $.post(aceSeoAdmin.ajaxurl, {
                action: 'ace_seo_generate_twitter_image',
                ...twitterData
            })
            .done((response) => {
                this.setButtonLoading($button, false);
                if (response.success) {
                    // Cache the response
                    this.setCachedResponse(cacheKey, response.data.image_suggestions);
                    this.showImageSuggestions(response.data.image_suggestions, 'twitter');
                } else {
                    this.showAiError(response.data || 'Failed to generate Twitter image suggestions');
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
            
            $.post(aceSeoAdmin.ajaxurl, {
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
            
            $.post(aceSeoAdmin.ajaxurl, {
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
            
            $.post(aceSeoAdmin.ajaxurl, {
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

        showTitleSuggestions: function(titles, platform) {
            // Showing title suggestions
            
            // Determine the title type and character limits based on platform
            let titleType, charLimit, modalTitle;
            if (platform === 'facebook') {
                titleType = 'facebook_title';
                charLimit = 95;
                modalTitle = 'AI Facebook Title Suggestions';
            } else if (platform === 'twitter') {
                titleType = 'twitter_title';
                charLimit = 70;
                modalTitle = 'AI Twitter Title Suggestions';
            } else {
                titleType = 'title';
                charLimit = 60;
                modalTitle = 'AI Title Suggestions';
            }
            
            let html = '<div class="ace-ai-suggestions-list">';
            
            titles.forEach((titleData, index) => {
                // Handle both old format (string) and new format (object)
                const title = typeof titleData === 'string' ? titleData : titleData.title;
                const reason = typeof titleData === 'object' ? titleData.reason : (index === 0 ? 'AI recommended best option' : 'Alternative suggestion');
                const isRecommended = index === 0;
                
                const charCount = title.length;
                const charClass = charCount <= charLimit ? 'optimal' : charCount <= charLimit + 10 ? 'warning' : 'error';
                
                html += `
                    <div class="ace-ai-suggestion-item ${isRecommended ? 'recommended' : ''}" data-suggestion="${this.escapeHtml(title)}" data-type="${titleType}">
                        ${isRecommended ? '<div class="ace-ai-recommended-badge">✨ AI Recommended</div>' : ''}
                        <div class="ace-ai-suggestion-text">${this.escapeHtml(title)}</div>
                        <div class="ace-ai-suggestion-reason">${this.escapeHtml(reason)}</div>
                        <div class="ace-ai-suggestion-meta">
                            <span class="ace-ai-char-count ${charClass}">${charCount} characters</span>
                            <span class="ace-ai-score">${charCount <= charLimit ? '✓ Optimal' : charCount <= charLimit + 10 ? '⚠ Long' : '❌ Too long'}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal(modalTitle, html, titleType);
        },

        showDescriptionSuggestions: function(descriptions, platform) {
            // Determine the description type and character limits based on platform
            let descType, charLimit, modalTitle, optimalMin;
            if (platform === 'facebook') {
                descType = 'facebook_description';
                charLimit = 300;
                optimalMin = 150;
                modalTitle = 'AI Facebook Description Suggestions';
            } else if (platform === 'twitter') {
                descType = 'twitter_description';
                charLimit = 200;
                optimalMin = 100;
                modalTitle = 'AI Twitter Description Suggestions';
            } else {
                descType = 'description';
                charLimit = 160;
                optimalMin = 120;
                modalTitle = 'AI Meta Description Suggestions';
            }
            
            let html = '<div class="ace-ai-suggestions-list">';
            
            descriptions.forEach((descData, index) => {
                // Handle both old format (string) and new format (object)
                const description = typeof descData === 'string' ? descData : descData.description;
                const reason = typeof descData === 'object' ? descData.reason : (index === 0 ? 'AI recommended best option' : 'Alternative suggestion');
                const isRecommended = index === 0;
                
                const charCount = description.length;
                const charClass = charCount >= optimalMin && charCount <= charLimit ? 'optimal' : charCount < optimalMin ? 'warning' : 'error';
                
                html += `
                    <div class="ace-ai-suggestion-item ${isRecommended ? 'recommended' : ''}" data-suggestion="${this.escapeHtml(description)}" data-type="${descType}">
                        ${isRecommended ? '<div class="ace-ai-recommended-badge">✨ AI Recommended</div>' : ''}
                        <div class="ace-ai-suggestion-text">${this.escapeHtml(description)}</div>
                        <div class="ace-ai-suggestion-reason">${this.escapeHtml(reason)}</div>
                        <div class="ace-ai-suggestion-meta">
                            <span class="ace-ai-char-count ${charClass}">${charCount} characters</span>
                            <span class="ace-ai-score">${charCount >= optimalMin && charCount <= charLimit ? '✓ Optimal' : charCount < optimalMin ? '⚠ Too short' : '❌ Too long'}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal(modalTitle, html, descType);
        },

        showKeywordSuggestions: function(keywords) {
            // Showing keyword suggestions
            
            let html = '<div class="ace-ai-suggestions-list">';
            
            keywords.forEach((keywordData, index) => {
                // Handle both old format (string) and new format (object)
                const keyword = typeof keywordData === 'string' ? keywordData : keywordData.keyword;
                const reason = typeof keywordData === 'object' ? keywordData.reason : (index === 0 ? 'AI recommended best option' : 'Alternative suggestion');
                const isRecommended = index === 0;
                
                // Simple keyword analysis
                const wordCount = keyword.split(' ').length;
                const keywordType = wordCount === 1 ? 'Primary' : wordCount <= 3 ? 'Long-tail' : 'Extended';
                const difficultyLevel = wordCount === 1 ? 'High' : wordCount <= 2 ? 'Medium' : 'Low';
                
                html += `
                    <div class="ace-ai-suggestion-item ${isRecommended ? 'recommended' : ''}" data-suggestion="${this.escapeHtml(keyword)}" data-type="keyword">
                        ${isRecommended ? '<div class="ace-ai-recommended-badge">✨ AI Recommended</div>' : ''}
                        <div class="ace-ai-suggestion-text">${this.escapeHtml(keyword)}</div>
                        <div class="ace-ai-suggestion-reason">${this.escapeHtml(reason)}</div>
                        <div class="ace-ai-suggestion-meta">
                            <span class="ace-ai-keyword-type">${keywordType} Keyword</span>
                            <span class="ace-ai-difficulty">Difficulty: ${difficultyLevel}</span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            this.showModal('AI Keyword Suggestions', html, 'keyword');
        },

        showImageSuggestions: function(imageSuggestions, platform) {
            // Determine the platform details
            let modalTitle, targetField;
            if (platform === 'facebook') {
                modalTitle = 'AI Facebook Image Suggestions';
                targetField = 'facebook_image';
            } else if (platform === 'twitter') {
                modalTitle = 'AI Twitter Image Suggestions';
                targetField = 'twitter_image';
            } else {
                modalTitle = 'AI Image Suggestions';
                targetField = 'image';
            }
            
            let html = '<div class="ace-ai-suggestions-list ace-ai-image-suggestions">';
            
            imageSuggestions.forEach((imageData, index) => {
                const isRecommended = index === 0;
                
                html += `
                    <div class="ace-ai-suggestion-item ace-ai-image-item ${isRecommended ? 'recommended' : ''}" data-suggestion="${this.escapeHtml(imageData.concept)}" data-type="${targetField}">
                        ${isRecommended ? '<div class="ace-ai-recommended-badge">✨ AI Recommended</div>' : ''}
                        
                        <div class="ace-ai-image-display">
                            ${imageData.generated_image ? 
                                `<div class="ace-ai-generated-image">
                                    <img src="${imageData.generated_image}" alt="${this.escapeHtml(imageData.concept)}" class="ace-generated-img" data-url="${imageData.generated_image}" data-field="${targetField}">
                                    <div class="ace-ai-image-overlay">
                                        <button type="button" class="ace-btn ace-btn-primary ace-use-image" data-url="${imageData.generated_image}" data-field="${targetField}">
                                            Use This Image
                                        </button>
                                    </div>
                                </div>` :
                                `<div class="ace-ai-image-placeholder">
                                    <span class="ace-ai-placeholder-icon">🖼️</span>
                                    <span class="ace-ai-placeholder-text">${imageData.image_error || 'Image generation failed'}</span>
                                </div>`
                            }
                        </div>
                        
                        <div class="ace-ai-image-concept">
                            <h4 class="ace-ai-concept-title">${this.escapeHtml(imageData.concept)}</h4>
                            <div class="ace-ai-concept-details">
                                <div class="ace-ai-detail-item">
                                    <strong>Text Overlay:</strong> ${this.escapeHtml(imageData.text_overlay)}
                                </div>
                                <div class="ace-ai-detail-item">
                                    <strong>Colors:</strong> ${this.escapeHtml(imageData.colors)}
                                </div>
                                <div class="ace-ai-detail-item">
                                    <strong>Why it works:</strong> ${this.escapeHtml(imageData.reason)}
                                </div>
                            </div>
                        </div>
                        
                        <div class="ace-ai-image-prompt">
                            <strong>AI Image Prompt:</strong>
                            <div class="ace-ai-prompt-editor">
                                <textarea class="ace-ai-prompt-text" data-original="${this.escapeHtml(imageData.image_prompt)}">${this.escapeHtml(imageData.image_prompt)}</textarea>
                                <div class="ace-ai-prompt-actions">
                                    <button type="button" class="ace-btn ace-btn-secondary ace-copy-prompt" data-prompt="${this.escapeHtml(imageData.image_prompt)}">
                                        Copy Prompt
                                    </button>
                                    <button type="button" class="ace-btn ace-btn-primary ace-regenerate-image" data-platform="${platform}" data-index="${index}">
                                        🔄 Regenerate
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            // Add generation controls
            html += `
                <div class="ace-ai-generation-controls">
                    <div class="ace-ai-generation-buttons">
                        <button type="button" class="ace-btn ace-btn-secondary ace-generate-more" data-platform="${platform}">
                            🎨 Generate 2 More
                        </button>
                        <button type="button" class="ace-btn ace-btn-outline ace-custom-prompt-toggle" data-platform="${platform}">
                            ✏️ Custom Prompt
                        </button>
                    </div>
                    <div class="ace-ai-custom-prompt" style="display: none;">
                        <textarea class="ace-ai-custom-prompt-text" placeholder="Enter your custom image generation prompt..."></textarea>
                        <button type="button" class="ace-btn ace-btn-primary ace-generate-custom" data-platform="${platform}">
                            Generate from Prompt
                        </button>
                    </div>
                </div>
            `;
            
            html += '<div class="ace-ai-image-note"><p><strong>Note:</strong> Click on any generated image to use it, or edit the prompt and regenerate for different variations. Images are powered by OpenAI\'s DALL-E.</p></div>';
            
            this.showModal(modalTitle, html, targetField);
            
            // Add event handlers using delegation to avoid conflicts
            const $modal = $('#ace-ai-suggestions-modal');
            
            // Handle image clicks
            $modal.off('click.aceImage').on('click.aceImage', '.ace-generated-img, .ace-use-image', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const imageUrl = $(this).data('url');
                const fieldType = $(this).data('field');
                
                // Find the button - either this element if it's the button, or find it in the same container
                let $button;
                if ($(this).hasClass('ace-use-image')) {
                    $button = $(this);
                } else {
                    // If image was clicked, find the button in the same image container
                    $button = $(this).closest('.ace-ai-generated-image').find('.ace-use-image');
                }
                
                AceSeo.useImageUrl(imageUrl, fieldType, $button);
            });
            
            // Handle copy prompt functionality
            $modal.off('click.aceCopy').on('click.aceCopy', '.ace-copy-prompt', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const prompt = $(this).data('prompt');
                navigator.clipboard.writeText(prompt).then(() => {
                    $(this).text('Copied!').addClass('copied');
                    setTimeout(() => {
                        $(this).text('Copy Prompt').removeClass('copied');
                    }, 2000);
                });
            });
            
            // Handle regenerate functionality
            $modal.off('click.aceRegen').on('click.aceRegen', '.ace-regenerate-image', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const platform = $(this).data('platform');
                const index = $(this).data('index');
                const promptTextarea = $(this).closest('.ace-ai-prompt-editor').find('.ace-ai-prompt-text');
                const customPrompt = promptTextarea.val();
                const $button = $(this);
                const $imageContainer = $(this).closest('.ace-ai-image-item').find('.ace-ai-image-display');
                
                // Show loading state
                $button.prop('disabled', true).text('🔄 Generating...');
                $imageContainer.html('<div class="ace-ai-image-loading"><span class="ace-seo-spinner"></span><span>Generating new image...</span></div>');
                
                // Make AJAX request to regenerate image
                $.ajax({
                    url: aceSeoAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_regenerate_image',
                        nonce: $('#ace_seo_ai_nonce').val(),
                        prompt: customPrompt,
                        platform: platform
                    },
                    success: function(response) {
                        if (response.success && response.data.image_url) {
                            $imageContainer.html(`
                                <div class="ace-ai-generated-image">
                                    <img src="${response.data.image_url}" alt="Regenerated image" class="ace-generated-img" data-url="${response.data.image_url}" data-field="${targetField}">
                                    <div class="ace-ai-image-overlay">
                                        <button type="button" class="ace-btn ace-btn-primary ace-use-image" data-url="${response.data.image_url}" data-field="${targetField}">
                                            Use This Image
                                        </button>
                                    </div>
                                </div>
                            `);
                        } else {
                            $imageContainer.html(`
                                <div class="ace-ai-image-placeholder">
                                    <span class="ace-ai-placeholder-icon">❌</span>
                                    <span class="ace-ai-placeholder-text">${response.data || 'Regeneration failed'}</span>
                                </div>
                            `);
                        }
                    },
                    error: function() {
                        $imageContainer.html(`
                            <div class="ace-ai-image-placeholder">
                                <span class="ace-ai-placeholder-icon">❌</span>
                                <span class="ace-ai-placeholder-text">Network error</span>
                            </div>
                        `);
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('🔄 Regenerate');
                    }
                });
            });
            
            // Prevent prompt textarea from triggering image events
            $modal.off('click.acePrompt').on('click.acePrompt', '.ace-ai-prompt-text', function(e) {
                e.stopPropagation();
                $(this).focus();
            });
            
            // Handle custom prompt toggle
            $modal.off('click.aceToggle').on('click.aceToggle', '.ace-custom-prompt-toggle', function(e) {
                e.preventDefault();
                const $customSection = $(this).closest('.ace-ai-generation-controls').find('.ace-ai-custom-prompt');
                $customSection.toggle();
                $(this).text($customSection.is(':visible') ? '✏️ Hide Custom' : '✏️ Custom Prompt');
            });
            
            // Handle generate more images
            $modal.off('click.aceMore').on('click.aceMore', '.ace-generate-more', function(e) {
                e.preventDefault();
                const platform = $(this).data('platform');
                const $button = $(this);
                
                $button.prop('disabled', true).text('🎨 Generating...');
                
                AceSeo.generateMoreImages(platform, function(newImages) {
                    // Append new images to the existing list
                    const $suggestionsList = $('.ace-ai-suggestions-list');
                    newImages.forEach(imageData => {
                        const newHtml = AceSeo.createImageSuggestionHtml(imageData, targetField, false);
                        $suggestionsList.append(newHtml);
                    });
                    $button.prop('disabled', false).text('🎨 Generate 2 More');
                }, function(error) {
                    AceSeo.showNotification('Failed to generate more images: ' + error, 'error');
                    $button.prop('disabled', false).text('🎨 Generate 2 More');
                });
            });
            
            // Handle generate from custom prompt
            $modal.off('click.aceCustom').on('click.aceCustom', '.ace-generate-custom', function(e) {
                e.preventDefault();
                const platform = $(this).data('platform');
                const customPrompt = $(this).siblings('.ace-ai-custom-prompt-text').val().trim();
                const $button = $(this);
                
                if (!customPrompt) {
                    AceSeo.showNotification('Please enter a custom prompt', 'error');
                    return;
                }
                
                $button.prop('disabled', true).text('Generating...');
                
                AceSeo.generateCustomImage(platform, customPrompt, function(imageData) {
                    // Append custom image to the existing list
                    const $suggestionsList = $('.ace-ai-suggestions-list');
                    const newHtml = AceSeo.createImageSuggestionHtml(imageData, targetField, false);
                    $suggestionsList.append(newHtml);
                    
                    // Clear and hide custom prompt
                    $(this).siblings('.ace-ai-custom-prompt-text').val('');
                    $(this).closest('.ace-ai-custom-prompt').hide();
                    $('.ace-custom-prompt-toggle').text('✏️ Custom Prompt');
                    
                    $button.prop('disabled', false).text('Generate from Prompt');
                }.bind(this), function(error) {
                    AceSeo.showNotification('Failed to generate custom image: ' + error, 'error');
                    $button.prop('disabled', false).text('Generate from Prompt');
                });
            });
        },

        useImageUrl: function(imageUrl, fieldType, $button) {
            // Set the image URL in the appropriate field
            const fieldMapping = {
                'facebook_image': '#yoast_wpseo_opengraph-image',
                'twitter_image': '#yoast_wpseo_twitter-image'
            };
            
            const fieldSelector = fieldMapping[fieldType];
            if (fieldSelector) {
                // Show loading state on input field
                const $field = $(fieldSelector);
                const originalValue = $field.val();
                $field.val('Saving image to media library...').prop('disabled', true);
                
                // Show loading state on button if provided
                let originalButtonText = '';
                if ($button && $button.length) {
                    originalButtonText = $button.text();
                    $button.text('🔄 Uploading to Library...').prop('disabled', true).addClass('uploading');
                }
                
                // Invalidate related caches when image is set
                this.invalidateImageCaches(fieldType);
                
                // Save image to media library first
                $.ajax({
                    url: aceSeoAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ace_seo_save_image_to_library',
                        nonce: $('#ace_seo_ai_nonce').val(),
                        image_url: imageUrl,
                        post_id: aceSeoAdmin.postId || 0,
                        filename: 'ai-' + fieldType.replace('_', '-') + '-' + Date.now() + '.png'
                    },
                    success: (response) => {
                        if (response.success) {
                            // Use the saved image URL
                            $field.val(response.data.url).trigger('input').prop('disabled', false);
                            
                            // Restore button state
                            if ($button && $button.length) {
                                $button.text(originalButtonText).prop('disabled', false).removeClass('uploading');
                            }
                            
                            // Update the preview if it exists
                            const previewMapping = {
                                'facebook_image': '#facebook-preview-image img',
                                'twitter_image': '#twitter-preview-image img'
                            };
                            
                            const previewSelector = previewMapping[fieldType];
                            if (previewSelector) {
                                $(previewSelector).attr('src', response.data.url);
                            }
                            
                            // Close the modal
                            this.closeModal({ target: $('#ace-ai-suggestions-modal')[0], currentTarget: $('#ace-ai-suggestions-modal')[0] });
                            
                            // Show success message
                            this.showNotification('Image saved to media library and applied!', 'success');
                        } else {
                            // Fallback to direct URL if saving fails
                            $field.val(imageUrl).trigger('input').prop('disabled', false);
                            
                            // Restore button state
                            if ($button && $button.length) {
                                $button.text(originalButtonText).prop('disabled', false).removeClass('uploading');
                            }
                            
                            const previewMapping = {
                                'facebook_image': '#facebook-preview-image img',
                                'twitter_image': '#twitter-preview-image img'
                            };
                            
                            const previewSelector = previewMapping[fieldType];
                            if (previewSelector) {
                                $(previewSelector).attr('src', imageUrl);
                            }
                            
                            this.closeModal({ target: $('#ace-ai-suggestions-modal')[0], currentTarget: $('#ace-ai-suggestions-modal')[0] });
                            this.showNotification('Image applied (direct URL - could not save to library)', 'success');
                        }
                    },
                    error: () => {
                        // Fallback to direct URL on error
                        $field.val(imageUrl).trigger('input').prop('disabled', false);
                        
                        // Restore button state
                        if ($button && $button.length) {
                            $button.text(originalButtonText).prop('disabled', false).removeClass('uploading');
                        }
                        
                        const previewMapping = {
                            'facebook_image': '#facebook-preview-image img',
                            'twitter_image': '#twitter-preview-image img'
                        };
                        
                        const previewSelector = previewMapping[fieldType];
                        if (previewSelector) {
                            $(previewSelector).attr('src', imageUrl);
                        }
                        
                        this.closeModal({ target: $('#ace-ai-suggestions-modal')[0], currentTarget: $('#ace-ai-suggestions-modal')[0] });
                        this.showNotification('Image applied (direct URL - could not save to library)', 'success');
                    }
                });
            }
        },

        showNotification: function(message, type = 'success') {
            const notification = $(`
                <div class="ace-notification ace-notification-${type}">
                    ${message}
                </div>
            `);
            
            $('body').append(notification);
            
            // Animate in
            setTimeout(() => {
                notification.addClass('ace-notification-show');
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.removeClass('ace-notification-show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        },

        generateMoreImages: function(platform, successCallback, errorCallback) {
            const contentData = {
                content: this.getContent(),
                focus_keyword: $('#yoast_wpseo_focuskw').val(),
                current_title: this.getCurrentTitle(),
                facebook_title: $('#yoast_wpseo_opengraph-title').val(),
                facebook_description: $('#yoast_wpseo_opengraph-description').val(),
                twitter_title: $('#yoast_wpseo_twitter-title').val(),
                twitter_description: $('#yoast_wpseo_twitter-description').val(),
                featured_image_url: this.getFeaturedImageUrl()
            };
            
            // Use a unique cache key for additional images with timestamp
            const cacheKey = this.getCacheKey(`${platform}_more_images`, contentData.content, {
                ...contentData,
                timestamp: Math.floor(Date.now() / (1000 * 60 * 10)) // 10-minute buckets for more images
            });
            
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse) {
                // Using cached more images response
                successCallback(cachedResponse);
                return;
            }
            
            $.ajax({
                url: aceSeoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ace_seo_generate_more_images',
                    nonce: $('#ace_seo_ai_nonce').val(),
                    platform: platform,
                    ...contentData
                },
                success: (response) => {
                    if (response.success && response.data.image_suggestions) {
                        // Cache the response
                        this.setCachedResponse(cacheKey, response.data.image_suggestions);
                        successCallback(response.data.image_suggestions);
                    } else {
                        errorCallback(response.data || 'Unknown error');
                    }
                },
                error: function() {
                    errorCallback('Network error');
                }
            });
        },

        generateCustomImage: function(platform, customPrompt, successCallback, errorCallback) {
            // Create cache key for custom prompts
            const cacheKey = this.getCacheKey(`${platform}_custom_image`, customPrompt, {
                timestamp: Math.floor(Date.now() / (1000 * 60 * 60)) // 1-hour buckets for custom prompts
            });
            
            const cachedResponse = this.getCachedResponse(cacheKey);
            if (cachedResponse && cachedResponse.length > 0) {
                console.log('Using cached custom image response');
                successCallback(cachedResponse[0]);
                return;
            }
            
            $.ajax({
                url: aceSeoAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ace_seo_generate_more_images',
                    nonce: $('#ace_seo_ai_nonce').val(),
                    platform: platform,
                    custom_prompt: customPrompt
                },
                success: (response) => {
                    if (response.success && response.data.image_suggestions && response.data.image_suggestions.length > 0) {
                        // Cache the response
                        this.setCachedResponse(cacheKey, response.data.image_suggestions);
                        successCallback(response.data.image_suggestions[0]);
                    } else {
                        errorCallback(response.data || 'Unknown error');
                    }
                },
                error: function() {
                    errorCallback('Network error');
                }
            });
        },

        createImageSuggestionHtml: function(imageData, targetField, isRecommended = false) {
            return `
                <div class="ace-ai-suggestion-item ace-ai-image-item ${isRecommended ? 'recommended' : ''}" data-suggestion="${this.escapeHtml(imageData.concept)}" data-type="${targetField}">
                    ${isRecommended ? '<div class="ace-ai-recommended-badge">✨ AI Recommended</div>' : ''}
                    
                    <div class="ace-ai-image-display">
                        ${imageData.generated_image ? 
                            `<div class="ace-ai-generated-image">
                                <img src="${imageData.generated_image}" alt="${this.escapeHtml(imageData.concept)}" class="ace-generated-img" data-url="${imageData.generated_image}" data-field="${targetField}">
                                <div class="ace-ai-image-overlay">
                                    <button type="button" class="ace-btn ace-btn-primary ace-use-image" data-url="${imageData.generated_image}" data-field="${targetField}">
                                        Use This Image
                                    </button>
                                </div>
                            </div>` :
                            `<div class="ace-ai-image-placeholder">
                                <span class="ace-ai-placeholder-icon">🖼️</span>
                                <span class="ace-ai-placeholder-text">${imageData.image_error || 'Image generation failed'}</span>
                            </div>`
                        }
                    </div>
                    
                    <div class="ace-ai-image-concept">
                        <h4 class="ace-ai-concept-title">${this.escapeHtml(imageData.concept)}</h4>
                        <div class="ace-ai-concept-details">
                            <div class="ace-ai-detail-item">
                                <strong>Text Overlay:</strong> ${this.escapeHtml(imageData.text_overlay)}
                            </div>
                            <div class="ace-ai-detail-item">
                                <strong>Colors:</strong> ${this.escapeHtml(imageData.colors)}
                            </div>
                            <div class="ace-ai-detail-item">
                                <strong>Why it works:</strong> ${this.escapeHtml(imageData.reason)}
                            </div>
                        </div>
                    </div>
                    
                    <div class="ace-ai-image-prompt">
                        <strong>AI Image Prompt:</strong>
                        <div class="ace-ai-prompt-editor">
                            <textarea class="ace-ai-prompt-text" data-original="${this.escapeHtml(imageData.image_prompt)}">${this.escapeHtml(imageData.image_prompt)}</textarea>
                            <div class="ace-ai-prompt-actions">
                                <button type="button" class="ace-btn ace-btn-secondary ace-copy-prompt" data-prompt="${this.escapeHtml(imageData.image_prompt)}">
                                    Copy Prompt
                                </button>
                                <button type="button" class="ace-btn ace-btn-primary ace-regenerate-image" data-platform="${imageData.platform || 'facebook'}" data-index="${Date.now()}">
                                    🔄 Regenerate
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
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
            const $results = $('#ace-analysis-results');
            
            if (show) {
                $loading.show();
                $results.hide();
            } else {
                $loading.hide();
                $results.show();
            }
        },

        showAnalysisError: function(message) {
            const $content = $('#ace-content-analysis-content');
            $content.html(`
                <div class="ace-analysis-error">
                    <span class="dashicons dashicons-warning"></span>
                    <p>${this.escapeHtml(message)}</p>
                </div>
            `);
        },

        switchToAnalysisTab: function() {
            // No longer needed - analysis is shown in sidebar
        },

        populateContentAnalysis: function(analysis) {
            const $content = $('#ace-content-analysis-content');
            const $section = $('#ace-content-analysis-section');
            
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
            $section.show();
            
            // Update SEO hat analysis
            this.populateSeoHatAnalysis(analysis);
            
            // Update analysis score indicator
            this.updateAnalysisScore('good');
        },

        populateContentImprovements: function(improvements) {
            const $content = $('#ace-content-improvements-content');
            const $section = $('#ace-content-improvements-section');
            
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
            
            $content.html(html);
            $section.show();
        },

        populateTopicSuggestions: function(suggestions) {
            const $content = $('#ace-topic-ideas-content');
            const $section = $('#ace-topic-ideas-section');
            
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
            $section.show();
            
            // Switch to analysis tab and scroll to topics
            this.switchToAnalysisTab();
        },

        updateAnalysisScore: function(rating) {
            // Score indicator removed in new sidebar design
        },

        populateSeoHatAnalysis: function(analysis) {
            const $container = $('#ace-seo-hat-analysis');
            const $indicator = $('#ace-seo-hat-indicator');
            const $scoreText = $('.ace-hat-score-text');
            
            // Analyze content for SEO practices
            const hatScore = this.calculateSeoHatScore(analysis);
            
            // Update the indicator position (0-100%)
            const position = hatScore.percentage;
            $indicator.css('left', `calc(${position}% - 3px)`);
            
            // Update the score text and styling
            $scoreText.text(hatScore.label)
                .removeClass('black-hat gray-hat white-hat')
                .addClass(hatScore.class);
            
            // Show the container
            $container.show();
            
            // Log for debugging
            console.log('SEO Hat Analysis:', hatScore);
            console.log('Analysis data:', analysis);
        },

        calculateSeoHatScore: function(analysis) {
            let score = 50; // Start neutral (gray hat)
            let reasons = [];
            
            // Convert analysis to string for pattern matching
            const content = JSON.stringify(analysis).toLowerCase();
            
            // First, check if AI provided explicit SEO ethics assessment
            if (analysis && analysis.seo_ethics) {
                let ethicsText = '';
                
                // Handle both array and string formats
                if (Array.isArray(analysis.seo_ethics)) {
                    ethicsText = analysis.seo_ethics.join(' ').toLowerCase();
                } else {
                    ethicsText = analysis.seo_ethics.toLowerCase();
                }
                
                console.log('SEO Ethics text:', ethicsText);
                
                if (ethicsText.includes('white hat')) {
                    score = 80;
                    reasons.push('AI Assessment: White Hat practices detected');
                } else if (ethicsText.includes('black hat')) {
                    score = 20;
                    reasons.push('AI Assessment: Black Hat practices detected');
                } else if (ethicsText.includes('gray hat') || ethicsText.includes('grey hat')) {
                    score = 50;
                    reasons.push('AI Assessment: Gray Hat practices detected');
                }
            } else {
                console.log('No seo_ethics found in analysis:', analysis);
            }
            
            // Black hat indicators (negative score)
            const blackHatPatterns = [
                'keyword stuffing', 'keyword density too high', 'excessive keywords',
                'hidden text', 'cloaking', 'duplicate content', 'thin content',
                'over-optimization', 'unnatural link building', 'clickbait',
                'misleading', 'spammy', 'manipulative', 'deceptive',
                'artificial', 'forced keywords', 'irrelevant keywords'
            ];
            
            // Gray hat indicators (neutral)
            const grayHatPatterns = [
                'aggressive seo', 'borderline', 'questionable',
                'paid links', 'guest posting', 'article spinning',
                'aggressive optimization', 'slightly aggressive'
            ];
            
            // White hat indicators (positive score)
            const whiteHatPatterns = [
                'natural', 'user-focused', 'high quality', 'valuable content',
                'good user experience', 'relevant', 'informative',
                'well-structured', 'helpful', 'authoritative',
                'original content', 'proper optimization', 'ethical',
                'user intent', 'natural flow', 'quality content'
            ];
            
            // Check for black hat patterns
            blackHatPatterns.forEach(pattern => {
                if (content.includes(pattern)) {
                    score -= 8;
                    reasons.push(`Detected: ${pattern}`);
                }
            });
            
            // Check for gray hat patterns
            grayHatPatterns.forEach(pattern => {
                if (content.includes(pattern)) {
                    score -= 3;
                    reasons.push(`Detected: ${pattern}`);
                }
            });
            
            // Check for white hat patterns
            whiteHatPatterns.forEach(pattern => {
                if (content.includes(pattern)) {
                    score += 5;
                    reasons.push(`Detected: ${pattern}`);
                }
            });
            
            // Ensure score is between 0-100
            score = Math.max(0, Math.min(100, score));
            
            // Determine category and label
            let category, label, className;
            if (score < 33) {
                category = 'black-hat';
                label = `Black Hat SEO (${Math.round(score)}% Ethical)`;
                className = 'black-hat';
            } else if (score < 67) {
                category = 'gray-hat';
                label = `Gray Hat SEO (${Math.round(score)}% Ethical)`;
                className = 'gray-hat';
            } else {
                category = 'white-hat';
                label = `White Hat SEO (${Math.round(score)}% Ethical)`;
                className = 'white-hat';
            }
            
            console.log('Calculated SEO hat score:', {score, category, reasons});
            
            return {
                score: score,
                percentage: score,
                category: category,
                label: label,
                class: className,
                reasons: reasons
            };
        },

        // Test function for debugging SEO hat analysis
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
            $.post(aceSeoAdmin.ajaxurl, {
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
            $.post(aceSeoAdmin.ajaxurl, {
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
            $.post(aceSeoAdmin.ajaxurl, {
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
            
            // First, show SEO hat analysis
            this.populateSeoHatAnalysis(analysis);
            
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
            // Allow closing if clicking on close button or X button
            if ($(e.target).hasClass('ace-modal-close') || $(e.target).closest('.ace-modal-close').length > 0) {
                $('#ace-ai-suggestions-modal').hide();
                this.currentModal = null;
                this.selectedSuggestion = null;
                return;
            }
            
            // Don't close if clicking on modal content, textareas, inputs, or other buttons
            if ($(e.target).closest('.ace-modal-content, textarea, input').length > 0) {
                return;
            }
            
            // Only close if clicking on overlay
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
                case 'facebook_title':
                    $('#yoast_wpseo_opengraph-title').val(suggestion).trigger('input');
                    // Also update Facebook preview
                    this.updateFacebookPreview();
                    break;
                case 'facebook_description':
                    $('#yoast_wpseo_opengraph-description').val(suggestion).trigger('input');
                    // Also update Facebook preview
                    this.updateFacebookPreview();
                    break;
                case 'twitter_title':
                    $('#yoast_wpseo_twitter-title').val(suggestion).trigger('input');
                    // Also update Twitter preview
                    this.updateTwitterPreview();
                    break;
                case 'twitter_description':
                    $('#yoast_wpseo_twitter-description').val(suggestion).trigger('input');
                    // Also update Twitter preview
                    this.updateTwitterPreview();
                    break;
                case 'facebook_image':
                case 'twitter_image':
                    // For images, we don't use the suggestion text, but trigger the image selection
                    // The actual image selection happens via the "Use This Image" button
                    console.log('Image suggestion clicked - image selection should be handled by "Use This Image" button');
                    return; // Don't close modal for images
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
                case 'facebook_title':
                    console.log('Setting Facebook title field'); // Debug log
                    $('#yoast_wpseo_opengraph-title').val(text).trigger('input');
                    this.updateFacebookPreview();
                    break;
                case 'facebook_description':
                    console.log('Setting Facebook description field'); // Debug log
                    $('#yoast_wpseo_opengraph-description').val(text).trigger('input');
                    this.updateFacebookPreview();
                    break;
                case 'twitter_title':
                    console.log('Setting Twitter title field'); // Debug log
                    $('#yoast_wpseo_twitter-title').val(text).trigger('input');
                    this.updateTwitterPreview();
                    break;
                case 'twitter_description':
                    console.log('Setting Twitter description field'); // Debug log
                    $('#yoast_wpseo_twitter-description').val(text).trigger('input');
                    this.updateTwitterPreview();
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
    
    // Dashboard Cache Management
    const DashboardCache = {
        init: function() {
            this.bindCacheButtons();
        },
        
        bindCacheButtons: function() {
            // Refresh cache button
            $(document).on('click', '#ace-refresh-cache-btn', function() {
                DashboardCache.refreshCache($(this));
            });
            
            // Clear cache button  
            $(document).on('click', '#ace-clear-cache-btn', function() {
                DashboardCache.clearCache($(this));
            });
        },
        
        refreshCache: function($button) {
            const $result = $('#ace-cache-result');
            
            // Show loading state
            $button.prop('disabled', true).html('🔄 Refreshing...');
            $result.show().html('<div class="notice notice-info"><p>🔄 Refreshing dashboard cache...</p></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ace_seo_refresh_dashboard_cache',
                    nonce: aceSeoAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $result.html(`
                            <div class="notice notice-success">
                                <p><strong>✅ Cache Refreshed Successfully!</strong></p>
                                <ul>
                                    <li>Focus Keywords: ${data.stats.focus_keywords}</li>
                                    <li>Meta Descriptions: ${data.stats.meta_descriptions}</li>
                                    <li>Total Posts: ${data.stats.total_posts}</li>
                                    <li>Recent Posts Cached: ${data.recent_posts_count}</li>
                                    <li>Refreshed: ${data.regenerated_at}</li>
                                </ul>
                                <p><em>Dashboard should load much faster now!</em></p>
                            </div>
                        `);
                        
                        // Reload page after 2 seconds to show updated data
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                        
                    } else {
                        $result.html(`<div class="notice notice-error"><p><strong>❌ Error:</strong> ${response.data}</p></div>`);
                    }
                },
                error: function() {
                    $result.html('<div class="notice notice-error"><p><strong>❌ Error:</strong> Failed to refresh cache. Please try again.</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).html('🔄 Refresh Dashboard Cache');
                }
            });
        },
        
        clearCache: function($button) {
            const $result = $('#ace-cache-result');
            
            if (!confirm('Are you sure you want to clear the dashboard cache? This will slow down the next dashboard load until cache is rebuilt.')) {
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).html('🗑️ Clearing...');
            $result.show().html('<div class="notice notice-info"><p>🗑️ Clearing dashboard cache...</p></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ace_seo_clear_dashboard_cache',
                    nonce: aceSeoAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html(`
                            <div class="notice notice-success">
                                <p><strong>✅ Cache Cleared Successfully!</strong></p>
                                <p>${response.data.note}</p>
                            </div>
                        `);
                    } else {
                        $result.html(`<div class="notice notice-error"><p><strong>❌ Error:</strong> ${response.data}</p></div>`);
                    }
                },
                error: function() {
                    $result.html('<div class="notice notice-error"><p><strong>❌ Error:</strong> Failed to clear cache. Please try again.</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).html('🗑️ Clear Cache');
                }
            });
        }
    };
    
    // Initialize dashboard cache management when DOM is ready
    $(document).ready(function() {
        if ($('#ace-refresh-cache-btn, #ace-clear-cache-btn').length > 0) {
            DashboardCache.init();
        }
    });

    // Make AceSeo globally available for debugging
    window.AceSeo = AceSeo;
    window.DashboardCache = DashboardCache;

})(jQuery);
