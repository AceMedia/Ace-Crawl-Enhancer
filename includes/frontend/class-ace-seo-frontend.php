<?php
/**
 * Ace SEO Frontend Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class AceSeoFrontend {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_head', [$this, 'output_schema_markup'], 20);
        add_action('wp_head', [$this, 'output_additional_meta'], 25);
        add_action('wp_footer', [$this, 'output_structured_data'], 20);
        
        // Remove other SEO plugin outputs to avoid conflicts
        add_action('init', [$this, 'remove_conflicting_outputs'], 1);
    }
    
    public function remove_conflicting_outputs() {
        // Remove Yoast SEO frontend if active
        if (class_exists('WPSEO_Frontend')) {
            remove_action('wp_head', ['WPSEO_Frontend', 'head'], 1);
        }
        
        // Remove other common SEO plugins
        remove_action('wp_head', 'rel_canonical');
    }
    
    public function output_schema_markup() {
        if (is_singular()) {
            global $post;
            
            $schema = $this->generate_article_schema($post);
            
            if (!empty($schema)) {
                echo '<script type="application/ld+json">' . "\n";
                echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                echo "\n" . '</script>' . "\n";
            }
        } elseif (is_home() || is_front_page()) {
            $schema = $this->generate_website_schema();
            
            if (!empty($schema)) {
                echo '<script type="application/ld+json">' . "\n";
                echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                echo "\n" . '</script>' . "\n";
            }
        }
    }
    
    private function generate_article_schema($post) {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => get_the_title($post),
            'description' => $this->get_meta_description($post),
            'url' => get_permalink($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', $post->post_author),
                'url' => get_author_posts_url($post->post_author),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
                'url' => home_url(),
            ],
        ];
        
        // Add image if available
        if (has_post_thumbnail($post)) {
            $image_url = get_the_post_thumbnail_url($post, 'large');
            $schema['image'] = [
                '@type' => 'ImageObject',
                'url' => $image_url,
            ];
        }
        
        // Add organization logo if available
        $options = get_option('ace_seo_options', []);
        if (!empty($options['social']['default_image'])) {
            $schema['publisher']['logo'] = [
                '@type' => 'ImageObject',
                'url' => $options['social']['default_image'],
            ];
        }
        
        return $schema;
    }
    
    private function generate_website_schema() {
        $options = get_option('ace_seo_options', []);
        
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url(),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => home_url('/?s={search_term_string}'),
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
        
        return $schema;
    }
    
    private function get_meta_description($post) {
        $meta_desc = AceCrawlEnhancer::get_meta_value($post->ID, 'metadesc');
        
        if (empty($meta_desc)) {
            if (!empty($post->post_excerpt)) {
                return wp_trim_words($post->post_excerpt, 25);
            } else {
                return wp_trim_words(strip_tags($post->post_content), 25);
            }
        }
        
        return $meta_desc;
    }
    
    public function output_additional_meta() {
        // Add generator meta
        echo '<meta name="generator" content="Ace SEO ' . ACE_SEO_VERSION . '">' . "\n";
        
        // Add site verification meta if set
        $options = get_option('ace_seo_options', []);
        
        if (!empty($options['webmaster']['google_verify'])) {
            echo '<meta name="google-site-verification" content="' . esc_attr($options['webmaster']['google_verify']) . '">' . "\n";
        }
        
        if (!empty($options['webmaster']['bing_verify'])) {
            echo '<meta name="msvalidate.01" content="' . esc_attr($options['webmaster']['bing_verify']) . '">' . "\n";
        }
        
        // Add Facebook App ID if set
        if (!empty($options['social']['facebook_app_id'])) {
            echo '<meta property="fb:app_id" content="' . esc_attr($options['social']['facebook_app_id']) . '">' . "\n";
        }
        
        // Add Twitter site if set
        if (!empty($options['social']['twitter_username'])) {
            echo '<meta name="twitter:site" content="' . esc_attr($options['social']['twitter_username']) . '">' . "\n";
        }
    }
    
    public function output_structured_data() {
        // Output breadcrumb structured data if enabled
        $options = get_option('ace_seo_options', []);
        
        if (!empty($options['advanced']['breadcrumbs']) && is_singular()) {
            $breadcrumbs = $this->generate_breadcrumb_schema();
            
            if (!empty($breadcrumbs)) {
                echo '<script type="application/ld+json">' . "\n";
                echo wp_json_encode($breadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                echo "\n" . '</script>' . "\n";
            }
        }
    }
    
    private function generate_breadcrumb_schema() {
        if (!is_singular()) {
            return null;
        }
        
        global $post;
        
        $breadcrumbs = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => []
        ];
        
        $position = 1;
        
        // Home
        $breadcrumbs['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => 'Home',
            'item' => home_url(),
        ];
        
        // Post type archive (if not post)
        if ($post->post_type !== 'post' && $post->post_type !== 'page') {
            $post_type_object = get_post_type_object($post->post_type);
            if ($post_type_object && $post_type_object->has_archive) {
                $breadcrumbs['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $post_type_object->labels->name,
                    'item' => get_post_type_archive_link($post->post_type),
                ];
            }
        }
        
        // Categories (for posts)
        if ($post->post_type === 'post') {
            $categories = get_the_category($post->ID);
            if (!empty($categories)) {
                $category = $categories[0];
                $breadcrumbs['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $category->name,
                    'item' => get_category_link($category->term_id),
                ];
            }
        }
        
        // Current page
        $breadcrumb_title = AceCrawlEnhancer::get_meta_value($post->ID, 'bctitle');
        $breadcrumbs['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => !empty($breadcrumb_title) ? $breadcrumb_title : get_the_title($post),
            'item' => get_permalink($post),
        ];
        
        return $breadcrumbs;
    }
}
