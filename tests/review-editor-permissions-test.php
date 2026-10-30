<?php
/** Run only on the disposable review installation with ACE_SEO_REDIRECT_TESTS=1. */
if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ACE_SEO_REDIRECT_TESTS' ) || empty( $argv[1] ) ) { exit( "Disposable WordPress opt-in required.\n" ); }
require $argv[1];
if ( 'Ace Redirect Tests' !== get_option( 'blogname' ) ) { exit( "Disposable Ace Redirect Tests site required.\n" ); }
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-metabox.php';
$original_user = get_current_user_id();
$original_post = $_POST;
$taxonomy = 'ace_review_private';
register_taxonomy( $taxonomy, 'post', array( 'public' => true, 'capabilities' => array( 'manage_terms' => 'ace_review_edit_terms', 'edit_terms' => 'ace_review_edit_terms', 'delete_terms' => 'ace_review_edit_terms' ) ) );
$user_id = wp_insert_user( array( 'user_login' => 'ace-review-permissions-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$term = wp_insert_term( 'Review permission fixture', $taxonomy );
if ( is_wp_error( $user_id ) || is_wp_error( $term ) ) { throw new RuntimeException( 'Could not create disposable fixtures.' ); }
$term_id = $term['term_id'];
$checks = 0;
$check = static function ( $ok, $message ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$checks; echo "PASS $message\n"; };
try {
    $editor = ( new ReflectionClass( 'AceSEOMetabox' ) )->newInstanceWithoutConstructor();
    wp_set_current_user( $user_id );
    $_POST = array( 'ace_seo_taxonomy_nonce' => wp_create_nonce( 'ace_seo_taxonomy_save' ), 'ace_seo_title' => 'Changed title' );
    $editor->save_taxonomy_fields( $term_id );
    $check( '' === get_term_meta( $term_id, ACE_SEO_META_PREFIX . 'title', true ), 'Category managers cannot edit a separately protected taxonomy.' );
    $user = get_user_by( 'id', $user_id );
    $user->add_cap( 'ace_review_edit_terms' );
    wp_set_current_user( 0 ); wp_set_current_user( $user_id );
    $editor->save_taxonomy_fields( $term_id );
    $check( 'Changed title' === get_term_meta( $term_id, ACE_SEO_META_PREFIX . 'title', true ), 'The taxonomy editor can save its own SEO fields.' );
    $_POST['ace_seo_taxonomy_nonce'] = 'invalid'; $_POST['ace_seo_title'] = 'Invalid change';
    $editor->save_taxonomy_fields( $term_id );
    $check( 'Changed title' === get_term_meta( $term_id, ACE_SEO_META_PREFIX . 'title', true ), 'A valid capability does not bypass the nonce.' );
} finally {
    $_POST = $original_post;
    wp_set_current_user( $original_user );
    wp_delete_term( $term_id, $taxonomy );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( $user_id );
    unregister_taxonomy( $taxonomy );
}
echo "$checks editor permission checks passed.\n";
