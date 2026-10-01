<?php
/**
 * Google Sheets as an export destination for the post list.
 *
 * A Google Cloud service account writes to one spreadsheet that has been shared with it, and each
 * export lands on a new tab of that spreadsheet. A service account is used rather than OAuth because
 * there is nobody to click through a consent screen on a cron or a colleague's login, and rather than
 * letting it create spreadsheets because a service account has no Drive storage of its own: the sheet
 * belongs to a person, and the account only edits it.
 *
 * No Google client library: a signed JWT (openssl) buys an access token, and two REST calls do the rest.
 *
 * @package AceCrawlEnhancer
 */

defined( 'ABSPATH' ) || exit;

class AceSeoSheets {

    const OPTION    = 'ace_seo_sheets';
    const TOKEN_KEY = 'ace_seo_sheets_token';
    const SCOPE     = 'https://www.googleapis.com/auth/spreadsheets';
    const API       = 'https://sheets.googleapis.com/v4/spreadsheets/';

    public static function init() {
        add_action( 'admin_post_ace_seo_sheets_settings', array( __CLASS__, 'handle_settings' ) );
    }

    /**
     * Stored settings. The key can instead come from the ACE_SEO_SHEETS_KEY_FILE constant (a path to
     * the JSON key outside the web root), which keeps the private key out of the database.
     *
     * @return array{key: array, sheet_id: string}
     */
    public static function settings() {
        $o   = get_option( self::OPTION, array() );
        $o   = is_array( $o ) ? $o : array();
        $key = array();
        if ( defined( 'ACE_SEO_SHEETS_KEY_FILE' ) && is_readable( ACE_SEO_SHEETS_KEY_FILE ) ) {
            $key = json_decode( (string) file_get_contents( ACE_SEO_SHEETS_KEY_FILE ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        } elseif ( ! empty( $o['key'] ) ) {
            $key = json_decode( (string) $o['key'], true );
        }
        return array(
            'key'      => is_array( $key ) ? $key : array(),
            'sheet_id' => (string) ( $o['sheet_id'] ?? '' ),
        );
    }

    public static function configured() {
        $s = self::settings();
        return ! empty( $s['key']['client_email'] ) && ! empty( $s['key']['private_key'] ) && '' !== $s['sheet_id'];
    }

    /** The address the spreadsheet has to be shared with, for the settings screen. */
    public static function account_email() {
        return (string) ( self::settings()['key']['client_email'] ?? '' );
    }

    public static function sheet_url() {
        $id = self::settings()['sheet_id'];
        return $id ? 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $id ) . '/edit' : '';
    }

    /** Accept a full spreadsheet URL or the bare ID. */
    public static function parse_sheet_id( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '#/spreadsheets/d/([A-Za-z0-9_-]+)#', $value, $m ) ) {
            return $m[1];
        }
        return preg_match( '/^[A-Za-z0-9_-]{20,}$/', $value ) ? $value : '';
    }

    public static function handle_settings() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_sheets_settings' ) ) {
            wp_die( 'Not allowed.' );
        }
        $o   = get_option( self::OPTION, array() );
        $o   = is_array( $o ) ? $o : array();
        $msg = 'Google Sheets settings saved.';

        $o['sheet_id'] = self::parse_sheet_id( wp_unslash( $_POST['sheet'] ?? '' ) );

        $raw = trim( (string) wp_unslash( $_POST['key'] ?? '' ) );
        if ( ! empty( $_POST['forget_key'] ) ) {
            unset( $o['key'] );
        } elseif ( '' !== $raw ) {
            $key = json_decode( $raw, true );
            if ( is_array( $key ) && ! empty( $key['client_email'] ) && ! empty( $key['private_key'] ) ) {
                $o['key'] = wp_json_encode( array_intersect_key( $key, array_flip( array( 'client_email', 'private_key', 'token_uri' ) ) ) );
            } else {
                $msg = 'That key is not a service account JSON key; the old one (if any) is kept.';
            }
        }
        update_option( self::OPTION, $o, false );
        delete_transient( self::TOKEN_KEY );

        if ( self::configured() && 'Google Sheets settings saved.' === $msg ) {
            $check = self::request( 'GET', self::settings()['sheet_id'] . '?fields=properties.title' );
            $msg   = is_wp_error( $check )
                ? 'Saved, but Google refused the connection: ' . $check->get_error_message()
                : 'Connected to “' . ( $check['properties']['title'] ?? 'the spreadsheet' ) . '”.';
        }
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), $msg, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-retention' ) );
        exit;
    }

    /** The settings block on the Retention report page. */
    public static function render_settings() {
        $s      = self::settings();
        $email  = self::account_email();
        $locked = defined( 'ACE_SEO_SHEETS_KEY_FILE' );
        ?>
        <details style="margin:1em 0">
            <summary style="cursor:pointer;font-weight:600">Google Sheets export <?php echo self::configured() ? '(connected)' : '(not set up)'; ?></summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ace_seo_sheets_settings' ); ?>
                <input type="hidden" name="action" value="ace_seo_sheets_settings">
                <p>Adds <strong>Export to Google Sheets</strong> beside Export CSV on the post list. Each export becomes a new tab in one spreadsheet. To set it up:</p>
                <ol style="margin-left:2em">
                    <li>In Google Cloud, enable the <em>Google Sheets API</em> on a project, create a service account and download a JSON key for it.</li>
                    <li>Paste the key below (or put the file outside the web root and define <code>ACE_SEO_SHEETS_KEY_FILE</code> as its path).</li>
                    <li>Create a spreadsheet and share it, as an Editor, with the service account's email<?php echo $email ? ': <code style="user-select:all">' . esc_html( $email ) . '</code>' : ''; ?>.</li>
                    <li>Paste the spreadsheet's URL below and save; it checks the connection there and then.</li>
                </ol>
                <table class="form-table" style="max-width:800px"><tbody>
                    <tr><th scope="row">Spreadsheet</th><td><input type="url" name="sheet" class="large-text" placeholder="https://docs.google.com/spreadsheets/d/…" value="<?php echo esc_attr( self::sheet_url() ); ?>"></td></tr>
                    <tr><th scope="row">Service account key</th><td>
                        <?php if ( $locked ) : ?>
                            Read from <code>ACE_SEO_SHEETS_KEY_FILE</code><?php echo $email ? '' : ' (file missing or unreadable)'; ?>.
                        <?php else : ?>
                            <textarea name="key" rows="4" class="large-text code" placeholder="<?php echo $email ? esc_attr( 'Stored for ' . $email . '. Paste a new key to replace it.' ) : '{ &quot;type&quot;: &quot;service_account&quot;, … }'; ?>"></textarea>
                            <?php if ( $email ) : ?><label><input type="checkbox" name="forget_key" value="1"> Forget the stored key</label><?php endif; ?>
                            <p class="description">Only the email and private key are kept, in an option that does not autoload. It is never shown again.</p>
                        <?php endif; ?>
                    </td></tr>
                </tbody></table>
                <p><button class="button">Save and test</button></p>
            </form>
        </details>
        <?php
    }

    /* ---- API ------------------------------------------------------------------------------------ */

    /** @return string|WP_Error */
    private static function token() {
        $cached = get_transient( self::TOKEN_KEY );
        if ( $cached ) {
            return $cached;
        }
        $key = self::settings()['key'];
        if ( empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
            return new WP_Error( 'ace_sheets_key', 'No service account key is set.' );
        }
        $aud  = $key['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now  = time();
        $b64  = static function ( $s ) {
            return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
        };
        $jwt  = $b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.' . $b64( wp_json_encode( array(
            'iss'   => $key['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $aud,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ) ) );
        $sig = '';
        if ( ! openssl_sign( $jwt, $sig, $key['private_key'], 'sha256WithRSAEncryption' ) ) {
            return new WP_Error( 'ace_sheets_key', 'The private key could not sign a request.' );
        }
        $res = wp_remote_post( $aud, array(
            'timeout' => 15,
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt . '.' . $b64( $sig ),
            ),
        ) );
        $body = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
        if ( empty( $body['access_token'] ) ) {
            return new WP_Error( 'ace_sheets_auth', is_wp_error( $res ) ? $res->get_error_message() : ( $body['error_description'] ?? 'Google did not issue a token.' ) );
        }
        set_transient( self::TOKEN_KEY, $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 ) );
        return $body['access_token'];
    }

    /** @return array|WP_Error */
    private static function request( $method, $path, $body = null ) {
        $token = self::token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }
        $res  = wp_remote_request( self::API . $path, $args );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code >= 300 ) {
            $msg = $data['error']['message'] ?? 'HTTP ' . $code;
            if ( 403 === $code || 404 === $code ) {
                $msg .= ' (is the spreadsheet shared with ' . self::account_email() . ' as an Editor?)';
            }
            return new WP_Error( 'ace_sheets_api', $msg );
        }
        return is_array( $data ) ? $data : array();
    }

    /**
     * Add a tab for one export and return its title.
     *
     * @return string|WP_Error
     */
    public static function add_tab( $title ) {
        $title = mb_substr( preg_replace( '/[\[\]\*\?\/\\\\:\']/', ' ', $title ), 0, 90 );
        $res   = self::request( 'POST', self::settings()['sheet_id'] . ':batchUpdate', array(
            'requests' => array( array( 'addSheet' => array( 'properties' => array(
                'title'          => $title,
                'gridProperties' => array( 'frozenRowCount' => 1 ),
            ) ) ) ),
        ) );
        return is_wp_error( $res ) ? $res : (string) ( $res['replies'][0]['addSheet']['properties']['title'] ?? $title );
    }

    /**
     * Append rows to a tab. RAW, so nothing typed into a title is evaluated as a formula.
     *
     * @return true|WP_Error
     */
    public static function append( $tab, array $rows ) {
        if ( ! $rows ) {
            return true;
        }
        $range = rawurlencode( "'" . str_replace( "'", "''", $tab ) . "'!A1" );
        $res   = self::request( 'POST', self::settings()['sheet_id'] . '/values/' . $range . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', array(
            'values' => array_map( static function ( $r ) {
                return array_map( static function ( $v ) {
                    return null === $v ? '' : $v;
                }, array_values( $r ) );
            }, $rows ),
        ) );
        return is_wp_error( $res ) ? $res : true;
    }
}
