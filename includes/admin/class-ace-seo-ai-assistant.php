<?php
/**
 * Ace SEO AI Assistant Class
 * Handles AI-powered content suggestions and generation
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOAiAssistant {
    
    /**
     * Initialize AI assistant functionality
     */
    public function __construct() {
        add_action( 'wp_ajax_ace_seo_generate_titles', array( $this, 'ajax_generate_titles' ) );
        add_action( 'wp_ajax_ace_seo_generate_descriptions', array( $this, 'ajax_generate_descriptions' ) );
        add_action( 'wp_ajax_ace_seo_analyze_content', array( $this, 'ajax_analyze_content' ) );
        add_action( 'wp_ajax_ace_seo_suggest_topics', array( $this, 'ajax_suggest_topics' ) );
        add_action( 'wp_ajax_ace_seo_improve_content', array( $this, 'ajax_improve_content' ) );
        add_action( 'wp_ajax_ace_seo_generate_keywords', array( $this, 'ajax_generate_keywords' ) );
        
        // Social media AI handlers
        add_action( 'wp_ajax_ace_seo_generate_facebook_titles', array( $this, 'ajax_generate_facebook_titles' ) );
        add_action( 'wp_ajax_ace_seo_generate_facebook_descriptions', array( $this, 'ajax_generate_facebook_descriptions' ) );
        add_action( 'wp_ajax_ace_seo_generate_twitter_titles', array( $this, 'ajax_generate_twitter_titles' ) );
        add_action( 'wp_ajax_ace_seo_generate_twitter_descriptions', array( $this, 'ajax_generate_twitter_descriptions' ) );
        add_action( 'wp_ajax_ace_seo_generate_facebook_image', array( $this, 'ajax_generate_facebook_image' ) );
        add_action( 'wp_ajax_ace_seo_generate_twitter_image', array( $this, 'ajax_generate_twitter_image' ) );
        add_action( 'wp_ajax_ace_seo_regenerate_image', array( $this, 'ajax_regenerate_image' ) );
        add_action( 'wp_ajax_ace_seo_generate_more_images', array( $this, 'ajax_generate_more_images' ) );
        add_action( 'wp_ajax_ace_seo_save_image_to_library', array( $this, 'ajax_save_image_to_library' ) );
    }
    
    /**
     * AJAX handler for generating SEO titles
     */
    public function ajax_generate_titles() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
        // Set global post context for caching
        if ( $post_id ) {
            global $post;
            $post = get_post( $post_id );
        }
        
        // Debug logging
        $titles = AceSEOApiHelper::generate_seo_titles( $post_content, $focus_keyword, $current_title );
        
        if ( is_wp_error( $titles ) ) {
            wp_send_json_error( $titles->get_error_message() );
        }
        
        wp_send_json_success( array( 'titles' => $titles ) );
    }
    
    /**
     * AJAX handler for generating meta descriptions
     */
    public function ajax_generate_descriptions() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
        // Set global post context for caching
        if ( $post_id ) {
            global $post;
            $post = get_post( $post_id );
        }
        
        // Debug logging
        
        $descriptions = AceSEOApiHelper::generate_meta_descriptions( $post_content, $focus_keyword, $current_title );        if ( is_wp_error( $descriptions ) ) {
            wp_send_json_error( $descriptions->get_error_message() );
        }
        
        wp_send_json_success( array( 'descriptions' => $descriptions ) );
    }
    
    /**
     * AJAX handler for AI content analysis
     */
    public function ajax_analyze_content() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI analysis' );
        }
        
        $analysis = AceSEOApiHelper::analyze_content_with_ai( $post_content, $focus_keyword, $current_title );
        
        if ( is_wp_error( $analysis ) ) {
            wp_send_json_error( $analysis->get_error_message() );
        }
        
        wp_send_json_success( array( 'analysis' => $analysis ) );
    }
    
    /**
     * AJAX handler for topic suggestions
     */
    public function ajax_suggest_topics() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for topic suggestions' );
        }
        
        $suggestions = AceSEOApiHelper::generate_topic_suggestions( $post_content, $focus_keyword, $current_title );
        
        if ( is_wp_error( $suggestions ) ) {
            wp_send_json_error( $suggestions->get_error_message() );
        }
        
        wp_send_json_success( array( 'suggestions' => $suggestions ) );
    }
    
    /**
     * AJAX handler for content improvement suggestions
     */
    public function ajax_improve_content() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for improvement suggestions' );
        }
        
        $improvements = AceSEOApiHelper::generate_content_improvements( $post_content, $focus_keyword );
        
        if ( is_wp_error( $improvements ) ) {
            wp_send_json_error( $improvements->get_error_message() );
        }
        
        wp_send_json_success( array( 'improvements' => $improvements ) );
    }
    
    /**
     * AJAX handler for generating keyword suggestions
     */
    public function ajax_generate_keywords() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for keyword suggestions' );
        }
        
        // Set global post context for caching
        if ( $post_id ) {
            global $post;
            $post = get_post( $post_id );
        }
        
        // Debug logging
        
        $keywords = AceSEOApiHelper::generate_keyword_suggestions( $post_content, $current_title );        if ( is_wp_error( $keywords ) ) {
            wp_send_json_error( $keywords->get_error_message() );
        }
        
        wp_send_json_success( array( 'keywords' => $keywords ) );
    }
    
    /**
     * AJAX handler for generating Facebook titles
     */
    public function ajax_generate_facebook_titles() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $seo_title = sanitize_text_field( $_POST['seo_title'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
        // Use SEO title as base if available, otherwise current title
        $base_title = $seo_title ?: $current_title;
        
        $titles = AceSEOApiHelper::generate_facebook_titles( $post_content, $focus_keyword, $base_title );
        
        if ( is_wp_error( $titles ) ) {
            wp_send_json_error( $titles->get_error_message() );
        }
        
        wp_send_json_success( array( 'titles' => $titles ) );
    }
    
    /**
     * AJAX handler for generating Facebook descriptions
     */
    public function ajax_generate_facebook_descriptions() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $meta_description = sanitize_text_field( $_POST['meta_description'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
        $descriptions = AceSEOApiHelper::generate_facebook_descriptions( $post_content, $focus_keyword, $current_title, $meta_description );
        
        if ( is_wp_error( $descriptions ) ) {
            wp_send_json_error( $descriptions->get_error_message() );
        }
        
        wp_send_json_success( array( 'descriptions' => $descriptions ) );
    }
    
    /**
     * AJAX handler for generating Twitter titles
     */
    public function ajax_generate_twitter_titles() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $seo_title = sanitize_text_field( $_POST['seo_title'] ?? '' );
        $facebook_title = sanitize_text_field( $_POST['facebook_title'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
        // Use Facebook title as base if available, otherwise SEO title, otherwise current title
        $base_title = $facebook_title ?: $seo_title ?: $current_title;
        
        $titles = AceSEOApiHelper::generate_twitter_titles( $post_content, $focus_keyword, $base_title );
        
        if ( is_wp_error( $titles ) ) {
            wp_send_json_error( $titles->get_error_message() );
        }
        
        wp_send_json_success( array( 'titles' => $titles ) );
    }
    
    /**
     * AJAX handler for generating Twitter descriptions
     */
    public function ajax_generate_twitter_descriptions() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $meta_description = sanitize_text_field( $_POST['meta_description'] ?? '' );
        $facebook_description = sanitize_text_field( $_POST['facebook_description'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
        // Use Facebook description as base if available, otherwise meta description
        $base_description = $facebook_description ?: $meta_description;
        
        $descriptions = AceSEOApiHelper::generate_twitter_descriptions( $post_content, $focus_keyword, $current_title, $base_description );
        
        if ( is_wp_error( $descriptions ) ) {
            wp_send_json_error( $descriptions->get_error_message() );
        }
        
        wp_send_json_success( array( 'descriptions' => $descriptions ) );
    }
    
    /**
     * AJAX handler for generating Facebook image
     */
    public function ajax_generate_facebook_image() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        // Check if image generation is enabled
        if ( ! AceSEOApiHelper::is_ai_image_generation_enabled() ) {
            wp_send_json_error( 'AI image generation is not enabled' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $facebook_title = sanitize_text_field( $_POST['facebook_title'] ?? '' );
        $facebook_description = sanitize_text_field( $_POST['facebook_description'] ?? '' );
        $featured_image_url = esc_url_raw( $_POST['featured_image_url'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI image generation' );
        }
        
        $image_suggestions = AceSEOApiHelper::generate_facebook_image( 
            $post_content, 
            $focus_keyword, 
            $facebook_title ?: $current_title,
            $facebook_description,
            $featured_image_url
        );
        
        if ( is_wp_error( $image_suggestions ) ) {
            wp_send_json_error( $image_suggestions->get_error_message() );
        }
        
        wp_send_json_success( array( 'image_suggestions' => $image_suggestions ) );
    }
    
    /**
     * AJAX handler for generating Twitter image
     */
    public function ajax_generate_twitter_image() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        // Check if image generation is enabled
        if ( ! AceSEOApiHelper::is_ai_image_generation_enabled() ) {
            wp_send_json_error( 'AI image generation is not enabled' );
        }
        
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $twitter_title = sanitize_text_field( $_POST['twitter_title'] ?? '' );
        $twitter_description = sanitize_text_field( $_POST['twitter_description'] ?? '' );
        $featured_image_url = esc_url_raw( $_POST['featured_image_url'] ?? '' );
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI image generation' );
        }
        
        $image_suggestions = AceSEOApiHelper::generate_twitter_image( 
            $post_content, 
            $focus_keyword, 
            $twitter_title ?: $current_title,
            $twitter_description,
            $featured_image_url
        );
        
        if ( is_wp_error( $image_suggestions ) ) {
            wp_send_json_error( $image_suggestions->get_error_message() );
        }
        
        wp_send_json_success( array( 'image_suggestions' => $image_suggestions ) );
    }
    
    /**
     * AJAX handler for regenerating a single image with custom prompt
     */
    public function ajax_regenerate_image() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        // Check if image generation is enabled
        if ( ! AceSEOApiHelper::is_ai_image_generation_enabled() ) {
            wp_send_json_error( 'AI image generation is not enabled' );
        }
        
        $custom_prompt = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $platform = sanitize_text_field( $_POST['platform'] ?? 'facebook' );
        
        if ( empty( $custom_prompt ) ) {
            wp_send_json_error( 'Image prompt is required' );
        }
        
        // Determine image size based on platform
        $size = ( $platform === 'twitter' ) ? '1024x1024' : '1792x1024';
        
        $image_url = AceSEOApiHelper::generate_dalle_image( $custom_prompt, $size );
        
        if ( is_wp_error( $image_url ) ) {
            wp_send_json_error( $image_url->get_error_message() );
        }
        
        wp_send_json_success( array( 'image_url' => $image_url ) );
    }
    
    /**
     * AJAX handler for generating additional images
     */
    public function ajax_generate_more_images() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        // Check if image generation is enabled
        if ( ! AceSEOApiHelper::is_ai_image_generation_enabled() ) {
            wp_send_json_error( 'AI image generation is not enabled' );
        }
        
        $platform = sanitize_text_field( $_POST['platform'] ?? 'facebook' );
        $custom_prompt = sanitize_textarea_field( $_POST['custom_prompt'] ?? '' );
        $post_content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $focus_keyword = sanitize_text_field( $_POST['focus_keyword'] ?? '' );
        $current_title = sanitize_text_field( $_POST['current_title'] ?? '' );
        $featured_image_url = esc_url_raw( $_POST['featured_image_url'] ?? '' );
        
        if ( ! empty( $custom_prompt ) ) {
            // Generate from custom prompt
            $size = ( $platform === 'twitter' ) ? '1024x1024' : '1792x1024';
            $image_url = AceSEOApiHelper::generate_dalle_image( $custom_prompt, $size );
            
            if ( is_wp_error( $image_url ) ) {
                wp_send_json_error( $image_url->get_error_message() );
            }
            
            wp_send_json_success( array( 
                'image_suggestions' => array( array(
                    'concept' => 'Custom Generated Image',
                    'text_overlay' => 'Custom prompt',
                    'colors' => 'Varies',
                    'reason' => 'Generated from your custom prompt',
                    'image_prompt' => $custom_prompt,
                    'generated_image' => $image_url
                ) )
            ) );
        } else {
            // Generate additional AI concepts
            if ( empty( $post_content ) ) {
                wp_send_json_error( 'Content is required for AI image generation' );
            }
            
            if ( $platform === 'facebook' ) {
                $facebook_title = sanitize_text_field( $_POST['facebook_title'] ?? '' );
                $facebook_description = sanitize_text_field( $_POST['facebook_description'] ?? '' );
                
                $image_suggestions = AceSEOApiHelper::generate_facebook_image( 
                    $post_content, 
                    $focus_keyword, 
                    $facebook_title ?: $current_title,
                    $facebook_description,
                    $featured_image_url
                );
            } else {
                $twitter_title = sanitize_text_field( $_POST['twitter_title'] ?? '' );
                $twitter_description = sanitize_text_field( $_POST['twitter_description'] ?? '' );
                
                $image_suggestions = AceSEOApiHelper::generate_twitter_image( 
                    $post_content, 
                    $focus_keyword, 
                    $twitter_title ?: $current_title,
                    $twitter_description,
                    $featured_image_url
                );
            }
            
            if ( is_wp_error( $image_suggestions ) ) {
                wp_send_json_error( $image_suggestions->get_error_message() );
            }
            
            wp_send_json_success( array( 'image_suggestions' => $image_suggestions ) );
        }
    }
    
    /**
     * AJAX handler for saving image to media library
     */
    public function ajax_save_image_to_library() {
        check_ajax_referer( 'ace_seo_ai_nonce', 'nonce' );
        
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Insufficient permissions to upload files' );
        }
        
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        
        if ( empty( $image_url ) ) {
            wp_send_json_error( 'Image URL is required' );
        }
        
        if ( ! $filename ) {
            $filename = 'ai-generated-image-' . time() . '.png';
        }
        
        // Download the image
        $response = wp_remote_get( $image_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url()
            )
        ) );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Failed to download image: ' . $response->get_error_message() );
        }
        
        $image_data = wp_remote_retrieve_body( $response );
        if ( empty( $image_data ) ) {
            wp_send_json_error( 'Downloaded image is empty' );
        }
        
        // Save to uploads directory
        $upload = wp_upload_bits( $filename, null, $image_data );
        
        if ( $upload['error'] ) {
            wp_send_json_error( 'Failed to save image: ' . $upload['error'] );
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => 'image/png',
            'post_title'     => 'AI Generated Image',
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        
        $attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
        
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( 'Failed to create attachment: ' . $attach_id->get_error_message() );
        }
        
        // Generate attachment metadata
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
        wp_update_attachment_metadata( $attach_id, $attach_data );
        
        wp_send_json_success( array( 
            'attachment_id' => $attach_id,
            'url' => $upload['url']
        ) );
    }
    
    /**
     * Get AI assistance status for current user
     */
    public static function is_ai_available() {
        return class_exists( 'AceSEOApiHelper' ) && AceSEOApiHelper::is_ai_enabled();
    }
    
    /**
     * Render AI assistant buttons for metabox
     */
    public static function render_ai_buttons( $field_type = 'title' ) {
        if ( ! self::is_ai_available() ) {
            return '';
        }
        
        $button_configs = array(
            'title' => array(
                'action' => 'generate_titles',
                'text' => 'AI Titles',
                'icon' => 'lightbulb',
                'tooltip' => 'Generate AI-powered SEO titles'
            ),
            'description' => array(
                'action' => 'generate_descriptions',
                'text' => 'AI Descriptions',
                'icon' => 'edit',
                'tooltip' => 'Generate compelling meta descriptions'
            ),
            'keyword' => array(
                'action' => 'generate_keywords',
                'text' => 'AI Keywords',
                'icon' => 'search',
                'tooltip' => 'Suggest focus keywords using AI'
            ),
            'facebook_title' => array(
                'action' => 'generate_facebook_titles',
                'text' => 'AI Titles',
                'icon' => 'facebook',
                'tooltip' => 'Generate AI-powered Facebook titles'
            ),
            'facebook_description' => array(
                'action' => 'generate_facebook_descriptions',
                'text' => 'AI Descriptions',
                'icon' => 'facebook-alt',
                'tooltip' => 'Generate compelling Facebook descriptions'
            ),
            'twitter_title' => array(
                'action' => 'generate_twitter_titles',
                'text' => 'AI Titles',
                'icon' => 'twitter',
                'tooltip' => 'Generate AI-powered Twitter titles'
            ),
            'twitter_description' => array(
                'action' => 'generate_twitter_descriptions',
                'text' => 'AI Descriptions',
                'icon' => 'twitter-alt',
                'tooltip' => 'Generate compelling Twitter descriptions'
            ),
            'facebook_image' => array(
                'action' => 'generate_facebook_image',
                'text' => 'AI Image',
                'icon' => 'facebook-image',
                'tooltip' => 'Generate AI-powered Facebook image suggestions',
                'requires_image_generation' => true
            ),
            'twitter_image' => array(
                'action' => 'generate_twitter_image',
                'text' => 'AI Image',
                'icon' => 'twitter-image',
                'tooltip' => 'Generate AI-powered Twitter image suggestions',
                'requires_image_generation' => true
            ),
            'analysis' => array(
                'action' => 'analyze_content',
                'text' => 'AI Analysis',
                'icon' => 'analytics',
                'tooltip' => 'Get detailed AI content analysis'
            ),
            'improve' => array(
                'action' => 'improve_content',
                'text' => 'AI Improve',
                'icon' => 'upload',
                'tooltip' => 'Get AI improvement suggestions'
            ),
            'topics' => array(
                'action' => 'suggest_topics',
                'text' => 'AI Topics',
                'icon' => 'list-view',
                'tooltip' => 'Generate related topics and questions'
            )
        );
        
        if ( ! isset( $button_configs[ $field_type ] ) ) {
            return '';
        }
        
        $config = $button_configs[ $field_type ];
        
        // Check if image generation is required and enabled
        if ( isset( $config['requires_image_generation'] ) && $config['requires_image_generation'] ) {
            if ( ! AceSEOApiHelper::is_ai_image_generation_enabled() ) {
                return '';
            }
        }
        
        return sprintf(
            '<button type="button" class="ace-ai-button" data-action="%s" title="%s">
                <span class="dashicons dashicons-%s"></span>
                <span class="ace-ai-button-text">%s</span>
                <span class="ace-ai-loading" style="display: none;">
                    <span class="ace-seo-spinner"></span>
                </span>
            </button>',
            esc_attr( $config['action'] ),
            esc_attr( $config['tooltip'] ),
            esc_attr( $config['icon'] ),
            esc_html( $config['text'] )
        );
    }
    
    /**
     * Render AI suggestions modal
     */
    public static function render_suggestions_modal() {
        if ( ! self::is_ai_available() ) {
            return;
        }
        
        ?>
        <div id="ace-ai-suggestions-modal" class="ace-modal" style="display: none;">
            <div class="ace-modal-overlay"></div>
            <div class="ace-modal-content">
                <div class="ace-modal-header">
                    <h3 id="ace-ai-modal-title">AI Suggestions</h3>
                    <button type="button" class="ace-modal-close">&times;</button>
                </div>
                <div class="ace-modal-body">
                    <div id="ace-ai-suggestions-content">
                        <div class="ace-ai-loading-state">
                            <div class="ace-seo-spinner"></div>
                            <p>Generating AI suggestions...</p>
                        </div>
                    </div>
                </div>
                <div class="ace-modal-footer">
                    <button type="button" class="button ace-modal-close">Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize the AI assistant
new AceSEOAiAssistant();
