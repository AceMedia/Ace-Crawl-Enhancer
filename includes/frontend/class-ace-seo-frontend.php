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

        // Adjust Jetpack OG tags instead of disabling everything: overwrite the bits we control.
        add_filter('jetpack_open_graph_tags', [$this, 'override_jetpack_og_tags'], 20);

        // Start output buffering to strip lingering OG title/description from other sources on the homepage.
        add_action('template_redirect', [$this, 'start_output_buffer'], 0);
    }

    public function remove_conflicting_outputs() {
        // Remove Yoast SEO frontend if active
        if (class_exists('WPSEO_Frontend')) {
            remove_action('wp_head', ['WPSEO_Frontend', 'head'], 1);
        }

        // Remove other common SEO plugins
        remove_action('wp_head', 'rel_canonical');

        // Remove Jetpack OG meta output (we'll supply our own).
        remove_action('wp_head', 'jetpack_og_tags');
        remove_action('wp_head', 'jetpack_seo_meta_tags');

        // Keep Jetpack enabled but override its OG fields via filter (see override_jetpack_og_tags) as a safety net.
    }

    public function start_output_buffer() {
        if (is_admin()) {
            return;
        }
        ob_start([$this, 'filter_head_output']);
    }

    /**
     * De-duplicate og:title / og:description in the head.
     *
     * ACE emits its own og:title/og:description (marked with data-ace-seo="1").
     * Themes and other plugins may inject their own copies. To guarantee exactly
     * one canonical pair — ACE's — we capture ACE's marked tags, strip *every*
     * og:title/og:description in the head, then re-inject ACE's pair once before
     * </head>. (Previously this stripped all of them, including ACE's own, so the
     * tags vanished site-wide.)
     */
    public function filter_head_output($html) {
        // Only act on pages that actually have a head section.
        if (stripos($html, '<head') === false) {
            return $html;
        }

        // Capture ACE's own canonical pair before stripping (markers removed).
        $preserve = '';
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]*data-ace-seo=["\']1["\'][^>]*>/i', $html, $m)) {
            $preserve .= preg_replace('/\s*data-ace-seo=["\']1["\']/i', '', $m[0]) . "\n";
        }
        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]*data-ace-seo=["\']1["\'][^>]*>/i', $html, $m)) {
            $preserve .= preg_replace('/\s*data-ace-seo=["\']1["\']/i', '', $m[0]) . "\n";
        }

        // Strip ALL og:title / og:description (ACE's own marked copies and any
        // duplicates injected by themes or other plugins).
        $html = preg_replace('/[ \t]*<meta[^>]+property=["\']og:(title|description)["\'][^>]*>\n?/i', '', $html);

        // Re-inject ACE's canonical pair once, immediately before </head>.
        if ('' !== $preserve) {
            $html = preg_replace('/<\/head>/i', $preserve . '</head>', $html, 1);
        }

        return $html;
    }

    public function output_schema_markup() {
        $graph = [];
        $publisher = $this->get_publisher_schema();
        $publisher_ref = !empty($publisher['@id']) ? ['@id' => $publisher['@id']] : null;

        // A WebPage node anchors every page; other nodes reference it by @id.
        $webpage = $this->generate_webpage_schema();
        $webpage_ref = ['@id' => $webpage['@id']];

        if (is_front_page()) {
            $website = $this->generate_website_schema();
            if ($publisher_ref) {
                $website['publisher'] = $publisher_ref;
            }
            $webpage['isPartOf'] = ['@id' => $website['@id']];
            $graph[] = $website;
        } elseif (is_home()) {
            $graph[] = $this->generate_archive_schema('home');
        } elseif (is_author() || is_search() || is_archive()) {
            $graph[] = $this->generate_archive_schema();
        } elseif (is_singular()) {
            global $post;
            /**
             * Whether to emit an Article node for this singular content. Post
             * types with their own primary schema type (Event, Product,
             * LocalBusiness, JobPosting…) should return false so the page isn't
             * both an Article and its real type. Integration adapters set this
             * for their CPTs.
             */
            if ($post && apply_filters('ace_seo_emit_article', true, $post)) {
                $article = $this->generate_article_schema($post, $publisher_ref, $webpage_ref);
                $graph[] = $article['node'];
                if (!empty($article['author'])) {
                    $graph[] = $article['author'];
                }
            }
        }

        $graph[] = $webpage;

        // Breadcrumb trail as BreadcrumbList (only when there is an actual trail).
        $breadcrumbs = $this->generate_breadcrumb_schema($webpage_ref);
        if (!empty($breadcrumbs)) {
            $webpage_index = count($graph) - 1;
            $graph[$webpage_index]['breadcrumb'] = ['@id' => $breadcrumbs['@id']];
            $graph[] = $breadcrumbs;
        }

        if (!empty($publisher)) {
            $graph[] = $publisher;
        }

        $context = [
            'is_front_page'  => is_front_page(),
            'is_singular'    => is_singular(),
            'queried_object' => get_queried_object(),
            'webpage_id'     => $webpage['@id'],
            'publisher_id'   => $publisher['@id'] ?? '',
        ];

        AceSeoSchemaGraph::render(AceSeoSchemaGraph::build($graph, $context));
    }

    /**
     * WebPage node for the current URL — the anchor other nodes reference.
     */
    private function generate_webpage_schema() {
        $url = $this->get_current_url();

        $webpage = [
            '@type' => 'WebPage',
            '@id'   => $url . '#webpage',
            'url'   => $url,
            'name'  => wp_strip_all_tags(wp_get_document_title()),
            'inLanguage' => get_bloginfo('language'),
        ];

        $description = $this->get_current_meta_description();
        if (!empty($description)) {
            $webpage['description'] = $description;
        }

        return $webpage;
    }

    /**
     * BreadcrumbList node from the shared breadcrumb trail.
     */
    private function generate_breadcrumb_schema($webpage_ref) {
        if (!class_exists('ACE_SEO_Breadcrumbs') || is_front_page() || is_404()) {
            return null;
        }

        $items = ACE_SEO_Breadcrumbs::get_items();
        if (count($items) < 2) {
            return null;
        }

        $list_items = [];
        $position = 1;
        foreach ($items as $item) {
            if (empty($item['label'])) {
                continue;
            }
            $list_item = [
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => wp_strip_all_tags($item['label']),
            ];
            // The current page omits `item` per Google's guidelines.
            if (empty($item['is_current']) && !empty($item['url'])) {
                $list_item['item'] = $item['url'];
            }
            $list_items[] = $list_item;
            $position++;
        }

        if (count($list_items) < 2) {
            return null;
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id'   => $webpage_ref['@id'] . '#breadcrumb',
            'itemListElement' => $list_items,
        ];
    }

    /**
     * Article node + separate @id-linked author Person node.
     *
     * @return array{node: array, author: ?array}
     */
    private function generate_article_schema($post, $publisher_ref, $webpage_ref) {
        $manual_title = AceCrawlEnhancer::get_meta_value($post->ID, 'title');
        if (empty($manual_title)) {
            $manual_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        }

        /**
         * Filter the schema.org type used for singular content.
         * Return e.g. 'NewsArticle' or 'BlogPosting' per post/post type.
         * Default comes from settings: schema.article_type_{post_type},
         * then schema.article_type, then 'Article'.
         */
        $options = $this->get_seo_options();
        $default_type = $options['schema']['article_type_' . $post->post_type]
            ?? ($options['schema']['article_type'] ?? 'Article');
        $type = apply_filters('ace_seo_article_schema_type', $default_type, $post);

        $author_node = null;
        $author_url = get_author_posts_url($post->post_author);
        $author_name = get_the_author_meta('display_name', $post->post_author);
        if (!empty($author_name)) {
            $author_node = [
                '@type' => 'Person',
                '@id'   => $author_url . '#author',
                'name'  => $author_name,
                'url'   => $author_url,
            ];
        }

        $schema = [
            '@type' => $type,
            '@id' => get_permalink($post) . '#article',
            'headline' => $manual_title ? $manual_title : get_the_title($post),
            'description' => $this->get_meta_description($post),
            'url' => get_permalink($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
        ];

        if ($author_node) {
            $schema['author'] = ['@id' => $author_node['@id']];
        }
        if ($publisher_ref) {
            $schema['publisher'] = $publisher_ref;
        }

        // Add image if available
        if (has_post_thumbnail($post)) {
            $image_url = get_the_post_thumbnail_url($post, 'large');
            $schema['image'] = [
                '@type' => 'ImageObject',
                'url' => $image_url,
            ];
        }

        /**
         * Filter the article node (word count, reading time, section and
         * keyword enhancements hook in here — see AceSeoSchema).
         */
        $schema = apply_filters('ace_seo_schema_article', $schema, $post);

        // Anchor to the WebPage node last so enhancers can't detach it.
        $schema['isPartOf'] = $webpage_ref;
        $schema['mainEntityOfPage'] = $webpage_ref;

        return ['node' => $schema, 'author' => $author_node];
    }

    /**
     * Override Jetpack OG tags for fields we explicitly control (title/description/url).
     */
    public function override_jetpack_og_tags($tags) {
        // Strip Jetpack's core OG fields so ACE outputs are the only ones for title/url/description.
        unset($tags['og:title'], $tags['og:url'], $tags['og:description']);
        unset($tags['twitter:title'], $tags['twitter:description']);
        return $tags;
    }

    /**
     * Derive the current page meta description following ACE rules (manual > Yoast > template/settings; no tagline fallback).
     */
    private function get_current_meta_description() {
        if (is_singular()) {
            global $post;
            if ($post) {
                $meta = AceCrawlEnhancer::get_meta_value($post->ID, 'metadesc');
                if (!empty($meta)) {
                    return $meta;
                }
                $yoast = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
                if (!empty($yoast)) {
                    return $yoast;
                }
                // Fallback to excerpt/content snippet
                if (!empty($post->post_excerpt)) {
                    return wp_trim_words(strip_tags($post->post_excerpt), 25);
                }
                return wp_trim_words(strip_tags($post->post_content), 25);
            }
        }

        if (is_home() || is_front_page()) {
            $options = $this->get_seo_options();
            $settings_desc = $options['general']['home_description'] ?? '';

            $page_on_front = get_option('page_on_front');
            $show_on_front = get_option('show_on_front');
            if ($show_on_front === 'page' && $page_on_front) {
                $page_meta_desc = AceCrawlEnhancer::get_meta_value($page_on_front, 'metadesc');
                $yoast_desc = get_post_meta($page_on_front, '_yoast_wpseo_metadesc', true);
                if (!empty($page_meta_desc)) {
                    return $page_meta_desc;
                }
                if (!empty($yoast_desc)) {
                    return $yoast_desc;
                }
                if (!empty($settings_desc)) {
                    return $settings_desc;
                }
                // last resort: content snippet of the static page
                $page = get_post($page_on_front);
                if ($page) {
                    return wp_trim_words(strip_tags($page->post_content), 25);
                }
            } else {
                if (!empty($settings_desc)) {
                    return $settings_desc;
                }
            }
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $desc = term_description($term);
                if (!empty($desc)) {
                    return wp_strip_all_tags($desc);
                }
            }
        }

        if (is_search()) {
            $query = get_search_query();
            if ($query) {
                return sprintf(__('Search results for "%s"', 'ace-crawl-enhancer'), $query);
            }
        }

        // No tagline fallback; return empty to let Jetpack omit the tag if we have nothing explicit.
        return '';
    }

    private function generate_website_schema() {
        $options = $this->get_seo_options();
        $home_title = $options['general']['home_title'] ?? '';
        $home_description = $options['general']['home_description'] ?? '';

        // Prefer page-level manual/Yoast values when the homepage is a static page.
        $page_on_front = get_option('page_on_front');
        $show_on_front = get_option('show_on_front');
        if ($show_on_front === 'page' && $page_on_front) {
            $ace_page_title = AceCrawlEnhancer::get_meta_value($page_on_front, 'title');
            $ace_page_desc  = AceCrawlEnhancer::get_meta_value($page_on_front, 'metadesc');
            $yoast_page_title = get_post_meta($page_on_front, '_yoast_wpseo_title', true);
            $yoast_page_desc  = get_post_meta($page_on_front, '_yoast_wpseo_metadesc', true);

            // Name: Yoast custom title > ACE page title > ACE settings title > site name
            $home_title = $yoast_page_title ?: ($ace_page_title ?: ($home_title ?: get_bloginfo('name')));

            // Description: Yoast custom meta desc > ACE page meta desc > ACE settings desc > empty
            $home_description = $yoast_page_desc ?: ($ace_page_desc ?: ($home_description ?: ''));
        } else {
            // Blog homepage: still allow Yoast settings if set on the posts page (rare), else ACE settings
            $posts_page = get_option('page_for_posts');
            if ($posts_page) {
                $yoast_posts_title = get_post_meta($posts_page, '_yoast_wpseo_title', true);
                $yoast_posts_desc  = get_post_meta($posts_page, '_yoast_wpseo_metadesc', true);
                if (!empty($yoast_posts_title)) {
                    $home_title = $yoast_posts_title;
                }
                if (!empty($yoast_posts_desc)) {
                    $home_description = $yoast_posts_desc;
                }
            }
        }

        // The WebSite node names the SITE (its brand), not the homepage. Feeding it the home
        // title made Google treat the long page title as the site name and fall back to the bare
        // domain for the result's site-name line. The homepage title still drives <title> and
        // og:title through the WebPage node; here we want the brand only.
        $brand = !empty($options['general']['site_name']) ? $options['general']['site_name'] : get_bloginfo('name');

        $schema = [
            '@type' => 'WebSite',
            '@id' => trailingslashit(home_url()) . '#website',
            'name' => $brand,
            'description' => !empty($home_description) ? $home_description : '',
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

    private function generate_archive_schema($context = '') {
        $publisher = $this->get_publisher_schema();
        $schema = [
            '@type' => 'CollectionPage',
            'name' => wp_strip_all_tags(wp_get_document_title()),
            'url' => $this->get_current_url(),
        ];
        if (!empty($publisher['@id'])) {
            $schema['publisher'] = ['@id' => $publisher['@id']];
        }

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
                
                // Try to use custom archive meta description template
                $options = $this->get_seo_options();
                $templates = $options['templates'] ?? [];
                $archive_meta_key = 'meta_template_archive_' . $post_type;
                
                if (!empty($templates[$archive_meta_key])) {
                    // Use custom template with variable replacement
                    $description = $this->replace_archive_template_variables($templates[$archive_meta_key], $post_type, $obj);
                } else {
                    // Fallback to default description
                    $description = sprintf(__('Latest %s entries from %s', 'ace-crawl-enhancer'), strtolower($label), get_bloginfo('name'));
                }
                
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

        // Avoid site tagline fallback to prevent unintended text injection
        if (!empty($description)) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    private function get_publisher_schema() {
        // Memoize: schema is constant within a single request.
        static $cached = null;
        if ( $cached !== null ) {
            return $cached;
        }

        $options = $this->get_seo_options();
        $organization = $options['organization'] ?? [];

        if (($organization['type'] ?? 'organization') === 'person') {
            return $cached = $this->get_person_publisher_schema($options);
        }

        $name = !empty($organization['name']) ? $organization['name'] : get_bloginfo('name');
        $url = !empty($organization['url']) ? $organization['url'] : home_url();

        $publisher = [
            '@context' => 'https://schema.org',
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

        // Description: only include if explicitly set and not equal to the WP tagline
        if (!empty($organization['description'])) {
            $tagline = get_bloginfo('description');
            if (trim($organization['description']) !== trim($tagline)) {
                $publisher['description'] = wp_strip_all_tags($organization['description']);
            }
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
        return $cached = apply_filters('ace_seo_publisher_schema', $publisher, $organization, $options);
    }

    /** Per-request cache for ace_seo_options to avoid repeated get_option() calls. */
    private function get_seo_options() {
        static $options = null;
        if ( $options === null ) {
            $options = get_option( 'ace_seo_options', [] );
        }
        return $options;
    }

    private function get_person_publisher_schema($options) {
        $person = $options['person'] ?? [];

        $name = !empty($person['name']) ? $person['name'] : get_bloginfo('name');
        $url = !empty($person['url']) ? $person['url'] : home_url();

        $schema = [
            '@type' => 'Person',
            '@id' => trailingslashit($url) . '#person',
            'name' => $name,
            'url' => $url,
        ];

        if (!empty($person['job_title'])) {
            $schema['jobTitle'] = $person['job_title'];
        }

        if (!empty($person['description'])) {
            $schema['description'] = wp_strip_all_tags($person['description']);
        }

        $image = $this->build_person_image($person);
        if (!empty($image)) {
            $schema['image'] = $image;
        }

        $same_as = $this->build_person_same_as($person);
        if (!empty($same_as)) {
            $schema['sameAs'] = $same_as;
        }

        /**
         * Filter the person publisher schema data before output.
         *
         * @param array $schema Person schema array.
         * @param array $person Person settings.
         * @param array $options All Ace SEO options.
         */
        return apply_filters('ace_seo_publisher_person_schema', $schema, $person, $options);
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

    private function build_person_image($person) {
        $image_id = !empty($person['image_id']) ? absint($person['image_id']) : 0;
        $image_url = '';

        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if (!empty($image_url)) {
                $metadata = wp_get_attachment_metadata($image_id);
                $image = [
                    '@type' => 'ImageObject',
                    'url' => $image_url,
                ];

                if (is_array($metadata)) {
                    if (!empty($metadata['width'])) {
                        $image['width'] = (int) $metadata['width'];
                    }
                    if (!empty($metadata['height'])) {
                        $image['height'] = (int) $metadata['height'];
                    }
                }

                return $image;
            }
        }

        if (empty($image_url) && !empty($person['image_url'])) {
            $image_url = $person['image_url'];
        }

        if (!empty($image_url)) {
            return [
                '@type' => 'ImageObject',
                'url' => $image_url,
            ];
        }

        return null;
    }

    private function build_person_same_as($person) {
        $profiles = [];

        if (!empty($person['twitter_username'])) {
            $handle = ltrim($person['twitter_username'], '@');
            if (!empty($handle)) {
                $profiles[] = 'https://twitter.com/' . $handle;
            }
        }

        if (!empty($person['same_as']) && is_array($person['same_as'])) {
            foreach ($person['same_as'] as $url) {
                if (!empty($url)) {
                    $profiles[] = $url;
                }
            }
        }

        $profiles = array_filter(array_unique(array_map('esc_url_raw', $profiles)));

        return array_values($profiles);
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

        if (!empty($organization['twitter_username'])) {
            $twitter_username = ltrim($organization['twitter_username'], '@');
            if (!empty($twitter_username)) {
                $profiles[] = 'https://twitter.com/' . $twitter_username;
            }
        }

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
                $legacy_handle = ltrim($options['social']['twitter_username'], '@');
                if (!empty($legacy_handle)) {
                    $profiles[] = 'https://twitter.com/' . $legacy_handle;
                }
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
            // Sanitize query parameters to prevent XSS
            $query_args = array();
            foreach ($_GET as $key => $value) {
                $safe_key = sanitize_key($key);
                $safe_value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field(wp_unslash($value));
                $query_args[$safe_key] = $safe_value;
            }
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
        $options = $this->get_seo_options();

        // og:site_name — the site's brand. This is the primary signal Google uses for the
        // site-name line in a result, and we remove Jetpack's OG tags in setup_hooks(), so
        // without this the tag is absent entirely and Google falls back to the bare domain
        // (showing "example.com" instead of the brand). Use the SEO "site name" setting, else
        // the WordPress Site Title — never the homepage title, which is a page title, not a brand.
        $brand = !empty($options['general']['site_name']) ? $options['general']['site_name'] : get_bloginfo('name');
        if (!empty($brand)) {
            echo '<meta property="og:site_name" content="' . esc_attr($brand) . '">' . "\n";
        }

        $google_code = $options['webmaster']['google'] ?? ($options['webmaster']['google_verify'] ?? '');
        if (!empty($google_code)) {
            echo '<meta name="google-site-verification" content="' . esc_attr($google_code) . '">' . "\n";
        }

        $bing_code = $options['webmaster']['bing'] ?? ($options['webmaster']['bing_verify'] ?? '');
        if (!empty($bing_code)) {
            echo '<meta name="msvalidate.01" content="' . esc_attr($bing_code) . '">' . "\n";
        }

        if (!empty($options['webmaster']['pinterest'])) {
            echo '<meta name="p:domain_verify" content="' . esc_attr($options['webmaster']['pinterest']) . '">' . "\n";
        }

        if (!empty($options['webmaster']['yandex'])) {
            echo '<meta name="yandex-verification" content="' . esc_attr($options['webmaster']['yandex']) . '">' . "\n";
        }

        if (!empty($options['webmaster']['baidu'])) {
            echo '<meta name="baidu-site-verification" content="' . esc_attr($options['webmaster']['baidu']) . '">' . "\n";
        }

        if (!empty($options['webmaster']['ahrefs'])) {
            echo '<meta name="ahrefs-site-verification" content="' . esc_attr($options['webmaster']['ahrefs']) . '">' . "\n";
        }

        // Add Facebook App ID if set
        if (!empty($options['social']['facebook_app_id'])) {
            echo '<meta property="fb:app_id" content="' . esc_attr($options['social']['facebook_app_id']) . '">' . "\n";
        }

        // Add Twitter site if set
        $twitter_handle = '';
        if (($options['organization']['type'] ?? 'organization') === 'person') {
            if (!empty($options['person']['twitter_username'])) {
                $twitter_handle = $options['person']['twitter_username'];
            }
        }

        if (empty($twitter_handle) && !empty($options['organization']['twitter_username'])) {
            $twitter_handle = $options['organization']['twitter_username'];
        } elseif (empty($twitter_handle) && !empty($options['social']['twitter_username'])) {
            // Backward compatibility with legacy setting
            $twitter_handle = $options['social']['twitter_username'];
        }

        if (!empty($twitter_handle)) {
            $twitter_handle = '@' . ltrim($twitter_handle, '@');
            if (strlen($twitter_handle) > 1) {
                echo '<meta name="twitter:site" content="' . esc_attr($twitter_handle) . '">' . "\n";
            }
        }
    }

    /**
     * Replace template variables for custom post type archives in schema
     */
    private function replace_archive_template_variables($template, $post_type, $post_type_obj) {
        $options = $this->get_seo_options();
        $general = $options['general'] ?? [];
        
        $variables = [
            '{site_name}' => $general['site_name'] ?? get_bloginfo('name'),
            '{sep}' => ' ' . ($general['separator'] ?? '|') . ' ',
            '{archive_title}' => $post_type_obj->labels->name ?? ucfirst($post_type),
            '{post_type_name}' => $post_type_obj->labels->name ?? ucfirst($post_type),
            '{post_type_singular}' => $post_type_obj->labels->singular_name ?? ucfirst($post_type),
            '{post_type_slug}' => $post_type,
        ];
        
        $result = str_replace(array_keys($variables), array_values($variables), $template);
        return wp_strip_all_tags($result);
    }
}
