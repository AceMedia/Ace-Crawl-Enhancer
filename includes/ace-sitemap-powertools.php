<?php
/**
 * Ace Sitemap Powertools (merged into Ace Crawl Enhancer).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'ACE_SITEMAP_POWERTOOLS_VERSION' ) ) {
    define( 'ACE_SITEMAP_POWERTOOLS_VERSION', '1.0.3' );
}

function ace_sitemap_powertools_default_options() {
    return array(
        'enable_custom_routes'     => 1,
        'serve_custom_routes'      => 1,
        'enable_news_provider'     => 1,
        'enable_authors_provider'  => 1,
        'enable_categories_route'  => 1,
        'enable_tags_route'        => 1,
        'enable_custom_header'     => 1,
        'enable_index_link'        => 1,
        'enable_recent_marker'     => 1,
        'enable_legacy_redirects'  => 1,
        'disable_canonical_redirects' => 1,
        'post_max_urls'            => 250,
        'enable_sitemap_cache'     => 1,
        'enable_index_lastmod'     => 1,
        'sitemap_cache_ttl'        => 600,
        'excluded_sitemap_providers'   => array(),
        'excluded_sitemap_post_types'  => array(),
        'excluded_sitemap_taxonomies'  => array( 'ace_event' ),
        'enable_root_tag_archive_fallback' => 1,
        'enable_root_tag_links'           => 1,
        'enable_tag_base_redirect'        => 0,
    );
}

function ace_sitemap_powertools_get_options() {
    $defaults = ace_sitemap_powertools_default_options();
    $saved    = get_option( 'ace_sitemap_powertools_options' );

    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return array_merge( $defaults, $saved );
}

function ace_sitemap_powertools_get_option( $key ) {
    $options = ace_sitemap_powertools_get_options();

    return isset( $options[ $key ] ) ? $options[ $key ] : null;
}

function ace_sitemap_powertools_has_redis_cache_plugin() {
    return defined( 'ACE_REDIS_CACHE_PLUGIN_FILE' ) || function_exists( 'ace_redis_cache' ) || class_exists( 'AceMedia\\RedisCache\\AceRedisCache' );
}

function ace_sitemap_powertools_redis_cache_available() {
    if ( ! ace_sitemap_powertools_has_redis_cache_plugin() ) {
        return false;
    }

    if ( function_exists( 'wp_using_ext_object_cache' ) ) {
        return wp_using_ext_object_cache();
    }

    return true;
}

function ace_sitemap_powertools_effective_post_max_urls() {
    $limit = (int) ace_sitemap_powertools_get_option( 'post_max_urls' );

    if ( $limit < 1 ) {
        $limit = 250;
    }

    return max( 1, $limit );
}

function ace_sitemap_powertools_is_enabled( $key ) {
    return (bool) ace_sitemap_powertools_get_option( $key );
}

function ace_sitemap_powertools_excluded_sitemap_post_types() {
    $saved = ace_sitemap_powertools_get_option( 'excluded_sitemap_post_types' );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return (array) apply_filters(
        'ace_sitemap_powertools_excluded_sitemap_post_types',
        array_values( array_unique( array_map( 'sanitize_key', $saved ) ) )
    );
}

function ace_sitemap_powertools_excluded_sitemap_taxonomies() {
    $saved = ace_sitemap_powertools_get_option( 'excluded_sitemap_taxonomies' );
    if ( ! is_array( $saved ) ) {
        $saved = array( 'ace_event' );
    }

    return (array) apply_filters(
        'ace_sitemap_powertools_excluded_sitemap_taxonomies',
        array_values( array_unique( array_merge( array( 'post_format' ), array_map( 'sanitize_key', $saved ) ) ) )
    );
}

function ace_sitemap_powertools_excluded_sitemap_providers() {
    $saved = ace_sitemap_powertools_get_option( 'excluded_sitemap_providers' );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }

    return (array) apply_filters(
        'ace_sitemap_powertools_excluded_sitemap_providers',
        array_values( array_unique( array_map( 'sanitize_key', $saved ) ) )
    );
}

function ace_sitemap_powertools_detected_custom_providers() {
    if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
        return array();
    }

    $providers = wp_sitemaps_get_server()->registry->get_providers();
    if ( ! is_array( $providers ) ) {
        return array();
    }

    $items = array();
    foreach ( $providers as $name => $provider ) {
        if ( in_array( $name, array( 'posts', 'taxonomies', 'users', 'news', 'authors' ), true ) ) {
            continue;
        }

        $items[ $name ] = array(
            'label'       => $name,
            'description' => is_object( $provider ) ? get_class( $provider ) : 'Provider',
        );
    }

    ksort( $items );
    return $items;
}

function ace_sitemap_powertools_detected_custom_post_types() {
    $post_types = get_post_types( array( 'public' => true ), 'objects' );
    if ( ! is_array( $post_types ) ) {
        return array();
    }

    $items = array();
    foreach ( $post_types as $name => $object ) {
        if ( in_array( $name, array( 'post', 'page', 'attachment' ), true ) ) {
            continue;
        }

        $slug = $name;
        if ( isset( $object->rewrite['slug'] ) && is_string( $object->rewrite['slug'] ) ) {
            $slug = trim( $object->rewrite['slug'], '/' );
        }

        $items[ $name ] = array(
            'label'       => isset( $object->labels->name ) ? $object->labels->name : $name,
            'description' => 'Post type slug: ' . $slug,
        );
    }

    ksort( $items );
    return $items;
}

function ace_sitemap_powertools_detected_custom_taxonomies() {
    $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
    if ( ! is_array( $taxonomies ) ) {
        return array();
    }

    $items = array();
    foreach ( $taxonomies as $name => $object ) {
        if ( in_array( $name, array( 'category', 'post_tag', 'post_format' ), true ) ) {
            continue;
        }

        $slug = $name;
        if ( isset( $object->rewrite['slug'] ) && is_string( $object->rewrite['slug'] ) ) {
            $slug = trim( $object->rewrite['slug'], '/' );
        }

        $items[ $name ] = array(
            'label'       => isset( $object->labels->name ) ? $object->labels->name : $name,
            'description' => 'Taxonomy slug: ' . $slug,
        );
    }

    ksort( $items );
    return $items;
}

function ace_sitemap_powertools_can_use_root_level_tag_slug( $slug ) {
    $slug = sanitize_title( (string) $slug );
    if ( '' === $slug ) {
        return false;
    }

    if ( get_category_by_slug( $slug ) instanceof WP_Term ) {
        return false;
    }

    if ( get_page_by_path( $slug, OBJECT, 'page' ) ) {
        return false;
    }

    $post_types = get_post_types(
        array(
            'publicly_queryable' => true,
        ),
        'names'
    );
    unset( $post_types['attachment'] );

    if ( ! empty( $post_types ) && get_page_by_path( $slug, OBJECT, array_values( $post_types ) ) ) {
        return false;
    }

    return true;
}

function ace_sitemap_powertools_resolve_root_slug_to_tag_archive( $query_vars ) {
    if ( is_admin() || ! ace_sitemap_powertools_root_tag_archives_enabled() ) {
        return $query_vars;
    }

    if ( ! empty( $_GET ) ) {
        return $query_vars;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    if ( '' === $request_uri ) {
        return $query_vars;
    }

    $path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
    $path = trim( $path, '/' );
    if ( '' === $path ) {
        return $query_vars;
    }

    $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
    $home_path = trim( $home_path, '/' );
    if ( '' !== $home_path ) {
        if ( $path === $home_path ) {
            $path = '';
        } elseif ( 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }
    }

    if ( '' === $path || false !== strpos( $path, '/' ) ) {
        return $query_vars;
    }

    $slug = sanitize_title( $path );
    if ( '' === $slug || in_array( $slug, array( 'tag', 'category' ), true ) ) {
        return $query_vars;
    }

    foreach ( array( 'tag', 'tag_id', 'taxonomy', 'term', 'post_type', 'attachment' ) as $key ) {
        if ( ! empty( $query_vars[ $key ] ) ) {
            return $query_vars;
        }
    }

    if ( ! ace_sitemap_powertools_can_use_root_level_tag_slug( $slug ) ) {
        return $query_vars;
    }

    $tag = get_term_by( 'slug', $slug, 'post_tag' );
    if ( ! $tag || is_wp_error( $tag ) ) {
        return $query_vars;
    }

    return array( 'tag' => $slug );
}
add_filter( 'request', 'ace_sitemap_powertools_resolve_root_slug_to_tag_archive', 20 );

function ace_sitemap_powertools_use_root_level_tag_links( $termlink, $term, $taxonomy ) {
    if ( 'post_tag' !== $taxonomy || ! $term instanceof WP_Term || ! ace_sitemap_powertools_root_tag_links_enabled() ) {
        return $termlink;
    }

    if ( ! ace_sitemap_powertools_can_use_root_level_tag_slug( $term->slug ) ) {
        return $termlink;
    }

    return home_url( user_trailingslashit( $term->slug ) );
}
add_filter( 'term_link', 'ace_sitemap_powertools_use_root_level_tag_links', 20, 3 );

function ace_sitemap_powertools_redirect_tag_base_to_root_slug() {
    if ( is_admin() || ! is_tag() || ! ace_sitemap_powertools_tag_base_redirect_enabled() ) {
        return;
    }

    $term = get_queried_object();
    if ( ! $term instanceof WP_Term || empty( $term->slug ) ) {
        return;
    }

    if ( ! ace_sitemap_powertools_can_use_root_level_tag_slug( $term->slug ) ) {
        return;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    if ( '' === $request_uri ) {
        return;
    }

    $path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
    $path = trim( $path, '/' );

    $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
    $home_path = trim( $home_path, '/' );
    if ( '' !== $home_path ) {
        if ( $path === $home_path ) {
            $path = '';
        } elseif ( 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }
    }

    $prefix = 'tag/' . $term->slug;
    if ( $path !== $prefix && 0 !== strpos( $path, $prefix . '/' ) ) {
        return;
    }

    $suffix = substr( $path, strlen( $prefix ) );
    if ( false === $suffix ) {
        $suffix = '';
    }

    $target = home_url( user_trailingslashit( $term->slug . ltrim( $suffix, '/' ) ) );
    $query_string = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
    if ( '' !== $query_string ) {
        $target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query_string;
    }

    wp_safe_redirect( $target, 301 );
    exit;
}
add_action( 'template_redirect', 'ace_sitemap_powertools_redirect_tag_base_to_root_slug', 1 );

function ace_sitemap_powertools_register_settings() {
    register_setting( 'ace_sitemap_powertools', 'ace_sitemap_powertools_options', 'ace_sitemap_powertools_sanitize_options' );

    add_settings_section(
        'ace_sitemap_powertools_section_routing',
        'Clean URLs',
        '__return_false',
        'ace-sitemap-powertools'
    );

    add_settings_field(
        'enable_custom_routes',
        'Clean sitemap URLs',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_routing',
        array(
            'key' => 'enable_custom_routes',
            'label' => 'Enable clean sitemap URLs (e.g. /sitemap.xml, /news-1.xml).',
            'description' => ace_sitemap_powertools_clean_urls_description(),
        )
    );

    add_settings_field(
        'serve_custom_routes',
        'Serve clean routes',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_routing',
        array(
            'key' => 'serve_custom_routes',
            'label' => 'Serve clean routes directly (fallback if rewrites fail).',
        )
    );

    add_settings_field(
        'enable_legacy_redirects',
        'Legacy redirects',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_routing',
        array(
            'key' => 'enable_legacy_redirects',
            'label' => 'Redirect legacy wp-sitemap URLs to clean URLs.',
        )
    );

    add_settings_field(
        'disable_canonical_redirects',
        'Disable canonical redirects',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_routing',
        array(
            'key' => 'disable_canonical_redirects',
            'label' => 'Prevent canonical redirects for sitemap URLs.',
        )
    );

    add_settings_field(
        'enable_root_tag_archive_fallback',
        'Root Tag Archive Fallback',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_routing',
        array(
            'key' => 'enable_root_tag_archive_fallback',
            'label' => 'Allow root-level tag archives like /slug/ when there is no slug collision.',
        )
    );

    add_settings_field(
        'enable_root_tag_links',
        'Root Tag Links',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_routing',
        array(
            'key' => 'enable_root_tag_links',
            'label' => 'Generate root-level tag links when a tag slug is safe to use.',
        )
    );

    add_settings_field(
        'enable_tag_base_redirect',
        'Tag Base Redirect',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_routing',
        array(
            'key' => 'enable_tag_base_redirect',
            'label' => 'Redirect /tag/slug/ requests to /slug/ when the root-level route is safe.',
        )
    );

    add_settings_section(
        'ace_sitemap_powertools_section_providers',
        'Clean URL Mapping',
        '__return_false',
        'ace-sitemap-powertools'
    );

    add_settings_field(
        'enable_news_provider',
        'News URL',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_providers',
        array(
            'key' => 'enable_news_provider',
            'label' => 'Rename the posts sitemap URL to /news-*.xml (removes /posts-*.xml).',
        )
    );

    add_settings_field(
        'enable_authors_provider',
        'Authors URL',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_providers',
        array(
            'key' => 'enable_authors_provider',
            'label' => 'Rename the users sitemap URL to /authors-*.xml.',
        )
    );

    add_settings_field(
        'enable_categories_route',
        'Categories URL',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_providers',
        array(
            'key' => 'enable_categories_route',
            'label' => 'Rename the categories sitemap URL to /categories-*.xml.',
        )
    );

    add_settings_field(
        'enable_tags_route',
        'Tags URL',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_providers',
        array(
            'key' => 'enable_tags_route',
            'label' => 'Rename the tags sitemap URL to /tags-*.xml.',
        )
    );

    add_settings_section(
        'ace_sitemap_powertools_section_display',
        'Styles & Display',
        '__return_false',
        'ace-sitemap-powertools'
    );

    add_settings_field(
        'enable_custom_header',
        'Header branding',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_display',
        array(
            'key' => 'enable_custom_header',
            'label' => 'Show logo, title, and description in the XSL header.',
        )
    );

    add_settings_section(
        'ace_sitemap_powertools_section_detected',
        'Detected Plugin Sitemaps',
        '__return_false',
        'ace-sitemap-powertools'
    );

    add_settings_field(
        'excluded_sitemap_providers',
        'Third-Party Providers',
        'ace_sitemap_powertools_field_detected_sitemaps',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_detected',
        array(
            'key' => 'excluded_sitemap_providers',
            'choices' => ace_sitemap_powertools_detected_custom_providers(),
            'empty_label' => 'No third-party sitemap providers detected.',
        )
    );

    add_settings_field(
        'excluded_sitemap_taxonomies',
        'Custom Taxonomy Sitemaps',
        'ace_sitemap_powertools_field_detected_sitemaps',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_detected',
        array(
            'key' => 'excluded_sitemap_taxonomies',
            'choices' => ace_sitemap_powertools_detected_custom_taxonomies(),
            'empty_label' => 'No custom public taxonomies detected.',
        )
    );

    add_settings_field(
        'excluded_sitemap_post_types',
        'Custom Post Type Sitemaps',
        'ace_sitemap_powertools_field_detected_sitemaps',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_detected',
        array(
            'key' => 'excluded_sitemap_post_types',
            'choices' => ace_sitemap_powertools_detected_custom_post_types(),
            'empty_label' => 'No custom public post types detected.',
        )
    );

    add_settings_field(
        'enable_index_link',
        'Index link',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_display',
        array(
            'key' => 'enable_index_link',
            'label' => 'Show a link back to the sitemap index on sub-sitemaps.',
        )
    );

    add_settings_field(
        'enable_recent_marker',
        'Most recent marker',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_display',
        array(
            'key' => 'enable_recent_marker',
            'label' => 'Highlight page 1 as “most recent” when a sitemap has 5+ pages.',
        )
    );

    add_settings_section(
        'ace_sitemap_powertools_section_performance',
        'Performance',
        '__return_false',
        'ace-sitemap-powertools'
    );

    add_settings_field(
        'post_max_urls',
        'Post URLs per sitemap',
        'ace_sitemap_powertools_field_number',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_performance',
        array(
            'key' => 'post_max_urls',
            'label' => 'Max URLs per posts sitemap. Uses a safe default of 250 when empty or set below 1.',
            'min' => 1,
        )
    );

    add_settings_field(
        'enable_sitemap_cache',
        'Enable sitemap cache',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_performance',
        array(
            'key' => 'enable_sitemap_cache',
            'label' => 'Cache sitemap index and sitemap pages for faster cold loads.',
        )
    );

    add_settings_field(
        'enable_index_lastmod',
        'Index lastmod',
        'ace_sitemap_powertools_field_checkbox',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_performance',
        array(
            'key' => 'enable_index_lastmod',
            'label' => 'Include lastmod in sitemap index entries (posts/pages/news only).',
        )
    );

    add_settings_field(
        'sitemap_cache_ttl',
        'Sitemap cache TTL (seconds)',
        'ace_sitemap_powertools_field_number',
        'ace-sitemap-powertools',
        'ace_sitemap_powertools_section_performance',
        array(
            'key' => 'sitemap_cache_ttl',
            'label' => 'Cache duration for sitemap index and pages (min 60 seconds).',
            'min' => 60,
        )
    );
}
add_action( 'admin_init', 'ace_sitemap_powertools_register_settings' );

function ace_sitemap_powertools_sanitize_options( $input ) {
    $defaults  = ace_sitemap_powertools_default_options();
    $checkboxes = array(
        'enable_custom_routes',
        'serve_custom_routes',
        'enable_news_provider',
        'enable_authors_provider',
        'enable_categories_route',
        'enable_tags_route',
        'enable_custom_header',
        'enable_index_link',
        'enable_recent_marker',
        'enable_legacy_redirects',
        'disable_canonical_redirects',
        'enable_sitemap_cache',
        'enable_index_lastmod',
        'enable_root_tag_archive_fallback',
        'enable_root_tag_links',
        'enable_tag_base_redirect',
    );
    $integers = array( 'post_max_urls', 'sitemap_cache_ttl' );
    $arrays = array(
        'excluded_sitemap_providers',
        'excluded_sitemap_post_types',
        'excluded_sitemap_taxonomies',
    );

    $output = array();

    foreach ( $checkboxes as $key ) {
        $output[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
    }

    foreach ( $integers as $key ) {
        $value = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
        $output[ $key ] = $value;
    }

    if ( ! ace_sitemap_powertools_has_redis_cache_plugin() ) {
        $output['enable_sitemap_cache'] = 0;
    }

    foreach ( $arrays as $key ) {
        $values = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
        $output[ $key ] = array_values( array_unique( array_map( 'sanitize_key', $values ) ) );
    }

    $detected_toggle_map = array(
        'excluded_sitemap_providers'  => 'provider',
        'excluded_sitemap_post_types' => 'post_type',
        'excluded_sitemap_taxonomies' => 'taxonomy',
    );

    foreach ( $detected_toggle_map as $option_key => $detected_type ) {
        $detected_key = 'detected_sitemap_' . $detected_type . 's';
        $enabled_key  = 'enabled_sitemap_' . $detected_type . 's';
        $detected     = isset( $input[ $detected_key ] ) && is_array( $input[ $detected_key ] ) ? array_values( array_unique( array_map( 'sanitize_key', $input[ $detected_key ] ) ) ) : array();
        $enabled      = isset( $input[ $enabled_key ] ) && is_array( $input[ $enabled_key ] ) ? array_values( array_unique( array_map( 'sanitize_key', $input[ $enabled_key ] ) ) ) : array();

        if ( ! empty( $detected ) ) {
            $output[ $option_key ] = array_values( array_diff( $detected, $enabled ) );
        }
    }

    return array_merge( $defaults, $output );
}

function ace_sitemap_powertools_route_description( $route ) {
    $provider = isset( $route['provider'] ) ? $route['provider'] : '';
    $subtype  = isset( $route['subtype'] ) ? $route['subtype'] : '';

    if ( 'news' === $provider ) {
        return 'posts (news)';
    }

    if ( 'authors' === $provider ) {
        return 'authors (users)';
    }

    if ( 'taxonomies' === $provider ) {
        if ( $subtype ) {
            $source   = in_array( $subtype, array( 'category', 'post_tag' ), true ) ? '' : ' (plugin)';
            return 'taxonomy: ' . $subtype . $source;
        }
        return 'taxonomy';
    }

    if ( 'posts' === $provider ) {
        if ( $subtype ) {
            $source    = in_array( $subtype, array( 'post', 'page' ), true ) ? '' : ' (plugin)';
            return 'post type: ' . $subtype . $source;
        }
        return 'posts';
    }

    return $subtype ? $provider . ': ' . $subtype : $provider;
}

function ace_sitemap_powertools_root_tag_archives_enabled() {
    return (bool) ace_sitemap_powertools_get_option( 'enable_root_tag_archive_fallback' );
}

function ace_sitemap_powertools_root_tag_links_enabled() {
    return (bool) ace_sitemap_powertools_get_option( 'enable_root_tag_links' );
}

function ace_sitemap_powertools_tag_base_redirect_enabled() {
    return (bool) ace_sitemap_powertools_get_option( 'enable_tag_base_redirect' );
}

function ace_sitemap_powertools_clean_urls_description() {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_routes' ) ) {
        return '<p class="description">Enable clean URLs to show the active route list.</p>';
    }

    $routes = ace_sitemap_powertools_custom_routes();
    if ( empty( $routes ) ) {
        return '<p class="description">No clean URL routes are currently active.</p>';
    }

    $items = array();
    foreach ( $routes as $slug => $route ) {
        $description = ace_sitemap_powertools_route_description( $route );
        $items[] = '<li><code>/' . esc_html( $slug ) . '-1.xml</code> → ' . esc_html( $description ) . '</li>';
    }

    $html  = '<p class="description">Active clean URL routes:</p>';
    $html .= '<ul class="ace-sitemap-powertools-route-list">' . implode( '', $items ) . '</ul>';

    if ( function_exists( 'wp_sitemaps_get_server' ) ) {
        $providers = wp_sitemaps_get_server()->registry->get_providers();
    } else {
        $providers = array();
    }

    $mapped_providers = array();
    foreach ( $routes as $route ) {
        if ( isset( $route['provider'] ) ) {
            $mapped_providers[] = $route['provider'];
        }
    }
    $mapped_providers = array_unique( $mapped_providers );

    $excluded_slugs = apply_filters(
        'ace_sitemap_powertools_excluded_clean_slugs',
        array( 'popular' )
    );
    $excluded_post_types = ace_sitemap_powertools_excluded_sitemap_post_types();
    $excluded_taxonomies = ace_sitemap_powertools_excluded_sitemap_taxonomies();
    $excluded_providers  = ace_sitemap_powertools_excluded_sitemap_providers();

    $core = array( 'posts', 'taxonomies', 'users' );
    $extra = array();
    $extra_provider_names = array();
    foreach ( $providers as $name => $provider ) {
        if ( in_array( $name, $core, true ) ) {
            continue;
        }
        if ( in_array( $name, $mapped_providers, true ) ) {
            continue;
        }
        $class = is_object( $provider ) ? get_class( $provider ) : 'Unknown';
        $extra_provider_names[] = $name;
        $extra[] = '<li><strong>' . esc_html( $name ) . '</strong> (plugin) <code>' . esc_html( $class ) . '</code></li>';
    }

    $forced_extra = array();
    if ( in_array( 'acemedia_glossary', $excluded_post_types, true ) ) {
        $forced_extra['glossary'] = '<li><strong>glossary</strong> (plugin) <code>/glossary-1.xml</code> <code>post type: acemedia_glossary</code></li>';
    }
    if ( in_array( 'popular', $excluded_slugs, true ) ) {
        $forced_extra['popular'] = '<li><strong>popular</strong> (plugin) <code>/popular-1.xml</code></li>';
    }

    foreach ( $forced_extra as $name => $html_line ) {
        if ( ! in_array( $name, $extra_provider_names, true ) ) {
            $extra_provider_names[] = $name;
            $extra[] = $html_line;
        }
    }

    if ( $extra ) {
        $html .= '<p class="description">Other non-core sitemap providers (not mapped to clean URLs):</p>';
        $html .= '<ul class="ace-sitemap-powertools-route-list">' . implode( '', $extra ) . '</ul>';
    }

    $excluded_items = array();
    foreach ( $excluded_slugs as $slug ) {
        $skip = false;
        foreach ( $extra_provider_names as $provider_name ) {
            if ( $provider_name === $slug || false !== strpos( $provider_name, $slug ) || false !== strpos( $slug, $provider_name ) ) {
                $skip = true;
                break;
            }
        }
        if ( $skip ) {
            continue;
        }
        $excluded_items[] = '<li><code>/' . esc_html( $slug ) . '-1.xml</code></li>';
    }

    foreach ( $excluded_post_types as $post_type ) {
        $post_type_object = get_post_type_object( $post_type );
        $slug = $post_type;
        if ( $post_type_object && isset( $post_type_object->rewrite['slug'] ) && is_string( $post_type_object->rewrite['slug'] ) ) {
            $slug = trim( $post_type_object->rewrite['slug'], '/' );
        }
        if ( in_array( $slug, $excluded_slugs, true ) ) {
            continue;
        }
        $skip = false;
        foreach ( $extra_provider_names as $provider_name ) {
            if ( $provider_name === $post_type || $provider_name === $slug || false !== strpos( $provider_name, $slug ) || false !== strpos( $slug, $provider_name ) ) {
                $skip = true;
                break;
            }
        }
        if ( $skip ) {
            continue;
        }
        $excluded_items[] = '<li><code>/' . esc_html( $slug ) . '-1.xml</code> (post type: ' . esc_html( $post_type ) . ')</li>';
    }

    foreach ( $excluded_taxonomies as $taxonomy ) {
        $taxonomy_object = get_taxonomy( $taxonomy );
        if ( ! $taxonomy_object ) {
            continue;
        }

        $slug = $taxonomy;
        if ( isset( $taxonomy_object->rewrite['slug'] ) && is_string( $taxonomy_object->rewrite['slug'] ) ) {
            $slug = trim( $taxonomy_object->rewrite['slug'], '/' );
        }

        $excluded_items[] = '<li><code>/' . esc_html( $slug ) . '-1.xml</code> (taxonomy: ' . esc_html( $taxonomy ) . ')</li>';
    }

    foreach ( $excluded_providers as $provider_name ) {
        $excluded_items[] = '<li><strong>' . esc_html( $provider_name ) . '</strong> (provider disabled)</li>';
    }

    if ( $excluded_items ) {
        $html .= '<p class="description">Plugin-managed sitemap routes (excluded from clean URLs):</p>';
        $html .= '<ul class="ace-sitemap-powertools-route-list">' . implode( '', $excluded_items ) . '</ul>';
    }

    return $html;
}

function ace_sitemap_powertools_field_checkbox( $args ) {
    $options = ace_sitemap_powertools_get_options();
    $key     = $args['key'];
    $label   = isset( $args['label'] ) ? $args['label'] : '';
    $description = isset( $args['description'] ) ? $args['description'] : '';
    $value   = ! empty( $options[ $key ] );

    echo '<label>';
    echo '<input type="checkbox" name="ace_sitemap_powertools_options[' . esc_attr( $key ) . ']" value="1" ' . checked( $value, true, false ) . ' />';
    echo ' ' . esc_html( $label ) . '</label>';
    if ( $description ) {
        echo wp_kses_post( $description );
    }
}

function ace_sitemap_powertools_field_number( $args ) {
    $options = ace_sitemap_powertools_get_options();
    $key     = $args['key'];
    $label   = isset( $args['label'] ) ? $args['label'] : '';
    $min     = isset( $args['min'] ) ? (int) $args['min'] : 0;
    $value   = isset( $options[ $key ] ) ? (int) $options[ $key ] : 0;

    echo '<input type="number" name="ace_sitemap_powertools_options[' . esc_attr( $key ) . ']" min="' . esc_attr( $min ) . '" value="' . esc_attr( $value ) . '" />';
    if ( $label ) {
        echo '<p class="description">' . esc_html( $label ) . '</p>';
    }
}

function ace_sitemap_powertools_field_detected_sitemaps( $args ) {
    $options     = ace_sitemap_powertools_get_options();
    $key         = $args['key'];
    $choices     = isset( $args['choices'] ) && is_array( $args['choices'] ) ? $args['choices'] : array();
    $empty_label = isset( $args['empty_label'] ) ? $args['empty_label'] : 'No items detected.';
    $excluded    = isset( $options[ $key ] ) && is_array( $options[ $key ] ) ? array_map( 'sanitize_key', $options[ $key ] ) : array();

    if ( empty( $choices ) ) {
        echo '<p class="description">' . esc_html( $empty_label ) . '</p>';
        return;
    }

    foreach ( $choices as $value => $choice ) {
        $label       = isset( $choice['label'] ) ? $choice['label'] : $value;
        $description = isset( $choice['description'] ) ? $choice['description'] : '';
        $checked     = ! in_array( sanitize_key( $value ), $excluded, true );

        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="checkbox" name="ace_sitemap_powertools_options[' . esc_attr( $key ) . '][]" value="' . esc_attr( $value ) . '" ' . checked( $checked, true, false ) . ' />';
        echo ' ' . esc_html( $label );
        if ( $description ) {
            echo ' <span class="description">(' . esc_html( $description ) . ')</span>';
        }
        echo '</label>';
    }

    echo '<p class="description">Unchecked items will be excluded from the sitemap index and sitemap routes.</p>';
}

function ace_sitemap_powertools_render_settings_page() {
    echo '<div class="wrap">';
    echo '<h1>Ace Sitemap Powertools</h1>';
    echo '<p>Configure clean sitemap URLs and output. After changing routes, re-save permalinks to flush rewrite rules.</p>';
    echo '<form method="post" action="options.php">';
    settings_fields( 'ace_sitemap_powertools' );
    do_settings_sections( 'ace-sitemap-powertools' );
    submit_button();
    echo '</form>';

    echo '</div>';
}

function ace_sitemap_powertools_get_provider() {
    if ( ! class_exists( 'WP_Sitemaps_Posts' ) ) {
        return null;
    }

    if ( ! class_exists( 'ACE_News_Posts_Sitemaps_Provider' ) ) {
        class ACE_News_Posts_Sitemaps_Provider extends WP_Sitemaps_Posts {
            public function __construct() {
                parent::__construct();
                $this->name = 'news';
            }

            public function get_sitemap_type_data() {
                $pages = $this->get_max_num_pages( 'post' );
                if ( $pages < 1 ) {
                    return array();
                }

                return array(
                    array(
                        'name'  => '',
                        'pages' => $pages,
                    ),
                );
            }

            public function get_object_subtypes() {
                $post_type = get_post_type_object( 'post' );
                if ( ! $post_type || ! is_post_type_viewable( $post_type ) ) {
                    return array();
                }

                return array( 'post' => $post_type );
            }

            public function get_url_list( $page_num, $object_subtype = '' ) {
                return parent::get_url_list( $page_num, 'post' );
            }

            public function get_max_num_pages( $object_subtype = '' ) {
                return parent::get_max_num_pages( 'post' );
            }
        }
    }

    return new ACE_News_Posts_Sitemaps_Provider();
}

function ace_sitemap_powertools_register_news_provider( $wp_sitemaps ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_news_provider' ) ) {
        return;
    }

    $provider = ace_sitemap_powertools_get_provider();
    if ( ! $provider ) {
        return;
    }

    $wp_sitemaps->registry->add_provider( 'news', $provider );
}
add_action( 'wp_sitemaps_init', 'ace_sitemap_powertools_register_news_provider' );

function ace_sitemap_powertools_custom_routes() {
    static $cache = null;

    if ( null !== $cache ) {
        return $cache;
    }

    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_routes' ) ) {
        $cache = array();
        return $cache;
    }

    $excluded_slugs = apply_filters(
        'ace_sitemap_powertools_excluded_clean_slugs',
        array( 'popular' )
    );
    $excluded_post_types = ace_sitemap_powertools_excluded_sitemap_post_types();
    $excluded_taxonomies = ace_sitemap_powertools_excluded_sitemap_taxonomies();

    $routes = array();

    if ( ace_sitemap_powertools_is_enabled( 'enable_news_provider' ) ) {
        $routes['news'] = array( 'provider' => 'news', 'subtype' => '' );
    } else {
        $routes['posts'] = array( 'provider' => 'posts', 'subtype' => 'post' );
    }

    if ( ace_sitemap_powertools_is_enabled( 'enable_authors_provider' ) ) {
        $routes['authors'] = array( 'provider' => 'authors', 'subtype' => '' );
    }

    if ( ace_sitemap_powertools_is_enabled( 'enable_categories_route' ) ) {
        $routes['categories'] = array( 'provider' => 'taxonomies', 'subtype' => 'category' );
    }

    if ( ace_sitemap_powertools_is_enabled( 'enable_tags_route' ) ) {
        $routes['tags'] = array( 'provider' => 'taxonomies', 'subtype' => 'post_tag' );
    }

    $taxonomies = get_taxonomies(
        array(
            'public' => true,
        ),
        'objects'
    );

    foreach ( $taxonomies as $taxonomy => $taxonomy_object ) {
        if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
            continue;
        }

        if ( in_array( $taxonomy, $excluded_taxonomies, true ) ) {
            continue;
        }

        $slug = $taxonomy;
        if ( isset( $taxonomy_object->rewrite['slug'] ) && is_string( $taxonomy_object->rewrite['slug'] ) ) {
            $slug = $taxonomy_object->rewrite['slug'];
        }

        $slug = trim( (string) $slug, '/' );
        if ( '' === $slug || isset( $routes[ $slug ] ) || in_array( $slug, $excluded_slugs, true ) ) {
            continue;
        }

        $routes[ $slug ] = array(
            'provider' => 'taxonomies',
            'subtype'  => $taxonomy,
        );
    }

    $post_types = get_post_types( array( 'public' => true ), 'objects' );
    unset( $post_types['attachment'] );
    if ( ace_sitemap_powertools_is_enabled( 'enable_news_provider' ) ) {
        unset( $post_types['post'] );
    }

    foreach ( $post_types as $post_type => $post_type_object ) {
        if ( in_array( $post_type, $excluded_post_types, true ) ) {
            continue;
        }

        $slug = $post_type;
        $subtype = $post_type;
        if ( isset( $post_type_object->rewrite['slug'] ) && is_string( $post_type_object->rewrite['slug'] ) ) {
            $slug = $post_type_object->rewrite['slug'];
        }

        if ( 'page' === $post_type ) {
            $slug = 'pages';
        }

        if ( 'acemedia_glossary' === $post_type ) {
            $subtype = 'glossary';
        }

        $slug = trim( $slug, '/' );
        if ( ! $slug || isset( $routes[ $slug ] ) || in_array( $slug, $excluded_slugs, true ) ) {
            continue;
        }

        $routes[ $slug ] = array( 'provider' => 'posts', 'subtype' => $subtype );
    }

    foreach ( $excluded_slugs as $slug ) {
        if ( isset( $routes[ $slug ] ) ) {
            unset( $routes[ $slug ] );
        }
    }

    $cache = apply_filters( 'ace_sitemap_powertools_custom_routes', $routes );
    return $cache;
}

function ace_sitemap_powertools_add_rewrite_rules() {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_routes' ) ) {
        return;
    }

    add_rewrite_rule( '^sitemap\\.xml$', 'index.php?sitemap=index', 'top' );
    add_rewrite_rule( '^sitemap\\.xsl$', 'index.php?sitemap-stylesheet=sitemap', 'top' );
    add_rewrite_rule( '^sitemap-index\\.xsl$', 'index.php?sitemap-stylesheet=index', 'top' );

    $routes = ace_sitemap_powertools_custom_routes();
    foreach ( $routes as $slug => $route ) {
        $slug = trim( (string) $slug, '/' );
        if ( '' === $slug ) {
            continue;
        }

        $pattern = preg_quote( $slug, '~' );
        $query   = 'index.php?sitemap=' . $route['provider'] . '&paged=$matches[1]';
        if ( ! empty( $route['subtype'] ) ) {
            $query .= '&sitemap-subtype=' . $route['subtype'];
        }

        add_rewrite_rule( '^' . $pattern . '\\.xml$', 'index.php?sitemap=' . $route['provider'] . '&paged=1' . ( ! empty( $route['subtype'] ) ? '&sitemap-subtype=' . $route['subtype'] : '' ), 'top' );
        add_rewrite_rule( '^' . $pattern . '-([0-9]+)\\.xml$', $query, 'top' );
        add_rewrite_rule( '^' . $pattern . '-([0-9]+)$', $query, 'top' );
    }
}
add_action( 'init', 'ace_sitemap_powertools_add_rewrite_rules' );

function ace_sitemap_powertools_get_cache_version() {
    $version = 0;

    if ( ace_sitemap_powertools_redis_cache_available() ) {
        $version = (int) wp_cache_get( 'ace_sitemap_cache_version', 'ace_sitemap_meta' );
        if ( $version < 1 ) {
            $version = 1;
            wp_cache_set( 'ace_sitemap_cache_version', $version, 'ace_sitemap_meta' );
        }
    } else {
        $version = (int) get_option( 'ace_sitemap_cache_version', 1 );
    }

    return $version > 0 ? $version : 1;
}

function ace_sitemap_powertools_bump_cache_version() {
    if ( ace_sitemap_powertools_redis_cache_available() ) {
        $version = (int) wp_cache_incr( 'ace_sitemap_cache_version', 1, 'ace_sitemap_meta' );
        if ( $version < 1 ) {
            $current = ace_sitemap_powertools_get_cache_version();
            wp_cache_set( 'ace_sitemap_cache_version', $current + 1, 'ace_sitemap_meta' );
        }
        return;
    }

    $version = ace_sitemap_powertools_get_cache_version();
    update_option( 'ace_sitemap_cache_version', $version + 1 );
}

function ace_sitemap_powertools_bump_cache_version_debounced( $scope ) {
    $scope = sanitize_key( (string) $scope );
    if ( '' === $scope ) {
        $scope = 'global';
    }

    $lock_key = 'ace_sitemap_cache_bump_' . $scope;
    if ( ace_sitemap_powertools_redis_cache_available() ) {
        if ( ! wp_cache_add( $lock_key, 1, 'ace_sitemap_locks', 15 ) ) {
            return false;
        }
    } else {
        if ( get_transient( $lock_key ) ) {
            return false;
        }

        set_transient( $lock_key, 1, 15 );
    }
    ace_sitemap_powertools_bump_cache_version();
    return true;
}

function ace_sitemap_powertools_is_public_sitemap_post( $post ) {
    if ( ! ( $post instanceof WP_Post ) ) {
        return false;
    }

    $post_type_object = get_post_type_object( $post->post_type );
    if ( ! $post_type_object ) {
        return false;
    }

    return is_post_type_viewable( $post_type_object );
}

function ace_sitemap_powertools_maybe_bump_cache_version_on_save( $post_id, $post, $update ) {
    if ( ! $update || ! ( $post instanceof WP_Post ) ) {
        return;
    }

    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }

    if ( ! ace_sitemap_powertools_is_public_sitemap_post( $post ) ) {
        return;
    }

    if ( 'publish' !== $post->post_status ) {
        return;
    }

    ace_sitemap_powertools_bump_cache_version_debounced( 'save_post_' . (int) $post_id );
}

function ace_sitemap_powertools_maybe_bump_cache_version_on_transition( $new_status, $old_status, $post ) {
    if ( ! ( $post instanceof WP_Post ) ) {
        return;
    }

    if ( wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) ) {
        return;
    }

    if ( ! ace_sitemap_powertools_is_public_sitemap_post( $post ) ) {
        return;
    }

    if ( $new_status === $old_status ) {
        return;
    }

    if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
        return;
    }

    ace_sitemap_powertools_bump_cache_version_debounced( 'transition_post_' . (int) $post->ID );
}

function ace_sitemap_powertools_maybe_bump_cache_version_on_delete( $post_id, $post ) {
    if ( ! ( $post instanceof WP_Post ) ) {
        return;
    }

    if ( ! ace_sitemap_powertools_is_public_sitemap_post( $post ) ) {
        return;
    }

    if ( 'publish' !== $post->post_status ) {
        return;
    }

    ace_sitemap_powertools_bump_cache_version_debounced( 'delete_post_' . (int) $post_id );
}

function ace_sitemap_powertools_cache_get_stale( $key ) {
    if ( ! ace_sitemap_powertools_cache_enabled() ) {
        return null;
    }

    if ( ace_sitemap_powertools_redis_cache_available() ) {
        $found  = false;
        $stale  = wp_cache_get( 'stale:' . $key, 'ace_sitemap', false, $found );
        return $found ? $stale : null;
    }

    $option_key = ace_sitemap_powertools_cache_option_key( $key );
    $stored     = get_option( $option_key );

    if ( ! is_array( $stored ) || ! array_key_exists( 'data', $stored ) ) {
        return null;
    }

    return $stored['data'];
}

function ace_sitemap_powertools_cache_lock_key( $key ) {
    return 'ace_sitemap_cache_lock_' . md5( (string) $key );
}

function ace_sitemap_powertools_cache_acquire_lock( $key ) {
    $lock_key = ace_sitemap_powertools_cache_lock_key( $key );
    if ( ace_sitemap_powertools_redis_cache_available() ) {
        return wp_cache_add( $lock_key, 1, 'ace_sitemap_locks', 30 );
    }

    if ( get_transient( $lock_key ) ) {
        return false;
    }
    set_transient( $lock_key, 1, 30 );
    return true;
}

function ace_sitemap_powertools_cache_release_lock( $key ) {
    if ( ace_sitemap_powertools_redis_cache_available() ) {
        wp_cache_delete( ace_sitemap_powertools_cache_lock_key( $key ), 'ace_sitemap_locks' );
        return;
    }

    delete_transient( ace_sitemap_powertools_cache_lock_key( $key ) );
}

function ace_sitemap_powertools_cache_remember( $key, $callback, $fallback = null ) {
    $cached = ace_sitemap_powertools_cache_get( $key );
    if ( null !== $cached ) {
        return $cached;
    }

    $stale = ace_sitemap_powertools_cache_get_stale( $key );
    if ( ! ace_sitemap_powertools_cache_acquire_lock( $key ) ) {
        if ( null !== $stale ) {
            return $stale;
        }

        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            usleep( 250000 );
            $cached = ace_sitemap_powertools_cache_get( $key );
            if ( null !== $cached ) {
                return $cached;
            }
        }

        return $fallback;
    }

    try {
        $value = is_callable( $callback ) ? call_user_func( $callback ) : $fallback;
        ace_sitemap_powertools_cache_set( $key, $value );
        return $value;
    } finally {
        ace_sitemap_powertools_cache_release_lock( $key );
    }
}

function ace_sitemap_powertools_get_cache_ttl() {
    $ttl = (int) ace_sitemap_powertools_get_option( 'sitemap_cache_ttl' );
    if ( $ttl < 60 ) {
        $ttl = 60;
    }
    return (int) apply_filters( 'ace_sitemap_powertools_cache_ttl', $ttl );
}

function ace_sitemap_powertools_cache_enabled() {
    return ace_sitemap_powertools_is_enabled( 'enable_sitemap_cache' ) && ace_sitemap_powertools_redis_cache_available();
}

function ace_sitemap_powertools_purge_cache() {
    if ( ace_sitemap_powertools_redis_cache_available() ) {
        ace_sitemap_powertools_bump_cache_version();
        return;
    }

    global $wpdb;

    if ( ! $wpdb ) {
        return;
    }

    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ace_sitemap_cache_%'" );
    ace_sitemap_powertools_bump_cache_version();
}

function ace_sitemap_powertools_cache_option_key( $key ) {
    return 'ace_sitemap_cache_' . md5( (string) $key );
}

function ace_sitemap_powertools_cache_get( $key ) {
    if ( ! ace_sitemap_powertools_cache_enabled() ) {
        return null;
    }

    $found = false;
    $cached = wp_cache_get( $key, 'ace_sitemap', false, $found );
    if ( $found ) {
        return $cached;
    }

    if ( ! ace_sitemap_powertools_redis_cache_available() ) {
        $option_key = ace_sitemap_powertools_cache_option_key( $key );
        $stored = get_option( $option_key );
        if ( ! is_array( $stored ) || ! isset( $stored['t'] ) || ! array_key_exists( 'data', $stored ) ) {
            return null;
        }

        $ttl = ace_sitemap_powertools_get_cache_ttl();
        if ( $ttl > 0 && ( time() - (int) $stored['t'] ) > $ttl ) {
            return null;
        }

        return $stored['data'];
    }

    return null;
}

function ace_sitemap_powertools_cache_set( $key, $value ) {
    if ( ! ace_sitemap_powertools_cache_enabled() ) {
        return;
    }

    $ttl = ace_sitemap_powertools_get_cache_ttl();
    wp_cache_set( $key, $value, 'ace_sitemap', $ttl );
    wp_cache_set( 'stale:' . $key, $value, 'ace_sitemap', max( $ttl * 2, $ttl + 60 ) );

    if ( ! ace_sitemap_powertools_redis_cache_available() ) {
        $option_key = ace_sitemap_powertools_cache_option_key( $key );
        update_option( $option_key, array( 't' => time(), 'data' => $value ), false );
    }
}

function ace_sitemap_powertools_filter_redis_cache_exclusions( $exclusions ) {
    if ( ! ace_sitemap_powertools_cache_enabled() ) {
        return $exclusions;
    }

    $exclusions[] = 'sitemap.xml';
    $exclusions[] = 'wp-sitemap';
    $exclusions[] = 'sitemap-stylesheet';

    $routes = function_exists( 'ace_sitemap_powertools_custom_routes' ) ? ace_sitemap_powertools_custom_routes() : array();
    foreach ( array_keys( (array) $routes ) as $slug ) {
        $slug = trim( (string) $slug );
        if ( '' !== $slug ) {
            $exclusions[] = $slug . '.xml';
            $exclusions[] = $slug . '-';
        }
    }

    return array_values( array_unique( array_filter( $exclusions ) ) );
}
add_filter( 'ace_redis_cache_excluded_urls', 'ace_sitemap_powertools_filter_redis_cache_exclusions' );

function ace_sitemap_powertools_filter_redis_sitemap_prime_delay( $delay ) {
    if ( ! ace_sitemap_powertools_cache_enabled() ) {
        return $delay;
    }

    return max( 300, ace_sitemap_powertools_get_cache_ttl() );
}
add_filter( 'ace_rc_sitemap_prime_delay', 'ace_sitemap_powertools_filter_redis_sitemap_prime_delay' );

// Invalidate sitemap cache only when public sitemap content changes.
add_action( 'save_post', 'ace_sitemap_powertools_maybe_bump_cache_version_on_save', 20, 3 );
add_action( 'deleted_post', 'ace_sitemap_powertools_maybe_bump_cache_version_on_delete', 20, 2 );
add_action( 'transition_post_status', 'ace_sitemap_powertools_maybe_bump_cache_version_on_transition', 20, 3 );
add_action( 'edited_term', 'ace_sitemap_powertools_bump_cache_version', 20, 0 );
add_action( 'delete_term', 'ace_sitemap_powertools_bump_cache_version', 20, 0 );
add_action( 'user_register', 'ace_sitemap_powertools_bump_cache_version', 20, 0 );
add_action( 'profile_update', 'ace_sitemap_powertools_bump_cache_version', 20, 0 );

function ace_sitemap_powertools_handle_purge_cache_request() {
    if ( ! is_admin() ) {
        return;
    }

    if ( empty( $_POST['ace_sitemap_powertools_purge_cache'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( empty( $_POST['ace_sitemap_powertools_purge_cache_nonce'] ) || ! wp_verify_nonce( $_POST['ace_sitemap_powertools_purge_cache_nonce'], 'ace_sitemap_purge_cache' ) ) {
        return;
    }

    ace_sitemap_powertools_purge_cache();
    update_option( 'ace_sitemap_powertools_purge_notice', 1 );
}
add_action( 'admin_init', 'ace_sitemap_powertools_handle_purge_cache_request' );

function ace_sitemap_powertools_ajax_purge_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    check_ajax_referer( 'ace_sitemap_purge_cache', 'nonce' );

    ace_sitemap_powertools_purge_cache();
    wp_send_json_success( array(
        'cache_version' => ace_sitemap_powertools_get_cache_version(),
    ) );
}
add_action( 'wp_ajax_ace_sitemap_purge_cache', 'ace_sitemap_powertools_ajax_purge_cache' );

function ace_sitemap_powertools_admin_notices() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && false === strpos( (string) $screen->id, 'ace-seo-settings' ) ) {
        return;
    }

    if ( get_option( 'ace_sitemap_powertools_large_site_notice' ) ) {
        echo '<div class="notice notice-info is-dismissible"><p><strong>Ace Sitemap:</strong> Large site detected (1000+ posts). We disabled sitemap index <code>lastmod</code> by default to reduce expensive cold-load work while keeping per-URL <code>lastmod</code> in sub-sitemaps. You can re-enable it in the Sitemaps → Caching section.</p></div>';
        delete_option( 'ace_sitemap_powertools_large_site_notice' );
    }

    if ( get_option( 'ace_sitemap_powertools_purge_notice' ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Sitemap cache cleared.</p></div>';
        delete_option( 'ace_sitemap_powertools_purge_notice' );
    }
}
add_action( 'admin_notices', 'ace_sitemap_powertools_admin_notices' );

function ace_sitemap_powertools_serve_custom_routes() {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_routes' ) || ! ace_sitemap_powertools_is_enabled( 'serve_custom_routes' ) ) {
        return;
    }

    if ( headers_sent() ) {
        return;
    }

    $requested_sitemap = sanitize_key( (string) get_query_var( 'sitemap' ) );
    if ( '' !== $requested_sitemap ) {
        $wp_sitemaps = wp_sitemaps_get_server();

        if ( 'index' === $requested_sitemap ) {
            $sitemap_list = ace_sitemap_powertools_get_cached_index_list( $wp_sitemaps );
            if ( ace_sitemap_powertools_should_render_human_view() ) {
                ace_sitemap_powertools_render_index_html( $sitemap_list );
            } else {
                ace_sitemap_powertools_render_index_xml( $wp_sitemaps, $sitemap_list );
            }
            exit;
        }

        $route = array(
            'provider' => $requested_sitemap,
            'subtype'  => sanitize_key( (string) get_query_var( 'sitemap-subtype' ) ),
        );
        $page = absint( get_query_var( 'paged' ) );
        if ( $page < 1 ) {
            $page = 1;
        }

        $provider = $wp_sitemaps->registry->get_provider( $route['provider'] );
        if ( ! $provider ) {
            return;
        }

        $url_list = ace_sitemap_powertools_get_cached_url_list( $provider, $route, $page );
        if ( empty( $url_list ) ) {
            global $wp_query;
            if ( $wp_query ) {
                $wp_query->set_404();
            }
            status_header( 404 );
            return;
        }

        if ( ace_sitemap_powertools_should_render_human_view() ) {
            ace_sitemap_powertools_render_sitemap_html( $url_list );
        } else {
            ace_sitemap_powertools_render_sitemap_xml( $wp_sitemaps, $url_list );
        }
        exit;
    }

    $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
    if ( ! $path ) {
        return;
    }

    $path = trim( $path, '/' );
    if ( 'sitemap.xml' === $path ) {
        $wp_sitemaps  = wp_sitemaps_get_server();
        $sitemap_list = ace_sitemap_powertools_get_cached_index_list( $wp_sitemaps );

        if ( ace_sitemap_powertools_should_render_human_view() ) {
            ace_sitemap_powertools_render_index_html( $sitemap_list );
        } else {
            ace_sitemap_powertools_render_index_xml( $wp_sitemaps, $sitemap_list );
        }
        exit;
    }

    $slug = '';
    $page = 0;

    if ( preg_match( '~^([a-z0-9_-]+)\.xml$~i', $path, $matches ) ) {
        $slug = sanitize_key( $matches[1] );
        $page = 1;
    } elseif ( preg_match( '~^([a-z0-9_-]+)-(\d+)(\.xml)?$~i', $path, $matches ) ) {
        $slug = sanitize_key( $matches[1] );
        $page = max( 1, (int) $matches[2] );
    }

    if ( '' === $slug || $page < 1 ) {
        return;
    }

    $routes = ace_sitemap_powertools_custom_routes();
    if ( ! isset( $routes[ $slug ] ) ) {
        return;
    }

    $route      = $routes[ $slug ];
    $wp_sitemaps = wp_sitemaps_get_server();
    $provider   = $wp_sitemaps->registry->get_provider( $route['provider'] );
    if ( ! $provider ) {
        return;
    }

    $url_list = ace_sitemap_powertools_get_cached_url_list( $provider, $route, $page );
    if ( empty( $url_list ) ) {
        global $wp_query;
        if ( $wp_query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        return;
    }

    if ( ace_sitemap_powertools_should_render_human_view() ) {
        ace_sitemap_powertools_render_sitemap_html( $url_list );
    } else {
        ace_sitemap_powertools_render_sitemap_xml( $wp_sitemaps, $url_list );
    }
    exit;
}
add_action( 'template_redirect', 'ace_sitemap_powertools_serve_custom_routes', 0 );

/**
 * Return 410 Gone for legacy sitemap URL patterns that no longer exist on this site.
 *
 * Runs at template_redirect priority -1, before the serve function, so the
 * response exits immediately with no sitemap processing cost.
 *
 * Each site maintains its own pattern list via the filter below. Add patterns
 * when migrating away from another sitemap plugin that left old URLs indexed.
 *
 * @return void
 */
function ace_sitemap_powertools_handle_legacy_gone(): void {
    $path = isset( $_SERVER['REQUEST_URI'] )
        ? wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH )
        : '';

    if ( ! $path ) {
        return;
    }

    $path = ltrim( $path, '/' );

    /**
     * Regex patterns matched against the URL path, leading slash stripped,
     * that should return 410 Gone.
     *
     * @param string[] $patterns Array of PCRE regex patterns.
     */
    $patterns = apply_filters(
        'ace_sitemap_powertools_legacy_gone_patterns',
        array(
            '~^post-sitemap\d*(-\d+)?\.xml$~i',
            '~^image-sitemap(-\d+)?\.xml$~i',
            '~^news-sitemap(-\d+)?\.xml$~i',
            '~^sitemap-\d+\.xml$~i',
        )
    );

    foreach ( $patterns as $pattern ) {
        if ( preg_match( $pattern, $path ) ) {
            nocache_headers();
            status_header( 410 );

            if ( ! headers_sent() ) {
                header( 'Content-Type: text/html; charset=UTF-8' );
            }

            echo '<!DOCTYPE html><html><head><title>410 Gone</title></head><body>'
                . '<h1>Gone</h1><p>This sitemap URL no longer exists.</p>'
                . '</body></html>';
            exit;
        }
    }
}
add_action( 'template_redirect', 'ace_sitemap_powertools_handle_legacy_gone', -1 );

function ace_sitemap_powertools_get_cached_index_list( $wp_sitemaps ) {
    $sitemap_list = null;

    if ( ace_sitemap_powertools_cache_enabled() ) {
        $cache_key    = 'index_list_v' . ace_sitemap_powertools_get_cache_version();
        $sitemap_list = ace_sitemap_powertools_cache_get( $cache_key );
    }

    if ( ! is_array( $sitemap_list ) ) {
        $sitemap_list = $wp_sitemaps->index->get_sitemap_list();
        if ( ace_sitemap_powertools_cache_enabled() && is_array( $sitemap_list ) ) {
            ace_sitemap_powertools_cache_set( $cache_key, $sitemap_list );
        }
    }

    return is_array( $sitemap_list ) ? $sitemap_list : array();
}

function ace_sitemap_powertools_get_cached_url_list( $provider, $route, $page ) {
    $url_list = null;

    if ( ace_sitemap_powertools_cache_enabled() ) {
        $cache_key = sprintf(
            'url_list_v%d_%s_%s_%d',
            ace_sitemap_powertools_get_cache_version(),
            sanitize_key( (string) $route['provider'] ),
            sanitize_key( (string) $route['subtype'] ),
            (int) $page
        );
        $url_list = ace_sitemap_powertools_cache_get( $cache_key );
    }

    if ( ! is_array( $url_list ) ) {
        $url_list = $provider->get_url_list( $page, $route['subtype'] );
        if ( ace_sitemap_powertools_cache_enabled() && is_array( $url_list ) ) {
            ace_sitemap_powertools_cache_set( $cache_key, $url_list );
        }
    }

    return is_array( $url_list ) ? $url_list : array();
}

function ace_sitemap_powertools_is_probable_bot_request() {
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
    if ( '' === $ua ) {
        return true;
    }

    $needles = array(
        'bot',
        'crawler',
        'spider',
        'slurp',
        'bingpreview',
        'mediapartners-google',
        'adsbot',
        'google-read-aloud',
        'googleother',
        'google-safety',
        'facebookexternalhit',
        'linkedinbot',
        'duckduckbot',
        'applebot',
        'yandex',
        'baiduspider',
        'ahrefsbot',
        'semrushbot',
    );

    foreach ( $needles as $needle ) {
        if ( false !== strpos( $ua, $needle ) ) {
            return true;
        }
    }

    return false;
}

function ace_sitemap_powertools_should_render_human_view() {
    if ( isset( $_GET['bot'] ) ) {
        return false;
    }

    if ( isset( $_GET['human'] ) ) {
        return true;
    }

    if ( ace_sitemap_powertools_is_probable_bot_request() ) {
        return false;
    }

    $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT'] ) : '';

    if ( '' === $accept ) {
        return true;
    }

    return false !== strpos( $accept, 'text/html' );
}

function ace_sitemap_powertools_render_index_xml( $wp_sitemaps, $sitemap_list ) {
    if ( ! isset( $wp_sitemaps->renderer ) || ! is_object( $wp_sitemaps->renderer ) ) {
        return;
    }

    ace_sitemap_powertools_send_response_cache_headers();
    header( 'Content-Type: application/xml; charset=UTF-8' );
    $xml = $wp_sitemaps->renderer->get_sitemap_index_xml( $sitemap_list );
    if ( ! empty( $xml ) ) {
        echo $xml;
    }
}

function ace_sitemap_powertools_render_sitemap_xml( $wp_sitemaps, $url_list ) {
    if ( ! isset( $wp_sitemaps->renderer ) || ! is_object( $wp_sitemaps->renderer ) ) {
        return;
    }

    ace_sitemap_powertools_send_response_cache_headers();
    header( 'Content-Type: application/xml; charset=UTF-8' );
    $xml = $wp_sitemaps->renderer->get_sitemap_xml( $url_list );
    if ( ! empty( $xml ) ) {
        echo $xml;
    }
}

function ace_sitemap_powertools_send_response_cache_headers() {
    $ttl = (int) apply_filters(
        'ace_sitemap_powertools_response_cache_ttl',
        ace_sitemap_powertools_get_cache_ttl()
    );

    if ( $ttl < 60 ) {
        $ttl = 60;
    }

    if ( ! headers_sent() ) {
        header( 'Cache-Control: public, max-age=' . $ttl . ', must-revalidate' );
        header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $ttl ) . ' GMT' );
        header( 'X-Robots-Tag: noindex, follow', true );
    }
}

function ace_sitemap_powertools_get_human_view_css() {
    if ( class_exists( 'WP_Sitemaps_Stylesheet' ) ) {
        $stylesheet = new WP_Sitemaps_Stylesheet();
        if ( method_exists( $stylesheet, 'get_stylesheet_css' ) ) {
            return (string) $stylesheet->get_stylesheet_css();
        }
    }

    return '#sitemap{font-family:Arial,sans-serif;max-width:980px;margin:0 auto;padding:16px}#sitemap__table{width:100%;border-collapse:collapse}#sitemap__table th,#sitemap__table td{border:1px solid #ddd;padding:8px;text-align:left}#sitemap__table thead{background:#f5f5f5}.text{margin:12px 0}';
}

function ace_sitemap_powertools_get_human_theme_stylesheet_links() {
    $urls = array();

    if ( function_exists( 'get_stylesheet_uri' ) ) {
        $main = get_stylesheet_uri();
        if ( is_string( $main ) && '' !== $main ) {
            $urls[] = $main;
        }
    }

    if ( function_exists( 'get_stylesheet_directory' ) && function_exists( 'get_stylesheet_directory_uri' ) ) {
        $theme_dir = trailingslashit( get_stylesheet_directory() );
        $theme_uri = trailingslashit( get_stylesheet_directory_uri() );

        if ( file_exists( $theme_dir . 'library/css/style.css' ) ) {
            $urls[] = $theme_uri . 'library/css/style.css';
        }

        if ( file_exists( $theme_dir . 'library/css/dark-mode.css' ) ) {
            $urls[] = $theme_uri . 'library/css/dark-mode.css';
        }
    }

    return array_values( array_unique( $urls ) );
}

function ace_sitemap_powertools_get_human_theme_global_styles() {
    if ( ! function_exists( 'wp_get_global_stylesheet' ) ) {
        return '';
    }

    $css = wp_get_global_stylesheet( array( 'variables', 'presets', 'base' ) );
    return is_string( $css ) ? $css : '';
}

function ace_sitemap_powertools_get_human_paddy_font_face_css() {
    if ( ! function_exists( 'get_stylesheet_directory' ) || ! function_exists( 'get_stylesheet_directory_uri' ) ) {
        return '';
    }

    $base_dir = trailingslashit( get_stylesheet_directory() ) . 'library/fonts/Paddy-Sans/Web/WOFF2/';
    $base_uri = trailingslashit( get_stylesheet_directory_uri() ) . 'library/fonts/Paddy-Sans/Web/WOFF2/';

    $variants = array(
        array( 'file' => 'Paddy_Sans-Light-web-1_1.woff2', 'weight' => 300 ),
        array( 'file' => 'Paddy_Sans-Regular-web-1_1.woff2', 'weight' => 400 ),
        array( 'file' => 'Paddy_Sans-Medium-web-1_1.woff2', 'weight' => 500 ),
        array( 'file' => 'Paddy_Sans-Semi_Bold-web-1_1.woff2', 'weight' => 600 ),
        array( 'file' => 'Paddy_Sans-Bold-web-1_1.woff2', 'weight' => 700 ),
    );

    $css = '';
    foreach ( $variants as $variant ) {
        $path = $base_dir . $variant['file'];
        if ( ! file_exists( $path ) ) {
            continue;
        }

        $css .= '@font-face{font-family:"Paddy Sans";font-style:normal;font-weight:' . (int) $variant['weight'] . ';font-display:swap;src:url("' . esc_url_raw( $base_uri . $variant['file'] ) . '") format("woff2");}';
    }

    return $css;
}

function ace_sitemap_powertools_get_human_theme_override_css() {
    return '#sitemap,#sitemap *{font-family:var(--wp--preset--font-family--paddy-sans,"Paddy Sans",sans-serif) !important;}body{background:var(--wp--preset--color--background,#fff);color:var(--wp--preset--color--foreground,#1a1a1a);}#sitemap__table{border-color:var(--wp--preset--color--lightgrey,#d9d9d9);}#sitemap__table th,#sitemap__table td{border-color:var(--wp--preset--color--lightgrey,#d9d9d9);}#sitemap__table tbody tr:nth-child(odd){background:color-mix(in srgb,var(--wp--preset--color--background,#fff) 94%,var(--wp--preset--color--tertiary,#67B650) 6%);}#sitemap__table tbody tr:hover{background:color-mix(in srgb,var(--wp--preset--color--background,#fff) 85%,var(--wp--preset--color--primary,#1BAA55) 15%);}#sitemap__table tr td.loc a,#sitemap__table tr td.loc span{color:var(--wp--preset--color--tertiary,#004833);}#sitemap__table tr td.loc a:hover{color:var(--wp--preset--color--primary,#1BAA55);}#sitemap__header p,#sitemap__content .text{color:var(--wp--preset--color--darkgrey,#666);}.sitemap-brand__title{color:var(--wp--preset--color--primary,#1BAA55);}';
}

function ace_sitemap_powertools_render_html_header( $title, $count, $context ) {
    ace_sitemap_powertools_send_response_cache_headers();
    header( 'Content-Type: text/html; charset=UTF-8' );
    status_header( 200 );

    $title_text = esc_html( $title );
    $css        = ace_sitemap_powertools_get_human_view_css();
    $font_face  = ace_sitemap_powertools_get_human_paddy_font_face_css();
    $theme_css  = ace_sitemap_powertools_get_human_theme_global_styles();
    $overrides  = ace_sitemap_powertools_get_human_theme_override_css();
    $theme_links = ace_sitemap_powertools_get_human_theme_stylesheet_links();
    $lang       = function_exists( 'get_language_attributes' ) ? get_language_attributes( 'html' ) : 'lang="en"';
    $learn_more = sprintf(
        '<a href="%s">%s</a>',
        esc_url( __( 'https://www.sitemaps.org/' ) ),
        esc_html__( 'Learn more about XML sitemaps.' )
    );
    $summary = sprintf(
        esc_html__( 'Number of URLs in this XML Sitemap: %s.' ),
        number_format_i18n( (int) $count )
    );

    $header_html = ace_sitemap_powertools_stylesheet_header_html( $context );
    if ( '' === $header_html ) {
        $description = esc_html__( 'This XML Sitemap is generated by WordPress to make your content more visible for search engines.' );
        $header_html = '<div id="sitemap__header"><h1>' . $title_text . '</h1><p>' . $description . '</p><p>' . $learn_more . '</p></div>';
    }

    echo '<!doctype html><html ' . $lang . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $title_text . '</title>';
    foreach ( $theme_links as $theme_link ) {
        echo '<link rel="stylesheet" href="' . esc_url( $theme_link ) . '" />';
    }
    echo '<style>' . $font_face . "\n" . $theme_css . "\n" . $css . "\n" . $overrides . '</style></head><body><div id="sitemap">';
    echo $header_html;
    echo '<div id="sitemap__content"><p class="text">' . esc_html( $summary ) . '</p>';
}

function ace_sitemap_powertools_render_html_footer() {
    echo '</div></div></body></html>';
}

function ace_sitemap_powertools_is_page_one_loc( $loc ) {
    if ( ! is_string( $loc ) || '' === $loc ) {
        return false;
    }

    return (bool) preg_match( '~(?:-1\.xml|-1(?:$|\?)|[?&]paged=1(?:$|&))~i', $loc );
}

function ace_sitemap_powertools_recent_prefix_from_loc( $loc ) {
    if ( false !== strpos( $loc, '-1.xml' ) ) {
        return preg_replace( '~-1\.xml.*$~i', '-', $loc );
    }

    if ( false !== strpos( $loc, '-1' ) ) {
        return preg_replace( '~-1(?:$|\?).*$~i', '-', $loc );
    }

    if ( false !== strpos( $loc, '&paged=1' ) ) {
        return substr( $loc, 0, strpos( $loc, '&paged=1' ) ) . 'paged=';
    }

    if ( false !== strpos( $loc, '?paged=1' ) ) {
        return substr( $loc, 0, strpos( $loc, '?paged=1' ) ) . 'paged=';
    }

    return '';
}

function ace_sitemap_powertools_html_should_mark_recent( $loc, $sitemap_list ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_recent_marker' ) ) {
        return false;
    }

    if ( ! ace_sitemap_powertools_is_page_one_loc( $loc ) ) {
        return false;
    }

    $prefix = ace_sitemap_powertools_recent_prefix_from_loc( $loc );
    if ( '' === $prefix ) {
        return false;
    }

    $count = 0;
    foreach ( (array) $sitemap_list as $entry ) {
        $entry_loc = isset( $entry['loc'] ) ? (string) $entry['loc'] : '';
        if ( '' !== $entry_loc && 0 === strpos( $entry_loc, $prefix ) ) {
            $count++;
        }
    }

    return $count >= 5;
}

function ace_sitemap_powertools_render_index_html( $sitemap_list ) {
    if ( ! is_array( $sitemap_list ) ) {
        $sitemap_list = array();
    }

    $has_lastmod = false;
    foreach ( $sitemap_list as $entry ) {
        if ( ! empty( $entry['lastmod'] ) ) {
            $has_lastmod = true;
            break;
        }
    }

    ace_sitemap_powertools_render_html_header( 'XML Sitemap', count( $sitemap_list ), 'index' );
    echo '<table id="sitemap__table"><thead><tr><th class="loc">' . esc_html__( 'URL' ) . '</th>';
    if ( $has_lastmod ) {
        echo '<th class="lastmod">' . esc_html__( 'Last Modified' ) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ( $sitemap_list as $entry ) {
        $loc     = isset( $entry['loc'] ) ? esc_url( $entry['loc'] ) : '';
        $raw_loc = isset( $entry['loc'] ) ? (string) $entry['loc'] : '';
        $lastmod = isset( $entry['lastmod'] ) ? esc_html( (string) $entry['lastmod'] ) : '—';
        if ( '' === $loc ) {
            continue;
        }

        $is_recent = ace_sitemap_powertools_html_should_mark_recent( $raw_loc, $sitemap_list );

        echo '<tr><td class="loc">';
        if ( $is_recent ) {
            echo '<strong><a href="' . $loc . '">' . $loc . '</a></strong> <span class="muted">(most recent)</span>';
        } else {
            echo '<a href="' . $loc . '">' . $loc . '</a>';
        }
        echo '</td>';
        if ( $has_lastmod ) {
            echo '<td class="lastmod">' . $lastmod . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
    ace_sitemap_powertools_render_html_footer();
}

function ace_sitemap_powertools_render_sitemap_html( $url_list ) {
    if ( ! is_array( $url_list ) ) {
        $url_list = array();
    }

    $has_lastmod    = false;
    $has_changefreq = false;
    $has_priority   = false;
    foreach ( $url_list as $entry ) {
        if ( ! empty( $entry['lastmod'] ) ) {
            $has_lastmod = true;
        }
        if ( ! empty( $entry['changefreq'] ) ) {
            $has_changefreq = true;
        }
        if ( ! empty( $entry['priority'] ) ) {
            $has_priority = true;
        }
    }

    ace_sitemap_powertools_render_html_header( 'XML Sitemap', count( $url_list ), 'sitemap' );
    echo '<table id="sitemap__table"><thead><tr><th class="loc">' . esc_html__( 'URL' ) . '</th>';
    if ( $has_lastmod ) {
        echo '<th class="lastmod">' . esc_html__( 'Last Modified' ) . '</th>';
    }
    if ( $has_changefreq ) {
        echo '<th class="changefreq">' . esc_html__( 'Change Frequency' ) . '</th>';
    }
    if ( $has_priority ) {
        echo '<th class="priority">' . esc_html__( 'Priority' ) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ( $url_list as $entry ) {
        $loc        = isset( $entry['loc'] ) ? esc_url( $entry['loc'] ) : '';
        $lastmod    = isset( $entry['lastmod'] ) ? esc_html( (string) $entry['lastmod'] ) : '—';
        $changefreq = isset( $entry['changefreq'] ) ? esc_html( (string) $entry['changefreq'] ) : '—';
        $priority   = isset( $entry['priority'] ) ? esc_html( (string) $entry['priority'] ) : '—';

        if ( '' === $loc ) {
            continue;
        }

        echo '<tr><td class="loc"><a href="' . $loc . '">' . $loc . '</a></td>';
        if ( $has_lastmod ) {
            echo '<td class="lastmod">' . $lastmod . '</td>';
        }
        if ( $has_changefreq ) {
            echo '<td class="changefreq">' . $changefreq . '</td>';
        }
        if ( $has_priority ) {
            echo '<td class="priority">' . $priority . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
    ace_sitemap_powertools_render_html_footer();
}

function ace_sitemap_powertools_redirect_legacy_urls() {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_routes' ) || ! ace_sitemap_powertools_is_enabled( 'enable_legacy_redirects' ) ) {
        return;
    }

    if ( headers_sent() ) {
        return;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( (string) $_SERVER['REQUEST_URI'] ) : '';
    $is_legacy   = false !== strpos( $request_uri, 'wp-sitemap' )
        || false !== strpos( $request_uri, 'sitemap=index' )
        || false !== strpos( $request_uri, 'sitemap-stylesheet=' );

    if ( ! $is_legacy ) {
        return;
    }

    $stylesheet = get_query_var( 'sitemap-stylesheet' );
    if ( $stylesheet ) {
        if ( false === strpos( $request_uri, 'sitemap-stylesheet=' ) ) {
            $target = home_url( '/?sitemap-stylesheet=' . $stylesheet );
            wp_safe_redirect( $target, 301 );
            exit;
        }

        return;
    }

    $sitemap = get_query_var( 'sitemap' );
    if ( 'index' === $sitemap ) {
        wp_safe_redirect( home_url( '/sitemap.xml' ), 301 );
        exit;
    }

    if ( $sitemap ) {
        $subtype = get_query_var( 'sitemap-subtype' );
        $page    = absint( get_query_var( 'paged' ) );
        if ( $page < 1 ) {
            $page = 1;
        }

        $target = ace_sitemap_powertools_build_custom_url( $sitemap, $subtype, $page );
        if ( $target ) {
            wp_safe_redirect( $target, 301 );
            exit;
        }
    }
}
add_action( 'template_redirect', 'ace_sitemap_powertools_redirect_legacy_urls', 0 );

function ace_sitemap_powertools_disable_canonical_redirects( $redirect_url, $requested_url ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'disable_canonical_redirects' ) ) {
        return $redirect_url;
    }

    if ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
        return false;
    }

    $path = '';
    if ( is_string( $requested_url ) && $requested_url ) {
        $path = wp_parse_url( $requested_url, PHP_URL_PATH );
    }

    if ( ! $path && isset( $_SERVER['REQUEST_URI'] ) ) {
        $path = wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    }

    if ( $path && preg_match( '~/(sitemap\.xml|sitemap\.xsl|sitemap-index\.xsl)$~i', $path ) ) {
        return false;
    }

    if ( $path && false !== stripos( $path, 'wp-sitemap' ) ) {
        return false;
    }

    if ( $path ) {
        $routes = ace_sitemap_powertools_custom_routes();
        foreach ( array_keys( $routes ) as $slug ) {
            if ( preg_match( '~/' . preg_quote( $slug, '~' ) . '-\d+(\.xml)?$~i', $path ) ) {
                return false;
            }
        }
    }

    return $redirect_url;
}
add_filter( 'redirect_canonical', 'ace_sitemap_powertools_disable_canonical_redirects', 0, 2 );

function ace_sitemap_powertools_stylesheet_urls( $url, $type ) {
    if ( 'sitemap' === $type ) {
        return home_url( '/?sitemap-stylesheet=sitemap&v=' . ACE_SITEMAP_POWERTOOLS_VERSION );
    }

    return home_url( '/?sitemap-stylesheet=index&v=' . ACE_SITEMAP_POWERTOOLS_VERSION );
}

function ace_sitemap_powertools_stylesheet_url( $url ) {
    return false;
}
add_filter( 'wp_sitemaps_stylesheet_url', 'ace_sitemap_powertools_stylesheet_url' );

function ace_sitemap_powertools_stylesheet_index_url( $url ) {
    return false;
}
add_filter( 'wp_sitemaps_stylesheet_index_url', 'ace_sitemap_powertools_stylesheet_index_url' );

function ace_sitemap_powertools_get_authors_provider() {
    if ( ! class_exists( 'WP_Sitemaps_Users' ) ) {
        return null;
    }

    if ( ! class_exists( 'ACE_Authors_Sitemaps_Provider' ) ) {
        class ACE_Authors_Sitemaps_Provider extends WP_Sitemaps_Users {
            public function __construct() {
                parent::__construct();
                $this->name = 'authors';
            }
        }
    }

    return new ACE_Authors_Sitemaps_Provider();
}

function ace_sitemap_powertools_register_authors_provider( $wp_sitemaps ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_authors_provider' ) ) {
        return;
    }

    $provider = ace_sitemap_powertools_get_authors_provider();
    if ( ! $provider ) {
        return;
    }

    $wp_sitemaps->registry->add_provider( 'authors', $provider );
}
add_action( 'wp_sitemaps_init', 'ace_sitemap_powertools_register_authors_provider' );

function ace_sitemap_powertools_disable_users_provider( $provider, $name ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_authors_provider' ) ) {
        return $provider;
    }

    if ( 'users' !== $name ) {
        return $provider;
    }

    return false;
}
add_filter( 'wp_sitemaps_add_provider', 'ace_sitemap_powertools_disable_users_provider', 20, 2 );

function ace_sitemap_powertools_disable_excluded_providers( $provider, $name ) {
    $excluded_providers = ace_sitemap_powertools_excluded_sitemap_providers();
    if ( in_array( sanitize_key( (string) $name ), $excluded_providers, true ) ) {
        return false;
    }

    return $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'ace_sitemap_powertools_disable_excluded_providers', 25, 2 );

function ace_sitemap_powertools_post_types_filter( $post_types ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_news_provider' ) ) {
        $excluded_post_types = ace_sitemap_powertools_excluded_sitemap_post_types();
        foreach ( $excluded_post_types as $post_type ) {
            if ( isset( $post_types[ $post_type ] ) ) {
                unset( $post_types[ $post_type ] );
            }
        }
        return $post_types;
    }

    if ( isset( $post_types['post'] ) ) {
        unset( $post_types['post'] );
    }

    $excluded_post_types = ace_sitemap_powertools_excluded_sitemap_post_types();
    foreach ( $excluded_post_types as $post_type ) {
        if ( isset( $post_types[ $post_type ] ) ) {
            unset( $post_types[ $post_type ] );
        }
    }

    return $post_types;
}
add_filter( 'wp_sitemaps_post_types', 'ace_sitemap_powertools_post_types_filter' );

function ace_sitemap_powertools_taxonomies_filter( $taxonomies ) {
    $excluded_taxonomies = ace_sitemap_powertools_excluded_sitemap_taxonomies();

    foreach ( $excluded_taxonomies as $taxonomy ) {
        if ( isset( $taxonomies[ $taxonomy ] ) ) {
            unset( $taxonomies[ $taxonomy ] );
        }
    }

    return $taxonomies;
}
add_filter( 'wp_sitemaps_taxonomies', 'ace_sitemap_powertools_taxonomies_filter' );

function ace_sitemap_powertools_should_short_circuit_posts_sitemap( $post_type ) {
    if ( ! post_type_exists( $post_type ) ) {
        return false;
    }

    $post_type_object = get_post_type_object( $post_type );
    if ( ! $post_type_object || ! is_post_type_viewable( $post_type_object ) ) {
        return false;
    }

    return true;
}

function ace_sitemap_powertools_posts_order_sql( $post_type ) {
    global $wpdb;

    if ( 'post' === $post_type ) {
        return "{$wpdb->posts}.post_modified DESC, {$wpdb->posts}.ID DESC";
    }

    return "{$wpdb->posts}.ID ASC";
}

function ace_sitemap_powertools_get_noindex_meta_map( $post_ids ) {
    global $wpdb;

    $post_ids = array_values( array_filter( array_map( 'intval', (array) $post_ids ) ) );
    if ( empty( $post_ids ) ) {
        return array();
    }

    $placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
    $sql          = $wpdb->prepare(
        "SELECT post_id, meta_key, meta_value
        FROM {$wpdb->postmeta}
        WHERE post_id IN ({$placeholders})
            AND meta_key IN ('_ace_seo_meta-robots-noindex', '_yoast_wpseo_meta-robots-noindex')",
        $post_ids
    );
    $rows         = $wpdb->get_results( $sql );
    $excluded_ids = array();

    if ( $rows ) {
        foreach ( $rows as $row ) {
            if ( isset( $row->meta_value ) && '1' === (string) $row->meta_value ) {
                $excluded_ids[ (int) $row->post_id ] = true;
            }
        }
    }

    return $excluded_ids;
}

function ace_sitemap_powertools_get_visible_post_ids_for_page( $post_type, $page_num, $per_page ) {
    global $wpdb;

    $page_num = max( 1, (int) $page_num );
    $per_page = max( 1, (int) $per_page );
    $offset   = ( $page_num - 1 ) * $per_page;
    $collected_ids = array();
    $visible_seen  = 0;
    $scan_offset   = 0;
    $batch_size    = max( 250, $per_page * 2 );
    $order_sql     = ace_sitemap_powertools_posts_order_sql( $post_type );

    while ( count( $collected_ids ) < $per_page ) {
        $sql = $wpdb->prepare(
            "SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = %s
                AND post_status = 'publish'
            ORDER BY {$order_sql}
            LIMIT %d, %d",
            $post_type,
            $scan_offset,
            $batch_size
        );
        $candidate_ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

        if ( empty( $candidate_ids ) ) {
            break;
        }

        $excluded_ids = ace_sitemap_powertools_get_noindex_meta_map( $candidate_ids );
        foreach ( $candidate_ids as $candidate_id ) {
            if ( isset( $excluded_ids[ $candidate_id ] ) ) {
                continue;
            }

            if ( $visible_seen < $offset ) {
                ++$visible_seen;
                continue;
            }

            $collected_ids[] = $candidate_id;
            if ( count( $collected_ids ) >= $per_page ) {
                break;
            }
        }

        if ( count( $candidate_ids ) < $batch_size ) {
            break;
        }

        $scan_offset += $batch_size;
    }

    return $collected_ids;
}

function ace_sitemap_powertools_get_visible_post_count( $post_type ) {
    global $wpdb;

    $published_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(ID)
            FROM {$wpdb->posts}
            WHERE post_type = %s
                AND post_status = 'publish'",
            $post_type
        )
    );

    if ( $published_count < 1 ) {
        return 0;
    }

    $excluded_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p
                ON p.ID = pm.post_id
            WHERE p.post_type = %s
                AND p.post_status = 'publish'
                AND pm.meta_key IN ('_ace_seo_meta-robots-noindex', '_yoast_wpseo_meta-robots-noindex')
                AND pm.meta_value = '1'",
            $post_type
        )
    );

    return max( 0, $published_count - $excluded_count );
}

function ace_sitemap_powertools_build_posts_sitemap_url_list( $post_type, $page_num ) {
    $per_page = ace_sitemap_powertools_effective_post_max_urls();
    $post_ids = ace_sitemap_powertools_get_visible_post_ids_for_page( $post_type, $page_num, $per_page );

    if ( empty( $post_ids ) ) {
        $url_list = array();
    } else {
        $posts = get_posts(
            array(
                'post_type'              => $post_type,
                'post_status'            => 'publish',
                'post__in'               => $post_ids,
                'orderby'                => 'post__in',
                'posts_per_page'         => count( $post_ids ),
                'suppress_filters'       => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $url_list = array();

        if ( 'page' === $post_type && 1 === (int) $page_num && 'posts' === get_option( 'show_on_front' ) ) {
            $sitemap_entry = array(
                'loc' => home_url( '/' ),
            );

            $latest_posts = get_posts(
                array(
                    'post_type'              => 'post',
                    'post_status'            => 'publish',
                    'orderby'                => 'date',
                    'order'                  => 'DESC',
                    'numberposts'            => 1,
                    'suppress_filters'       => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            );

            if ( ! empty( $latest_posts[0]->post_modified_gmt ) ) {
                $sitemap_entry['lastmod'] = wp_date( DATE_W3C, strtotime( $latest_posts[0]->post_modified_gmt ) );
            }

            $url_list[] = apply_filters( 'wp_sitemaps_posts_show_on_front_entry', $sitemap_entry );
        }

        foreach ( $posts as $post ) {
            $sitemap_entry = array(
                'loc'     => get_permalink( $post ),
                'lastmod' => wp_date( DATE_W3C, strtotime( $post->post_modified_gmt ) ),
            );

            $url_list[] = apply_filters( 'wp_sitemaps_posts_entry', $sitemap_entry, $post, $post_type );
        }
    }

    return $url_list;
}

function ace_sitemap_powertools_posts_pre_url_list( $url_list, $post_type, $page_num ) {
    if ( null !== $url_list || ! ace_sitemap_powertools_should_short_circuit_posts_sitemap( $post_type ) ) {
        return $url_list;
    }

    $cache_key = sprintf(
        'posts_url_list_v%d_%s_%d_%d',
        ace_sitemap_powertools_get_cache_version(),
        sanitize_key( $post_type ),
        (int) $page_num,
        ace_sitemap_powertools_effective_post_max_urls()
    );

    return ace_sitemap_powertools_cache_remember(
        $cache_key,
        function () use ( $post_type, $page_num ) {
            return ace_sitemap_powertools_build_posts_sitemap_url_list( $post_type, $page_num );
        },
        array()
    );
}
add_filter( 'wp_sitemaps_posts_pre_url_list', 'ace_sitemap_powertools_posts_pre_url_list', 10, 3 );

function ace_sitemap_powertools_posts_pre_max_num_pages( $max_num_pages, $post_type ) {
    if ( null !== $max_num_pages || ! ace_sitemap_powertools_should_short_circuit_posts_sitemap( $post_type ) ) {
        return $max_num_pages;
    }

    $cache_key = sprintf(
        'posts_max_pages_v%d_%s_%d',
        ace_sitemap_powertools_get_cache_version(),
        sanitize_key( $post_type ),
        ace_sitemap_powertools_effective_post_max_urls()
    );

    return (int) ace_sitemap_powertools_cache_remember(
        $cache_key,
        function () use ( $post_type ) {
            $visible_count  = ace_sitemap_powertools_get_visible_post_count( $post_type );
            $max_num_pages  = (int) ceil( $visible_count / ace_sitemap_powertools_effective_post_max_urls() );
            $min_num_pages  = ( 'page' === $post_type && 'posts' === get_option( 'show_on_front' ) ) ? 1 : 0;

            return max( $min_num_pages, $max_num_pages );
        },
        0
    );
}
add_filter( 'wp_sitemaps_posts_pre_max_num_pages', 'ace_sitemap_powertools_posts_pre_max_num_pages', 10, 2 );

function ace_sitemap_powertools_posts_query_args( $args, $post_type ) {
    if ( ! is_array( $args ) ) {
        return $args;
    }

    if ( 'post' === $post_type ) {
        $args['orderby'] = array(
            'modified' => 'DESC',
            'ID'       => 'DESC',
        );
        $args['order'] = 'DESC';
    }

    return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'ace_sitemap_powertools_posts_query_args', 10, 2 );

function ace_sitemap_powertools_max_urls( $max_urls, $object_type ) {
    if ( 'post' === $object_type ) {
        return ace_sitemap_powertools_effective_post_max_urls();
    }

    return $max_urls;
}
add_filter( 'wp_sitemaps_max_urls', 'ace_sitemap_powertools_max_urls', 10, 2 );

function ace_sitemap_powertools_stylesheet_header_html( $context ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_header' ) ) {
        return '';
    }

    $site_name_raw    = get_bloginfo( 'name' );
    $site_name        = esc_xml( $site_name_raw );
    $site_name_attr   = esc_attr( $site_name_raw );
    $site_description = esc_xml( get_bloginfo( 'description' ) );
    $title_suffix     = ( 'index' === $context ) ? 'Sitemap Index' : 'Sitemap';
    $title_line       = esc_xml( trim( $site_name_raw . ' ' . $title_suffix ) );
    $logo_url         = '';

    if ( function_exists( 'get_custom_logo' ) ) {
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
        }
    }

    if ( ! $logo_url ) {
        $logo_url = get_site_icon_url( 256 );
    }

    $logo_html = '';
    if ( $logo_url ) {
        $logo_html = '<a class="sitemap-brand__logo" href="' . esc_url( home_url( '/' ) ) . '"><img src="' . esc_url( $logo_url ) . '" alt="' . $site_name_attr . '" /></a>';
    }

    $description_html = '';
    if ( $site_description ) {
        $description_html = '<p class="sitemap-brand__description">' . $site_description . '</p>';
    }

    $index_link_html = '';
    if ( 'index' !== $context && ace_sitemap_powertools_is_enabled( 'enable_index_link' ) ) {
        $index_link_html = '<a class="sitemap-brand__index-link" href="' . esc_url( home_url( '/sitemap.xml' ) ) . '">Sitemap Index</a>';
    }

    $header = <<<HTML
					<div id="sitemap__header">
						<div class="sitemap-brand">
							{$logo_html}
							<h1 class="sitemap-brand__title">{$title_line}</h1>
							{$description_html}
							{$index_link_html}
						</div>
					</div>
HTML;

    return $header;
}

function ace_sitemap_powertools_replace_stylesheet_header( $xsl_content ) {
    return ace_sitemap_powertools_replace_stylesheet_header_for_context( $xsl_content, 'sitemap' );
}

function ace_sitemap_powertools_replace_stylesheet_index_header( $xsl_content ) {
    return ace_sitemap_powertools_replace_stylesheet_header_for_context( $xsl_content, 'index' );
}

function ace_sitemap_powertools_replace_stylesheet_header_for_context( $xsl_content, $context ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_header' ) ) {
        return $xsl_content;
    }

    $header = ace_sitemap_powertools_stylesheet_header_html( $context );
    $pattern = '/<div id="sitemap__header">.*?<\\/div>\\s*<div id="sitemap__content">/s';
    $replacement = $header . "\n\t\t\t\t\t<div id=\"sitemap__content\">";

    $updated = preg_replace( $pattern, $replacement, $xsl_content, 1 );
    if ( is_string( $updated ) ) {
        return $updated;
    }

    return $xsl_content;
}
add_filter( 'wp_sitemaps_stylesheet_content', 'ace_sitemap_powertools_replace_stylesheet_header' );
add_filter( 'wp_sitemaps_stylesheet_index_content', 'ace_sitemap_powertools_replace_stylesheet_index_header' );

function ace_sitemap_powertools_fix_stylesheet_namespaces( $xsl_content ) {
    if ( false === strpos( $xsl_content, '<xsl:stylesheet' ) ) {
        return $xsl_content;
    }

    $needs_xsl_namespace     = false === strpos( $xsl_content, 'xmlns:xsl=' );
    $needs_sitemap_namespace = false === strpos( $xsl_content, 'xmlns:sitemap=' );

    if ( ! $needs_xsl_namespace && ! $needs_sitemap_namespace ) {
        return $xsl_content;
    }

    $updated = preg_replace_callback(
        '/<xsl:stylesheet\\b[^>]*>/',
        function ( $matches ) use ( $needs_xsl_namespace, $needs_sitemap_namespace ) {
            $tag = $matches[0];
            if ( $needs_xsl_namespace && false === strpos( $tag, 'xmlns:xsl=' ) ) {
                $tag = preg_replace(
                    '/<xsl:stylesheet\\b/',
                    '<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"',
                    $tag,
                    1
                );
            }

            if ( $needs_sitemap_namespace && false === strpos( $tag, 'xmlns:sitemap=' ) ) {
                $tag = preg_replace(
                    '/<xsl:stylesheet\\b/',
                    '<xsl:stylesheet xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"',
                    $tag,
                    1
                );
            }

            return $tag;
        },
        $xsl_content,
        1
    );

    if ( is_string( $updated ) ) {
        return $updated;
    }

    return $xsl_content;
}
add_filter( 'wp_sitemaps_stylesheet_content', 'ace_sitemap_powertools_fix_stylesheet_namespaces', 9999 );
add_filter( 'wp_sitemaps_stylesheet_index_content', 'ace_sitemap_powertools_fix_stylesheet_namespaces', 9999 );

function ace_sitemap_powertools_parse_loc( $loc ) {
    $parsed = array(
        'provider' => '',
        'subtype'  => '',
        'page'     => 0,
    );

    if ( preg_match( '~wp-sitemap-([a-z]+?)(?:-([a-z0-9_-]+))?-(\\d+)\\.xml~', $loc, $matches ) ) {
        $parsed['provider'] = $matches[1];
        $parsed['subtype']  = isset( $matches[2] ) ? $matches[2] : '';
        $parsed['page']     = (int) $matches[3];
        return $parsed;
    }

    if ( preg_match( '~[\\?&]sitemap=([^&]+)~', $loc, $matches ) ) {
        $parsed['provider'] = sanitize_key( $matches[1] );
    }

    if ( preg_match( '~[\\?&]sitemap-subtype=([^&]+)~', $loc, $matches ) ) {
        $parsed['subtype'] = sanitize_key( $matches[1] );
    }

    if ( preg_match( '~[\\?&]paged=(\\d+)~', $loc, $matches ) ) {
        $parsed['page'] = (int) $matches[1];
    }

    return $parsed;
}

function ace_sitemap_powertools_route_lookup() {
    static $cache = null;

    if ( null !== $cache ) {
        return $cache;
    }

    $routes = ace_sitemap_powertools_custom_routes();
    $lookup = array();

    foreach ( $routes as $slug => $route ) {
        $key = $route['provider'] . '|' . (string) $route['subtype'];
        if ( ! isset( $lookup[ $key ] ) ) {
            $lookup[ $key ] = $slug;
        }
    }

    $cache = $lookup;
    return $cache;
}

function ace_sitemap_powertools_custom_loc_for_entry( $entry, $object_type, $object_subtype, $page ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_routes' ) ) {
        return $entry;
    }

    if ( empty( $entry['loc'] ) ) {
        return $entry;
    }

    $parsed = ace_sitemap_powertools_parse_loc( $entry['loc'] );
    if ( empty( $parsed['provider'] ) || empty( $parsed['page'] ) ) {
        return $entry;
    }

    $lookup = ace_sitemap_powertools_route_lookup();
    $key    = $parsed['provider'] . '|' . (string) $parsed['subtype'];

    if ( ! isset( $lookup[ $key ] ) ) {
        return $entry;
    }

    $slug = trim( $lookup[ $key ], '/' );
    if ( '' === $slug ) {
        return $entry;
    }

    $max_pages = ace_sitemap_powertools_get_max_pages_for_route( $parsed['provider'], $parsed['subtype'] );
    if ( 1 === (int) $parsed['page'] && $max_pages <= 1 ) {
        $entry['loc'] = home_url( '/' . $slug . '.xml' );
    } else {
        $entry['loc'] = home_url( '/' . $slug . '-' . $parsed['page'] . '.xml' );
    }
    return $entry;
}
add_filter( 'wp_sitemaps_index_entry', 'ace_sitemap_powertools_custom_loc_for_entry', 10, 4 );

function ace_sitemap_powertools_index_entry_lastmod( $entry, $object_type, $object_subtype, $page ) {
    if ( ! is_array( $entry ) ) {
        return $entry;
    }

    if ( ! empty( $entry['lastmod'] ) ) {
        return $entry;
    }

    if ( ! ace_sitemap_powertools_should_set_index_lastmod( $object_type, $object_subtype ) ) {
        return $entry;
    }

    $lastmod = ace_sitemap_powertools_get_index_entry_lastmod( $object_type, $object_subtype, $page );
    if ( $lastmod ) {
        $entry['lastmod'] = $lastmod;
    }

    return $entry;
}
add_filter( 'wp_sitemaps_index_entry', 'ace_sitemap_powertools_index_entry_lastmod', 20, 4 );

function ace_sitemap_powertools_should_set_index_lastmod( $provider, $subtype ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_index_lastmod' ) ) {
        return false;
    }

    // Keep this fast: only compute lastmod for primary content sitemaps.
    // "news" maps to posts; "posts" covers core posts/pages/custom types.
    $provider = sanitize_key( (string) $provider );
    $subtype  = sanitize_key( (string) $subtype );

    if ( 'news' === $provider ) {
        return true;
    }

    if ( 'posts' !== $provider ) {
        return false;
    }

    if ( '' === $subtype || 'post' === $subtype || 'page' === $subtype ) {
        return true;
    }

    return false;
}

function ace_sitemap_powertools_get_index_entry_lastmod( $provider, $subtype, $page ) {
    static $cache = array();

    $page = (int) $page;
    if ( $page < 1 ) {
        $page = 1;
    }

    $key = $provider . '|' . (string) $subtype . '|' . $page;
    if ( array_key_exists( $key, $cache ) ) {
        return $cache[ $key ];
    }

    $cache[ $key ] = '';

    $persistent_key = sprintf(
        'index_lastmod_v%d_%s_%s_%d',
        ace_sitemap_powertools_get_cache_version(),
        sanitize_key( (string) $provider ),
        sanitize_key( (string) $subtype ),
        $page
    );
    $cached = ace_sitemap_powertools_cache_get( $persistent_key );
    if ( is_string( $cached ) && '' !== $cached ) {
        $cache[ $key ] = $cached;
        return $cache[ $key ];
    }

    if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
        return $cache[ $key ];
    }

    $post_type = '';
    if ( 'news' === $provider ) {
        $post_type = 'post';
    } elseif ( 'posts' === $provider ) {
        $post_type = $subtype ? $subtype : 'post';
    }

    if ( $post_type ) {
        $lastmod = get_lastpostmodified( 'GMT', $post_type );
        if ( $lastmod ) {
            $cache[ $key ] = mysql2date( 'c', $lastmod );
            ace_sitemap_powertools_cache_set( $persistent_key, $cache[ $key ] );
            return $cache[ $key ];
        }
    }

    return $cache[ $key ];
}

function ace_sitemap_powertools_get_max_pages_for_route( $provider, $subtype ) {
    static $cache = array();

    $key = $provider . '|' . (string) $subtype;
    if ( isset( $cache[ $key ] ) ) {
        return $cache[ $key ];
    }

    if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
        $cache[ $key ] = 0;
        return 0;
    }

    $persistent_key = sprintf(
        'max_pages_v%d_%s_%s',
        ace_sitemap_powertools_get_cache_version(),
        sanitize_key( (string) $provider ),
        sanitize_key( (string) $subtype )
    );
    $cached = ace_sitemap_powertools_cache_get( $persistent_key );
    if ( is_numeric( $cached ) ) {
        $cache[ $key ] = (int) $cached;
        return $cache[ $key ];
    }

    $wp_sitemaps = wp_sitemaps_get_server();
    $provider_obj = $wp_sitemaps->registry->get_provider( $provider );
    if ( ! $provider_obj ) {
        $cache[ $key ] = 0;
        return 0;
    }

    $cache[ $key ] = (int) $provider_obj->get_max_num_pages( $subtype );
    ace_sitemap_powertools_cache_set( $persistent_key, $cache[ $key ] );
    return $cache[ $key ];
}

function ace_sitemap_powertools_build_custom_url( $provider, $subtype, $page ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_routes' ) ) {
        return '';
    }

    if ( 'users' === $provider && ace_sitemap_powertools_is_enabled( 'enable_authors_provider' ) ) {
        $provider = 'authors';
    }

    if ( 'posts' === $provider && 'post' === $subtype && ace_sitemap_powertools_is_enabled( 'enable_news_provider' ) ) {
        $provider = 'news';
        $subtype  = '';
    }

    $lookup = ace_sitemap_powertools_route_lookup();
    $key    = $provider . '|' . (string) $subtype;

    if ( isset( $lookup[ $key ] ) ) {
        $slug = trim( $lookup[ $key ], '/' );
        if ( '' !== $slug ) {
            $max_pages = ace_sitemap_powertools_get_max_pages_for_route( $provider, $subtype );
            if ( 1 === (int) $page && $max_pages <= 1 ) {
                return home_url( '/' . $slug . '.xml' );
            }
            return home_url( '/' . $slug . '-' . (int) $page . '.xml' );
        }
    }

    return '';
}

function ace_sitemap_powertools_index_mark_recent( $xsl_content ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_recent_marker' ) ) {
        return $xsl_content;
    }

    $pattern = '#<td class="loc"><a href="\\{sitemap:loc\\}"><xsl:value-of select="sitemap:loc" \\/></a></td>#';

    $replacement = <<<'XSL'
<td class="loc">
	<xsl:variable name="loc" select="sitemap:loc" />
	<xsl:variable name="is-page-one">
		<xsl:choose>
			<xsl:when test="substring( $loc, string-length( $loc ) - string-length( '-1.xml' ) + 1 ) = '-1.xml'">1</xsl:when>
			<xsl:when test="substring( $loc, string-length( $loc ) - string-length( '-1' ) + 1 ) = '-1'">1</xsl:when>
			<xsl:when test="substring( $loc, string-length( $loc ) - string-length( 'paged=1' ) + 1 ) = 'paged=1'">1</xsl:when>
			<xsl:otherwise>0</xsl:otherwise>
		</xsl:choose>
	</xsl:variable>
	<xsl:variable name="prefix">
		<xsl:choose>
			<xsl:when test="$is-page-one = 1 and contains( $loc, '-1.xml' )">
				<xsl:value-of select="substring( $loc, 1, string-length( $loc ) - string-length( '1.xml' ) )" />
			</xsl:when>
			<xsl:when test="$is-page-one = 1 and contains( $loc, '-1' )">
				<xsl:value-of select="substring( $loc, 1, string-length( $loc ) - string-length( '1' ) )" />
			</xsl:when>
			<xsl:when test="$is-page-one = 1 and contains( $loc, '&amp;paged=1' )">
				<xsl:value-of select="concat( substring-before( $loc, '&amp;paged=1' ), 'paged=' )" />
			</xsl:when>
			<xsl:when test="$is-page-one = 1 and contains( $loc, '?paged=1' )">
				<xsl:value-of select="concat( substring-before( $loc, '?paged=1' ), 'paged=' )" />
			</xsl:when>
		</xsl:choose>
	</xsl:variable>
	<xsl:variable name="page-count">
		<xsl:choose>
			<xsl:when test="$is-page-one = 1 and string-length( $prefix ) &gt; 0">
				<xsl:value-of select="count( /sitemap:sitemapindex/sitemap:sitemap[ starts-with( sitemap:loc, $prefix ) ] )" />
			</xsl:when>
			<xsl:otherwise>0</xsl:otherwise>
		</xsl:choose>
	</xsl:variable>
	<xsl:choose>
        <xsl:when test="$is-page-one = 1 and number( $page-count ) &gt;= 5">
			<strong><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc" /></a></strong>
			<xsl:text> (most recent)</xsl:text>
		</xsl:when>
		<xsl:otherwise>
			<a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc" /></a>
		</xsl:otherwise>
	</xsl:choose>
</td>
XSL;

    $updated = preg_replace( $pattern, $replacement, $xsl_content, 1 );
    if ( is_string( $updated ) ) {
        return $updated;
    }

    return $xsl_content;
}
add_filter( 'wp_sitemaps_stylesheet_index_content', 'ace_sitemap_powertools_index_mark_recent', 20 );

function ace_sitemap_powertools_stylesheet_css( $css ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_custom_header' ) ) {
        return $css;
    }

    $extra = <<<CSS

					.sitemap-brand {
						display: flex;
						gap: 10px;
						align-items: center;
						flex-direction: column;
						text-align: center;
					}

					.sitemap-brand__logo img {
						max-height: 48px;
						width: auto;
					}

					.sitemap-brand__title {
						margin: 0;
						font-size: 22px;
                        color: var(--wp--preset--color--primary, #1BAA55);
					}

					.sitemap-brand__description {
						margin: 0;
						color: #666;
					}

					.sitemap-brand__index-link {
						color: #1e73be;
						text-decoration: none;
						font-weight: 600;
					}

					.sitemap-brand__index-link:hover {
						text-decoration: underline;
					}

					#sitemap__content .text {
						text-align: center;
					}
CSS;

    return $css . $extra;
}
add_filter( 'wp_sitemaps_stylesheet_css', 'ace_sitemap_powertools_stylesheet_css' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'ace-sitemap-powertools check', 'ace_sitemap_powertools_cli_check' );
}

function ace_sitemap_powertools_cli_check( $args, $assoc_args ) {
    if ( function_exists( 'wp_sitemaps_get_server' ) ) {
        wp_sitemaps_get_server();
    }

    if ( ! ace_sitemap_powertools_is_enabled( 'enable_news_provider' ) ) {
        WP_CLI::error( 'News sitemap provider is disabled in settings.' );
    }

    $provider = ace_sitemap_powertools_get_provider();
    if ( ! $provider ) {
        WP_CLI::error( 'Sitemaps provider is unavailable. Is WordPress sitemaps enabled?' );
    }

    $recent_days = isset( $assoc_args['recent-days'] ) ? (int) $assoc_args['recent-days'] : 30;
    $count       = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 5;
    $post_type   = isset( $assoc_args['post-type'] ) ? sanitize_key( $assoc_args['post-type'] ) : 'post';
    if ( 'post' !== $post_type ) {
        WP_CLI::error( 'Only the native post type is exposed under the news sitemap.' );
    }

    $max      = (int) $provider->get_max_num_pages( $post_type );

    if ( $max < 1 ) {
        WP_CLI::error( 'No sitemap pages found for posts.' );
    }

    $first_url = ace_sitemap_powertools_build_custom_url( 'news', '', 1 );
    $last_url  = ace_sitemap_powertools_build_custom_url( 'news', '', $max );

    if ( ! $first_url || ! $last_url ) {
        $first_url = $provider->get_sitemap_url( '', 1 );
        $last_url  = $provider->get_sitemap_url( '', $max );
    }

    $first_url = apply_filters( 'ace_sitemap_powertools_sanity_url', $first_url, 1, $max, $post_type );
    $last_url  = apply_filters( 'ace_sitemap_powertools_sanity_url', $last_url, $max, $max, $post_type );

    $first_xml = ace_sitemap_powertools_fetch_xml( $first_url );
    $last_xml  = ace_sitemap_powertools_fetch_xml( $last_url );

    $first_lastmods = ace_sitemap_powertools_extract_lastmods( $first_xml, $count );
    $last_lastmods  = ace_sitemap_powertools_extract_lastmods( $last_xml, $count );

    if ( empty( $first_lastmods ) || empty( $last_lastmods ) ) {
        WP_CLI::error( 'Could not parse <lastmod> values from one or both sitemaps.' );
    }

    $first_timestamps = ace_sitemap_powertools_to_timestamps( $first_lastmods );
    $last_timestamps  = ace_sitemap_powertools_to_timestamps( $last_lastmods );

    if ( empty( $first_timestamps ) || empty( $last_timestamps ) ) {
        WP_CLI::error( 'Could not parse <lastmod> timestamps.' );
    }

    $now          = time();
    $recent_limit = $now - ( max( 1, $recent_days ) * DAY_IN_SECONDS );
    $recent_found = false;

    foreach ( $first_timestamps as $timestamp ) {
        if ( $timestamp >= $recent_limit ) {
            $recent_found = true;
            break;
        }
    }

    if ( ! $recent_found ) {
        WP_CLI::error( 'Page 1 <lastmod> values are not recent. Increase activity or adjust --recent-days.' );
    }

    $first_min = min( $first_timestamps );
    $last_max  = max( $last_timestamps );

    if ( $last_max >= $first_min ) {
        WP_CLI::error( 'Last page contains <lastmod> values that are not older than page 1.' );
    }

    WP_CLI::success( 'Sitemap sanity check passed.' );
    WP_CLI::log( 'Page 1 lastmod: ' . implode( ', ', $first_lastmods ) );
    WP_CLI::log( 'Page ' . $max . ' lastmod: ' . implode( ', ', $last_lastmods ) );
}

function ace_sitemap_powertools_fetch_xml( $url ) {
    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 10,
        )
    );

    if ( is_wp_error( $response ) ) {
        WP_CLI::error( 'Request failed for ' . $url . ': ' . $response->get_error_message() );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        WP_CLI::error( 'Request failed for ' . $url . ' with HTTP status ' . $code . '.' );
    }

    return (string) wp_remote_retrieve_body( $response );
}

function ace_sitemap_powertools_extract_lastmods( $xml, $limit ) {
    $limit = max( 1, (int) $limit );
    $mods  = array();

    if ( function_exists( 'simplexml_load_string' ) ) {
        $parsed = @simplexml_load_string( $xml );
        if ( $parsed && isset( $parsed->url ) ) {
            foreach ( $parsed->url as $url ) {
                if ( isset( $url->lastmod ) ) {
                    $mods[] = (string) $url->lastmod;
                }
                if ( count( $mods ) >= $limit ) {
                    break;
                }
            }
        }
    }

    if ( empty( $mods ) ) {
        if ( preg_match_all( '/<lastmod>([^<]+)<\/lastmod>/', $xml, $matches ) ) {
            $mods = array_slice( $matches[1], 0, $limit );
        }
    }

    return $mods;
}

function ace_sitemap_powertools_to_timestamps( $lastmods ) {
    $timestamps = array();

    foreach ( $lastmods as $lastmod ) {
        $timestamp = strtotime( (string) $lastmod );
        if ( $timestamp ) {
            $timestamps[] = $timestamp;
        }
    }

    return $timestamps;
}
