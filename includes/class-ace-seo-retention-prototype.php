<?php
/** Dev-only shadow queue. There is deliberately no apply endpoint or scheduled worker. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AceSeoRetentionPrototype {
    const TYPE = 'ace_retention_plan';
    const KEEP = '_ace_seo_retention_automation_keep';
    const VERSION = 'shadow-1';

    public static function enabled() {
        // Explicitly bind the opt-in to one discouraged site. A copied dev flag cannot enable live.
        return defined( 'ACE_SEO_RETENTION_PROTOTYPE_SITE' )
            && untrailingslashit( home_url() ) === ACE_SEO_RETENTION_PROTOTYPE_SITE
            && '0' === (string) get_option( 'blog_public' );
    }

    public static function init() {
        if ( ! self::enabled() ) { return; }
        require_once __DIR__ . '/admin/class-ace-seo-retention-evidence-view.php';
        register_post_type( self::TYPE, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false,
            'rewrite' => false, 'query_var' => false, 'supports' => array() ) );
        add_action( 'admin_post_ace_retention_preview', array( __CLASS__, 'handle' ) );
    }

    private static function hash( $value ) { return hash( 'sha256', wp_json_encode( $value ) ); }

    public static function facts( $id ) {
        $post = get_post( $id );
        if ( ! $post ) { return array( 'id' => (int) $id, 'missing' => true ); }
        $terms = wp_get_object_terms( $id, get_object_taxonomies( $post->post_type ), array( 'fields' => 'tt_ids' ) );
        if ( is_wp_error( $terms ) ) { throw new RuntimeException( 'Could not read article terms.' ); }
        sort( $terms );
        $meta = array();
        foreach ( array( self::KEEP, AceSeoRetentionActions::META_NOINDEX, AceSeoRetentionActions::META_UNAVAILABLE,
            '_ace_seo_canonical', '_ace_seo_retention_timing', '_ace_seo_relevant_from', '_ace_seo_relevant_to' ) as $key ) {
            $meta[ $key ] = get_post_meta( $id, $key, true );
        }
        return array( 'id' => (int) $id, 'title' => $post->post_title, 'url' => get_permalink( $id ),
            'type' => $post->post_type, 'status' => $post->post_status, 'password' => '' !== $post->post_password,
            'published' => $post->post_date_gmt, 'modified' => $post->post_modified_gmt,
            'content_hash' => self::hash( array( $post->post_title, $post->post_content, $post->post_excerpt ) ),
            'terms' => $terms, 'meta' => $meta, 'redirect' => AceSeoRedirects::settings( $id ),
            'protected' => in_array( (int) $id, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true ) );
    }

    public static function fingerprint( $source, $target ) {
        return self::hash( array( self::facts( $source ), self::facts( $target ),
            get_post_meta( $source, AceSeoRetentionReport::META, true ), AceSeoRetentionActions::options(),
            AceSeoRetentionReport::settings(), get_option( 'blog_public' ), self::VERSION ) );
    }

    /** Pure response checks; a challenge page or a redirect is not a verified replacement. */
    public static function inspect_response( $response, $url ) {
        $holds = array();
        if ( is_wp_error( $response ) ) { return array( 'Destination could not be fetched securely.' ); }
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { $holds[] = 'Destination must return 200 without following a redirect.'; }
        if ( wp_remote_retrieve_header( $response, 'x-amzn-waf-action' ) || wp_remote_retrieve_header( $response, 'cf-mitigated' ) ) { $holds[] = 'Destination returned a security challenge.'; }
        $body = wp_remote_retrieve_body( $response );
        if ( false === stripos( wp_remote_retrieve_header( $response, 'content-type' ), 'text/html' )
            || false === stripos( $body, '</html>' ) || false === stripos( $body, '</body>' ) ) {
            $holds[] = 'Destination is not a complete HTML page.';
        }
        if ( preg_match( '/\b(noindex|none)\b/i', (string) wp_remote_retrieve_header( $response, 'x-robots-tag' ) ) ) {
            $holds[] = 'Destination sends an HTTP noindex directive.';
        }
        if ( ! class_exists( 'DOMDocument' ) ) { $holds[] = 'HTML validation is unavailable on this server.'; return $holds; }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $doc->loadHTML( $body ?: '<html></html>', LIBXML_NONET );
        libxml_clear_errors(); libxml_use_internal_errors( $previous );
        if ( ! $loaded ) { $holds[] = 'Destination HTML could not be read.'; return $holds; }
        foreach ( $doc->getElementsByTagName( 'meta' ) as $meta ) {
            if ( in_array( strtolower( $meta->getAttribute( 'name' ) ), array( 'robots', 'googlebot', 'bingbot' ), true )
                && preg_match( '/\b(noindex|none)\b/i', $meta->getAttribute( 'content' ) ) ) { $holds[] = 'Destination HTML is marked noindex.'; }
            if ( 'refresh' === strtolower( $meta->getAttribute( 'http-equiv' ) ) ) { $holds[] = 'Destination uses a meta refresh.'; }
        }
        $canonicals = array();
        foreach ( $doc->getElementsByTagName( 'link' ) as $link ) {
            if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ) ), true ) ) { $canonicals[] = $link->getAttribute( 'href' ); }
        }
        if ( 1 !== count( $canonicals ) || AceSeoRedirects::url_key( $canonicals[0] ) !== AceSeoRedirects::url_key( $url )
            || wp_parse_url( $canonicals[0], PHP_URL_SCHEME ) !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
            $holds[] = 'Destination must declare exactly one canonical matching its URL.';
        }
        return array_values( array_unique( $holds ) );
    }

    /** Additional action gates, intentionally stricter than the report's editorial suggestions. */
    public static function holds( array $s, $now ) {
        $holds = $s['target_holds'];
        if ( $s['building'] ) { $holds[] = 'Retention report is being rebuilt.'; }
        if ( $s['site_discouraged'] ) { $holds[] = 'Site-wide noindex is enabled. Dev remains a simulation.'; }
        if ( ! $s['review']['confirmed'] || strlen( $s['review']['reason'] ) < 30 ) { $holds[] = 'Confirm the replacement covers the same subject and reader intent, with a review reason.'; }
        if ( $s['source']['id'] === $s['target']['id'] ) { $holds[] = 'Source and destination are the same article.'; }
        foreach ( array( 'source', 'target' ) as $side ) {
            $f = $s[ $side ];
            if ( ! empty( $f['missing'] ) || 'publish' !== ( $f['status'] ?? '' ) || ! in_array( $f['type'] ?? '', array( 'post', 'page' ), true ) || ! empty( $f['password'] ) ) {
                $holds[] = ucfirst( $side ) . ' must be a public, published post or page without a password.';
            }
            if ( ! empty( $f['redirect']['target'] ) ) { $holds[] = ucfirst( $side ) . ' already has a redirect or 410 rule, including paused or expired rules.'; }
            if ( ! empty( $f['meta'][ self::KEEP ] ) ) { $holds[] = ucfirst( $side ) . ' has a manual keep exclusion.'; }
        }
        if ( ! empty( $s['source']['protected'] ) ) { $holds[] = 'Homepage and posts-index sources are protected.'; }
        if ( ! empty( $s['target']['meta'][ AceSeoRetentionActions::META_NOINDEX ] ) || ! empty( $s['target']['meta'][ AceSeoRetentionActions::META_UNAVAILABLE ] ) ) { $holds[] = 'Destination has a search exclusion or expiry setting.'; }
        $published = strtotime( ( $s['source']['published'] ?? '' ) . ' UTC' );
        if ( ! $published || $published > strtotime( '-' . max( 1, (int) $s['settings']['older_than_years'] ) . ' years', $now ) ) { $holds[] = 'Source is younger than the configured retention age.'; }
        $c = $s['context']; $r = $s['row'];
        if ( empty( $c['period']['start'] ) || empty( $c['period']['end'] ) || ( strtotime( $c['period']['end'] ) - strtotime( $c['period']['start'] ) ) / DAY_IN_SECONDS + 1 < max( 90, (int) ( $s['settings']['days'] ?? 90 ) ) ) { $holds[] = 'Observe at least 90 days, or the longer configured report period.'; }
        if ( empty( $c['period']['end'] ) || strtotime( $c['period']['end'] . ' UTC' ) < $now - 7 * DAY_IN_SECONDS ) { $holds[] = 'A recent observation period is also required before moving an article.'; }
        if ( empty( $c['collected_at'] ) || $c['collected_at'] > $now || $now - $c['collected_at'] > DAY_IN_SECONDS ) { $holds[] = 'Traffic collection time is missing or older than 24 hours.'; }
        $sources = (array) ( $c['sources'] ?? array() );
        if ( ! in_array( 'Search Console', $sources, true ) || ! array_intersect( array( 'Google Analytics', 'Own view tracking' ), $sources ) ) { $holds[] = 'Complete search and visitor evidence are both required.'; }
        foreach ( array( 'views', 'clicks' ) as $key ) {
            if ( ! isset( $c['metrics'][ $key ] ) || ! is_numeric( $c['metrics'][ $key ] ) || 0.0 !== (float) $c['metrics'][ $key ] ) { $holds[] = 'Any readership or unknown ' . $key . ' requires manual review.'; }
        }
        if ( ! isset( $r['backlinks'] ) || ! is_numeric( $r['backlinks'] ) || 0.0 !== (float) $r['backlinks'] ) { $holds[] = 'Backlinks are present or unknown; review their value first.'; }
        if ( ! isset( $r['links_in'] ) || ! is_numeric( $r['links_in'] ) || 0.0 !== (float) $r['links_in'] ) { $holds[] = 'Internal links are present or unknown; review them before moving this article.'; }
        if ( empty( $s['assessment']['ready'] ) || ! in_array( 'consolidate', $s['assessment']['suggestions'], true ) ) {
            $holds[] = 'The coverage-aware assessment does not support combining.';
            $holds = array_merge( $holds, $s['assessment']['reasons'] );
        }
        return array_values( array_unique( $holds ) );
    }

    public static function capture( $source, $url, $start, $end, $confirmed, $reason ) {
        if ( ! self::enabled() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $source ) ) { return new WP_Error( 'permission', 'Dev preview permission required.' ); }
        if ( ! Ace_SEO_Retention_Evidence::date( $start ) || ! Ace_SEO_Retention_Evidence::date( $end )
            || $start > $end || $end >= wp_date( 'Y-m-d' ) || strtotime( $end ) - strtotime( $start ) > 366 * DAY_IN_SECONDS ) { return new WP_Error( 'dates', 'Use a finished observation period of at most 367 days.' ); }
        $url = AceSeoRetentionActions::normalise_target( $url );
        $parts = wp_parse_url( $url );
        if ( ! $url || wp_parse_url( home_url(), PHP_URL_HOST ) !== ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] )
            || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) { return new WP_Error( 'target', 'Choose a post or page on this site without query parameters or fragments.' ); }
        $target = url_to_postid( $url );
        if ( ! $target || ! current_user_can( 'edit_post', $target ) || AceSeoRedirects::url_key( $url ) !== AceSeoRedirects::url_key( get_permalink( $target ) ) ) { return new WP_Error( 'target', 'Choose the canonical URL of an editable post or page. Archive and external destinations are outside this prototype.' ); }
        $before = self::fingerprint( $source, $target );
        $was_building = AceSeoRetentionReport::is_building();
        $row = get_post_meta( $source, AceSeoRetentionReport::META, true );
        $row = is_array( $row ) ? $row : array();
        $row['published'] = get_post_time( 'Y-m-d', true, $source );
        $settings = AceSeoRetentionReport::settings();
        $context = Ace_SEO_Retention_Evidence_View::context( $source, $row, array( 'start' => $start, 'end' => $end ), wp_date( 'Y-m-d' ) );
        $context['overlap_verified'] = (bool) $confirmed && strlen( $reason ) >= 30;
        $prepared = Ace_SEO_Retention_Evidence_View::prepare( $row, $context );
        $response = wp_safe_remote_get( get_permalink( $target ), array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 1048576, 'cookies' => array() ) );
        $s = array( 'version' => self::VERSION, 'created' => time(), 'by' => get_current_user_id(), 'fingerprint' => $before,
            'source' => self::facts( $source ), 'target' => self::facts( $target ), 'settings' => $settings,
            'site_discouraged' => '0' === (string) get_option( 'blog_public' ), 'building' => $was_building || AceSeoRetentionReport::is_building(),
            'review' => array( 'confirmed' => (bool) $confirmed, 'reason' => $reason ), 'row' => $prepared, 'context' => $context,
            'assessment' => Ace_SEO_Retention_Evidence::assess( $prepared, $context, $settings ),
            'target_holds' => self::inspect_response( $response, get_permalink( $target ) ) );
        $s['holds'] = self::holds( $s, time() );
        if ( $before !== self::fingerprint( $source, $target ) ) { $s['holds'][] = 'Article, settings or saved assessment changed during capture. Run a new preview.'; }
        return $s;
    }

    public static function store( array $snapshot ) {
        if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', 'Dev preview permission required.' ); }
        // Each capture is a new immutable record. Existing captures are never edited by this tool.
        return wp_insert_post( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'post_title' => 'Retention preview ' . gmdate( 'Y-m-d H:i:s' ),
            'post_content' => wp_slash( wp_json_encode( array( 'snapshot' => $snapshot, 'sha256' => self::hash( $snapshot ) ) ) ), 'post_author' => get_current_user_id() ), true );
    }

    public static function read( $post ) {
        $record = json_decode( $post->post_content, true );
        if ( ! is_array( $record ) || ! isset( $record['snapshot'], $record['sha256'] ) || ! is_array( $record['snapshot'] ) || ! is_string( $record['sha256'] ) || ! hash_equals( self::hash( $record['snapshot'] ), $record['sha256'] ) ) { return null; }
        return $record['snapshot'];
    }

    public static function handle() {
        if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) { wp_die( 'Dev preview permission required.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'ace_retention_preview' );
        $input = wp_unslash( $_POST );
        foreach ( array( 'source', 'target', 'start', 'end', 'reason', 'operation', 'confirmed' ) as $key ) {
            if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) { wp_die( 'Invalid form data.' ); }
        }
        $id = absint( $input['source'] ?? 0 );
        if ( ! get_post( $id ) || ! current_user_can( 'edit_post', $id ) ) { wp_die( 'Choose an editable source article.' ); }
        $operation = $input['operation'] ?? 'preview';
        if ( in_array( $operation, array( 'keep', 'clear-keep' ), true ) ) {
            if ( 'keep' === $operation ) { update_post_meta( $id, self::KEEP, array( 'by' => get_current_user_id(), 'at' => time() ) ); }
            else { delete_post_meta( $id, self::KEEP ); }
            $result = true;
            $notice = 'keep' === $operation ? 'kept' : 'cleared';
        } elseif ( 'preview' === $operation ) {
            try {
                $result = self::capture( $id, trim( $input['target'] ?? '' ), $input['start'] ?? '', $input['end'] ?? '', ! empty( $input['confirmed'] ), substr( sanitize_textarea_field( $input['reason'] ?? '' ), 0, 2000 ) );
                if ( ! is_wp_error( $result ) ) { $result = self::store( $result ); }
            } catch ( Throwable $e ) { $result = new WP_Error( 'capture', 'Assessment could not be completed. No redirect was applied; try a new preview.' ); }
            $notice = 'saved';
        } else { $result = new WP_Error( 'operation', 'Unknown preview action.' ); }
        // Bounded status code survives deployments with inconsistent transient caches across workers.
        if ( is_wp_error( $result ) ) { $notice = $result->get_error_code(); }
        wp_safe_redirect( add_query_arg( 'preview_notice', $notice, admin_url( 'admin.php?page=ace-seo-redirects&tab=auto' ) ) ); exit;
    }

    public static function render() {
        if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) { return; }
        include __DIR__ . '/admin/views/retention-prototype.php';
    }
}
