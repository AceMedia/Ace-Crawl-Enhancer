<?php
/** WordPress-managed assets used only when their owning interface is rendered. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AceSeoAssets {
    /** Queue a script after its dependencies, before WordPress prints footer assets. */
    public static function script( $handle, $path, $dependencies = array( 'jquery' ) ) {
        wp_enqueue_script( $handle, ACE_SEO_URL . $path, $dependencies, ACE_SEO_VERSION, true );
    }

    /** Keep request-specific nonces and configuration in WordPress' script registry. */
    public static function inline_script( $handle, $code, $dependencies = array( 'jquery' ) ) {
        if ( wp_script_is( $handle, 'enqueued' ) ) {
            return;
        }
        wp_register_script( $handle, false, $dependencies, ACE_SEO_VERSION, true );
        wp_enqueue_script( $handle );
        wp_add_inline_script( $handle, $code );
    }

    /**
     * Render-time assets also work in widgets and metaboxes discovered after admin_head.
     * WordPress tracks printed handles so a repeated component never duplicates its CSS.
     */
    public static function style( $handle, $path ) {
        wp_enqueue_style( $handle, ACE_SEO_URL . $path, array(), ACE_SEO_VERSION );
        if ( did_action( 'admin_head' ) || did_action( 'wp_head' ) ) {
            wp_print_styles( array( $handle ) );
        }
    }

    public static function inline_style( $handle, $css ) {
        if ( wp_style_is( $handle, 'enqueued' ) ) {
            return;
        }
        wp_register_style( $handle, false, array(), ACE_SEO_VERSION );
        wp_enqueue_style( $handle );
        wp_add_inline_style( $handle, $css );
        if ( did_action( 'admin_head' ) || did_action( 'wp_head' ) ) {
            wp_print_styles( array( $handle ) );
        }
    }
}
