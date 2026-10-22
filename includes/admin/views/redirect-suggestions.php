<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$labels = AceSeoRetentionReport::recommendation_labels();
$bucket = is_string( $_GET['recommendation'] ?? null ) ? sanitize_key( wp_unslash( $_GET['recommendation'] ) ) : 'consolidate';
if ( ! isset( $labels[ $bucket ] ) && 'all' !== $bucket ) { $bucket = 'consolidate'; }
$rows = AceSeoRetentionReport::rows( 'all' === $bucket ? '' : $bucket, 31, ( $page - 1 ) * 30 );
_prime_post_caches( array_column( $rows, 'id' ), false, true );
$progress = AceSeoRetentionReport::progress();
$history = array_reverse( AceSeoRetentionReport::history() );
$next = wp_next_scheduled( AceSeoRetentionReport::WEEKLY_HOOK );
?>
<section class="ace-redirect-card ace-redirect-tab-panel">
    <h2>Suggested redirects</h2>
    <p>Articles the saved retention report says to <strong>consider combining</strong>. These are review candidates: no replacement URL has been chosen and no redirect is applied by a suggestion.</p>
    <p>Suggestions refresh when the retention report is rebuilt. Traffic, timing, coverage and settings can move an article into or out of this list. Applied redirects remain in place until managed separately.</p>
    <p><strong>Weekly checks:</strong> <?php echo empty( $options['auto_build'] ) ? 'off' : 'on'; ?>.
    <strong>Next check:</strong> <?php echo esc_html( ! empty( $options['auto_build'] ) && $next ? wp_date( 'j M Y, H:i T', $next ) : 'not scheduled' ); ?>.
    <a href="<?php echo esc_url( $settings_url ); ?>">Manage settings</a> · <a href="<?php echo esc_url( $report_url ); ?>">Open report / run a new check</a></p>
    <?php if ( AceSeoRetentionReport::is_building() ) : ?><p class="notice notice-info inline">A report is being rebuilt. Rows update in batches and may come from different checks; use each row’s assessment date until the build finishes.</p><?php endif; ?>
    <form method="get"><input type="hidden" name="page" value="ace-seo-redirects"><input type="hidden" name="tab" value="suggestions">
        <p><label for="ace-redirect-recommendation">Saved recommendation</label> <select id="ace-redirect-recommendation" name="recommendation">
        <?php foreach ( array_merge( array( 'all' => 'All recommendations' ), $labels ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $bucket ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
        </select> <button class="button">Filter</button></p>
    </form>
    <div class="ace-redirect-table"><table class="widefat striped"><thead><tr><th>Article / current redirect</th><th>Recommendation / evidence</th><th>Assessment</th><th>Review</th></tr></thead><tbody>
    <?php foreach ( array_slice( $rows, 0, 30 ) as $row ) : $redirect = AceSeoRedirects::label( $row['id'] ); ?>
        <tr><td><strong><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></strong><br><?php echo esc_html( $redirect ?: 'No saved redirect' ); ?></td>
        <td><strong><?php echo esc_html( $labels[ $row['bucket'] ?? '' ] ?? 'Unknown' ); ?></strong><br><?php echo esc_html( $row['reason'] ?? '' ); ?>
            <p>Views: <?php echo isset( $row['views'] ) ? esc_html( number_format_i18n( $row['views'] ) ) : 'unknown'; ?> · Search clicks: <?php echo isset( $row['clicks'] ) ? esc_html( number_format_i18n( $row['clicks'] ) ) : 'unknown'; ?> · Impressions: <?php echo isset( $row['impressions'] ) ? esc_html( number_format_i18n( $row['impressions'] ) ) : 'unknown'; ?></p>
            <?php if ( ! empty( $row['hold'] ) ) : ?><p>On hold: <?php echo esc_html( $row['hold'] ); ?></p><?php endif; ?>
        </td>
        <td><?php echo esc_html( ! empty( $row['built'] ) ? wp_date( 'j M Y, H:i T', $row['built'] ) : 'Date unknown' ); ?><br><?php echo esc_html( ! empty( $row['window'] ) ? $row['window'] . '-day traffic window' : 'Window unknown' ); ?><br><?php echo esc_html( AceSeoRetentionReport::trend_labels()[ $row['trend'] ?? '' ] ?? 'Trend unknown' ); ?></td>
        <td><?php if ( current_user_can( 'edit_post', $row['id'] ) ) : ?>
            <a class="button" href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>">Review article</a><br>
            <?php if ( 'consolidate' === ( $row['bucket'] ?? '' ) || $redirect ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ace-seo-redirects', 'post' => $row['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo $redirect ? 'Manage redirect' : 'Choose a destination'; ?></a><?php endif; ?>
        <?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if ( ! $rows ) : ?><tr><td colspan="4">No saved recommendations match this view. Run or review the retention report to check its coverage and timing; an empty list does not prove no redirects could be useful.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php AceSeoRedirectDiscovery::pagination( 'suggestions', $page, count( $rows ) > 30, array( 'recommendation' => $bucket ) ); ?>
    <details><summary>How recommendations have changed</summary>
        <p>Completed report checks, newest first (up to 26). These are report-wide totals at the time, not a per-article change log. Different traffic windows or settings can change the totals. The current list also excludes unpublished articles.</p>
        <table class="widefat striped"><thead><tr><th>Completed check</th><th>Traffic window / minimum age</th><th>Consider combining</th><th>Entered this group</th><th>Left this group</th></tr></thead><tbody>
        <?php foreach ( $history as $check ) : $entered = 0; $left = 0;
            foreach ( (array) ( $check['moves'] ?? array() ) as $move => $count ) {
                $parts = explode( '>', $move, 2 );
                if ( 'consolidate' === ( $parts[1] ?? '' ) ) { $entered += (int) $count; }
                if ( 'consolidate' === $parts[0] ) { $left += (int) $count; }
            } ?>
            <tr><td><?php echo esc_html( wp_date( 'j M Y, H:i T', $check['finished'] ) ); ?></td><td><?php echo (int) $check['days']; ?> days / <?php echo (int) $check['years']; ?> years</td><td><?php echo (int) ( $check['counts']['consolidate'] ?? 0 ); ?></td><td><?php echo (int) $entered; ?></td><td><?php echo (int) $left; ?></td></tr>
        <?php endforeach; ?>
        <?php if ( ! $history ) : ?><tr><td colspan="5">No completed check history yet.</td></tr><?php endif; ?>
        </tbody></table>
    </details>
    <details><summary>How to interpret these suggestions</summary>
        <p>The saved report considers combining when it sees impressions without clicks or measured visits, after its other checks and timing holds. This does not establish that another article covers the same subject. Compare the articles and verify a suitable replacement before saving a 301.</p>
        <p>Missing traffic is not zero traffic. Off-season or incomplete periods can hold a recommendation back. The separate evidence preview checks relevant periods and verified overlap more strictly; it can disagree with an older saved report and does not overwrite it.</p>
        <p><a href="<?php echo esc_url( add_query_arg( 'evidence_preview', 1, $report_url ) ); ?>">Preview evidence and timing</a></p>
    </details>
</section>
