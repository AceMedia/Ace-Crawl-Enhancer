/**
 * Ace Crawl Enhancer Admin JavaScript
 *
 * Handles admin interface interactions, AJAX requests,
 * and dynamic UI updates.
 *
 * @package AceCrawlEnhancer
 * @since 1.0.3
 */

import SaveBar from './components/SaveBar.js';

(function($) {
    'use strict';

    // Make SaveBar available globally for WordPress integration
    window.AceCrawlEnhancerSaveBar = SaveBar;

    // Main admin class
    class AceCrawlEnhancerAdmin {
        constructor() {
            this.init();
        }

        init() {
            this.initTabs();
            this.initSaveBar();
            this.initTemplateTokenInsert();
            this.initFormValidation();
            this.initAjaxHandlers();
        }

        initTemplateTokenInsert() {
            $(document).on('click keydown', '.ace-template-tag', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
                    return;
                }

                e.preventDefault();

                const token = $(this).data('template-token');
                const targetId = $(this).data('template-target');

                if (!token || !targetId) {
                    return;
                }

                const $target = $('#' + targetId);
                if (!$target.length) {
                    return;
                }

                const current = $target.val() || '';
                $target.val(current + token);

                const targetEl = $target.get(0);
                if (targetEl && typeof targetEl.setSelectionRange === 'function') {
                    const pos = $target.val().length;
                    targetEl.setSelectionRange(pos, pos);
                }

                $target.trigger('input').trigger('change').focus();
            });
        }

        initTabs() {
            const activateTab = (target) => {
                if (!target || target.charAt(0) !== '#') {
                    return;
                }

                $('.ace-redis-sidebar .nav-tab, .ace-seo-nav-tab').removeClass('nav-tab-active');
                $(`.ace-redis-sidebar .nav-tab[href="${target}"], .ace-seo-nav-tab[href="${target}"]`).addClass('nav-tab-active');

                $('.tab-content, .ace-seo-tab-content').removeClass('active');
                $(target).addClass('active');

                this.updateSidebarSubnav(target.replace('#', ''));
                this.setActiveSubtabLink(null);

                if (history && history.replaceState) {
                    history.replaceState(null, '', target);
                }
            };

            const activateTabAndGroup = (tabId, groupId) => {
                const tabTarget = `#${tabId}`;
                activateTab(tabTarget);

                if (!groupId) {
                    return;
                }

                const group = document.getElementById(groupId);
                const panel = document.getElementById(tabId);
                if (group && panel && panel.classList.contains('active')) {
                    group.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }

                this.setActiveSubtabLink({ tabId, groupId });

                if (history && history.replaceState) {
                    history.replaceState(null, '', `${tabTarget}/${groupId}`);
                }
            };

            $('.ace-redis-sidebar .nav-tab, .ace-seo-nav-tab').on('click', function(e) {
                e.preventDefault();
                activateTab($(this).attr('href'));
            });

            $(document).on('click', '.ace-subtab-link', function(e) {
                e.preventDefault();
                const tabId = $(this).data('target-tab');
                const groupId = $(this).data('target-group');
                activateTabAndGroup(tabId, groupId);
            });

            const hash = window.location.hash;
            if (hash && hash.includes('/')) {
                const parts = hash.replace('#', '').split('/');
                const tabId = parts[0];
                const groupId = parts[1];
                if (tabId) {
                    activateTabAndGroup(tabId, groupId);
                    return;
                }
            }

            if (hash) {
                const tab = $(`.ace-redis-sidebar .nav-tab[href="${hash}"], .ace-seo-nav-tab[href="${hash}"]`);
                if (tab.length) {
                    activateTab(hash);
                    return;
                }
            }

            const $activeTab = $('.ace-redis-sidebar .nav-tab.nav-tab-active').first();
            if ($activeTab.length) {
                this.updateSidebarSubnav(($activeTab.attr('href') || '').replace('#', ''));
            }
        }

        updateSidebarSubnav(activeTabId) {
            const $subnavBlocks = $('.ace-redis-sidebar .ace-tab-subnav');
            $subnavBlocks.removeClass('active');
            $subnavBlocks.filter(`[data-tab="${activeTabId}"]`).addClass('active');
        }

        setActiveSubtabLink(target = null) {
            const $subtabs = $('.ace-redis-sidebar .ace-subtab-link');
            $subtabs.removeClass('is-active');

            if (!target || !target.tabId || !target.groupId) {
                return;
            }

            $subtabs
                .filter(`[data-target-tab="${target.tabId}"][data-target-group="${target.groupId}"]`)
                .addClass('is-active');
        }

        initSaveBar() {
            // Initialize SaveBar component if we're on the settings page
            const $form = $('#ace-redis-settings-form, #ace-seo-settings-form');
            if ($form.length) {
                const selector = $('#ace-redis-settings-form').length ? '#ace-redis-settings-form' : '#ace-seo-settings-form';
                $(document).ready(() => {
                    this.saveBar = new SaveBar({
                        containerSelector: selector,
                        messageContainerSelector: '#ace-redis-messages'
                    });
                });
            }
        }

        initFormValidation() {
            // Add form validation if needed
            $('#ace-redis-settings-form, #ace-seo-settings-form').on('submit', function() {
                // Basic validation can go here
                return true;
            });
        }

        initAjaxHandlers() {
            // Handle any AJAX operations
            // Migration handlers, optimization, etc.
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        window.aceCrawlEnhancerAdmin = new AceCrawlEnhancerAdmin();
    });

})(jQuery);