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
		$dry  = ! empty( $assoc_args['dry-run'] );
		$keep = ! empty( $assoc_args['keep-old'] );
		// Run this once the deploy has fully switched (PHP reloaded): old code still serving requests would
		// recreate the old rows. A new option that already exists is never overwritten, since the new code
		// writes only the new names; a differing old value is reported and left for a person to compare.
		$rows = array();

		foreach ( AceCrawlLegacyNames::OPTIONS as $old => $new ) {
			$old_row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $old_row ) {
				continue;
			}
			$new_exists = null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$differs    = $new_exists && maybe_serialize( get_option( $new ) ) !== $old_row->option_value;
			$action     = $new_exists ? ( $differs ? 'keep new (old differed)' : 'keep new' ) : 'copy';
			$rows[]     = array( 'type' => 'option', 'old' => $old, 'new' => $new, 'action' => $action . ( $keep ? '' : ', delete old' ) );
			if ( $dry ) {
				continue;
			}
			if ( ! $new_exists ) {
				$value    = maybe_unserialize( $old_row->option_value );
				$autoload = in_array( $old_row->autoload, array( 'no', 'off', 'auto-off' ), true ) ? 'no' : 'yes';
				$new_exists ? update_option( $new, $value, $autoload ) : add_option( $new, $value, '', $autoload );
			}
			if ( ! $keep ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		if ( is_multisite() ) {
			foreach ( AceCrawlLegacyNames::OPTIONS as $old => $new ) {
				$value = get_site_option( $old, null );
				if ( null === $value ) {
					continue;
				}
				$rows[] = array( 'type' => 'network option', 'old' => $old, 'new' => $new, 'action' => 'copy if missing' . ( $keep ? '' : ', delete old' ) );
				if ( ! $dry ) {
					if ( null === get_site_option( $new, null ) ) {
						add_site_option( $new, $value );
					}
					if ( ! $keep ) {
						delete_site_option( $old );
					}
				}
			}
		}

		$cron = _get_cron_array();
		foreach ( is_array( $cron ) ? $cron : array() as $timestamp => $hooks ) {
			foreach ( AceCrawlLegacyNames::CRONS as $old => $new ) {
				foreach ( $hooks[ $old ] ?? array() as $event ) {
					$rows[] = array( 'type' => 'cron', 'old' => $old, 'new' => $new, 'action' => 'reschedule' );
				}
			}
		}
		if ( ! $dry ) {
			AceCrawlLegacyNames::move_events();
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
		$manual = count( array_filter( $rows, static function ( $row ) { return 'wp-config' === $row['type']; } ) );
		if ( $dry ) {
			WP_CLI::success( sprintf( 'Dry run: %d change(s) found, nothing written.', count( $rows ) ) );
			return;
		}
		if ( $manual ) {
			WP_CLI::warning( 'Rename the wp-config.php constants above, then run this again to mark the site converted.' );
			return;
		}
		update_option( 'acecrawl_names_converted', time(), false );
		delete_option( AceCrawlLegacyNames::MIGRATED );
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
