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
        
        // Add taxonomy form fields
        $this->init_taxonomy_fields();
    }
    
    /**
     * Initialize taxonomy form fields for all public taxonomies
     */
    public function init_taxonomy_fields() {
        $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
        
        foreach ( $taxonomies as $taxonomy ) {
            // Add fields to "Add New" form
            add_action( $taxonomy . '_add_form_fields', array( $this, 'render_taxonomy_add_fields' ) );
            
            // Add fields to "Edit" form  
            add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_taxonomy_edit_fields' ) );
            
            // Save taxonomy fields
            add_action( 'created_' . $taxonomy, array( $this, 'save_taxonomy_fields' ) );
            add_action( 'edited_' . $taxonomy, array( $this, 'save_taxonomy_fields' ) );
        }
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
                
                <!-- SEO Hat Analysis Bar -->
                <div class="ace-seo-hat-analysis" id="ace-seo-hat-analysis" style="display: none;">
                    <h4 style="margin: 15px 0 10px 0; border-bottom: 1px solid #ddd; padding-bottom: 5px;">
                        <span class="dashicons dashicons-shield-alt"></span>
                        SEO Practice Analysis
                    </h4>
                    <div class="ace-seo-hat-container">
                        <div class="ace-seo-hat-bar">
                            <div class="ace-seo-hat-indicator" id="ace-seo-hat-indicator"></div>
                        </div>
                        <div class="ace-seo-hat-labels">
                            <span class="ace-hat-label black-hat">Black Hat</span>
                            <span class="ace-hat-label gray-hat">Gray Hat</span>
                            <span class="ace-hat-label white-hat">White Hat</span>
                        </div>
                        <div class="ace-seo-hat-score" id="ace-seo-hat-score">
                            <span class="ace-hat-score-text">Analyzing...</span>
                        </div>
                    </div>
                </div>
                
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
        // Load on post edit screens and taxonomy pages
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit-tags.php', 'term.php' ) ) ) {
            return;
        }
        
        // For post screens, check post type
        if ( in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            global $post_type;
            $public_post_types = get_post_types( array( 'public' => true ), 'names' );
            
            if ( ! in_array( $post_type, $public_post_types ) || $post_type === 'attachment' ) {
                return;
            }
        }
        
        // For taxonomy screens, check if it's a public taxonomy
        if ( in_array( $hook, array( 'edit-tags.php', 'term.php' ) ) ) {
            $taxonomy = isset( $_GET['taxonomy'] ) ? $_GET['taxonomy'] : '';
            $public_taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
            
            if ( ! in_array( $taxonomy, $public_taxonomies ) ) {
                return;
            }
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
            ACE_SEO_VERSION . '.' . time(), // Add timestamp for cache busting during development
            true
        );
        
        // Add inline script for template variable insertion
        wp_add_inline_script( 'ace-seo-admin', '
            jQuery(document).ready(function($) {
                // Handle clicking on template variables
                $(document).on("click", ".ace-variable-tag", function(e) {
                    e.preventDefault();
                    
                    var variable = $(this).data("variable");
                    var targetId = $(this).data("target");
                    var $target = $("#" + targetId);
                    
                    if ($target.length) {
                        var currentValue = $target.val();
                        var cursorPos = $target[0].selectionStart;
                        
                        // If cursor position is available and field is focused
                        if (typeof cursorPos !== "undefined" && $target.is(":focus")) {
                            var before = currentValue.substring(0, cursorPos);
                            var after = currentValue.substring(cursorPos);
                            
                            // Add spaces around variable if needed
                            var spaceBefore = (before.length > 0 && !before.endsWith(" ")) ? " " : "";
                            var spaceAfter = (after.length > 0 && !after.startsWith(" ")) ? " " : "";
                            
                            var newValue = before + spaceBefore + variable + spaceAfter + after;
                            $target.val(newValue);
                            
                            // Set cursor position after inserted variable
                            var newPos = cursorPos + spaceBefore.length + variable.length + spaceAfter.length;
                            $target[0].setSelectionRange(newPos, newPos);
                        } else {
                            // Insert at end with space if needed
                            var spacePrefix = (currentValue.length > 0 && !currentValue.endsWith(" ")) ? " " : "";
                            $target.val(currentValue + spacePrefix + variable);
                        }
                        
                        // Focus the field and trigger any change events
                        $target.focus().trigger("input").trigger("change");
                    }
                });
            });
        ' );
        
        // Enqueue media scripts for image selection
        wp_enqueue_media();
        
        // Localize script with data
        global $post;
        $options = get_option('ace_seo_options', []);
        $templates = $options['templates'] ?? [];
        $general = $options['general'] ?? [];
        
        // Get data for posts or taxonomy terms
        $featured_image_url = '';
        $current_id = 0;
        $current_title = '';
        $current_content = '';
        $current_type = '';
        $is_taxonomy = false;
        
        if ( $post ) {
            // Post data
            $current_id = $post->ID;
            $current_title = $post->post_title;
            $current_content = wp_trim_words( strip_tags( $post->post_content ), 25 );
            $current_type = $post->post_type;
            
            $featured_image_id = get_post_thumbnail_id( $post->ID );
            $featured_image_url = $featured_image_id ? wp_get_attachment_image_url( $featured_image_id, 'large' ) : '';
        } elseif ( isset( $_GET['tag_ID'] ) && isset( $_GET['taxonomy'] ) ) {
            // Taxonomy term data
            $term_id = intval( $_GET['tag_ID'] );
            $taxonomy = sanitize_text_field( $_GET['taxonomy'] );
            $term = get_term( $term_id, $taxonomy );
            
            if ( $term && ! is_wp_error( $term ) ) {
                $current_id = $term_id;
                $current_title = $term->name;
                $current_content = wp_trim_words( strip_tags( $term->description ), 25 );
                $current_type = 'taxonomy_' . $taxonomy;
                $is_taxonomy = true;
            }
        }
        
        wp_localize_script( 'ace-seo-admin', 'aceSeoAdmin', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'ace-seo/v1/' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'performanceNonce' => wp_create_nonce( 'ace_seo_performance_test' ),
            'postId' => $current_id,
            'postTitle' => $current_title,
            'postContent' => $current_content,
            'postType' => $current_type,
            'featuredImage' => $featured_image_url,
            'isTaxonomy' => $is_taxonomy,
            'titleTemplate' => $post ? ($templates['title_template_' . $post->post_type] ?? '{title} {sep} {site_name}') : '{title} {sep} {site_name}',
            'metaTemplate' => $post ? ($templates['meta_template_' . $post->post_type] ?? '{excerpt}') : '{excerpt}',
            'siteName' => $general['site_name'] ?? get_bloginfo('name'),
            'separator' => $general['separator'] ?? '|',
            'strings' => array(
                'analyzing' => __( 'Analyzing...', 'ace-crawl-enhancer' ),
                'excellent' => __( 'Excellent', 'ace-crawl-enhancer' ),
                'good' => __( 'Good', 'ace-crawl-enhancer' ),
                'ok' => __( 'OK', 'ace-crawl-enhancer' ),
                'poor' => __( 'Poor', 'ace-crawl-enhancer' ),
                'bad' => __( 'Bad', 'ace-crawl-enhancer' ),
            )
        ) );
    }
    
    /**
     * Render SEO fields for taxonomy "Add New" form
     */
    public function render_taxonomy_add_fields( $taxonomy ) {
        wp_nonce_field( 'ace_seo_taxonomy_save', 'ace_seo_taxonomy_nonce' );
        ?>
        <div class="form-field ace-seo-taxonomy-fields">
            <h3><?php _e( 'SEO Settings', 'ace-crawl-enhancer' ); ?></h3>
            
            <div class="form-field">
                <label for="ace_seo_title"><?php _e( 'SEO Title', 'ace-crawl-enhancer' ); ?></label>
                <input type="text" name="ace_seo_title" id="ace_seo_title" value="" size="40" />
                <p class="description">
                    <?php _e( 'Custom title for this term. Leave blank to use template.', 'ace-crawl-enhancer' ); ?>
                    <br>
                    <strong><?php _e( 'Available variables (click to insert):', 'ace-crawl-enhancer' ); ?></strong>
                    <span class="ace-template-variables">
                        <code class="ace-variable-tag" data-variable="{category_name}" data-target="ace_seo_title" title="Click to insert">{category_name}</code>
                        <code class="ace-variable-tag" data-variable="{tag_name}" data-target="ace_seo_title" title="Click to insert">{tag_name}</code>
                        <code class="ace-variable-tag" data-variable="{archive_title}" data-target="ace_seo_title" title="Click to insert">{archive_title}</code>
                        <code class="ace-variable-tag" data-variable="{site_name}" data-target="ace_seo_title" title="Click to insert">{site_name}</code>
                        <code class="ace-variable-tag" data-variable="{sep}" data-target="ace_seo_title" title="Click to insert">{sep}</code>
                    </span>
                </p>
                <div class="ace-title-counter">
                    <span class="ace-counter-text">0 characters</span>
                    <div class="ace-counter-bar">
                        <div class="ace-counter-progress"></div>
                    </div>
                </div>
            </div>
            
            <div class="form-field">
                <label for="ace_seo_desc"><?php _e( 'Meta Description', 'ace-crawl-enhancer' ); ?></label>
                <textarea name="ace_seo_desc" id="ace_seo_desc" rows="3" cols="40"></textarea>
                <p class="description">
                    <?php _e( 'Custom meta description for this term. Leave blank to use term description.', 'ace-crawl-enhancer' ); ?>
                    <br>
                    <strong><?php _e( 'Available variables (click to insert):', 'ace-crawl-enhancer' ); ?></strong>
                    <span class="ace-template-variables">
                        <code class="ace-variable-tag" data-variable="{category_name}" data-target="ace_seo_desc" title="Click to insert">{category_name}</code>
                        <code class="ace-variable-tag" data-variable="{tag_name}" data-target="ace_seo_desc" title="Click to insert">{tag_name}</code>
                        <code class="ace-variable-tag" data-variable="{archive_title}" data-target="ace_seo_desc" title="Click to insert">{archive_title}</code>
                        <code class="ace-variable-tag" data-variable="{site_name}" data-target="ace_seo_desc" title="Click to insert">{site_name}</code>
                    </span>
                </p>
                <div class="ace-desc-counter">
                    <span class="ace-counter-text">0 characters</span>
                    <div class="ace-counter-bar">
                        <div class="ace-counter-progress"></div>
                    </div>
                </div>
            </div>
            
            <div class="form-field">
                <label for="ace_seo_canonical"><?php _e( 'Canonical URL', 'ace-crawl-enhancer' ); ?></label>
                <input type="url" name="ace_seo_canonical" id="ace_seo_canonical" value="" size="40" />
                <p class="description"><?php _e( 'Custom canonical URL for this term. Leave blank to use default.', 'ace-crawl-enhancer' ); ?></p>
            </div>
            
            <div class="form-field">
                <label for="ace_seo_focuskw"><?php _e( 'Focus Keyword', 'ace-crawl-enhancer' ); ?></label>
                <input type="text" name="ace_seo_focuskw" id="ace_seo_focuskw" value="" size="40" />
                <p class="description"><?php _e( 'Primary keyword to optimize this term for.', 'ace-crawl-enhancer' ); ?></p>
            </div>
            
            <div class="form-field">
                <label for="ace_seo_noindex"><?php _e( 'Search Engine Visibility', 'ace-crawl-enhancer' ); ?></label>
                <select name="ace_seo_noindex" id="ace_seo_noindex">
                    <option value="default"><?php _e( 'Default (follow site settings)', 'ace-crawl-enhancer' ); ?></option>
                    <option value="index"><?php _e( 'Index (visible in search results)', 'ace-crawl-enhancer' ); ?></option>
                    <option value="noindex"><?php _e( 'No Index (hidden from search results)', 'ace-crawl-enhancer' ); ?></option>
                </select>
                <p class="description"><?php _e( 'Control how search engines handle this term.', 'ace-crawl-enhancer' ); ?></p>
            </div>
        </div>
        
        <style>
        .ace-seo-taxonomy-fields {
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .ace-counter-text {
            font-size: 12px;
            color: #666;
        }
        .ace-counter-bar {
            width: 100%;
            height: 4px;
            background: #ddd;
            border-radius: 2px;
            margin-top: 5px;
        }
        .ace-counter-progress {
            height: 100%;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        .ace-counter-progress.good { background: #46b450; }
        .ace-counter-progress.warning { background: #ffb900; }
        .ace-counter-progress.error { background: #dc3232; }
        </style>
        <?php
    }
    
    /**
     * Render SEO fields for taxonomy "Edit" form
     */
    public function render_taxonomy_edit_fields( $term ) {
        $term_id = $term->term_id;
        $taxonomy = $term->taxonomy;
        
        // Get existing values with Yoast fallback
        $title = AceCrawlEnhancer::get_taxonomy_meta( $term_id, $taxonomy, 'title' );
        $desc = AceCrawlEnhancer::get_taxonomy_meta( $term_id, $taxonomy, 'desc' );
        $canonical = AceCrawlEnhancer::get_taxonomy_meta( $term_id, $taxonomy, 'canonical' );
        $focuskw = AceCrawlEnhancer::get_taxonomy_meta( $term_id, $taxonomy, 'focuskw' );
        $noindex = AceCrawlEnhancer::get_taxonomy_meta( $term_id, $taxonomy, 'noindex' );
        
        // Check if values come from Yoast and haven't been migrated yet
        $yoast_tax_meta = get_option( 'wpseo_taxonomy_meta', [] );
        $has_yoast_data = isset( $yoast_tax_meta[$taxonomy][$term_id] );
        
        // Check if ACE data already exists (indicating migration has happened)
        $has_ace_data = false;
        if ( $has_yoast_data ) {
            // Check if migration check meta exists (more reliable than counting all meta)
            $migration_check = get_term_meta( $term_id, '_ace_seo_taxonomy_migration_check', true );
            
            // Also check if any actual ACE SEO data exists
            global $wpdb;
            $ace_meta_count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key LIKE %s AND meta_value != ''",
                $term_id,
                '_ace_seo_%'
            ));
            
            // Consider migrated if either migration check exists OR actual ACE data exists
            $has_ace_data = ( !empty( $migration_check ) || $ace_meta_count > 0 );
        }
        
        // Only show Yoast notice if Yoast data exists but ACE data doesn't (not migrated yet)
        $show_yoast_notice = $has_yoast_data && !$has_ace_data;
        
        wp_nonce_field( 'ace_seo_taxonomy_save', 'ace_seo_taxonomy_nonce' );
        ?>
        <tr class="form-field ace-seo-taxonomy-section">
            <th colspan="2">
                <h3><?php _e( 'SEO Settings', 'ace-crawl-enhancer' ); ?></h3>
                <?php if ( $show_yoast_notice ): ?>
                    <p class="description" style="color: #0073aa;">
                        <strong><?php _e( 'Yoast Data Detected:', 'ace-crawl-enhancer' ); ?></strong> 
                        <?php _e( 'Values below are inherited from Yoast SEO and will be migrated when saved.', 'ace-crawl-enhancer' ); ?>
                    </p>
                <?php endif; ?>
            </th>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="ace_seo_title"><?php _e( 'SEO Title', 'ace-crawl-enhancer' ); ?></label>
            </th>
            <td>
                <input type="text" name="ace_seo_title" id="ace_seo_title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" />
                <p class="description">
                    <?php _e( 'Custom title for this term. Leave blank to use template.', 'ace-crawl-enhancer' ); ?>
                    <br>
                    <strong><?php _e( 'Available variables (click to insert):', 'ace-crawl-enhancer' ); ?></strong>
                    <span class="ace-template-variables">
                        <code class="ace-variable-tag" data-variable="{category_name}" data-target="ace_seo_title" title="Click to insert">{category_name}</code>
                        <code class="ace-variable-tag" data-variable="{tag_name}" data-target="ace_seo_title" title="Click to insert">{tag_name}</code>
                        <code class="ace-variable-tag" data-variable="{archive_title}" data-target="ace_seo_title" title="Click to insert">{archive_title}</code>
                        <code class="ace-variable-tag" data-variable="{site_name}" data-target="ace_seo_title" title="Click to insert">{site_name}</code>
                        <code class="ace-variable-tag" data-variable="{sep}" data-target="ace_seo_title" title="Click to insert">{sep}</code>
                    </span>
                </p>
                <div class="ace-title-counter">
                    <span class="ace-counter-text"><?php echo strlen( $title ); ?> characters</span>
                    <div class="ace-counter-bar">
                        <div class="ace-counter-progress"></div>
                    </div>
                </div>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="ace_seo_desc"><?php _e( 'Meta Description', 'ace-crawl-enhancer' ); ?></label>
            </th>
            <td>
                <textarea name="ace_seo_desc" id="ace_seo_desc" rows="3" class="large-text"><?php echo esc_textarea( $desc ); ?></textarea>
                <p class="description">
                    <?php _e( 'Custom meta description for this term. Leave blank to use term description.', 'ace-crawl-enhancer' ); ?>
                    <br>
                    <strong><?php _e( 'Available variables (click to insert):', 'ace-crawl-enhancer' ); ?></strong>
                    <span class="ace-template-variables">
                        <code class="ace-variable-tag" data-variable="{category_name}" data-target="ace_seo_desc" title="Click to insert">{category_name}</code>
                        <code class="ace-variable-tag" data-variable="{tag_name}" data-target="ace_seo_desc" title="Click to insert">{tag_name}</code>
                        <code class="ace-variable-tag" data-variable="{archive_title}" data-target="ace_seo_desc" title="Click to insert">{archive_title}</code>
                        <code class="ace-variable-tag" data-variable="{site_name}" data-target="ace_seo_desc" title="Click to insert">{site_name}</code>
                    </span>
                </p>
                <div class="ace-desc-counter">
                    <span class="ace-counter-text"><?php echo strlen( $desc ); ?> characters</span>
                    <div class="ace-counter-bar">
                        <div class="ace-counter-progress"></div>
                    </div>
                </div>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="ace_seo_canonical"><?php _e( 'Canonical URL', 'ace-crawl-enhancer' ); ?></label>
            </th>
            <td>
                <input type="url" name="ace_seo_canonical" id="ace_seo_canonical" value="<?php echo esc_attr( $canonical ); ?>" class="regular-text" />
                <p class="description"><?php _e( 'Custom canonical URL for this term. Leave blank to use default.', 'ace-crawl-enhancer' ); ?></p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="ace_seo_focuskw"><?php _e( 'Focus Keyword', 'ace-crawl-enhancer' ); ?></label>
            </th>
            <td>
                <input type="text" name="ace_seo_focuskw" id="ace_seo_focuskw" value="<?php echo esc_attr( $focuskw ); ?>" class="regular-text" />
                <p class="description"><?php _e( 'Primary keyword to optimize this term for.', 'ace-crawl-enhancer' ); ?></p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="ace_seo_noindex"><?php _e( 'Search Engine Visibility', 'ace-crawl-enhancer' ); ?></label>
            </th>
            <td>
                <select name="ace_seo_noindex" id="ace_seo_noindex">
                    <option value="default" <?php selected( $noindex, 'default' ); ?>><?php _e( 'Default (follow site settings)', 'ace-crawl-enhancer' ); ?></option>
                    <option value="index" <?php selected( $noindex, 'index' ); ?>><?php _e( 'Index (visible in search results)', 'ace-crawl-enhancer' ); ?></option>
                    <option value="noindex" <?php selected( $noindex, 'noindex' ); ?>><?php _e( 'No Index (hidden from search results)', 'ace-crawl-enhancer' ); ?></option>
                </select>
                <p class="description"><?php _e( 'Control how search engines handle this term.', 'ace-crawl-enhancer' ); ?></p>
            </td>
        </tr>
        
        <style>
        .ace-seo-taxonomy-section th {
            padding: 20px 0 10px 0;
        }
        .ace-counter-text {
            font-size: 12px;
            color: #666;
        }
        .ace-counter-bar {
            width: 100%;
            height: 4px;
            background: #ddd;
            border-radius: 2px;
            margin-top: 5px;
        }
        .ace-counter-progress {
            height: 100%;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        .ace-counter-progress.good { background: #46b450; }
        .ace-counter-progress.warning { background: #ffb900; }
        .ace-counter-progress.error { background: #dc3232; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Character counters for taxonomy fields
            function updateCounter(field, counterClass, optimal, max) {
                var length = $(field).val().length;
                var counter = $(counterClass);
                var progress = counter.find('.ace-counter-progress');
                
                counter.find('.ace-counter-text').text(length + ' characters');
                
                var percentage = (length / max) * 100;
                progress.css('width', Math.min(percentage, 100) + '%');
                
                progress.removeClass('good warning error');
                if (length >= optimal && length <= max) {
                    progress.addClass('good');
                } else if (length > 0 && (length < optimal || length > max)) {
                    progress.addClass('warning');
                } else if (length > max * 1.2) {
                    progress.addClass('error');
                }
            }
            
            // Title counter (optimal: 50-60, max: 70)
            $('#ace_seo_title').on('input', function() {
                updateCounter(this, '.ace-title-counter', 50, 70);
            }).trigger('input');
            
            // Description counter (optimal: 140-160, max: 170)
            $('#ace_seo_desc').on('input', function() {
                updateCounter(this, '.ace-desc-counter', 140, 170);
            }).trigger('input');
        });
        </script>
        <?php
    }
    
    /**
     * Save taxonomy SEO fields
     */
    public function save_taxonomy_fields( $term_id ) {
        // Verify nonce
        if ( ! isset( $_POST['ace_seo_taxonomy_nonce'] ) || 
             ! wp_verify_nonce( $_POST['ace_seo_taxonomy_nonce'], 'ace_seo_taxonomy_save' ) ) {
            return;
        }
        
        // Check user permissions
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }
        
        $fields = array( 'title', 'desc', 'canonical', 'focuskw', 'noindex' );
        $fields_saved = 0;
        
        foreach ( $fields as $field ) {
            $key = 'ace_seo_' . $field;
            
            if ( isset( $_POST[$key] ) ) {
                $value = sanitize_text_field( $_POST[$key] );
                
                if ( $field === 'desc' ) {
                    $value = sanitize_textarea_field( $_POST[$key] );
                } elseif ( $field === 'canonical' ) {
                    $value = esc_url_raw( $_POST[$key] );
                }
                
                if ( ! empty( $value ) ) {
                    update_term_meta( $term_id, ACE_SEO_META_PREFIX . $field, $value );
                    $fields_saved++;
                } else {
                    delete_term_meta( $term_id, ACE_SEO_META_PREFIX . $field );
                }
            }
        }
        
        // Always mark as migrated when form is saved (even if all fields are empty)
        // This prevents the Yoast notice from showing again
        update_term_meta( $term_id, '_ace_seo_taxonomy_migration_check', time() );
    }
}

// Initialize the metabox class
new AceSEOMetabox();
