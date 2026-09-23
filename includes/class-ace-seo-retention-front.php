<?php
/**
 * Retained posts on the front end: a lighter page, and a way on into current content.
 *
 * A retained post is an old post that is still being read (the retention report's "retained"
 * tier). Rather than removing it, serve it cheaply and hand its reader on to something current:
 *
 *   - lighter: the blocks named in the settings (by class name or template part slug, "sidebar" by
 *     default) are not rendered at all, classic widget areas are emptied, and Ace Redis Cache keeps
 *     the page for longer (ace_rc_page_ttl).
 *   - continue: at the end of the post a "keep reading" card links to the latest post in the same
 *     category, and a reader who scrolls on past it is taken there, back into the normal layout.
 *
 * Everything is off until switched on (Ace SEO, Retention). Only singular posts of the report's post
 * types in the retained tier, seen by visitors who are not logged in, are affected: pages, the cart,
 * checkout and account pages never are.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoRetentionFront {

    private static $active = false;
    private static $drop   = array();

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        if ( ! is_admin() ) {
            add_action( 'template_redirect', array( __CLASS__, 'setup' ), 20 );
        }
    }

    private static function options() {
        return AceSeoRetentionActions::options();
    }

    /** Is the current request a retained post that gets the light treatment? */
    public static function applies() {
        $o = self::options();
        if ( empty( $o['light_enabled'] ) || is_admin() || is_preview() || is_feed() || is_user_logged_in() || ! is_singular() || is_page() ) {
            return false;
        }
        if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
            return false;
        }
        $post = get_queried_object();
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, (array) AceSeoRetentionReport::settings()['post_types'], true ) ) {
            return false;
        }

        /**
         * Filter whether this request gets the lighter retained-post treatment.
         *
         * @param bool    $applies
         * @param WP_Post $post
         */
        return (bool) apply_filters( 'ace_seo_retention_light_applies', AceSeoRetentionActions::is_retained( $post->ID ), $post );
    }

    public static function setup() {
        if ( ! self::applies() ) {
            return;
        }
        self::$active = true;
        $o            = self::options();
        self::$drop   = array_filter( array_map( 'trim', explode( ',', (string) $o['light_drop'] ) ) );

        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
        add_filter( 'pre_render_block', array( __CLASS__, 'skip_block' ), 10, 2 );
        add_filter( 'sidebars_widgets', array( __CLASS__, 'empty_sidebars' ) );
        add_action( 'wp_head', array( __CLASS__, 'print_style' ), 99 );

        if ( (int) $o['light_cache_hours'] > 0 ) {
            add_filter( 'ace_rc_page_ttl', array( __CLASS__, 'page_ttl' ), 20 );
        }
        if ( 'card' === $o['light_continue'] ) {
            add_filter( 'the_content', array( __CLASS__, 'continue_card' ), 20 );
            add_action( 'wp_footer', array( __CLASS__, 'print_continue_script' ), 99 );
        }

        /**
         * A retained post is being served light: for a CDN or cache layer to act on.
         *
         * @param int $post_id
         */
        do_action( 'ace_seo_retention_light_request', get_queried_object_id() );
    }

    public static function body_class( $classes ) {
        $classes[] = 'ace-seo-retained';
        $classes[] = 'ace-seo-light';
        return $classes;
    }

    /**
     * Skip a block before it renders (so a sidebar's queries never run): one whose class names or
     * template part slug are in the drop list.
     */
    public static function skip_block( $pre, $block ) {
        if ( null !== $pre || ! self::$drop || ! is_array( $block ) ) {
            return $pre;
        }
        $attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
        $classes = isset( $attrs['className'] ) ? preg_split( '/\s+/', (string) $attrs['className'] ) : array();
        if ( array_intersect( $classes, self::$drop ) ) {
            return '';
        }
        if ( 'core/template-part' === ( $block['blockName'] ?? '' ) && in_array( (string) ( $attrs['slug'] ?? '' ), self::$drop, true ) ) {
            return '';
        }
        return $pre;
    }

    /** Classic themes: every widget area is empty, so is_active_sidebar() is false. */
    public static function empty_sidebars( $sidebars ) {
        if ( ! is_array( $sidebars ) ) {
            return $sidebars;
        }
        foreach ( $sidebars as $id => $widgets ) {
            if ( 'wp_inactive_widgets' !== $id && 'array_version' !== $id ) {
                $sidebars[ $id ] = array();
            }
        }
        return $sidebars;
    }

    public static function page_ttl( $ttl ) {
        if ( ! self::$active ) {
            return $ttl;
        }
        return max( (int) $ttl, (int) self::options()['light_cache_hours'] * HOUR_IN_SECONDS );
    }

    public static function print_style() {
        echo '<style id="ace-seo-light">body.ace-seo-light .wp-block-columns>.wp-block-column:only-child{flex-basis:100%!important}'
            . '.ace-seo-continue{margin:2em 0 0;padding:1em 1.25em;border:1px solid rgba(127,127,127,.35);border-radius:.4em}'
            . '.ace-seo-continue__label{margin:0 0 .25em;font-size:.85em;text-transform:uppercase;letter-spacing:.04em;opacity:.75}'
            . '.ace-seo-continue a{font-weight:600}</style>' . "\n";
    }

    /**
     * The latest published post in the post's first category (the one the site shows it under),
     * other than the post itself.
     */
    public static function next_post_id( $post_id ) {
        $post_id = (int) $post_id;
        $cats    = get_the_category( $post_id );
        $next    = 0;
        if ( ! empty( $cats ) ) {
            $cat    = (int) $cats[0]->term_id;
            $latest = wp_cache_get( 'latest_' . $cat, 'ace_seo_retention' );
            if ( false === $latest ) {
                $latest = get_posts( array(
                    'post_type'           => 'post',
                    'post_status'         => 'publish',
                    'cat'                 => $cat,
                    'posts_per_page'      => 2,
                    'orderby'             => 'date',
                    'order'               => 'DESC',
                    'fields'              => 'ids',
                    'no_found_rows'       => true,
                    'ignore_sticky_posts' => true,
                ) );
                wp_cache_set( 'latest_' . $cat, $latest, 'ace_seo_retention', 10 * MINUTE_IN_SECONDS );
            }
            foreach ( (array) $latest as $id ) {
                if ( (int) $id !== $post_id ) {
                    $next = (int) $id;
                    break;
                }
            }
        }

        /**
         * Filter where a reader of a retained post is sent next.
         *
         * @param int $next    Post ID, 0 for nowhere.
         * @param int $post_id The retained post.
         */
        return (int) apply_filters( 'ace_seo_retention_next_post_id', $next, $post_id );
    }

    public static function continue_card( $content ) {
        // As with the dated notice: block themes render Post Content outside the classic loop, so the
        // main post is the queried one; the head and excerpts also run the_content and are skipped.
        if ( ! self::$active || doing_action( 'wp_head' ) || doing_filter( 'get_the_excerpt' ) || false !== strpos( $content, 'ace-seo-continue' ) ) {
            return $content;
        }
        $current = get_post();
        if ( ! $current || (int) $current->ID !== (int) get_queried_object_id() ) {
            return $content;
        }
        $next = self::next_post_id( get_queried_object_id() );
        if ( ! $next ) {
            return $content;
        }
        $cats  = get_the_category( get_queried_object_id() );
        $label = ! empty( $cats ) ? sprintf( __( 'Latest in %s', 'ace-crawl-enhancer' ), $cats[0]->name ) : __( 'Keep reading', 'ace-crawl-enhancer' );
        $html  = '<aside class="ace-seo-continue" data-ace-seo-continue>'
            . '<p class="ace-seo-continue__label">' . esc_html( $label ) . '</p>'
            . '<a href="' . esc_url( get_permalink( $next ) ) . '">' . esc_html( get_the_title( $next ) ) . '</a>'
            . '</aside>';
        return $content . apply_filters( 'ace_seo_retention_continue_html', $html, $next, get_queried_object_id() );
    }

    /**
     * Once the card is fully in view, scrolling on by more than a short distance follows its link:
     * a full page load, so the reader lands in the site's normal layout.
     */
    public static function print_continue_script() {
        ?>
        <script id="ace-seo-continue">(function(){var c=document.querySelector('[data-ace-seo-continue]');if(!c||!('IntersectionObserver' in window)){return;}var a=c.querySelector('a'),y=null,gone=false;new IntersectionObserver(function(e){y=e[0].intersectionRatio>=0.99?window.pageYOffset:y;},{threshold:[0,1]}).observe(c);window.addEventListener('scroll',function(){if(gone||y===null||!a){return;}if(window.pageYOffset-y>120){gone=true;window.location.assign(a.href);}},{passive:true});})();</script>
        <?php
    }

    public static function register_routes() {
        if ( empty( self::options()['light_enabled'] ) ) {
            return;
        }
        register_rest_route( 'ace-seo/v1', '/retention/next', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array( __CLASS__, 'rest_next' ),
            'args'                => array( 'post' => array( 'type' => 'integer', 'required' => true ) ),
        ) );
    }

    /** For a theme's own load-more: where a reader of this post should go next. */
    public static function rest_next( WP_REST_Request $request ) {
        $post = get_post( absint( $request->get_param( 'post' ) ) );
        if ( ! $post || 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) ) {
            return new WP_Error( 'ace_seo_not_found', 'No such post.', array( 'status' => 404 ) );
        }
        $next = self::next_post_id( $post->ID );
        return rest_ensure_response( array(
            'retained' => AceSeoRetentionActions::is_retained( $post->ID ),
            'next'     => $next ? array( 'id' => $next, 'url' => get_permalink( $next ), 'title' => get_the_title( $next ) ) : null,
        ) );
    }
}
