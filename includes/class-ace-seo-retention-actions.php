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
            // Lifetimes: days from the last publish or edit to unavailable_after, per post type; term rules
            // ("taxonomy:slug" => days) override the type's, longest match wins. Worked out on every render,
            // not stored, so a rule reaches the whole archive at once and an edit extends a post's life.
            'lifetimes'      => array(),
            'lifetime_rules' => array(),
            // The report's own settings (Ace SEO, Retention, Report settings). Only post meta and a
            // stats table depend on them; nothing a visitor sees.
            'report_years'   => 3,
            'report_days'    => 90,
            'retained_views' => 1,
            'thin_words'     => 300,
            'timing_policy'  => 'estimate',
            'timing_rules'   => array(),
            'auto_build'     => 0,
            'track_views'    => 0,
            // Retained posts on the front end (AceSeoRetentionFront). All off until switched on.
            'retained_notice'      => 0,
            'retained_notice_text' => 'This is an older article, published {date}, and it has not been updated in a while.',
            'light_enabled'        => 0,
            'light_drop'           => 'sidebar',
            'light_cache_hours'    => 24,
            'light_continue'       => 'card',
        );
        $saved = get_option( self::OPTION, array() );
        return apply_filters( 'ace_seo_retention_options', array_merge( $defaults, is_array( $saved ) ? array_intersect_key( $saved, $defaults ) : array() ) );
    }

    public static function save_options( array $input ) {
        $current = get_option( self::OPTION, array() );
        $current = is_array( $current ) ? $current : array();
        // The combined Settings form only displays public post types. A notice save must not
        // silently remove rules for an inactive plugin or an unlisted/private content type.
        $preserve_unlisted = 'notice-lifetimes' === ( $input['settings_section'] ?? '' );
        $clean   = array_merge( $current, array(
            'notice_enabled' => ! empty( $input['notice_enabled'] ) ? 1 : 0,
            'notice_years'   => max( 1, min( 30, (int) ( $input['notice_years'] ?? 3 ) ) ),
            'notice_text'    => sanitize_text_field( (string) ( $input['notice_text'] ?? '' ) ),
        ) );
        if ( '' === $clean['notice_text'] ) {
            unset( $clean['notice_text'] );
        }
        if ( isset( $input['lifetimes'] ) && is_array( $input['lifetimes'] ) ) {
            $clean['lifetimes'] = $preserve_unlisted && is_array( $current['lifetimes'] ?? null ) ? $current['lifetimes'] : array();
            foreach ( $input['lifetimes'] as $type => $days ) {
                $type = sanitize_key( $type );
                $days = max( 0, (int) $days );
                unset( $clean['lifetimes'][ $type ] );
                if ( $type && $days > 0 && post_type_exists( $type ) ) {
                    $clean['lifetimes'][ $type ] = $days;
                }
            }
        }
        if ( isset( $input['lifetime_rules'] ) ) {
            $clean['lifetime_rules'] = array();
            foreach ( preg_split( '/[\r\n,]+/', (string) $input['lifetime_rules'] ) as $line ) {
                if ( preg_match( '/^\s*([a-z0-9_-]+)\s*:\s*([^=\s]+)\s*=\s*(\d+)\s*$/i', $line, $m ) && (int) $m[3] > 0 ) {
                    $key = sanitize_key( $m[1] ) . ':' . sanitize_title( $m[2] );
                    // Existing rules stay editable/removable even while their taxonomy is inactive.
                    if ( taxonomy_exists( sanitize_key( $m[1] ) ) || ( $preserve_unlisted && isset( $current['lifetime_rules'][ $key ] ) ) ) {
                        $clean['lifetime_rules'][ $key ] = (int) $m[3];
                    }
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
            'timing_policy'  => 'strict' === ( $input['timing_policy'] ?? '' ) ? 'strict' : 'estimate',
            'auto_build'     => ! empty( $input['auto_build'] ) ? 1 : 0,
            'track_views'    => ! empty( $input['track_views'] ) ? 1 : 0,
        ) );
        if ( isset( $input['timing_rules'] ) ) {
            $clean['timing_rules'] = self::parse_timing_rules( (string) $input['timing_rules'] );
        }
        update_option( self::OPTION, $clean, false );
        return self::options();
    }

    /**
     * Timing rules by term, one per line, so an archive can be classified in bulk rather than post by
     * post: when an article in that category or tag matters.
     *
     *   category:guides = evergreen                 relevant in any period
     *   category:horse-racing-tips = event 3        relevant from its publication date for 3 days (one-off)
     *   category:cheltenham-tips = season 03-01 03-20   relevant every year between those dates
     *
     * A per-post setting on the Advanced tab always wins over a rule.
     */
    public static function parse_timing_rules( $text ) {
        $rules = array();
        foreach ( preg_split( '/[\r\n]+/', $text ) as $line ) {
            if ( ! preg_match( '/^\s*([a-z0-9_-]+)\s*:\s*([^=\s]+)\s*=\s*(evergreen|event\s+(\d+)|season\s+(\d{2}-\d{2})\s+(\d{2}-\d{2}))\s*$/i', $line, $m ) ) {
                continue;
            }
            $key = sanitize_key( $m[1] ) . ':' . sanitize_title( $m[2] );
            if ( 'evergreen' === strtolower( $m[3] ) ) {
                $rules[ $key ] = array( 'type' => 'evergreen' );
            } elseif ( ! empty( $m[4] ) ) {
                $rules[ $key ] = array( 'type' => 'event', 'days' => max( 1, (int) $m[4] ) );
            } elseif ( ! empty( $m[5] ) && checkdate( (int) substr( $m[5], 0, 2 ), (int) substr( $m[5], 3 ), 2024 ) && checkdate( (int) substr( $m[6], 0, 2 ), (int) substr( $m[6], 3 ), 2024 ) ) {
                $rules[ $key ] = array( 'type' => 'season', 'start' => $m[5], 'end' => $m[6] );
            }
        }
        return $rules;
    }

    /** The rules back as text for the textarea. */
    public static function timing_rules_text( array $rules ) {
        $lines = array();
        foreach ( $rules as $key => $rule ) {
            $value = 'evergreen' === $rule['type'] ? 'evergreen' : ( 'event' === $rule['type'] ? 'event ' . (int) $rule['days'] : 'season ' . $rule['start'] . ' ' . $rule['end'] );
            $lines[] = $key . ' = ' . $value;
        }
        return implode( "\n", $lines );
    }

    /** The first timing rule that matches one of the post's terms, with the term it matched, or null. */
    public static function timing_rule_for( $post_id ) {
        $rules = (array) ( self::options()['timing_rules'] ?? array() );
        if ( ! $rules ) {
            return null;
        }
        $taxonomies = array();
        foreach ( array_keys( $rules ) as $key ) {
            $taxonomies[ strtok( $key, ':' ) ] = true;
        }
        foreach ( array_keys( $taxonomies ) as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_the_terms( $post_id, $taxonomy );
            if ( ! is_array( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $key = $taxonomy . ':' . $term->slug;
                if ( isset( $rules[ $key ] ) ) {
                    return array_merge( $rules[ $key ], array( 'key' => $key, 'label' => $term->name ) );
                }
            }
        }
        return null;
    }

    /** The front-end form for retained posts: notice, lighter render, continue reading. */
    public static function save_front_settings( array $input ) {
        $current = get_option( self::OPTION, array() );
        $current = is_array( $current ) ? $current : array();
        $drop    = implode( ', ', array_filter( array_map( 'sanitize_html_class', preg_split( '/[\s,]+/', (string) ( $input['light_drop'] ?? '' ) ) ) ) );
        $clean   = array_merge( $current, array(
            'retained_notice'      => ! empty( $input['retained_notice'] ) ? 1 : 0,
            'retained_notice_text' => sanitize_text_field( (string) ( $input['retained_notice_text'] ?? '' ) ),
            'light_enabled'        => ! empty( $input['light_enabled'] ) ? 1 : 0,
            'light_drop'           => $drop,
            'light_cache_hours'    => max( 0, min( 720, (int) ( $input['light_cache_hours'] ?? 24 ) ) ),
            'light_continue'       => in_array( $input['light_continue'] ?? '', array( 'card', 'none' ), true ) ? $input['light_continue'] : 'card',
        ) );
        if ( '' === $clean['retained_notice_text'] ) {
            unset( $clean['retained_notice_text'] );
        }
        update_option( self::OPTION, $clean, false );
        return self::options();
    }

    /** Is this post in the retained tier of the last report? */
    public static function is_retained( $post_id ) {
        $retained = 'retained' === (string) get_post_meta( (int) $post_id, '_ace_seo_ret_tier', true );
        return (bool) apply_filters( 'ace_seo_retention_is_retained', $retained, (int) $post_id );
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

    /* ---- Lifetimes: unavailable_after worked out on the fly --------------------------------------- */

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

    /**
     * The unavailable_after date a post carries and where it came from: a date set by hand (bulk action,
     * the post's Advanced tab) always wins; otherwise its lifetime counted from whichever is later, the
     * publish date or the last edit. Nothing is stored, so changing a rule moves every matching post the
     * moment it is saved, and refreshing a post buys it another lifetime.
     *
     * @return array{date: string, source: string, days: int}
     */
    public static function unavailable_for( $post ) {
        $post = get_post( $post );
        if ( ! $post ) {
            return array( 'date' => '', 'source' => '', 'days' => 0 );
        }
        $manual = (string) get_post_meta( $post->ID, self::META_UNAVAILABLE, true );
        if ( '' !== $manual ) {
            return array( 'date' => $manual, 'source' => 'manual', 'days' => 0 );
        }
        $days = self::lifetime_for( $post );
        if ( $days <= 0 ) {
            return array( 'date' => '', 'source' => '', 'days' => 0 );
        }
        $from = max( (int) get_post_time( 'U', true, $post ), (int) get_post_modified_time( 'U', true, $post ) );
        return array( 'date' => gmdate( 'Y-m-d', ( $from ?: time() ) + $days * DAY_IN_SECONDS ), 'source' => 'lifetime', 'days' => $days );
    }

    /**
     * How many published posts each lifetime currently covers, and how many are already past it, for the
     * settings screen. Counts on the dates alone; a hand-set date on a post is not taken into account.
     *
     * @return array<string, array{label: string, days: int, total: int, expired: int}>
     */
    public static function lifetime_counts() {
        global $wpdb;
        $o   = self::options();
        $out = array();
        $now = current_time( 'mysql', true );
        foreach ( (array) $o['lifetimes'] as $type => $days ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) total, SUM( GREATEST( post_date_gmt, post_modified_gmt ) < DATE_SUB( %s, INTERVAL %d DAY ) ) expired FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                $now, (int) $days, $type
            ) );
            $out[ 'type:' . $type ] = array( 'label' => $type, 'days' => (int) $days, 'total' => (int) $row->total, 'expired' => (int) $row->expired );
        }
        foreach ( (array) $o['lifetime_rules'] as $key => $days ) {
            list( $taxonomy, $slug ) = array_pad( explode( ':', $key, 2 ), 2, '' );
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term ) {
                $out[ $key ] = array( 'label' => $key . ' (no such term)', 'days' => (int) $days, 'total' => 0, 'expired' => 0 );
                continue;
            }
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) total, SUM( GREATEST( p.post_date_gmt, p.post_modified_gmt ) < DATE_SUB( %s, INTERVAL %d DAY ) ) expired FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id = %d AND p.post_status = 'publish'",
                $now, (int) $days, (int) $term->term_taxonomy_id
            ) );
            $out[ $key ] = array( 'label' => $key, 'days' => (int) $days, 'total' => (int) $row->total, 'expired' => (int) $row->expired );
        }
        return $out;
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
            'unavailable' => self::unavailable_for( $id )['date'],
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
        $date = self::unavailable_for( get_queried_object_id() )['date'];
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
        $o     = self::options();
        $types = apply_filters( 'ace_seo_retention_notice_post_types', array( 'post' ) );
        if ( ! in_array( $post->post_type, (array) $types, true ) ) {
            return false;
        }
        if ( ! empty( $o['retained_notice'] ) && self::is_retained( $post->ID ) ) {
            return true;
        }
        if ( empty( $o['notice_enabled'] ) ) {
            return false;
        }
        return get_post_time( 'U', true, $post ) < strtotime( '-' . (int) $o['notice_years'] . ' years' );
    }

    public static function notice_html( $post ) {
        $post  = get_post( $post );
        $o     = self::options();
        $years = max( 1, (int) floor( ( time() - get_post_time( 'U', true, $post ) ) / YEAR_IN_SECONDS ) );
        $tpl   = ! empty( $o['retained_notice'] ) && self::is_retained( $post->ID ) ? $o['retained_notice_text'] : $o['notice_text'];
        $text  = strtr( $tpl, array(
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
