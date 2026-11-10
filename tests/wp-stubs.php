<?php
/**
 * WordPress helpers the plugin's input handling uses (AceCrawlInput), for standalone tests that run
 * without WordPress. Each is defined only when the test has not already defined it.
 */
if ( ! function_exists( 'map_deep' ) ) {
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = map_deep( $item, $callback );
			}
			return $value;
		}
		return is_object( $value ) ? $value : call_user_func( $callback, $value );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return map_deep( $value, static function ( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } );
	}
}
if ( ! function_exists( 'wp_parse_id_list' ) ) {
	function wp_parse_id_list( $list ) {
		$list = is_array( $list ) ? $list : preg_split( '/[\s,]+/', (string) $list );
		return array_values( array_unique( array_map( 'absint', $list ) ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}
