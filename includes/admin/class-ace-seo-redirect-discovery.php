<?php
/** Bounded destination searches and read-only retention views for the redirect manager. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AceSeoRedirectDiscovery {
    const LIMIT = 20;

    public static function types() {
        $types = array();
        foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
            if ( 'attachment' !== $type->name && is_post_type_viewable( $type ) ) {
                $types[ 'post:' . $type->name ] = $type->labels->name;
            }
        }
        foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
            if ( is_taxonomy_viewable( $taxonomy ) ) { $types[ 'term:' . $taxonomy->name ] = $taxonomy->labels->name; }
        }
        return $types;
    }

    public static function ajax_destinations() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'You do not have permission to search redirect destinations.' ), 403 ); }
        check_ajax_referer( 'ace_seo_redirect_destinations', 'nonce' );
        $result = self::destinations( wp_unslash( $_POST ) );
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
        wp_send_json_success( $result );
    }

    /** Search titles/names only; no content scan, totals query or unbounded archive load. */
    public static function destinations( array $input ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', 'You do not have permission to search redirect destinations.' ); }
        if ( ! is_string( $input['search'] ?? null ) || ! is_array( $input['types'] ?? null ) ) { return new WP_Error( 'input', 'Enter a search and choose at least one type.' ); }
        $search = trim( sanitize_text_field( $input['search'] ) );
        if ( strlen( $search ) < 2 || strlen( $search ) > 100 ) { return new WP_Error( 'search', 'Use between 2 and 100 characters.' ); }
        $allowed = self::types(); $posts = array(); $taxonomies = array();
        foreach ( $input['types'] as $type ) {
            if ( ! is_string( $type ) || ! isset( $allowed[ $type ] ) ) { return new WP_Error( 'type', 'Choose the destination types shown in the form.' ); }
            list( $kind, $name ) = explode( ':', $type, 2 );
            if ( 'post' === $kind ) { $posts[] = $name; } else { $taxonomies[] = $name; }
        }
        if ( ! $posts && ! $taxonomies ) { return new WP_Error( 'type', 'Choose at least one destination type.' ); }
        $items = array(); $more = false;
        if ( $posts ) {
            $query = new WP_Query( array( 'post_type' => array_unique( $posts ), 'post_status' => 'publish', 'has_password' => false,
                's' => $search, 'search_columns' => array( 'post_title' ), 'posts_per_page' => self::LIMIT + 1,
                'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'no_found_rows' => true, 'ignore_sticky_posts' => true,
                'update_post_term_cache' => false ) );
            $more = count( $query->posts ) > self::LIMIT;
            foreach ( array_slice( $query->posts, 0, self::LIMIT ) as $post ) {
                $redirect = AceSeoRedirects::settings( $post->ID );
                $blocked = AceSeoRedirects::active( $redirect );
                $items[] = array( 'title' => get_the_title( $post ), 'url' => get_permalink( $post ), 'type' => $allowed[ 'post:' . $post->post_type ],
                    'blocked' => $blocked, 'note' => $blocked ? 'Already redirects or answers 410. Choose its final destination.' : '' );
            }
        }
        if ( $taxonomies ) {
            $terms = get_terms( array( 'taxonomy' => array_unique( $taxonomies ), 'hide_empty' => false, 'name__like' => $search,
                'number' => self::LIMIT + 1, 'orderby' => 'name', 'order' => 'ASC' ) );
            if ( is_wp_error( $terms ) ) { return $terms; }
            $more = $more || count( $terms ) > self::LIMIT;
            foreach ( array_slice( $terms, 0, self::LIMIT ) as $term ) {
                $url = get_term_link( $term );
                if ( is_wp_error( $url ) ) { continue; }
                $blocked = '' !== (string) get_term_meta( $term->term_id, '_ace_seo_redirect', true );
                $items[] = array( 'title' => $term->name, 'url' => $url, 'type' => $allowed[ 'term:' . $term->taxonomy ],
                    'blocked' => $blocked, 'note' => $blocked ? 'Has an archive redirect rule. Review that rule before choosing this archive.' : '' );
            }
        }
        return array( 'items' => $items, 'more' => $more );
    }

    public static function render( $tab ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $settings_url = admin_url( 'admin.php?page=ace-seo-settings#retention/retention-report' );
        $report_url = admin_url( 'admin.php?page=ace-seo-retention' );
        $options = AceSeoRetentionActions::options();
        include __DIR__ . '/views/redirect-' . ( 'auto' === $tab ? 'auto' : 'suggestions' ) . '.php';
    }

    public static function pagination( $tab, $page, $more, $extra = array() ) {
        echo '<p class="ace-redirect-pagination">';
        foreach ( array( -1 => 'Previous page', 1 => 'Next page' ) as $offset => $label ) {
            if ( ( -1 === $offset && $page <= 1 ) || ( 1 === $offset && ! $more ) ) { continue; }
            $args = array_merge( $extra, array( 'page' => 'ace-seo-redirects', 'tab' => $tab, 'paged' => $page + $offset ) );
            echo '<a class="button" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> ';
        }
        echo '</p>';
    }
}
