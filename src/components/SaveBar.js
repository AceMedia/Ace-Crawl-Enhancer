/**
 * SaveBar: the one fixed save bar every Ace settings page uses.
 *
 * Tracks changes across one or more forms and saves whichever are dirty from a single button, so a
 * page never needs a Save button per section. A form joins in two ways:
 *
 *   1. `containerSelector` / `forms` options when the page's own script builds the bar, or
 *   2. markup alone: any `<form data-ace-savebar="admin-post">` on the page is picked up by
 *      `SaveBar.autoRegister()`. Such a form is posted to its own `action` with `ace_json=1`, and its
 *      handler replies with WordPress JSON (`wp_send_json_success( { message, reload } )`).
 *      `data-ace-savebar-autosave="0"` keeps it out of auto-save; `data-ace-savebar-reload="1"`
 *      reloads the page once it has saved (for server-rendered state).
 *
 * Shared by the Ace plugin suite: the built file is registered as the `ace-savebar` script handle and
 * exposed as `window.AceSaveBar`, so other plugins enqueue it rather than ship their own buttons.
 *
 * @package AceCrawlEnhancer
 * @since 1.0.3
 */

const $ = window.jQuery;

class SaveBar {
    constructor(options = {}) {
        this.options = {
            containerSelector: '#ace-seo-settings-form',
            saveButtonSelector: '#ace-redis-save-btn',
            messageContainerSelector: '#ace-redis-messages',
            onSave: null,
            forms: null,
            ...options
        };
        // Every form this bar looks after: { selector, save(formEl) -> Promise<{success,message,reload}>, autoSave }.
        this.forms = this.options.forms || [{
            selector: this.options.containerSelector,
            save: null,
            autoSave: true
        }];
        this.original = {};
        this.dirty = {};

        this.isInitialized = false;
        this.hasUnsavedChanges = false;
        this.isSaving = false;
        this.isSuccess = false;
        this.message = '';
        this.elapsedTime = 0;
        this.intervalId = null;
        this.originalFormData = null;

        try {
            const stored = localStorage.getItem('ace_seo_auto_save_enabled');
            this.isAutoSaveEnabled = stored === null ? true : stored === '1';
        } catch (e) {
            this.isAutoSaveEnabled = true;
        }

        this.init();
    }

    init() {
        if (this.isInitialized) return;

        this.createSaveBar();
        this.setupEventListeners();
        this.captureOriginalFormData();
        this.updateSaveButtonState();
        this.isInitialized = true;
    }

    createSaveBar() {
        if (document.querySelector('.ace-redis-save-bar')) {
            return;
        }

        const autoSaveToggle = this.isAutoSaveEnabled ? 'checked' : '';

        const saveBarHTML = `
            <div class="ace-redis-save-bar">
                <div class="save-bar-content">
                    <div class="save-bar-left">
                        <span class="save-message"></span>
                    </div>
                    <div class="save-bar-right">
                        <div class="auto-save-toggle-wrapper">
                            <label class="ace-switch" for="auto-save-toggle">
                                <input type="checkbox" id="auto-save-toggle" ${autoSaveToggle}>
                                <span class="ace-slider"></span>
                            </label>
                            <span class="toggle-label">Auto-save</span>
                        </div>
                        <button type="button" id="save-bar-button" class="button button-primary" disabled>
                            <span class="dashicons dashicons-admin-settings"></span>
                            <span class="button-text">Saved</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', saveBarHTML);
        this.updateFixedPosition();
    }

    setupEventListeners() {
        $(this.formSelectors()).on('input change', 'input, select, textarea', () => {
            setTimeout(() => this.checkForChanges(), 10);
        });

        $(document).on('click', '#save-bar-button', (e) => {
            e.preventDefault();
            this.handleSave();
        });

        $(document).on('change', '#auto-save-toggle', () => {
            this.toggleAutoSave();
        });

        $(window).on('resize scroll load', () => this.updateFixedPosition());

        if (window.wp && window.wp.hooks) {
            window.wp.hooks.addAction('wp-collapse-menu', 'ace-crawl-enhancer', () => {
                setTimeout(() => this.updateFixedPosition(), 300);
            });
        }

        $(window).on('beforeunload', (e) => {
            if (this.hasUnsavedChanges && !this.isSaving) {
                const message = 'You have unsaved changes. Are you sure you want to leave?';
                e.originalEvent.returnValue = message;
                return message;
            }
        });
    }

    updateFixedPosition() {
        const saveBar = document.querySelector('.ace-redis-save-bar');
        if (!saveBar) return;

        const adminMenuWrap = document.querySelector('#adminmenuwrap');
        if (adminMenuWrap) {
            saveBar.style.left = `${adminMenuWrap.offsetWidth}px`;
        }
    }

    formSelectors() {
        return this.forms.map((f) => f.selector).join(', ');
    }

    captureOriginalFormData() {
        this.forms.forEach((f) => {
            this.original[f.selector] = this.getFormDataObject($(f.selector));
            this.dirty[f.selector] = false;
        });
        this.originalFormData = this.original;
    }

    getFormDataObject($form) {
        const formData = {};

        $form.serializeArray().forEach(field => {
            formData[field.name] = field.value;
        });

        $form.find('input[type="checkbox"]').each(function() {
            const name = $(this).attr('name');
            if (name) {
                formData[name] = $(this).is(':checked') ? '1' : '0';
            }
        });

        return formData;
    }

    checkForChanges() {
        if (!this.originalFormData) return;

        let any = false;
        this.forms.forEach((f) => {
            const current = this.getFormDataObject($(f.selector));
            this.dirty[f.selector] = JSON.stringify(this.original[f.selector]) !== JSON.stringify(current);
            any = any || this.dirty[f.selector];
        });
        this.setUnsavedChanges(any);
    }

    /** Dirty forms that may be saved automatically; a form that opted out waits for the button. */
    autoSaveable() {
        return this.forms.filter((f) => this.dirty[f.selector] && f.autoSave !== false);
    }

    setUnsavedChanges(hasChanges) {
        if (this.hasUnsavedChanges !== hasChanges) {
            this.hasUnsavedChanges = hasChanges;
            this.updateSaveButtonState();

            if (hasChanges) {
                this.startElapsedTimeTracking();
                if (this.isAutoSaveEnabled && this.autoSaveable().length) {
                    setTimeout(() => this.handleAutoSave(), 500);
                }
            } else {
                this.stopElapsedTimeTracking();
            }
        }
    }

    updateSaveButtonState() {
        const $button = $('#save-bar-button');
        const $buttonText = $button.find('.button-text');
        const $icon = $button.find('.dashicons');

        if (this.isSaving) {
            $button.prop('disabled', true).removeClass('success');
            $buttonText.text('Saving...');
            $icon.removeClass('dashicons-admin-settings dashicons-yes-alt').addClass('dashicons-update');
        } else if (this.isSuccess) {
            $button.prop('disabled', true).addClass('success');
            $buttonText.text('Saved!');
            $icon.removeClass('dashicons-admin-settings dashicons-update').addClass('dashicons-yes-alt');
        } else if (this.hasUnsavedChanges) {
            $button.prop('disabled', false).removeClass('success');
            $buttonText.text('Save changes');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
        } else {
            $button.prop('disabled', true).removeClass('success');
            $buttonText.text('Saved');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
        }
    }

    /** Save one form; normalises every saver to { success, message, reload }. */
    async saveForm(f) {
        const formEl = document.querySelector(f.selector);
        if (!formEl) return { success: false };
        let result;
        if (typeof f.save === 'function') {
            result = await f.save(formEl);
        } else if (this.options.onSave && typeof this.options.onSave === 'function' && f.selector === this.options.containerSelector) {
            result = await this.options.onSave();
        } else {
            result = await this.defaultSave(formEl);
        }
        if (typeof result === 'boolean') return { success: result };
        return result || { success: false };
    }

    /** Save every dirty form in the given list, one after another. */
    async saveForms(list) {
        const outcome = { success: true, messages: [], reload: false };
        for (const f of list) {
            const r = await this.saveForm(f);
            if (r.success) {
                this.original[f.selector] = this.getFormDataObject($(f.selector));
                this.dirty[f.selector] = false;
                if (r.message) outcome.messages.push(r.message);
                outcome.reload = outcome.reload || !!r.reload;
            } else {
                outcome.success = false;
                if (r.message) outcome.messages.push(r.message);
            }
        }
        return outcome;
    }

    async handleSave() {
        if (!this.hasUnsavedChanges || this.isSaving) return;

        this.setSaving(true);

        try {
            const outcome = await this.saveForms(this.forms.filter((f) => this.dirty[f.selector]));
            if (outcome.success) {
                this.showMessage(outcome.messages.join(' ') || 'Settings saved.', 'success');
                this.setSuccess(true);
                this.checkForChanges();
                setTimeout(() => this.setSuccess(false), 3000);
                if (outcome.reload) {
                    this.isSaving = true; // keep the leave-page warning quiet while we reload
                    setTimeout(() => window.location.reload(), 600);
                    return;
                }
            } else {
                this.showMessage(outcome.messages.join(' ') || 'Save failed. Please try again.', 'error');
            }
        } catch (error) {
            this.showMessage('An error occurred while saving.', 'error');
        } finally {
            if (!this.isSaving) return;
            this.setSaving(false);
        }
    }

    async handleAutoSave() {
        if (!this.hasUnsavedChanges || this.isSaving) return;
        const list = this.autoSaveable();
        if (!list.length) return;

        try {
            const outcome = await this.saveForms(list);
            if (outcome.success) {
                this.showMessage(outcome.messages.join(' ') || 'Changes auto-saved.', 'success');
                this.checkForChanges();
            } else {
                this.showMessage(outcome.messages.join(' ') || 'Auto-save failed', 'error');
            }
        } catch (error) {
            this.showMessage('Auto-save error occurred', 'error');
        }
    }

    /** A form marked data-ace-savebar="admin-post": post it to its own action and read WordPress JSON back. */
    static async adminPostSave(formEl) {
        const formData = new FormData(formEl);
        formData.append('ace_json', '1');
        try {
            const response = await fetch(formEl.getAttribute('action') || window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const text = await response.text();
            let json = null;
            try { json = JSON.parse(text); } catch (e) { json = null; }
            if (json && typeof json.success !== 'undefined') {
                const data = json.data || {};
                return { success: !!json.success, message: data.message || (typeof json.data === 'string' ? json.data : ''), reload: !!data.reload };
            }
            return { success: response.ok, reload: response.ok && formEl.getAttribute('data-ace-savebar-reload') === '1' };
        } catch (e) {
            return { success: false, message: 'The server could not be reached.' };
        }
    }

    /** Forms declared in markup: every <form data-ace-savebar> on the page. */
    static declaredForms() {
        return Array.prototype.map.call(document.querySelectorAll('form[data-ace-savebar]'), (formEl, i) => {
            if (!formEl.id) formEl.id = 'ace-savebar-form-' + i;
            return {
                selector: '#' + formEl.id,
                save: SaveBar.adminPostSave,
                autoSave: formEl.getAttribute('data-ace-savebar-autosave') !== '0'
            };
        });
    }

    /** Build one bar for the page's main form (if any) plus every declared form. */
    static autoRegister(options = {}) {
        const main = document.querySelector(options.containerSelector || '#ace-redis-settings-form, #ace-seo-settings-form');
        const forms = SaveBar.declaredForms();
        if (main) {
            forms.unshift({ selector: '#' + main.id, save: null, autoSave: true });
        }
        if (!forms.length) return null;
        return new SaveBar({ ...options, containerSelector: forms[0].selector, forms });
    }

    async defaultSave(formEl) {
        return new Promise((resolve) => {
            formEl = formEl || document.querySelector(this.options.containerSelector);
            if (!formEl) {
                resolve(false);
                return;
            }

            const formData = new FormData(formEl);
            formData.append('action', 'ace_seo_save_settings');
            formData.append('nonce', (window.ace_seo_admin && window.ace_seo_admin.nonce) ? window.ace_seo_admin.nonce : '');

            $.ajax({
                url: (window.ace_seo_admin && window.ace_seo_admin.ajax_url) ? window.ace_seo_admin.ajax_url : window.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    resolve(!!(response && response.success));
                },
                error: () => {
                    resolve(false);
                }
            });
        });
    }

    setSaving(isSaving) {
        this.isSaving = isSaving;
        this.updateSaveButtonState();
    }

    setSuccess(isSuccess) {
        this.isSuccess = isSuccess;
        this.updateSaveButtonState();
    }

    showMessage(message, type = 'info') {
        this.message = message;
        this.updateMessageDisplay(type);

        if (type === 'success') {
            this.startElapsedTimeTracking();
        }

        const hideDelay = type === 'error' ? 8000 : (type === 'success' ? 5000 : 3000);
        setTimeout(() => {
            this.clearMessage();
        }, hideDelay);
    }

    updateMessageDisplay(type = 'info') {
        const $messageContainer = $('.save-message');

        if (this.message) {
            $messageContainer
                .text(this.message)
                .addClass('visible')
                .removeClass('error success info')
                .addClass(type);
        } else {
            $messageContainer
                .removeClass('visible error success info')
                .text('');
        }
    }

    clearMessage() {
        this.message = '';
        this.updateMessageDisplay();
        this.stopElapsedTimeTracking();
    }

    startElapsedTimeTracking() {
        this.stopElapsedTimeTracking();
        this.elapsedTime = 0;

        this.intervalId = setInterval(() => {
            this.elapsedTime++;
            this.updateElapsedTimeDisplay();
        }, 1000);
    }

    stopElapsedTimeTracking() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    updateElapsedTimeDisplay() {
        if (this.elapsedTime > 0) {
            $('.save-message').text(this.formatElapsedTime(this.elapsedTime));
        }
    }

    formatElapsedTime(seconds) {
        if (seconds < 60) return `${seconds}s ago`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    toggleAutoSave() {
        this.isAutoSaveEnabled = $('#auto-save-toggle').is(':checked');

        try {
            localStorage.setItem('ace_seo_auto_save_enabled', this.isAutoSaveEnabled ? '1' : '0');
        } catch (e) {
            // ignore
        }

        if (this.isAutoSaveEnabled) {
            this.showMessage('Auto-save enabled - changes will be saved automatically', 'success');
        } else {
            this.showMessage('Auto-save disabled - manual save required', 'info');
        }
    }
}

export default SaveBar;
