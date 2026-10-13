<?php
defined( 'ABSPATH' ) || exit;

/** Clarity's export API is a rolling snapshot, not a historical daily series. */
class AceSeoTrackingClarity {
    const OPTION = 'ace_seo_tracking_clarity_token';
    const CACHE = 'ace_seo_tracking_clarity_snapshot';

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
        if ( is_array( $cached ) && ( $cached['project'] ?? '' ) === $project ) { return $cached; }
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

    public static function render() {
        $data = self::snapshot();
        if ( ! $data['project'] ) { return; }
        $base = 'https://clarity.microsoft.com/projects/view/' . rawurlencode( $data['project'] );
        echo '<section id="clarity-data" class="ace-trk-clarity"><h2>Microsoft Clarity</h2><p>Project <code>' . esc_html( $data['project'] ) . '</code> · <a href="' . esc_url( $base . '/dashboard' ) . '" target="_blank" rel="noopener noreferrer">Dashboard</a> · <a href="' . esc_url( $base . '/recordings' ) . '" target="_blank" rel="noopener noreferrer">Recordings</a> · <a href="' . esc_url( $base . '/heatmaps' ) . '" target="_blank" rel="noopener noreferrer">Heatmaps</a></p>';
        if ( $data['ok'] ) {
            echo '<p>Rolling 24-hour snapshot, fetched ' . esc_html( wp_date( 'j M Y H:i', $data['at'] ) ) . '. Clarity reports in UTC; this is not a daily chart or a historical backfill.</p><div class="ace-trk-clarity-grid">';
            foreach ( $data['metrics'] as $row ) {
                echo '<div class="ace-trk-clarity-card"><h3>' . esc_html( $row['label'] ) . '</h3><dl>';
                foreach ( $row['fields'] as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( is_numeric( $value ) ? number_format_i18n( (float) $value, floor( (float) $value ) == (float) $value ? 0 : 2 ) : $value ) . '</dd>'; }
                echo '</dl></div>';
            }
            echo '</div>';
            if ( ! $data['metrics'] ) { echo '<p>No metrics were returned for the last 24 hours.</p>'; }
        } elseif ( $data['error'] ) {
            echo '<p class="notice notice-warning">' . esc_html( $data['error'] ) . '</p>';
        } else {
            echo '<p>The tracking tag is connected separately from the reporting API. Connect a Data Export token to see sessions, engagement and interaction metrics here.</p>';
        }
        echo '<details><summary>' . ( $data['connected'] ? 'Manage reporting connection' : 'Connect Clarity reporting' ) . '</summary><p>In Clarity, open <a href="' . esc_url( $base . '/settings' ) . '" target="_blank" rel="noopener noreferrer">Settings → Data Export</a> and generate an API token. Results are cached for six hours to respect the ten-request daily limit. Re-running the tracking checks does not bypass this cache.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ace_seo_tracking_clarity' );
        echo '<input type="hidden" name="action" value="ace_seo_tracking_clarity"><label for="ace-clarity-token">Data Export API token</label> <input id="ace-clarity-token" name="clarity_token" type="password" autocomplete="new-password" class="regular-text" value="" placeholder="' . ( $data['connected'] ? 'Leave blank to keep the saved token' : 'Paste the token for this project' ) . '"> <button class="button button-primary">Save connection</button>';
        if ( $data['connected'] ) { echo ' <button class="button" name="disconnect" value="1">Disconnect reporting</button>'; }
        echo '<p class="description">Saved encrypted, never displayed. Disconnecting reporting leaves the tracking tag unchanged.</p></form></details></section>';
    }
}
