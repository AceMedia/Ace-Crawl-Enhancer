<?php
/**
 * SEO columns on the post list screens.
 *
 * Adds indexability, title, description, canonical and social-image columns next
 * to the existing SEO score, all optional through Screen Options, plus a filter
 * for the questions people actually open this screen to answer.
 *
 * On sorting: ordering a list by a value that lives in postmeta means joining
 * postmeta and filesorting the whole post type, and on a site with tens of
 * thousands of posts that is the query pattern that saturated MySQL during
 * WC26. So sorting is offered only below a size threshold, and the filter is
 * the route above it — "which posts are noindex" is a WHERE against an indexed
 * meta_key returning a handful of rows, not an ORDER BY over everything.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoPostColumns {

    /**
     * Above this many posts of a type, meta-backed sorting is withheld.
     * Filter ace_seo_admin_sort_max_posts to override per site.
     */
    const SORT_MAX_POSTS = 20000;

    /** Columns this class owns: key => label. */
    private static function columns() {
        return array(
            'ace_seo_index'     => __( 'Indexable', 'ace-crawl-enhancer' ),
            'ace_seo_title_set' => __( 'SEO title', 'ace-crawl-enhancer' ),
            'ace_seo_desc'      => __( 'Meta description', 'ace-crawl-enhancer' ),
            'ace_seo_canonical' => __( 'Canonical', 'ace-crawl-enhancer' ),
            'ace_seo_social'    => __( 'Social image', 'ace-crawl-enhancer' ),
        );
    }

    public static function init() {
        // Bind on current_screen rather than now: this class is loaded from the
        // plugin's own init callback, which runs at the same priority that post
        // types register at, so iterating post types here would silently miss any
        // CPT whose plugin or theme happened to load later.
        add_action( 'current_screen', array( __CLASS__, 'register_for_screen' ) );
    }

    /**
     * Bind the column hooks for the list screen being rendered.
     *
     * @param WP_Screen $screen
     * @return void
     */
    public static function register_for_screen( $screen ) {
        if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base ) {
            return;
        }

        $post_type = $screen->post_type;

        if ( ! $post_type || ! in_array( $post_type, self::post_types(), true ) ) {
            return;
        }

        add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'add_columns' ) );
        add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
        add_filter( "manage_edit-{$post_type}_sortable_columns", array( __CLASS__, 'sortable_columns' ) );

        // Registered columns appear in Screen Options automatically; hiding them by
        // default keeps the screen as it was for anyone who does not want them.
        add_filter( 'default_hidden_columns', array( __CLASS__, 'default_hidden' ), 10, 2 );

        add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_query' ) );
    }

    /**
     * @return string[]
     */
    private static function post_types() {
        $types = array();

        foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
            if ( 'attachment' === $type->name || ! is_post_type_viewable( $type ) ) {
                continue;
            }

            $types[] = $type->name;
        }

        /**
         * Filter the post types that get SEO list columns.
         *
         * @param string[] $types
         */
        return (array) apply_filters( 'ace_seo_admin_column_post_types', $types );
    }

    /**
     * @param array $columns
     * @return array
     */
    public static function add_columns( $columns ) {
        $out = array();

        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;

            // Sit with the existing SEO score rather than at the far right.
            if ( 'ace_seo_focus' === $key ) {
                $out = array_merge( $out, self::columns() );
            }
        }

        // No score column on this screen: append instead of dropping them.
        foreach ( self::columns() as $key => $label ) {
            if ( ! isset( $out[ $key ] ) ) {
                $out[ $key ] = $label;
            }
        }

        return $out;
    }

    /**
     * Hide the new columns until someone asks for them in Screen Options.
     *
     * @param string[]  $hidden
     * @param WP_Screen $screen
     * @return string[]
     */
    public static function default_hidden( $hidden, $screen ) {
        if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base ) {
            return $hidden;
        }

        return array_merge( (array) $hidden, array_keys( self::columns() ) );
    }

    /**
     * Only offer sorting where it will not filesort a huge table.
     *
     * @param array $columns
     * @return array
     */
    public static function sortable_columns( $columns ) {
        if ( ! self::sorting_allowed( self::current_post_type() ) ) {
            return $columns;
        }

        $columns['ace_seo_index'] = 'ace_seo_index';
        $columns['ace_seo_desc']  = 'ace_seo_desc';

        return $columns;
    }

    /**
     * @param string $post_type
     * @return bool
     */
    private static function sorting_allowed( $post_type ) {
        $counts = wp_count_posts( $post_type );
        $total  = 0;

        foreach ( (array) $counts as $count ) {
            $total += (int) $count;
        }

        /**
         * Filter the size above which meta-backed sorting is withheld. Raising it
         * accepts a filesort across the post type on every sorted page load.
         *
         * @param int    $max
         * @param string $post_type
         * @param int    $total
         */
        $max = (int) apply_filters( 'ace_seo_admin_sort_max_posts', self::SORT_MAX_POSTS, $post_type, $total );

        return $total <= $max;
    }

    /**
     * @return string
     */
    private static function current_post_type() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( $screen instanceof WP_Screen && $screen->post_type ) {
            return $screen->post_type;
        }

        return isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
    }

    /**
     * Render one cell.
     *
     * Reads only post meta, which WP_List_Table has already primed for the whole
     * page in a single query, so this costs no extra round trips.
     *
     * @param string $column
     * @param int    $post_id
     * @return void
     */
    public static function render_column( $column, $post_id ) {
        switch ( $column ) {
            case 'ace_seo_index':
                self::render_index_cell( $post_id );
                break;

            case 'ace_seo_title_set':
                self::render_presence( AceCrawlEnhancer::get_meta_value( $post_id, 'title' ) );
                break;

            case 'ace_seo_desc':
                $desc = (string) AceCrawlEnhancer::get_meta_value( $post_id, 'metadesc' );

                if ( '' === $desc ) {
                    echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'
                        . esc_html__( 'not set', 'ace-crawl-enhancer' ) . '</span>';
                    break;
                }

                $length = function_exists( 'mb_strlen' ) ? mb_strlen( $desc ) : strlen( $desc );
                $good   = $length >= 120 && $length <= 160;

                printf(
                    '<span title="%s" style="color:%s">%d</span>',
                    esc_attr( $desc ),
                    $good ? '#1a7f37' : '#8a6d00',
                    (int) $length
                );
                break;

            case 'ace_seo_canonical':
                self::render_presence( AceCrawlEnhancer::get_meta_value( $post_id, 'canonical' ) );
                break;

            case 'ace_seo_social':
                $image = AceCrawlEnhancer::get_meta_value( $post_id, 'opengraph-image' );

                if ( '' === (string) $image ) {
                    $image = get_post_thumbnail_id( $post_id ) ? __( 'featured', 'ace-crawl-enhancer' ) : '';
                }

                self::render_presence( $image );
                break;
        }
    }

    /**
     * Indexability, and where it came from — a site-wide setting and a per-post
     * one look identical in the output but need different action.
     *
     * @param int $post_id
     * @return void
     */
    private static function render_index_cell( $post_id ) {
        if ( function_exists( 'ace_seo_site_is_discouraged' ) && ace_seo_site_is_discouraged() ) {
            printf(
                '<span style="color:#b32d2e;font-weight:600" title="%s">%s</span>',
                esc_attr__( 'The whole site is set to discourage search engines, in Settings → Reading.', 'ace-crawl-enhancer' ),
                esc_html__( 'No (site-wide)', 'ace-crawl-enhancer' )
            );

            return;
        }

        if ( self::post_is_noindex( $post_id ) ) {
            printf(
                '<span style="color:#b32d2e;font-weight:600">%s</span>',
                esc_html__( 'Noindex', 'ace-crawl-enhancer' )
            );

            return;
        }

        printf( '<span style="color:#1a7f37">%s</span>', esc_html__( 'Yes', 'ace-crawl-enhancer' ) );
    }

    /**
     * @param int $post_id
     * @return bool
     */
    private static function post_is_noindex( $post_id ) {
        if ( '1' === (string) AceCrawlEnhancer::get_meta_value( $post_id, 'meta-robots-noindex' ) ) {
            return true;
        }

        $advanced = (string) AceCrawlEnhancer::get_meta_value( $post_id, 'meta-robots-adv' );

        return false !== stripos( $advanced, 'noindex' );
    }

    /**
     * @param mixed $value
     * @return void
     */
    private static function render_presence( $value ) {
        if ( '' === (string) $value || null === $value ) {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'
                . esc_html__( 'not set', 'ace-crawl-enhancer' ) . '</span>';

            return;
        }

        printf(
            '<span style="color:#1a7f37" title="%s">%s</span>',
            esc_attr( is_scalar( $value ) ? (string) $value : '' ),
            esc_html__( 'Set', 'ace-crawl-enhancer' )
        );
    }

    /**
     * Filters that stay available whatever the size of the post type.
     *
     * "Noindex only" matches a rare value through the meta_key index and returns a
     * handful of rows — 0.002s across 33k posts.
     *
     * @return array
     */
    private static function cheap_filters() {
        return array( 'noindex' => __( 'Noindex only', 'ace-crawl-enhancer' ) );
    }

    /**
     * Filters that have to look for the ABSENCE of a value.
     *
     * These are the majority of posts by definition, so they join postmeta and
     * walk most of the table: "missing meta description" measured 8.5s across 33k
     * posts, and rewriting it as post__not_in over the 1,239 posts that do have one
     * did not finish at all. Offered only below the size threshold — the same query
     * shape, on this same site, is what saturated MySQL during WC26.
     *
     * @return array
     */
    private static function expensive_filters() {
        return array(
            'indexable' => __( 'Indexable only', 'ace-crawl-enhancer' ),
            'no_desc'   => __( 'Missing meta description', 'ace-crawl-enhancer' ),
            'no_title'  => __( 'Missing SEO title', 'ace-crawl-enhancer' ),
            'no_focus'  => __( 'No focus keyword', 'ace-crawl-enhancer' ),
        );
    }

    /**
     * @param string $post_type
     * @return array
     */
    private static function filter_options( $post_type ) {
        $options = self::cheap_filters();

        if ( self::sorting_allowed( $post_type ) ) {
            $options = array_merge( $options, self::expensive_filters() );
        }

        return $options;
    }

    /**
     * The filter dropdown — the cheap way to answer "which ones are noindex".
     *
     * @param string $post_type
     * @return void
     */
    public static function render_filter( $post_type ) {
        if ( ! in_array( $post_type, self::post_types(), true ) ) {
            return;
        }

        $current = isset( $_GET['ace_seo_filter'] ) ? sanitize_key( wp_unslash( $_GET['ace_seo_filter'] ) ) : '';

        $options = self::filter_options( $post_type );

        echo '<select name="ace_seo_filter"><option value="">'
            . esc_html__( 'All SEO states', 'ace-crawl-enhancer' ) . '</option>';

        foreach ( $options as $value => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }

        echo '</select>';
    }

    /**
     * Apply the filter and any sort to the list query.
     *
     * @param WP_Query $query
     * @return void
     */
    public static function apply_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base ) {
            return;
        }

        self::apply_filter( $query );
        self::apply_sort( $query, $screen->post_type );
    }

    /**
     * @param WP_Query $query
     * @return void
     */
    private static function apply_filter( $query ) {
        $filter = isset( $_GET['ace_seo_filter'] ) ? sanitize_key( wp_unslash( $_GET['ace_seo_filter'] ) ) : '';

        if ( '' === $filter ) {
            return;
        }

        // The dropdown hides these above the threshold; a hand-typed URL must not
        // be able to run one anyway.
        if ( isset( self::expensive_filters()[ $filter ] ) && ! self::sorting_allowed( $query->get( 'post_type' ) ?: 'post' ) ) {
            return;
        }

        // Every branch below is an EXISTS / NOT EXISTS against an indexed meta_key,
        // so it narrows with the index and leaves the date ordering alone.
        $map = array(
            'no_desc'  => 'metadesc',
            'no_title' => 'title',
            'no_focus' => 'focuskw',
        );

        if ( isset( $map[ $filter ] ) ) {
            $query->set(
                'meta_query',
                array(
                    'relation' => 'OR',
                    array( 'key' => '_ace_seo_' . $map[ $filter ], 'compare' => 'NOT EXISTS' ),
                    array( 'key' => '_ace_seo_' . $map[ $filter ], 'value' => '', 'compare' => '=' ),
                )
            );

            return;
        }

        if ( 'noindex' === $filter ) {
            $query->set(
                'meta_query',
                array(
                    'relation' => 'OR',
                    array( 'key' => '_ace_seo_meta-robots-noindex', 'value' => '1', 'compare' => '=' ),
                    array( 'key' => '_yoast_wpseo_meta-robots-noindex', 'value' => '1', 'compare' => '=' ),
                )
            );

            return;
        }

        if ( 'indexable' === $filter ) {
            $query->set(
                'meta_query',
                array(
                    'relation' => 'AND',
                    array(
                        'relation' => 'OR',
                        array( 'key' => '_ace_seo_meta-robots-noindex', 'compare' => 'NOT EXISTS' ),
                        array( 'key' => '_ace_seo_meta-robots-noindex', 'value' => '1', 'compare' => '!=' ),
                    ),
                    array(
                        'relation' => 'OR',
                        array( 'key' => '_yoast_wpseo_meta-robots-noindex', 'compare' => 'NOT EXISTS' ),
                        array( 'key' => '_yoast_wpseo_meta-robots-noindex', 'value' => '1', 'compare' => '!=' ),
                    ),
                )
            );
        }
    }

    /**
     * @param WP_Query $query
     * @param string   $post_type
     * @return void
     */
    private static function apply_sort( $query, $post_type ) {
        $orderby = $query->get( 'orderby' );

        if ( ! in_array( $orderby, array( 'ace_seo_index', 'ace_seo_desc' ), true ) ) {
            return;
        }

        if ( ! self::sorting_allowed( $post_type ) ) {
            return;
        }

        $key = 'ace_seo_index' === $orderby ? '_ace_seo_meta-robots-noindex' : '_ace_seo_metadesc';

        // EXISTS ordering rather than meta_key ordering: a plain meta_key sort drops
        // every post that has no row for the key, which here is most of them.
        $query->set(
            'meta_query',
            array(
                'relation' => 'OR',
                array( 'key' => $key, 'compare' => 'EXISTS' ),
                array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
            )
        );
        $query->set( 'orderby', 'meta_value' );
        $query->set( 'meta_key', $key );
    }
}
