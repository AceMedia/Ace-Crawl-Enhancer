<?php
/**
 * Ace SEO Schema Class - Advanced Schema.org markup
 */

if (!defined('ABSPATH')) {
    exit;
}

class AceSeoSchema {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_head', [$this, 'output_organization_schema'], 15);
        add_action('wp_head', [$this, 'output_local_business_schema'], 16);
        add_filter('ace_seo_schema_article', [$this, 'enhance_article_schema'], 10, 2);
    }
    
    public function output_organization_schema() {
        if (!is_front_page()) {
            return;
        }
        
        $options = get_option('ace_seo_options', []);
        $organization = $this->get_organization_data();
        
        if (!empty($organization)) {
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode($organization, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "\n" . '</script>' . "\n";
        }
    }
    
    public function output_local_business_schema() {
        if (!is_front_page()) {
            return;
        }
        
        $local_business = $this->get_local_business_data();
        
        if (!empty($local_business)) {
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode($local_business, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "\n" . '</script>' . "\n";
        }
    }
    
    private function get_organization_data() {
        $options = get_option('ace_seo_options', []);
        
        $organization = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => get_bloginfo('name'),
            'url' => home_url(),
            'description' => get_bloginfo('description'),
        ];
        
        // Add logo if available
        if (!empty($options['social']['default_image'])) {
            $organization['logo'] = [
                '@type' => 'ImageObject',
                'url' => $options['social']['default_image'],
            ];
        }
        
        // Add social media profiles
        $social_profiles = [];
        
        if (!empty($options['social']['facebook_page'])) {
            $social_profiles[] = $options['social']['facebook_page'];
        }
        
        if (!empty($options['social']['twitter_username'])) {
            $twitter_url = 'https://twitter.com/' . ltrim($options['social']['twitter_username'], '@');
            $social_profiles[] = $twitter_url;
        }
        
        if (!empty($options['social']['linkedin_page'])) {
            $social_profiles[] = $options['social']['linkedin_page'];
        }
        
        if (!empty($options['social']['instagram_page'])) {
            $social_profiles[] = $options['social']['instagram_page'];
        }
        
        if (!empty($social_profiles)) {
            $organization['sameAs'] = $social_profiles;
        }
        
        return $organization;
    }
    
    private function get_local_business_data() {
        $options = get_option('ace_seo_options', []);
        $local = $options['local'] ?? [];
        
        // Only output if local business data is available
        if (empty($local['business_type']) || empty($local['address'])) {
            return null;
        }
        
        $business = [
            '@context' => 'https://schema.org',
            '@type' => $local['business_type'] ?? 'LocalBusiness',
            'name' => get_bloginfo('name'),
            'url' => home_url(),
            'description' => get_bloginfo('description'),
        ];
        
        // Add address
        if (!empty($local['address'])) {
            $address = [
                '@type' => 'PostalAddress',
            ];
            
            if (!empty($local['street_address'])) {
                $address['streetAddress'] = $local['street_address'];
            }
            
            if (!empty($local['city'])) {
                $address['addressLocality'] = $local['city'];
            }
            
            if (!empty($local['state'])) {
                $address['addressRegion'] = $local['state'];
            }
            
            if (!empty($local['postal_code'])) {
                $address['postalCode'] = $local['postal_code'];
            }
            
            if (!empty($local['country'])) {
                $address['addressCountry'] = $local['country'];
            }
            
            $business['address'] = $address;
        }
        
        // Add contact information
        if (!empty($local['phone'])) {
            $business['telephone'] = $local['phone'];
        }
        
        if (!empty($local['email'])) {
            $business['email'] = $local['email'];
        }
        
        // Add geo coordinates
        if (!empty($local['latitude']) && !empty($local['longitude'])) {
            $business['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $local['latitude'],
                'longitude' => $local['longitude'],
            ];
        }
        
        // Add opening hours
        if (!empty($local['opening_hours'])) {
            $business['openingHours'] = $local['opening_hours'];
        }
        
        // Add price range
        if (!empty($local['price_range'])) {
            $business['priceRange'] = $local['price_range'];
        }
        
        return $business;
    }
    
    public function enhance_article_schema($schema, $post) {
        // Add additional article schema properties
        
        // Add word count
        $content = strip_tags($post->post_content);
        $word_count = str_word_count($content);
        
        if ($word_count > 0) {
            $schema['wordCount'] = $word_count;
        }
        
        // Add reading time estimate
        $reading_time = max(1, round($word_count / 200)); // Assume 200 words per minute
        $schema['timeRequired'] = 'PT' . $reading_time . 'M';
        
        // Add article section (category)
        $categories = get_the_category($post->ID);
        if (!empty($categories)) {
            $schema['articleSection'] = $categories[0]->name;
        }
        
        // Add tags as keywords
        $tags = get_the_tags($post->ID);
        if (!empty($tags)) {
            $keywords = array_map(function($tag) {
                return $tag->name;
            }, $tags);
            $schema['keywords'] = implode(', ', $keywords);
        }
        
        // Add main entity of page
        $focus_keyword = AceCrawlEnhancer::get_meta_value($post->ID, 'focuskw');
        if (!empty($focus_keyword)) {
            $schema['mainEntityOfPage'] = [
                '@type' => 'WebPage',
                '@id' => get_permalink($post),
                'name' => get_the_title($post),
                'description' => $this->get_meta_description($post),
            ];
        }
        
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
    
    /**
     * Generate FAQ schema for posts with FAQ blocks
     */
    public function generate_faq_schema($post_id) {
        if (!has_blocks($post_id)) {
            return null;
        }
        
        $content = get_post_field('post_content', $post_id);
        $blocks = parse_blocks($content);
        
        $faq_items = [];
        
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/heading' && 
                isset($block['attrs']['level']) && 
                $block['attrs']['level'] >= 3) {
                
                // Look for question-like headings
                $question = strip_tags($block['innerHTML']);
                if (preg_match('/\?$/', $question)) {
                    // Find the next paragraph as the answer
                    $answer = $this->find_next_paragraph($blocks, $block);
                    
                    if (!empty($answer)) {
                        $faq_items[] = [
                            '@type' => 'Question',
                            'name' => $question,
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => $answer,
                            ],
                        ];
                    }
                }
            }
        }
        
        if (empty($faq_items)) {
            return null;
        }
        
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faq_items,
        ];
    }
    
    private function find_next_paragraph($blocks, $current_block) {
        $found_current = false;
        
        foreach ($blocks as $block) {
            if ($found_current && $block['blockName'] === 'core/paragraph') {
                return strip_tags($block['innerHTML']);
            }
            
            if ($block === $current_block) {
                $found_current = true;
            }
        }
        
        return '';
    }
    
    /**
     * Generate product schema for WooCommerce integration
     */
    public function generate_product_schema($product_id) {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return null;
        }
        
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->get_name(),
            'description' => $product->get_short_description() ?: $product->get_description(),
            'sku' => $product->get_sku(),
            'url' => get_permalink($product_id),
        ];
        
        // Add image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'large');
            $schema['image'] = $image_url;
        }
        
        // Add brand if available
        $brand_terms = get_the_terms($product_id, 'product_brand');
        if (!empty($brand_terms)) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $brand_terms[0]->name,
            ];
        }
        
        // Add offers
        $schema['offers'] = [
            '@type' => 'Offer',
            'price' => $product->get_price(),
            'priceCurrency' => get_woocommerce_currency(),
            'availability' => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => get_permalink($product_id),
        ];
        
        // Add reviews if available
        $reviews = get_comments([
            'post_id' => $product_id,
            'status' => 'approve',
            'type' => 'review',
            'number' => 5,
        ]);
        
        if (!empty($reviews)) {
            $review_schema = [];
            $total_rating = 0;
            
            foreach ($reviews as $review) {
                $rating = get_comment_meta($review->comment_ID, 'rating', true);
                
                if ($rating) {
                    $review_schema[] = [
                        '@type' => 'Review',
                        'author' => [
                            '@type' => 'Person',
                            'name' => $review->comment_author,
                        ],
                        'reviewRating' => [
                            '@type' => 'Rating',
                            'ratingValue' => $rating,
                            'bestRating' => '5',
                        ],
                        'reviewBody' => $review->comment_content,
                    ];
                    
                    $total_rating += $rating;
                }
            }
            
            if (!empty($review_schema)) {
                $schema['review'] = $review_schema;
                
                // Add aggregate rating
                $average_rating = $total_rating / count($review_schema);
                $schema['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => round($average_rating, 1),
                    'reviewCount' => count($review_schema),
                    'bestRating' => '5',
                ];
            }
        }
        
        return $schema;
    }
}
