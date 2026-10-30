<?php
/** Exercise frontend REST bootstrap without WP_CLI, WP_ADMIN or an early REST_REQUEST flag. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ACE_SEO_REDIRECT_TESTS' ) || empty( $argv[1] ) ) { exit( "Disposable WordPress opt-in required.\n" ); }
require $argv[1];
if ( 'Ace Redirect Tests' !== get_option( 'blogname' ) ) { exit( "Disposable Ace Redirect Tests site required.\n" ); }
if ( is_admin() || defined( 'WP_CLI' ) || defined( 'REST_REQUEST' ) ) { throw new RuntimeException( 'A normal frontend bootstrap is required.' ); }
$server = rest_get_server();
$routes = $server->get_routes();
$route = '/ace-seo/v1/performance/history/(?P<id>\d+)';
if ( ! isset( $routes[ $route ] ) ) { throw new RuntimeException( 'Performance route was not registered.' ); }
$original_user = get_current_user_id();
try {
    wp_set_current_user( 0 );
    $response = $server->dispatch( new WP_REST_Request( 'GET', '/ace-seo/v1/performance/history/2' ) );
    if ( 401 !== $response->get_status() ) { throw new RuntimeException( 'Anonymous history should be forbidden, not missing.' ); }
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
    wp_set_current_user( $admins[0]->ID );
    $response = $server->dispatch( new WP_REST_Request( 'GET', '/ace-seo/v1/performance/history/2' ) );
    if ( 200 !== $response->get_status() ) { throw new RuntimeException( 'An authorised editor should receive the history response.' ); }
} finally { wp_set_current_user( $original_user ); }
echo "PASS frontend REST route registration, anonymous denial and authorised response\n";
