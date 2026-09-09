<?php
/**
 * Ace SEO Term Redirect
 *
 * 301s a taxonomy archive to another term or URL, driven entirely by the
 * `_ace_seo_redirect` term meta. Nothing site-specific lives here: the
 * mechanism ships with the plugin, the targets live in each site's database.
 *
 * Scope is the archive itself (including its paged and feed variants) only.
 * Posts beneath the term keep their own URLs, which matters on sites using a
 * /%category%/ permalink structure where the term is still a live section.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACE_SEO_Term_Redirect {

    /**
     * Term meta key holding the redirect target.
     *
     * Accepts a term ID (same taxonomy) or an absolute URL.
     */
    const META_KEY = ACE_SEO_META_PREFIX . 'redirect';

    public function init() {
        add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
    }

    /**
     * Redirect the current taxonomy archive if a target is configured.
     */
    public function maybe_redirect() {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        if ( ! is_category() && ! is_tag() && ! is_tax() ) {
            return;
        }

        $term = get_queried_object();
        if ( ! $term instanceof WP_Term ) {
            return;
        }

        $target = $this->resolve_target( $term );
        if ( '' === $target ) {
            return;
        }

        $target = $this->carry_over_request( $target, $term );

        wp_safe_redirect( $target, 301 );
        exit;
    }

    /**
     * Turn the stored meta value into an absolute URL.
     *
     * Returns an empty string when nothing is configured, when the target
     * cannot be resolved, or when it would redirect the term to itself.
     */
    private function resolve_target( WP_Term $term ) {
        $raw = get_term_meta( $term->term_id, self::META_KEY, true );
        $raw = is_string( $raw ) ? trim( $raw ) : '';

        /**
         * Filter a term's redirect target before it is used.
         *
         * @param string  $raw  Stored meta value (term ID or absolute URL).
         * @param WP_Term $term The term being viewed.
         */
        $raw = (string) apply_filters( 'ace_seo_term_redirect_target', $raw, $term );

        if ( '' === $raw ) {
            return '';
        }

        if ( is_numeric( $raw ) ) {
            $target_id = (int) $raw;

            // Self-reference would loop forever.
            if ( $target_id === (int) $term->term_id ) {
                return '';
            }

            $target_term = get_term( $target_id, $term->taxonomy );
            if ( ! $target_term instanceof WP_Term ) {
                return '';
            }

            // Only follow a single hop: if the destination itself redirects,
            // the chain is a configuration mistake rather than something to
            // resolve at request time.
            $link = get_term_link( $target_term );

            return is_wp_error( $link ) ? '' : $this->normalise( $link );
        }

        $url = esc_url_raw( $raw );
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return '';
        }

        $url = $this->normalise( $url );

        // wp_safe_redirect() would quietly send an off-site target to wp-admin
        // instead, so refuse it here rather than redirecting somewhere the
        // author plainly did not mean. Sites that genuinely want an external
        // target can permit the host via allowed_redirect_hosts.
        if ( '' === wp_validate_redirect( $url, '' ) ) {
            return '';
        }

        // Never redirect a term to the URL it is already serving.
        $current = get_term_link( $term );
        if ( ! is_wp_error( $current ) ) {
            $current = $this->normalise( $current );
            if ( untrailingslashit( $current ) === untrailingslashit( $url ) ) {
                return '';
            }
        }

        return $url;
    }

    /**
     * Tidy the path of a resolved URL.
     *
     * A site whose category base is "." (used to lift category archives to the
     * root) makes get_term_link() return paths like /./football/. Browsers
     * normalise that away, but it is not something to put in a Location header
     * or hand to Google, so collapse the no-op segments and any doubled
     * slashes before redirecting.
     */
    private function normalise( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['path'] ) ) {
            return $url;
        }

        $path = preg_replace( '#/\.(?=/|$)#', '', $parts['path'] );
        $path = preg_replace( '#/{2,}#', '/', $path );
        if ( '' === $path ) {
            $path = '/';
        }

        if ( $path === $parts['path'] ) {
            return $url;
        }

        $rebuilt = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' )
            . ( $parts['host'] ?? '' )
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
            . $path
            . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

        return $rebuilt;
    }

    /**
     * Preserve pagination, feeds and the query string across the redirect.
     *
     * A request for /old/page/3/ lands on /new/page/3/ rather than dumping
     * every paged URL onto the destination's first page.
     */
    private function carry_over_request( $target, WP_Term $term ) {
        $suffix = $this->archive_suffix( $term );
        if ( '' !== $suffix ) {
            $target = user_trailingslashit( trailingslashit( $target ) . $suffix );
        }

        $query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
        if ( '' !== $query ) {
            $target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
        }

        return $target;
    }

    /**
     * The part of the request path that sits below the term archive itself.
     */
    private function archive_suffix( WP_Term $term ) {
        $parts = array();

        $paged = (int) get_query_var( 'paged' );
        if ( $paged > 1 ) {
            global $wp_rewrite;
            $base    = ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->pagination_base )
                ? $wp_rewrite->pagination_base
                : 'page';
            $parts[] = $base . '/' . $paged;
        }

        if ( is_feed() ) {
            $feed    = get_query_var( 'feed' );
            $parts[] = 'feed' . ( $feed && 'feed' !== $feed ? '/' . $feed : '' );
        }

        return implode( '/', $parts );
    }
}
