<?php
/** Scheduled, resumable Sheets snapshots. No report rebuilds or article changes. */
defined( 'ABSPATH' ) || exit;

class AceSeoSheetsSchedule {
    const OPTION = 'ace_seo_sheets_schedule';
    const JOB = 'ace_seo_sheets_export_job';
    const HISTORY = 'ace_seo_sheets_export_history';
    const TARGETS = 'ace_seo_sheets_report_targets';
    const LOCK = 'ace_seo_sheets_export_lock';
    const START = 'ace_seo_sheets_schedule_start';
    const TICK = 'ace_seo_sheets_schedule_tick';
    const BATCH = 250;
    const LEASE = 300;
    const MAX_FAILURES = 5;

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) );
        add_action( self::START, array( __CLASS__, 'start' ) );
        add_action( self::TICK, array( __CLASS__, 'tick' ) );
        if ( is_admin() ) {
            add_action( 'admin_init', array( __CLASS__, 'sync_schedule' ) );
            add_action( 'admin_post_ace_seo_sheets_schedule', array( __CLASS__, 'handle_settings' ) );
            add_action( 'admin_post_ace_seo_sheets_run', array( __CLASS__, 'handle_run' ) );
            add_action( 'admin_post_ace_seo_sheets_stop', array( __CLASS__, 'handle_stop' ) );
            add_action( 'admin_post_ace_seo_sheets_retry', array( __CLASS__, 'handle_retry' ) );
        }
        if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            add_action( 'wp_loaded', array( __CLASS__, 'sync_schedule' ) );
        }
        add_action( 'ace_seo_retention_built', array( __CLASS__, 'after_build' ) );
    }

    /** Opt-in: a completed retention build refreshes the main report, so the sheet never lags the assessment. */
    public static function after_build() {
        if ( empty( self::settings()['after_build'] ) ) {
            return;
        }
        self::start( true );
    }

    public static function intervals( $schedules ) {
        $schedules['ace_seo_four_weeks'] = array( 'interval' => 28 * DAY_IN_SECONDS, 'display' => 'Every four weeks' );
        return $schedules;
    }

    public static function frequencies() {
        return array( 'off' => 'Off', 'daily' => 'Every day', 'weekly' => 'Every week', 'ace_seo_four_weeks' => 'Every four weeks' );
    }

    /** Read these small control records from the database: web/CLI object caches can differ. */
    public static function record( $name, $default = array() ) {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        return null === $value ? $default : maybe_unserialize( $value );
    }

    public static function settings() {
        $saved = self::record( self::OPTION );
        $saved = is_array( $saved ) ? $saved : array();
        return array_merge( array( 'frequency' => 'off', 'post_types' => array( 'post' ), 'include_unpublished' => 0, 'after_build' => 0, 'stop_generation' => 0, 'auto_stop_generation' => 0 ), $saved );
    }

    private static function dependencies() {
        require_once __DIR__ . '/class-ace-seo-export.php';
        require_once __DIR__ . '/admin/class-ace-seo-sheets.php';
    }

    /** Atomic database lease. Only its owner can renew/release it or store job progress. */
    public static function acquire() {
        global $wpdb;
        $old = self::record( self::LOCK, null );
        if ( is_array( $old ) && (int) $old['expires'] > time() ) {
            return false;
        }
        $lease = array( 'token' => wp_generate_uuid4(), 'expires' => time() + self::LEASE );
        $value = maybe_serialize( $lease );
        $result = null === $old
            ? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value ) )
            : $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK, maybe_serialize( $old ) ) );
        return 1 === (int) $result ? $lease : false;
    }

    public static function owns( array $lease ) {
        return $lease['expires'] > time() && self::record( self::LOCK ) === $lease;
    }

    public static function release( array $lease ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, maybe_serialize( $lease ) ) );
    }

    private static function store( $name, array $value, array $lease ) {
        global $wpdb;
        if ( ! self::owns( $lease ) ) {
            throw new RuntimeException( 'The snapshot worker lease expired; another worker can resume its saved batch.' );
        }
        // The SELECT checks ownership in the same SQL operation as the write.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'no' FROM {$wpdb->options} AS owner WHERE owner.option_name = %s AND owner.option_value = %s ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
            $name, maybe_serialize( $value ), self::LOCK, maybe_serialize( $lease )
        ) );
        wp_cache_delete( $name, 'options' );
        if ( false === $result || ! self::owns( $lease ) ) {
            throw new RuntimeException( 'Snapshot progress could not be saved safely.' );
        }
    }

    private static function active( array $job ) {
        return in_array( $job['status'] ?? '', array( 'queued', 'running', 'retrying' ), true );
    }

    private static function stopped( array $job ) {
        $settings = self::settings();
        return ( empty( $job['manual'] ) && ( 'off' === $settings['frequency'] || (int) ( $job['auto_stop_generation'] ?? 0 ) !== (int) $settings['auto_stop_generation'] ) ) || (int) ( $job['stop_generation'] ?? 0 ) !== (int) $settings['stop_generation'];
    }

    private static function queue_tick( $delay = 60 ) {
        if ( wp_next_scheduled( self::TICK ) ) {
            return true;
        }
        return wp_schedule_single_event( time() + max( 10, (int) $delay ), self::TICK, array(), true );
    }

    public static function sync_schedule() {
        $settings = self::settings();
        $frequency = $settings['frequency'];
        if ( 'off' === $frequency || ! isset( self::frequencies()[ $frequency ] ) ) {
            wp_clear_scheduled_hook( self::START );
            $job = self::record( self::JOB );
            if ( ! empty( $job['manual'] ) && self::active( $job ) && ! self::stopped( $job ) ) { self::queue_tick( max( 60, (int) ( $job['retry_at'] ?? 0 ) - time() ) ); } else { wp_clear_scheduled_hook( self::TICK ); }
            return;
        }
        $event = wp_get_scheduled_event( self::START );
        if ( $event && $event->schedule !== $frequency ) {
            wp_clear_scheduled_hook( self::START );
            $event = false;
        }
        if ( ! $event ) {
            $schedules = wp_get_schedules();
            wp_schedule_event( time() + $schedules[ $frequency ]['interval'], $frequency, self::START );
        }
        $job = self::record( self::JOB );
        if ( self::active( $job ) && ! self::stopped( $job ) ) {
            // Recover a dropped continuation; the DB lease still prevents overlapping execution.
            self::queue_tick( max( 60, (int) ( $job['retry_at'] ?? 0 ) - time() ) );
        }
    }

    public static function clear_schedule() {
        wp_clear_scheduled_hook( self::START );
        wp_clear_scheduled_hook( self::TICK );
    }

    public static function handle_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_sheets_schedule' ) ) {
            wp_die( 'Not allowed.' );
        }
        $saved = self::save_settings( wp_unslash( $_POST ) );
        if ( is_wp_error( $saved ) ) {
            wp_die( esc_html( $saved->get_error_message() ) );
        }
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), 'Snapshot schedule saved. The Google connection and retention settings have not changed.', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-sheets-schedule' ) );
        exit;
    }

    /** Validate and save the schedule from submitted fields; true or a WP_Error, no redirect. */
    public static function save_settings( array $input ) {
        self::dependencies();
        $old = self::settings();
        $frequency = sanitize_key( $input['frequency'] ?? 'off' );
        if ( ! isset( self::frequencies()[ $frequency ] ) ) {
            return new WP_Error( 'ace_sheets_frequency', 'Choose a recognised refresh frequency.' );
        }
        $types = array_values( array_intersect( AceSeoExport::post_types(), array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ) ) );
        if ( 'off' !== $frequency && ( ! $types || ! AceSeoSheets::configured() ) ) {
            return new WP_Error( 'ace_sheets_requirements', 'Choose at least one content type and save a Google Sheets connection before switching automatic refreshes on.' );
        }
        $after_build = empty( $input['after_build'] ) ? 0 : 1;
        if ( $after_build && ( ! $types || ! AceSeoSheets::configured() ) ) {
            return new WP_Error( 'ace_sheets_requirements', 'Choose at least one content type and save a Google Sheets connection before refreshing after each check.' );
        }
        $new = array_merge( $old, array( 'frequency' => $frequency, 'post_types' => $types ?: array( 'post' ), 'include_unpublished' => empty( $input['include_unpublished'] ) ? 0 : 1, 'after_build' => $after_build, 'stop_generation' => (int) $old['stop_generation'], 'auto_stop_generation' => (int) $old['auto_stop_generation'] + ( 'off' === $frequency && 'off' !== $old['frequency'] ? 1 : 0 ) ) );
        wp_cache_delete( self::OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        update_option( self::OPTION, $new, false );
        if ( self::record( self::OPTION ) !== $new ) {
            return new WP_Error( 'ace_sheets_save', 'The refresh schedule could not be saved. Please try again.' );
        }
        self::sync_schedule();
        return true;
    }

    public static function start( $manual = false ) {
        if ( 'off' === self::settings()['frequency'] && ! $manual ) {
            return;
        }
        $lease = self::acquire();
        if ( ! $lease ) {
            return;
        }
        $job = array();
        try {
            self::dependencies();
            $job = self::record( self::JOB );
            if ( self::active( $job ) && ! self::stopped( $job ) ) {
                self::queue_tick();
                return;
            }
            if ( $job && 'complete' !== ( $job['status'] ?? '' ) && 'not-started' !== ( $job['phase'] ?? '' ) ) {
                if ( ! self::stopped( $job ) && 'failed' === ( $job['status'] ?? '' ) ) { return; }
                $removed = AceSeoSheets::discard_snapshot_tab( $job['destination'], $job['tab_id'], $job['tab'] );
                if ( is_wp_error( $removed ) ) { self::fail( $job, $removed, $lease ); return; }
            }
            $settings = self::settings();
            $types = array_values( array_intersect( (array) $settings['post_types'], AceSeoExport::post_types() ) );
            $statuses = empty( $settings['include_unpublished'] ) ? array( 'publish' ) : array( 'publish', 'future', 'draft', 'pending', 'private' );
            $id = wp_generate_uuid4();
            $job = array( 'manual' => (bool) $manual, 'id' => $id, 'status' => 'queued', 'started' => time(), 'updated' => time(), 'destination' => AceSeoSheets::settings()['sheet_id'], 'tab' => '_Ace SEO working ' . wp_date( 'Y-m-d H.i.s' ) . ' ' . substr( $id, 0, 8 ), 'tab_id' => random_int( 1, 2147483646 ), 'types' => $types, 'statuses' => $statuses, 'stop_generation' => $settings['stop_generation'], 'auto_stop_generation' => $settings['auto_stop_generation'], 'cursor' => 0, 'written' => 0, 'skipped' => 0, 'failures' => 0, 'phase' => 'not-started', 'header' => array(), 'ids' => array(), 'total' => 0 );
            $targets = self::record( self::TARGETS );
            $target = $targets[ $job['destination'] ] ?? array();
            $job['report_id'] = isset( $target['sheet_id'] ) ? (int) $target['sheet_id'] : random_int( 1, 2147483646 );
            if ( $job['report_id'] === $job['tab_id'] ) { $job['tab_id'] = ( $job['tab_id'] % 2147483645 ) + 1; }
            $job['previous_rows'] = (int) ( $target['rows'] ?? 0 );
            $job['previous_columns'] = (int) ( $target['columns'] ?? 0 );
            $job['create_report'] = ! isset( $target['sheet_id'] );
            $job['header'] = AceSeoExport::header();
            if ( ! $types || ! AceSeoSheets::configured() ) {
                $job['status'] = 'failed';
                $job['error'] = 'The selected content types or Google Sheets connection are unavailable. No tab was created.';
            } else {
                global $wpdb;
                $type_sql = implode( ',', array_fill( 0, count( $types ), '%s' ) );
                $status_sql = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($type_sql) AND post_status IN ($status_sql) ORDER BY ID ASC", array_merge( $types, $statuses ) ) );
                if ( $wpdb->last_error ) {
                    throw new RuntimeException( 'The list of posts could not be read. No snapshot was started.' );
                }
                $job['ids'] = array_map( 'intval', $ids );
                $job['total'] = count( $job['ids'] );
                $job['phase'] = 'tab';
            }
            self::store( self::JOB, $job, $lease );
            if ( self::active( $job ) ) {
                $queued = self::queue_tick( 10 );
                if ( is_wp_error( $queued ) || false === $queued ) {
                    self::fail( $job, new WP_Error( 'ace_sheets_queue', 'WordPress could not queue the snapshot worker.' ), $lease );
                }
            }
        } catch ( Throwable $error ) {
            if ( $job && self::owns( $lease ) ) {
                $job['status'] = 'failed';
                $job['error'] = sanitize_text_field( $error->getMessage() );
                $job['updated'] = time();
                self::store( self::JOB, $job, $lease );
            }
        } finally {
            self::release( $lease );
        }
    }

    private static function fail( array $job, $error, array $lease ) {
        $job['failures'] = (int) $job['failures'] + 1;
        $job['error'] = sanitize_text_field( $error->get_error_message() );
        $job['updated'] = time();
        $job['status'] = self::stopped( $job ) ? 'cancelled' : ( $job['failures'] >= self::MAX_FAILURES ? 'failed' : 'retrying' );
        $delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** $job['failures'] ) );
        $job['retry_at'] = time() + $delay;
        self::store( self::JOB, $job, $lease );
        if ( 'retrying' === $job['status'] ) {
            self::queue_tick( $delay );
        }
    }

    public static function tick() {
        $lease = self::acquire();
        if ( ! $lease ) {
            return;
        }
        $job = array();
        try {
            self::dependencies();
            $job = self::record( self::JOB );
            if ( ! self::active( $job ) ) {
                return;
            }
            if ( self::stopped( $job ) ) {
                $job['status'] = 'cancelled';
                self::store( self::JOB, $job, $lease );
                return;
            }
            if ( ! empty( $job['retry_at'] ) && $job['retry_at'] > time() ) {
                self::queue_tick( $job['retry_at'] - time() );
                return;
            }
            $job['status'] = 'running';
            if ( 'publish' === $job['phase'] ) {
                $result = AceSeoSheets::publish_snapshot( $job['destination'], $job['tab_id'], $job['tab'], $job['report_id'], $job['id'], $job['written'] + 1, count( $job['header'] ), $job['create_report'], $job['previous_rows'], $job['previous_columns'], static function () use ( $job, $lease ) { return self::owns( $lease ) && ! self::stopped( $job ); } );
                if ( is_wp_error( $result ) ) { self::fail( $job, $result, $lease ); return; }
                $targets = self::record( self::TARGETS );
                $targets[ $job['destination'] ] = array( 'sheet_id' => $job['report_id'], 'title' => 'Ace SEO report', 'rows' => $job['written'] + 1, 'columns' => count( $job['header'] ) );
                self::store( self::TARGETS, $targets, $lease );
                $job['status'] = 'complete';
                $job['finished'] = time();
                $job['updated'] = time();
                unset( $job['ids'], $job['pending'], $job['error'], $job['retry_at'] );
                $history = self::record( self::HISTORY );
                $history = array_values( array_filter( $history, static function ( $entry ) use ( $job ) { return ( $entry['id'] ?? '' ) !== $job['id']; } ) );
                $history[] = $job;
                self::store( self::HISTORY, array_slice( $history, -10 ), $lease );
                self::store( self::JOB, $job, $lease );
                return;
            }
            // Verify tab identity every tick; never recreate a removed partially filled working tab.
            $confirmed = AceSeoSheets::ensure_snapshot_tab( $job['destination'], $job['tab'], $job['tab_id'], $job['total'] + 1, count( $job['header'] ), 'tab' === $job['phase'], static function () use ( $job, $lease ) { return self::owns( $lease ) && ! self::stopped( $job ); } );
            if ( is_wp_error( $confirmed ) ) { self::fail( $job, $confirmed, $lease ); return; }
            if ( 'tab' === $job['phase'] ) {
                $job['phase'] = 'header';
                self::store( self::JOB, $job, $lease );
            }
            if ( self::stopped( $job ) || ! self::owns( $lease ) ) { $job['status'] = 'cancelled'; self::store( self::JOB, $job, $lease ); return; }
            if ( 'header' === $job['phase'] ) {
                $result = AceSeoSheets::write_snapshot_rows( $job['destination'], $job['tab_id'], 1, array( $job['header'] ) );
                if ( is_wp_error( $result ) ) {
                    self::fail( $job, $result, $lease );
                    return;
                }
                $job['phase'] = 'rows';
                self::store( self::JOB, $job, $lease );
            }
            $deadline = microtime( true ) + 20;
            $batches = 0;
            do {
            if ( self::stopped( $job ) ) { $job['status'] = 'cancelled'; self::store( self::JOB, $job, $lease ); return; }
            if ( ! isset( $job['pending'] ) && $job['cursor'] < $job['total'] ) {
                $slice = array_slice( $job['ids'], $job['cursor'], self::BATCH );
                if ( $slice ) { _prime_post_caches( $slice, true, true ); }
                $eligible = array();
                foreach ( $slice as $id ) {
                    $post = get_post( $id );
                    if ( $post && in_array( $post->post_type, $job['types'], true ) && in_array( $post->post_status, $job['statuses'], true ) ) {
                        $eligible[] = $id;
                    }
                }
                $rows = AceSeoExport::rows( $eligible );
                foreach ( $rows as $row ) {
                    if ( count( $row ) !== count( $job['header'] ) ) {
                        throw new RuntimeException( 'The export row and header filters produced different column counts.' );
                    }
                }
                $job['pending'] = array( 'rows' => $rows, 'first_row' => $job['written'] + 2, 'next_cursor' => $job['cursor'] + count( $slice ), 'skipped' => count( $slice ) - count( $rows ) );
                // Persist the exact payload before sending it: retries cannot shift rows or change values.
                self::store( self::JOB, $job, $lease );
            }
            if ( self::stopped( $job ) || ! self::owns( $lease ) ) { $job['status'] = 'cancelled'; self::store( self::JOB, $job, $lease ); return; }
            if ( isset( $job['pending'] ) ) {
                $result = AceSeoSheets::write_snapshot_rows( $job['destination'], $job['tab_id'], $job['pending']['first_row'], $job['pending']['rows'] );
                if ( is_wp_error( $result ) ) {
                    self::fail( $job, $result, $lease );
                    return;
                }
                $job['written'] += count( $job['pending']['rows'] );
                $job['cursor'] = $job['pending']['next_cursor'];
                $job['skipped'] += $job['pending']['skipped'];
                unset( $job['pending'] );
            }
            $job['updated'] = time();
            $job['failures'] = 0;
            unset( $job['error'], $job['retry_at'] );
            if ( self::stopped( $job ) ) {
                $job['status'] = 'cancelled';
            } elseif ( $job['cursor'] >= $job['total'] ) {
                $job['phase'] = 'publish';
            }
            self::store( self::JOB, $job, $lease );
            $batches++;
            // As many batches as fit the deadline (a 42,000-row site took 85 minutes at two a tick).
            } while ( $batches < 12 && microtime( true ) < $deadline && 'publish' !== $job['phase'] && self::active( $job ) );
            if ( self::active( $job ) ) { self::queue_tick(); }
        } catch ( Throwable $error ) {
            if ( $job && self::owns( $lease ) ) {
                self::fail( $job, new WP_Error( 'ace_sheets_worker', $error->getMessage() ), $lease );
            }
        } finally {
            self::release( $lease );
        }
    }

    public static function handle_run() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_sheets_run' ) ) { wp_die( 'Not allowed.' ); }
        self::start( true );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-sheets-schedule' ) );
        exit;
    }

    public static function handle_stop() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_sheets_stop' ) ) { wp_die( 'Not allowed.' ); }
        $settings = self::settings();
        $settings['stop_generation'] = (int) $settings['stop_generation'] + 1;
        wp_cache_delete( self::OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        update_option( self::OPTION, $settings, false );
        if ( self::record( self::OPTION ) !== $settings ) { wp_die( 'The stop request could not be saved. Please try again.' ); }
        wp_clear_scheduled_hook( self::TICK );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-sheets-schedule' ) );
        exit;
    }

    public static function handle_retry() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_sheets_retry' ) ) {
            wp_die( 'Not allowed.' );
        }
        $lease = self::acquire();
        if ( ! $lease ) {
            wp_die( 'A snapshot request is still running. Try again once it finishes.' );
        }
        try {
            $job = self::record( self::JOB );
            if ( 'failed' !== ( $job['status'] ?? '' ) || self::stopped( $job ) ) {
                wp_die( 'There is no paused snapshot to retry with the current schedule.' );
            }
            $restart = 'not-started' === ( $job['phase'] ?? '' );
            if ( ! $restart ) {
                $job['status'] = 'queued';
                $job['failures'] = 0;
                unset( $job['retry_at'], $job['error'] );
                self::store( self::JOB, $job, $lease );
                self::queue_tick( 10 );
            }
        } finally {
            self::release( $lease );
        }
        // An initialisation failure has no frozen IDs or working tab to resume.
        if ( $restart ) { self::start( ! empty( $job['manual'] ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-sheets-schedule' ) );
        exit;
    }

    /** Standalone: fields in their own form plus the controls. The Retention tab uses render_fields() and render_controls(). */
    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ace_seo_sheets_schedule' );
        echo '<input type="hidden" name="action" value="ace_seo_sheets_schedule">';
        self::render_fields();
        echo '<p><button class="button button-primary">Save report schedule</button></p></form>';
        self::render_controls();
    }

    /** The schedule fields only, for a parent form. */
    public static function render_fields() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        self::dependencies();
        $settings = self::settings();
        ?>
        <div id="retention-sheets-schedule">
            <h4>Keep the main report tab up to date</h4>
            <p>Refresh the first tab, “Ace SEO report”, from the saved SEO and retention data. The previous report stays visible until the new copy is complete. Other tabs are kept.</p>
                <p><label for="ace-sheets-frequency">How often</label><br><select id="ace-sheets-frequency" name="frequency">
                    <?php foreach ( self::frequencies() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['frequency'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
                </select></p>
                <fieldset><legend>Which content to include</legend>
                    <?php foreach ( AceSeoExport::post_types() as $type ) : $object = get_post_type_object( $type ); if ( ! $object ) { continue; } ?>
                        <label style="display:block"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $settings['post_types'], true ) ); ?>> <?php echo esc_html( $object->labels->name ); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <p><label><input type="checkbox" name="include_unpublished" value="1" <?php checked( ! empty( $settings['include_unpublished'] ) ); ?>> Also include drafts, pending, private and scheduled content</label></p>
                <p><label><input type="checkbox" name="after_build" value="1" <?php checked( ! empty( $settings['after_build'] ) ); ?>> Also refresh the report each time a retention build finishes</label><br><span class="description">The weekly build (when switched on), a manual rebuild or a resumed one: the sheet then always shows the latest saved assessment.</span></p>
                <p class="description">Otherwise, only published content is copied. The refresh includes every item in the selected types and statuses, with the same columns as the post-list export. Trash, revisions and automatic drafts are excluded.</p>
        </div>
        <?php
    }

    /** Run, stop and retry: operational controls that use the saved settings and are never part of a save. */
    public static function render_controls() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        self::dependencies();
        $settings = self::settings();
        $job = self::record( self::JOB );
        $next = wp_next_scheduled( self::START );
        $history = self::record( self::HISTORY );
        $last = $history ? end( $history ) : array();
        ?>
        <section id="retention-sheets-controls" class="ace-retention-section">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ace_seo_sheets_run' ); ?><input type="hidden" name="action" value="ace_seo_sheets_run"><p><button class="button">Refresh main report now</button></p><p class="description">Uses the saved content choices above. This one-off refresh works while automatic refreshes are off.</p></form>
            <?php if ( ( self::active( $job ) || 'failed' === ( $job['status'] ?? '' ) ) && ! self::stopped( $job ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ace_seo_sheets_stop' ); ?><input type="hidden" name="action" value="ace_seo_sheets_stop"><button class="button">Stop this refresh</button></form><?php endif; ?>
            <p><?php echo 'off' === $settings['frequency'] ? 'Automatic refreshes are off.' : ( $next ? 'Next refresh due: ' . esc_html( wp_date( 'j F Y, H:i', $next ) ) . '.' : 'No refresh is scheduled. Check that WordPress cron is working.' ); ?> Times use the site timezone. WordPress runs the job when its scheduler next runs, so a due time is not a guaranteed completion time.</p>
            <?php if ( $job ) : ?>
                <p><strong>Current or latest refresh:</strong> <?php echo esc_html( self::stopped( $job ) && self::active( $job ) ? 'Stopped' : ( array( 'queued' => 'Waiting to start', 'running' => 'Preparing the report', 'retrying' => 'Waiting to try again', 'failed' => 'Needs attention', 'complete' => 'Complete', 'cancelled' => 'Stopped' )[ $job['status'] ] ?? $job['status'] ) ); ?> · <?php echo esc_html( number_format_i18n( $job['written'] ) ); ?> of <?php echo esc_html( number_format_i18n( $job['total'] ) ); ?> items written<?php echo ! empty( $job['skipped'] ) ? ' · ' . esc_html( number_format_i18n( $job['skipped'] ) ) . ' no longer in scope' : ''; ?>.</p>
                <p><a href="<?php echo esc_url( 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $job['destination'] ) . '/edit#gid=' . $job['report_id'] ); ?>" target="_blank" rel="noopener noreferrer">Open the main report</a> · Started <?php echo esc_html( wp_date( 'j F Y, H:i', $job['started'] ) ); ?>.</p>
                <?php if ( ! empty( $job['error'] ) ) : ?><p role="status"><strong>This refresh is incomplete.</strong> <?php echo esc_html( $job['error'] ); ?> Retry checks whether Google has already finished before writing again.</p><?php endif; ?>
                <?php if ( 'failed' === $job['status'] && ! self::stopped( $job ) ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ace_seo_sheets_retry' ); ?><input type="hidden" name="action" value="ace_seo_sheets_retry"><button class="button">Retry this incomplete refresh</button></form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ( $last ) : ?><p>Last completed refresh: <?php echo esc_html( wp_date( 'j F Y, H:i', $last['finished'] ) ); ?> · <?php echo esc_html( number_format_i18n( $last['written'] ) ); ?> items.</p><?php endif; ?>
            <p class="description">This copies the data already saved. It does not finish an incomplete retention report, fill missing scores or change articles. Use the Posts list export for a separate filtered, dated copy.</p>
            <details class="ace-retention-detail"><summary>How refreshes and retries work</summary>
                <p>The destination, content types and item IDs are fixed when a run starts. Changing the connection or scope applies to later runs. Values are copied in batches, so this is not an exact database backup from one instant. New posts wait for the next refresh; deleted items or items no longer in scope are skipped.</p>
                <p>Switching automatic refreshes off stops their queued work. “Stop this refresh” also stops a one-off run. A request already in progress may finish. Failed batches retry up to five times without adding duplicate rows; after that, use Retry. A failed refresh pauses later automatic refreshes until it is retried or stopped.</p>
                <p>Ace SEO builds a hidden working copy, then updates its managed report in one operation. It removes only that working copy after success, and clears stale report cells within its recorded area. Failed working copies are reused on retry; stopped ones are removed before a new run. Google size or access errors stay visible here; other tabs are never deleted to make room.</p>
                <p>Exports copy the measurements already saved on each item. Missing scores remain blank. If the retention report is unfinished, stale or has only limited history, exporting it does not fill those gaps or finish the report.</p>
            </details>
        </section>
        <?php
    }
}
