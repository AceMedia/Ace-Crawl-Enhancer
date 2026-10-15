<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="ace-redirect-fields">
    <details class="ace-destination-search" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ace_seo_redirect_destinations' ) ); ?>" data-target="<?php echo esc_attr( $prefix ); ?>target">
        <summary>Search for a destination</summary>
        <p class="description">Find published content or an archive by title or name. Tick only the types you need for a quicker search.</p>
        <fieldset class="ace-destination-types"><legend>Search these types</legend>
        <?php foreach ( AceSeoRedirectDiscovery::types() as $key => $label ) : ?>
            <label><input type="checkbox" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, array( 'post:post', 'post:page', 'term:category' ), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
        <?php endforeach; ?>
        </fieldset>
        <p><label for="<?php echo esc_attr( $prefix ); ?>destination-search">Title or name</label><br><input type="search" id="<?php echo esc_attr( $prefix ); ?>destination-search" class="ace-destination-query regular-text" maxlength="100"> <button type="button" class="button ace-destination-find">Search destinations</button></p>
        <p class="ace-destination-status" role="status" aria-live="polite"></p>
        <ul class="ace-destination-results"></ul>
    </details>
    <p><label for="<?php echo esc_attr( $prefix ); ?>target"><strong>Destination URL</strong></label><br>
    <input class="large-text" id="<?php echo esc_attr( $prefix ); ?>target" name="<?php echo esc_attr( $prefix ); ?>target" type="text" value="<?php echo esc_attr( $s['target'] ); ?>" placeholder="/category/bingo/ or https://example.com/new-page/">
    <span class="description">Choose a search result above or enter a manual URL here. Leave blank to clear the redirect. Enter <code>gone</code> for 410 Gone.</span></p>
    <p><label for="<?php echo esc_attr( $prefix ); ?>code"><strong>Redirect type</strong></label><br>
    <select id="<?php echo esc_attr( $prefix ); ?>code" name="<?php echo esc_attr( $prefix ); ?>code">
    <?php foreach ( array( 301 => '301 - Permanent move', 302 => '302 - Temporary redirect', 307 => '307 - Temporary, preserve request method', 308 => '308 - Permanent, preserve request method' ) as $code => $label ) : ?>
        <option value="<?php echo (int) $code; ?>" <?php selected( $s['code'], $code ); ?>><?php echo esc_html( $label ); ?></option>
    <?php endforeach; ?>
    </select></p>
    <p><label for="<?php echo esc_attr( $prefix ); ?>expires"><strong>Stop redirecting at (optional)</strong></label><br>
    <input type="datetime-local" id="<?php echo esc_attr( $prefix ); ?>expires" name="<?php echo esc_attr( $prefix ); ?>expires" value="<?php echo esc_attr( $s['expires'] ? wp_date( 'Y-m-d\TH:i', $s['expires'], wp_timezone() ) : '' ); ?>">
    <button type="button" class="button ace-redirect-three-months" data-target="<?php echo esc_attr( $prefix ); ?>expires" data-date="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', strtotime( '+90 days' ), wp_timezone() ) ); ?>">In 90 days</button><br>
    <span class="description">Site timezone: <?php echo esc_html( wp_timezone_string() ); ?>. Blank means no expiry. At expiry the redirect stops and the published post becomes readable again. Permanent moves normally stay in place; age alone is not a reason to remove them.</span></p>
    <p><label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>trash" value="1" <?php checked( $s['trash'] ); ?>> Also move the source post to Trash when the redirect expires</label><br>
    <span class="description">Optional. The old URL will normally return 404. This runs on WordPress cron, which may be later than the expiry time. Restore from Posts → Trash if needed; WordPress may empty Trash after its configured retention period.</span></p>
    <p><label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>paused" value="1" <?php checked( $s['paused'] ); ?>> Pause this redirect and its scheduled Trash action</label></p>
</div>
