<?php
if ( ! defined( 'ABSPATH' ) || ! AceSeoRetentionPrototype::enabled() || ! current_user_can( 'manage_options' ) ) { return; }
global $wpdb;
$control = AceSeoRetentionQueue::control();
$jobs = $wpdb->get_results( 'SELECT * FROM ' . AceSeoRetentionQueue::table( 'jobs' ) . ' ORDER BY updated DESC,plan_id DESC LIMIT 20', ARRAY_A );
$events = $wpdb->get_results( 'SELECT plan_id,kind,recorded,actor FROM ' . AceSeoRetentionQueue::table( 'events' ) . ' ORDER BY id DESC LIMIT 20', ARRAY_A );
$notice = is_string( $_GET['queue_notice'] ?? null ) ? sanitize_key( $_GET['queue_notice'] ) : '';
?>
<section class="ace-redirect-card" id="retention-approved-queue">
    <h2>Approved redirect queue</h2>
    <?php if ( $notice ) : ?><p role="status"><strong><?php echo 'saved' === $notice ? 'Queue operation completed. Review the current state below.' : 'Operation refused (' . esc_html( $notice ) . '). Check scope, current evidence and permissions; create a new assessment if anything changed.'; ?></strong></p><?php endif; ?>
    <p><strong><?php echo $control['enabled'] ? 'Enabled for the listed sources only' : 'Paused'; ?></strong>. One approved pair per hourly run, at most three applications per UTC day. A 301 stays in place until explicitly changed or safely undone. No expiry, Trash or deletion is added by this queue.</p>
    <p>Configure scope first, then approve individual assessments. Every configuration change invalidates earlier approvals. Approval expires after at most 24 hours. The worker checks current permissions, evidence, destination and source again before applying anything. Dev noindex remains a hold; enabling this queue cannot override it.</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'ace_retention_queue' ); ?><input type="hidden" name="action" value="ace_retention_queue">
        <p><label for="retention-scope">Allowed source article IDs (up to 10, comma separated)</label><br><input class="large-text" id="retention-scope" name="scope" value="<?php echo esc_attr( implode( ',', $control['scope'] ) ); ?>"></p>
        <p><label for="retention-cap">Daily application limit</label> <input type="number" min="1" max="3" id="retention-cap" name="cap" value="<?php echo (int) $control['daily_cap']; ?>"></p>
        <p><label><input type="checkbox" name="enabled" value="1" <?php checked( $control['enabled'] ); ?>> Enable the guarded queue for this scope</label></p>
        <p><button class="button" name="operation" value="configure">Save queue controls</button> <button class="button" name="operation" value="pause" formnovalidate>Pause queue now</button> <button class="button" name="operation" value="tick" formnovalidate>Run one guarded check</button></p>
    </form>
    <h3>Latest jobs</h3>
    <table class="widefat striped"><thead><tr><th>Assessment / source</th><th>State</th><th>Review</th></tr></thead><tbody>
    <?php foreach ( $jobs as $job ) : $data = json_decode( $job['payload'], true ); ?>
        <tr><td>#<?php echo (int) $job['plan_id']; ?> · Source #<?php echo (int) $job['source_id']; ?><br>Approved by #<?php echo (int) $job['actor']; ?></td><td><strong><?php echo esc_html( $job['state'] ); ?></strong><br><?php echo esc_html( $data['reason'] ?? '' ); ?><br>Approval ends <?php echo esc_html( wp_date( 'j M Y H:i T', $job['expires'] ) ); ?></td><td>
            <a href="<?php echo esc_url( get_edit_post_link( $job['source_id'] ) ); ?>">Review source</a>
            <details><summary>Evidence and rule changes</summary><pre style="white-space:pre-wrap;max-width:48em;max-height:24em;overflow:auto"><?php echo esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></details>
            <?php if ( in_array( $job['state'], array( 'approved', 'applied' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ace_retention_queue' ); ?><input type="hidden" name="action" value="ace_retention_queue"><input type="hidden" name="plan" value="<?php echo (int) $job['plan_id']; ?>">
                <button class="button" name="operation" value="<?php echo 'applied' === $job['state'] ? 'undo' : 'cancel'; ?>"><?php echo 'applied' === $job['state'] ? 'Undo if unchanged' : 'Cancel approval'; ?></button>
            </form><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    <?php if ( ! $jobs ) : ?><tr><td colspan="3">No approved jobs. Held assessments cannot enter the queue.</td></tr><?php endif; ?>
    </tbody></table>
    <h3>Latest audit events</h3>
    <p>The database ledger retains every event, including the frozen approval, preflight and before/after rule. This view shows the latest 20.</p>
    <ul><?php foreach ( $events as $event ) : ?><li><?php echo esc_html( wp_date( 'j M Y H:i T', $event['recorded'] ) . ' · ' . $event['kind'] . ' · Assessment #' . $event['plan_id'] . ' · User #' . $event['actor'] ); ?></li><?php endforeach; ?></ul>
</section>
