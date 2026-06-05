<?php
/**
 * Google Site Kit connection helper.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOSiteKit {

    const SCOPE_ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';
    const SCOPE_SEARCH_CONSOLE = 'https://www.googleapis.com/auth/webmasters.readonly';
    const SCOPE_PAGESPEED = 'https://www.googleapis.com/auth/pagespeedonline';

    /**
     * Check whether Site Kit is active.
     */
    public static function is_active() {
        return class_exists( 'Google\\Site_Kit\\Plugin' );
    }

    /**
     * Return a module settings option as an array.
     */
    public static function get_module_settings( $module ) {
        $settings = get_option( 'googlesitekit_' . $module . '_settings', array() );
        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Return the detected GA4 property ID.
     */
    public static function get_analytics_property_id() {
        $settings = self::get_module_settings( 'analytics-4' );
        return (string) ( $settings['propertyID'] ?? '' );
    }

    /**
     * Return the detected Search Console property URL.
     */
    public static function get_search_console_property_id() {
        $settings = self::get_module_settings( 'search-console' );
        $property = (string) ( $settings['propertyID'] ?? ( $settings['siteURL'] ?? '' ) );

        return $property !== '' ? $property : home_url( '/' );
    }

    /**
     * Return a compact Site Kit/module status map.
     */
    public static function get_status() {
        $active = self::is_active();

        $analytics_property = $active ? self::get_analytics_property_id() : '';
        $search_property = $active ? self::get_search_console_property_id() : '';
        $pagespeed_token = $active ? self::get_access_token( array( self::SCOPE_PAGESPEED ) ) : false;

        return array(
            'plugin_active' => $active,
            'modules'       => array(
                'analytics_4'    => array(
                    'connected'   => $active && $analytics_property !== '',
                    'property_id' => $analytics_property,
                ),
                'search_console' => array(
                    'connected'   => $active && $search_property !== '',
                    'property_id' => $search_property,
                ),
                'pagespeed'      => array(
                    'connected'       => $active && ! is_wp_error( $pagespeed_token ) && $pagespeed_token !== false,
                    'token_available' => $active && ! is_wp_error( $pagespeed_token ) && $pagespeed_token !== false,
                ),
                'adsense'        => array(
                    'connected' => $active && ! empty( self::get_module_settings( 'adsense' ) ),
                    'settings'  => self::summarise_settings( self::get_module_settings( 'adsense' ) ),
                ),
                'tagmanager'     => array(
                    'connected' => $active && ! empty( self::get_module_settings( 'tagmanager' ) ),
                    'settings'  => self::summarise_settings( self::get_module_settings( 'tagmanager' ) ),
                ),
            ),
        );
    }

    /**
     * Fetch a Site Kit OAuth access token, optionally requiring all scopes.
     *
     * @param array $required_scopes Required OAuth scopes.
     * @return string|false|WP_Error
     */
    public static function get_access_token( $required_scopes = array() ) {
        if ( ! self::is_active() ) {
            return new WP_Error( 'sitekit_inactive', 'Google Site Kit is not installed or active.' );
        }

        if ( ! class_exists( 'Google\\Site_Kit\\Core\\Storage\\Data_Encryption' ) ) {
            return new WP_Error( 'sitekit_storage_unavailable', 'Site Kit token storage is not available.' );
        }

        $required_scopes = array_values( array_filter( (array) $required_scopes ) );
        sort( $required_scopes );

        $cache_key = 'ace_seo_sitekit_token_' . md5( implode( '|', $required_scopes ) );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $candidates = self::get_candidate_user_ids();
        foreach ( $candidates as $user_id ) {
            $token = self::get_user_access_token( $user_id, $required_scopes );
            if ( $token ) {
                set_transient( $cache_key, $token, 5 * MINUTE_IN_SECONDS );
                return $token;
            }
        }

        return new WP_Error( 'sitekit_no_token', 'No valid Site Kit OAuth token is available for the required Google scopes.' );
    }

    /**
     * Clear cached Site Kit OAuth tokens.
     */
    public static function clear_token_cache() {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_ace_seo_sitekit_token_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_ace_seo_sitekit_token_' ) . '%'
            )
        );
    }

    /**
     * Return candidate users for Site Kit token lookup.
     */
    private static function get_candidate_user_ids() {
        $candidates = array();
        $current_user_id = get_current_user_id();

        if ( $current_user_id ) {
            $candidates[] = $current_user_id;
        }

        $admins = get_users(
            array(
                'role'   => 'administrator',
                'number' => 5,
                'fields' => 'ID',
            )
        );

        foreach ( $admins as $user_id ) {
            $user_id = (int) $user_id;
            if ( ! in_array( $user_id, $candidates, true ) ) {
                $candidates[] = $user_id;
            }
        }

        return $candidates;
    }

    /**
     * Read, refresh if needed, decrypt, and validate a user token.
     */
    private static function get_user_access_token( $user_id, $required_scopes ) {
        $raw_token = get_user_option( 'googlesitekit_access_token', $user_id );
        if ( empty( $raw_token ) ) {
            return false;
        }

        if ( ! self::user_has_scopes( $user_id, $required_scopes ) ) {
            return false;
        }

        if ( self::is_user_token_expired( $user_id ) ) {
            self::refresh_user_token( $user_id );
            $raw_token = get_user_option( 'googlesitekit_access_token', $user_id );
            if ( empty( $raw_token ) || self::is_user_token_expired( $user_id ) ) {
                return false;
            }
        }

        try {
            $decrypted = ( new \Google\Site_Kit\Core\Storage\Data_Encryption() )->decrypt( $raw_token );
        } catch ( \Throwable $e ) {
            return false;
        }

        return ! empty( $decrypted ) ? $decrypted : false;
    }

    /**
     * Check whether a Site Kit user has all required scopes.
     */
    private static function user_has_scopes( $user_id, $required_scopes ) {
        if ( empty( $required_scopes ) ) {
            return true;
        }

        $scopes = get_user_option( 'googlesitekit_auth_scopes', $user_id );
        if ( ! is_array( $scopes ) ) {
            return false;
        }

        foreach ( $required_scopes as $scope ) {
            if ( ! in_array( $scope, $scopes, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether a user token is expired or close to expiring.
     */
    private static function is_user_token_expired( $user_id ) {
        $expires_in = (int) get_user_option( 'googlesitekit_access_token_expires_in', $user_id );
        $created_at = (int) get_user_option( 'googlesitekit_access_token_created_at', $user_id );

        return $expires_in && $created_at && ( $created_at + $expires_in - 120 ) < time();
    }

    /**
     * Refresh a Site Kit token using Site Kit's own OAuth client.
     */
    private static function refresh_user_token( $user_id ) {
        if ( get_transient( 'ace_seo_sitekit_refresh_fail_' . $user_id ) ) {
            return;
        }

        if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
            return;
        }

        try {
            $context      = new \Google\Site_Kit\Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
            $options      = new \Google\Site_Kit\Core\Storage\Options( $context );
            $user_options = new \Google\Site_Kit\Core\Storage\User_Options( $context, $user_id );
            $credentials  = new \Google\Site_Kit\Core\Authentication\Credentials(
                new \Google\Site_Kit\Core\Storage\Encrypted_Options( $options )
            );
            $google_proxy = new \Google\Site_Kit\Core\Authentication\Google_Proxy( $context );
            $profile      = new \Google\Site_Kit\Core\Authentication\Profile( $user_options );
            $token        = new \Google\Site_Kit\Core\Authentication\Token( $user_options );
            $transients   = new \Google\Site_Kit\Core\Storage\Transients( $context );

            $oauth = new \Google\Site_Kit\Core\Authentication\Clients\OAuth_Client(
                $context,
                $options,
                $user_options,
                $credentials,
                $google_proxy,
                $profile,
                $token,
                $transients
            );

            $stored = $oauth->get_token();
            if ( empty( $stored['refresh_token'] ) ) {
                set_transient( 'ace_seo_sitekit_refresh_fail_' . $user_id, 1, 10 * MINUTE_IN_SECONDS );
                return;
            }

            $oauth->refresh_token();
        } catch ( \Throwable $e ) {
            set_transient( 'ace_seo_sitekit_refresh_fail_' . $user_id, 1, 10 * MINUTE_IN_SECONDS );
        }
    }

    /**
     * Return safe non-secret setting hints.
     */
    private static function summarise_settings( $settings ) {
        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return array();
        }

        $summary = array();
        foreach ( array( 'accountID', 'clientID', 'containerID', 'propertyID', 'profileID', 'webDataStreamID' ) as $key ) {
            if ( ! empty( $settings[ $key ] ) ) {
                $summary[ $key ] = $settings[ $key ];
            }
        }

        return $summary;
    }
}
