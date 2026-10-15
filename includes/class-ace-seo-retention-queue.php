<?php
/** Dev-only, explicitly approved redirect queue. Durable state never depends on transients. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AceSeoRetentionQueue {
    const HOOK = 'ace_seo_retention_approved_tick';
    const CONTROL = 'ace_seo_retention_queue_control';
    const LOCK = 'ace_seo_retention_queue_lock';
    const TTL = DAY_IN_SECONDS;

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'install' ) );
        add_action( 'admin_post_ace_retention_queue', array( __CLASS__, 'handle' ) );
        add_action( self::HOOK, array( __CLASS__, 'tick' ) );
    }

    public static function table( $kind ) { global $wpdb; return $wpdb->prefix . 'ace_retention_' . $kind; }

    public static function install() {
        if ( ! AceSeoRetentionPrototype::enabled() || ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        if ( '1' === (string) AceSeoRetentionFresh::option( 'ace_retention_queue_schema', '' ) ) { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $jobs = self::table( 'jobs' ); $events = self::table( 'events' );
        dbDelta( "CREATE TABLE $jobs (
            plan_id bigint(20) unsigned NOT NULL,
            source_id bigint(20) unsigned NOT NULL,
            state varchar(24) NOT NULL,
            actor bigint(20) unsigned NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            updated bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (plan_id),
            KEY state_updated (state,updated)
        ) ENGINE=InnoDB $charset;" );
        dbDelta( "CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) unsigned NOT NULL,
            kind varchar(32) NOT NULL,
            recorded bigint(20) unsigned NOT NULL,
            actor bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY plan_id (plan_id),
            KEY kind_recorded (kind,recorded)
        ) ENGINE=InnoDB $charset;" );
        if ( ! $wpdb->last_error ) { update_option( 'ace_retention_queue_schema', '1', false ); }
    }

    public static function control() {
        $c = AceSeoRetentionFresh::option( self::CONTROL );
        return array_merge( array( 'enabled' => false, 'scope' => array(), 'daily_cap' => 3, 'revision' => '', 'by' => 0 ), is_array( $c ) ? $c : array() );
    }

    public static function configure( $enabled, array $scope, $cap ) {
        if ( ! AceSeoRetentionPrototype::enabled() || ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', 'Administrator permission required.' ); }
        $scope = array_values( array_filter( $scope, static function( $id ) { return '' !== (string) $id; } ) );
        foreach ( $scope as $id ) { if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id ) ) { return new WP_Error( 'scope', 'Source IDs must be positive whole numbers.' ); } }
        $scope = array_values( array_unique( array_map( 'absint', $scope ) ) );
        if ( count( $scope ) > 10 || $cap < 1 || $cap > 3 || ( $enabled && ! $scope ) ) { return new WP_Error( 'scope', 'Use up to 10 explicit source IDs and a daily cap of 1 to 3.' ); }
        foreach ( $scope as $id ) { if ( ! current_user_can( 'edit_post', $id ) ) { return new WP_Error( 'permission', 'Every scoped source must be editable.' ); } }
        // One atomic DB update: a kill switch is visible to other processes immediately.
        $next = array( 'enabled' => (bool) $enabled, 'scope' => $scope, 'daily_cap' => (int) $cap, 'revision' => wp_generate_uuid4(), 'by' => get_current_user_id() );
        global $wpdb;
        $raw = maybe_serialize( $next );
        $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", self::CONTROL, $raw ) );
        if ( false === $ok ) { return new WP_Error( 'storage', 'Could not save the durable queue control.' ); }
        wp_cache_delete( self::CONTROL, 'options' );
        try { self::event( 0, $enabled ? 'enabled' : 'paused', $next ); }
        catch ( Throwable $e ) {
            // Never leave a newly enabled worker running without its control audit.
            $next['enabled'] = false;
            $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $next ) ), array( 'option_name' => self::CONTROL ) );
            wp_cache_delete( self::CONTROL, 'options' ); wp_clear_scheduled_hook( self::HOOK );
            return new WP_Error( 'audit', 'Queue control audit failed. Processing was stopped.' );
        }
        if ( $enabled ) {
            if ( ! wp_next_scheduled( self::HOOK ) ) {
                $scheduled = wp_schedule_event( time() + 60, 'hourly', self::HOOK, array(), true );
                if ( is_wp_error( $scheduled ) || ! $scheduled ) { self::configure( false, $scope, $cap ); return new WP_Error( 'schedule', 'Scheduling failed; the queue has been paused.' ); }
            }
        } else { wp_clear_scheduled_hook( self::HOOK ); }
        return true;
    }

    public static function event( $plan, $kind, array $payload ) {
        global $wpdb;
        if ( false === $wpdb->insert( self::table( 'events' ), array( 'plan_id' => $plan, 'kind' => $kind, 'recorded' => time(), 'actor' => get_current_user_id(), 'payload' => wp_json_encode( $payload ) ) ) ) {
            throw new RuntimeException( 'The durable audit event could not be recorded.' );
        }
    }

    public static function job( $plan ) {
        global $wpdb;
        $r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'jobs' ) . ' WHERE plan_id=%d', $plan ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot read queue state.' ); }
        if ( $r ) { $r['payload'] = json_decode( $r['payload'], true ); }
        return $r;
    }

    private static function state( $plan, $state, array $payload ) {
        global $wpdb;
        if ( false === $wpdb->update( self::table( 'jobs' ), array( 'state' => $state, 'updated' => time(), 'payload' => wp_json_encode( $payload ) ), array( 'plan_id' => $plan ) ) ) { throw new RuntimeException( 'Cannot save queue state.' ); }
        self::event( $plan, $state, $payload );
    }

    protected static function preflight( array $s ) {
        return AceSeoRetentionPrototype::capture( $s['source']['id'], $s['target']['url'], $s['context']['period']['start'], $s['context']['period']['end'], $s['review']['confirmed'], $s['review']['reason'] );
    }

    private static function evidence_key( array $s ) {
        $c = $s['context']; unset( $c['collected_at'] );
        foreach ( array_keys( $c['link_evidence'] ?? array() ) as $key ) { unset( $c['link_evidence'][ $key ]['checked_at'] ); }
        return hash( 'sha256', wp_json_encode( $c ) );
    }

    public static function approve( $plan ) {
        if ( ! AceSeoRetentionPrototype::enabled() || ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', 'Administrator permission required.' ); }
        $post = AceSeoRetentionFresh::post( $plan );
        $s = $post && AceSeoRetentionPrototype::TYPE === $post->post_type ? AceSeoRetentionPrototype::read( $post ) : null;
        if ( ! $s || AceSeoRetentionPrototype::VERSION !== ( $s['version'] ?? '' ) || ! empty( $s['holds'] ) || time() - $s['created'] > self::TTL ) { return new WP_Error( 'held', 'Create a fresh assessment with no holds before approving.' ); }
        $control = self::control();
        if ( ! AceSeoRetentionFresh::authorised( get_current_user_id(), array( (int) $s['source']['id'], (int) $s['target']['id'] ) ) ) { return new WP_Error( 'permission', 'Current edit and administrator permissions are required.' ); }
        if ( ! in_array( (int) $s['source']['id'], $control['scope'], true ) ) { return new WP_Error( 'scope', 'Add this source to the explicit scope first.' ); }
        $fresh = static::preflight( $s );
        if ( is_wp_error( $fresh ) || ! empty( $fresh['holds'] ) || $fresh['fingerprint'] !== $s['fingerprint'] || self::evidence_key( $fresh ) !== self::evidence_key( $s ) ) { return new WP_Error( 'changed', 'Fresh preflight is held or inputs changed. Save a new assessment.' ); }
        global $wpdb;
        $payload = array( 'snapshot' => $s, 'control' => $control['revision'], 'digest' => hash( 'sha256', wp_json_encode( $s ) ) );
        $existing = self::job( $plan );
        if ( $existing ) { return 'approved' === $existing['state'] ? true : new WP_Error( 'used', 'This assessment has already been processed. Create a new one.' ); }
        $ok = $wpdb->insert( self::table( 'jobs' ), array( 'plan_id' => $plan, 'source_id' => $s['source']['id'], 'state' => 'preparing', 'actor' => get_current_user_id(), 'expires' => min( time() + self::TTL, $s['created'] + self::TTL ), 'updated' => time(), 'payload' => wp_json_encode( $payload ) ) );
        if ( false === $ok ) { return new WP_Error( 'storage', 'Approval could not be saved.' ); }
        self::event( $plan, 'approved', $payload );
        if ( false === $wpdb->update( self::table( 'jobs' ), array( 'state' => 'approved' ), array( 'plan_id' => $plan ) ) ) { return new WP_Error( 'storage', 'Approval could not be finalised.' ); }
        return true;
    }

    /** Unique DB row plus compare-and-swap lease; no cache-based lock and no blind stale delete. */
    protected static function acquire() {
        global $wpdb;
        $owner = wp_generate_uuid4(); $value = $owner . '|' . ( time() + 300 );
        if ( 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::LOCK, $value ) ) ) { return $value; }
        $old = AceSeoRetentionFresh::option( self::LOCK, '' ); $parts = explode( '|', $old );
        if ( 2 === count( $parts ) && (int) $parts[1] < time()
            && 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", $value, self::LOCK, $old ) ) ) { return $value; }
        return false;
    }

    protected static function release( $lease ) {
        global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", self::LOCK, $lease ) );
    }

    private static function transaction( array $ids ) {
        global $wpdb;
        if ( false === stripos( get_class( $wpdb ), 'sqlite' ) ) {
            foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->options, $wpdb->users, $wpdb->usermeta, self::table( 'jobs' ), self::table( 'events' ) ) as $table ) {
                $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
                if ( 'innodb' !== strtolower( (string) $engine ) ) { throw new RuntimeException( 'Transactional InnoDB tables are required.' ); }
            }
        }
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'Transactions unavailable.' ); }
        // SQLite's transaction provides its write lock; InnoDB needs row/range locks too.
        if ( false === stripos( get_class( $wpdb ), 'sqlite' ) ) {
            foreach ( $ids as $id ) {
                foreach ( array( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE", "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id=%d FOR UPDATE" ) as $sql ) {
                    if ( false === $wpdb->query( $wpdb->prepare( $sql, $id ) ) ) { throw new RuntimeException( 'Article locks unavailable.' ); }
                }
            }
            foreach ( array( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id=%d FOR UPDATE" ) as $sql ) {
                if ( false === $wpdb->query( $wpdb->prepare( $sql, get_current_user_id() ) ) ) { throw new RuntimeException( 'Permission locks unavailable.' ); }
            }
            $names = array( self::CONTROL, self::LOCK, AceSeoRetentionReport::PROGRESS_OPTION, AceSeoRetentionActions::OPTION, 'blog_public', 'home', 'siteurl', 'page_on_front', 'page_for_posts', 'permalink_structure', $wpdb->prefix . 'user_roles' );
            foreach ( $names as $key ) { if ( false === $wpdb->query( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $key ) ) ) { throw new RuntimeException( 'Settings locks unavailable.' ); } }
        }
    }

    public static function tick() {
        if ( ! AceSeoRetentionPrototype::enabled() ) { return; }
        $lease = static::acquire(); if ( ! $lease ) { return; }
        $old_user = get_current_user_id();
        try {
            global $wpdb;
            $pending = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'jobs' ) . " WHERE state IN ('applied','undone') AND payload LIKE '%\"cache_pending\":true%' ORDER BY updated LIMIT 3", ARRAY_A );
            foreach ( $pending as $j ) { self::finish_purge( $j['plan_id'], $j['source_id'], json_decode( $j['payload'], true ) ); }
            // A crash cannot silently retry a possibly half-finished action. Its intent remains auditable.
            $stuck = $wpdb->get_results( "SELECT * FROM " . self::table( 'jobs' ) . " WHERE state='applying' ORDER BY updated LIMIT 10", ARRAY_A );
            foreach ( $stuck as $j ) { self::state( $j['plan_id'], 'review', array_merge( json_decode( $j['payload'], true ), array( 'reason' => 'Interrupted application: inspect the source and create a new assessment.' ) ) ); }
            $c = self::control(); if ( ! $c['enabled'] ) { return; }
            // Exactly one approved pair per run. The hourly schedule cannot sweep an archive.
            $plan = $wpdb->get_var( "SELECT plan_id FROM " . self::table( 'jobs' ) . " WHERE state='approved' ORDER BY updated,plan_id LIMIT 1" );
            if ( $plan ) { static::execute( (int) $plan, $lease ); }
        } finally { wp_set_current_user( $old_user ); static::release( $lease ); }
    }

    protected static function execute( $plan, $lease ) {
        global $wpdb;
        $j = self::job( $plan ); $p = $j['payload']; $s = $p['snapshot'];
        $ids = array( (int) $s['source']['id'], (int) $s['target']['id'] );
        wp_cache_delete( $j['actor'], 'user_meta' ); wp_set_current_user( 0 ); wp_set_current_user( $j['actor'] );
        $c = self::control();
        if ( $j['expires'] <= time() || $c['revision'] !== $p['control'] || ! in_array( $ids[0], $c['scope'], true ) ) { self::state( $plan, 'expired', array_merge( $p, array( 'reason' => 'Approval expired, scope changed or queue configuration changed.' ) ) ); return; }
        if ( ! AceSeoRetentionFresh::authorised( $j['actor'], $ids ) ) { self::state( $plan, 'held', array_merge( $p, array( 'reason' => 'Approver no longer has the required permissions.' ) ) ); return; }
        $fresh = static::preflight( $s );
        $saved = AceSeoRetentionFresh::post( $plan );
        if ( is_wp_error( $fresh ) || ! empty( $fresh['holds'] ) || $s['fingerprint'] !== $fresh['fingerprint'] || self::evidence_key( $s ) !== self::evidence_key( $fresh )
            || ! $saved || $p['digest'] !== hash( 'sha256', wp_json_encode( AceSeoRetentionPrototype::read( $saved ) ) ) ) {
            self::state( $plan, 'held', array_merge( $p, array( 'reason' => 'Fresh evidence, destination or snapshot validation failed.', 'preflight' => is_wp_error( $fresh ) ? $fresh->get_error_message() : $fresh ) ) ); return;
        }
        $committed = false;
        try {
            self::transaction( array_merge( $ids, array( $plan ) ) );
            $c = self::control();
            if ( ! $c['enabled'] || $c['revision'] !== $p['control'] || $j['expires'] <= time() || AceSeoRetentionFresh::option( self::LOCK, '' ) !== $lease || (int) explode( '|', $lease )[1] <= time() || ! AceSeoRetentionPrototype::enabled() ) { throw new RuntimeException( 'Queue paused, configuration changed or lease/approval expired.' ); }
            if ( ! AceSeoRetentionFresh::authorised( $j['actor'], $ids ) ) { throw new RuntimeException( 'Permissions changed during preflight.' ); }
            $saved = AceSeoRetentionFresh::post( $plan );
            if ( ! $saved || $p['digest'] !== hash( 'sha256', wp_json_encode( AceSeoRetentionPrototype::read( $saved ) ) ) ) { throw new RuntimeException( 'Assessment changed during preflight.' ); }
            $attempts = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'events' ) . ' WHERE kind=%s AND recorded >= %d', 'apply-intent', strtotime( 'today UTC' ) ) );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot verify the daily cap.' ); }
            if ( $attempts >= $c['daily_cap'] ) { $wpdb->query( 'ROLLBACK' ); return; }
            AceSeoRetentionFresh::run( $ids, static function() use ( $ids, $plan, $s, &$p, $fresh ) {
                if ( AceSeoRetentionPrototype::fingerprint( $ids[0], $ids[1] ) !== $s['fingerprint'] ) { throw new RuntimeException( 'Inputs changed during preflight.' ); }
                $p['before'] = AceSeoRedirects::settings( $ids[0] ); $p['preflight'] = $fresh;
                if ( $p['before']['target'] ) { throw new RuntimeException( 'Source already has a rule.' ); }
                self::state( $plan, 'applying', $p ); self::event( $plan, 'apply-intent', $p );
                $ok = AceSeoRedirects::save( $ids[0], array( 'target' => $s['target']['url'], 'code' => 301 ) );
                if ( is_wp_error( $ok ) ) { throw new RuntimeException( $ok->get_error_message() ); }
                $p['after'] = AceSeoRedirects::settings( $ids[0] );
                if ( $p['after']['target'] !== $s['target']['url'] || 301 !== $p['after']['code'] || $p['after']['paused'] || $p['after']['expires'] || $p['after']['trash'] ) { throw new RuntimeException( 'Redirect write could not be verified.' ); }
                $p['after_fingerprint'] = AceSeoRetentionPrototype::fingerprint( $ids[0], $ids[1] );
                $p['cache_pending'] = true;
                self::state( $plan, 'applied', $p );
            } );
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Commit failed; manual verification required.' ); }
            $committed = true;
            self::finish_purge( $plan, $ids[0], $p );
        } catch ( Throwable $e ) {
            if ( $committed ) { self::event( $plan, 'cache-pending', array( 'reason' => 'Application committed; cache purge will retry.' ) ); return; }
            $wpdb->query( 'ROLLBACK' );
            AceSeoRedirects::purge( $ids[0] );
            self::state( $plan, 'held', array_merge( $p, array( 'reason' => $e->getMessage() ) ) );
        }
    }

    private static function finish_purge( $plan, $source, array $payload ) {
        global $wpdb;
        AceSeoRedirects::purge( $source );
        $payload['cache_pending'] = false;
        if ( false === $wpdb->update( self::table( 'jobs' ), array( 'payload' => wp_json_encode( $payload ) ), array( 'plan_id' => $plan ) ) ) { throw new RuntimeException( 'Cache completion could not be recorded.' ); }
        self::event( $plan, 'cache-purged', array( 'source' => (int) $source ) );
    }

    public static function undo( $plan ) {
        if ( ! AceSeoRetentionPrototype::enabled() || ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', 'Administrator permission required.' ); }
        $lease = static::acquire(); if ( ! $lease ) { return new WP_Error( 'busy', 'A queue operation is already running.' ); }
        global $wpdb;
        $committed = false;
        try {
            $j = self::job( $plan );
            if ( ! $j || 'applied' !== $j['state'] ) { return new WP_Error( 'state', 'Only an applied job can be undone.' ); }
            $p = $j['payload']; $s = $p['snapshot']; $ids = array( (int) $s['source']['id'], (int) $s['target']['id'] );
            if ( ! current_user_can( 'edit_post', $ids[0] ) ) { return new WP_Error( 'permission', 'Source edit permission required.' ); }
            self::transaction( $ids );
            if ( ! AceSeoRetentionFresh::authorised( get_current_user_id(), array( $ids[0] ) ) ) { throw new RuntimeException( 'Current permissions do not allow undo.' ); }
            AceSeoRetentionFresh::run( $ids, static function() use ( $ids, $p, $plan ) {
                if ( ! empty( $p['before']['target'] ) || AceSeoRetentionPrototype::fingerprint( $ids[0], $ids[1] ) !== $p['after_fingerprint'] || AceSeoRedirects::settings( $ids[0] ) !== $p['after'] ) { throw new RuntimeException( 'Article, settings or rule changed after application. Manual review required; nothing overwritten.' ); }
                $ok = AceSeoRedirects::save( $ids[0], array( 'operation' => 'clear' ) );
                if ( is_wp_error( $ok ) || AceSeoRedirects::settings( $ids[0] ) !== $p['before'] ) { throw new RuntimeException( 'Undo could not be verified.' ); }
                $p['cache_pending'] = true;
                self::state( $plan, 'undone', $p );
            } );
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Undo commit failed.' ); }
            $committed = true;
            self::finish_purge( $plan, $ids[0], self::job( $plan )['payload'] ); return true;
        } catch ( Throwable $e ) { if ( $committed ) { self::event( $plan, 'cache-pending', array( 'reason' => 'Undo committed; cache purge will retry.' ) ); return true; } $wpdb->query( 'ROLLBACK' ); if ( ! empty( $ids[0] ) ) { AceSeoRedirects::purge( $ids[0] ); } self::event( $plan, 'undo-refused', array( 'reason' => $e->getMessage() ) ); return new WP_Error( 'undo', $e->getMessage() ); }
        finally { static::release( $lease ); }
    }

    public static function cancel( $plan ) {
        if ( ! AceSeoRetentionPrototype::enabled() || ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', 'Administrator permission required.' ); }
        $lease = static::acquire(); if ( ! $lease ) { return new WP_Error( 'busy', 'A queue operation is already running.' ); }
        try { $j = self::job( $plan ); if ( ! $j || 'approved' !== $j['state'] ) { return new WP_Error( 'state', 'Only pending approvals can be cancelled.' ); } self::state( $plan, 'cancelled', $j['payload'] ); return true; }
        finally { static::release( $lease ); }
    }

    public static function handle() {
        if ( ! AceSeoRetentionPrototype::enabled() || ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator permission required.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'ace_retention_queue' );
        foreach ( array( 'operation', 'plan', 'scope', 'cap', 'enabled' ) as $k ) { if ( isset( $_POST[ $k ] ) && ! is_scalar( $_POST[ $k ] ) ) { wp_die( 'Invalid form.' ); } }
        $op = sanitize_key( $_POST['operation'] ?? '' ); $plan = absint( $_POST['plan'] ?? 0 );
        try {
            if ( 'configure' === $op ) { $r = self::configure( ! empty( $_POST['enabled'] ), preg_split( '/[\s,]+/', wp_unslash( $_POST['scope'] ?? '' ) ), (int) ( $_POST['cap'] ?? 3 ) ); }
            elseif ( 'pause' === $op ) { $c = self::control(); $r = self::configure( false, $c['scope'], $c['daily_cap'] ); }
            elseif ( 'approve' === $op ) { $r = self::approve( $plan ); }
            elseif ( 'undo' === $op ) { $r = self::undo( $plan ); }
            elseif ( 'cancel' === $op ) { $r = self::cancel( $plan ); }
            elseif ( 'tick' === $op ) { self::tick(); $r = true; }
            else { $r = new WP_Error( 'operation', 'Unknown operation.' ); }
        } catch ( Throwable $e ) { $r = new WP_Error( 'storage', 'Operation stopped. Review the queue and server log.' ); }
        wp_safe_redirect( add_query_arg( 'queue_notice', is_wp_error( $r ) ? $r->get_error_code() : 'saved', admin_url( 'admin.php?page=ace-seo-redirects&tab=auto' ) ) ); exit;
    }
}
