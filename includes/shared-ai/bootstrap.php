<?php
/** Shared Ace AI connection service. Bundled identically by independent consumers. */
defined( 'ABSPATH' ) || exit;
// Every bundled copy registers here, so only the newest one loads.
$GLOBALS['ace_ai_bundles']['1.4.0'][__DIR__] = true;
if ( ! function_exists( 'acemedia_ai_connection_service' ) ) {
	function acemedia_ai_connection_service() {
		$service = $GLOBALS['acemedia_ai_connection_service'] ?? null;
		// acecrawl-source-only:start
		// An older bundled copy registers the service under its earlier name.
		$service = $service ?? ( $GLOBALS['ace_ai_connection_service'] ?? null );
		// acecrawl-source-only:end
		return $service;
	}
	add_action( 'plugins_loaded', static function () {
		// acecrawl-source-only:start
		// An older copy's loader is already loading the newest bundled library, this one included.
		if ( function_exists( 'ace_ai_connection_service' ) ) {
			return;
		}
		// acecrawl-source-only:end
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
