<?php
/**
 * Ace SEO Metabox Handler
 * Handles the SEO metabox display and functionality
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOMetabox {
    
    /**
     * Initialize metabox functionality
     */
    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta_fields' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }
    
    /**
     * Add meta boxes to post types
     */
    public function add_meta_boxes() {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        
        foreach ( $post_types as $post_type ) {
            if ( in_array( $post_type, array( 'attachment' ) ) ) {
                continue;
            }
            
            add_meta_box(
                'ace-seo-metabox',
                'Ace SEO',
                array( $this, 'render_metabox' ),
                $post_type,
                'normal',
                'high'
            );
        }
    }
    
    /**
     * Render the metabox
     */
    public function render_metabox( $post ) {
        // Add nonce for security
        wp_nonce_field( 'ace_seo_save_meta', 'ace_seo_meta_nonce' );
        
        // Include the metabox template
        include ACE_SEO_PATH . 'includes/admin/views/metabox.php';
    }
    
    /**
     * Save meta fields
     */
    public function save_meta_fields( $post_id ) {
        // Security checks
        if ( ! isset( $_POST['ace_seo_meta_nonce'] ) || 
             ! wp_verify_nonce( $_POST['ace_seo_meta_nonce'], 'ace_seo_save_meta' ) ) {
            return;
        }
        
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        
        // Get meta fields from main class
        $meta_fields = AceCrawlEnhancer::get_meta_fields();
        
        // Save each meta field
        foreach ( $meta_fields as $field_key => $field_config ) {
            $meta_key = '_yoast_wpseo_' . $field_key;
            $input_name = 'yoast_wpseo_' . str_replace( '_', '-', $field_key );
            
            if ( isset( $_POST[ $input_name ] ) ) {
                $value = $_POST[ $input_name ];
                
                // Handle arrays (like advanced robots settings)
                if ( is_array( $value ) ) {
                    $value = implode( ',', array_map( 'sanitize_text_field', $value ) );
                } else {
                    // Sanitize based on field type
                    switch ( $field_config['type'] ) {
                        case 'url':
                            $value = esc_url_raw( $value );
                            break;
                        case 'textarea':
                            $value = sanitize_textarea_field( $value );
                            break;
                        case 'checkbox':
                            $value = $value ? '1' : '0';
                            break;
                        default:
                            $value = sanitize_text_field( $value );
                            break;
                    }
                }
                
                // Save the meta value
                update_post_meta( $post_id, $meta_key, $value );
            } else {
                // Handle unchecked checkboxes
                if ( $field_config['type'] === 'checkbox' ) {
                    update_post_meta( $post_id, $meta_key, '0' );
                }
            }
        }
        
        // Handle special cases
        $this->handle_special_fields( $post_id );
    }
    
    /**
     * Handle special field cases
     */
    private function handle_special_fields( $post_id ) {
        // Handle cornerstone content
        if ( isset( $_POST['yoast_wpseo_is_cornerstone'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', 'true' );
        } else {
            update_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', 'false' );
        }
        
        // Handle advanced robots settings
        if ( isset( $_POST['yoast_wpseo_meta-robots-adv'] ) && is_array( $_POST['yoast_wpseo_meta-robots-adv'] ) ) {
            $robots_adv = array_map( 'sanitize_text_field', $_POST['yoast_wpseo_meta-robots-adv'] );
            update_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', implode( ',', $robots_adv ) );
        } else {
            update_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', '' );
        }
    }
    
    /**
     * Enqueue scripts and styles for metabox
     */
    public function enqueue_scripts( $hook ) {
        // Only load on post edit screens
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            return;
        }
        
        // Get current post type
        global $post_type;
        $public_post_types = get_post_types( array( 'public' => true ), 'names' );
        
        if ( ! in_array( $post_type, $public_post_types ) || $post_type === 'attachment' ) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'ace-seo-admin',
            ACE_SEO_URL . 'assets/css/admin.css',
            array(),
            ACE_SEO_VERSION
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'ace-seo-admin',
            ACE_SEO_URL . 'assets/js/admin.js',
            array( 'jquery', 'wp-api' ),
            ACE_SEO_VERSION,
            true
        );
        
        // Enqueue media scripts for image selection
        wp_enqueue_media();
        
        // Localize script with data
        wp_localize_script( 'ace-seo-admin', 'aceSeoAdmin', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'ace-seo/v1/' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'postId' => get_the_ID(),
            'strings' => array(
                'analyzing' => __( 'Analyzing...', 'ace-seo' ),
                'excellent' => __( 'Excellent', 'ace-seo' ),
                'good' => __( 'Good', 'ace-seo' ),
                'ok' => __( 'OK', 'ace-seo' ),
                'poor' => __( 'Poor', 'ace-seo' ),
                'bad' => __( 'Bad', 'ace-seo' ),
            )
        ) );
    }
}

// Initialize the metabox class
new AceSEOMetabox();
