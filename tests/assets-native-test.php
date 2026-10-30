<?php
/** Run inside a disposable WordPress: wp eval-file tests/assets-native-test.php */
$_SERVER['HTTP_HOST'] = '127.0.0.1';
wp_set_current_user( 1 );
require_once dirname( __DIR__ ) . '/includes/admin/class-ace-seo-post-columns.php';
set_current_screen( 'edit-post' );
ob_start();
AceSeoPostColumns::print_export_script();
$early = ob_get_clean();
if ( '' !== trim( $early ) || ! wp_script_is( 'ace-seo-post-columns-script-1', 'enqueued' ) ) { throw new Exception( 'Export assets did not enqueue cleanly' ); }
ob_start(); do_action( 'admin_head' ); ob_end_clean();
ob_start(); AceSeoAssets::style( 'ace-native-css', 'assets/css/ace-seo-settings-style-1.css' ); $style = ob_get_clean();
if ( false === strpos( $style, 'ace-native-css-css' ) || false === strpos( $style, 'ace-seo-settings-style-1.css' ) ) { throw new Exception( 'Late style missing' ); }
ob_start(); AceSeoAssets::style( 'ace-native-css', 'assets/css/ace-seo-settings-style-1.css' ); $duplicate = ob_get_clean();
if ( '' !== $duplicate ) { throw new Exception( 'Style duplicated' ); }
ob_start(); wp_print_footer_scripts(); $scripts = ob_get_clean();
if ( false === strpos( $scripts, 'ace-seo-post-columns-script-1-js-after' ) || false === strpos( $scripts, 'Export CSV' ) ) { throw new Exception( 'Export script missing' ); }
echo "PASS native WordPress export enqueue, late CSS printing, deduplication and footer JS.\n";
