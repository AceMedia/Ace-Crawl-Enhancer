<?php
/** Existing retention options, grouped for administrators without changing their storage. */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'AceSeoRetentionActions' ) ) {
    return;
}
$o = AceSeoRetentionActions::options();
?>
<div id="retention" class="tab-content ace-retention-settings">
    <h2>Retention settings</h2>
    <?php AceSeoRetentionReport::render_message(); ?>
    <p>Choose which older posts to review and what readers see. Saving report settings does not delete, redirect or hide any post.</p>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention' ) ); ?>">Open the retention dashboard</a> · <a href="#retention-help" class="ace-subtab-link" data-target-tab="retention" data-target-group="retention-help">What the groups and recommendations mean</a></p>
    <p class="description">Change anything below, then use the single <strong>Save all retention settings</strong> button at the bottom. Running, stopping or retrying a Google Sheets refresh is separate and never part of a save.</p>
    <form id="ace-retention-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'ace_seo_retention_settings_save' ); ?>
        <input type="hidden" name="action" value="ace_seo_retention_settings_save">

    <section id="retention-report" class="ace-retention-section">
        <h3>Choose the posts to review</h3>
        <p class="description">Saved changes apply to the next report build. The dashboard keeps showing the period and settings used for its existing results.</p>
            <table class="form-table"><tbody>
                <tr><th scope="row"><label for="retention-years">Review posts older than</label></th><td><input id="retention-years" type="number" name="report_years" min="1" max="20" value="<?php echo esc_attr( (int) $o['report_years'] ); ?>" class="small-text"> years<p class="description">This is the age of the article, measured from its publication date.</p></td></tr>
                <tr><th scope="row"><label for="retention-days">Look at traffic from the last</label></th><td><input id="retention-days" type="number" name="report_days" min="7" max="480" value="<?php echo esc_attr( (int) $o['report_days'] ); ?>" class="small-text"> days<p class="description">This is the period requested from the traffic sources, not how long this site has been collecting evidence.</p></td></tr>
                <tr><th scope="row"><label for="retention-views">Views needed to count as retained</label></th><td><input id="retention-views" type="number" name="retained_views" min="1" value="<?php echo esc_attr( (int) $o['retained_views'] ); ?>" class="small-text"><p class="description">An older post joins the Retained group when it reaches this many views in the traffic window, or gets any search click.</p></td></tr>
                <tr><th scope="row"><label for="retention-words">Shorter than</label></th><td><input id="retention-words" type="number" name="thin_words" min="0" value="<?php echo esc_attr( (int) $o['thin_words'] ); ?>" class="small-text"> words<p class="description">Posts below this length with no recorded visits are candidates for review. Length alone does not decide their value.</p></td></tr>
                <tr><th scope="row"><label for="retention-timing">Judging timing</label></th><td><select id="retention-timing" name="timing_policy">
                    <option value="estimate" <?php selected( $o['timing_policy'] ?? 'estimate', 'estimate' ); ?>>Judge on the traffic window, holding only posts whose known relevant dates fall outside it</option>
                    <option value="strict" <?php selected( $o['timing_policy'] ?? 'estimate', 'strict' ); ?>>Hold every post until its timing is confirmed (evergreen, set dates, or a verified event)</option>
                </select><p class="description">Posts with readers or search clicks are never held. Under the strict policy, unconfirmed posts are "Not ready to judge" until an editor sets "When this article matters" on them or a rule below covers them; the anniversary estimate is shown but not trusted.</p></td></tr>
                <tr><th scope="row"><label for="retention-timing-rules">When articles in a category or tag matter</label></th><td><textarea id="retention-timing-rules" name="timing_rules" rows="4" class="large-text code" placeholder="category:guides = evergreen&#10;category:horse-racing-tips = event 3&#10;category:cheltenham-festival-tips = season 03-01 03-20"><?php echo esc_textarea( AceSeoRetentionActions::timing_rules_text( (array) ( $o['timing_rules'] ?? array() ) ) ); ?></textarea><p class="description">One rule per line: <code>taxonomy:slug = evergreen</code>, <code>= event N</code> (relevant for N days from publication, a one-off) or <code>= season MM-DD MM-DD</code> (relevant between those dates every year). A setting on the post itself always wins. An event that is over is judged on its readership since, not held.</p></td></tr>
            </tbody></table>
            <details class="ace-retention-detail"><summary>Developer details: weekly checks and visitor tracking</summary>
                <p><label><input type="checkbox" name="auto_build" value="1" <?php checked( ! empty( $o['auto_build'] ) ); ?>> Recheck the report every week</label></p>
                <p class="description">Uses WordPress cron and the saved settings. It updates recommendations; it does not apply actions.</p>
                <p><label><input type="checkbox" name="track_views" value="1" <?php checked( ! empty( $o['track_views'] ) ); ?>> Count visitors to older posts on this site</label></p>
                <p class="description">Adds a small visitor beacon and stores daily counts and referrers for eligible older posts. Google Analytics is used for report views when connected; local counts are the fallback. Tracking starts when enabled, so earlier missing data is not a measured zero.</p>
                <p>Bots are counted only when WordPress renders the page. A cached response can bypass that count. The report keeps the last <?php echo esc_html( AceSeoRetentionReport::HISTORY_KEEP ); ?> completed builds, including manual rebuilds; that does not guarantee a full year of seasonal evidence.</p>
            </details>
    </section>

    <section id="retention-notice" class="ace-retention-section">
        <h3>Help readers recognise older articles</h3>
            <input type="hidden" name="settings_section" value="notice-lifetimes">
            <p><label><input type="checkbox" name="notice_enabled" value="1" <?php checked( ! empty( $o['notice_enabled'] ) ); ?>> Show an old-article notice</label></p>
            <p class="description">Adds a message above older articles. It does not change the saved article or its search visibility.</p>
            <p><label for="retention-notice-years">Show it on articles older than</label> <input id="retention-notice-years" type="number" name="notice_years" min="1" max="30" value="<?php echo esc_attr( (int) $o['notice_years'] ); ?>" class="small-text"> years</p>
            <p><label for="retention-notice-text">Message for readers</label><br><input id="retention-notice-text" type="text" name="notice_text" value="<?php echo esc_attr( $o['notice_text'] ); ?>" class="large-text"></p>
            <p class="description">Use <code>{date}</code> for the publication date and <code>{years}</code> for the age in whole years. A post can override this notice in its SEO controls.</p>
            <div id="retention-lifetimes">
                <p><strong>Search expiry rules below can remove matching content from search results. They apply to existing articles as well as new ones.</strong></p>
                <details class="ace-retention-detail"><summary>Developer details: when content should leave search results</summary>
                    <p>A lifetime adds an <code>unavailable_after</code> date. It is calculated from the later of publication or last edit; updating the article gives it a fresh lifetime. Search engines read the instruction on their next crawl. Nothing is deleted or redirected.</p>
                    <p>Leave a lifetime blank for no rule. A term rule overrides a post-type rule; if several terms match, the longest lifetime wins. A date set directly on the post takes priority. WordPress <a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>">Search engine visibility</a> remains the site-wide control.</p>
                    <table class="form-table"><tbody>
                        <?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : if ( 'attachment' === $pt->name ) { continue; } ?>
                        <tr><th scope="row"><label for="retention-life-<?php echo esc_attr( $pt->name ); ?>"><?php echo esc_html( $pt->labels->name ); ?></label></th><td><input id="retention-life-<?php echo esc_attr( $pt->name ); ?>" type="number" min="0" name="lifetimes[<?php echo esc_attr( $pt->name ); ?>]" value="<?php echo esc_attr( (int) ( $o['lifetimes'][ $pt->name ] ?? 0 ) ?: '' ); ?>" class="small-text"> days</td></tr>
                        <?php endforeach; ?>
                        <tr><th scope="row"><label for="retention-rules">Rules for categories or tags</label></th><td><textarea id="retention-rules" name="lifetime_rules" rows="4" class="large-text" placeholder="category:announcements=30"><?php foreach ( (array) $o['lifetime_rules'] as $k => $d ) { echo esc_html( $k . '=' . $d ) . "\n"; } ?></textarea><p class="description">One per line: <code>taxonomy:slug=days</code>.</p></td></tr>
                    </tbody></table>
                    <?php $lc = AceSeoRetentionActions::lifetime_counts(); if ( $lc ) : ?>
                    <div class="ace-retention-table"><table class="widefat striped"><thead><tr><th>Rule</th><th>Days</th><th>Posts</th><th>Past the expiry date</th></tr></thead><tbody>
                        <?php foreach ( $lc as $c ) : ?><tr><td><?php echo esc_html( $c['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( $c['days'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $c['expired'] ) ); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                    <p>The notice markup can be changed with <code>ace_seo_retention_notice_html</code>.</p>
                </details>
            </div>
                </section>

    <section id="retention-readers" class="ace-retention-section">
        <h3>Help readers move from older posts to current ones</h3>
        <p>These controls use the Retained group from the latest report. They do not move or delete the article.</p>
            <p><label><input type="checkbox" name="retained_notice" value="1" <?php checked( ! empty( $o['retained_notice'] ) ); ?>> Show a notice on retained posts</label></p>
            <p class="description">This notice follows the Retained group rather than the age rule above. When both apply, this message takes priority; a post’s own notice override still wins.</p>
            <p><label for="retained-notice-text">Message for readers</label><br><input id="retained-notice-text" type="text" name="retained_notice_text" value="<?php echo esc_attr( $o['retained_notice_text'] ); ?>" class="large-text"></p>
            <p class="description"><code>{date}</code> and <code>{years}</code> work here too.</p>
            <p><label><input type="checkbox" name="light_enabled" value="1" <?php checked( ! empty( $o['light_enabled'] ) ); ?>> Use a lighter page</label></p>
            <p class="description">For logged-out readers, skips selected blocks and widget areas on retained posts. The result depends on the theme; pages, previews, feeds, carts, checkout and accounts are excluded.</p>
            <p><label for="retention-continue">Link to the latest article</label><br><select id="retention-continue" name="light_continue">
                <option value="card" <?php selected( $o['light_continue'], 'card' ); ?>>Show a link and continue when the reader scrolls past it</option>
                <option value="none" <?php selected( $o['light_continue'], 'none' ); ?>>Leave this to the theme</option>
            </select></p>
            <p class="description">Works when “Use a lighter page” is on. Opens the latest other article in the first category with a full page load. No other article means no card.</p>
            <details class="ace-retention-detail"><summary>Developer details: excluded blocks, caching and theme integration</summary>
                <p><label for="retention-drop">Block classes or template-part slugs to leave out</label><br><input id="retention-drop" type="text" name="light_drop" value="<?php echo esc_attr( $o['light_drop'] ); ?>" class="large-text"></p>
                <p class="description">Comma separated. Matching blocks are skipped before rendering and classic widget areas are emptied. The saved post content is unchanged.</p>
                <p><label for="retention-cache">Keep the lighter page in Ace Redis Cache for</label> <input id="retention-cache" type="number" name="light_cache_hours" min="0" max="720" value="<?php echo esc_attr( (int) $o['light_cache_hours'] ); ?>" class="small-text"> hours</p>
                <p class="description">0 keeps the site’s usual cache lifetime. Existing cached pages change as they expire or are purged; a lighter page does not guarantee a database-free request.</p>
                <p>A theme can use <code>/wp-json/ace-seo/v1/retention/next?post=ID</code> for the same destination. Check both the card and the theme’s own scrolling behaviour on desktop and mobile.</p>
            </details>
                </section>
    <section class="ace-retention-section">
        <h3>Google Sheets</h3>
        <p>Connect a spreadsheet to export filtered post lists as separate tabs and to keep the main report tab up to date.</p>
        <?php if ( class_exists( 'AceSeoSheets' ) ) { AceSeoSheets::render_fields(); } ?>
    </section>
    </form>
    <div class="ace-retention-save-bar" aria-label="Retention settings">
        <span class="ace-retention-save-status" role="status" aria-live="polite" data-clean="No unsaved changes" data-dirty="You have unsaved changes" data-saving="Saving…">No unsaved changes</span>
        <button type="submit" form="ace-retention-settings-form" class="button button-primary">Save all retention settings</button>
    </div>
    <section class="ace-retention-section">
        <h3>Google Sheets report: run, stop or retry</h3>
        <?php if ( class_exists( 'AceSeoSheetsSchedule' ) ) { AceSeoSheetsSchedule::render_controls(); } ?>
    </section>
    <script>
    (function () {
        var form = document.getElementById('ace-retention-settings-form');
        var status = document.querySelector('.ace-retention-save-status');
        var bar = status ? status.closest('.ace-retention-save-bar') : null;
        if (!form || !status || !bar) { return; }
        var snapshot = function () {
            var out = [];
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name) { return; }
                if (el.type === 'checkbox' || el.type === 'radio') { out.push(el.name + '=' + (el.checked ? el.value : '')); }
                else if (el.multiple) { out.push(el.name + '=' + Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; }).join('|')); }
                else { out.push(el.name + '=' + el.value); }
            });
            return out.join('\n');
        };
        var clean = snapshot(), dirty = false, saving = false;
        var paint = function () {
            dirty = !saving && snapshot() !== clean;
            status.textContent = saving ? status.dataset.saving : (dirty ? status.dataset.dirty : status.dataset.clean);
            bar.classList.toggle('is-dirty', dirty);
        };
        form.addEventListener('input', paint);
        form.addEventListener('change', paint);
        form.addEventListener('submit', function () { saving = true; paint(); });
        window.addEventListener('beforeunload', function (e) { if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; } });
    })();
    </script>
    <section id="retention-help" class="ace-retention-section">
        <?php AceSeoRetentionReport::render_help(); ?>
    </section>
</div>
<noscript><style>.ace-redis-settings .tab-content { display: block; } .ace-retention-settings { scroll-margin-top: 40px; }</style></noscript>
