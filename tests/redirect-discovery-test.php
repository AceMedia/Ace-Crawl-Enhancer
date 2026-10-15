<?php
/** Disposable real-WordPress tests; same opt-in and installation as redirects-test.php. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ACE_SEO_REDIRECT_TESTS' ) || empty( $argv[1] ) ) { exit( "Set ACE_SEO_REDIRECT_TESTS=1 and pass the disposable wp-load.php.\n" ); }
require $argv[1];
if ( 'Ace Redirect Tests' !== get_option( 'blogname' ) ) { exit( "Disposable Ace Redirect Tests site required.\n" ); }
$ids = array(); $terms = array(); $checks = 0;
$saved_options = get_option( AceSeoRetentionActions::OPTION, null );
$saved_history = get_option( AceSeoRetentionReport::HISTORY_OPTION, null );
function discovery_check( $ok, $label ) { global $checks; $checks++; if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS $label\n"; }
function discovery_render( $tab ) { $_GET = array( 'tab' => $tab ); ob_start(); AceSeoRedirects::render(); return ob_get_clean(); }
try {
    $admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
    wp_set_current_user( $admin );
    register_post_type( 'discovery_book', array( 'public' => true, 'label' => 'Books' ) );
    register_post_type( 'discovery_hidden', array( 'public' => false ) );
    register_taxonomy( 'discovery_topic', 'discovery_book', array( 'public' => true, 'label' => 'Topics' ) );
    foreach ( array( array( 'post', 'publish', 'Discoveryneedle article' ), array( 'page', 'publish', 'Discoveryneedle page' ), array( 'discovery_book', 'publish', 'Discoveryneedle book' ), array( 'post', 'draft', 'Discoveryneedle draft' ), array( 'post', 'private', 'Discoveryneedle private' ), array( 'post', 'publish', 'Content-only match' ) ) as $fixture ) {
        $ids[] = wp_insert_post( array( 'post_type' => $fixture[0], 'post_status' => $fixture[1], 'post_title' => $fixture[2], 'post_content' => 'Discoveryneedle inside content' ) );
    }
    foreach ( array( 'category', 'post_tag', 'discovery_topic' ) as $taxonomy ) { $term = wp_insert_term( 'Discoveryneedle ' . $taxonomy, $taxonomy ); $terms[ $taxonomy ] = $term['term_id']; }
    $types = AceSeoRedirectDiscovery::types();
    discovery_check( isset( $types['post:page'], $types['post:discovery_book'], $types['term:category'], $types['term:post_tag'], $types['term:discovery_topic'] ) && ! isset( $types['post:discovery_hidden'], $types['post:attachment'] ), 'Public custom types and taxonomies available; hidden types excluded' );
    $find = static function ( $types ) { return AceSeoRedirectDiscovery::destinations( array( 'search' => 'Discoveryneedle', 'types' => $types ) ); };
    $result = $find( array( 'post:post' ) );
    discovery_check( 1 === count( $result['items'] ) && get_permalink( $ids[0] ) === $result['items'][0]['url'], 'Title-only post search excludes draft, private, content-only matches and other types' );
    $front_search = static function() { return ' AND 1=0 '; };
    add_filter( 'posts_search', $front_search, 99 );
    $literal = AceSeoRedirectDiscovery::destinations( array( 'search' => 'Discoveryneedle article', 'types' => array( 'post:post' ) ) );
    discovery_check( 1 === count( $literal['items'] ) && get_permalink( $ids[0] ) === $literal['items'][0]['url'], 'Multiword destination title bypasses front-end fulltext filters' );
    $normal_search = new WP_Query( array( 's' => 'Discoveryneedle', 'post_type' => 'post' ) );
    discovery_check( ! $normal_search->posts, 'Ordinary search still uses the site search filter' );
    remove_filter( 'posts_search', $front_search, 99 );
    discovery_check( 1 === count( $find( array( 'post:page' ) )['items'] ), 'Page-only search honours type filter' );
    discovery_check( 3 === count( $find( array( 'term:category', 'term:post_tag', 'term:discovery_topic' ) )['items'] ), 'Categories, tags and custom taxonomy return archive URLs' );
    discovery_check( 6 === count( $find( array_keys( $types ) )['items'] ), 'Mixed search returns all requested content kinds' );
    update_post_meta( $ids[0], AceSeoRetentionActions::META_REDIRECT, get_permalink( $ids[1] ) );
    discovery_check( $find( array( 'post:post' ) )['items'][0]['blocked'], 'Redirecting posts cannot be selected as destinations' );
    update_term_meta( $terms['category'], '_ace_seo_redirect', (string) $terms['post_tag'] );
    discovery_check( $find( array( 'term:category' ) )['items'][0]['blocked'], 'Archive rules are flagged before choosing a destination' );
    foreach ( array( array(), array( 'post:discovery_hidden' ), array( 'term:invalid' ), array( array() ) ) as $invalid ) { discovery_check( is_wp_error( $find( $invalid ) ), 'Invalid or empty type selection is rejected' ); }
    discovery_check( is_wp_error( AceSeoRedirectDiscovery::destinations( array( 'search' => array(), 'types' => array( 'post:post' ) ) ) ), 'Malformed search rejected' );
    discovery_check( is_wp_error( AceSeoRedirectDiscovery::destinations( array( 'search' => 'x', 'types' => array( 'post:post' ) ) ) ), 'One-character query rejected' );
    wp_set_current_user( 0 );
    discovery_check( is_wp_error( $find( array( 'post:post' ) ) ), 'Anonymous search denied' );
    wp_set_current_user( $admin );
    for ( $i = 0; $i < 21; $i++ ) { $ids[] = wp_insert_post( array( 'post_title' => 'Discoverylimit ' . $i, 'post_status' => 'publish' ) ); }
    $bounded = AceSeoRedirectDiscovery::destinations( array( 'search' => 'Discoverylimit', 'types' => array( 'post:post' ) ) );
    discovery_check( 20 === count( $bounded['items'] ) && $bounded['more'], 'Search is capped and reports additional matches' );
    $row = array( 'bucket' => 'consolidate', 'reason' => 'Fixture: impressions without readers; editorial review needed.', 'views' => 0, 'clicks' => 0, 'impressions' => 20, 'window' => 90, 'built' => time(), 'trend' => 'falling' );
    update_post_meta( $ids[2], AceSeoRetentionReport::META, $row );
    update_option( AceSeoRetentionReport::HISTORY_OPTION, array( array( 'finished' => time(), 'days' => 90, 'years' => 3, 'counts' => array( 'consolidate' => 1 ), 'moves' => array( 'keep>consolidate' => 1 ) ) ) );
    $before = get_post_meta( $ids[2] ); $before_cron = _get_cron_array();
    $html = discovery_render( 'suggestions' );
    discovery_check( false !== strpos( $html, 'Discoveryneedle book' ) && false !== strpos( $html, $row['reason'] ) && false !== strpos( $html, 'Choose a destination' ), 'Saved consolidation suggestions display evidence and manual review link' );
    discovery_check( false !== strpos( $html, 'How recommendations have changed' ) && false !== strpos( $html, 'Entered this group' ), 'Existing completed-check history displayed' );
    discovery_check( $before === get_post_meta( $ids[2] ) && $before_cron === _get_cron_array(), 'Viewing recommendations does not change posts or schedules' );
    $row['bucket'] = 'keep'; update_post_meta( $ids[2], AceSeoRetentionReport::META, $row );
    discovery_check( false === strpos( discovery_render( 'suggestions' ), 'Discoveryneedle book' ), 'Article leaves suggestion list when its saved recommendation changes' );
    $o = AceSeoRetentionActions::options(); $o['auto_build'] = 1; update_option( AceSeoRetentionActions::OPTION, $o );
    $html = discovery_render( 'auto' );
    discovery_check( false !== strpos( $html, 'Approved redirect queue' ) && false !== strpos( $html, 'Manage archive' ) && false === strpos( $html, 'dev review' ) && false === strpos( $html, 'not implemented yet' ), 'Auto view exposes the released review workflow alongside archive management' );
    discovery_check( ! AceSeoRetentionQueue::control()['enabled'] && ! wp_next_scheduled( AceSeoRetentionQueue::HOOK ), 'Opening released Auto view never enables or schedules processing' );
    discovery_check( ! get_post_meta( $ids[2], AceSeoRetentionActions::META_REDIRECT, true ), 'Weekly report option and viewing auto tab never create a redirect' );
    $_GET = array(); ob_start(); AceSeoRedirects::fields( 0, 'editor_' ); $fields = ob_get_clean();
    discovery_check( false === strpos( $fields, '<form' ) && false !== strpos( $fields, 'manual URL' ) && false !== strpos( $fields, 'editor_destination-search' ), 'Shared editor search retains manual input without nesting forms' );
    echo "$checks discovery checks passed.\n";
} finally {
    wp_set_current_user( $admin ?? 0 );
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    foreach ( $terms as $taxonomy => $id ) { wp_delete_term( $id, $taxonomy ); }
    if ( null === $saved_options ) { delete_option( AceSeoRetentionActions::OPTION ); } else { update_option( AceSeoRetentionActions::OPTION, $saved_options ); }
    if ( null === $saved_history ) { delete_option( AceSeoRetentionReport::HISTORY_OPTION ); } else { update_option( AceSeoRetentionReport::HISTORY_OPTION, $saved_history ); }
}
