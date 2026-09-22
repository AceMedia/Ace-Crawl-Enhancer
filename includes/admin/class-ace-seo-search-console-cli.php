<?php
/**
 * WP-CLI commands for Google Search Console (Site Kit-gated).
 *
 * Mirrors the REST surface in AceSEOSearchConsole:
 *   wp ace-crawl gsc sitemaps
 *   wp ace-crawl gsc submit <feed-url>
 *   wp ace-crawl gsc resubmit-all
 *   wp ace-crawl gsc inspect <url>
 *   wp ace-crawl gsc queries [--url=<url>] [--dimension=query|page] [--rows=<n>] [--days=<days>] [--totals]
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOSearchConsoleCli {

    /**
     * Register the sub-subcommands. Hyphenated names (resubmit-all) cannot be
     * PHP method names, so each maps explicitly to a callable.
     */
    public static function register() {
        if ( ! class_exists( 'AceSEOSearchConsole' ) ) {
            return;
        }
        $cli = new self();
        WP_CLI::add_command( 'ace-crawl gsc sitemaps', array( $cli, 'sitemaps' ) );
        WP_CLI::add_command( 'ace-crawl gsc submit', array( $cli, 'submit' ) );
        WP_CLI::add_command( 'ace-crawl gsc resubmit-all', array( $cli, 'resubmit_all' ) );
        WP_CLI::add_command( 'ace-crawl gsc inspect', array( $cli, 'inspect' ) );
        WP_CLI::add_command( 'ace-crawl gsc queries', array( $cli, 'queries' ) );
    }

    private function require_ready() {
        if ( ! AceSEOSearchConsole::is_ready() ) {
            WP_CLI::error( 'Search Console is not connected in Site Kit (no active plugin or no property).' );
        }
    }

    private function bail_on_error( $result ) {
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        return $result;
    }

    /**
     * List the sitemaps Search Console knows about.
     *
     * ## OPTIONS
     *
     * [--refresh]
     * : Skip the cached list and fetch fresh from Google.
     */
    public function sitemaps( $args, $assoc_args ) {
        $this->require_ready();
        $force = isset( $assoc_args['refresh'] );
        $rows  = $this->bail_on_error( AceSEOSearchConsole::list_sitemaps( $force ) );

        if ( empty( $rows ) ) {
            WP_CLI::success( 'No sitemaps have been submitted to Search Console yet.' );
            return;
        }

        $items = array();
        foreach ( $rows as $row ) {
            $submitted = 0;
            $indexed   = 0;
            foreach ( $row['contents'] as $content ) {
                $submitted += $content['submitted'];
                $indexed   += $content['indexed'];
            }
            $items[] = array(
                'path'           => $row['path'],
                'submitted'      => $submitted,
                'indexed'        => $indexed,
                'errors'         => $row['errors'],
                'warnings'       => $row['warnings'],
                'pending'        => $row['isPending'] ? 'yes' : 'no',
                'lastDownloaded' => substr( $row['lastDownloaded'], 0, 10 ),
            );
        }

        WP_CLI\Utils\format_items(
            'table',
            $items,
            array( 'path', 'submitted', 'indexed', 'errors', 'warnings', 'pending', 'lastDownloaded' )
        );
    }

    /**
     * Submit a single sitemap feed URL.
     *
     * ## OPTIONS
     *
     * <feed>
     * : The fully-qualified sitemap URL to submit.
     */
    public function submit( $args, $assoc_args ) {
        $this->require_ready();
        $feed = isset( $args[0] ) ? $args[0] : '';
        if ( '' === $feed ) {
            WP_CLI::error( 'Provide a sitemap URL: wp ace-crawl gsc submit <feed-url>' );
        }
        $this->bail_on_error( AceSEOSearchConsole::submit_sitemap( $feed ) );
        WP_CLI::success( 'Submitted: ' . $feed );
    }

    /**
     * Discover and resubmit every declared sitemap.
     */
    public function resubmit_all( $args, $assoc_args ) {
        $this->require_ready();
        $results = $this->bail_on_error( AceSEOSearchConsole::resubmit_all() );

        $items = array();
        foreach ( $results as $row ) {
            $items[] = array(
                'feed'   => $row['feed'],
                'ok'     => $row['ok'] ? 'yes' : 'no',
                'error'  => $row['error'],
            );
        }
        WP_CLI\Utils\format_items( 'table', $items, array( 'feed', 'ok', 'error' ) );

        $ok = count( array_filter( $results, function ( $r ) { return $r['ok']; } ) );
        WP_CLI::success( $ok . ' of ' . count( $results ) . ' sitemap(s) resubmitted.' );
    }

    /**
     * Inspect a URL's index status.
     *
     * ## OPTIONS
     *
     * <url>
     * : The absolute URL to inspect.
     */
    public function inspect( $args, $assoc_args ) {
        $this->require_ready();
        $url = isset( $args[0] ) ? $args[0] : '';
        if ( '' === $url ) {
            WP_CLI::error( 'Provide a URL: wp ace-crawl gsc inspect <url>' );
        }
        $result = $this->bail_on_error( AceSEOSearchConsole::inspect_url( $url ) );

        WP_CLI\Utils\format_items(
            'table',
            array(
                array( 'field' => 'verdict', 'value' => $result['verdict'] ),
                array( 'field' => 'coverageState', 'value' => $result['coverageState'] ),
                array( 'field' => 'indexingState', 'value' => $result['indexingState'] ),
                array( 'field' => 'robotsTxtState', 'value' => $result['robotsTxtState'] ),
                array( 'field' => 'lastCrawlTime', 'value' => $result['lastCrawlTime'] ),
            ),
            array( 'field', 'value' )
        );
    }

    /**
     * Show top queries or pages by impressions, or the queries for one page.
     *
     * ## OPTIONS
     *
     * [--url=<url>]
     * : Restrict to this exact page URL (dimension defaults to query).
     *
     * [--dimension=<dimension>]
     * : query or page. Default query (with --url the page is fixed, so query is the useful breakdown).
     *
     * [--rows=<rows>]
     * : Number of rows (1-1000). Default 50.
     *
     * [--days=<days>]
     * : Trailing window in days (7-90). Default 28.
     *
     * [--totals]
     * : Print the site totals for the window instead of a breakdown.
     *
     * ## EXAMPLES
     *
     *     wp ace-crawl gsc queries --days=28
     *     wp ace-crawl gsc queries --dimension=page --rows=30
     *     wp ace-crawl gsc queries --url=https://sheff.events/whats-on/ --rows=20
     *     wp ace-crawl gsc queries --totals
     */
    public function queries( $args, $assoc_args ) {
        $this->require_ready();
        $days = isset( $assoc_args['days'] ) ? max( 7, min( 90, (int) $assoc_args['days'] ) ) : 28;
        $url  = isset( $assoc_args['url'] ) ? (string) $assoc_args['url'] : '';
        $rows = isset( $assoc_args['rows'] ) ? max( 1, min( 1000, (int) $assoc_args['rows'] ) ) : 50;

        if ( isset( $assoc_args['totals'] ) ) {
            $totals = $this->bail_on_error( AceSEOSearchConsole::site_totals( $days ) );
            WP_CLI\Utils\format_items(
                'table',
                array(
                    array( 'metric' => 'impressions', 'value' => $totals['impressions'] ),
                    array( 'metric' => 'clicks', 'value' => $totals['clicks'] ),
                    array( 'metric' => 'ctr (%)', 'value' => $totals['ctr'] ),
                    array( 'metric' => 'avg position', 'value' => $totals['position'] ),
                ),
                array( 'metric', 'value' )
            );
            WP_CLI::log( 'Site totals, last ' . $days . ' days.' );
            return;
        }

        $dimension = isset( $assoc_args['dimension'] ) ? (string) $assoc_args['dimension'] : 'query';
        if ( ! in_array( $dimension, array( 'query', 'page' ), true ) ) {
            WP_CLI::error( '--dimension must be query or page.' );
        }

        $start = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
        $end   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
        $list  = $this->bail_on_error( AceSEOSearchConsole::top_rows( $dimension, $start, $end, $rows, $url ) );

        if ( empty( $list ) ) {
            WP_CLI::success( 'No ' . $dimension . ' data in the last ' . $days . ' days' . ( '' !== $url ? ' for ' . $url : '' ) . '.' );
            return;
        }

        $items = array();
        foreach ( $list as $row ) {
            $items[] = array(
                $dimension => $row['key'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
                'ctr (%)'     => $row['ctr'],
                'position'    => $row['position'],
            );
        }
        WP_CLI\Utils\format_items( 'table', $items, array( $dimension, 'impressions', 'clicks', 'ctr (%)', 'position' ) );
        WP_CLI::log( sprintf( 'Top %d %s rows by impressions, %s to %s%s.', count( $items ), $dimension, $start, $end, '' !== $url ? ', page ' . $url : '' ) );
    }
}
