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
        
        // Clear AI cache when posts are updated
        add_action( 'save_post', array( __CLASS__, 'clear_post_ai_cache' ) );
        add_action( 'wp_insert_post', array( __CLASS__, 'clear_post_ai_cache' ) );
        add_action( 'before_delete_post', array( __CLASS__, 'clear_post_ai_cache' ) );
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
        // Support both legacy (sk-) and new project-based (sk-proj-) key formats
        return preg_match( '/^sk-[a-zA-Z0-9_-]{40,}$/', $key ) || 
               preg_match( '/^sk-proj-[a-zA-Z0-9_-]{40,}$/', $key );
    }
    
    /**
     * Get OpenAI API key from settings
     */
    public static function get_openai_key() {
        $options = get_option( 'ace_seo_options', array() );
        return $options['ai']['openai_api_key'] ?? '';
    }
    
    /**
     * Test basic OpenAI connection with a simple prompt
     */
    public static function test_basic_openai_connection() {
        $api_key = self::get_openai_key();
        
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenAI API key not configured' );
        }
        
        // Simple test prompt
        $test_prompt = "Say 'Hello, this is a test' in exactly those words.";
        
        $result = self::make_openai_request( $test_prompt, 'gpt-3.5-turbo', false );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        return 'OpenAI API connection successful. Response: ' . substr( $result, 0, 100 );
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
     * Check if AI web search is enabled
     */
    public static function is_ai_web_search_enabled() {
        $options = get_option( 'ace_seo_options', array() );
        $ai_settings = $options['ai'] ?? array();
        
        return ! empty( $ai_settings['openai_api_key'] ) && 
               ( $ai_settings['ai_web_search'] ?? 0 );
    }
    
    /**
     * Check if AI image generation is enabled
     */
    public static function is_ai_image_generation_enabled() {
        $options = get_option( 'ace_seo_options', array() );
        $ai_settings = $options['ai'] ?? array();
        
        return ! empty( $ai_settings['openai_api_key'] ) && 
               ( $ai_settings['ai_image_generation'] ?? 0 );
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
     * Make OpenAI API request with model fallback
     */
    public static function make_openai_request( $prompt, $model = 'gpt-3.5-turbo', $enable_web_search = false ) {
        $api_key = self::get_openai_key();
        
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenAI API key not configured' );
        }
        
        // Try primary model first, then fallback models
        $models_to_try = array( $model );
        if ( $model !== 'gpt-3.5-turbo' ) {
            $models_to_try[] = 'gpt-3.5-turbo';
        }
        if ( ! in_array( 'gpt-4', $models_to_try ) ) {
            $models_to_try[] = 'gpt-4';
        }
        
        $last_error = null;
        
        foreach ( $models_to_try as $current_model ) {
            $request_body = array(
                'model' => $current_model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $enable_web_search ? "Use your knowledge to provide current and relevant information. " . $prompt : $prompt
                    )
                ),
                'max_tokens' => 1000,
                'temperature' => 0.7,
            );
            
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode( $request_body ),
                'timeout' => 45,
            ) );
            
            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                continue; // Try next model
            }
            
            $response_code = wp_remote_retrieve_response_code( $response );
            
            if ( $response_code !== 200 ) {
                $body = wp_remote_retrieve_body( $response );
                $error_data = json_decode( $body, true );
                
                // If it's a model not found error, try the next model
                if ( isset( $error_data['error']['type'] ) && $error_data['error']['type'] === 'model_not_found' ) {
                    continue;
                }
                
                // Get detailed error information
                if ( isset( $error_data['error']['message'] ) ) {
                    $last_error = new WP_Error( 'api_error', 'OpenAI API error: ' . $error_data['error']['message'] );
                } else {
                    $last_error = new WP_Error( 'api_error', 'OpenAI API returned HTTP ' . $response_code );
                }
                continue; // Try next model
            }
            
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            
            // Check for API errors
            if ( isset( $data['error'] ) ) {
                $error = $data['error'];
                $error_type = $error['type'] ?? 'unknown';
                $error_message = $error['message'] ?? 'OpenAI API error';
                
                // If it's a model not found error, try the next model
                if ( $error_type === 'model_not_found' ) {
                    continue;
                }
                
                // Provide user-friendly error messages
                switch ( $error_type ) {
                    case 'insufficient_quota':
                        $friendly_message = 'OpenAI quota exceeded. Please add billing details at https://platform.openai.com/settings/organization/billing';
                        break;
                    case 'invalid_api_key':
                        $friendly_message = 'Invalid OpenAI API key. Please check your key in the settings.';
                        break;
                    case 'rate_limit_exceeded':
                        $friendly_message = 'Too many requests. Please wait a moment and try again.';
                        break;
                    default:
                        $friendly_message = 'OpenAI API error: ' . $error_message;
                }
                
                $last_error = new WP_Error( 'api_error', $friendly_message );
                continue; // Try next model
            }
            
            if ( isset( $data['choices'][0]['message']['content'] ) ) {
                return $data['choices'][0]['message']['content'];
            }
            
            // If we got here, the response was successful but malformed
            $last_error = new WP_Error( 'api_error', 'Invalid API response format' );
        }
        
        // If we tried all models and none worked, return the last error
        if ( $last_error ) {
            return $last_error;
        }
        
        return new WP_Error( 'api_error', 'All AI models failed to respond' );
    }
    
    /**
     * Generate image using DALL-E API
     */
    public static function generate_dalle_image( $prompt, $size = '1024x1024', $quality = 'standard' ) {
        if ( ! self::is_ai_image_generation_enabled() ) {
            return new WP_Error( 'ai_image_disabled', 'AI image generation is not enabled' );
        }
        $api_key = self::get_openai_key();
        
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenAI API key not configured' );
        }
        
        $request_body = array(
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => $quality,
            'response_format' => 'url'
        );
        
        $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $request_body ),
            'timeout' => 60, // Image generation can take longer
        ) );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        
        if ( $response_code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $error_data = json_decode( $body, true );
            
            if ( isset( $error_data['error']['message'] ) ) {
                return new WP_Error( 'api_error', 'DALL-E API error: ' . $error_data['error']['message'] );
            } else {
                return new WP_Error( 'api_error', 'DALL-E API returned HTTP ' . $response_code );
            }
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( isset( $data['data'][0]['url'] ) ) {
            return $data['data'][0]['url'];
        }
        
        return new WP_Error( 'api_error', 'Invalid DALL-E API response format' );
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
    
    /**
     * Get AI suggestion cache key for post meta
     */
    private static function get_post_cache_key( $type, $post_content, $focus_keyword = '', $additional_data = '' ) {
        $content_hash = md5( $post_content . $focus_keyword . $additional_data );
        return "ace_ai_cache_{$type}_{$content_hash}";
    }
    
    /**
     * Get post revision timestamp for cache invalidation
     */
    private static function get_post_revision_time( $post_id = null ) {
        if ( ! $post_id ) {
            global $post;
            if ( ! $post ) {
                return time(); // Fallback to current time
            }
            $post_id = $post->ID;
        }
        
        // Get the latest revision time, or post modified time if no revisions
        $revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
        if ( $revisions ) {
            $latest_revision = array_shift( $revisions );
            return strtotime( $latest_revision->post_modified );
        }
        
        $post = get_post( $post_id );
        if ( $post ) {
            return strtotime( $post->post_modified );
        }
        
        return time();
    }
    
    /**
     * Get cached AI suggestions from post meta if valid
     */
    private static function get_cached_post_suggestions( $post_id, $cache_key ) {
        if ( ! $post_id ) {
            global $post;
            if ( ! $post ) {
                return false;
            }
            $post_id = $post->ID;
        }
        
        $cached_data = get_post_meta( $post_id, $cache_key, true );
        
        if ( ! $cached_data || ! is_array( $cached_data ) ) {
            return false;
        }
        
        // Check if cache is still valid based on post revision time
        $cache_time = $cached_data['timestamp'] ?? 0;
        $post_revision_time = self::get_post_revision_time( $post_id );
        
        // Cache is valid if it was created after the last post modification
        if ( $cache_time < $post_revision_time ) {
            delete_post_meta( $post_id, $cache_key );
            return false;
        }
        
        return $cached_data['suggestions'] ?? false;
    }
    
    /**
     * Cache AI suggestions in post meta
     */
    private static function cache_post_suggestions( $post_id, $cache_key, $suggestions ) {
        if ( ! $post_id ) {
            global $post;
            if ( ! $post ) {
                return false;
            }
            $post_id = $post->ID;
        }
        
        $cache_data = array(
            'suggestions' => $suggestions,
            'timestamp' => time()
        );
        
        return update_post_meta( $post_id, $cache_key, $cache_data );
    }
    
    /**
     * Clear all AI suggestion caches for a specific post
     */
    public static function clear_post_ai_cache( $post_id ) {
        if ( ! $post_id ) {
            return false;
        }
        
        global $wpdb;
        
        // Delete all post meta that starts with ace_ai_cache_
        $wpdb->query( $wpdb->prepare( 
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", 
            $post_id,
            'ace_ai_cache_%'
        ) );
        
        return true;
    }
    
    /**
     * Clear all AI suggestion caches (useful for cache management)
     */
    public static function clear_all_ai_cache() {
        global $wpdb;
        
        // Delete all post meta that starts with ace_ai_cache_
        $wpdb->query( $wpdb->prepare( 
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", 
            'ace_ai_cache_%'
        ) );
        
        // Also clear old transient-based caches for backward compatibility
        $cache_prefix = 'ace_ai_';
        $wpdb->query( $wpdb->prepare( 
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 
            '_transient_' . $cache_prefix . '%'
        ) );
        $wpdb->query( $wpdb->prepare( 
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 
            '_transient_timeout_' . $cache_prefix . '%'
        ) );
    }

    /**
     * Generate AI-powered SEO title suggestions
     */
    public static function generate_seo_titles( $post_content, $focus_keyword = '', $post_title = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        // Get current post ID
        global $post;
        $post_id = $post ? $post->ID : ( $_POST['post_id'] ?? 0 );
        
        // Check cache first
        $cache_key = self::get_post_cache_key( 'titles', $post_content, $focus_keyword, $post_title );
        $cached_titles = self::get_cached_post_suggestions( $post_id, $cache_key );
        if ( $cached_titles !== false ) {
            return $cached_titles;
        }
        
        // Enable web search if setting is enabled and focus keyword is provided
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        
        $search_context = '';
        if ( $enable_search ) {
            $search_context = " First, search for current trends and popular content related to '" . $focus_keyword . "' to understand what's working in the market right now.";
        }
        
        $prompt = "I need you to generate 5 compelling SEO titles for this content." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $post_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        if ( $enable_search ) {
            $prompt .= "Based on current search trends and best practices, create titles that:\n";
        } else {
            $prompt .= "Create titles that:\n";
        }
        $prompt .= "- Include the focus keyword naturally if provided\n";
        $prompt .= "- Keep titles under 60 characters for optimal SEO\n";
        if ( $enable_search ) {
            $prompt .= "- Are compelling and click-worthy based on current market trends\n";
        } else {
            $prompt .= "- Are compelling and click-worthy\n";
        }
        $prompt .= "- Vary the style (direct, question, benefit-focused, etc.)\n";
        if ( $enable_search ) {
            $prompt .= "- Consider what's currently popular and trending in this topic area\n\n";
        } else {
            $prompt .= "\n";
        }
        
        $prompt .= "Respond with ONLY valid JSON in this exact format (no markdown, no code blocks):\n";
        $prompt .= '{"titles":[{"title":"Best title here","reason":"Brief explanation"},{"title":"Second title","reason":"Brief explanation"},{"title":"Third title","reason":"Brief explanation"},{"title":"Fourth title","reason":"Brief explanation"},{"title":"Fifth title","reason":"Brief explanation"}]}' . "\n\n";
        if ( $enable_search ) {
            $prompt .= "Put the BEST title first based on current trends. Keep reasons to one short sentence. Return ONLY the JSON, nothing else.";
        } else {
            $prompt .= "Put the BEST title first. Keep reasons to one short sentence. Return ONLY the JSON, nothing else.";
        }
        
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        // Clean the response - remove any markdown formatting
        $response = trim( $response );
        // Find the JSON object - look for first { and last }
        $start = strpos( $response, '{' );
        $end = strrpos( $response, '}' );
        if ( $start !== false && $end !== false && $end > $start ) {
            $response = substr( $response, $start, $end - $start + 1 );
        }
        $response = trim( $response );
        
        // Parse JSON response
        $data = json_decode( $response, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['titles'] ) ) {
            // Fallback: try to extract titles from malformed response
            $fallback_titles = self::extract_titles_fallback( $response );
            if ( ! empty( $fallback_titles ) ) {
                return $fallback_titles;
            }
            
            // Final fallback: generate simple titles
            return array(
                array( 'title' => $post_title ?: 'Untitled Post', 'reason' => 'Current title' ),
                array( 'title' => 'Amazing ' . $focus_keyword . ' Guide', 'reason' => 'Keyword focused' ),
                array( 'title' => 'Best ' . $focus_keyword . ' Tips', 'reason' => 'Benefit focused' ),
                array( 'title' => 'Ultimate ' . $focus_keyword . ' Resource', 'reason' => 'Authority focused' ),
                array( 'title' => $focus_keyword . ' Explained Simply', 'reason' => 'Educational focus' )
            );
        }
        
        // Ensure we have valid title structures
        $valid_titles = array();
        foreach ( $data['titles'] as $title_data ) {
            if ( isset( $title_data['title'] ) && isset( $title_data['reason'] ) ) {
                $valid_titles[] = array(
                    'title' => $title_data['title'],
                    'reason' => $title_data['reason']
                );
            }
        }
        
        // Cache the results in post meta
        if ( $post_id ) {
            self::cache_post_suggestions( $post_id, $cache_key, $valid_titles );
        }
        
        return $valid_titles;
    }
    
    /**
     * Extract titles from malformed AI response
     */
    private static function extract_titles_fallback( $response ) {
        $titles = array();
        
        // Try to find title patterns in the response
        if ( preg_match_all( '/"title":\s*"([^"]+)".*?"reason":\s*"([^"]+)"/i', $response, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $titles[] = array(
                    'title' => $match[1],
                    'reason' => $match[2]
                );
            }
        }
        
        return array_slice( $titles, 0, 5 );
    }
    
    /**
     * Generate AI-powered meta description suggestions
     */
    public static function generate_meta_descriptions( $post_content, $focus_keyword = '', $post_title = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        // Get current post ID
        global $post;
        $post_id = $post ? $post->ID : ( $_POST['post_id'] ?? 0 );
        
        // Check cache first
        $cache_key = self::get_post_cache_key( 'descriptions', $post_content, $focus_keyword, $post_title );
        $cached_descriptions = self::get_cached_post_suggestions( $post_id, $cache_key );
        if ( $cached_descriptions !== false ) {
            return $cached_descriptions;
        }
        
        // Enable web search if setting is enabled and focus keyword is provided
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        
        $search_context = '';
        if ( $enable_search ) {
            $search_context = " First, search for current meta description trends and effective examples for '" . $focus_keyword . "' to understand what descriptions are getting good click-through rates.";
        }
        
        $prompt = "I need you to generate 3 compelling meta descriptions for this content." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $post_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        if ( $enable_search ) {
            $prompt .= "Based on current trends and high-performing meta descriptions, create descriptions that:\n";
        } else {
            $prompt .= "Create descriptions that:\n";
        }
        $prompt .= "- Include the focus keyword naturally if provided\n";
        $prompt .= "- Keep between 150-160 characters for optimal SEO\n";
        if ( $enable_search ) {
            $prompt .= "- Are compelling and action-oriented based on current market trends\n";
        } else {
            $prompt .= "- Are compelling and action-oriented\n";
        }
        $prompt .= "- Vary the approach (benefit, question, direct, etc.)\n";
        if ( $enable_search ) {
            $prompt .= "- Consider what meta descriptions are currently working well in this topic area\n\n";
        } else {
            $prompt .= "\n";
        }
        
        $prompt .= "Respond with ONLY valid JSON in this exact format (no markdown, no code blocks):\n";
        $prompt .= '{"descriptions":[{"description":"Best meta description here","reason":"Brief explanation"},{"description":"Second description","reason":"Brief explanation"},{"description":"Third description","reason":"Brief explanation"}]}' . "\n\n";
        if ( $enable_search ) {
            $prompt .= "Put the BEST description first based on current trends. Keep reasons to one short sentence. Return ONLY the JSON, nothing else.";
        } else {
            $prompt .= "Put the BEST description first. Keep reasons to one short sentence. Return ONLY the JSON, nothing else.";
        }
        
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        // Clean the response - remove any markdown formatting
        $response = trim( $response );
        // Find the JSON object - look for first { and last }
        $start = strpos( $response, '{' );
        $end = strrpos( $response, '}' );
        if ( $start !== false && $end !== false && $end > $start ) {
            $response = substr( $response, $start, $end - $start + 1 );
        }
        $response = trim( $response );
        
        // Parse JSON response
        $data = json_decode( $response, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['descriptions'] ) ) {
            // Fallback: try to extract descriptions from malformed response
            $fallback_descriptions = self::extract_descriptions_fallback( $response );
            if ( ! empty( $fallback_descriptions ) ) {
                return $fallback_descriptions;
            }
            
            // Final fallback: generate simple descriptions
            $base_desc = wp_trim_words( strip_tags( $post_content ), 20 );
            return array(
                array( 'description' => $base_desc . ' Learn more about ' . $focus_keyword . '.', 'reason' => 'Content based' ),
                array( 'description' => 'Discover everything about ' . $focus_keyword . ' in this comprehensive guide.', 'reason' => 'Discovery focused' ),
                array( 'description' => 'Get expert insights on ' . $focus_keyword . ' and improve your knowledge today.', 'reason' => 'Expert focused' )
            );
        }
        
        // Ensure we have valid description structures
        $valid_descriptions = array();
        foreach ( $data['descriptions'] as $desc_data ) {
            if ( isset( $desc_data['description'] ) && isset( $desc_data['reason'] ) ) {
                $valid_descriptions[] = array(
                    'description' => $desc_data['description'],
                    'reason' => $desc_data['reason']
                );
            }
        }
        
        // Cache the results in post meta
        if ( $post_id ) {
            self::cache_post_suggestions( $post_id, $cache_key, $valid_descriptions );
        }
        
        return $valid_descriptions;
    }
    
    /**
     * Extract descriptions from malformed AI response
     */
    private static function extract_descriptions_fallback( $response ) {
        $descriptions = array();
        
        // Try to find description patterns in the response
        if ( preg_match_all( '/"description":\s*"([^"]+)".*?"reason":\s*"([^"]+)"/i', $response, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $descriptions[] = array(
                    'description' => $match[1],
                    'reason' => $match[2]
                );
            }
        }
        
        return array_slice( $descriptions, 0, 3 );
    }
    
    /**
     * Analyze content with AI for detailed suggestions
     */
    public static function analyze_content_with_ai( $post_content, $focus_keyword = '', $post_title = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        // Enable web search if setting is enabled and focus keyword is provided
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        
        $search_context = '';
        if ( $enable_search ) {
            $search_context = " First, search for current SEO best practices and high-performing content for '" . $focus_keyword . "' to understand what strategies are working now.";
        }
        
        $prompt = "I need you to analyze this content for SEO and readability improvements." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $post_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 500 ) . "\n\n";
        
        if ( $enable_search ) {
            $prompt .= "Based on current SEO trends and best practices, provide analysis in these categories with specific actionable suggestions:\n";
            $prompt .= "1. SEO_ETHICS: Analyze if the content follows ethical SEO practices. Rate as Black Hat (manipulative/deceptive), Gray Hat (borderline/aggressive), or White Hat (natural/user-focused). Explain why.\n";
            $prompt .= "2. KEYWORD_OPTIMIZATION: How well is the focus keyword used and where to improve based on current trends?\n";
            $prompt .= "3. CONTENT_STRUCTURE: How is the content organized and what structure improvements are needed for current SEO standards?\n";
            $prompt .= "4. READABILITY: How easy is it to read and specific ways to improve readability for modern audiences?\n";
            $prompt .= "5. SEO_IMPROVEMENTS: What specific SEO improvements are needed based on current algorithm preferences?\n";
            $prompt .= "6. ENGAGEMENT: How engaging is the content and specific ways to improve engagement using current best practices?\n\n";
        } else {
            $prompt .= "Provide analysis in these categories with specific actionable suggestions:\n";
            $prompt .= "1. SEO_ETHICS: Analyze if the content follows ethical SEO practices. Rate as Black Hat (manipulative/deceptive), Gray Hat (borderline/aggressive), or White Hat (natural/user-focused). Explain why.\n";
            $prompt .= "2. KEYWORD_OPTIMIZATION: How well is the focus keyword used and where to improve?\n";
            $prompt .= "3. CONTENT_STRUCTURE: How is the content organized and what structure improvements are needed?\n";
            $prompt .= "4. READABILITY: How easy is it to read and specific ways to improve readability?\n";
            $prompt .= "5. SEO_IMPROVEMENTS: What specific SEO improvements are needed?\n";
            $prompt .= "6. ENGAGEMENT: How engaging is the content and specific ways to improve engagement?\n\n";
        }
        
        $prompt .= "Respond with clear, actionable suggestions for each category. Do NOT use JSON format. Use this format:\n\n";
        $prompt .= "SEO_ETHICS:\n- Your ethical assessment (Black Hat/Gray Hat/White Hat) with explanation\n\n";
        $prompt .= "KEYWORD_OPTIMIZATION:\n- Suggestion 1\n- Suggestion 2\n\n";
        $prompt .= "CONTENT_STRUCTURE:\n- Suggestion 1\n- Suggestion 2\n\n";
        if ( $enable_search ) {
            $prompt .= "And so on for each category. Keep suggestions specific and actionable based on current market insights.";
        } else {
            $prompt .= "And so on for each category. Keep suggestions specific and actionable.";
        }
        
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        // Parse the structured text response
        $analysis = self::parse_ai_analysis_text( $response );
        
        return $analysis;
    }
    
    /**
     * Generate topic suggestions based on content
     */
    public static function generate_topic_suggestions( $post_content, $focus_keyword = '', $post_title = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for trending topics, questions, and content gaps related to '" . $focus_keyword . "' to identify current opportunities.";
        }
        
        $prompt = "Based on this content, suggest related topics and questions." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $post_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        $prompt .= "Based on current search trends and market demand, generate:\n";
        $prompt .= "1. 5 related topic ideas for future content that are currently trending\n";
        $prompt .= "2. 5 'People Also Ask' style questions that are frequently searched\n";
        $prompt .= "3. 3 long-tail keyword variations with good search potential\n\n";
        
        $prompt .= "Format as:\n";
        $prompt .= "TOPICS:\n- [topic 1]\n- [topic 2]...\n\n";
        $prompt .= "QUESTIONS:\n- [question 1]\n- [question 2]...\n\n";
        $prompt .= "KEYWORDS:\n- [keyword 1]\n- [keyword 2]...";
        
        // Enable web search if setting is enabled and focus keyword is provided
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return self::parse_topic_suggestions( $response );
    }
    
    /**
     * Generate content improvement suggestions
     */
    public static function generate_content_improvements( $post_content, $focus_keyword = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for high-performing content and optimization strategies for '" . $focus_keyword . "' to understand what improvements work best.";
        }
        
        $prompt = "Analyze this article content and provide specific writing and content SEO improvement suggestions." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 400 ) . "\n\n";
        
        $prompt .= "Provide 8-10 specific, actionable suggestions for improving this article's content and writing. Focus ONLY on:\n";
        $prompt .= "- Natural keyword placement and usage within the article text\n";
        $prompt .= "- Content structure improvements (headings, paragraphs, flow)\n";
        $prompt .= "- Writing style and readability enhancements\n";
        $prompt .= "- Additional content topics or sections to add to the article\n";
        $prompt .= "- Improving user engagement through better writing\n";
        $prompt .= "- Content gaps that should be filled\n";
        $prompt .= "- Better introduction and conclusion suggestions\n\n";
        
        $prompt .= "DO NOT suggest technical implementations like:\n";
        $prompt .= "- Schema markup or structured data\n";
        $prompt .= "- Meta tags or HTML code\n";
        $prompt .= "- Server configurations or monitoring\n";
        $prompt .= "- External tools or plugins\n";
        $prompt .= "- Analytics or tracking setup\n\n";
        
        $prompt .= "Focus only on what can be improved in the actual article content and writing style.\n";
        $prompt .= "Return as a numbered list with clear, actionable content writing suggestions. For example:\n";
        $prompt .= "1. Add the focus keyword naturally in the first paragraph\n";
        $prompt .= "2. Break up the third paragraph into two shorter ones for better readability\n";
        $prompt .= "3. Add a section about [specific topic] to provide more comprehensive coverage\n";
        $prompt .= "And so on...";
        
        // Enable web search if setting is enabled and focus keyword is provided
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        // Parse numbered suggestions
        $lines = explode( "\n", $response );
        $suggestions = [];
        
        foreach ( $lines as $line ) {
            $line = trim( $line );
            // Match numbered items like "1.", "1)", or just numbers
            if ( preg_match( '/^\d+\.?\s*(.+)/', $line, $matches ) ) {
                $suggestion = trim( $matches[1] );
                // Clean up any markdown formatting
                $suggestion = preg_replace( '/\*\*(.*?)\*\*/', '$1', $suggestion ); // Remove bold
                $suggestion = preg_replace( '/\*(.*?)\*/', '$1', $suggestion ); // Remove italic
                if ( ! empty( $suggestion ) ) {
                    $suggestions[] = $suggestion;
                }
            }
        }
        
        // If no numbered suggestions found, try to extract any meaningful content
        if ( empty( $suggestions ) ) {
            $lines = explode( "\n", $response );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( ! empty( $line ) && strlen( $line ) > 10 && ! preg_match( '/^(Here|Below|These)/', $line ) ) {
                    $suggestions[] = $line;
                }
            }
        }
        
        return array_slice( $suggestions, 0, 10 ); // Limit to 10 suggestions
    }
    
    /**
     * Parse AI analysis text response
     */
    private static function parse_ai_analysis_text( $text ) {
        $analysis = [
            'seo_ethics' => [],
            'keyword_optimization' => [],
            'content_structure' => [],
            'readability' => [],
            'seo_improvements' => [],
            'engagement' => []
        ];
        
        $current_category = '';
        $lines = explode( "\n", $text );
        
        foreach ( $lines as $line ) {
            $line = trim( $line );
            
            // Check for category headers
            if ( stripos( $line, 'SEO_ETHICS' ) !== false || stripos( $line, 'seo ethics' ) !== false ) {
                $current_category = 'seo_ethics';
                continue;
            } elseif ( stripos( $line, 'KEYWORD_OPTIMIZATION' ) !== false || stripos( $line, 'keyword optimization' ) !== false ) {
                $current_category = 'keyword_optimization';
                continue;
            } elseif ( stripos( $line, 'CONTENT_STRUCTURE' ) !== false || stripos( $line, 'content structure' ) !== false ) {
                $current_category = 'content_structure';
                continue;
            } elseif ( stripos( $line, 'READABILITY' ) !== false || stripos( $line, 'readability' ) !== false ) {
                $current_category = 'readability';
                continue;
            } elseif ( stripos( $line, 'SEO_IMPROVEMENTS' ) !== false || stripos( $line, 'seo improvements' ) !== false ) {
                $current_category = 'seo_improvements';
                continue;
            } elseif ( stripos( $line, 'ENGAGEMENT' ) !== false || stripos( $line, 'engagement' ) !== false ) {
                $current_category = 'engagement';
                continue;
            }
            
            // Process suggestion lines
            if ( ! empty( $current_category ) && ! empty( $line ) ) {
                // Handle bullet points
                if ( strpos( $line, '- ' ) === 0 ) {
                    $suggestion = trim( substr( $line, 2 ) );
                    if ( ! empty( $suggestion ) ) {
                        $analysis[ $current_category ][] = $suggestion;
                    }
                } 
                // Handle numbered items
                elseif ( preg_match( '/^\d+\.?\s*(.+)/', $line, $matches ) ) {
                    $suggestion = trim( $matches[1] );
                    if ( ! empty( $suggestion ) ) {
                        $analysis[ $current_category ][] = $suggestion;
                    }
                }
                // Handle regular text (but skip category headers)
                elseif ( ! preg_match( '/^[A-Z_]+:?$/', $line ) && strlen( $line ) > 10 ) {
                    $analysis[ $current_category ][] = $line;
                }
            }
        }
        
        // Remove empty categories
        foreach ( $analysis as $category => $suggestions ) {
            if ( empty( $suggestions ) ) {
                unset( $analysis[ $category ] );
            }
        }
        
        return $analysis;
    }
    
    /**
     * Parse topic suggestions response
     */
    private static function parse_topic_suggestions( $text ) {
        $suggestions = [
            'topics' => [],
            'questions' => [],
            'keywords' => []
        ];
        
        $current_section = '';
        $lines = explode( "\n", $text );
        
        foreach ( $lines as $line ) {
            $line = trim( $line );
            
            if ( stripos( $line, 'TOPICS:' ) !== false ) {
                $current_section = 'topics';
            } elseif ( stripos( $line, 'QUESTIONS:' ) !== false ) {
                $current_section = 'questions';
            } elseif ( stripos( $line, 'KEYWORDS:' ) !== false ) {
                $current_section = 'keywords';
            } elseif ( strpos( $line, '- ' ) === 0 && ! empty( $current_section ) ) {
                $suggestions[ $current_section ][] = substr( $line, 2 );
            }
        }
        
        return $suggestions;
    }
    
    /**
     * Generate keyword suggestions with reasons (similar to titles/descriptions format)
     */
    public static function generate_keyword_suggestions( $post_content, $post_title = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        // Get current post ID
        global $post;
        $post_id = $post ? $post->ID : ( $_POST['post_id'] ?? 0 );
        
        // Check cache first
        $cache_key = self::get_post_cache_key( 'keywords', $post_content, '', $post_title );
        $cached_keywords = self::get_cached_post_suggestions( $post_id, $cache_key );
        if ( $cached_keywords !== false ) {
            return $cached_keywords;
        }
        
        $prompt = "Based on this content, suggest 5 potential focus keywords for SEO optimization:\n\n";
        if ( ! empty( $post_title ) ) {
            $prompt .= "Title: " . $post_title . "\n";
        }
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        $prompt .= "Analyze the content and suggest keywords that:\n";
        $prompt .= "- Match the content's main topic and intent\n";
        $prompt .= "- Have good search volume potential\n";
        $prompt .= "- Are not too competitive for ranking\n";
        $prompt .= "- Include a mix of primary and long-tail keywords\n";
        $prompt .= "- Are commercially viable if applicable\n\n";
        
        $prompt .= "Respond with ONLY valid JSON in this exact format (no markdown, no code blocks):\n";
        $prompt .= '{"keywords":[{"keyword":"Best keyword here","reason":"Brief explanation of why this is the top choice"},{"keyword":"Second keyword","reason":"Brief explanation"},{"keyword":"Third keyword","reason":"Brief explanation"},{"keyword":"Fourth keyword","reason":"Brief explanation"},{"keyword":"Fifth keyword","reason":"Brief explanation"}]}' . "\n\n";
        $prompt .= "Put the BEST keyword first based on SEO potential. Keep reasons to one short sentence. Return ONLY the JSON, nothing else.";
        
        // Enable web search if setting is enabled
        $enable_search = self::is_ai_web_search_enabled();
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        // Clean the response - remove any markdown formatting
        $response = trim( $response );
        // Find the JSON object - look for first { and last }
        $start = strpos( $response, '{' );
        $end = strrpos( $response, '}' );
        if ( $start !== false && $end !== false && $end > $start ) {
            $response = substr( $response, $start, $end - $start + 1 );
        }
        $response = trim( $response );
        
        // Parse JSON response
        $data = json_decode( $response, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['keywords'] ) ) {
            // Fallback: try to extract keywords from malformed response
            $fallback_keywords = self::extract_keywords_fallback( $response, $post_content );
            if ( ! empty( $fallback_keywords ) ) {
                return $fallback_keywords;
            }
            
            // Final fallback: generate simple keywords based on content analysis
            return self::generate_simple_keywords( $post_content, $post_title );
        }
        
        // Ensure we have valid keyword structures
        $valid_keywords = array();
        foreach ( $data['keywords'] as $keyword_data ) {
            if ( isset( $keyword_data['keyword'] ) && isset( $keyword_data['reason'] ) ) {
                $valid_keywords[] = array(
                    'keyword' => $keyword_data['keyword'],
                    'reason' => $keyword_data['reason']
                );
            }
        }
        
        // Limit to 5 keywords
        $final_keywords = array_slice( $valid_keywords, 0, 5 );
        
        // Cache the results in post meta
        if ( $post_id ) {
            self::cache_post_suggestions( $post_id, $cache_key, $final_keywords );
        }
        
        return $final_keywords;
    }
    
    /**
     * Extract keywords fallback when JSON parsing fails
     */
    private static function extract_keywords_fallback( $response, $post_content ) {
        $keywords = array();
        
        // Try to find keyword patterns in the response
        $lines = explode( "\n", $response );
        $count = 0;
        
        foreach ( $lines as $line ) {
            $line = trim( $line );
            
            // Skip empty lines and JSON artifacts
            if ( empty( $line ) || strpos( $line, '{' ) !== false || strpos( $line, '}' ) !== false ) {
                continue;
            }
            
            // Look for various keyword patterns
            if ( preg_match( '/^(\d+\.?\s*)?([^-:]+?)[\s-:]+(.+)$/', $line, $matches ) ) {
                $keywords[] = array(
                    'keyword' => trim( $matches[2] ),
                    'reason' => trim( $matches[3] )
                );
                $count++;
            } elseif ( ! empty( $line ) && $count < 5 ) {
                // Simple keyword without reason
                $keywords[] = array(
                    'keyword' => $line,
                    'reason' => 'Relevant to content topic'
                );
                $count++;
            }
            
            if ( $count >= 5 ) {
                break;
            }
        }
        
        return $keywords;
    }
    
    /**
     * Generate simple keywords as final fallback
     */
    private static function generate_simple_keywords( $post_content, $post_title = '' ) {
        $keywords = array();
        
        // Extract potential keywords from title and content
        $text = $post_title . ' ' . strip_tags( $post_content );
        $words = str_word_count( strtolower( $text ), 1 );
        
        // Filter out common words
        $stop_words = array( 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'this', 'that', 'these', 'those', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'cannot', 'not' );
        $words = array_diff( $words, $stop_words );
        
        // Count word frequency
        $word_counts = array_count_values( $words );
        arsort( $word_counts );
        
        $top_words = array_slice( array_keys( $word_counts ), 0, 3 );
        
        foreach ( $top_words as $word ) {
            if ( strlen( $word ) > 3 ) { // Only words longer than 3 characters
                $keywords[] = array(
                    'keyword' => $word,
                    'reason' => 'Frequently mentioned in content'
                );
            }
        }
        
        // Add some generic keywords if we don't have enough
        while ( count( $keywords ) < 5 ) {
            $generic_keywords = array(
                array( 'keyword' => 'main topic', 'reason' => 'Primary subject matter' ),
                array( 'keyword' => 'guide', 'reason' => 'Educational content' ),
                array( 'keyword' => 'tips', 'reason' => 'Helpful information' ),
                array( 'keyword' => 'best practices', 'reason' => 'Expert advice' ),
                array( 'keyword' => 'how to', 'reason' => 'Instructional content' )
            );
            
            $keywords[] = $generic_keywords[ count( $keywords ) ];
        }
        
        return array_slice( $keywords, 0, 5 );
    }
    
    /**
     * Generate Facebook-specific titles
     */
    public static function generate_facebook_titles( $post_content, $focus_keyword = '', $base_title = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for engaging Facebook content and viral posts related to '" . $focus_keyword . "' to understand what drives engagement.";
        }
        
        $prompt = "Generate 5 compelling Facebook titles optimized for social media engagement." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Base Title: " . $base_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        $prompt .= "Create Facebook titles that:\n";
        $prompt .= "- Are optimized for social media engagement and sharing\n";
        $prompt .= "- Include the focus keyword naturally if provided\n";
        $prompt .= "- Keep under 95 characters for optimal display\n";
        $prompt .= "- Are compelling and encourage clicks and shares\n";
        $prompt .= "- Use engaging language that works well on Facebook\n";
        $prompt .= "- Consider emotional triggers and social proof\n\n";
        
        $prompt .= "Respond with ONLY valid JSON in this exact format:\n";
        $prompt .= '{"titles":[{"title":"Best Facebook title here","reason":"Brief explanation"},{"title":"Second title","reason":"Brief explanation"},{"title":"Third title","reason":"Brief explanation"},{"title":"Fourth title","reason":"Brief explanation"},{"title":"Fifth title","reason":"Brief explanation"}]}' . "\n\n";
        $prompt .= "Focus on social media engagement. Return ONLY the JSON, nothing else.";
        
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return self::parse_titles_response( $response );
    }
    
    /**
     * Generate Facebook-specific descriptions
     */
    public static function generate_facebook_descriptions( $post_content, $focus_keyword = '', $base_title = '', $meta_description = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for engaging Facebook post descriptions and high-performing social content for '" . $focus_keyword . "'.";
        }
        
        $prompt = "Generate 3 compelling Facebook descriptions optimized for social engagement." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $base_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Meta Description: " . $meta_description . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        $prompt .= "Create Facebook descriptions that:\n";
        $prompt .= "- Are optimized for social media engagement\n";
        $prompt .= "- Include the focus keyword naturally if provided\n";
        $prompt .= "- Keep between 150-300 characters\n";
        $prompt .= "- Encourage engagement, clicks, and shares\n";
        $prompt .= "- Use social-friendly language and tone\n";
        $prompt .= "- Include calls-to-action when appropriate\n\n";
        
        $prompt .= "Respond with ONLY valid JSON in this exact format:\n";
        $prompt .= '{"descriptions":[{"description":"Best Facebook description here","reason":"Brief explanation"},{"description":"Second description","reason":"Brief explanation"},{"description":"Third description","reason":"Brief explanation"}]}' . "\n\n";
        $prompt .= "Focus on social media engagement. Return ONLY the JSON, nothing else.";
        
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return self::parse_descriptions_response( $response );
    }
    
    /**
     * Generate Twitter-specific titles
     */
    public static function generate_twitter_titles( $post_content, $focus_keyword = '', $base_title = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for trending Twitter content and viral tweets related to '" . $focus_keyword . "'.";
        }
        
        $prompt = "Generate 5 compelling Twitter titles optimized for the platform." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Base Title: " . $base_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        $prompt .= "Create Twitter titles that:\n";
        $prompt .= "- Are optimized for Twitter engagement and retweets\n";
        $prompt .= "- Include the focus keyword naturally if provided\n";
        $prompt .= "- Keep under 70 characters to allow room for handles/hashtags\n";
        $prompt .= "- Are punchy and attention-grabbing\n";
        $prompt .= "- Work well with Twitter's fast-paced environment\n";
        $prompt .= "- Consider trending topics and Twitter culture\n\n";
        
        $prompt .= "Respond with ONLY valid JSON in this exact format:\n";
        $prompt .= '{"titles":[{"title":"Best Twitter title here","reason":"Brief explanation"},{"title":"Second title","reason":"Brief explanation"},{"title":"Third title","reason":"Brief explanation"},{"title":"Fourth title","reason":"Brief explanation"},{"title":"Fifth title","reason":"Brief explanation"}]}' . "\n\n";
        $prompt .= "Focus on Twitter engagement. Return ONLY the JSON, nothing else.";
        
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return self::parse_titles_response( $response );
    }
    
    /**
     * Generate Twitter-specific descriptions
     */
    public static function generate_twitter_descriptions( $post_content, $focus_keyword = '', $base_title = '', $base_description = '' ) {
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for engaging Twitter content and high-performing tweets for '" . $focus_keyword . "'.";
        }
        
        $prompt = "Generate 3 compelling Twitter descriptions optimized for the platform." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $base_title . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Base Description: " . $base_description . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        
        $prompt .= "Create Twitter descriptions that:\n";
        $prompt .= "- Are optimized for Twitter engagement and retweets\n";
        $prompt .= "- Include the focus keyword naturally if provided\n";
        $prompt .= "- Keep between 120-200 characters\n";
        $prompt .= "- Are concise and impactful\n";
        $prompt .= "- Work well with Twitter's character limits\n";
        $prompt .= "- Include relevant hashtags or mentions when appropriate\n\n";
        
        $prompt .= "Respond with ONLY valid JSON in this exact format:\n";
        $prompt .= '{"descriptions":[{"description":"Best Twitter description here","reason":"Brief explanation"},{"description":"Second description","reason":"Brief explanation"},{"description":"Third description","reason":"Brief explanation"}]}' . "\n\n";
        $prompt .= "Focus on Twitter engagement. Return ONLY the JSON, nothing else.";
        
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return self::parse_descriptions_response( $response );
    }
    
    /**
     * Parse titles response (reuse existing logic)
     */
    private static function parse_titles_response( $response ) {
        // Clean the response - remove any markdown formatting
        $response = trim( $response );
        $start = strpos( $response, '{' );
        $end = strrpos( $response, '}' );
        if ( $start !== false && $end !== false && $end > $start ) {
            $response = substr( $response, $start, $end - $start + 1 );
        }
        $response = trim( $response );
        
        $data = json_decode( $response, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['titles'] ) ) {
            return array(
                array( 'title' => 'AI-Generated Title 1', 'reason' => 'Fallback title' ),
                array( 'title' => 'AI-Generated Title 2', 'reason' => 'Fallback title' ),
                array( 'title' => 'AI-Generated Title 3', 'reason' => 'Fallback title' )
            );
        }
        
        $valid_titles = array();
        foreach ( $data['titles'] as $title_data ) {
            if ( isset( $title_data['title'] ) && isset( $title_data['reason'] ) ) {
                $valid_titles[] = array(
                    'title' => $title_data['title'],
                    'reason' => $title_data['reason']
                );
            }
        }
        
        return array_slice( $valid_titles, 0, 5 );
    }
    
    /**
     * Parse descriptions response (reuse existing logic)
     */
    private static function parse_descriptions_response( $response ) {
        // Clean the response - remove any markdown formatting
        $response = trim( $response );
        $start = strpos( $response, '{' );
        $end = strrpos( $response, '}' );
        if ( $start !== false && $end !== false && $end > $start ) {
            $response = substr( $response, $start, $end - $start + 1 );
        }
        $response = trim( $response );
        
        $data = json_decode( $response, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['descriptions'] ) ) {
            return array(
                array( 'description' => 'AI-Generated Description 1', 'reason' => 'Fallback description' ),
                array( 'description' => 'AI-Generated Description 2', 'reason' => 'Fallback description' ),
                array( 'description' => 'AI-Generated Description 3', 'reason' => 'Fallback description' )
            );
        }
        
        $valid_descriptions = array();
        foreach ( $data['descriptions'] as $desc_data ) {
            if ( isset( $desc_data['description'] ) && isset( $desc_data['reason'] ) ) {
                $valid_descriptions[] = array(
                    'description' => $desc_data['description'],
                    'reason' => $desc_data['reason']
                );
            }
        }
        
        return array_slice( $valid_descriptions, 0, 3 );
    }
    
    /**
     * Generate Facebook-specific image suggestions
     */
    public static function generate_facebook_image( $post_content, $focus_keyword = '', $title = '', $description = '', $featured_image_url = '' ) {
        if ( ! self::is_ai_image_generation_enabled() ) {
            return new WP_Error( 'ai_image_disabled', 'AI image generation is not enabled' );
        }
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for trending Facebook visual content and high-engagement posts for '" . $focus_keyword . "'.";
        }
        
        $prompt = "Generate 3 Facebook image concept suggestions optimized for maximum engagement." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $title . "\n";
        $prompt .= "Description: " . $description . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 200 ) . "\n";
        if ( ! empty( $featured_image_url ) ) {
            $prompt .= "Current Featured Image: " . $featured_image_url . " (use as reference for style/branding)\n";
        }
        $prompt .= "\n";
        
        $prompt .= "Create Facebook image suggestions that:\n";
        $prompt .= "- Are optimized for Facebook's 1200x630px format (1.91:1 ratio)\n";
        $prompt .= "- Include compelling visual concepts that drive clicks and engagement\n";
        $prompt .= "- Work well in Facebook's feed algorithm\n";
        $prompt .= "- Include text overlay concepts (max 20% text per Facebook guidelines)\n";
        $prompt .= "- Consider current Facebook visual trends and best practices\n";
        $prompt .= "- Are eye-catching when viewed as thumbnails\n";
        $prompt .= "- Match the content's tone and target audience\n\n";
        
        $prompt .= "Each suggestion should include:\n";
        $prompt .= "- Visual concept description\n";
        $prompt .= "- Recommended text overlay (if any)\n";
        $prompt .= "- Color scheme suggestions\n";
        $prompt .= "- Why this concept will perform well on Facebook\n\n";
        
        $prompt .= "Respond with ONLY valid JSON in this exact format:\n";
        $prompt .= '{"image_suggestions":[{"concept":"Visual concept description","text_overlay":"Suggested overlay text","colors":"Color scheme","reason":"Why this will engage Facebook users","image_prompt":"Detailed DALL-E style prompt for generating this image"},{"concept":"Second concept","text_overlay":"Second overlay","colors":"Second colors","reason":"Second reason","image_prompt":"Second detailed prompt"}]}' . "\n\n";
        $prompt .= "Focus on Facebook engagement metrics. Return ONLY the JSON, nothing else.";
        
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $image_concepts = self::parse_image_suggestions_response( $response );
        
        if ( is_wp_error( $image_concepts ) ) {
            return $image_concepts;
        }
        
        // Generate actual images for each concept
        foreach ( $image_concepts as &$concept ) {
            if ( isset( $concept['image_prompt'] ) ) {
                $image_url = self::generate_dalle_image( $concept['image_prompt'], '1792x1024' ); // Closest to Facebook 1200x630 ratio
                if ( ! is_wp_error( $image_url ) ) {
                    $concept['generated_image'] = $image_url;
                } else {
                    $concept['generated_image'] = false;
                    $concept['image_error'] = $image_url->get_error_message();
                }
            }
        }
        
        return $image_concepts;
    }
    
    /**
     * Generate Twitter-specific image suggestions
     */
    public static function generate_twitter_image( $post_content, $focus_keyword = '', $title = '', $description = '', $featured_image_url = '' ) {
        if ( ! self::is_ai_image_generation_enabled() ) {
            return new WP_Error( 'ai_image_disabled', 'AI image generation is not enabled' );
        }
        if ( ! self::is_ai_enabled() ) {
            return new WP_Error( 'ai_disabled', 'AI features are not enabled' );
        }
        
        $search_context = '';
        if ( ! empty( $focus_keyword ) ) {
            $search_context = " First, search for trending Twitter visual content and viral tweets for '" . $focus_keyword . "'.";
        }
        
        $prompt = "Generate 3 Twitter image concept suggestions optimized for maximum retweets and engagement." . $search_context . "\n\n";
        $prompt .= "Content Details:\n";
        $prompt .= "Title: " . $title . "\n";
        $prompt .= "Description: " . $description . "\n";
        $prompt .= "Focus Keyword: " . $focus_keyword . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 200 ) . "\n";
        if ( ! empty( $featured_image_url ) ) {
            $prompt .= "Current Featured Image: " . $featured_image_url . " (use as reference for style/branding)\n";
        }
        $prompt .= "\n";
        
        $prompt .= "Create Twitter image suggestions that:\n";
        $prompt .= "- Are optimized for Twitter's 1024x512px format (2:1 ratio) for optimal display\n";
        $prompt .= "- Include compelling visual concepts that drive retweets and replies\n";
        $prompt .= "- Work well with Twitter's algorithm and trending topics\n";
        $prompt .= "- Are mobile-friendly and look great on small screens\n";
        $prompt .= "- Consider current Twitter visual trends and meme culture\n";
        $prompt .= "- Include engaging text overlays that complement tweets\n";
        $prompt .= "- Are shareable and discussion-worthy\n\n";
        
        $prompt .= "Each suggestion should include:\n";
        $prompt .= "- Visual concept description\n";
        $prompt .= "- Recommended text overlay (if any)\n";
        $prompt .= "- Color scheme suggestions\n";
        $prompt .= "- Why this concept will go viral on Twitter\n\n";
        
        $prompt .= "Respond with ONLY valid JSON in this exact format:\n";
        $prompt .= '{"image_suggestions":[{"concept":"Visual concept description","text_overlay":"Suggested overlay text","colors":"Color scheme","reason":"Why this will engage Twitter users","image_prompt":"Detailed DALL-E style prompt for generating this image"},{"concept":"Second concept","text_overlay":"Second overlay","colors":"Second colors","reason":"Second reason","image_prompt":"Second detailed prompt"}]}' . "\n\n";
        $prompt .= "Focus on Twitter viral potential. Return ONLY the JSON, nothing else.";
        
        $enable_search = self::is_ai_web_search_enabled() && ! empty( $focus_keyword );
        $response = self::make_openai_request( $prompt, 'gpt-3.5-turbo', $enable_search );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        $image_concepts = self::parse_image_suggestions_response( $response );
        
        if ( is_wp_error( $image_concepts ) ) {
            return $image_concepts;
        }
        
        // Generate actual images for each concept
        foreach ( $image_concepts as &$concept ) {
            if ( isset( $concept['image_prompt'] ) ) {
                $image_url = self::generate_dalle_image( $concept['image_prompt'], '1024x1024' ); // Twitter's preferred square format
                if ( ! is_wp_error( $image_url ) ) {
                    $concept['generated_image'] = $image_url;
                } else {
                    $concept['generated_image'] = false;
                    $concept['image_error'] = $image_url->get_error_message();
                }
            }
        }
        
        return $image_concepts;
    }
    
    /**
     * Parse image suggestions response
     */
    private static function parse_image_suggestions_response( $response ) {
        // Clean the response - remove any markdown formatting
        $response = trim( $response );
        $start = strpos( $response, '{' );
        $end = strrpos( $response, '}' );
        if ( $start !== false && $end !== false && $end > $start ) {
            $response = substr( $response, $start, $end - $start + 1 );
        }
        $response = trim( $response );
        
        $data = json_decode( $response, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['image_suggestions'] ) ) {
            return array(
                array( 
                    'concept' => 'Professional Header Image',
                    'text_overlay' => 'Eye-catching title text',
                    'colors' => 'Brand colors with high contrast',
                    'reason' => 'Professional appearance builds trust',
                    'image_prompt' => 'Professional, clean design with modern typography'
                ),
                array( 
                    'concept' => 'Engaging Visual Metaphor',
                    'text_overlay' => 'Key message highlight',
                    'colors' => 'Vibrant, attention-grabbing palette',
                    'reason' => 'Visual metaphors increase engagement',
                    'image_prompt' => 'Creative visual metaphor related to the content topic'
                ),
                array( 
                    'concept' => 'Minimalist Design',
                    'text_overlay' => 'Simple, clear message',
                    'colors' => 'Clean, minimal color scheme',
                    'reason' => 'Minimalist designs perform well on social media',
                    'image_prompt' => 'Clean, minimalist design with plenty of white space'
                )
            );
        }
        
        $suggestions = $data['image_suggestions'];
        $valid_suggestions = array();
        
        foreach ( $suggestions as $suggestion ) {
            if ( isset( $suggestion['concept'] ) && ! empty( $suggestion['concept'] ) ) {
                $valid_suggestions[] = array(
                    'concept' => sanitize_text_field( $suggestion['concept'] ),
                    'text_overlay' => sanitize_text_field( $suggestion['text_overlay'] ?? '' ),
                    'colors' => sanitize_text_field( $suggestion['colors'] ?? '' ),
                    'reason' => sanitize_text_field( $suggestion['reason'] ?? '' ),
                    'image_prompt' => sanitize_text_field( $suggestion['image_prompt'] ?? '' )
                );
            }
        }
        
        // Ensure we have at least 3 suggestions
        while ( count( $valid_suggestions ) < 3 ) {
            $valid_suggestions[] = array(
                'concept' => 'Custom Design Concept',
                'text_overlay' => 'Compelling message',
                'colors' => 'Brand-appropriate colors',
                'reason' => 'Tailored to your content',
                'image_prompt' => 'Professional design tailored to the content'
            );
        }
        
        return array_slice( $valid_suggestions, 0, 3 );
    }
}

// Initialize the API helper
new AceSEOApiHelper();
