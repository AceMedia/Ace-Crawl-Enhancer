<?php
/**
 * Real WordPress integration checks. Only run against a disposable test installation.
 * ACE_SEO_REDIRECT_TESTS=1 php tests/redirects-test.php /path/to/wp-load.php
 * Creates fixture posts, exercises meta/cron/capabilities/forms, and removes them afterwards.
 */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ACE_SEO_REDIRECT_TESTS' ) || empty( $argv[1] ) ) {
    exit( "Use an isolated WordPress installation and explicitly set ACE_SEO_REDIRECT_TESTS=1.\n" );
}
require $argv[1];
if ( 'Ace Redirect Tests' !== get_option( 'blogname' ) || ! class_exists( 'AceSeoRedirects' ) ) {
    exit( "This test only runs in the disposable Ace Redirect Tests site with the plugin loaded.\n" );
}
$checks = 0; $failed = 0; $ids = array(); $users = array();
function verify_redirect( $label, $ok ) {
    global $checks, $failed;
    $checks++; if ( ! $ok ) { $failed++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function fixture_redirect_post( $title, $status = 'publish' ) {
    global $ids;
    $id = wp_insert_post( array( 'post_title' => $title, 'post_status' => $status, 'post_content' => 'Redirect test article.' ), true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    $ids[] = $id; return $id;
}
function redirect_http( $url, $method = 'GET', $body = null ) {
    $headers = array();
    $curl = curl_init( $url );
    curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADERFUNCTION => function ( $curl, $line ) use ( &$headers ) { $headers[] = $line; return strlen( $line ); } ) );
    if ( null !== $body ) { curl_setopt( $curl, CURLOPT_POSTFIELDS, $body ); }
    $body = curl_exec( $curl ); $status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ); curl_close( $curl );
    return array( $status, implode( '', $headers ), $body );
}
try {
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
    wp_set_current_user( $admins[0]->ID );
    $admin = get_current_user_id();
    $a = fixture_redirect_post( 'Redirect fixture A' );
    $b = fixture_redirect_post( 'Redirect fixture B' );
    $c = fixture_redirect_post( 'Redirect fixture C' );
    $draft = fixture_redirect_post( 'Redirect fixture draft', 'draft' );
    $target = get_permalink( $b );
    $future = wp_date( 'Y-m-d\TH:i', time() + 86400, wp_timezone() );
    $base = array( 'target' => $target, 'code' => 302, 'expires' => $future );
    update_post_meta( $a, AceSeoRetentionActions::META_REDIRECT, $target );
    $legacy = AceSeoRedirects::settings( $a );
    verify_redirect( 'legacy target remains an active 301 without invented age/expiry', 301 === $legacy['code'] && ! $legacy['created'] && ! $legacy['expires'] && AceSeoRedirects::active( $legacy ) );
    verify_redirect( 'save a temporary redirect', true === AceSeoRedirects::save( $a, $base ) );
    $s = AceSeoRedirects::settings( $a );
    verify_redirect( 'site-local expiry round trips to UTC', $future === wp_date( 'Y-m-d\TH:i', $s['expires'], wp_timezone() ) );
    verify_redirect( 'expiry event scheduled', (bool) wp_next_scheduled( AceSeoRedirects::HOOK, array( $a, $s['expires'] ) ) );
    verify_redirect( 'active immediately before expiry', AceSeoRedirects::active( $s, $s['expires'] - 1 ) );
    verify_redirect( 'inactive exactly at expiry without cron', ! AceSeoRedirects::active( $s, $s['expires'] ) );
    verify_redirect( 'pause works', true === AceSeoRedirects::save( $a, array( 'operation' => 'pause' ) ) && ! AceSeoRedirects::active( AceSeoRedirects::settings( $a ) ) );
    verify_redirect( 'pause cancels scheduled work', ! wp_next_scheduled( AceSeoRedirects::HOOK, array( $a, $s['expires'] ) ) );
    verify_redirect( 'resume works and preserves age', true === AceSeoRedirects::save( $a, array( 'operation' => 'resume' ) ) && $s['created'] === AceSeoRedirects::settings( $a )['created'] );
    foreach ( array( 'javascript:alert(1)', '//evil.test/path', 'not a URL', 'https://user:pass@example.test/', "https://example.test/a\r\nLocation:foo", 'https://example.test/%0aLocation:foo', get_permalink( $a ), get_permalink( $a ) . '#section', home_url( '/?p=' . $a ) ) as $bad ) {
        $before = AceSeoRedirects::settings( $a );
        verify_redirect( 'reject unsafe/self target ' . str_replace( array("\r", "\n"), '', $bad ), is_wp_error( AceSeoRedirects::save( $a, array_merge( $base, array( 'target' => $bad ) ) ) ) && $before === AceSeoRedirects::settings( $a ) );
    }
    foreach ( array( '2026-02-30T10:00', '2020-01-01T00:00', 'tomorrow', '2027-03-28T01:30' ) as $bad_date ) {
        verify_redirect( 'reject invalid/past/nonexistent local date ' . $bad_date, is_wp_error( AceSeoRedirects::save( $a, array_merge( $base, array( 'expires' => $bad_date ) ) ) ) );
    }
    verify_redirect( 'reject invalid redirect status', is_wp_error( AceSeoRedirects::save( $a, array_merge( $base, array( 'code' => 200 ) ) ) ) );
    verify_redirect( 'reject draft source', is_wp_error( AceSeoRedirects::save( $draft, $base ) ) );
    verify_redirect( 'reject Trash without expiry', is_wp_error( AceSeoRedirects::save( $a, array( 'target' => $target, 'trash' => 1 ) ) ) );
    AceSeoRedirects::save( $b, array( 'target' => get_permalink( $c ), 'code' => 301 ) );
    verify_redirect( 'reject known redirect chain on a changed rule', is_wp_error( AceSeoRedirects::save( $a, array_merge( $base, array( 'code' => 307 ) ) ) ) );
    verify_redirect( 'reject reverse loop', is_wp_error( AceSeoRedirects::save( $c, array( 'target' => get_permalink( $a ) ) ) ) );
    verify_redirect( 'pause still works after destination starts redirecting', true === AceSeoRedirects::save( $a, array( 'operation' => 'pause' ) ) && ! AceSeoRedirects::active( AceSeoRedirects::settings( $a ) ) );
    verify_redirect( 'resume rejects changed destination chain', is_wp_error( AceSeoRedirects::save( $a, array( 'operation' => 'resume' ) ) ) );
    AceSeoRedirects::save( $b, array( 'operation' => 'clear' ) );
    foreach ( array( 301, 302, 307, 308 ) as $code ) {
        verify_redirect( 'round-trip status ' . $code, true === AceSeoRedirects::save( $a, array( 'target' => $target, 'code' => $code ) ) && $code === AceSeoRedirects::settings( $a )['code'] );
    }
    $editor = wp_insert_user( array( 'user_login' => 'redirect-fixture-' . time(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) ); $users[] = $editor;
    wp_set_current_user( $editor );
    verify_redirect( 'editor cannot change redirects even with edit permission', is_wp_error( AceSeoRedirects::save( $a, $base ) ) );
    wp_set_current_user( $admin );
    AceSeoRedirects::save( $a, array_merge( $base, array( 'trash' => 1 ) ) );
    $s = AceSeoRedirects::settings( $a );
    AceSeoRedirects::expire( $a, $s['expires'] );
    verify_redirect( 'early cron cannot trash', 'publish' === get_post_status( $a ) );
    AceSeoRedirects::expire( $a, $s['expires'] - 20 );
    verify_redirect( 'stale cron cannot trash', 'publish' === get_post_status( $a ) );
    update_post_meta( $a, AceSeoRetentionActions::META_REDIRECT, get_permalink( $c ) );
    verify_redirect( 'legacy target change cancels destructive schedule', ! AceSeoRedirects::settings( $a )['trash'] && ! wp_next_scheduled( AceSeoRedirects::HOOK, array( $a, $s['expires'] ) ) );
    AceSeoRedirects::save( $a, array_merge( $base, array( 'trash' => 1 ) ) );
    $s = AceSeoRedirects::settings( $a ); $s['expires'] = time() - 10; update_post_meta( $a, AceSeoRedirects::META, $s );
    AceSeoRedirects::expire( $a, $s['expires'] );
    verify_redirect( 'opted-in expiry moves source to Trash', 'trash' === get_post_status( $a ) );
    verify_redirect( 'rule retained for review', 'Expired' === AceSeoRedirects::state( AceSeoRedirects::settings( $a ) ) );
    $completed = AceSeoRedirects::settings( $a )['completed']; AceSeoRedirects::expire( $a, $s['expires'] );
    verify_redirect( 'expiry is idempotent', $completed === AceSeoRedirects::settings( $a )['completed'] );
    wp_untrash_post( $a ); wp_update_post( array( 'ID' => $a, 'post_status' => 'publish' ) );
    AceSeoRedirects::save( $a, $base );
    $s = AceSeoRedirects::settings( $a ); $s['expires'] = time() - 10; update_post_meta( $a, AceSeoRedirects::META, $s );
    AceSeoRedirects::expire( $a, $s['expires'] );
    verify_redirect( 'ordinary expiry leaves post published', 'publish' === get_post_status( $a ) );
    $unchanged = AceSeoRedirects::settings( $a );
    verify_redirect( 'unrelated editor save preserves expired rule and audit state', true === AceSeoRedirects::save( $a, array( 'target' => $unchanged['target'], 'code' => $unchanged['code'], 'expires' => wp_date( 'Y-m-d\\TH:i', $unchanged['expires'], wp_timezone() ) ) ) && $unchanged === AceSeoRedirects::settings( $a ) );
    verify_redirect( 'expired cannot resume accidentally', is_wp_error( AceSeoRedirects::save( $a, array( 'operation' => 'resume' ) ) ) );
    AceSeoRedirects::save( $a, array_merge( $base, array( 'trash' => 1 ) ) );
    $s = AceSeoRedirects::settings( $a ); $s['expires'] = time() - 10; $s['by'] = $editor; update_post_meta( $a, AceSeoRedirects::META, $s );
    $user = get_user_by( 'id', $editor ); $user->set_role( 'subscriber' );
    AceSeoRedirects::expire( $a, $s['expires'] );
    verify_redirect( 'revoked deletion rights prevent Trash and show error', 'publish' === get_post_status( $a ) && (bool) AceSeoRedirects::settings( $a )['error'] );
    verify_redirect( 'clear removes target and lifecycle', true === AceSeoRedirects::save( $a, array( 'operation' => 'clear' ) ) && ! get_post_meta( $a, AceSeoRetentionActions::META_REDIRECT, true ) && ! get_post_meta( $a, AceSeoRedirects::META, true ) );
    $deny_schedule = function () { return false; };
    add_filter( 'pre_schedule_event', $deny_schedule );
    verify_redirect( 'schedule failure leaves rule unchanged', is_wp_error( AceSeoRedirects::save( $c, $base ) ) && ! AceSeoRedirects::settings( $c )['target'] );
    remove_filter( 'pre_schedule_event', $deny_schedule );
    verify_redirect( 'reject malformed array fields', is_wp_error( AceSeoRedirects::save( $a, array( 'target' => array( $target ) ) ) ) );
    // Optional real HTTP checks require a running server for this same isolated installation.
    if ( '1' === getenv( 'ACE_SEO_REDIRECT_HTTP_TESTS' ) && in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true ) ) {
        foreach ( array( 301, 302, 307, 308 ) as $code ) {
            AceSeoRedirects::save( $a, array( 'target' => $target, 'code' => $code ) );
            list( $status, $headers ) = redirect_http( get_permalink( $a ) );
            verify_redirect( 'HTTP ' . $code . ' with destination and no-store', $code === $status && false !== strpos( $headers, 'Location: ' . $target ) && false !== strpos( $headers, 'no-store' ) );
        }
        AceSeoRedirects::save( $a, array( 'operation' => 'pause' ) );
        verify_redirect( 'HTTP paused source returns 200', 200 === redirect_http( get_permalink( $a ) )[0] );
        AceSeoRedirects::save( $a, $base );
        $s = AceSeoRedirects::settings( $a ); $s['expires'] = time() - 10; update_post_meta( $a, AceSeoRedirects::META, $s );
        verify_redirect( 'HTTP expired source returns 200 before cron', 200 === redirect_http( get_permalink( $a ) )[0] );
        AceSeoRedirects::save( $a, array( 'target' => 'gone' ) );
        verify_redirect( 'HTTP legacy Gone remains 410', 410 === redirect_http( get_permalink( $a ) )[0] );
        AceSeoRedirects::save( $a, array( 'operation' => 'clear' ) );
        $response = redirect_http( admin_url( 'admin-post.php' ), 'POST', array( 'action' => 'ace_seo_redirect_save', 'ids[]' => $a, 'target' => $target ) );
        verify_redirect( 'unauthenticated admin POST cannot save', ! AceSeoRedirects::settings( $a )['target'] && $response[0] >= 300 );
    }
    ob_start(); AceSeoRedirects::fields( $a, 'ace_redirect_' ); $html = ob_get_clean();
    verify_redirect( 'post controls have labelled inputs and no nested form', false === strpos( $html, '<form' ) && false !== strpos( $html, 'for="ace_redirect_expires"' ) );
    $dom = new DOMDocument(); @$dom->loadHTML( $html ); $xpath = new DOMXPath( $dom );
    verify_redirect( 'new rule does not opt into expiry or Trash', '' === $xpath->query( '//input[@name="ace_redirect_expires"]' )->item(0)->getAttribute('value') && ! $xpath->query( '//input[@name="ace_redirect_trash"]' )->item(0)->hasAttribute('checked') );
} finally {
    wp_set_current_user( isset( $admin ) ? $admin : 0 );
    // Some tests deliberately overwrite timestamps to simulate elapsed time. Remove those
    // fixtures' original events too, without disturbing any other posts' schedules.
    foreach ( _get_cron_array() as $timestamp => $hooks ) {
        foreach ( $hooks[ AceSeoRedirects::HOOK ] ?? array() as $event ) {
            if ( in_array( (int) $event['args'][0], $ids, true ) ) {
                wp_unschedule_event( $timestamp, AceSeoRedirects::HOOK, $event['args'] );
            }
        }
    }
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $users as $id ) { wp_delete_user( $id ); }
}
echo "$checks redirect checks, $failed failures.\n";
exit( $failed ? 1 : 0 );
