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
            'ace_seo_retention' => __( 'Retention', 'ace-crawl-enhancer' ),
            'ace_seo_ret_views'   => __( 'Views', 'ace-crawl-enhancer' ),
            'ace_seo_last_viewed' => __( 'Last viewed', 'ace-crawl-enhancer' ),
            'ace_seo_ret_links'   => __( 'Links in', 'ace-crawl-enhancer' ),
            'ace_seo_people'      => __( 'People (30 days)', 'ace-crawl-enhancer' ),
            'ace_seo_bot_pct'     => __( 'Bots', 'ace-crawl-enhancer' ),
        ) + ( self::whitehat_on() ? array( 'ace_seo_whitehat' => __( 'White hat', 'ace-crawl-enhancer' ) ) : array() );
    }

    private static function whitehat_on() {
        return class_exists( 'AceSeoWhiteHat' ) && AceSeoWhiteHat::enabled();
    }

    /** Retention columns, shown without Screen Options on a list filtered or sorted by retention. */
    private static function retention_columns() {
        return array( 'ace_seo_retention', 'ace_seo_ret_views', 'ace_seo_last_viewed', 'ace_seo_ret_links', 'ace_seo_people', 'ace_seo_bot_pct' );
    }

    /**
     * Sortable retention columns => meta key and type. These sort on flat meta written only for
     * posts the report scored, so a sort lists the scored posts: a bounded set joined on an indexed
     * key, not a filesort over the whole post type.
     */
    private static function retention_sorts() {
        return array(
            'ace_seo_ret_views'   => array( '_ace_seo_ret_views', 'NUMERIC' ),
            'ace_seo_last_viewed' => array( '_ace_seo_last_viewed', 'CHAR' ),
            'ace_seo_ret_links'   => array( '_ace_seo_ret_links', 'NUMERIC' ),
            'ace_seo_retention'   => array( '_ace_seo_ret_tier', 'CHAR' ),
            'ace_seo_people'      => array( '_ace_seo_humans', 'NUMERIC' ),
            'ace_seo_bot_pct'     => array( '_ace_seo_bot_pct', 'NUMERIC' ),
            'ace_seo_whitehat'    => array( '_ace_seo_whitehat', 'CHAR' ),
        );
    }

    /** Query arguments that belong to the retention filters. */
    private static function retention_params() {
        return array( 'ace_ret', 'ace_before', 'ace_views_min', 'ace_views_max', 'ace_wh' );
    }

    public static function init() {
        // Bind on current_screen rather than now: this class is loaded from the
        // plugin's own init callback, which runs at the same priority that post
        // types register at, so iterating post types here would silently miss any
        // CPT whose plugin or theme happened to load later.
        add_action( 'current_screen', array( __CLASS__, 'register_for_screen' ) );
        add_action( 'wp_ajax_ace_seo_list_export', array( __CLASS__, 'ajax_export' ) );
    }

    /** Rows per export request: small enough to finish well inside a request's time limit. */
    const EXPORT_BATCH = 500;

    /**
     * One batch of the CSV export: the list's own query (its filters, search, sort) rebuilt from the
     * query string the screen was loaded with, a page at a time. The browser stitches the batches
     * together, so a 30,000-post export is sixty short requests rather than one that times out.
     *
     * @return void
     */
    public static function ajax_export() {
        check_ajax_referer( 'ace_seo_list_export' );

        $params = array();
        wp_parse_str( (string) wp_unslash( $_POST['q'] ?? '' ), $params );
        $post_type = sanitize_key( $params['post_type'] ?? 'post' );
        $type_obj  = get_post_type_object( $post_type );

        if ( ! $type_obj || ! in_array( $post_type, self::post_types(), true ) || ! current_user_can( $type_obj->cap->edit_posts ) ) {
            wp_send_json_error( __( 'Not allowed.', 'ace-crawl-enhancer' ), 403 );
        }

        $batch  = max( 1, absint( $_POST['batch'] ?? 1 ) );
        $status = sanitize_key( $params['post_status'] ?? '' );
        $args   = array(
            'post_type'           => $post_type,
            'post_status'         => ( '' !== $status && get_post_status_object( $status ) ) ? $status : array_values( get_post_stati( array( 'show_in_admin_all_list' => true ) ) ),
            'posts_per_page'      => self::EXPORT_BATCH,
            'paged'               => $batch,
            'fields'              => 'ids',
            'orderby'             => sanitize_key( $params['orderby'] ?? 'date' ) ?: 'date',
            'order'               => 'asc' === strtolower( (string) ( $params['order'] ?? '' ) ) ? 'ASC' : 'DESC',
            'ignore_sticky_posts' => true,
            'suppress_filters'    => false,
            'ace_seo_export'      => true,
        );
        // The list screen's own filters, passed straight through: WP_Query reads them the same way.
        foreach ( array( 'm', 'cat', 'category_name', 'tag', 's', 'author' ) as $var ) {
            if ( isset( $params[ $var ] ) && '' !== $params[ $var ] ) {
                $args[ $var ] = sanitize_text_field( $params[ $var ] );
            }
        }
        if ( ! current_user_can( $type_obj->cap->edit_others_posts ) ) {
            $args['author'] = get_current_user_id();
        }

        $apply = function ( $query ) use ( $params, $post_type ) {
            if ( ! $query->get( 'ace_seo_export' ) ) {
                return;
            }
            self::apply_filter( $query, $params );
            self::apply_retention_filters( $query, $params );
            self::apply_sort( $query, $post_type );
            self::apply_retention_sort( $query );
        };
        add_action( 'pre_get_posts', $apply );
        $query = new WP_Query( $args );
        remove_action( 'pre_get_posts', $apply );

        $ids = array_map( 'intval', $query->posts );
        if ( $ids ) {
            _prime_post_caches( $ids, false, true );
        }

        $header = array( 'ID', 'Title', 'URL', 'Status', 'Published', 'Modified', 'Tier', 'Bucket', 'Views', 'Last viewed', 'Links in', 'Words', 'Search clicks', 'Search impressions', 'People (30 days)', 'Bots %', 'White hat', 'Indexable' );
        $rows   = array();
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            $row  = class_exists( 'AceSeoRetentionReport' ) ? get_post_meta( $id, AceSeoRetentionReport::META, true ) : '';
            $row  = is_array( $row ) ? $row : array();
            $line = array(
                $id,
                html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                get_permalink( $post ),
                $post->post_status,
                get_post_time( 'Y-m-d', false, $post ),
                get_post_modified_time( 'Y-m-d', false, $post ),
                $row['tier'] ?? '',
                $row['bucket'] ?? '',
                get_post_meta( $id, '_ace_seo_ret_views', true ),
                get_post_meta( $id, '_ace_seo_last_viewed', true ),
                get_post_meta( $id, '_ace_seo_ret_links', true ),
                get_post_meta( $id, '_ace_seo_ret_words', true ),
                $row['clicks'] ?? '',
                $row['impressions'] ?? '',
                get_post_meta( $id, '_ace_seo_humans', true ),
                get_post_meta( $id, '_ace_seo_bot_pct', true ),
                get_post_meta( $id, '_ace_seo_whitehat', true ),
                self::post_is_noindex( $id ) ? 'no' : 'yes',
            );

            /**
             * Filter one exported row; add a value and a matching header with ace_seo_list_export_header.
             *
             * @param array $line
             * @param int   $id
             */
            $rows[] = array_values( (array) apply_filters( 'ace_seo_list_export_row', $line, $id ) );
        }

        wp_send_json_success( array(
            'header' => array_values( (array) apply_filters( 'ace_seo_list_export_header', $header ) ),
            'rows'   => $rows,
            'total'  => (int) $query->found_posts,
            'pages'  => (int) $query->max_num_pages,
            'batch'  => $batch,
        ) );
    }

    /**
     * The export button's script: fetch the batches, build the CSV in the browser, save it.
     *
     * @return void
     */
    public static function print_export_script() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, self::post_types(), true ) ) {
            return;
        }
        $label = __( 'Export CSV', 'ace-crawl-enhancer' );
        ?>
        <script>
        (function () {
            var bar = document.querySelector('.tablenav.top .actions:not(.bulkactions)');
            if (!bar || !window.fetch || !window.Blob) { return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'button';
            b.id = 'ace-seo-export';
            b.title = <?php echo wp_json_encode( __( 'Everything this list shows with its current filters, search and sort, not just this page', 'ace-crawl-enhancer' ) ); ?>;
            b.textContent = <?php echo wp_json_encode( $label ); ?>;
            bar.appendChild(b);
            var cell = function (v) {
                v = v === null || v === undefined ? '' : String(v);
                if (/^[=+\-@]/.test(v)) { v = "'" + v; }
                return /[",
]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
            };
            b.addEventListener('click', function () {
                var q = window.location.search.replace(/^\?/, '').split('&').filter(function (p) { return p && p.indexOf('paged=') !== 0; }).join('&');
                var rows = [], header = null, page = 1, pages = 1;
                b.disabled = true;
                var next = function () {
                    var fd = new FormData();
                    fd.append('action', 'ace_seo_list_export');
                    fd.append('_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'ace_seo_list_export' ) ); ?>);
                    fd.append('q', q);
                    fd.append('batch', String(page));
                    return fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            if (!j || !j.success) { throw new Error((j && j.data) || 'Export failed'); }
                            header = j.data.header;
                            pages = Math.max(1, j.data.pages);
                            rows = rows.concat(j.data.rows);
                            b.textContent = 'Exporting ' + Math.min(page, pages) + ' of ' + pages + '…';
                            page++;
                            return page <= pages ? next() : null;
                        });
                };
                next().then(function () {
                    var csv = [header].concat(rows).map(function (r) { return r.map(cell).join(','); }).join('
');
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
                    a.download = <?php echo wp_json_encode( $screen->post_type . '-' ); ?> + new Date().toISOString().slice(0, 10) + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                }).catch(function (e) {
                    window.alert(e.message);
                }).then(function () {
                    b.disabled = false;
                    b.textContent = <?php echo wp_json_encode( $label ); ?>;
                });
            });
        })();
        </script>
        <?php
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
        add_filter( 'hidden_columns', array( __CLASS__, 'show_retention_columns' ), 10, 2 );
        add_action( 'admin_footer', array( __CLASS__, 'print_export_script' ) );
    }

    /**
     * On a list filtered or sorted by retention the columns that explain it are shown, whatever
     * Screen Options says, so a shared link opens on the numbers it is about.
     *
     * @param string[]  $hidden
     * @param WP_Screen $screen
     * @return string[]
     */
    public static function show_retention_columns( $hidden, $screen ) {
        if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base ) {
            return $hidden;
        }
        $active = false;
        foreach ( self::retention_params() as $param ) {
            if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) {
                $active = true;
            }
        }
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
        if ( isset( self::retention_sorts()[ $orderby ] ) ) {
            $active = true;
        }
        return $active ? array_values( array_diff( (array) $hidden, self::retention_columns() ) ) : $hidden;
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
        $columns = self::retention_sortable_columns( $columns );

        if ( ! self::sorting_allowed( self::current_post_type() ) ) {
            return $columns;
        }

        $columns['ace_seo_index'] = 'ace_seo_index';
        $columns['ace_seo_desc']  = 'ace_seo_desc';

        return $columns;
    }

    /**
     * Retention sorts are offered whatever the size of the post type (see retention_sorts()).
     *
     * @param array $columns
     * @return array
     */
    public static function retention_sortable_columns( $columns ) {
        foreach ( array_keys( self::retention_sorts() ) as $key ) {
            $columns[ $key ] = array( $key, in_array( $key, array( 'ace_seo_ret_views', 'ace_seo_people', 'ace_seo_bot_pct' ), true ) );
        }

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

            case 'ace_seo_retention':
                self::render_retention_cell( $post_id );
                break;

            case 'ace_seo_ret_views':
                $views = get_post_meta( $post_id, '_ace_seo_ret_views', true );
                echo '' === $views ? self::dash() : esc_html( number_format_i18n( (int) $views ) );
                break;

            case 'ace_seo_people':
                $people = get_post_meta( $post_id, '_ace_seo_humans', true );
                echo '' === $people ? self::dash() : esc_html( number_format_i18n( (int) $people ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                break;

            case 'ace_seo_bot_pct':
                $pct = get_post_meta( $post_id, '_ace_seo_bot_pct', true );
                echo '' === $pct ? self::dash() : esc_html( (int) $pct . '%' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                break;

            case 'ace_seo_whitehat':
                $status = (string) get_post_meta( $post_id, '_ace_seo_whitehat', true );
                if ( '' === $status || ! class_exists( 'AceSeoWhiteHat' ) ) {
                    echo self::dash(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    break;
                }
                $data   = get_post_meta( $post_id, '_ace_seo_whitehat_data', true );
                $colour = array( 'yes' => '#1a7f37', 'no' => '#b32d2e', 'unknown' => '#8a6d00' );
                printf(
                    '<span style="color:%s;font-weight:600" title="%s">%s</span>',
                    esc_attr( $colour[ $status ] ?? '#646970' ),
                    esc_attr( is_array( $data ) ? implode( ' ', (array) ( $data['reasons'] ?? array() ) ) . ( ! empty( $data['checked'] ) ? ' (' . wp_date( 'Y-m-d H:i', (int) $data['checked'] ) . ', ' . ( $data['method'] ?? '' ) . ')' : '' ) : '' ),
                    esc_html( AceSeoWhiteHat::labels()[ $status ] ?? $status )
                );
                break;

            case 'ace_seo_last_viewed':
                $last = (string) get_post_meta( $post_id, '_ace_seo_last_viewed', true );
                echo '' === $last ? self::dash() : esc_html( mysql2date( get_option( 'date_format' ), $last ) );
                break;

            case 'ace_seo_ret_links':
                $links = get_post_meta( $post_id, '_ace_seo_ret_links', true );
                if ( '' === $links ) {
                    echo self::dash(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } elseif ( 0 === (int) $links ) {
                    printf(
                        '<span style="color:#b32d2e" title="%s">0 %s</span>',
                        esc_attr__( 'No post on the site links here.', 'ace-crawl-enhancer' ),
                        esc_html__( '(orphaned)', 'ace-crawl-enhancer' )
                    );
                } else {
                    echo esc_html( number_format_i18n( (int) $links ) );
                }
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
    /**
     * The retention report's verdict for this post, and whatever has been applied since: bucket
     * (with the reason as its title), then the live state — noindex, unavailable_after, 301 / 410,
     * out of the news sitemap, notice forced. A post the report has not scored shows a dash.
     *
     * @param int $post_id
     * @return void
     */
    private static function dash() {
        return '<span aria-hidden="true">-</span><span class="screen-reader-text">' . esc_html__( 'no data', 'ace-crawl-enhancer' ) . '</span>';
    }

    private static function render_retention_cell( $post_id ) {
        $row = class_exists( 'AceSeoRetentionReport' ) ? get_post_meta( $post_id, AceSeoRetentionReport::META, true ) : '';

        if ( is_array( $row ) && ! empty( $row['tier'] ) ) {
            $tiers = array(
                'retained'  => __( 'Retained', 'ace-crawl-enhancer' ),
                'candidate' => __( 'Deletion candidate', 'ace-crawl-enhancer' ),
                'dormant'   => __( 'Dormant', 'ace-crawl-enhancer' ),
            );
            echo '<span style="display:block">' . esc_html( $tiers[ $row['tier'] ] ?? $row['tier'] ) . '</span>';
        }

        if ( is_array( $row ) && ! empty( $row['bucket'] ) ) {
            $labels = array(
                'keep'        => __( 'Keep', 'ace-crawl-enhancer' ),
                'refresh'     => __( 'Refresh', 'ace-crawl-enhancer' ),
                'consolidate' => __( 'Consolidate', 'ace-crawl-enhancer' ),
                'noindex'     => __( 'Noindex', 'ace-crawl-enhancer' ),
                'no-signal'   => __( 'No signal', 'ace-crawl-enhancer' ),
            );
            printf(
                '<strong title="%s">%s</strong>',
                esc_attr( (string) ( $row['reason'] ?? '' ) ),
                esc_html( $labels[ $row['bucket'] ] ?? $row['bucket'] )
            );
        } else {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'
                . esc_html__( 'not scored', 'ace-crawl-enhancer' ) . '</span>';
        }

        if ( ! class_exists( 'AceSeoRetentionActions' ) ) {
            return;
        }

        $st    = AceSeoRetentionActions::state( $post_id );
        $flags = array_filter( array(
            $st['noindex'] ? __( 'noindex', 'ace-crawl-enhancer' ) : '',
            $st['unavailable'] ? sprintf( __( 'until %s', 'ace-crawl-enhancer' ), $st['unavailable'] ) : '',
            'gone' === $st['redirect'] ? '410' : ( $st['redirect'] ? '301 → ' . wp_make_link_relative( $st['redirect'] ) : '' ),
            $st['news_excl'] ? __( 'no news sitemap', 'ace-crawl-enhancer' ) : '',
            $st['notice'] ? sprintf( __( 'notice: %s', 'ace-crawl-enhancer' ), $st['notice'] ) : '',
        ) );

        if ( $flags ) {
            echo '<br><span style="font-size:11px;color:#646970">' . esc_html( implode( ' · ', $flags ) ) . '</span>';
        }
    }

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
        $filters = array( 'noindex' => __( 'Noindex only', 'ace-crawl-enhancer' ) );

        // One indexed meta key narrows these to the scored posts before the LIKE runs, so they cost
        // a fraction of the absence filters below.
        foreach ( self::retention_buckets() as $bucket => $label ) {
            $filters[ 'retention_' . $bucket ] = sprintf( __( 'Retention: %s', 'ace-crawl-enhancer' ), $label );
        }
        $filters['retention_acted'] = __( 'Retention: has a redirect or 410', 'ace-crawl-enhancer' );

        return $filters;
    }

    /** @return array bucket => label, keyed with a hyphen as the report stores it. */
    private static function retention_buckets() {
        return array(
            'keep'        => __( 'keep', 'ace-crawl-enhancer' ),
            'refresh'     => __( 'refresh', 'ace-crawl-enhancer' ),
            'consolidate' => __( 'consolidate', 'ace-crawl-enhancer' ),
            'noindex'     => __( 'noindex', 'ace-crawl-enhancer' ),
            'no-signal'   => __( 'no signal', 'ace-crawl-enhancer' ),
        );
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

        self::render_retention_filters( $post_type );

        if ( self::whitehat_on() && in_array( $post_type, (array) AceSeoWhiteHat::options()['post_types'], true ) ) {
            $wh = isset( $_GET['ace_wh'] ) ? sanitize_key( wp_unslash( $_GET['ace_wh'] ) ) : '';
            echo '<select name="ace_wh" aria-label="' . esc_attr__( 'White hat status', 'ace-crawl-enhancer' ) . '"><option value="">' . esc_html__( 'Any white hat status', 'ace-crawl-enhancer' ) . '</option>';
            foreach ( AceSeoWhiteHat::labels() as $value => $label ) {
                printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $wh, $value, false ), esc_html( $label ) );
            }
            echo '</select>';
        }
    }

    /** Is the retention report about this post type? */
    private static function is_report_type( $post_type ) {
        if ( ! class_exists( 'AceSeoRetentionReport' ) ) {
            return false;
        }
        return in_array( $post_type, (array) AceSeoRetentionReport::settings()['post_types'], true );
    }

    /**
     * Retention tier, published before, and view thresholds: combined with each other and with the
     * list's own filters. Plus the export of whatever the list is showing.
     *
     * @param string $post_type
     * @return void
     */
    private static function render_retention_filters( $post_type ) {
        if ( ! self::is_report_type( $post_type ) ) {
            return;
        }
        $get = function ( $key ) {
            return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
        };
        $current = sanitize_key( $get( 'ace_ret' ) );

        echo '<select name="ace_ret" aria-label="' . esc_attr__( 'Retention tier', 'ace-crawl-enhancer' ) . '"><option value="">'
            . esc_html__( 'All retention tiers', 'ace-crawl-enhancer' ) . '</option>';
        foreach ( AceSeoRetentionReport::tier_labels() + array( 'scored' => __( 'Any old post (scored)', 'ace-crawl-enhancer' ) ) as $value => $label ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
        }
        echo '</select>';

        printf(
            '<label class="screen-reader-text" for="ace-before">%1$s</label><input type="date" id="ace-before" name="ace_before" value="%2$s" title="%1$s" style="float:left;margin-right:6px">',
            esc_attr__( 'Published before', 'ace-crawl-enhancer' ),
            esc_attr( $get( 'ace_before' ) )
        );
        printf(
            '<input type="number" min="0" name="ace_views_min" value="%s" placeholder="%s" title="%s" style="float:left;width:7em;margin-right:6px">',
            esc_attr( $get( 'ace_views_min' ) ),
            esc_attr__( 'Views from', 'ace-crawl-enhancer' ),
            esc_attr__( 'At least this many views in the report window', 'ace-crawl-enhancer' )
        );
        printf(
            '<input type="number" min="0" name="ace_views_max" value="%s" placeholder="%s" title="%s" style="float:left;width:7em;margin-right:6px">',
            esc_attr( $get( 'ace_views_max' ) ),
            esc_attr__( 'Views to', 'ace-crawl-enhancer' ),
            esc_attr__( 'At most this many views in the report window', 'ace-crawl-enhancer' )
        );
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
        self::apply_retention_filters( $query, $_GET );
        self::apply_sort( $query, $screen->post_type );
        self::apply_retention_sort( $query );
    }

    /**
     * AND a set of meta clauses onto whatever meta_query the query already has.
     *
     * @param WP_Query $query
     * @param array    $clauses
     * @return void
     */
    private static function add_meta_clauses( $query, array $clauses ) {
        if ( ! $clauses ) {
            return;
        }
        $existing = $query->get( 'meta_query' );
        $merged   = array( 'relation' => 'AND' );
        if ( ! empty( $existing ) ) {
            $merged[] = $existing;
        }
        foreach ( $clauses as $key => $clause ) {
            if ( is_string( $key ) ) {
                $merged[ $key ] = $clause;
            } else {
                $merged[] = $clause;
            }
        }
        $query->set( 'meta_query', $merged );
    }

    /**
     * Retention tier, published before and view thresholds.
     *
     * @param WP_Query $query
     * @param array    $params Request arguments (the list's $_GET, or an export's copy of it).
     * @return void
     */
    public static function apply_retention_filters( $query, array $params ) {
        if ( ! class_exists( 'AceSeoRetentionReport' ) ) {
            return;
        }
        $clauses = array();
        $tier    = isset( $params['ace_ret'] ) ? sanitize_key( wp_unslash( $params['ace_ret'] ) ) : '';
        if ( 'scored' === $tier ) {
            $clauses[] = array( 'key' => AceSeoRetentionReport::META_TIER, 'compare' => 'EXISTS' );
        } elseif ( in_array( $tier, AceSeoRetentionReport::TIERS, true ) ) {
            $clauses[] = array( 'key' => AceSeoRetentionReport::META_TIER, 'value' => $tier );
        }

        $wh = isset( $params['ace_wh'] ) ? sanitize_key( wp_unslash( $params['ace_wh'] ) ) : '';
        if ( in_array( $wh, array( 'yes', 'no', 'unknown' ), true ) ) {
            $clauses[] = array( 'key' => '_ace_seo_whitehat', 'value' => $wh );
        }

        foreach ( array( 'ace_views_min' => '>=', 'ace_views_max' => '<=' ) as $param => $compare ) {
            if ( isset( $params[ $param ] ) && '' !== $params[ $param ] && is_numeric( $params[ $param ] ) ) {
                $clauses[] = array(
                    'key'     => AceSeoRetentionReport::META_VIEWS,
                    'value'   => max( 0, (int) $params[ $param ] ),
                    'compare' => $compare,
                    'type'    => 'NUMERIC',
                );
            }
        }
        self::add_meta_clauses( $query, $clauses );

        $before = isset( $params['ace_before'] ) ? sanitize_text_field( wp_unslash( $params['ace_before'] ) ) : '';
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ) {
            $date_query   = (array) $query->get( 'date_query' );
            $date_query[] = array( 'before' => $before, 'inclusive' => false, 'column' => 'post_date' );
            $query->set( 'date_query', $date_query );
        }
    }

    /**
     * @param WP_Query $query
     * @return void
     */
    private static function apply_retention_sort( $query ) {
        $orderby = $query->get( 'orderby' );
        $sorts   = self::retention_sorts();
        if ( ! is_string( $orderby ) || ! isset( $sorts[ $orderby ] ) ) {
            return;
        }
        list( $key, $type ) = $sorts[ $orderby ];
        self::add_meta_clauses( $query, array( 'ace_sort' => array( 'key' => $key, 'compare' => 'EXISTS', 'type' => $type ) ) );
        $order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
        $query->set( 'orderby', array( 'ace_sort' => $order, 'date' => 'DESC' ) );
    }

    /**
     * @param WP_Query $query
     * @return void
     */
    private static function apply_filter( $query, $params = null ) {
        $params = null === $params ? $_GET : $params;
        $filter = isset( $params['ace_seo_filter'] ) ? sanitize_key( wp_unslash( $params['ace_seo_filter'] ) ) : '';

        if ( '' === $filter ) {
            return;
        }

        if ( 0 === strpos( $filter, 'retention_' ) && class_exists( 'AceSeoRetentionReport' ) ) {
            $bucket = substr( $filter, strlen( 'retention_' ) );

            if ( 'acted' === $bucket ) {
                $query->set( 'meta_query', array( array( 'key' => AceSeoRetentionActions::META_REDIRECT, 'value' => '', 'compare' => '!=' ) ) );
                return;
            }

            if ( isset( self::retention_buckets()[ $bucket ] ) ) {
                // The verdict is one field of a serialised array; matching the serialised fragment
                // is exact for the bucket name and cheap behind the meta_key index.
                $query->set(
                    'meta_query',
                    array(
                        array(
                            'key'     => AceSeoRetentionReport::META,
                            'value'   => 's:6:"bucket";s:' . strlen( $bucket ) . ':"' . $bucket . '";',
                            'compare' => 'LIKE',
                        ),
                    )
                );
            }

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
