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
        $msg = self::save_settings( wp_unslash( $_POST ) );
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), $msg, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-sheets' ) );
        exit;
    }

    /** Save the connection from submitted fields and return a plain-English result (no redirect). */
    public static function save_settings( array $input ) {
        $o   = get_option( self::OPTION, array() );
        $o   = is_array( $o ) ? $o : array();
        $msg = 'Google Sheets settings saved.';

        $o['sheet_id'] = self::parse_sheet_id( $input['sheet'] ?? '' );

        $raw = trim( (string) ( $input['key'] ?? '' ) );
        if ( ! empty( $input['forget_key'] ) ) {
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
        return $msg;
    }

    /** The administrator-only connection form in Settings → Retention. */
    /** Standalone form (own nonce, action and button). The Retention tab uses render_fields() inside its single form. */
    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ace_seo_sheets_settings' );
        echo '<input type="hidden" name="action" value="ace_seo_sheets_settings">';
        self::render_fields( false );
        echo '<p><button class="button">Save and check access</button></p></form>';
        if ( class_exists( 'AceSeoSheetsSchedule' ) ) {
            AceSeoSheetsSchedule::render_settings();
        }
    }

    /** The connection fields only: no form, nonce, action or button, so a parent form can save them. */
    public static function render_fields( $with_schedule = true ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $email  = self::account_email();
        $locked = defined( 'ACE_SEO_SHEETS_KEY_FILE' );
        ?>
        <details id="retention-sheets" style="margin:1em 0" <?php echo self::configured() ? '' : 'open'; ?>>
            <summary style="cursor:pointer;font-weight:600">Connection <?php echo self::configured() ? '(connection details saved)' : '(not set up)'; ?></summary>
                <p>Adds <strong>Export to Google Sheets</strong> beside Export CSV on the post list. Each export copies the currently filtered posts into a new tab in your spreadsheet. It is a snapshot: later changes in WordPress or the spreadsheet do not update each other.</p>
                <p>To set it up:</p>
                <ol style="margin-left:2em">
                    <li>In Google Cloud, enable the <em>Google Sheets API</em> on a project, create a service account and download a JSON key for it.</li>
                    <li>Paste the key below (or put the file outside the web root and define <code>ACE_SEO_SHEETS_KEY_FILE</code> as its path).</li>
                    <li>Create a spreadsheet and share it, as an Editor, with the service account's email<?php echo $email ? ': <code style="user-select:all">' . esc_html( $email ) . '</code>' : ''; ?>.</li>
                    <li>Paste the spreadsheet's URL below and save; it checks the connection there and then.</li>
                </ol>
                <table class="form-table" style="max-width:800px"><tbody>
                    <tr><th scope="row"><label for="ace-seo-sheet">Spreadsheet link or ID</label></th><td><input type="text" id="ace-seo-sheet" name="sheet" class="large-text" placeholder="https://docs.google.com/spreadsheets/d/…" value="<?php echo esc_attr( self::sheet_url() ); ?>"></td></tr>
                    <tr><th scope="row"><?php if ( $locked ) : ?>Google service account key<?php else : ?><label for="ace-seo-sheets-key">Google service account key</label><?php endif; ?></th><td>
                        <?php if ( $locked ) : ?>
                            Read from <code>ACE_SEO_SHEETS_KEY_FILE</code><?php echo $email ? '' : ' (file missing or unreadable)'; ?>.
                        <?php else : ?>
                            <textarea id="ace-seo-sheets-key" name="key" autocomplete="off" spellcheck="false" rows="4" class="large-text code" placeholder="<?php echo $email ? esc_attr( 'Stored for ' . $email . '. Paste a new key to replace it.' ) : '{ &quot;type&quot;: &quot;service_account&quot;, … }'; ?>"></textarea>
                            <?php if ( $email ) : ?><label><input type="checkbox" name="forget_key" value="1"> Forget the stored key</label><?php endif; ?>
                            <p class="description">Leave this blank to keep the saved key. The service account email, private key and token address are stored in an option that does not autoload. The private key is never shown again.</p>
                        <?php endif; ?>
                    </td></tr>
                </tbody></table>
                <p class="description">Saving checks that Google lets us read the spreadsheet; the result is shown after saving. Creating an export also needs Editor access. Saving does not export any posts.</p>
        </details>
        <?php
        if ( $with_schedule && class_exists( 'AceSeoSheetsSchedule' ) ) {
            AceSeoSheetsSchedule::render_fields();
        }
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
        if ( ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'ace_sheets_openssl', 'Google Sheets needs the PHP OpenSSL extension. Ask your host to enable it.' );
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
        if ( $code < 200 || ! is_array( $data ) ) {
            return new WP_Error( 'ace_sheets_response', 'Google returned an unreadable response. The export could not be confirmed.' );
        }
        return $data;
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
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $created = $res['replies'][0]['addSheet']['properties']['title'] ?? null;
        if ( ! is_string( $created ) || '' === $created ) {
            return new WP_Error( 'ace_sheets_response', 'Google did not confirm the new export tab. Check the spreadsheet before trying again.' );
        }
        return $created;
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
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        if ( ! isset( $res['updates']['updatedRows'] ) || (int) $res['updates']['updatedRows'] !== count( $rows ) ) {
            return new WP_Error( 'ace_sheets_incomplete', 'Google did not confirm all rows in this batch. The export tab may be incomplete; check it before starting a new export.' );
        }
        return true;
    }

    /**
     * Find or create the one tab owned by a scheduled job. A lost creation response
     * is safe to retry: both the title and numeric sheet ID were saved before the request.
     *
     * @return true|WP_Error
     */
    public static function ensure_snapshot_tab( $spreadsheet, $title, $tab_id, $rows, $columns, $allow_create = true, $may_write = null ) {
        $existing = self::request( 'GET', rawurlencode( $spreadsheet ) . '?fields=sheets.properties(sheetId,title)' );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }
        if ( ! isset( $existing['sheets'] ) || ! is_array( $existing['sheets'] ) ) {
            return new WP_Error( 'ace_sheets_response', 'Google did not return the tab list. No new tab was created.' );
        }
        foreach ( $existing['sheets'] as $sheet ) {
            $properties = $sheet['properties'] ?? array();
            if ( (int) ( $properties['sheetId'] ?? -1 ) === (int) $tab_id || ( $properties['title'] ?? '' ) === $title ) {
                return (int) ( $properties['sheetId'] ?? -1 ) === (int) $tab_id && ( $properties['title'] ?? '' ) === $title
                    ? true
                    : new WP_Error( 'ace_sheets_tab_conflict', 'The snapshot tab name or ID belongs to another tab. No existing tab was overwritten.' );
            }
        }
        if ( ! $allow_create ) {
            return new WP_Error( 'ace_sheets_tab_missing', 'The unfinished report tab was removed. The current report has not changed.' );
        }
        if ( is_callable( $may_write ) && ! $may_write() ) { return new WP_Error( 'ace_sheets_stopped', 'The refresh was stopped before creating its working copy.' ); }
        $created = self::request( 'POST', rawurlencode( $spreadsheet ) . ':batchUpdate', array(
            'requests' => array( array( 'addSheet' => array( 'properties' => array(
                'sheetId' => (int) $tab_id,
                'title' => $title,
                'hidden' => true,
                'gridProperties' => array( 'rowCount' => max( 1, (int) $rows ), 'columnCount' => max( 1, (int) $columns ), 'frozenRowCount' => 1 ),
            ) ) ) ),
        ) );
        if ( is_wp_error( $created ) ) {
            return $created;
        }
        $properties = $created['replies'][0]['addSheet']['properties'] ?? array();
        if ( (int) ( $properties['sheetId'] ?? -1 ) !== (int) $tab_id || ( $properties['title'] ?? '' ) !== $title ) {
            return new WP_Error( 'ace_sheets_response', 'Google did not confirm the snapshot tab. The next attempt will check for it before creating anything.' );
        }
        return true;
    }

    /** Idempotent numeric-grid write: a renamed/replaced title cannot redirect a retry. */
    public static function write_snapshot_rows( $spreadsheet, $tab_id, $first_row, array $rows ) {
        if ( ! $rows ) { return true; }
        $result = self::request( 'POST', rawurlencode( $spreadsheet ) . '/values:batchUpdateByDataFilter', array(
            'valueInputOption' => 'RAW',
            'data' => array( array(
                'dataFilter' => array( 'gridRange' => array( 'sheetId' => (int) $tab_id, 'startRowIndex' => max( 0, (int) $first_row - 1 ), 'startColumnIndex' => 0 ) ),
                'majorDimension' => 'ROWS',
                'values' => array_map( static function ( $row ) {
                    return array_map( static function ( $value ) { return null === $value ? '' : $value; }, array_values( $row ) );
                }, $rows ),
            ) ),
        ) );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( ! isset( $result['totalUpdatedRows'] ) || (int) $result['totalUpdatedRows'] !== count( $rows ) ) {
            return new WP_Error( 'ace_sheets_incomplete', 'Google did not confirm every row. The same report range can be retried without adding duplicates.' );
        }
        return true;
    }

    /** Remove only the verified hidden working tab from a cancelled job. */
    public static function discard_snapshot_tab( $spreadsheet, $tab_id, $title ) {
        $metadata = self::request( 'GET', rawurlencode( $spreadsheet ) . '?fields=sheets.properties(sheetId,title,hidden)' );
        if ( is_wp_error( $metadata ) ) { return $metadata; }
        if ( ! isset( $metadata['sheets'] ) || ! is_array( $metadata['sheets'] ) ) { return new WP_Error( 'ace_sheets_response', 'Google did not confirm the unfinished tab list.' ); }
        foreach ( $metadata['sheets'] as $sheet ) {
            $properties = $sheet['properties'] ?? array();
            if ( (int) ( $properties['sheetId'] ?? -1 ) !== (int) $tab_id ) { continue; }
            if ( ( $properties['title'] ?? '' ) !== $title || empty( $properties['hidden'] ) ) {
                return new WP_Error( 'ace_sheets_stage_changed', 'The unfinished tab has been changed outside Ace SEO. It was not removed.' );
            }
            $result = self::request( 'POST', rawurlencode( $spreadsheet ) . ':batchUpdate', array( 'requests' => array( array( 'deleteSheet' => array( 'sheetId' => (int) $tab_id ) ) ) ) );
            if ( is_wp_error( $result ) ) { return $result; }
            return isset( $result['replies'] ) && count( $result['replies'] ) === 1 ? true : new WP_Error( 'ace_sheets_response', 'The unfinished tab removal was not confirmed; it can be checked again safely.' );
        }
        return true;
    }

    /**
     * Atomically replace only the managed report after staging has finished.
     * The run marker and deletion of our staging tab commit in the same batch.
     * A timeout is resolved by reading that marker, never by clearing the report again blindly.
     */
    public static function publish_snapshot( $spreadsheet, $stage_id, $stage_title, $report_id, $run_id, $rows, $columns, $allow_create_report, $previous_rows = 0, $previous_columns = 0, $may_publish = null ) {
        $metadata = self::request( 'GET', rawurlencode( $spreadsheet ) . '?fields=sheets(properties(sheetId,title,gridProperties),developerMetadata(metadataId,metadataKey,metadataValue),basicFilter)' );
        if ( is_wp_error( $metadata ) ) { return $metadata; }
        if ( ! isset( $metadata['sheets'] ) || ! is_array( $metadata['sheets'] ) ) {
            return new WP_Error( 'ace_sheets_response', 'Google did not return the report tab details. The current report has not changed.' );
        }
        $stage = null; $report = null; $title_conflict = false;
        foreach ( $metadata['sheets'] as $sheet ) {
            $properties = $sheet['properties'] ?? array();
            $id = (int) ( $properties['sheetId'] ?? -1 );
            if ( $id === (int) $stage_id && ( $properties['title'] ?? '' ) === $stage_title ) { $stage = $sheet; }
            if ( $id === (int) $report_id ) { $report = $sheet; }
            if ( 'Ace SEO report' === ( $properties['title'] ?? '' ) && $id !== (int) $report_id ) { $title_conflict = true; }
        }
        foreach ( (array) ( $report['developerMetadata'] ?? array() ) as $marker ) {
            if ( 'ace_seo_report_run' === ( $marker['metadataKey'] ?? '' ) && $run_id === ( $marker['metadataValue'] ?? '' ) ) { return true; }
        }
        if ( ! $stage || $title_conflict || ( $allow_create_report && $report ) || ( ! $report && ! $allow_create_report ) || (int) $stage_id === (int) $report_id ) {
            return new WP_Error( 'ace_sheets_report_conflict', 'The managed report or its unfinished copy no longer matches. No existing report was replaced; check the tab setup.' );
        }
        $grid = array( 'rowCount' => max( 1, (int) $rows ), 'columnCount' => max( 1, (int) $columns ), 'frozenRowCount' => 1 );
        $requests = array();
        $managed_columns = (int) $columns;
        $managed_rows = (int) $rows;
        $known_rows = $previous_rows > 0;
        $known_columns = $previous_columns > 0;
        if ( $report ) {
            $managed_rows = max( $managed_rows, min( (int) $previous_rows, (int) ( $report['properties']['gridProperties']['rowCount'] ?? $rows ) ) );
            $managed_columns = max( $managed_columns, min( (int) $previous_columns, (int) ( $report['properties']['gridProperties']['columnCount'] ?? $columns ) ) );
            foreach ( (array) ( $report['developerMetadata'] ?? array() ) as $marker ) {
                if ( 'ace_seo_report_rows' === ( $marker['metadataKey'] ?? '' ) ) { $managed_rows = max( $managed_rows, min( (int) $marker['metadataValue'], (int) ( $report['properties']['gridProperties']['rowCount'] ?? $rows ) ) ); $known_rows = (int) $marker['metadataValue'] > 0; }
                if ( 'ace_seo_report_columns' === ( $marker['metadataKey'] ?? '' ) ) { $managed_columns = max( $managed_columns, min( (int) $marker['metadataValue'], (int) ( $report['properties']['gridProperties']['columnCount'] ?? $columns ) ) ); $known_columns = (int) $marker['metadataValue'] > 0; }
            }
            if ( ! $known_rows || ! $known_columns ) { return new WP_Error( 'ace_sheets_report_extent', 'The managed report size has not been recorded. Its existing cells were left unchanged.' ); }
            $grid['rowCount'] = max( $grid['rowCount'], (int) ( $report['properties']['gridProperties']['rowCount'] ?? 1 ) );
            $grid['columnCount'] = max( $grid['columnCount'], (int) ( $report['properties']['gridProperties']['columnCount'] ?? 1 ) );
            // Keep unrelated columns/formatting; clear stale values only within the managed report width.
            $requests[] = array( 'clearBasicFilter' => array( 'sheetId' => (int) $report_id ) );
            $requests[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => (int) $report_id, 'title' => 'Ace SEO report', 'index' => 0, 'hidden' => false, 'gridProperties' => $grid ), 'fields' => 'title,index,hidden,gridProperties.rowCount,gridProperties.columnCount,gridProperties.frozenRowCount' ) );
            $requests[] = array( 'updateCells' => array( 'range' => array( 'sheetId' => (int) $report_id, 'startRowIndex' => 0, 'endRowIndex' => $managed_rows, 'startColumnIndex' => 0, 'endColumnIndex' => $managed_columns ), 'fields' => 'userEnteredValue' ) );
            foreach ( (array) ( $report['developerMetadata'] ?? array() ) as $marker ) {
                if ( in_array( $marker['metadataKey'] ?? '', array( 'ace_seo_report_run', 'ace_seo_report_columns', 'ace_seo_report_rows' ), true ) && isset( $marker['metadataId'] ) ) {
                    $requests[] = array( 'deleteDeveloperMetadata' => array( 'dataFilter' => array( 'developerMetadataLookup' => array( 'metadataId' => (int) $marker['metadataId'] ) ) ) );
                }
            }
        } else {
            $requests[] = array( 'addSheet' => array( 'properties' => array( 'sheetId' => (int) $report_id, 'title' => 'Ace SEO report', 'index' => 0, 'gridProperties' => $grid ) ) );
        }
        $source = array( 'sheetId' => (int) $stage_id, 'startRowIndex' => 0, 'endRowIndex' => (int) $rows, 'startColumnIndex' => 0, 'endColumnIndex' => (int) $columns );
        $destination = array_merge( $source, array( 'sheetId' => (int) $report_id ) );
        $requests[] = array( 'copyPaste' => array( 'source' => $source, 'destination' => $destination, 'pasteType' => 'PASTE_VALUES', 'pasteOrientation' => 'NORMAL' ) );
        $filter = $report['basicFilter'] ?? array();
        $filter['range'] = $destination;
        foreach ( array_keys( $filter['criteria'] ?? array() ) as $column ) { if ( (int) $column >= $columns ) { unset( $filter['criteria'][ $column ] ); } }
        if ( isset( $filter['criteria'] ) ) { if ( ! $filter['criteria'] ) { unset( $filter['criteria'] ); } else { $filter['criteria'] = (object) $filter['criteria']; } }
        if ( isset( $filter['sortSpecs'] ) ) { $filter['sortSpecs'] = array_values( array_filter( $filter['sortSpecs'], static function ( $sort ) use ( $columns ) { return (int) ( $sort['dimensionIndex'] ?? 0 ) < $columns; } ) ); }
        $requests[] = array( 'setBasicFilter' => array( 'filter' => $filter ) );
        $requests[] = array( 'deleteSheet' => array( 'sheetId' => (int) $stage_id ) );
        $requests[] = array( 'createDeveloperMetadata' => array( 'developerMetadata' => array( 'metadataKey' => 'ace_seo_report_rows', 'metadataValue' => (string) $rows, 'location' => array( 'sheetId' => (int) $report_id ), 'visibility' => 'DOCUMENT' ) ) );
        $requests[] = array( 'createDeveloperMetadata' => array( 'developerMetadata' => array( 'metadataKey' => 'ace_seo_report_columns', 'metadataValue' => (string) $columns, 'location' => array( 'sheetId' => (int) $report_id ), 'visibility' => 'DOCUMENT' ) ) );
        $requests[] = array( 'createDeveloperMetadata' => array( 'developerMetadata' => array( 'metadataKey' => 'ace_seo_report_run', 'metadataValue' => $run_id, 'location' => array( 'sheetId' => (int) $report_id ), 'visibility' => 'DOCUMENT' ) ) );
        if ( is_callable( $may_publish ) && ! $may_publish() ) { return new WP_Error( 'ace_sheets_stopped', 'The refresh was stopped before replacing the main report.' ); }
        $result = self::request( 'POST', rawurlencode( $spreadsheet ) . ':batchUpdate', array( 'requests' => $requests ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $last = count( $requests ) - 1;
        $confirmed = $result['replies'][ $last ]['createDeveloperMetadata']['developerMetadata'] ?? array();
        if ( count( $result['replies'] ?? array() ) !== count( $requests ) || ( $confirmed['metadataValue'] ?? '' ) !== $run_id || (int) ( $confirmed['location']['sheetId'] ?? -1 ) !== (int) $report_id ) {
            return new WP_Error( 'ace_sheets_publish_unconfirmed', 'The report update could not be confirmed. The next attempt will check its run marker before writing.' );
        }
        return true;
    }
}
