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
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
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
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for AI suggestions' );
        }
        
        $descriptions = AceSEOApiHelper::generate_meta_descriptions( $post_content, $focus_keyword, $current_title );
        
        if ( is_wp_error( $descriptions ) ) {
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
        
        if ( empty( $post_content ) ) {
            wp_send_json_error( 'Content is required for keyword suggestions' );
        }
        
        $prompt = "Based on this content, suggest 5 potential focus keywords for SEO:\n\n";
        $prompt .= "Title: " . $current_title . "\n";
        $prompt .= "Content: " . wp_trim_words( strip_tags( $post_content ), 300 ) . "\n\n";
        $prompt .= "Provide keywords in order of SEO potential:\n";
        $prompt .= "1. Best primary keyword (1-3 words)\n";
        $prompt .= "2-5. Alternative keywords (mix of short and long-tail)\n\n";
        $prompt .= "Return ONLY the keywords, one per line, without numbering.";
        
        $response = AceSEOApiHelper::make_openai_request( $prompt );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }
        
        $keywords = array_filter( array_map( 'trim', explode( "\n", $response ) ) );
        $keywords = array_slice( $keywords, 0, 5 );
        
        wp_send_json_success( array( 'keywords' => $keywords ) );
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
