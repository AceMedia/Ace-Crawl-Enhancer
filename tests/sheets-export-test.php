<?php
/**
 * Isolated Sheets boundary checks. No WordPress database or Google requests.
 * Run: php tests/sheets-export-test.php
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 404 );
    exit;
}


define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['sheets_options'] = array();
$GLOBALS['sheets_transients'] = array( 'ace_seo_sheets_token' => 'dummy-token' );
$GLOBALS['sheets_responses'] = array();
$GLOBALS['sheets_requests'] = array();
$GLOBALS['sheets_admin'] = true;
class WP_Error {
    public $code;
    public $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class SheetsTestRedirect extends Exception { public $url; public function __construct( $url ) { $this->url = $url; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['sheets_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['sheets_options'][ $key ] = $value; }
function get_transient( $key ) { return $GLOBALS['sheets_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['sheets_transients'][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['sheets_transients'][ $key ] ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_user_can( $capability ) { return $GLOBALS['sheets_admin']; }
function check_admin_referer( $action ) { return true; }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function get_current_user_id() { return 1; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function wp_safe_redirect( $url ) { throw new SheetsTestRedirect( $url ); }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_request( $url, $args ) {
    $GLOBALS['sheets_requests'][] = array( 'url' => $url, 'args' => $args );
    if ( ! $GLOBALS['sheets_responses'] ) { throw new RuntimeException( 'Unexpected request.' ); }
    return array_shift( $GLOBALS['sheets_responses'] );
}
function wp_remote_post( $url, $args ) {
    return array( 'code' => 200, 'body' => '{"access_token":"dummy-token","expires_in":3600}' );
}
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action ) {}
// WordPress provides this compatibility function when mbstring is unavailable.
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $offset, $length ) { return substr( $value, $offset, $length ); } }
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-sheets.php';

$failures = 0;
$checks = 0;
$check = static function ( $label, $ok ) use ( &$failures, &$checks ) {
    $checks++;
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $ok ) { $failures++; }
};
$response = static function ( $body, $code = 200 ) {
    $GLOBALS['sheets_responses'][] = array( 'body' => is_string( $body ) ? $body : json_encode( $body ), 'code' => $code );
};
$GLOBALS['sheets_options']['ace_seo_sheets'] = array( 'sheet_id' => 'dummy-spreadsheet-for-test-only' );

$response( array( 'replies' => array( array( 'addSheet' => array( 'properties' => array( 'title' => 'Store products' ) ) ) ) ) );
$tab = AceSeoSheets::add_tab( 'Store/products' );
$last = end( $GLOBALS['sheets_requests'] );
$body = json_decode( $last['args']['body'], true );
$check( 'generic post-type label creates only a new tab with a frozen header', 'Store products' === $tab && 'Store products' === $body['requests'][0]['addSheet']['properties']['title'] && 1 === $body['requests'][0]['addSheet']['properties']['gridProperties']['frozenRowCount'] && 1 === count( $body['requests'] ) );

$response( array( 'replies' => array() ) );
$check( 'missing tab confirmation is an error', is_wp_error( AceSeoSheets::add_tab( 'Test' ) ) );
$response( '<html>upstream failure</html>' );
$check( 'malformed successful HTTP response is an error', is_wp_error( AceSeoSheets::add_tab( 'Test' ) ) );
$response( array( 'error' => array( 'message' => 'Permission denied.' ) ), 403 );
$result = AceSeoSheets::add_tab( 'Test' );
$check( 'permission failure stays an error with Editor-access guidance', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'as an Editor' ) );

$response( array( 'updates' => array( 'updatedRows' => 2 ) ) );
$result = AceSeoSheets::append( "Editor's snapshot", array( array( '=1+1', null, 0 ), array( 'Café', 'plain text', 7 ) ) );
$last = end( $GLOBALS['sheets_requests'] );
$body = json_decode( $last['args']['body'], true );
$check( 'complete batches succeed with text kept RAW and null cells normalised', true === $result && false !== strpos( $last['url'], 'valueInputOption=RAW&insertDataOption=INSERT_ROWS' ) && false !== strpos( rawurldecode( $last['url'] ), "'Editor''s snapshot'!A1" ) && '=1+1' === $body['values'][0][0] && '' === $body['values'][0][1] && 0 === $body['values'][0][2] );
$response( array( 'updates' => array( 'updatedRows' => 1 ) ) );
$result = AceSeoSheets::append( 'Test', array( array( 1 ), array( 2 ) ) );
$check( 'partial batch confirmation cannot report success', is_wp_error( $result ) && 'ace_sheets_incomplete' === $result->get_error_code() );
$response( array() );
$check( 'missing batch confirmation cannot report success', is_wp_error( AceSeoSheets::append( 'Test', array( array( 1 ) ) ) ) );
$requests = count( $GLOBALS['sheets_requests'] );
$check( 'empty batch makes no network request', true === AceSeoSheets::append( 'Test', array() ) && $requests === count( $GLOBALS['sheets_requests'] ) );

$GLOBALS['sheets_admin'] = false;
ob_start();
AceSeoSheets::render_settings();
$check( 'non-administrators cannot render connection controls', '' === ob_get_clean() );
$GLOBALS['sheets_admin'] = true;
ob_start();
AceSeoSheets::render_settings();
$html = ob_get_clean();
$check( 'settings explain snapshot behaviour and accept a link or bare ID', false !== strpos( $html, 'It is a snapshot' ) && false !== strpos( $html, 'id="ace-seo-sheet"' ) && false !== strpos( $html, 'type="text"' ) );

$random_state = tempnam( sys_get_temp_dir(), 'ace-sheets-rand-' );
$previous_random_file = getenv( 'RANDFILE' );
putenv( 'RANDFILE=' . $random_state );
$key_resource = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $key_resource, $private_key );
putenv( false === $previous_random_file ? 'RANDFILE' : 'RANDFILE=' . $previous_random_file );
unlink( $random_state );
$saved_key = json_encode( array( 'client_email' => 'service@example.test', 'private_key' => $private_key ) );
$GLOBALS['sheets_options']['ace_seo_sheets'] = array( 'sheet_id' => 'dummy-spreadsheet-for-test-only', 'key' => $saved_key, 'extension_setting' => 'preserve-me' );
$_POST = array( 'sheet' => 'dummy-spreadsheet-for-test-only', 'key' => '' );
$response( array( 'properties' => array( 'title' => 'Test spreadsheet' ) ) );
try { AceSeoSheets::handle_settings(); } catch ( SheetsTestRedirect $redirect ) {
    $check( 'save returns to the new settings section', false !== strpos( $redirect->url, 'page=ace-seo-settings#retention/retention-sheets' ) );
}
$saved = $GLOBALS['sheets_options']['ace_seo_sheets'];
$check( 'blank key preserves the existing key and unrelated options', $saved_key === $saved['key'] && 'preserve-me' === $saved['extension_setting'] );
$_POST['key'] = '{invalid JSON';
try { AceSeoSheets::handle_settings(); } catch ( SheetsTestRedirect $redirect ) {}
$check( 'invalid replacement key preserves the working key', $saved_key === $GLOBALS['sheets_options']['ace_seo_sheets']['key'] );
echo $checks . ' checks, ' . $failures . " failures.\n";
exit( $failures ? 1 : 0 );
