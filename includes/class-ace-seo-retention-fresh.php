<?php
/** Bounded authoritative reads for the retention queue; object caches are not locks. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AceSeoRetentionFresh {
    public static function option( $key, $default = array() ) {
        // WordPress configuration overrides migrated database URLs. Honour that trusted
        // runtime identity while still avoiding stale object-cache option values.
        if ( 'home' === $key && defined( 'WP_HOME' ) && WP_HOME ) { return untrailingslashit( WP_HOME ); }
        if ( 'siteurl' === $key && defined( 'WP_SITEURL' ) && WP_SITEURL ) { return untrailingslashit( WP_SITEURL ); }
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $key ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot read durable settings.' ); }
        return null === $raw ? $default : maybe_unserialize( $raw );
    }

    public static function post( $id ) {
        global $wpdb;
        $post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $id ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot read durable article.' ); }
        return $post ? new WP_Post( $post ) : null;
    }

    public static function meta( $id, $key, $single = true ) {
        global $wpdb;
        $values = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id", $id, $key ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot read durable evidence.' ); }
        return $single ? ( $values ? maybe_unserialize( $values[0] ) : '' ) : array_map( 'maybe_unserialize', $values );
    }

    /** Run existing WordPress validation against current DB facts, restoring filters even on errors. */
    public static function run( array $ids, callable $callback ) {
        global $wpdb;
        foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
            wp_cache_delete( $id, 'posts' ); wp_cache_delete( $id, 'post_meta' );
            $post = self::post( $id );
            if ( $post ) { wp_cache_set( $id, $post, 'posts' ); }
            if ( $post ) { foreach ( get_object_taxonomies( $post->post_type ) as $tax ) { wp_cache_delete( $id, $tax . '_relationships' ); } }
        }
        $keys = array( AceSeoRetentionActions::OPTION, AceSeoRetentionReport::PROGRESS_OPTION, 'blog_public', 'home', 'siteurl', 'page_on_front', 'page_for_posts', 'permalink_structure', $wpdb->prefix . 'user_roles' );
        $options = static function( $pre, $key, $default ) use ( $keys ) {
            return in_array( $key, $keys, true ) ? self::option( $key, false === $default ? '' : $default ) : $pre;
        };
        $metadata = static function( $pre, $id, $key, $single ) use ( $ids ) {
            if ( ! $key || ! in_array( (int) $id, $ids, true ) ) { return $pre; }
            // Core unwraps the first value for single reads, including serialised array values.
            $values = self::meta( $id, $key, false );
            return $single && ! $values ? array( '' ) : $values;
        };
        add_filter( 'pre_option', $options, PHP_INT_MAX, 3 );
        add_filter( 'get_post_metadata', $metadata, PHP_INT_MAX, 4 );
        try { return $callback(); }
        finally { remove_filter( 'pre_option', $options, PHP_INT_MAX ); remove_filter( 'get_post_metadata', $metadata, PHP_INT_MAX ); }
    }

    public static function generation( $source ) {
        $p = self::option( AceSeoRetentionReport::PROGRESS_OPTION );
        $row = self::meta( $source, AceSeoRetentionReport::META );
        if ( 'done' !== ( $p['phase'] ?? '' ) || empty( $p['generation'] ) || empty( $p['finished'] )
            || ! is_array( $row ) || ( $row['generation'] ?? '' ) !== $p['generation'] ) { return null; }
        return array( 'id' => $p['generation'], 'started' => $p['started'], 'finished' => $p['finished'], 'settings' => $p['settings'] );
    }

    public static function authorised( $actor, array $ids ) {
        global $wpdb;
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d", $actor ) ) ) { return false; }
        return self::run( $ids, static function() use ( $actor, $ids ) {
            global $wpdb, $wp_roles;
            $metadata = static function( $pre, $id, $key, $single ) use ( $actor ) {
                global $wpdb;
                if ( (int) $id !== (int) $actor || ! $key ) { return $pre; }
                $values = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id", $id, $key ) );
                if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot verify current permissions.' ); }
                return $single && ! $values ? array( '' ) : array_map( 'maybe_unserialize', $values );
            };
            $old_roles = $wp_roles;
            add_filter( 'get_user_metadata', $metadata, PHP_INT_MAX, 4 );
            try {
                $wp_roles = new WP_Roles(); $user = new WP_User( $actor );
                if ( ! user_can( $user, 'manage_options' ) ) { return false; }
                foreach ( $ids as $id ) { if ( ! user_can( $user, 'edit_post', $id ) ) { return false; } }
                return true;
            } finally { $wp_roles = $old_roles; remove_filter( 'get_user_metadata', $metadata, PHP_INT_MAX ); }
        } );
    }
}
