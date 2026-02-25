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
        'post_max_urls'            => 0,
        'enable_sitemap_cache'     => 1,
        'enable_index_lastmod'     => 1,
        'sitemap_cache_ttl'        => 600,
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

function ace_sitemap_powertools_is_enabled( $key ) {
    return (bool) ace_sitemap_powertools_get_option( $key );
}

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
            'label' => 'Max URLs per posts sitemap (0 = WordPress default).',
            'min' => 0,
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
    );
    $integers = array( 'post_max_urls', 'sitemap_cache_ttl' );

    $output = array();

    foreach ( $checkboxes as $key ) {
        $output[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
    }

    foreach ( $integers as $key ) {
        $value = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
        $output[ $key ] = $value;
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
    $excluded_post_types = apply_filters(
        'ace_sitemap_powertools_excluded_clean_post_types',
        array()
    );

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
    $excluded_post_types = apply_filters(
        'ace_sitemap_powertools_excluded_clean_post_types',
        array()
    );

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
    $version = get_option( 'ace_sitemap_cache_version', 1 );
    return (int) $version > 0 ? (int) $version : 1;
}

function ace_sitemap_powertools_bump_cache_version() {
    $version = ace_sitemap_powertools_get_cache_version();
    update_option( 'ace_sitemap_cache_version', $version + 1 );
}

function ace_sitemap_powertools_get_cache_ttl() {
    $ttl = (int) ace_sitemap_powertools_get_option( 'sitemap_cache_ttl' );
    if ( $ttl < 60 ) {
        $ttl = 60;
    }
    return (int) apply_filters( 'ace_sitemap_powertools_cache_ttl', $ttl );
}

function ace_sitemap_powertools_cache_enabled() {
    return ace_sitemap_powertools_is_enabled( 'enable_sitemap_cache' );
}

function ace_sitemap_powertools_purge_cache() {
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

function ace_sitemap_powertools_cache_set( $key, $value ) {
    if ( ! ace_sitemap_powertools_cache_enabled() ) {
        return;
    }

    wp_cache_set( $key, $value, 'ace_sitemap', ace_sitemap_powertools_get_cache_ttl() );
    $option_key = ace_sitemap_powertools_cache_option_key( $key );
    update_option( $option_key, array( 't' => time(), 'data' => $value ), false );
}

// Invalidate sitemap cache when content changes.
add_action( 'save_post', 'ace_sitemap_powertools_bump_cache_version', 20, 0 );
add_action( 'deleted_post', 'ace_sitemap_powertools_bump_cache_version', 20, 0 );
add_action( 'transition_post_status', 'ace_sitemap_powertools_bump_cache_version', 20, 0 );
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

    header( 'Content-Type: application/xml; charset=UTF-8' );
    $xml = $wp_sitemaps->renderer->get_sitemap_xml( $url_list );
    if ( ! empty( $xml ) ) {
        echo $xml;
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

function ace_sitemap_powertools_post_types_filter( $post_types ) {
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_news_provider' ) ) {
        return $post_types;
    }

    if ( isset( $post_types['post'] ) ) {
        unset( $post_types['post'] );
    }

    return $post_types;
}
add_filter( 'wp_sitemaps_post_types', 'ace_sitemap_powertools_post_types_filter' );

function ace_sitemap_powertools_posts_query_args( $args, $post_type ) {
    if ( ! is_array( $args ) ) {
        return $args;
    }

    if ( 'post' !== $post_type ) {
        return $args;
    }

    $args['orderby'] = array(
        'modified' => 'DESC',
        'ID'       => 'DESC',
    );
    $args['order'] = 'DESC';

    return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'ace_sitemap_powertools_posts_query_args', 10, 2 );

function ace_sitemap_powertools_max_urls( $max_urls, $object_type ) {
    $limit = (int) ace_sitemap_powertools_get_option( 'post_max_urls' );

    if ( $limit > 0 && 'post' === $object_type ) {
        return $limit;
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
