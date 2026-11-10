<?php
/**
 * `wp acecrawl convert-names`: move a site's stored data from the old names to the acecrawl ones, once
 * its themes and plugins have been converted with bin/convert-legacy-names.py.
 *
 * Source repository only, like compat/legacy-names.php, whose name lists it uses. When it finishes it
 * marks the site converted, and from then on the plugin stops loading the compatibility layer there.
 */
defined( 'ABSPATH' ) || exit;

final class AceCrawlConvertNamesCommand {
	/** Options the plugin rewrites on load; a newer value under the new name never needs comparing. */
	const PLUGIN_MANAGED = array(
		'acecrawl_version',
		'acecrawl_sitemap_cache_version',
		'acecrawl_hits_db_version',
		'acecrawl_aggregate_views_schema',
		'acecrawl_retention_queue_schema',
		'acecrawl_activation_date',
	);

	/**
	 * Move stored options, network options and scheduled events to the new names, and list what is
	 * left for a person to change.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--keep-old]
	 * : Copy options to their new names but leave the old rows in place.
	 *
	 * [--force]
	 * : Delete old options even when they differ from the new ones (the new value is kept).
	 *
	 * ## EXAMPLES
	 *
	 * Run it after the new code is live everywhere (php-fpm reloaded), not while a deploy is switching.
	 *
	 *     wp acecrawl convert-names --dry-run
	 *     wp acecrawl convert-names
	 *     wp acecrawl convert-names --url=sub.example.com    # each site of a network
	 *
	 * @subcommand convert-names
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function convert_names( $args, $assoc_args ) {
		global $wpdb;
		$dry   = ! empty( $assoc_args['dry-run'] );
		$keep  = ! empty( $assoc_args['keep-old'] );
		$force = ! empty( $assoc_args['force'] );
		$rows  = array();
		$held  = 0;

		foreach ( AceCrawlLegacyNames::stored_options() as $old => $new ) {
			$old_row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $old_row ) {
				continue;
			}
			$new_exists = null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$old_value  = AceCrawlLegacyNames::normalise_value( $new, maybe_unserialize( $old_row->option_value ) );
			// Version and schema markers are rewritten by the plugin itself, so the new value is always the right one.
			$managed    = in_array( $new, self::PLUGIN_MANAGED, true );
			$differs    = ! $managed && $new_exists && maybe_serialize( get_option( $new ) ) !== maybe_serialize( $old_value );
			$delete     = ! $keep && ( ! $differs || $force );
			$held      += ( $differs && ! $force ) ? 1 : 0;
			$action     = ! $new_exists ? 'copy' : ( $differs ? 'keep new, old differs' : 'keep new' );
			$rows[]     = array( 'type' => 'option', 'old' => $old, 'new' => $new, 'action' => $action . ( $delete ? ', delete old' : ( $differs && ! $keep ? ', old kept (use --force)' : '' ) ) );
			if ( $dry ) {
				continue;
			}
			if ( ! $new_exists ) {
				add_option( $new, $old_value, '', in_array( $old_row->autoload, array( 'no', 'off', 'auto-off' ), true ) ? 'no' : 'yes' );
			}
			if ( $delete ) {
				// Through the options API, so a persistent object cache (Redis, Valkey) drops it too.
				delete_option( $old );
			}
		}

		foreach ( AceCrawlLegacyNames::stored_network_options() as $old => $new ) {
			$value      = get_site_option( $old, null );
			$current    = get_site_option( $new, null );
			$differs    = null !== $current && maybe_serialize( $current ) !== maybe_serialize( $value );
			$delete     = ! $keep && ( ! $differs || $force );
			$held      += ( $differs && ! $force ) ? 1 : 0;
			$rows[]     = array( 'type' => 'network option', 'old' => $old, 'new' => $new, 'action' => ( null === $current ? 'copy' : ( $differs ? 'keep new, old differs' : 'keep new' ) ) . ( $delete ? ', delete old' : '' ) );
			if ( $dry ) {
				continue;
			}
			if ( null === $current ) {
				add_site_option( $new, $value );
			}
			if ( $delete ) {
				delete_site_option( $old );
			}
		}

		foreach ( AceCrawlLegacyNames::stale_events() as list( $timestamp, $old, $event, $new ) ) {
			$rows[] = array( 'type' => 'cron', 'old' => $old . ( empty( $event['schedule'] ) ? '' : ' (' . $event['schedule'] . ')' ), 'new' => $new, 'action' => 'reschedule' );
		}

		// Old object cache keys and transients simply expire; constants are the site owner's to rename.
		foreach ( AceCrawlLegacyNames::INPUT_CONSTS as $old => $new ) {
			if ( defined( $old ) ) {
				$rows[] = array( 'type' => 'wp-config', 'old' => $old, 'new' => $new, 'action' => 'rename by hand' );
			}
		}

		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'old', 'new', 'action' ) );
		}
		if ( $dry ) {
			WP_CLI::success( sprintf( 'Dry run: %d change(s) found, nothing written.', count( $rows ) ) );
			return;
		}
		AceCrawlLegacyNames::fix_values();
		AceCrawlLegacyNames::move_events();
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$manual = count( array_filter( $rows, static function ( $row ) { return 'wp-config' === $row['type']; } ) );
		if ( $manual || $held ) {
			WP_CLI::warning( sprintf( 'Not marked converted yet: %d wp-config constant(s) to rename and %d old option(s) that differ from the new ones. Compare them, then run again (with --force to keep the new values).', $manual, $held ) );
			return;
		}
		update_option( 'acecrawl_names_converted', time(), false );
		delete_option( AceCrawlLegacyNames::MIGRATED );
		delete_option( AceCrawlLegacyNames::MIGRATED . '_names' );
		WP_CLI::success( sprintf( '%d change(s) made. The site is marked converted and no longer loads the compatibility layer.', count( $rows ) ) );
	}

	/**
	 * Load the compatibility layer again on this site, for example after rolling back a theme.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function unconvert( $args, $assoc_args ) {
		delete_option( 'acecrawl_names_converted' );
		WP_CLI::success( 'The compatibility layer loads again on this site.' );
	}
}

WP_CLI::add_command( 'acecrawl', 'AceCrawlConvertNamesCommand' );
