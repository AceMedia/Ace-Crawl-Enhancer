<?php
/**
 * Request input, sanitised as it is read.
 *
 * Form handlers name the fields they accept and the type of each, so nothing else in the request
 * reaches them and every value is cleaned before any other code sees it. Callers check the nonce
 * and the user's capability first.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AceSeoInput {
    /**
     * Read the named fields from $_POST. Fields that were not sent are left out, so callers keep
     * their own defaults for them.
     *
     * @param array $fields Field name => type (see clean()).
     * @return array
     */
    public static function post( array $fields ) {
        $out = array();
        foreach ( $fields as $key => $type ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its nonce before reading.
            if ( ! isset( $_POST[ $key ] ) ) {
                continue;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by clean() for its declared type.
            $out[ $key ] = self::clean( wp_unslash( $_POST[ $key ] ), $type );
        }
        return $out;
    }

    /**
     * Sanitise one value for its type. Arrays are cleaned element by element; their keys are kept
     * and validated by the caller against what it offered in the form.
     *
     * Types: text, textarea, key, int, ids, bool, url, target (a post ID, a URL or path, or a
     * word such as "gone"), json.
     *
     * @param mixed  $value Unslashed input.
     * @param string $type  Field type.
     * @return mixed
     */
    public static function clean( $value, $type ) {
        switch ( $type ) {
            case 'int':
                return is_array( $value ) ? array_map( 'intval', $value ) : (int) $value;
            case 'ids':
                return wp_parse_id_list( $value );
            case 'bool':
                return empty( $value ) ? 0 : 1;
            case 'key':
                return map_deep( $value, 'sanitize_key' );
            case 'url':
                return map_deep( $value, array( __CLASS__, 'url' ) );
            case 'target':
                return is_scalar( $value ) ? self::target( (string) $value ) : '';
            case 'json':
                return is_scalar( $value ) ? self::json( (string) $value ) : '';
            case 'textarea':
                return map_deep( $value, 'sanitize_textarea_field' );
            case 'text':
            default:
                return map_deep( $value, 'sanitize_text_field' );
        }
    }

    /** @internal map_deep() callback for URLs. */
    public static function url( $value ) {
        return esc_url_raw( trim( (string) $value ) );
    }

    /** A redirect destination: digits stay an ID, URLs and paths keep their encoding, anything else is text. */
    private static function target( $value ) {
        $value = trim( $value );
        if ( '' === $value || ctype_digit( $value ) ) {
            return $value;
        }
        if ( '/' === $value[0] || preg_match( '#^https?://#i', $value ) ) {
            return esc_url_raw( $value );
        }
        return sanitize_text_field( $value );
    }

    /** JSON is decoded, its strings cleaned and re-encoded; anything that is not JSON comes back empty. */
    private static function json( $value ) {
        $decoded = json_decode( trim( $value ), true );
        if ( ! is_array( $decoded ) ) {
            return '';
        }
        return (string) wp_json_encode( map_deep( $decoded, array( __CLASS__, 'json_string' ) ) );
    }

    /** @internal map_deep() callback: strings lose markup and control characters, other scalars pass. */
    public static function json_string( $value ) {
        return is_string( $value ) ? sanitize_textarea_field( $value ) : $value;
    }
}
