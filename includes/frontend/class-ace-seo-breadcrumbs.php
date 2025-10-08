<?php
/**
 * ACE SEO Breadcrumbs helper.
 *
 * Generates context-aware breadcrumb trails that work in both frontend requests
 * and editor previews when the block renderer is invoked through REST.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ACE_SEO_Breadcrumbs {
    /**
     * Build the breadcrumb list for the current context.
     *
     * @param array $context Block rendering context (e.g. postId, postType, query).
     * @return array<int, array<string, mixed>>
     */
    public static function get_items(array $context = []) {
        $items = self::build_from_wp_query();

        if (empty($items)) {
            $items = self::build_from_context($context);
        }

        if (empty($items)) {
            $items = self::build_fallback();
        }

        /**
         * Filter the breadcrumb items before rendering.
         *
         * Each item is an associative array with the keys: label, url, is_current.
         *
         * @param array $items   Breadcrumb items.
         * @param array $context Rendering context.
         */
        return apply_filters('ace_seo_breadcrumb_items', $items, $context);
    }

    /**
     * Attempt to build breadcrumbs using the global query (frontend usage).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_from_wp_query() {
        if (function_exists('in_the_loop') && in_the_loop()) {
            if (!function_exists('is_main_query') || !is_main_query()) {
                // When rendering inside a secondary loop (e.g. Query Loop), rely on context instead.
                return [];
            }
        }

        if (is_admin() && !(function_exists('wp_is_block_theme') && wp_is_block_theme())) {
            // In the classic admin, global conditionals do not reflect frontend context.
            return [];
        }

        if (is_front_page()) {
            return self::build_front_page();
        }

        if (is_home()) {
            return self::build_blog_index();
        }

        if (is_singular()) {
            $post = get_queried_object();
            return $post instanceof WP_Post ? self::build_for_post($post) : [];
        }

        if (is_category()) {
            $term = get_queried_object();
            return $term instanceof WP_Term ? self::build_for_term($term) : [];
        }

        if (is_tag()) {
            $term = get_queried_object();
            return $term instanceof WP_Term ? self::build_for_term($term) : [];
        }

        if (is_tax()) {
            $term = get_queried_object();
            return $term instanceof WP_Term ? self::build_for_term($term) : [];
        }

        if (is_post_type_archive()) {
            $post_type = get_query_var('post_type');
            if (is_array($post_type)) {
                $post_type = reset($post_type);
            }

            return $post_type ? self::build_for_post_type_archive($post_type) : [];
        }

        if (is_search()) {
            return self::build_for_search(get_search_query());
        }

        if (is_author()) {
            $author = get_queried_object();
            return $author instanceof WP_User ? self::build_for_author($author) : [];
        }

        if (is_date()) {
            return self::build_for_date_archive();
        }

        if (is_404()) {
            return self::build_for_404();
        }

        return [];
    }

    /**
     * Build breadcrumbs based on the block rendering context (editor previews).
     *
     * @param array $context Rendering context.
     * @return array<int, array<string, mixed>>
     */
    private static function build_from_context(array $context) {
        if (!empty($context['query']) && is_array($context['query'])) {
            $query_items = self::build_from_query_context($context['query']);
            if (!empty($query_items)) {
                return $query_items;
            }
        }

        if (!empty($context['termId']) && !empty($context['taxonomy'])) {
            $term = get_term((int) $context['termId'], sanitize_key($context['taxonomy']));
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return self::build_for_term($term);
            }
        }

        if (!empty($context['postId']) && empty($context['queryId'])) {
            $post = get_post((int) $context['postId']);
            if ($post instanceof WP_Post) {
                return self::build_for_post($post);
            }
        }

        if (!empty($context['query']) && is_array($context['query'])) {
            $query_items = self::build_from_query_context($context['query']);
            if (!empty($query_items)) {
                return $query_items;
            }
        }

        return [];
    }

    /**
     * Build breadcrumbs for editor query contexts (e.g. Query Loop).
     *
     * @param array $query Query arguments passed through block context.
     * @return array<int, array<string, mixed>>
     */
    private static function build_from_query_context(array $query) {
        if (!empty($query['inherit'])) {
            // Inherit main query; let build_from_wp_query handle it.
            return [];
        }

        if (!empty($query['categoryIds'])) {
            $term = get_term((int) $query['categoryIds'][0], 'category');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return self::build_for_term($term);
            }
        }

        if (!empty($query['tagIds'])) {
            $term = get_term((int) $query['tagIds'][0], 'post_tag');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return self::build_for_term($term);
            }
        }

        if (!empty($query['taxonomy']) && !empty($query['terms'])) {
            $taxonomy = sanitize_key($query['taxonomy']);
            $term_id  = (int) (is_array($query['terms']) ? reset($query['terms']) : $query['terms']);

            if ($term_id > 0 && taxonomy_exists($taxonomy)) {
                $term = get_term($term_id, $taxonomy);
                if ($term instanceof WP_Term && !is_wp_error($term)) {
                    return self::build_for_term($term);
                }
            }
        }

        if (!empty($query['postType']) && $query['postType'] !== 'any') {
            $post_type = is_array($query['postType']) ? reset($query['postType']) : $query['postType'];

            if ('post' === $post_type) {
                return self::build_blog_index();
            }

            if (!empty($post_type)) {
                return self::build_for_post_type_archive($post_type);
            }
        }

        if (!empty($query['author'])) {
            $author = get_user_by('id', (int) $query['author']);
            if ($author instanceof WP_User) {
                return self::build_for_author($author);
            }
        }

        if (!empty($query['search'])) {
            return self::build_for_search((string) $query['search']);
        }

        return [];
    }

    /**
     * Build breadcrumbs for the static front page.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_front_page() {
        $home = self::home_item();
        $home['is_current'] = true;
        $home['url'] = '';

        return [$home];
    }

    /**
     * Build breadcrumbs for the posts index.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_blog_index() {
        $items = [self::home_item()];

        $posts_page_id = (int) get_option('page_for_posts');
        if ($posts_page_id > 0) {
            $items[] = self::format_item(get_the_title($posts_page_id), '', true);
        } else {
            $items[] = self::format_item(
                self::get_blog_index_label(),
                '',
                true
            );
        }

        return $items;
    }

    /**
     * Build breadcrumbs for a singular post or page.
     *
     * @param WP_Post $post Post instance.
     * @return array<int, array<string, mixed>>
     */
    private static function build_for_post(WP_Post $post) {
        $items = [self::home_item()];

        if ('page' === $post->post_type) {
            $items = array_merge($items, self::build_page_hierarchy($post));
        } elseif ('post' === $post->post_type) {
            $items = array_merge($items, self::build_blog_hierarchy($post));
        } else {
            $items = array_merge($items, self::build_cpt_hierarchy($post));
        }

        $items[] = self::format_item(get_the_title($post), '', true);

        return $items;
    }

    /**
     * Build breadcrumbs for a taxonomy term archive.
     *
     * @param WP_Term $term Term instance.
     * @return array<int, array<string, mixed>>
     */
    private static function build_for_term(WP_Term $term) {
        $items = [self::home_item()];

        if ('category' !== $term->taxonomy) {
            $archive_item = self::get_taxonomy_archive_item($term->taxonomy);
            if ($archive_item) {
                $items[] = $archive_item;
            }
        }

        $ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy));
        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term($ancestor_id, $term->taxonomy);
            if ($ancestor instanceof WP_Term && !is_wp_error($ancestor)) {
                $items[] = self::format_item($ancestor->name, get_term_link($ancestor), false);
            }
        }

        $items[] = self::format_item($term->name, '', true);

        return $items;
    }

    /**
     * Build breadcrumbs for a custom post type archive.
     *
     * @param string $post_type Post type slug.
     * @return array<int, array<string, mixed>>
     */
    private static function build_for_post_type_archive($post_type) {
        $post_type_object = get_post_type_object($post_type);
        if (!$post_type_object) {
            return [];
        }

        $items = [self::home_item()];

        $items[] = self::format_item(
            $post_type_object->labels->name ?? $post_type_object->label ?? ucfirst($post_type),
            '',
            true
        );

        return $items;
    }

    /**
     * Build breadcrumbs for a search results page.
     *
     * @param string $query Raw search query.
     * @return array<int, array<string, mixed>>
     */
    private static function build_for_search($query) {
        $items = [self::home_item()];
        $label = sprintf(
            /* translators: %s: search term. */
            esc_html__('Search Results for "%s"', 'ace-crawl-enhancer'),
            esc_html(wp_strip_all_tags($query))
        );

        $items[] = self::format_item($label, '', true);

        return $items;
    }

    /**
     * Build breadcrumbs for an author archive.
     *
     * @param WP_User $author Author instance.
     * @return array<int, array<string, mixed>>
     */
    private static function build_for_author(WP_User $author) {
        $items = [self::home_item()];

        $items[] = self::format_item(
            sprintf(
                /* translators: %s: author display name. */
                esc_html__('Articles by %s', 'ace-crawl-enhancer'),
                esc_html($author->display_name)
            ),
            '',
            true
        );

        return $items;
    }

    /**
     * Build breadcrumbs for a date archive (year, month, day).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_for_date_archive() {
        $items = [self::home_item()];

        $year  = get_query_var('year');
        $month = get_query_var('monthnum');
        $day   = get_query_var('day');

        if ($year) {
            $items[] = self::format_item(
                sprintf(
                    /* translators: %s: year number. */
                    esc_html__('Year %s', 'ace-crawl-enhancer'),
                    (int) $year
                ),
                $day || $month ? get_year_link($year) : '',
                !$month && !$day
            );
        }

        if ($month) {
            $items[] = self::format_item(
                sprintf(
                    /* translators: 1: month number (formatted), 2: year. */
                    esc_html__('%1$s %2$s', 'ace-crawl-enhancer'),
                    esc_html(date_i18n('F', mktime(0, 0, 0, (int) $month, 10))),
                    esc_html((string) $year)
                ),
                $day ? get_month_link($year, $month) : '',
                !$day
            );
        }

        if ($day) {
            $items[] = self::format_item(
                sprintf(
                    /* translators: 1: day of the month, 2: formatted month/year. */
                    esc_html__('%1$s %2$s', 'ace-crawl-enhancer'),
                    esc_html((string) $day),
                    esc_html(date_i18n('F Y', mktime(0, 0, 0, (int) $month, (int) $day, (int) $year)))
                ),
                '',
                true
            );
        }

        return $items;
    }

    /**
     * Build breadcrumbs for a 404 page.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_for_404() {
        $items = [self::home_item()];

        $items[] = self::format_item(
            esc_html__('Page Not Found', 'ace-crawl-enhancer'),
            '',
            true
        );

        return $items;
    }

    /**
     * Build a fallback breadcrumb trail used as a last resort in editors.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_fallback() {
        $home = self::home_item();
        $home['is_current'] = true;
        $home['url'] = '';

        return [$home];
    }

    /**
     * Return the breadcrumb item representing the home page.
     *
     * @param bool $allow_link Whether to keep the URL when marking as current.
     * @return array<string, mixed>
     */
    private static function home_item() {
        $label = apply_filters(
            'ace_seo_breadcrumb_home_label',
            esc_html__('Home', 'ace-crawl-enhancer')
        );

        return self::format_item($label, home_url('/'), false);
    }

    /**
     * Retrieve the optional blog index breadcrumb item.
     *
     * @return array<string, mixed>|null
     */
    private static function get_blog_index_item() {
        $posts_page_id = (int) get_option('page_for_posts');
        if ($posts_page_id > 0) {
            return self::format_item(
                get_the_title($posts_page_id),
                get_permalink($posts_page_id),
                false
            );
        }

        return self::format_item(
            self::get_blog_index_label(),
            '',
            false
        );
    }

    /**
     * Get the default label used for the posts index breadcrumb.
     *
     * @return string
     */
    private static function get_blog_index_label() {
        return apply_filters(
            'ace_seo_breadcrumb_blog_label',
            esc_html__('Blog', 'ace-crawl-enhancer')
        );
    }

    /**
     * Generate breadcrumb items for a hierarchical page.
     *
     * @param WP_Post $post Page instance.
     * @return array<int, array<string, mixed>>
     */
    private static function build_page_hierarchy(WP_Post $post) {
        $items = [];
        $ancestors = array_reverse(get_post_ancestors($post));

        foreach ($ancestors as $ancestor_id) {
            $items[] = self::format_item(
                get_the_title($ancestor_id),
                get_permalink($ancestor_id),
                false
            );
        }

        return $items;
    }

    /**
     * Generate breadcrumb items for posts (blog).
     *
     * @param WP_Post $post Post instance.
     * @return array<int, array<string, mixed>>
     */
    private static function build_blog_hierarchy(WP_Post $post) {
        $items = [];
        $blog_index = self::get_blog_index_item();

        if ($blog_index) {
            $items[] = $blog_index;
        }

        $primary_term = self::determine_primary_term($post->ID, 'category');
        if ($primary_term instanceof WP_Term) {
            $ancestors = array_reverse(get_ancestors($primary_term->term_id, 'category'));
            foreach ($ancestors as $ancestor_id) {
                $ancestor = get_term($ancestor_id, 'category');
                if ($ancestor instanceof WP_Term && !is_wp_error($ancestor)) {
                    $items[] = self::format_item($ancestor->name, get_term_link($ancestor), false);
                }
            }

            $items[] = self::format_item($primary_term->name, get_term_link($primary_term), false);
        }

        return $items;
    }

    /**
     * Generate breadcrumb items for custom post types.
     *
     * @param WP_Post $post Post instance.
     * @return array<int, array<string, mixed>>
     */
    private static function build_cpt_hierarchy(WP_Post $post) {
        $items = [];
        $post_type_object = get_post_type_object($post->post_type);

        if ($post_type_object && $post_type_object->has_archive) {
            $archive_link = get_post_type_archive_link($post->post_type);
            $items[] = self::format_item(
                $post_type_object->labels->name ?? $post_type_object->label ?? ucfirst($post->post_type),
                $archive_link ?: '',
                false
            );
        }

        if (!empty($post_type_object->taxonomies)) {
            foreach ($post_type_object->taxonomies as $taxonomy) {
                $term = self::determine_primary_term($post->ID, $taxonomy);
                if ($term instanceof WP_Term) {
                    $items = array_merge($items, self::build_tax_term_hierarchy($term));
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Build ancestor items for a taxonomy term and append the term itself.
     *
     * @param WP_Term $term Term instance.
     * @return array<int, array<string, mixed>>
     */
    private static function build_tax_term_hierarchy(WP_Term $term) {
        $items = [];
        $ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy));

        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term($ancestor_id, $term->taxonomy);
            if ($ancestor instanceof WP_Term && !is_wp_error($ancestor)) {
                $items[] = self::format_item($ancestor->name, get_term_link($ancestor), false);
            }
        }

        $items[] = self::format_item($term->name, get_term_link($term), false);

        return $items;
    }

    /**
     * Attempt to determine the primary term for a post.
     *
     * @param int    $post_id Post ID.
     * @param string $taxonomy Taxonomy name.
     * @return WP_Term|null
     */
    private static function determine_primary_term($post_id, $taxonomy) {
        $taxonomy = sanitize_key($taxonomy);

        if (!taxonomy_exists($taxonomy)) {
            return null;
        }

        /**
         * Attempt to read the Yoast primary term if migrated.
         */
        $primary_term_id = get_post_meta($post_id, '_yoast_wpseo_primary_' . $taxonomy, true);
        if ($primary_term_id) {
            $term = get_term((int) $primary_term_id, $taxonomy);
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                return $term;
            }
        }

        $terms = get_the_terms($post_id, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) {
            return null;
        }

        $terms = array_values($terms);

        return $terms[0];
    }

    /**
     * Optionally return a taxonomy archive item.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return array<string, mixed>|null
     */
    private static function get_taxonomy_archive_item($taxonomy) {
        $taxonomy_object = get_taxonomy($taxonomy);
        if (!$taxonomy_object) {
            return null;
        }

        if (!empty($taxonomy_object->rewrite['slug'])) {
            $link = home_url(trailingslashit($taxonomy_object->rewrite['slug']));
        } else {
            $link = get_post_type_archive_link($taxonomy_object->object_type[0] ?? '');
        }

        if (!$link) {
            $link = '';
        }

        return self::format_item(
            $taxonomy_object->labels->name ?? $taxonomy,
            $link,
            false
        );
    }

    /**
     * Normalize a breadcrumb item array.
     *
     * @param string $label     Human-readable label.
     * @param string $url       Item URL, if any.
     * @param bool   $is_current Whether the item represents the current view.
     * @return array<string, mixed>
     */
    private static function format_item($label, $url = '', $is_current = false) {
        return [
            'label' => wp_strip_all_tags($label),
            'url' => $url ? esc_url_raw($url) : '',
            'is_current' => (bool) $is_current,
        ];
    }
}
