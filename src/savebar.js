/**
 * Standalone SaveBar bundle for the Ace plugin suite.
 *
 * Enqueue the `ace-savebar` handle (registered by Ace SEO when active, or ship this file) and give any
 * settings form `data-ace-savebar="admin-post"`; the bar appears and saves it. See SaveBar.js.
 */
import SaveBar from './components/SaveBar.js';

window.AceSaveBar = SaveBar;

if (!window.AceCrawlEnhancerSaveBar) {
    window.AceCrawlEnhancerSaveBar = SaveBar;
}

document.addEventListener('DOMContentLoaded', () => {
    // Ace SEO's own admin bundle builds the bar itself; only build one here if nobody else has.
    if (!window.aceCrawlEnhancerAdmin && !document.querySelector('.ace-redis-save-bar')) {
        SaveBar.autoRegister();
    }
});
