<?php
if ( PHP_SAPI !== 'cli' ) {
    exit;
}
define( 'ABSPATH', __DIR__ . '/' );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-orphan-report.php';
$checks = 0;
function check( $ok, $message ) {
    global $checks;
    ++$checks;
    if ( ! $ok ) {
        throw new RuntimeException( $message );
    }
}

// A chain 60 levels deep under a reachable root, listed deepest first so a
// pass-limited walk would stall, plus a parent cycle that nothing reaches.
$rows = array();
for ( $id = 160; $id > 100; $id-- ) {
    $rows[] = array( 'ID' => $id, 'post_parent' => $id - 1 );
}
$rows[] = array( 'ID' => 500, 'post_parent' => 501 );
$rows[] = array( 'ID' => 501, 'post_parent' => 500 );
$reachable = AceSeoOrphanReport::propagate_to_children( array( 100 => true ), $rows );
check( isset( $reachable[160] ), 'A page 60 levels below a reachable root is reachable' );
check( ! isset( $reachable[500] ) && ! isset( $reachable[501] ), 'An unreached parent cycle stays orphaned and terminates' );

$nav = AceSeoOrphanReport::navigation_reachable(
    array(
        array( 'ID' => 1, 'post_type' => 'wp_navigation', 'post_content' => '<!-- wp:navigation-submenu {"label":"About","type":"page","id":42,"kind":"post-type"} --><!-- wp:navigation-link {"label":"Team","type":"page","id":43,"kind":"post-type"} /--><!-- /wp:navigation-submenu -->' ),
        array( 'ID' => 2, 'post_type' => 'wp_block', 'post_content' => '<!-- wp:page-list /-->' ),
    ),
    'page'
);
check( ! $nav['all'], 'An unused pattern with a page list does not mark everything reachable' );
check( in_array( 42, $nav['ids'], true ), 'A page linked only as a submenu parent is reachable' );
check( in_array( 43, $nav['ids'], true ), 'Navigation links are still read' );

$nav = AceSeoOrphanReport::navigation_reachable(
    array(
        array( 'ID' => 3, 'post_type' => 'wp_template_part', 'post_content' => '<!-- wp:block {"ref":4} /-->' ),
        array( 'ID' => 4, 'post_type' => 'wp_block', 'post_content' => '<!-- wp:block {"ref":5} /-->' ),
        array( 'ID' => 5, 'post_type' => 'wp_block', 'post_content' => '<!-- wp:page-list /-->' ),
    ),
    'page'
);
check( $nav['all'], 'A page list in a pattern referenced (through another pattern) from a template part still counts' );

$nav = AceSeoOrphanReport::navigation_reachable(
    array( array( 'ID' => 6, 'post_type' => 'wp_navigation', 'post_content' => '<!-- wp:page-list /-->' ) ),
    'post'
);
check( ! $nav['all'], 'A page list only covers pages' );

echo "orphan reachability: $checks checks passed\n";
