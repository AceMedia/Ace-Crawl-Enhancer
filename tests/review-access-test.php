<?php
/** Regression checks for public recommendations and object-level AJAX permissions. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Post { public $ID; public $post_status = 'publish'; public $post_password = ''; public $public = true; public function __construct( $id ) { $this->ID = $id; } }
class WP_Error { public $code; public function __construct( $code, $message, $data ) { $this->code = $code; } }
class WP_REST_Request { public function get_param( $key ) { return 1; } }
class AceSeoRetentionActions { public static function is_retained( $id ) { return true; } }
class DeniedRequest extends Exception {}
function add_action() {}
function add_filter() {}
function absint( $value ) { return abs( (int) $value ); }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function is_post_publicly_viewable( $post ) { return $post->public; }
function get_the_category() { return array( (object) array( 'term_id' => 1 ) ); }
function wp_cache_get() { return $GLOBALS['cached']; }
function apply_filters( $hook, $value ) { return $GLOBALS['filtered'] ?? $value; }
function get_permalink( $id ) { return 'https://example.test/post/' . $id; }
function get_the_title( $id ) { return 'Post ' . $id; }
function rest_ensure_response( $data ) { return $data; }
function check_ajax_referer() { if ( ! $GLOBALS['nonce'] ) { throw new DeniedRequest( 'nonce' ); } }
function current_user_can( $capability, $id = null ) { return $GLOBALS['allowed'] && ( null === $id || $id === 1 ); }
function wp_unslash( $value ) { return $value; }
function wp_send_json_error( $message, $code = null ) { throw new DeniedRequest( 'permission' ); }
function esc_url_raw( $value ) { return $value; }
function sanitize_file_name( $value ) { return $value; }
require dirname( __DIR__ ) . '/includes/class-ace-seo-retention-front.php';
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-pagespeed.php';
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-ai-assistant.php';
$checks = 0;
function check_review( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
$GLOBALS['posts'] = array( 1 => new WP_Post( 1 ), 2 => new WP_Post( 2 ) );
$GLOBALS['cached'] = array( 2, 1 );
$request = new WP_REST_Request();
check_review( AceSeoRetentionFront::rest_next( $request )['next']['id'] === 2, 'Public destination remains available anonymously.' );
foreach ( array( 'draft', 'private', 'trash' ) as $status ) {
    $GLOBALS['posts'][2]->post_status = $status;
    check_review( null === AceSeoRetentionFront::rest_next( $request )['next'], 'Stale cache must not reveal ' . $status );
}
$GLOBALS['posts'][2]->post_status = 'publish';
$GLOBALS['posts'][2]->post_password = 'secret';
check_review( null === AceSeoRetentionFront::rest_next( $request )['next'], 'Password-protected destination.' );
$GLOBALS['filtered'] = 2;
check_review( null === AceSeoRetentionFront::rest_next( $request )['next'], 'Filtered destination still requires visibility.' );
$GLOBALS['posts'][1]->post_password = 'secret';
check_review( AceSeoRetentionFront::rest_next( $request ) instanceof WP_Error, 'Password-protected source.' );
$GLOBALS['posts'][1]->post_password = '';
$GLOBALS['posts'][1]->public = false;
check_review( AceSeoRetentionFront::rest_next( $request ) instanceof WP_Error, 'Non-public post type source.' );
$GLOBALS['filtered'] = null;
$GLOBALS['nonce'] = true;
$GLOBALS['allowed'] = true;
$_POST = array( 'post_id' => 2, 'image_url' => 'https://example.test/image.png' );
$pagespeed = ( new ReflectionClass( 'AceSEOPagespeed' ) )->newInstanceWithoutConstructor();
$assistant = ( new ReflectionClass( 'AceSEOAiAssistant' ) )->newInstanceWithoutConstructor();
foreach ( array( array( $pagespeed, 'get_pagespeed_report' ), array( $assistant, 'ajax_generate_titles' ), array( $assistant, 'ajax_save_image_to_library' ) ) as $handler ) {
    foreach ( array( true, false ) as $nonce ) {
        $GLOBALS['nonce'] = $nonce;
        try { call_user_func( $handler ); throw new RuntimeException( 'Request unexpectedly allowed.' ); }
        catch ( DeniedRequest $error ) { check_review( true, 'Denied before network or writes.' ); }
    }
}
echo "PASS $checks review access checks\n";
