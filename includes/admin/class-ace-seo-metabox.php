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
            
            // Add AI Analysis sidebar metabox
            if ( AceSEOApiHelper::is_ai_enabled() ) {
                add_meta_box(
                    'ace-seo-ai-analysis',
                    'AI Content Analysis',
                    array( $this, 'render_ai_analysis_metabox' ),
                    $post_type,
                    'side',
                    'default'
                );
            }
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
     * Render the AI Analysis sidebar metabox
     */
    public function render_ai_analysis_metabox( $post ) {
        // Add nonce for AI requests
        wp_nonce_field( 'ace_seo_ai_nonce', 'ace_seo_ai_nonce_field' );
        ?>
        <div id="ace-ai-analysis-sidebar">
            <div class="ace-ai-analysis-actions">
                <button type="button" class="button button-primary button-large" id="ace-analyze-all-content" style="width: 100%; margin-bottom: 10px;">
                    <span class="dashicons dashicons-analytics" style="margin-top: 3px;"></span>
                    Analyze Content with AI
                </button>
                <p class="description">Get comprehensive AI analysis including SEO insights, topic ideas, and content improvements.</p>
            </div>
            
            <!-- Loading State -->
            <div class="ace-analysis-loading" id="ace-analysis-loading" style="display: none;">
                <div class="ace-spinner" style="margin: 20px auto; display: block;"></div>
                <p style="text-align: center; margin: 10px 0;">Analyzing content with AI...</p>
            </div>
            
            <!-- Analysis Results -->
            <div class="ace-analysis-results" id="ace-analysis-results" style="display: none;">
                
                <!-- Content Analysis Section -->
                <div class="ace-analysis-section" id="ace-content-analysis-section">
                    <h4 style="margin: 15px 0 10px 0; border-bottom: 1px solid #ddd; padding-bottom: 5px;">
                        <span class="dashicons dashicons-analytics"></span>
                        Content Analysis
                    </h4>
                    <div id="ace-content-analysis-content"></div>
                </div>
                
                <!-- Topic Ideas Section -->
                <div class="ace-analysis-section" id="ace-topic-ideas-section" style="display: none;">
                    <h4 style="margin: 15px 0 10px 0; border-bottom: 1px solid #ddd; padding-bottom: 5px;">
                        <span class="dashicons dashicons-lightbulb"></span>
                        Topic Ideas
                    </h4>
                    <div id="ace-topic-ideas-content"></div>
                </div>
                
                <!-- Content Improvements Section -->
                <div class="ace-analysis-section" id="ace-content-improvements-section" style="display: none;">
                    <h4 style="margin: 15px 0 10px 0; border-bottom: 1px solid #ddd; padding-bottom: 5px;">
                        <span class="dashicons dashicons-edit"></span>
                        Improvements
                    </h4>
                    <div id="ace-content-improvements-content"></div>
                </div>
                
            </div>
            
            <!-- Error State -->
            <div class="ace-analysis-error" id="ace-analysis-error" style="display: none;">
                <div style="background: #fff2f2; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px; margin: 10px 0;">
                    <span class="dashicons dashicons-warning" style="color: #721c24;"></span>
                    <span id="ace-analysis-error-message" style="color: #721c24;">Analysis failed</span>
                </div>
            </div>
        </div>
        
        <!-- AI Nonce for AJAX requests -->
        <input type="hidden" id="ace_seo_ai_nonce" value="<?php echo wp_create_nonce('ace_seo_ai_nonce'); ?>">
        <?php
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
        
        // Save each meta field using plugin-specific keys
        foreach ( $meta_fields as $group => $fields ) {
            foreach ( $fields as $field_key => $field_config ) {
                $input_name = 'yoast_wpseo_' . $field_key;
                
                if ( isset( $_POST[ $input_name ] ) ) {
                    $value = $_POST[ $input_name ];
                    
                    // Sanitize based on field type
                    switch ( $field_config['type'] ) {
                        case 'url':
                            $value = esc_url_raw( $value );
                            break;
                        case 'textarea':
                            $value = sanitize_textarea_field( $value );
                            break;
                        case 'checkbox':
                            $value = ($value === 'on' || $value === '1' || $value === 'true') ? '1' : '0';
                            break;
                        case 'multiselect':
                            if ( is_array( $value ) ) {
                                $value = implode( ',', array_map( 'sanitize_text_field', $value ) );
                            } else {
                                $value = sanitize_text_field( $value );
                            }
                            break;
                        default:
                            $value = sanitize_text_field( $value );
                            break;
                    }
                    
                    // Save to plugin-specific meta key
                    update_post_meta( $post_id, '_ace_seo_' . $field_key, $value );
                } else {
                    // Handle unchecked checkboxes
                    if ( $field_config['type'] === 'checkbox' ) {
                        update_post_meta( $post_id, '_ace_seo_' . $field_key, '0' );
                    }
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
            update_post_meta( $post_id, '_ace_seo_is_cornerstone', '1' );
        } else {
            update_post_meta( $post_id, '_ace_seo_is_cornerstone', '0' );
        }
        
        // Handle advanced robots settings (if they come as array)
        if ( isset( $_POST['yoast_wpseo_meta-robots-adv'] ) && is_array( $_POST['yoast_wpseo_meta-robots-adv'] ) ) {
            $robots_adv = array_map( 'sanitize_text_field', $_POST['yoast_wpseo_meta-robots-adv'] );
            update_post_meta( $post_id, '_ace_seo_meta-robots-adv', implode( ',', $robots_adv ) );
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
        global $post;
        wp_localize_script( 'ace-seo-admin', 'aceSeoAdmin', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'ace-seo/v1/' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'performanceNonce' => wp_create_nonce( 'ace_seo_performance_test' ),
            'postId' => get_the_ID(),
            'postTitle' => $post ? $post->post_title : '',
            'postContent' => $post ? wp_trim_words( strip_tags( $post->post_content ), 25 ) : '',
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
