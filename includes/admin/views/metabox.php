<?php
/**
 * Metabox template with modern Yoast-like interface
 */

// Get current post values
$post_id = $post->ID;
$focus_keyword = AceCrawlEnhancer::get_meta_value($post_id, 'focuskw');
$seo_title = AceCrawlEnhancer::get_meta_value($post_id, 'title');
$meta_description = AceCrawlEnhancer::get_meta_value($post_id, 'metadesc');
$canonical = AceCrawlEnhancer::get_meta_value($post_id, 'canonical');
$is_cornerstone = AceCrawlEnhancer::get_meta_value($post_id, 'is_cornerstone') === 'true';

// Social meta
$og_title = AceCrawlEnhancer::get_meta_value($post_id, 'opengraph-title');
$og_description = AceCrawlEnhancer::get_meta_value($post_id, 'opengraph-description');
$og_image = AceCrawlEnhancer::get_meta_value($post_id, 'opengraph-image');
$twitter_title = AceCrawlEnhancer::get_meta_value($post_id, 'twitter-title');
$twitter_description = AceCrawlEnhancer::get_meta_value($post_id, 'twitter-description');
$twitter_image = AceCrawlEnhancer::get_meta_value($post_id, 'twitter-image');

// Advanced settings
$noindex = AceCrawlEnhancer::get_meta_value($post_id, 'meta-robots-noindex');
$nofollow = AceCrawlEnhancer::get_meta_value($post_id, 'meta-robots-nofollow');
$robots_adv = AceCrawlEnhancer::get_meta_value($post_id, 'meta-robots-adv');
$breadcrumb_title = AceCrawlEnhancer::get_meta_value($post_id, 'bctitle');
?>

<div id="ace-seo-metabox" class="ace-seo-metabox">
    <!-- Tab Navigation -->
    <div class="ace-seo-tabs">
        <ul class="ace-seo-tab-nav">
            <li class="ace-seo-tab-item active" data-tab="general">
                <span class="ace-seo-tab-icon">📊</span>
                <span class="ace-seo-tab-label">SEO</span>
                <span class="ace-seo-tab-score" id="ace-seo-score">—</span>
            </li>
            <li class="ace-seo-tab-item" data-tab="readability">
                <span class="ace-seo-tab-icon">📖</span>
                <span class="ace-seo-tab-label">Readability</span>
                <span class="ace-seo-tab-score" id="ace-readability-score">—</span>
            </li>
            <li class="ace-seo-tab-item" data-tab="social">
                <span class="ace-seo-tab-icon">📱</span>
                <span class="ace-seo-tab-label">Social</span>
            </li>
            <li class="ace-seo-tab-item" data-tab="advanced">
                <span class="ace-seo-tab-icon">⚙️</span>
                <span class="ace-seo-tab-label">Advanced</span>
            </li>
        </ul>
    </div>

    <!-- SEO Tab -->
    <div class="ace-seo-tab-content active" id="tab-general">
        <!-- Focus Keyword -->
        <div class="ace-seo-field">
            <label for="yoast_wpseo_focuskw" class="ace-seo-label">
                <strong>Focus Keyword</strong>
                <span class="ace-seo-help">?</span>
            </label>
            <input 
                type="text" 
                id="yoast_wpseo_focuskw" 
                name="yoast_wpseo_focuskw" 
                value="<?php echo esc_attr($focus_keyword); ?>"
                class="ace-seo-input"
                placeholder="Enter your focus keyword"
            >
            <p class="ace-seo-description">
                The main keyword you want this content to rank for in search engines.
            </p>
        </div>

        <!-- Google Preview -->
        <div class="ace-seo-preview-section">
            <h4>Google Preview</h4>
            <div class="ace-seo-google-preview" id="ace-seo-google-preview">
                <div class="ace-seo-preview-url"><?php echo esc_html(get_site_url()); ?> › <?php echo esc_html($post->post_name); ?></div>
                <div class="ace-seo-preview-title" id="preview-title">
                    <?php echo esc_html($seo_title ?: $post->post_title); ?>
                </div>
                <div class="ace-seo-preview-description" id="preview-description">
                    <?php echo esc_html($meta_description ?: wp_trim_words(strip_tags($post->post_content), 25)); ?>
                </div>
            </div>
        </div>

        <!-- SEO Title -->
        <div class="ace-seo-field">
            <label for="yoast_wpseo_title" class="ace-seo-label">
                <strong>SEO Title</strong>
                <span class="ace-seo-counter" id="title-counter">0 / 60</span>
            </label>
            <input 
                type="text" 
                id="yoast_wpseo_title" 
                name="yoast_wpseo_title" 
                value="<?php echo esc_attr($seo_title); ?>"
                class="ace-seo-input"
                placeholder="<?php echo esc_attr($post->post_title); ?>"
                maxlength="60"
            >
            <div class="ace-seo-progress-bar">
                <div class="ace-seo-progress-fill" id="title-progress"></div>
            </div>
            <p class="ace-seo-description">
                The title that will appear in search engine results. Keep it under 60 characters.
            </p>
        </div>

        <!-- Meta Description -->
        <div class="ace-seo-field">
            <label for="yoast_wpseo_metadesc" class="ace-seo-label">
                <strong>Meta Description</strong>
                <span class="ace-seo-counter" id="description-counter">0 / 160</span>
            </label>
            <textarea 
                id="yoast_wpseo_metadesc" 
                name="yoast_wpseo_metadesc" 
                class="ace-seo-textarea"
                placeholder="Enter a compelling description for search engines"
                maxlength="160"
                rows="3"
            ><?php echo esc_textarea($meta_description); ?></textarea>
            <div class="ace-seo-progress-bar">
                <div class="ace-seo-progress-fill" id="description-progress"></div>
            </div>
            <p class="ace-seo-description">
                The description that will appear under your title in search results. Aim for 120-160 characters.
            </p>
        </div>

        <!-- Cornerstone Content -->
        <div class="ace-seo-field">
            <label class="ace-seo-checkbox-label">
                <input 
                    type="checkbox" 
                    id="yoast_wpseo_is_cornerstone" 
                    name="yoast_wpseo_is_cornerstone" 
                    value="on"
                    <?php checked($is_cornerstone); ?>
                >
                <span class="ace-seo-checkmark"></span>
                <strong>Cornerstone Content</strong>
            </label>
            <p class="ace-seo-description">
                Mark this as cornerstone content if it's one of your most important articles.
            </p>
        </div>

        <!-- SEO Analysis -->
        <div class="ace-seo-analysis" id="ace-seo-analysis">
            <h4>SEO Analysis</h4>
            <div class="ace-seo-analysis-loading">
                <span class="ace-seo-spinner"></span>
                Analyzing content...
            </div>
            <div class="ace-seo-analysis-results" id="ace-seo-analysis-results" style="display: none;">
                <!-- Results will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Readability Tab -->
    <div class="ace-seo-tab-content" id="tab-readability">
        <div class="ace-seo-readability-analysis" id="ace-readability-analysis">
            <h4>Readability Analysis</h4>
            <div class="ace-seo-analysis-loading">
                <span class="ace-seo-spinner"></span>
                Analyzing readability...
            </div>
            <div class="ace-seo-analysis-results" id="ace-readability-analysis-results" style="display: none;">
                <!-- Results will be populated by JavaScript -->
            </div>
        </div>

        <div class="ace-seo-readability-tips">
            <h4>Readability Tips</h4>
            <ul>
                <li>Use shorter sentences (aim for 15-20 words)</li>
                <li>Break up long paragraphs</li>
                <li>Use subheadings to structure your content</li>
                <li>Write in active voice when possible</li>
                <li>Use simple, clear language</li>
            </ul>
        </div>
    </div>

    <!-- Social Tab -->
    <div class="ace-seo-tab-content" id="tab-social">
        <!-- Facebook/Open Graph -->
        <div class="ace-seo-social-section">
            <h4>Facebook</h4>
            
            <!-- Facebook Preview -->
            <div class="ace-seo-social-preview ace-seo-facebook-preview">
                <div class="ace-seo-social-preview-image" id="facebook-preview-image">
                    <?php if ($og_image): ?>
                        <img src="<?php echo esc_url($og_image); ?>" alt="Facebook preview">
                    <?php else: ?>
                        <div class="ace-seo-placeholder-image">📷</div>
                    <?php endif; ?>
                </div>
                <div class="ace-seo-social-preview-content">
                    <div class="ace-seo-social-preview-title" id="facebook-preview-title">
                        <?php echo esc_html($og_title ?: $seo_title ?: $post->post_title); ?>
                    </div>
                    <div class="ace-seo-social-preview-description" id="facebook-preview-description">
                        <?php echo esc_html($og_description ?: $meta_description ?: wp_trim_words(strip_tags($post->post_content), 25)); ?>
                    </div>
                    <div class="ace-seo-social-preview-url"><?php echo esc_html(parse_url(get_site_url(), PHP_URL_HOST)); ?></div>
                </div>
            </div>

            <div class="ace-seo-field">
                <label for="yoast_wpseo_opengraph-title" class="ace-seo-label">
                    <strong>Facebook Title</strong>
                    <span class="ace-seo-counter" id="og-title-counter">0 / 95</span>
                </label>
                <input 
                    type="text" 
                    id="yoast_wpseo_opengraph-title" 
                    name="yoast_wpseo_opengraph-title" 
                    value="<?php echo esc_attr($og_title); ?>"
                    class="ace-seo-input"
                    placeholder="<?php echo esc_attr($seo_title ?: $post->post_title); ?>"
                    maxlength="95"
                >
            </div>

            <div class="ace-seo-field">
                <label for="yoast_wpseo_opengraph-description" class="ace-seo-label">
                    <strong>Facebook Description</strong>
                    <span class="ace-seo-counter" id="og-description-counter">0 / 300</span>
                </label>
                <textarea 
                    id="yoast_wpseo_opengraph-description" 
                    name="yoast_wpseo_opengraph-description" 
                    class="ace-seo-textarea"
                    placeholder="<?php echo esc_attr($meta_description ?: wp_trim_words(strip_tags($post->post_content), 25)); ?>"
                    maxlength="300"
                    rows="3"
                ><?php echo esc_textarea($og_description); ?></textarea>
            </div>

            <div class="ace-seo-field">
                <label for="yoast_wpseo_opengraph-image" class="ace-seo-label">
                    <strong>Facebook Image</strong>
                </label>
                <div class="ace-seo-image-field">
                    <input 
                        type="url" 
                        id="yoast_wpseo_opengraph-image" 
                        name="yoast_wpseo_opengraph-image" 
                        value="<?php echo esc_attr($og_image); ?>"
                        class="ace-seo-input"
                        placeholder="Enter image URL"
                    >
                    <button type="button" class="ace-seo-button ace-seo-image-button" data-target="yoast_wpseo_opengraph-image">
                        Select Image
                    </button>
                </div>
            </div>
        </div>

        <!-- Twitter -->
        <div class="ace-seo-social-section">
            <h4>Twitter</h4>
            
            <!-- Twitter Preview -->
            <div class="ace-seo-social-preview ace-seo-twitter-preview">
                <div class="ace-seo-social-preview-image" id="twitter-preview-image">
                    <?php if ($twitter_image ?: $og_image): ?>
                        <img src="<?php echo esc_url($twitter_image ?: $og_image); ?>" alt="Twitter preview">
                    <?php else: ?>
                        <div class="ace-seo-placeholder-image">📷</div>
                    <?php endif; ?>
                </div>
                <div class="ace-seo-social-preview-content">
                    <div class="ace-seo-social-preview-title" id="twitter-preview-title">
                        <?php echo esc_html($twitter_title ?: $og_title ?: $seo_title ?: $post->post_title); ?>
                    </div>
                    <div class="ace-seo-social-preview-description" id="twitter-preview-description">
                        <?php echo esc_html($twitter_description ?: $og_description ?: $meta_description ?: wp_trim_words(strip_tags($post->post_content), 25)); ?>
                    </div>
                    <div class="ace-seo-social-preview-url"><?php echo esc_html(parse_url(get_site_url(), PHP_URL_HOST)); ?></div>
                </div>
            </div>

            <div class="ace-seo-field">
                <label for="yoast_wpseo_twitter-title" class="ace-seo-label">
                    <strong>Twitter Title</strong>
                    <span class="ace-seo-counter" id="twitter-title-counter">0 / 70</span>
                </label>
                <input 
                    type="text" 
                    id="yoast_wpseo_twitter-title" 
                    name="yoast_wpseo_twitter-title" 
                    value="<?php echo esc_attr($twitter_title); ?>"
                    class="ace-seo-input"
                    placeholder="<?php echo esc_attr($og_title ?: $seo_title ?: $post->post_title); ?>"
                    maxlength="70"
                >
            </div>

            <div class="ace-seo-field">
                <label for="yoast_wpseo_twitter-description" class="ace-seo-label">
                    <strong>Twitter Description</strong>
                    <span class="ace-seo-counter" id="twitter-description-counter">0 / 200</span>
                </label>
                <textarea 
                    id="yoast_wpseo_twitter-description" 
                    name="yoast_wpseo_twitter-description" 
                    class="ace-seo-textarea"
                    placeholder="<?php echo esc_attr($og_description ?: $meta_description ?: wp_trim_words(strip_tags($post->post_content), 25)); ?>"
                    maxlength="200"
                    rows="3"
                ><?php echo esc_textarea($twitter_description); ?></textarea>
            </div>

            <div class="ace-seo-field">
                <label for="yoast_wpseo_twitter-image" class="ace-seo-label">
                    <strong>Twitter Image</strong>
                </label>
                <div class="ace-seo-image-field">
                    <input 
                        type="url" 
                        id="yoast_wpseo_twitter-image" 
                        name="yoast_wpseo_twitter-image" 
                        value="<?php echo esc_attr($twitter_image); ?>"
                        class="ace-seo-input"
                        placeholder="Enter image URL or leave empty to use Facebook image"
                    >
                    <button type="button" class="ace-seo-button ace-seo-image-button" data-target="yoast_wpseo_twitter-image">
                        Select Image
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Advanced Tab -->
    <div class="ace-seo-tab-content" id="tab-advanced">
        <!-- Search Engine Visibility -->
        <div class="ace-seo-field">
            <label for="yoast_wpseo_meta-robots-noindex" class="ace-seo-label">
                <strong>Search Engine Visibility</strong>
            </label>
            <select id="yoast_wpseo_meta-robots-noindex" name="yoast_wpseo_meta-robots-noindex" class="ace-seo-select">
                <option value="0" <?php selected($noindex, '0'); ?>>Default (Index)</option>
                <option value="2" <?php selected($noindex, '2'); ?>>Yes (Index)</option>
                <option value="1" <?php selected($noindex, '1'); ?>>No (No-index)</option>
            </select>
            <p class="ace-seo-description">
                Allow search engines to show this content in search results?
            </p>
        </div>

        <!-- Follow Links -->
        <div class="ace-seo-field">
            <label for="yoast_wpseo_meta-robots-nofollow" class="ace-seo-label">
                <strong>Follow Links</strong>
            </label>
            <select id="yoast_wpseo_meta-robots-nofollow" name="yoast_wpseo_meta-robots-nofollow" class="ace-seo-select">
                <option value="0" <?php selected($nofollow, '0'); ?>>Yes (Follow)</option>
                <option value="1" <?php selected($nofollow, '1'); ?>>No (No-follow)</option>
            </select>
            <p class="ace-seo-description">
                Should search engines follow links on this content?
            </p>
        </div>

        <!-- Advanced Meta Robots -->
        <div class="ace-seo-field">
            <label class="ace-seo-label">
                <strong>Advanced Meta Robots</strong>
            </label>
            <?php
            $robots_adv_array = !empty($robots_adv) ? explode(',', $robots_adv) : [];
            $robots_options = [
                'noimageindex' => 'No Image Index',
                'noarchive' => 'No Archive',
                'nosnippet' => 'No Snippet',
            ];
            ?>
            <div class="ace-seo-checkbox-group">
                <?php foreach ($robots_options as $value => $label): ?>
                    <label class="ace-seo-checkbox-label">
                        <input 
                            type="checkbox" 
                            name="yoast_wpseo_meta-robots-adv[]" 
                            value="<?php echo esc_attr($value); ?>"
                            <?php checked(in_array($value, $robots_adv_array)); ?>
                        >
                        <span class="ace-seo-checkmark"></span>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="ace-seo-description">
                Advanced robots meta settings for search engines.
            </p>
        </div>

        <!-- Canonical URL -->
        <div class="ace-seo-field">
            <label for="yoast_wpseo_canonical" class="ace-seo-label">
                <strong>Canonical URL</strong>
            </label>
            <input 
                type="url" 
                id="yoast_wpseo_canonical" 
                name="yoast_wpseo_canonical" 
                value="<?php echo esc_attr($canonical); ?>"
                class="ace-seo-input"
                placeholder="<?php echo esc_attr(get_permalink($post)); ?>"
            >
            <p class="ace-seo-description">
                The canonical URL for this content. Leave empty to use the default permalink.
            </p>
        </div>

        <!-- Breadcrumbs Title -->
        <div class="ace-seo-field">
            <label for="yoast_wpseo_bctitle" class="ace-seo-label">
                <strong>Breadcrumbs Title</strong>
            </label>
            <input 
                type="text" 
                id="yoast_wpseo_bctitle" 
                name="yoast_wpseo_bctitle" 
                value="<?php echo esc_attr($breadcrumb_title); ?>"
                class="ace-seo-input"
                placeholder="<?php echo esc_attr($post->post_title); ?>"
            >
            <p class="ace-seo-description">
                Title to use in breadcrumb navigation. Leave empty to use the post title.
            </p>
        </div>
    </div>
</div>

<!-- Hidden fields for scores -->
<input type="hidden" id="yoast_wpseo_linkdex" name="yoast_wpseo_linkdex" value="<?php echo esc_attr(AceCrawlEnhancer::get_meta_value($post_id, 'linkdex')); ?>">
<input type="hidden" id="yoast_wpseo_content_score" name="yoast_wpseo_content_score" value="<?php echo esc_attr(AceCrawlEnhancer::get_meta_value($post_id, 'content_score')); ?>">
