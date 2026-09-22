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
        'clear-redirect'    => 'Clear the redirect',
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
    }

    /* ---- Settings ------------------------------------------------------------------------------ */

    public static function options() {
        $defaults = array(
            'notice_enabled' => 0,
            'notice_years'   => 3,
            /* translators: {years} the post's age in whole years, {date} its publish date. */
            'notice_text'    => 'This article was published {date}. Details, prices and dates may have changed since.',
        );
        $saved = get_option( self::OPTION, array() );
        return apply_filters( 'ace_seo_retention_options', array_merge( $defaults, is_array( $saved ) ? array_intersect_key( $saved, $defaults ) : array() ) );
    }

    public static function save_options( array $input ) {
        $clean = array(
            'notice_enabled' => ! empty( $input['notice_enabled'] ) ? 1 : 0,
            'notice_years'   => max( 1, min( 30, (int) ( $input['notice_years'] ?? 3 ) ) ),
            'notice_text'    => sanitize_text_field( (string) ( $input['notice_text'] ?? '' ) ),
        );
        if ( '' === $clean['notice_text'] ) {
            unset( $clean['notice_text'] );
        }
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
            $url = esc_url_raw( trim( (string) ( $args['url'] ?? '' ) ) );
            if ( '' === $url ) {
                return array( 'applied' => 0, 'skipped' => 0, 'error' => 'A URL is needed for a redirect.' );
            }
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
        if ( '' === $url || self::path_of( $url ) === self::path_of( get_permalink( $id ) ) ) {
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
