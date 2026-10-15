<?php
/** Per-post redirect lifecycle. Existing target meta and legacy 301s remain compatible. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AceSeoRedirects {
    const META = '_ace_seo_redirect_settings';
    const HOOK = 'ace_seo_redirect_expire';

    public static function init() {
        add_action( self::HOOK, array( __CLASS__, 'expire' ), 10, 2 );
        add_action( 'added_post_meta', array( __CLASS__, 'target_changed' ), 20, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'target_changed' ), 20, 4 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'target_changed' ), 20, 4 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
        add_action( 'admin_post_ace_seo_redirect_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        require_once __DIR__ . '/admin/class-ace-seo-redirect-discovery.php';
        add_action( 'wp_ajax_ace_seo_redirect_destinations', array( 'AceSeoRedirectDiscovery', 'ajax_destinations' ) );
        require_once __DIR__ . '/class-ace-seo-retention-prototype.php';
        AceSeoRetentionPrototype::init();
    }

    public static function settings( $id ) {
        $saved = get_post_meta( $id, self::META, true );
        $target = (string) get_post_meta( $id, AceSeoRetentionActions::META_REDIRECT, true );
        // A legacy writer changing the target must never inherit an old destructive schedule.
        if ( ! is_array( $saved ) || ( $saved['target'] ?? '' ) !== $target ) { $saved = array(); }
        return array_merge( array( 'target' => $target, 'code' => 301, 'created' => 0, 'expires' => 0, 'source_url' => '',
            'paused' => false, 'trash' => false, 'by' => 0, 'completed' => 0, 'error' => '' ), $saved );
    }

    public static function active( array $settings, $now = null ) {
        $now = null === $now ? time() : $now;
        return '' !== $settings['target'] && ! $settings['paused'] && ( ! $settings['expires'] || $settings['expires'] > $now );
    }

    public static function state( array $settings ) {
        if ( $settings['expires'] && $settings['expires'] <= time() ) { return 'Expired'; }
        return $settings['paused'] ? 'Paused' : 'Active';
    }

    public static function label( $id ) {
        $s = self::settings( $id );
        if ( ! $s['target'] ) { return ''; }
        return ( 'gone' === $s['target'] ? '410 Gone' : $s['code'] . ' → ' . wp_make_link_relative( $s['target'] ) ) . ' (' . self::state( $s ) . ')';
    }

    /** Ignore fragments and trailing slashes, but retain host, port and query when comparing. */
    public static function url_key( $url ) {
        $p = wp_parse_url( $url );
        return strtolower( $p['host'] ?? '' ) . ':' . ( $p['port'] ?? '' ) . '/' . trim( $p['path'] ?? '', '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    }

    public static function validate_target( $id, $raw ) {
        $target = AceSeoRetentionActions::normalise_target( $raw );
        if ( ! $target || 'gone' === $target || preg_match( '/[\x00-\x20\\\\]/', $raw ) || preg_match( '/%0[ad]/i', $raw ) ) {
            return new WP_Error( 'target', 'Enter a valid http(s) destination URL or a path on this site.' );
        }
        $parts = wp_parse_url( $target );
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return new WP_Error( 'target', 'Destination URLs cannot contain login details.' ); }
        if ( self::url_key( $target ) === self::url_key( get_permalink( $id ) ) ) {
            return new WP_Error( 'loop', 'A post cannot redirect to itself.' );
        }
        $destination = url_to_postid( $target );
        if ( $destination ) {
            if ( (int) $destination === (int) $id ) { return new WP_Error( 'loop', 'A post cannot redirect to itself.' ); }
            if ( 'publish' !== get_post_status( $destination ) ) { return new WP_Error( 'target', 'Choose a published destination.' ); }
            if ( self::active( self::settings( $destination ) ) ) { return new WP_Error( 'chain', 'That destination already redirects or answers 410. Choose its final destination to avoid a chain or loop.' ); }
        }
        return $target;
    }

    /** Validate everything before changing meta. All entry points share these checks. */
    public static function save( $id, array $input ) {
        foreach ( array( 'operation', 'target', 'code', 'expires', 'trash', 'paused' ) as $key ) {
            if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) { return new WP_Error( 'input', 'Invalid redirect form data.' ); }
        }
        $post = get_post( $id );
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) ) {
            return new WP_Error( 'permission', 'You do not have permission to manage redirects for this post.' );
        }
        if ( ! $post || 'attachment' === $post->post_type || ! in_array( $post->post_type, get_post_types( array( 'public' => true ) ), true ) ) {
            return new WP_Error( 'post', 'Choose a public post or page.' );
        }
        $old = self::settings( $id );
        $operation = $input['operation'] ?? 'save';
        // Saving unrelated post edits must not reschedule, re-authorise or reject an unchanged rule.
        if ( 'save' === $operation && $old['target'] === trim( (string) ( $input['target'] ?? '' ) )
            && (int) $old['code'] === (int) ( $input['code'] ?? 301 )
            && ( $old['expires'] ? wp_date( 'Y-m-d\TH:i', $old['expires'], wp_timezone() ) : '' ) === (string) ( $input['expires'] ?? '' )
            && (bool) $old['trash'] === ! empty( $input['trash'] ) && (bool) $old['paused'] === ! empty( $input['paused'] ) ) { return true; }
        if ( 'clear' === $operation || ( 'save' === $operation && '' === trim( (string) ( $input['target'] ?? '' ) ) ) ) {
            self::purge( $id );
            wp_clear_scheduled_hook( self::HOOK, array( (int) $id, (int) $old['expires'] ) );
            delete_post_meta( $id, AceSeoRetentionActions::META_REDIRECT );
            delete_post_meta( $id, self::META );
            self::record( $id, 'redirect-clear', $old );
            self::purge( $id );
            return true;
        }
        // Disabling a rule must remain possible if its destination or deletion rights changed.
        if ( 'pause' === $operation ) {
            if ( ! $old['target'] ) { return new WP_Error( 'missing', 'This post no longer has a redirect.' ); }
            $next = $old;
            $next['paused'] = true;
            wp_clear_scheduled_hook( self::HOOK, array( (int) $id, (int) $old['expires'] ) );
            update_post_meta( $id, self::META, $next );
            self::record( $id, 'redirect-pause', array( 'before' => $old, 'after' => $next ) );
            self::purge( $id );
            return true;
        }
        if ( 'publish' !== $post->post_status ) { return new WP_Error( 'post', 'Publish or restore the source post before enabling a redirect.' ); }
        if ( 'resume' === $operation ) {
            if ( ! $old['target'] ) { return new WP_Error( 'missing', 'This post no longer has a redirect.' ); }
            if ( 'resume' === $operation && $old['expires'] && $old['expires'] <= time() ) { return new WP_Error( 'expired', 'Set a future expiry or remove the expiry before resuming.' ); }
            $input = array( 'target' => $old['target'], 'code' => $old['code'], 'expires' => $old['expires'] ? wp_date( 'Y-m-d\TH:i', $old['expires'], wp_timezone() ) : '', 'trash' => $old['trash'], 'paused' => 'pause' === $operation );
        } elseif ( 'save' !== $operation ) { return new WP_Error( 'operation', 'Unknown redirect action.' ); }
        $raw = trim( (string) ( $input['target'] ?? '' ) );
        $target = 'gone' === $raw ? 'gone' : self::validate_target( $id, $raw );
        if ( is_wp_error( $target ) ) { return $target; }
        $code = (int) ( $input['code'] ?? 301 );
        if ( ! in_array( $code, array( 301, 302, 307, 308 ), true ) ) { return new WP_Error( 'code', 'Choose a supported redirect type.' ); }
        $expiry = trim( (string) ( $input['expires'] ?? '' ) );
        $expires = 0;
        if ( '' !== $expiry ) {
            $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $expiry, wp_timezone() );
            if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $expiry || $date->getTimestamp() <= time() ) {
                return new WP_Error( 'expiry', 'Choose a valid future expiry in the site timezone.' );
            }
            $expires = $date->getTimestamp();
        }
        $trash = ! empty( $input['trash'] );
        if ( $trash && ( ! $expires || ! current_user_can( 'delete_post', $id ) || ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS < 1 ) ) {
            return new WP_Error( 'trash', 'Automatic Trash needs a future expiry, permission to delete this post, and WordPress Trash enabled.' );
        }
        $next = array( 'target' => $target, 'code' => $code, 'created' => $old['created'] ?: time(), 'expires' => $expires, 'source_url' => get_permalink( $id ),
            'paused' => ! empty( $input['paused'] ), 'trash' => $trash, 'by' => get_current_user_id(), 'completed' => 0, 'error' => '' );
        // Check scheduling before saving. A failure must not leave an unscheduled Trash action.
        if ( $expires && ! $next['paused'] && ! wp_next_scheduled( self::HOOK, array( (int) $id, $expires ) ) ) {
            $scheduled = wp_schedule_single_event( $expires, self::HOOK, array( (int) $id, $expires ), true );
            if ( is_wp_error( $scheduled ) || ! $scheduled ) { return new WP_Error( 'schedule', 'WordPress could not schedule expiry. No changes were saved; try again.' ); }
        }
        if ( $old['expires'] && ( $old['expires'] !== $expires || $next['paused'] ) ) {
            wp_clear_scheduled_hook( self::HOOK, array( (int) $id, (int) $old['expires'] ) );
        }
        // Store settings first so the target-change hook recognises this managed update.
        update_post_meta( $id, self::META, $next );
        update_post_meta( $id, AceSeoRetentionActions::META_REDIRECT, $target );
        self::record( $id, 'redirect-' . $operation, array( 'before' => $old, 'after' => $next ) );
        self::purge( $id );
        return true;
    }

    public static function target_changed( $meta_id, $id, $key, $value ) {
        if ( AceSeoRetentionActions::META_REDIRECT !== $key ) { return; }
        $saved = get_post_meta( $id, self::META, true );
        $target = (string) get_post_meta( $id, $key, true );
        if ( is_array( $saved ) && ( $saved['target'] ?? '' ) !== $target ) {
            wp_clear_scheduled_hook( self::HOOK, array( (int) $id, (int) ( $saved['expires'] ?? 0 ) ) );
            delete_post_meta( $id, self::META );
        }
        self::purge( $id );
    }

    public static function expire( $id, $expected ) {
        $s = self::settings( $id );
        if ( ! $s['expires'] || (int) $expected !== (int) $s['expires'] || $s['expires'] > time() || $s['paused'] || $s['completed'] ) { return; }
        $s['completed'] = time();
        if ( $s['trash'] ) {
            if ( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS < 1 || ! user_can( $s['by'], 'delete_post', $id ) || 'publish' !== get_post_status( $id ) ) {
                $s['error'] = 'Redirect expired. The post was not moved to Trash because its status, permissions or Trash settings changed.';
            } elseif ( ! wp_trash_post( $id ) ) { $s['error'] = 'Redirect expired, but WordPress could not move the post to Trash.'; }
        }
        update_post_meta( $id, self::META, $s );
        self::record( $id, 'redirect-expired', $s );
        self::purge( $id );
    }

    private static function record( $id, $action, $value ) {
        AceSeoRetentionActions::log( $id, $action, $value, 'redirect-manager' );
    }

    public static function purge( $id ) {
        clean_post_cache( $id );
        if ( class_exists( 'ACE_SEO_Frontend_Performance' ) ) { ACE_SEO_Frontend_Performance::clear_post_cache( $id ); }
        $url = get_permalink( $id );
        if ( $url ) { do_action( 'ace_redis_cache_purge_url', $url ); }
        $s = self::settings( $id );
        if ( $s['source_url'] && $s['source_url'] !== $url ) { do_action( 'ace_redis_cache_purge_url', $s['source_url'] ); }
    }

    public static function menu() {
        add_submenu_page( 'ace-seo', 'Redirect Manager', 'Redirects', 'manage_options', 'ace-seo-redirects', array( __CLASS__, 'render' ) );
    }

    public static function assets( $hook ) {
        if ( false === strpos( $hook, 'ace-seo-redirects' ) && ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        wp_enqueue_style( 'ace-seo-redirects', ACE_SEO_URL . 'assets/css/redirects.css', array(), ACE_SEO_VERSION );
        wp_enqueue_script( 'ace-seo-redirects', ACE_SEO_URL . 'assets/js/redirects.js', array(), ACE_SEO_VERSION, true );
    }

    public static function notice() {
        $key = 'ace_seo_redirect_notice_' . get_current_user_id();
        $message = get_transient( $key );
        if ( ! $message ) { return; }
        delete_transient( $key );
        echo '<div class="notice notice-' . ( $message['error'] ? 'error' : 'success' ) . '"><p>' . esc_html( $message['text'] ) . '</p></div>';
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You do not have permission to manage redirects.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'ace_seo_redirect_save' );
        $input = wp_unslash( $_POST );
        $ids = array_slice( array_unique( array_map( 'absint', (array) ( $input['ids'] ?? array() ) ) ), 0, 50 );
        $count = 0; $errors = array();
        foreach ( $ids as $id ) {
            $result = self::save( $id, $input );
            if ( is_wp_error( $result ) ) { $errors[] = '#' . $id . ': ' . $result->get_error_message(); } else { $count++; }
        }
        if ( ! $ids ) { $errors[] = 'Select at least one source post.'; }
        set_transient( 'ace_seo_redirect_notice_' . get_current_user_id(), array( 'error' => (bool) $errors, 'text' => $count . ' redirect(s) updated. ' . implode( ' ', $errors ) ), 120 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-redirects' ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        include ACE_SEO_PATH . 'includes/admin/views/redirects.php';
    }

    /** Shared controls inside either the manager form or the existing post editor form. */
    public static function fields( $id = 0, $prefix = '' ) {
        $s = self::settings( $id );
        include ACE_SEO_PATH . 'includes/admin/views/redirect-fields.php';
    }
}
