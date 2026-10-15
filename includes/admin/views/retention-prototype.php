<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$page = max( 1, absint( $_GET['preview_page'] ?? 1 ) );
$plans = new WP_Query( array( 'post_type' => AceSeoRetentionPrototype::TYPE, 'post_status' => 'private', 'posts_per_page' => 21,
    'offset' => ( $page - 1 ) * 20, 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true ) );
?>
<section class="ace-redirect-card ace-redirect-tab-panel">
    <h2>Retention automation: dev preview</h2>
    <?php $notice_key = 'ace_retention_preview_notice_' . get_current_user_id(); $notice = get_transient( $notice_key );
    if ( is_array( $notice ) ) : delete_transient( $notice_key ); ?>
        <p role="status"><strong><?php echo esc_html( $notice['text'] ); ?></strong></p>
    <?php endif; ?>
    <p><strong>Simulation only. No redirect application or scheduling is available.</strong> Review a specific replacement, then save a frozen assessment. Missing evidence stays on hold. Each new preview preserves earlier snapshots.</p>
    <p>This first prototype accepts posts and pages on this site. Archive and external replacements need separate editorial handling. Dev’s site-wide noindex remains a hold; it is never silently ignored.</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'ace_retention_preview' ); ?>
        <input type="hidden" name="action" value="ace_retention_preview">
        <p><label for="retention-source">Source article ID</label><br><input id="retention-source" name="source" type="number" min="1" required></p>
        <details class="ace-destination-search" data-target="retention-destination" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ace_seo_redirect_destinations' ) ); ?>">
            <summary>Find a replacement post or page</summary>
            <fieldset class="ace-destination-types"><legend>Search these types</legend>
                <label><input type="checkbox" value="post:post" checked> Posts</label>
                <label><input type="checkbox" value="post:page" checked> Pages</label>
            </fieldset>
            <p><label for="retention-query">Replacement title</label><br><input id="retention-query" type="search" class="ace-destination-query" maxlength="100"> <button type="button" class="button ace-destination-find">Search destinations</button></p>
            <p class="ace-destination-status" role="status" aria-live="polite"></p><ul class="ace-destination-results"></ul>
        </details>
        <p><label for="retention-destination">Replacement URL</label><br><input type="url" class="large-text" id="retention-destination" name="target"></p>
        <p><label for="retention-start">Traffic period starts</label> <input type="date" id="retention-start" name="start" value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '-90 days' ) ) ); ?>">
            <label for="retention-end">Ends</label> <input type="date" id="retention-end" name="end" value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '-1 day' ) ) ); ?>"></p>
        <p><label><input type="checkbox" name="confirmed" value="1"> I have compared both articles: the replacement preserves the subject, reader intent and relevant event edition or historical information.</label></p>
        <p><label for="retention-reason">Why this is a suitable replacement (at least 30 characters)</label><br><textarea class="large-text" rows="3" id="retention-reason" name="reason" maxlength="2000"></textarea></p>
        <p><button class="button button-primary" name="operation" value="preview">Save dry-run assessment</button></p>
        <p>Protect this source from future automation. These controls only change its manual keep exclusion; they never change a redirect.</p>
        <p><button class="button" name="operation" value="keep" formnovalidate>Pin source: keep</button> <button class="button" name="operation" value="clear-keep" formnovalidate>Remove keep exclusion</button></p>
    </form>
    <h3>Frozen assessments</h3>
    <p>“Passes evidence checks” is a dry-run result, not approval to apply. A changed article, rule or report invalidates a snapshot. No 301 is removed just because a later recommendation changes.</p>
    <?php foreach ( array_slice( $plans->posts, 0, 20 ) as $plan ) : $s = AceSeoRetentionPrototype::read( $plan ); ?>
        <details class="ace-redirect-card">
        <summary><?php echo esc_html( '#' . $plan->ID . ' — ' . ( $s ? $s['source']['title'] . ' → ' . $s['target']['title'] : 'Snapshot integrity check failed' ) ); ?></summary>
        <?php if ( $s ) :
            $stale = $s['fingerprint'] !== AceSeoRetentionPrototype::fingerprint( $s['source']['id'], $s['target']['id'] );
            $old = empty( $s['context']['collected_at'] ) || time() - $s['context']['collected_at'] > DAY_IN_SECONDS; ?>
            <p><strong><?php echo $stale ? 'Changed since capture — reassess' : ( $old ? 'Traffic freshness needs reassessment' : ( $s['holds'] ? 'On hold' : 'Passes evidence checks — simulation only' ) ); ?></strong><br>
                Captured <?php echo esc_html( wp_date( 'j M Y, H:i T', $s['created'] ) ); ?> by user #<?php echo (int) $s['by']; ?> · Rule <?php echo esc_html( $s['version'] ); ?></p>
            <p><a href="<?php echo esc_url( get_edit_post_link( $s['source']['id'] ) ); ?>">Review source</a> · <a href="<?php echo esc_url( get_edit_post_link( $s['target']['id'] ) ); ?>">Review replacement</a></p>
            <p><?php echo esc_html( $s['review']['reason'] ?: 'No editorial reason supplied.' ); ?></p>
            <p>Traffic period: <?php echo esc_html( $s['context']['period']['start'] . ' to ' . $s['context']['period']['end'] ); ?>.<br>
                Views: <?php echo esc_html( isset( $s['context']['metrics']['views'] ) ? $s['context']['metrics']['views'] : 'unknown' ); ?> · Search clicks: <?php echo esc_html( isset( $s['context']['metrics']['clicks'] ) ? $s['context']['metrics']['clicks'] : 'unknown' ); ?> · Backlinks: <?php echo esc_html( isset( $s['row']['backlinks'] ) ? $s['row']['backlinks'] : 'unknown' ); ?></p>
            <p>Sources: <?php echo esc_html( implode( ', ', $s['context']['sources'] ?? array() ) ?: 'None confirmed' ); ?>.<br>
                <?php echo empty( $s['assessment']['ready'] ) ? 'Coverage or timing is incomplete. Zero counts do not establish inactivity.' : 'Relevant coverage established; the additional action safeguards still apply.'; ?></p>
            <ul><?php foreach ( $s['holds'] as $hold ) : ?><li><?php echo esc_html( $hold ); ?></li><?php endforeach; ?></ul>
        <?php endif; ?></details>
    <?php endforeach; ?>
    <?php if ( ! $plans->posts ) : ?><p>No dry-run assessments saved yet.</p><?php endif; ?>
    <p><?php foreach ( array( -1 => 'Previous previews', 1 => 'Next previews' ) as $step => $label ) :
        if ( ( -1 === $step && 1 === $page ) || ( 1 === $step && count( $plans->posts ) <= 20 ) ) { continue; } ?>
        <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ace-seo-redirects', 'tab' => 'auto', 'preview_page' => $page + $step ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
    <?php endforeach; ?></p>
</section>
