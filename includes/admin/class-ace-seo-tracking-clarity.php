<?php
defined( 'ABSPATH' ) || exit;

/** Clarity's export API is a rolling snapshot, not a historical daily series. */
class AceSeoTrackingClarity {
    const OPTION = 'ace_seo_tracking_clarity_token';
    const CACHE = 'ace_seo_tracking_clarity_snapshot';
    const HISTORY = 'ace_seo_tracking_clarity_history';

    public static function encrypt( $token, $project ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) { return new WP_Error( 'clarity_crypto', 'OpenSSL is required to save this token.' ); }
        $iv = random_bytes( 12 );
        $tag = '';
        $cipher = openssl_encrypt( $token, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag, $project );
        if ( false === $cipher ) { return new WP_Error( 'clarity_crypto', 'The token could not be encrypted.' ); }
        return array( 'project' => $project, 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'cipher' => base64_encode( $cipher ) );
    }

    public static function decrypt( $stored, $project ) {
        if ( ! is_array( $stored ) || ( $stored['project'] ?? '' ) !== $project || ! function_exists( 'openssl_decrypt' ) ) { return ''; }
        $iv = base64_decode( $stored['iv'] ?? '', true );
        $tag = base64_decode( $stored['tag'] ?? '', true );
        $cipher = base64_decode( $stored['cipher'] ?? '', true );
        if ( false === $iv || 12 !== strlen( $iv ) || false === $tag || 16 !== strlen( $tag ) || false === $cipher ) { return ''; }
        return openssl_decrypt( $cipher, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag, $project ) ?: '';
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You cannot manage this connection.' ); }
        check_admin_referer( 'ace_seo_tracking_clarity' );
        if ( ! empty( $_POST['disconnect'] ) ) {
            delete_option( self::OPTION );
            delete_transient( self::CACHE );
        } else {
            $project = (string) get_option( 'clarity_project_id', '' );
            $token = isset( $_POST['clarity_token'] ) && is_string( $_POST['clarity_token'] ) ? trim( wp_unslash( $_POST['clarity_token'] ) ) : '';
            if ( '' !== $token ) {
                if ( ! $project || strlen( $token ) > 8192 || preg_match( '/[^\x21-\x7e]/', $token ) ) { wp_die( 'Enter a valid token for the configured Clarity project.' ); }
                $encrypted = self::encrypt( $token, $project );
                if ( is_wp_error( $encrypted ) ) { wp_die( esc_html( $encrypted->get_error_message() ) ); }
                update_option( self::OPTION, $encrypted, false );
                delete_transient( self::CACHE );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . AceSeoTracking::SLUG . '#clarity-data' ) );
        exit;
    }

    public static function snapshot() {
        $project = (string) get_option( 'clarity_project_id', '' );
        $token = self::decrypt( get_option( self::OPTION, array() ), $project );
        $out = array( 'connected' => '' !== $token, 'ok' => false, 'project' => $project, 'at' => time(), 'metrics' => array(), 'error' => '' );
        if ( ! $token ) { return $out; }
        $cached = get_transient( self::CACHE );
        if ( is_array( $cached ) && ( $cached['project'] ?? '' ) === $project ) { self::remember( $cached ); return $cached; }
        $response = wp_remote_get( 'https://www.clarity.ms/export-data/api/v1/project-live-insights?numOfDays=1', array(
            'timeout' => 20, 'redirection' => 0,
            'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
        ) );
        if ( is_wp_error( $response ) ) {
            $out['error'] = 'Clarity could not be reached. The connection will be retried after the cache expires.';
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 === $code && is_array( $data ) && ( array() === $data || isset( $data[0]['metricName'], $data[0]['information'] ) ) ) {
                $out['metrics'] = self::normalise( $data );
                $out['ok'] = true;
            } else {
                $out['error'] = in_array( $code, array( 401, 403 ), true ) ? 'Clarity rejected the token. Generate a Data Export token for this project and reconnect.' : ( 429 === $code ? 'Clarity’s daily request limit has been reached. Cached results will be retried later.' : 'Clarity returned an unreadable report (HTTP ' . (int) $code . ').' );
            }
        }
        self::remember( $out );
        // Four requests/day at most in normal use; errors are cached too.
        set_transient( self::CACHE, $out, 6 * HOUR_IN_SECONDS );
        return $out;
    }

    public static function normalise( $data ) {
        $rows = array();
        foreach ( array_slice( $data, 0, 30 ) as $metric ) {
            if ( ! is_array( $metric ) || ! isset( $metric['metricName'] ) ) { continue; }
            foreach ( array_slice( (array) ( $metric['information'] ?? array() ), 0, 5 ) as $info ) {
                if ( ! is_array( $info ) ) { continue; }
                $fields = array();
                foreach ( $info as $key => $value ) {
                    if ( is_scalar( $value ) ) { $fields[ self::label( $key ) ] = (string) $value; }
                }
                if ( $fields ) { $rows[] = array( 'label' => self::label( $metric['metricName'] ), 'fields' => $fields ); }
            }
        }
        return $rows;
    }

    public static function label( $key ) {
        if ( 'distantUserCount' === $key ) { return 'Distinct users'; } // API field spelling.
        return ucfirst( trim( preg_replace( '/([a-z])([A-Z])/', '$1 $2', str_replace( '_', ' ', (string) $key ) ) ) );
    }

    /** Only explicit numeric fields from an unsegmented response become chart metrics. */
    public static function definitions() {
        return array(
            'sessions' => array( 'Sessions', 'Traffic', 'Total session count', 'Visits counted by Clarity in this reporting window.' ),
            'bots' => array( 'Bot sessions', 'Traffic', 'Total Bot Session Count', 'Sessions Clarity identified as bot traffic.' ),
            'users' => array( 'Unique visitors', 'Traffic', 'Distinct users', 'People counted by Clarity in this reporting window.' ),
            'dead' => array( 'Dead clicks', 'Dead Click Count', 'Sub total', 'Clicks with no visible response.' ),
            'rage' => array( 'Rage clicks', 'Rage Click Count', 'Sub total', 'Repeated clicks in the same area.' ),
            'quickback' => array( 'Quick backs', 'Quickback Click', 'Sub total', 'Visits followed by a quick return to the previous page.' ),
            'errors' => array( 'Script errors', 'Script Error Count', 'Sub total', 'JavaScript errors reported by Clarity.' ),
            'error_clicks' => array( 'Error clicks', 'Error Click Count', 'Sub total', 'Clicks associated with a JavaScript error.' ),
        );
    }

    public static function values( $metrics ) {
        $out = array();
        foreach ( self::definitions() as $key => $definition ) {
            $matches = array_values( array_filter( $metrics, function ( $row ) use ( $definition ) {
                return strtolower( str_replace( ' ', '', $row['label'] ) ) === strtolower( str_replace( ' ', '', $definition[1] ) );
            } ) );
            // Never sum segmented distinct visitors or guess a missing count from a percentage.
            if ( 1 !== count( $matches ) ) { continue; }
            $fields = array();
            foreach ( $matches[0]['fields'] as $field => $value ) { $fields[ strtolower( str_replace( ' ', '', $field ) ) ] = $value; }
            $value = $fields[ strtolower( str_replace( ' ', '', $definition[2] ) ) ] ?? null;
            if ( 'users' === $key ) { $value = $fields['distinctusercount'] ?? $value; }
            if ( is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 ) { $out[ $key ] = (float) $value; }
        }
        return $out;
    }

    /** One immutable rolling window per capture date; never invent missed days. */
    public static function remember( $snapshot ) {
        if ( empty( $snapshot['ok'] ) || empty( $snapshot['project'] ) ) { return; }
        $values = self::values( $snapshot['metrics'] );
        if ( ! $values ) { return; }
        $history = (array) get_option( self::HISTORY, array() );
        if ( ( $history['project'] ?? '' ) !== $snapshot['project'] ) { $history = array( 'project' => $snapshot['project'], 'days' => array() ); }
        $date = wp_date( 'Y-m-d', $snapshot['at'] );
        if ( isset( $history['days'][ $date ] ) ) { return; }
        $history['days'][ $date ] = array( 'at' => $snapshot['at'], 'values' => $values );
        ksort( $history['days'] );
        $cutoff = time() - 60 * 86400;
        $history['days'] = array_filter( $history['days'], function ( $day ) use ( $cutoff ) { return $day['at'] >= $cutoff; } );
        $history['days'] = array_slice( $history['days'], -60, null, true );
        update_option( self::HISTORY, $history, false );
    }

    public static function history() {
        $history = (array) get_option( self::HISTORY, array() );
        return ( $history['project'] ?? '' ) === (string) get_option( 'clarity_project_id', '' ) ? ( $history['days'] ?? array() ) : array();
    }

    public static function render() {
        $data = self::snapshot();
        if ( ! $data['project'] ) { return; }
        $base = 'https://clarity.microsoft.com/projects/view/' . rawurlencode( $data['project'] );
        $values = self::values( $data['metrics'] );
        echo '<section id="clarity-data" class="ace-trk-clarity"><header class="ace-trk-clarity-header"><div><span class="ace-trk-eyebrow">Behaviour &amp; experience</span><h2>Microsoft Clarity</h2><p>Project <code class="ace-trk-id">' . esc_html( $data['project'] ) . '</code> <span class="pill pill-' . ( $data['ok'] ? 'ok' : 'warn' ) . '">' . ( $data['ok'] ? 'Reporting connected' : ( $data['connected'] ? 'Check connection' : 'Reporting not connected' ) ) . '</span></p></div><nav aria-label="Clarity reports">';
        foreach ( array( 'dashboard' => 'Dashboard', 'recordings' => 'Recordings', 'heatmaps' => 'Heatmaps' ) as $path => $label ) {
            echo '<a class="button" href="' . esc_url( $base . '/' . $path ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . ' ↗</a> ';
        }
        echo '</nav></header>';
        if ( $data['ok'] ) {
            echo '<p class="ace-trk-window"><strong>Last 24 hours</strong> · ' . esc_html( wp_date( 'j M H:i T', $data['at'] - 86400 ) . ' – ' . wp_date( 'j M H:i T', $data['at'] ) ) . '</p><div class="ace-trk-clarity-grid">';
            foreach ( self::definitions() as $key => $definition ) {
                if ( ! array_key_exists( $key, $values ) ) { continue; }
                echo '<article class="ace-trk-clarity-stat"><span>' . esc_html( $definition[0] ) . '</span><strong>' . esc_html( number_format_i18n( $values[ $key ] ) ) . '</strong><p>' . esc_html( $definition[3] ) . '</p></article>';
            }
            echo '</div>';
            if ( ! $data['metrics'] ) { echo '<p>No metrics were returned for this window.</p>'; }
            $next = wp_next_scheduled( AceSeoTracking::CRON );
            echo '<p class="ace-trk-history-note"><strong>Daily history logging</strong> · ' . ( $next ? 'Next check ' . esc_html( wp_date( 'j M H:i T', $next ) ) : 'Daily check is not scheduled' ) . '. We keep up to 60 saved windows, even when nobody opens this page.</p>';
            echo '<p class="ace-trk-history-note">' . esc_html( count( self::history() ) ) . ' saved reporting windows. The main chart can show sessions, visitors and available interaction counts. Each point covers the 24 hours before its capture time; it is not a calendar-day total.</p>';
            echo '<details class="ace-trk-clarity-detail"><summary>All reported metrics</summary><div class="ace-trk-clarity-grid">';
            foreach ( $data['metrics'] as $row ) {
                echo '<div class="ace-trk-clarity-card"><h3>' . esc_html( $row['label'] ) . '</h3><dl>';
                foreach ( $row['fields'] as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( is_numeric( $value ) ? number_format_i18n( (float) $value, floor( (float) $value ) == (float) $value ? 0 : 2 ) : $value ) . '</dd>'; }
                echo '</dl></div>';
            }
            echo '</div></details>';
        } elseif ( $data['error'] ) {
            echo '<p class="ace-trk-clarity-message" role="status">' . esc_html( $data['error'] ) . '</p>';
        } else {
            echo '<div class="ace-trk-clarity-empty"><strong>See what happens after a visit</strong><p>Connect reporting for sessions, dead clicks, rage clicks and more. Your tracking tag works separately. Once connected, we save a rolling 24-hour snapshot each day to build a chart history.</p></div>';
        }
        echo '<details class="ace-trk-clarity-connection"' . ( ! $data['connected'] ? ' open' : '' ) . '><summary>' . ( $data['connected'] ? 'Manage reporting connection' : 'Connect Clarity reporting' ) . '</summary><p>Open <a href="' . esc_url( $base . '/settings' ) . '" target="_blank" rel="noopener noreferrer">Clarity Settings → Data Export</a>, generate a token and paste it below. Past daily figures cannot be backfilled through this API.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ace_seo_tracking_clarity' );
        echo '<input type="hidden" name="action" value="ace_seo_tracking_clarity"><label for="ace-clarity-token">Data Export API token</label><div class="ace-trk-connection-fields"><input id="ace-clarity-token" name="clarity_token" type="password" autocomplete="new-password" class="regular-text" value="" placeholder="' . ( $data['connected'] ? 'Leave blank to keep the saved token' : 'Paste the token for this project' ) . '"><button class="button button-primary">Save connection</button>';
        if ( $data['connected'] ) { echo '<button class="button" name="disconnect" value="1">Disconnect reporting</button>'; }
        echo '</div><p class="description">Token stored encrypted. Results refresh at most every six hours; daily checks also save history. Disconnecting reporting leaves tracking and saved history intact.</p></form></details></section>';
    }
}
