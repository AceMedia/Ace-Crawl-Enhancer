<?php
/**
 * XML Sitemap Generator
 * Generates WordPress XML sitemaps following Google standards
 * 
 * @package AceCrawlEnhancer
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSEOSitemap {
    
    /**
     * Initialize sitemap functionality
     */
    public function __construct() {
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_action( 'template_redirect', array( $this, 'handle_sitemap_request' ) );
        add_action( 'ace_seo_generate_sitemap', array( $this, 'generate_all_sitemaps' ) );
    }
    
    /**
     * Add rewrite rules for sitemaps
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( 
            '^sitemap\.xml$', 
            'index.php?ace_seo_sitemap=index', 
            'top' 
        );
        add_rewrite_rule( 
            '^sitemap-([^/]+)\.xml$', 
            'index.php?ace_seo_sitemap=$matches[1]', 
            'top' 
        );
    }
    
    /**
     * Handle sitemap requests
     */
    public function handle_sitemap_request() {
        $sitemap = get_query_var( 'ace_seo_sitemap' );
        
        if ( ! $sitemap ) {
            return;
        }
        
        // Set proper headers
        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );
        
        switch ( $sitemap ) {
            case 'index':
                $this->output_sitemap_index();
                break;
            case 'posts':
                $this->output_posts_sitemap();
                break;
            case 'pages':
                $this->output_pages_sitemap();
                break;
            case 'categories':
                $this->output_categories_sitemap();
                break;
            case 'tags':
                $this->output_tags_sitemap();
                break;
            default:
                // Check for custom post types
                if ( post_type_exists( $sitemap ) ) {
                    $this->output_post_type_sitemap( $sitemap );
                } else {
                    status_header( 404 );
                    exit;
                }
                break;
        }
        
        exit;
    }
    
    /**
     * Output main sitemap index
     */
    private function output_sitemap_index() {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        $sitemaps = $this->get_sitemap_list();
        
        foreach ( $sitemaps as $sitemap ) {
            echo "\t<sitemap>\n";
            echo "\t\t<loc>" . esc_url( $sitemap['loc'] ) . "</loc>\n";
            if ( ! empty( $sitemap['lastmod'] ) ) {
                echo "\t\t<lastmod>" . esc_html( $sitemap['lastmod'] ) . "</lastmod>\n";
            }
            echo "\t</sitemap>\n";
        }
        
        echo '</sitemapindex>';
    }
    
    /**
     * Get list of available sitemaps
     */
    private function get_sitemap_list() {
        $sitemaps = array();
        $home_url = home_url();
        
        // Posts sitemap
        if ( $this->has_posts( 'post' ) ) {
            $sitemaps[] = array(
                'loc' => $home_url . '/sitemap-posts.xml',
                'lastmod' => $this->get_last_modified_date( 'post' )
            );
        }
        
        // Pages sitemap
        if ( $this->has_posts( 'page' ) ) {
            $sitemaps[] = array(
                'loc' => $home_url . '/sitemap-pages.xml',
                'lastmod' => $this->get_last_modified_date( 'page' )
            );
        }
        
        // Custom post types
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        foreach ( $post_types as $post_type ) {
            if ( in_array( $post_type->name, array( 'post', 'page', 'attachment' ) ) ) {
                continue;
            }
            
            if ( $this->has_posts( $post_type->name ) ) {
                $sitemaps[] = array(
                    'loc' => $home_url . '/sitemap-' . $post_type->name . '.xml',
                    'lastmod' => $this->get_last_modified_date( $post_type->name )
                );
            }
        }
        
        // Categories
        if ( $this->has_terms( 'category' ) ) {
            $sitemaps[] = array(
                'loc' => $home_url . '/sitemap-categories.xml',
                'lastmod' => current_time( 'c' )
            );
        }
        
        // Tags
        if ( $this->has_terms( 'post_tag' ) ) {
            $sitemaps[] = array(
                'loc' => $home_url . '/sitemap-tags.xml',
                'lastmod' => current_time( 'c' )
            );
        }
        
        return apply_filters( 'ace_seo_sitemap_list', $sitemaps );
    }
    
    /**
     * Output posts sitemap
     */
    private function output_posts_sitemap() {
        $this->output_post_type_sitemap( 'post' );
    }
    
    /**
     * Output pages sitemap
     */
    private function output_pages_sitemap() {
        $this->output_post_type_sitemap( 'page' );
    }
    
    /**
     * Output sitemap for specific post type
     */
    private function output_post_type_sitemap( $post_type ) {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        
        $posts = $this->get_posts_for_sitemap( $post_type );
        
        foreach ( $posts as $post ) {
            $url = get_permalink( $post->ID );
            $lastmod = get_the_modified_time( 'c', $post->ID );
            $priority = $this->calculate_priority( $post );
            $changefreq = $this->calculate_changefreq( $post );
            
            // Skip if noindex is set
            if ( get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) === '1' ) {
                continue;
            }
            
            echo "\t<url>\n";
            echo "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
            echo "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
            echo "\t\t<changefreq>" . esc_html( $changefreq ) . "</changefreq>\n";
            echo "\t\t<priority>" . esc_html( $priority ) . "</priority>\n";
            
            // Add images
            $images = $this->get_post_images( $post->ID );
            foreach ( $images as $image ) {
                echo "\t\t<image:image>\n";
                echo "\t\t\t<image:loc>" . esc_url( $image['src'] ) . "</image:loc>\n";
                if ( ! empty( $image['alt'] ) ) {
                    echo "\t\t\t<image:caption>" . esc_html( $image['alt'] ) . "</image:caption>\n";
                }
                echo "\t\t</image:image>\n";
            }
            
            echo "\t</url>\n";
        }
        
        echo '</urlset>';
    }
    
    /**
     * Output categories sitemap
     */
    private function output_categories_sitemap() {
        $this->output_taxonomy_sitemap( 'category' );
    }
    
    /**
     * Output tags sitemap
     */
    private function output_tags_sitemap() {
        $this->output_taxonomy_sitemap( 'post_tag' );
    }
    
    /**
     * Output sitemap for specific taxonomy
     */
    private function output_taxonomy_sitemap( $taxonomy ) {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        $terms = get_terms( array(
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => 1000
        ) );
        
        foreach ( $terms as $term ) {
            $url = get_term_link( $term );
            
            if ( is_wp_error( $url ) ) {
                continue;
            }
            
            echo "\t<url>\n";
            echo "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
            echo "\t\t<changefreq>weekly</changefreq>\n";
            echo "\t\t<priority>0.6</priority>\n";
            echo "\t</url>\n";
        }
        
        echo '</urlset>';
    }
    
    /**
     * Get posts for sitemap
     */
    private function get_posts_for_sitemap( $post_type ) {
        return get_posts( array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => 1000,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_yoast_wpseo_meta-robots-noindex',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_yoast_wpseo_meta-robots-noindex',
                    'value' => '1',
                    'compare' => '!='
                )
            )
        ) );
    }
    
    /**
     * Calculate URL priority
     */
    private function calculate_priority( $post ) {
        if ( $post->post_type === 'page' ) {
            return is_front_page( $post->ID ) ? '1.0' : '0.8';
        }
        
        // Calculate based on comments and age
        $comments = get_comments_number( $post->ID );
        $age_days = ( time() - strtotime( $post->post_date ) ) / DAY_IN_SECONDS;
        
        if ( $age_days < 30 && $comments > 5 ) {
            return '0.9';
        } elseif ( $age_days < 90 ) {
            return '0.7';
        } else {
            return '0.5';
        }
    }
    
    /**
     * Calculate change frequency
     */
    private function calculate_changefreq( $post ) {
        $modified = strtotime( $post->post_modified );
        $age_days = ( time() - $modified ) / DAY_IN_SECONDS;
        
        if ( $age_days < 7 ) {
            return 'daily';
        } elseif ( $age_days < 30 ) {
            return 'weekly';
        } else {
            return 'monthly';
        }
    }
    
    /**
     * Get images from post content
     */
    private function get_post_images( $post_id ) {
        $images = array();
        $content = get_post_field( 'post_content', $post_id );
        
        // Featured image
        if ( has_post_thumbnail( $post_id ) ) {
            $thumbnail_id = get_post_thumbnail_id( $post_id );
            $thumbnail_url = wp_get_attachment_image_url( $thumbnail_id, 'full' );
            $thumbnail_alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
            
            if ( $thumbnail_url ) {
                $images[] = array(
                    'src' => $thumbnail_url,
                    'alt' => $thumbnail_alt
                );
            }
        }
        
        // Images in content
        preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i', $content, $matches );
        
        for ( $i = 0; $i < count( $matches[0] ); $i++ ) {
            $src = $matches[1][$i];
            $alt = $matches[2][$i];
            
            // Only include images from our domain
            if ( strpos( $src, home_url() ) === 0 ) {
                $images[] = array(
                    'src' => $src,
                    'alt' => $alt
                );
            }
        }
        
        return array_slice( array_unique( $images, SORT_REGULAR ), 0, 10 ); // Max 10 images per page
    }
    
    /**
     * Check if post type has posts
     */
    private function has_posts( $post_type ) {
        $count = wp_count_posts( $post_type );
        return ! empty( $count->publish );
    }
    
    /**
     * Check if taxonomy has terms
     */
    private function has_terms( $taxonomy ) {
        $count = wp_count_terms( $taxonomy );
        return ! empty( $count );
    }
    
    /**
     * Get last modified date for post type
     */
    private function get_last_modified_date( $post_type ) {
        global $wpdb;
        
        $last_modified = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_modified_gmt FROM {$wpdb->posts} 
             WHERE post_type = %s AND post_status = 'publish' 
             ORDER BY post_modified_gmt DESC LIMIT 1",
            $post_type
        ) );
        
        return $last_modified ? date( 'c', strtotime( $last_modified ) ) : current_time( 'c' );
    }
    
    /**
     * Generate all sitemaps (for cron)
     */
    public function generate_all_sitemaps() {
        // This could be used to generate static sitemap files if needed
        // For now, we generate them dynamically
        do_action( 'ace_seo_sitemaps_generated' );
    }
}
