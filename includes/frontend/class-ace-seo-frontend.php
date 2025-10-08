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

        // Disable Jetpack's Open Graph tags so ACE controls social meta
        add_filter('jetpack_enable_open_graph', '__return_false', 99);
    }

    public function output_schema_markup() {
        $schema = [];

        if (is_front_page()) {
            $schema = $this->generate_website_schema();
        } elseif (is_home()) {
            $schema = $this->generate_archive_schema('home');
        } elseif (is_author() || is_search() || is_archive()) {
            $schema = $this->generate_archive_schema();
        } elseif (is_singular()) {
            global $post;
            $schema = $this->generate_article_schema($post);
        }

        if (!empty($schema)) {
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "\n" . '</script>' . "\n";
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
            'publisher' => $this->get_publisher_schema(),
        ];

        // Add image if available
        if (has_post_thumbnail($post)) {
            $image_url = get_the_post_thumbnail_url($post, 'large');
            $schema['image'] = [
                '@type' => 'ImageObject',
                'url' => $image_url,
            ];
        }

        return $schema;
    }

    private function generate_website_schema() {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url(),
            'publisher' => $this->get_publisher_schema(),
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

    private function generate_archive_schema($context = '') {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => wp_strip_all_tags(wp_get_document_title()),
            'url' => $this->get_current_url(),
            'publisher' => $this->get_publisher_schema(),
        ];

        $description = '';

        if ($context === 'home') {
            $description = get_bloginfo('description');
            $schema['mainEntity'] = [
                '@type' => 'Blog',
                'name' => get_bloginfo('name'),
                'url' => home_url(),
            ];
        } elseif (is_search()) {
            $query = get_search_query();
            $schema['@type'] = 'SearchResultsPage';
            $description = sprintf(__('Search results for "%s"', 'ace-crawl-enhancer'), $query);
            $schema['mainEntity'] = [
                '@type' => 'ItemList',
                'name' => $description,
            ];
            $schema['about'] = [
                '@type' => 'Thing',
                'name' => $query,
            ];
        } elseif (is_author()) {
            $author = get_queried_object();
            if ($author instanceof WP_User) {
                $schema['@type'] = 'ProfilePage';
                $description = get_the_author_meta('description', $author->ID);
                if (empty($description)) {
                    $description = sprintf(__('Articles written by %s', 'ace-crawl-enhancer'), $author->display_name);
                }
                $author_data = [
                    '@type' => 'Person',
                    'name' => $author->display_name,
                    'url' => get_author_posts_url($author->ID),
                ];
                $avatar = get_avatar_url($author->ID, ['size' => 512]);
                if ($avatar) {
                    $author_data['image'] = $avatar;
                }
                $schema['mainEntity'] = $author_data;
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link)) {
                    $schema['url'] = $term_link;
                }
                $term_description = term_description($term);
                $description = $term_description ? wp_strip_all_tags($term_description) : sprintf(__('Latest articles about %s', 'ace-crawl-enhancer'), $term->name);
                $schema['name'] = sprintf(__('%s Archives', 'ace-crawl-enhancer'), $term->name);
                $schema['mainEntity'] = [
                    '@type' => 'Thing',
                    'name' => $term->name,
                    'url' => !empty($schema['url']) ? $schema['url'] : $this->get_current_url(),
                ];
            }
        } elseif (is_post_type_archive()) {
            $post_type = get_query_var('post_type');
            if (is_array($post_type)) {
                $post_type = reset($post_type);
            }
            if (!empty($post_type)) {
                $obj = get_post_type_object($post_type);
                $label = $obj && isset($obj->labels->name) ? $obj->labels->name : ucfirst($post_type);
                $schema['name'] = sprintf(__('%s Archive', 'ace-crawl-enhancer'), $label);
                $description = sprintf(__('Latest %s entries from %s', 'ace-crawl-enhancer'), strtolower($label), get_bloginfo('name'));
                $schema['mainEntity'] = [
                    '@type' => 'ItemList',
                    'name' => $schema['name'],
                ];
            }
        } elseif (is_date()) {
            $schema['name'] = wp_strip_all_tags(wp_get_document_title());
            $description = sprintf(__('Articles published on %s', 'ace-crawl-enhancer'), wp_strip_all_tags(wp_get_document_title()));
            $schema['mainEntity'] = [
                '@type' => 'ItemList',
                'name' => $schema['name'],
            ];
        }

        if (empty($description)) {
            $description = get_bloginfo('description');
        }

        if (!empty($description)) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    private function get_publisher_schema() {
        $options = get_option('ace_seo_options', []);
        $organization = $options['organization'] ?? [];

        $name = !empty($organization['name']) ? $organization['name'] : get_bloginfo('name');
        $url = !empty($organization['url']) ? $organization['url'] : home_url();

        $publisher = [
            '@type' => 'Organization',
            '@id' => trailingslashit($url) . '#organization',
            'name' => $name,
            'url' => $url,
        ];

        if (!empty($organization['legal_name'])) {
            $publisher['legalName'] = $organization['legal_name'];
        }

        if (!empty($organization['alternate_name'])) {
            $publisher['alternateName'] = $organization['alternate_name'];
        }

        $description = !empty($organization['description']) ? $organization['description'] : get_bloginfo('description');
        if (!empty($description)) {
            $publisher['description'] = wp_strip_all_tags($description);
        }

        $logo = $this->build_logo_schema($organization, $options);
        if (!empty($logo)) {
            $publisher['logo'] = $logo;
        }

        $contact_point = $this->build_contact_point($organization);
        if (!empty($contact_point)) {
            $publisher['contactPoint'] = [$contact_point];
        }

        $social_profiles = $this->build_social_profiles($organization, $options);
        if (!empty($social_profiles)) {
            $publisher['sameAs'] = $social_profiles;
        }

        /**
         * Filter the organization publisher schema data before output.
         *
         * @param array $publisher     Publisher schema array.
         * @param array $organization  Saved organization settings.
         * @param array $options       All Ace SEO options.
         */
        return apply_filters('ace_seo_publisher_schema', $publisher, $organization, $options);
    }

    private function build_logo_schema($organization, $options) {
        $logo_id = !empty($organization['logo_id']) ? absint($organization['logo_id']) : 0;
        $logo_url = '';

        if ($logo_id) {
            $logo_url = wp_get_attachment_image_url($logo_id, 'full');
            if (!empty($logo_url)) {
                $metadata = wp_get_attachment_metadata($logo_id);
                $logo = [
                    '@type' => 'ImageObject',
                    'url' => $logo_url,
                ];

                if (is_array($metadata)) {
                    if (!empty($metadata['width'])) {
                        $logo['width'] = (int) $metadata['width'];
                    }
                    if (!empty($metadata['height'])) {
                        $logo['height'] = (int) $metadata['height'];
                    }
                }

                return $logo;
            }
        }

        if (empty($logo_url) && !empty($organization['logo_url'])) {
            $logo_url = $organization['logo_url'];
        }

        if (empty($logo_url) && !empty($options['social']['default_image'])) {
            $logo_url = $options['social']['default_image'];
        }

        if (!empty($logo_url)) {
            return [
                '@type' => 'ImageObject',
                'url' => $logo_url,
            ];
        }

        return null;
    }

    private function build_contact_point($organization) {
        $contact_type = $organization['contact_type'] ?? '';
        $telephone = $organization['contact_phone'] ?? '';
        $email = $organization['contact_email'] ?? '';
        $contact_url = $organization['contact_url'] ?? '';

        if (empty($contact_type) && empty($telephone) && empty($email) && empty($contact_url)) {
            return null;
        }

        $contact_point = [
            '@type' => 'ContactPoint',
        ];

        if (!empty($contact_type)) {
            $contact_point['contactType'] = $contact_type;
        }
        if (!empty($telephone)) {
            $contact_point['telephone'] = $telephone;
        }
        if (!empty($email)) {
            $contact_point['email'] = $email;
        }
        if (!empty($contact_url)) {
            $contact_point['url'] = $contact_url;
        }

        return $contact_point;
    }

    private function build_social_profiles($organization, $options) {
        $profiles = [];
        $keys = [
            'social_facebook',
            'social_twitter',
            'social_instagram',
            'social_linkedin',
            'social_youtube',
        ];

        foreach ($keys as $key) {
            if (!empty($organization[$key])) {
                $profiles[] = $organization[$key];
            }
        }

        if (empty($profiles)) {
            if (!empty($options['social']['facebook_page'])) {
                $profiles[] = $options['social']['facebook_page'];
            }
            if (!empty($options['social']['twitter_username'])) {
                $profiles[] = 'https://twitter.com/' . ltrim($options['social']['twitter_username'], '@');
            }
            if (!empty($options['social']['linkedin_page'])) {
                $profiles[] = $options['social']['linkedin_page'];
            }
            if (!empty($options['social']['instagram_page'])) {
                $profiles[] = $options['social']['instagram_page'];
            }
            if (!empty($options['social']['youtube_channel'])) {
                $profiles[] = $options['social']['youtube_channel'];
            }
        }

        $profiles = array_filter(array_unique(array_map('esc_url_raw', $profiles)));

        return array_values($profiles);
    }

    private function get_current_url() {
        global $wp;

        $request_path = '';
        if (is_object($wp) && !empty($wp->request)) {
            $request_path = '/' . ltrim($wp->request, '/');
        }

        $url = home_url($request_path ?: '/');

        if (!empty($_GET)) {
            $query_args = array_map('wp_unslash', $_GET);
            $url = add_query_arg($query_args, $url);
        }

        return $url;
    }

    private function get_meta_description($post) {
        $meta_desc = AceCrawlEnhancer::get_meta_value($post->ID, 'metadesc');

        if (empty($meta_desc)) {
            if (!empty($post->post_excerpt)) {
                return wp_trim_words($post->post_excerpt, 25);
            }

            return wp_trim_words(strip_tags($post->post_content), 25);
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
}
