<?php
/** Run: php tests/assets-buffer-test.php */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ACECRAWL_URL', 'https://example.test/wp-content/plugins/ace-crawl-enhancer/' );
define( 'ACECRAWL_VERSION', 'test' );
$GLOBALS['scripts'] = $GLOBALS['styles'] = $GLOBALS['hooks'] = $GLOBALS['printed'] = array();
$GLOBALS['head_done'] = false;
function wp_enqueue_script( $handle, $url = '', $deps = array(), $version = '', $footer = false ) { $GLOBALS['scripts'][$handle] = array( 'url' => $url, 'deps' => $deps, 'footer' => $footer ); }
function wp_register_script( $handle, $url, $deps, $version, $footer ) { $GLOBALS['registered'][$handle] = array( 'deps' => $deps, 'footer' => $footer ); }
function wp_add_inline_script( $handle, $code ) { $GLOBALS['inline'][$handle][] = $code; }
function wp_script_is( $handle, $status ) { return isset( $GLOBALS['scripts'][$handle] ); }
function wp_enqueue_style( $handle, $url = '', $deps = array(), $version = '' ) { $GLOBALS['styles'][$handle] = $url; }
function wp_register_style( $handle, $url, $deps, $version ) {}
function wp_add_inline_style( $handle, $css ) { $GLOBALS['css'][$handle][] = $css; }
function wp_style_is( $handle, $status ) { return isset( $GLOBALS['styles'][$handle] ); }
function wp_print_styles( $handles ) { foreach ( $handles as $handle ) { $GLOBALS['printed'][$handle] = true; } }
function did_action( $action ) { return $GLOBALS['head_done'] ? 1 : 0; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter( $hook, $callback, $priority = 10 ) { add_action( $hook, $callback, $priority ); }
function wp_create_nonce( $action ) { return 'safe-nonce'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function __( $value, $domain = '' ) { return $value; }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }
class WP_Screen { public $base = 'edit'; public $post_type = 'post'; }
function get_post_types( $args, $output ) { return array( 'post' => (object) array( 'name' => 'post' ), 'page' => (object) array( 'name' => 'page' ) ); }
function is_post_type_viewable( $type ) { return true; }
function apply_filters( $tag, $value ) { return $value; }
function asset_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS $message\n"; }
require dirname( __DIR__ ) . '/includes/class-ace-seo-assets.php';
require dirname( __DIR__ ) . '/includes/frontend/class-ace-seo-frontend.php';
require dirname( __DIR__ ) . '/includes/admin/class-ace-seo-post-columns.php';
ob_start();
AceCrawlAssets::script( 'ace-test', 'assets/js/admin.js', array( 'jquery' ) );
AceCrawlAssets::inline_script( 'ace-inline', 'window.test=true;' );
AceCrawlAssets::inline_script( 'ace-inline', 'window.test=true;' );
AceCrawlAssets::style( 'ace-css', 'assets/css/admin.css' );
$output = ob_get_clean();
asset_check( '' === $output, 'Asset registration does not inject raw markup.' );
asset_check( true === $GLOBALS['scripts']['ace-test']['footer'], 'Scripts are queued in the footer.' );
asset_check( 1 === count( $GLOBALS['inline']['ace-inline'] ), 'Repeated components do not duplicate inline handlers.' );
asset_check( empty( $GLOBALS['printed'] ), 'Early styles stay queued for the document head.' );
$GLOBALS['head_done'] = true;
AceCrawlAssets::style( 'ace-late', 'assets/css/admin.css' );
asset_check( isset( $GLOBALS['printed']['ace-late'] ), 'Late metabox styles use WordPress style printing.' );
$GLOBALS['scripts'] = array();
AceCrawlPostColumns::print_export_script();
asset_check( empty( $GLOBALS['scripts'] ), 'Export scripts are absent from unrelated screens.' );
$GLOBALS['screen'] = new WP_Screen();
ob_start(); AceCrawlPostColumns::print_export_script(); $export_output = ob_get_clean();
asset_check( '' === trim( $export_output ), 'Export control queues assets without raw script tags.' );
asset_check( isset( $GLOBALS['inline']['ace-seo-post-columns-script-1'] ), 'Post list export behaviour is queued on supported screens.' );
file_put_contents( sys_get_temp_dir() . '/ace-review-export-script.js', $GLOBALS['inline']['ace-seo-post-columns-script-1'][0] );
$frontend = new AceCrawlFrontend();
asset_check( isset( $GLOBALS['hooks']['wp_template_enhancement_output_buffer'] ) && ! isset( $GLOBALS['hooks']['template_redirect'] ), 'Core owns the template buffer lifecycle.' );
$before = ob_get_level();
$html = '<html><head><meta property="og:title" content="old"><meta data-ace-seo="1" property="og:title" content="$1 canonical"><meta property="og:description" content="keep"></head><body><meta property="og:title" content="body"></body></html>';
$filtered = $frontend->filter_head_output( $html );
asset_check( false === strpos( $filtered, 'content="old"' ) && false !== strpos( $filtered, 'content="$1 canonical"' ), 'Canonical metadata replaces duplicate tags without expanding dollar sequences.' );
asset_check( false !== strpos( $filtered, 'content="body"' ) && false !== strpos( $filtered, 'content="keep"' ), 'Body markup and metadata without an ACE replacement are preserved.' );
asset_check( $before === ob_get_level(), 'Filtering never opens or closes another component buffer.' );
$fragment = '<p>No head</p>';
asset_check( $fragment === $frontend->filter_head_output( $fragment ), 'Fragments remain untouched.' );
$huge = '<!doctype html><html><head><title>x</title><script>' . str_repeat( 'a ', 1500000 ) . '</script></head><body>kept</body></html>';
asset_check( $huge === $frontend->filter_head_output( $huge ), 'A head past the backtrack limit keeps the page.' );
$late = '<html><head><title>T</title><style>' . str_repeat( 'x', 5000 ) . '</style><meta property="og:site_name" content="S"><meta property="og:image" content="i"><meta name="twitter:card" content="c"><meta property="og:site_name" content="S"><meta data-ace-seo="1" property="og:title" content="Real"></head><body></body></html>';
$hoisted = $frontend->filter_head_output( $late );
asset_check( strpos( $hoisted, 'og:title' ) < strpos( $hoisted, '<style>' ) && strpos( $hoisted, 'twitter:card' ) < strpos( $hoisted, '<style>' ) && strpos( $hoisted, '</title>' ) < strpos( $hoisted, 'og:' ), 'Share tags sit just after the title, ahead of inline styles.' );
asset_check( 1 === substr_count( $hoisted, 'og:site_name' ) && strpos( $hoisted, 'og:image' ) < strpos( $hoisted, 'og:title' ), 'Repeated share tags print once and keep their order.' );
