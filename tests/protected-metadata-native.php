<?php
/** Run with wp eval-file against a disposable WordPress install. */
if (!defined('ABSPATH')) { exit(1); }
$created = [];
$checks = 0;
$assert = static function($condition, $message) use (&$checks) {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$seo = (new ReflectionClass('AceCrawlEnhancer'))->newInstanceWithoutConstructor();
$frontend = (new ReflectionClass('AceSeoFrontend'))->newInstanceWithoutConstructor();
try {
    foreach (['publish', 'password', 'private', 'draft'] as $case) {
        $id = wp_insert_post(['post_type'=>'post', 'post_status'=>$case === 'password' ? 'publish' : $case, 'post_password'=>$case === 'password' ? 'test-password' : '', 'post_title'=>'Metadata regression', 'post_content'=>'PRIVATE_BODY_SENTINEL', 'post_excerpt'=>'PRIVATE_EXCERPT_SENTINEL']);
        $created[] = $id;
        update_post_meta($id, '_ace_seo_metadesc', 'PRIVATE_MANUAL_SENTINEL');
        update_post_meta($id, '_ace_seo_title', 'PRIVATE_TITLE_SENTINEL');
        $GLOBALS['post'] = get_post($id);
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->is_singular = true;
        $GLOBALS['wp_query']->is_single = true;
        $GLOBALS['wp_query']->queried_object = $GLOBALS['post'];
        $GLOBALS['wp_query']->queried_object_id = $id;
        $GLOBALS['wp_query']->posts = [$GLOBALS['post']];
        $GLOBALS['wp_query']->post = $GLOBALS['post'];
        $GLOBALS['wp_query']->post_count = 1;
        foreach ([0, 1] as $user_id) {
            wp_set_current_user($user_id);
            $assert(AceCrawlEnhancer::current_request_has_public_metadata() === ($case === 'publish'), "$case public metadata boundary");
            if ($case === 'publish') {
                ob_start(); $seo->output_meta_description(); $html = ob_get_clean();
                $assert(strpos($html, 'PRIVATE_MANUAL_SENTINEL') !== false, 'Published manual metadata retained');
                $GLOBALS['wp_query']->is_preview = true;
                $assert(!AceCrawlEnhancer::current_request_has_public_metadata(), 'Preview blocked');
                $GLOBALS['wp_query']->is_preview = false;
                continue;
            }
            foreach (['output_meta_description', 'output_opengraph_tags', 'output_twitter_tags'] as $method) {
                ob_start(); $seo->$method(); $html = ob_get_clean();
                $assert($html === '', "$case $method emits nothing");
            }
            ob_start(); $frontend->output_schema_markup(); $html = ob_get_clean();
            $assert($html === '', "$case graph emits nothing");
            $assert($seo->filter_document_title_parts(['title'=>'Protected title']) === ['title'=>'Protected title'], "$case core title unchanged");
            $assert($seo->filter_title('Protected title') === 'Protected title', "$case legacy title unchanged");
        }
        if ($case === 'password') {
            require_once ABSPATH . WPINC . '/class-phpass.php';
            $_COOKIE['wp-postpass_' . COOKIEHASH] = (new PasswordHash(8, true))->HashPassword('test-password');
            $assert(!post_password_required($id), 'Correct password cookie unlocks body');
            $assert(!AceCrawlEnhancer::current_request_has_public_metadata(), 'Unlocked protected post still excludes public metadata');
            unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
        }
    }
    echo wp_json_encode(['checks'=>$checks, 'passed'=>true]) . "\n";
} finally {
    foreach ($created as $id) { wp_delete_post($id, true); }
    wp_set_current_user(0);
}
