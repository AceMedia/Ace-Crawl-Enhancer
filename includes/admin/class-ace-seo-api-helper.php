<?php
/**
 * Ace SEO API Helper Class
 * Handles API validation and testing for OpenAI and PageSpeed APIs
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOApiHelper {
    
    /**
     * Initialize API helper functionality
     */
    public function __construct() {
        add_action( 'wp_ajax_ace_seo_test_api', array( $this, 'test_api_connection' ) );
        add_action( 'wp_ajax_ace_seo_validate_openai', array( $this, 'validate_openai_key' ) );
        add_action( 'wp_ajax_ace_seo_validate_pagespeed', array( $this, 'validate_pagespeed_key' ) );
    }
    
    /**
     * Test API connection via AJAX
     */
    public function test_api_connection() {
        check_ajax_referer( 'ace_seo_api_test', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        $api_type = sanitize_text_field( $_POST['api_type'] );
        $api_key = sanitize_text_field( $_POST['api_key'] );
        
        switch ( $api_type ) {
            case 'openai':
                $result = $this->test_openai_connection( $api_key );
                break;
            case 'pagespeed':
                $result = $this->test_pagespeed_connection( $api_key );
                break;
            default:
                $result = array( 'success' => false, 'message' => 'Invalid API type' );
        }
        
        wp_send_json( $result );
    }
    
    /**
     * Validate OpenAI API key
     */
    public function validate_openai_key() {
        check_ajax_referer( 'ace_seo_api_test', 'nonce' );
        
        $api_key = sanitize_text_field( $_POST['api_key'] );
        $result = $this->test_openai_connection( $api_key );
        
        wp_send_json( $result );
    }
    
    /**
     * Validate PageSpeed API key
     */
    public function validate_pagespeed_key() {
        check_ajax_referer( 'ace_seo_api_test', 'nonce' );
        
        $api_key = sanitize_text_field( $_POST['api_key'] );
        $result = $this->test_pagespeed_connection( $api_key );
        
        wp_send_json( $result );
    }
    
    /**
     * Test OpenAI API connection
     */
    private function test_openai_connection( $api_key ) {
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'message' => 'API key is required' );
        }
        
        if ( ! $this->is_valid_openai_key_format( $api_key ) ) {
            return array( 'success' => false, 'message' => 'Invalid API key format. OpenAI keys should start with "sk-"' );
        }
        
        $response = wp_remote_get( 'https://api.openai.com/v1/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 10,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return array( 
                'success' => false, 
                'message' => 'Connection failed: ' . $response->get_error_message() 
            );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        
        if ( $response_code === 200 ) {
            return array( 'success' => true, 'message' => 'OpenAI API connection successful' );
        } elseif ( $response_code === 401 ) {
            return array( 'success' => false, 'message' => 'Invalid API key' );
        } else {
            return array( 'success' => false, 'message' => 'API connection failed with code: ' . $response_code );
        }
    }
    
    /**
     * Test PageSpeed API connection
     */
    private function test_pagespeed_connection( $api_key ) {
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'message' => 'API key is required' );
        }
        
        $test_url = home_url();
        $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . urlencode( $test_url ) . '&key=' . $api_key;
        
        $response = wp_remote_get( $api_url, array(
            'timeout' => 15,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return array( 
                'success' => false, 
                'message' => 'Connection failed: ' . $response->get_error_message() 
            );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        
        if ( $response_code === 200 ) {
            return array( 'success' => true, 'message' => 'PageSpeed Insights API connection successful' );
        } elseif ( $response_code === 400 ) {
            return array( 'success' => false, 'message' => 'Invalid API key or request format' );
        } elseif ( $response_code === 403 ) {
            return array( 'success' => false, 'message' => 'API key lacks necessary permissions' );
        } else {
            return array( 'success' => false, 'message' => 'API connection failed with code: ' . $response_code );
        }
    }
    
    /**
     * Check if OpenAI API key format is valid
     */
    private function is_valid_openai_key_format( $key ) {
        return preg_match( '/^sk-[a-zA-Z0-9]{48}$/', $key ) || preg_match( '/^sk-proj-[a-zA-Z0-9]{48}$/', $key );
    }
    
    /**
     * Get OpenAI API key from settings
     */
    public static function get_openai_key() {
        $options = get_option( 'ace_seo_options', array() );
        return $options['ai']['openai_api_key'] ?? '';
    }
    
    /**
     * Get PageSpeed API key from settings
     */
    public static function get_pagespeed_key() {
        $options = get_option( 'ace_seo_options', array() );
        return $options['performance']['pagespeed_api_key'] ?? '';
    }
    
    /**
     * Check if AI features are enabled
     */
    public static function is_ai_enabled() {
        $options = get_option( 'ace_seo_options', array() );
        $ai_settings = $options['ai'] ?? array();
        
        return ! empty( $ai_settings['openai_api_key'] ) && 
               ( $ai_settings['ai_content_analysis'] || 
                 $ai_settings['ai_keyword_suggestions'] || 
                 $ai_settings['ai_content_optimization'] );
    }
    
    /**
     * Check if performance monitoring is enabled
     */
    public static function is_performance_monitoring_enabled() {
        $options = get_option( 'ace_seo_options', array() );
        $performance_settings = $options['performance'] ?? array();
        
        return ! empty( $performance_settings['pagespeed_api_key'] ) && 
               $performance_settings['pagespeed_monitoring'];
    }
    
    /**
     * Make OpenAI API request
     */
    public static function make_openai_request( $prompt, $model = 'gpt-4o-mini' ) {
        $api_key = self::get_openai_key();
        
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenAI API key not configured' );
        }
        
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 1000,
                'temperature' => 0.7,
            ) ),
            'timeout' => 30,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            return $data['choices'][0]['message']['content'];
        }
        
        return new WP_Error( 'api_error', 'Invalid API response' );
    }
    
    /**
     * Make PageSpeed Insights API request
     */
    public static function make_pagespeed_request( $url, $strategy = 'mobile' ) {
        $api_key = self::get_pagespeed_key();
        
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'PageSpeed Insights API key not configured' );
        }
        
        $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query( array(
            'url' => $url,
            'key' => $api_key,
            'strategy' => $strategy,
            'category' => 'performance,accessibility,best-practices,seo',
        ) );
        
        $response = wp_remote_get( $api_url, array(
            'timeout' => 30,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body( $response );
        return json_decode( $body, true );
    }
}

// Initialize the API helper
new AceSEOApiHelper();
