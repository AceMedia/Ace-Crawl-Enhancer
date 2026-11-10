<?php
/** Shared Ace AI connection service. Bundled identically by independent consumers. */
defined( 'ABSPATH' ) || exit;
// The registry key is shared with older copies of this library bundled by other plugins, so they agree on one version.
$GLOBALS['ace_ai_bundles']['1.4.0'][__DIR__] = true;
if ( ! function_exists( 'acemedia_ai_connection_service' ) ) {
	function acemedia_ai_connection_service() {
		return $GLOBALS['acemedia_ai_connection_service'] ?? ( $GLOBALS['ace_ai_connection_service'] ?? null );
	}
	add_action( 'plugins_loaded', static function () {
		// An older copy's loader is already loading the newest bundled library, this one included.
		if ( function_exists( 'ace_ai_connection_service' ) ) {
			return;
		}
		$versions = array_keys( $GLOBALS['ace_ai_bundles'] );
		usort( $versions, 'version_compare' );
		$paths = array_keys( $GLOBALS['ace_ai_bundles'][ end( $versions ) ] );
		sort( $paths );
		require_once $paths[0] . '/service.php';
		$GLOBALS['acemedia_ai_connection_service'] = new \AceMedia\SharedAI\V1\Service();
		require_once $paths[0] . '/admin.php';
		\AceMedia\SharedAI\V1\Admin::register();
	}, -100 );
}
