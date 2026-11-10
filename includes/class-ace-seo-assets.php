<?php
/** WordPress-managed assets used only when their owning interface is rendered. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AceCrawlAssets {
    /** Queue a script after its dependencies, before WordPress prints footer assets. */
    public static function script( $handle, $path, $dependencies = array( 'jquery' ) ) {
        wp_enqueue_script( $handle, ACECRAWL_URL . $path, $dependencies, ACECRAWL_VERSION, true );
    }

    /** Keep request-specific nonces and configuration in WordPress' script registry. */
    public static function inline_script( $handle, $code, $dependencies = array( 'jquery' ) ) {
        if ( wp_script_is( $handle, 'enqueued' ) ) {
            return;
        }
        wp_register_script( $handle, false, $dependencies, ACECRAWL_VERSION, true );
        wp_enqueue_script( $handle );
        // A closing script tag in any letter case would end the element early.
        wp_add_inline_script( $handle, preg_replace( '#</(script)#i', '<\\/$1', (string) $code ) );
    }

    /**
     * Render-time assets also work in widgets and metaboxes discovered after admin_head.
     * WordPress tracks printed handles so a repeated component never duplicates its CSS.
     */
    public static function style( $handle, $path ) {
        wp_enqueue_style( $handle, ACECRAWL_URL . $path, array(), ACECRAWL_VERSION );
        if ( did_action( 'admin_head' ) || did_action( 'wp_head' ) ) {
            wp_print_styles( array( $handle ) );
        }
    }

    public static function inline_style( $handle, $css ) {
        if ( wp_style_is( $handle, 'enqueued' ) ) {
            return;
        }
        wp_register_style( $handle, false, array(), ACECRAWL_VERSION );
        wp_enqueue_style( $handle );
        // CSS never needs markup, so strip anything that could close the style element.
        wp_add_inline_style( $handle, wp_strip_all_tags( (string) $css ) );
        if ( did_action( 'admin_head' ) || did_action( 'wp_head' ) ) {
            wp_print_styles( array( $handle ) );
        }
    }
}
