<?php
/** Redacted JSON diagnostics, including PHP 8.6 locations when supplied by the parser. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function acecrawl_json_array_result( $raw ) {
    if ( ! is_string( $raw ) ) {
        return array( 'ok' => false, 'value' => null, 'error' => array( 'code' => 'invalid_json_input', 'json_error_code' => 0 ) );
    }
    try {
        $value = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
    } catch ( JsonException $exception ) {
        $error = array( 'code' => 'invalid_json', 'json_error_code' => (int) $exception->getCode() );
        // Optional parser detail only. Never retain its full message or the response body.
        if ( preg_match( '/near location ([1-9][0-9]*):([1-9][0-9]*)\s*$/', $exception->getMessage(), $location ) ) {
            $error['line'] = (int) $location[1];
            $error['column'] = (int) $location[2];
        }
        return array( 'ok' => false, 'value' => null, 'error' => $error );
    }
    if ( ! is_array( $value ) ) {
        return array( 'ok' => false, 'value' => null, 'error' => array( 'code' => 'unexpected_json_shape', 'json_error_code' => 0, 'json_type' => gettype( $value ) ) );
    }
    return array( 'ok' => true, 'value' => $value, 'error' => null );
}
