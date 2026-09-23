<?php
/**
 * Retention actions: what the retention report leads to, applied in bulk, all reversible, all logged.
 *
 *   noindex / index           the plugin's own Search Engine Visibility meta (_ace_seo_meta-robots-noindex)
 *   unavailable-after / clear a robots `unavailable_after` date: the page drops out of results on that
 *                             day without anyone touching it again
 *   redirect / clear          a 301 from this post to a stronger page (the consolidate bucket)
 *   news-exclude / include    keep the post out of the news sitemap (the regular one still lists it)
 *   notice-show / hide / auto force or suppress the dated-content notice regardless of age
 *
 * The dated-content notice itself lives here too: on a singular post older than the configured age a
 * short line above the content says so. It keeps the post indexed and honest, which is the point —
 * old is not the same as wrong, but a reader deserves to know the date.
 *
 * Nothing here deletes anything.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoRetentionActions {

    const META_NOINDEX     = '_ace_seo_meta-robots-noindex';
    const META_UNAVAILABLE = '_ace_seo_unavailable_after';
    const META_REDIRECT    = '_ace_seo_redirect_to';
    const META_NEWS_EXCL   = '_ace_seo_news_exclude';
    const META_NOTICE      = '_ace_seo_archive_notice';
    const META_LOG         = '_ace_seo_retention_log';
    const OPTION           = 'ace_seo_retention_options';
    const LOG_OPTION       = 'ace_seo_retention_actions_log';

    const ACTIONS = array(
        'noindex'           => 'Noindex (keep serving it)',
        'index'             => 'Index again',
        'unavailable-after' => 'Set an unavailable_after date',
        'clear-unavailable' => 'Clear the unavailable_after date',
        'redirect'          => 'Redirect (301) to a URL',
        'gone'              => 'Answer 410 Gone (kept in the database)',
        'clear-redirect'    => 'Clear the redirect / 410',
        'news-exclude'      => 'Keep out of the news sitemap',
        'news-include'      => 'Back into the news sitemap',
        'notice-show'       => 'Always show the dated-content notice',
        'notice-hide'       => 'Never show the dated-content notice',
        'notice-auto'       => 'Notice decided by age (default)',
    );

    public static function init() {
        add_filter( 'ace_seo_robots_directives', array( __CLASS__, 'robots_unavailable_after' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 2 );
        add_filter( 'the_content', array( __CLASS__, 'dated_content_notice' ), 5 );
        add_filter( 'ace_sitemap_powertools_news_query_args', array( __CLASS__, 'news_sitemap_exclusions' ) );
        add_action( 'transition_post_status', array( __CLASS__, 'lifetime_on_publish' ), 10, 3 );
        add_action( 'updated_post_meta', array( __CLASS__, 'tidy_saved_field' ), 10, 4 );
        add_action( 'added_post_meta', array( __CLASS__, 'tidy_saved_field' ), 10, 4 );
    }

    /* ---- Settings ------------------------------------------------------------------------------ */

    public static function options() {
        $defaults = array(
            'notice_enabled' => 0,
            'notice_years'   => 3,
            /* translators: {years} the post's age in whole years, {date} its publish date. */
            'notice_text'    => 'This article was published {date}. Details, prices and dates may have changed since.',
            // Lifetimes: days from publish to unavailable_after, per post type; term rules ("taxonomy:slug"
            // => days) override the type's, longest match wins. A time-boxed match preview lives a week;
            // an evergreen guide has no lifetime at all.
            'lifetimes'      => array(),
            'lifetime_rules' => array(),
            // The report's own settings (Ace SEO, Retention, Report settings). Only post meta and a
            // stats table depend on them; nothing a visitor sees.
            'report_years'   => 3,
            'report_days'    => 90,
            'retained_views' => 1,
            'thin_words'     => 300,
            'auto_build'     => 0,
            'track_views'    => 0,
        );
        $saved = get_option( self::OPTION, array() );
        return apply_filters( 'ace_seo_retention_options', array_merge( $defaults, is_array( $saved ) ? array_intersect_key( $saved, $defaults ) : array() ) );
    }

    public static function save_options( array $input ) {
        $current = get_option( self::OPTION, array() );
        $current = is_array( $current ) ? $current : array();
        $clean   = array_merge( $current, array(
            'notice_enabled' => ! empty( $input['notice_enabled'] ) ? 1 : 0,
            'notice_years'   => max( 1, min( 30, (int) ( $input['notice_years'] ?? 3 ) ) ),
            'notice_text'    => sanitize_text_field( (string) ( $input['notice_text'] ?? '' ) ),
        ) );
        if ( '' === $clean['notice_text'] ) {
            unset( $clean['notice_text'] );
        }
        if ( isset( $input['lifetimes'] ) && is_array( $input['lifetimes'] ) ) {
            $clean['lifetimes'] = array();
            foreach ( $input['lifetimes'] as $type => $days ) {
                $type = sanitize_key( $type );
                $days = max( 0, (int) $days );
                if ( $type && $days > 0 && post_type_exists( $type ) ) {
                    $clean['lifetimes'][ $type ] = $days;
                }
            }
        }
        if ( isset( $input['lifetime_rules'] ) ) {
            $clean['lifetime_rules'] = array();
            foreach ( preg_split( '/[\r\n,]+/', (string) $input['lifetime_rules'] ) as $line ) {
                if ( preg_match( '/^\s*([a-z0-9_-]+)\s*:\s*([^=\s]+)\s*=\s*(\d+)\s*$/i', $line, $m ) && taxonomy_exists( sanitize_key( $m[1] ) ) && (int) $m[3] > 0 ) {
                    $clean['lifetime_rules'][ sanitize_key( $m[1] ) . ':' . sanitize_title( $m[2] ) ] = (int) $m[3];
                }
            }
        }
        update_option( self::OPTION, $clean, false );
        return self::options();
    }

    /** The Report settings form: cutoffs, thresholds, the weekly rebuild and own tracking. */
    public static function save_report_settings( array $input ) {
        $current = get_option( self::OPTION, array() );
        $current = is_array( $current ) ? $current : array();
        $clean   = array_merge( $current, array(
            'report_years'   => max( 1, min( 20, (int) ( $input['report_years'] ?? 3 ) ) ),
            'report_days'    => max( 7, min( 480, (int) ( $input['report_days'] ?? 90 ) ) ),
            'retained_views' => max( 1, (int) ( $input['retained_views'] ?? 1 ) ),
            'thin_words'     => max( 0, (int) ( $input['thin_words'] ?? 300 ) ),
            'auto_build'     => ! empty( $input['auto_build'] ) ? 1 : 0,
            'track_views'    => ! empty( $input['track_views'] ) ? 1 : 0,
        ) );
        update_option( self::OPTION, $clean, false );
        return self::options();
    }

    /* ---- Applying actions ------------------------------------------------------------------------ */

    /**
     * Apply one action to a set of posts. Returns array( 'applied' => n, 'skipped' => n, 'error' => '' ).
     * $args: 'date' (Y-m-d) for unavailable-after, 'url' for redirect. $source is who did it (admin,
     * cli) for the log.
     */
    public static function apply( array $ids, $action, array $args = array(), $source = 'admin' ) {
        if ( ! isset( self::ACTIONS[ $action ] ) ) {
            return array( 'applied' => 0, 'skipped' => 0, 'error' => 'Unknown action.' );
        }

        $date = '';
        $url  = '';
        if ( 'unavailable-after' === $action ) {
            $date = self::normalise_date( $args['date'] ?? '' );
            if ( '' === $date ) {
                return array( 'applied' => 0, 'skipped' => 0, 'error' => 'A date (YYYY-MM-DD) is needed for unavailable_after.' );
            }
        }
        if ( 'redirect' === $action ) {
            $url = self::normalise_target( $args['url'] ?? '' );
            if ( '' === $url || 'gone' === $url ) {
                return array( 'applied' => 0, 'skipped' => 0, 'error' => 'A URL (absolute, or a path on this site) is needed for a redirect.' );
            }
        }
        if ( 'gone' === $action ) {
            $url = 'gone';
        }

        $applied = 0;
        $skipped = 0;
        foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
            $post = $id ? get_post( $id ) : null;
            if ( ! $post || 'publish' !== $post->post_status ) {
                $skipped++;
                continue;
            }
            if ( 'redirect' === $action && self::path_of( $url ) === self::path_of( get_permalink( $id ) ) ) {
                $skipped++; // a post cannot redirect to itself
                continue;
            }

            switch ( $action ) {
                case 'noindex':
                    update_post_meta( $id, self::META_NOINDEX, '1' );
                    break;
                case 'index':
                    delete_post_meta( $id, self::META_NOINDEX );
                    break;
                case 'unavailable-after':
                    update_post_meta( $id, self::META_UNAVAILABLE, $date );
                    break;
                case 'clear-unavailable':
                    delete_post_meta( $id, self::META_UNAVAILABLE );
                    break;
                case 'redirect':
                case 'gone':
                    update_post_meta( $id, self::META_REDIRECT, $url );
                    break;
                case 'clear-redirect':
                    delete_post_meta( $id, self::META_REDIRECT );
                    break;
                case 'news-exclude':
                    update_post_meta( $id, self::META_NEWS_EXCL, '1' );
                    break;
                case 'news-include':
                    delete_post_meta( $id, self::META_NEWS_EXCL );
                    break;
                case 'notice-show':
                    update_post_meta( $id, self::META_NOTICE, 'show' );
                    break;
                case 'notice-hide':
                    update_post_meta( $id, self::META_NOTICE, 'hide' );
                    break;
                case 'notice-auto':
                    delete_post_meta( $id, self::META_NOTICE );
                    break;
            }

            // The front end reads post meta through the plugin's guest meta cache; a change made here
            // has to reach it now, not on the next save.
            clean_post_cache( $id );
            if ( class_exists( 'ACE_SEO_Frontend_Performance' ) ) {
                ACE_SEO_Frontend_Performance::clear_post_cache( $id );
            }
            do_action( 'ace_seo_retention_post_changed', $id );

            self::log( $id, $action, $date ?: $url, $source );
            do_action( 'ace_seo_retention_action_applied', $id, $action, $args, $source );
            $applied++;
        }

        return array( 'applied' => $applied, 'skipped' => $skipped, 'error' => '' );
    }

    private static function log( $id, $action, $value, $source ) {
        $entry = array( 'time' => time(), 'action' => $action, 'value' => $value, 'by' => $source . ( get_current_user_id() ? ':' . get_current_user_id() : '' ) );

        $post_log   = get_post_meta( $id, self::META_LOG, true );
        $post_log   = is_array( $post_log ) ? $post_log : array();
        $post_log[] = $entry;
        update_post_meta( $id, self::META_LOG, array_slice( $post_log, -20 ) );

        $log   = get_option( self::LOG_OPTION, array() );
        $log   = is_array( $log ) ? $log : array();
        $log[] = $entry + array( 'post' => (int) $id );
        update_option( self::LOG_OPTION, array_slice( $log, -300 ), false );
    }

    public static function recent_log( $limit = 30 ) {
        $log = get_option( self::LOG_OPTION, array() );
        return is_array( $log ) ? array_reverse( array_slice( $log, -$limit ) ) : array();
    }

    /* ---- Lifetimes: unavailable_after set at publish ------------------------------------------ */

    /** Days a post of this type, with these terms, should live in search results; 0 for no lifetime. */
    public static function lifetime_for( $post ) {
        $post = get_post( $post );
        if ( ! $post ) {
            return 0;
        }
        $o         = self::options();
        $type_days = (int) ( $o['lifetimes'][ $post->post_type ] ?? 0 );
        $rule_days = 0;
        if ( ! empty( $o['lifetime_rules'] ) ) {
            foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
                $terms = get_the_terms( $post, $taxonomy );
                if ( ! is_array( $terms ) ) {
                    continue;
                }
                foreach ( $terms as $term ) {
                    // Among term rules the longest lifetime wins: a post that is both a "preview" and an
                    // "explainer" keeps the explainer's.
                    $rule_days = max( $rule_days, (int) ( $o['lifetime_rules'][ $taxonomy . ':' . $term->slug ] ?? 0 ) );
                }
            }
        }
        $days = $rule_days > 0 ? $rule_days : $type_days;
        return (int) apply_filters( 'ace_seo_retention_lifetime_days', $days, $post );
    }

    /** On first publish, a post with a lifetime gets its unavailable_after date, unless one was set by hand. */
    public static function lifetime_on_publish( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post ) {
            return;
        }
        if ( '' !== (string) get_post_meta( $post->ID, self::META_UNAVAILABLE, true ) ) {
            return;
        }
        $days = self::lifetime_for( $post );
        if ( $days <= 0 ) {
            return;
        }
        // The object handed to the transition hook can still carry a zeroed GMT date for a post being
        // published for the first time; that is "now" anyway.
        $published = get_post_time( 'U', true, $post );
        if ( $published <= 0 || '0000-00-00 00:00:00' === $post->post_date_gmt ) {
            $published = time();
        }
        $date = gmdate( 'Y-m-d', $published + $days * DAY_IN_SECONDS );
        update_post_meta( $post->ID, self::META_UNAVAILABLE, $date );
        self::log( $post->ID, 'unavailable-after', $date, 'publish:' . $days . 'd' );
    }

    /** The two metabox fields share these meta keys: keep what is saved by hand in the shape the front end reads. */
    public static function tidy_saved_field( $meta_id, $post_id, $meta_key, $value ) {
        if ( self::META_UNAVAILABLE === $meta_key ) {
            $clean = self::normalise_date( $value );
            if ( $clean !== (string) $value ) {
                remove_action( 'updated_post_meta', array( __CLASS__, 'tidy_saved_field' ), 10 );
                '' === $clean ? delete_post_meta( $post_id, $meta_key ) : update_post_meta( $post_id, $meta_key, $clean );
                add_action( 'updated_post_meta', array( __CLASS__, 'tidy_saved_field' ), 10, 4 );
            }
        } elseif ( self::META_REDIRECT === $meta_key ) {
            $clean = self::normalise_target( $value );
            if ( $clean !== (string) $value ) {
                remove_action( 'updated_post_meta', array( __CLASS__, 'tidy_saved_field' ), 10 );
                '' === $clean ? delete_post_meta( $post_id, $meta_key ) : update_post_meta( $post_id, $meta_key, $clean );
                add_action( 'updated_post_meta', array( __CLASS__, 'tidy_saved_field' ), 10, 4 );
            }
        }
    }

    /** Every post with a redirect or 410, for the map. */
    public static function redirects( $limit = 500 ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value <> '' ORDER BY pm.meta_id DESC LIMIT %d",
            self::META_REDIRECT,
            (int) $limit
        ) );
        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array( 'id' => (int) $r->ID, 'title' => $r->post_title, 'type' => $r->post_type, 'from' => get_permalink( $r->ID ), 'to' => $r->meta_value, 'gone' => 'gone' === $r->meta_value );
        }
        return $out;
    }

    /** The state of every action's meta for a post, for the report's columns. */
    public static function state( $id ) {
        return array(
            'noindex'     => '1' === (string) get_post_meta( $id, self::META_NOINDEX, true ),
            'unavailable' => (string) get_post_meta( $id, self::META_UNAVAILABLE, true ),
            'redirect'    => (string) get_post_meta( $id, self::META_REDIRECT, true ),
            'news_excl'   => '1' === (string) get_post_meta( $id, self::META_NEWS_EXCL, true ),
            'notice'      => (string) get_post_meta( $id, self::META_NOTICE, true ),
        );
    }

    /**
     * A redirect target as stored: 'gone', an absolute http(s) URL, or a site-relative path made
     * absolute. Anything else is '' — esc_url_raw() alone would turn "not a url" into http://notaurl.
     */
    public static function normalise_target( $value ) {
        $raw = trim( (string) $value );
        if ( '' === $raw ) {
            return '';
        }
        if ( 'gone' === strtolower( $raw ) ) {
            return 'gone';
        }
        if ( 0 === strpos( $raw, '/' ) && 0 !== strpos( $raw, '//' ) ) {
            $raw = home_url( $raw );
        }
        if ( ! preg_match( '#^https?://[^\s/]+#i', $raw ) ) {
            return '';
        }
        $url = esc_url_raw( $raw );
        return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
    }

    private static function normalise_date( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }
        $ts = strtotime( $value );
        return $ts ? gmdate( 'Y-m-d', $ts ) : '';
    }

    private static function path_of( $url ) {
        return '/' . trim( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/' );
    }

    /* ---- Front end ------------------------------------------------------------------------------ */

    /** robots: unavailable_after, in the RFC 850 form Google documents. */
    public static function robots_unavailable_after( $robots ) {
        if ( ! is_singular() ) {
            return $robots;
        }
        $date = (string) get_post_meta( get_queried_object_id(), self::META_UNAVAILABLE, true );
        if ( '' === $date ) {
            return $robots;
        }
        $ts = strtotime( $date . ' 00:00:00 UTC' );
        if ( ! $ts ) {
            return $robots;
        }
        $robots   = (array) $robots;
        $robots[] = 'unavailable_after: ' . gmdate( 'D, d M Y H:i:s', $ts ) . ' GMT';
        return $robots;
    }

    /** A post with a redirect target sends visitors there with a 301. */
    public static function maybe_redirect() {
        if ( ! is_singular() || is_preview() || is_admin() ) {
            return;
        }
        $id  = get_queried_object_id();
        $url = (string) get_post_meta( $id, self::META_REDIRECT, true );
        if ( '' === $url ) {
            return;
        }
        if ( 'gone' === $url ) {
            // 410, not 404: "this was here and is not coming back", which search engines act on faster.
            // The post itself is untouched, so this is one meta value away from being live again.
            status_header( 410 );
            nocache_headers();
            header( 'X-Robots-Tag: noindex' );
            $message = apply_filters( 'ace_seo_retention_gone_message', '<p>This page has been retired and is no longer available.</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p>', $id );
            wp_die( $message, esc_html__( 'Gone', 'ace-crawl-enhancer' ), array( 'response' => 410 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered markup
        }
        if ( self::path_of( $url ) === self::path_of( get_permalink( $id ) ) ) {
            return;
        }
        $url = apply_filters( 'ace_seo_retention_redirect_url', $url, $id );
        if ( $url ) {
            wp_redirect( esc_url_raw( $url ), 301, 'Ace SEO retention' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- consolidation target may be off-site
            exit;
        }
    }

    /** Posts marked out of the news sitemap. */
    public static function news_sitemap_exclusions( $args ) {
        $args = (array) $args;
        $args['meta_query'] = array_merge( (array) ( $args['meta_query'] ?? array() ), array(
            array(
                'key'     => self::META_NEWS_EXCL,
                'compare' => 'NOT EXISTS',
            ),
        ) );
        return $args;
    }

    /** Should this post carry the dated-content notice? */
    public static function notice_applies( $post ) {
        $post = get_post( $post );
        if ( ! $post ) {
            return false;
        }
        $forced = (string) get_post_meta( $post->ID, self::META_NOTICE, true );
        if ( 'hide' === $forced ) {
            return false;
        }
        if ( 'show' === $forced ) {
            return true;
        }
        $o = self::options();
        if ( empty( $o['notice_enabled'] ) ) {
            return false;
        }
        $types = apply_filters( 'ace_seo_retention_notice_post_types', array( 'post' ) );
        if ( ! in_array( $post->post_type, (array) $types, true ) ) {
            return false;
        }
        return get_post_time( 'U', true, $post ) < strtotime( '-' . (int) $o['notice_years'] . ' years' );
    }

    public static function notice_html( $post ) {
        $post  = get_post( $post );
        $o     = self::options();
        $years = max( 1, (int) floor( ( time() - get_post_time( 'U', true, $post ) ) / YEAR_IN_SECONDS ) );
        $text  = strtr( $o['notice_text'], array(
            '{years}' => number_format_i18n( $years ),
            '{date}'  => get_the_date( '', $post ),
        ) );
        $html = '<div class="ace-seo-dated-notice" role="note">'
            . '<time datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( $text ) . '</time>'
            . '</div>';
        return apply_filters( 'ace_seo_retention_notice_html', $html, $post, $text );
    }

    public static function dated_content_notice( $content ) {
        // Block themes render Post Content outside the classic loop, so "the main post" is the
        // queried one rather than in_the_loop(); anything else running the_content (a query loop's
        // excerpt, a related-posts block) is a different post and gets nothing.
        // Not while the head or an excerpt is being built from the content (both run the_content and
        // throw the markup away), and never twice.
        if ( ! is_singular() || doing_action( 'wp_head' ) || doing_filter( 'get_the_excerpt' ) || false !== strpos( $content, 'ace-seo-dated-notice' ) ) {
            return $content;
        }
        $post = get_post();
        if ( ! $post || (int) $post->ID !== (int) get_queried_object_id() || ! self::notice_applies( $post ) ) {
            return $content;
        }

        $style = '<style id="ace-seo-dated-notice-style">.ace-seo-dated-notice{margin:0 0 1.25em;padding:.6em .9em;border-left:3px solid currentColor;border-radius:.25em;background:rgba(127,127,127,.08);font-size:.9em;line-height:1.4;opacity:.85}</style>';
        return $style . self::notice_html( $post ) . $content;
    }
}
