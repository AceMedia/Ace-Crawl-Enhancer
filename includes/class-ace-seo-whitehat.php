<?php
/**
 * White hat status: does a post link to a bet or push readers to place one?
 *
 * Off by default, and generic: what counts as a bet link, a call to action or a bet block is all in
 * the settings (Ace SEO, White hat), so a site that is not about betting never sees any of it.
 *
 * How a post is assessed, cheapest first, so AI tokens are only spent where rules cannot decide:
 *
 *   1. The post is rendered (blocks and shortcodes), so a dynamic bet banner that has dropped off
 *      after its event is judged as it is now, not as it was saved.
 *   2. Any link matching a bet link pattern (and not an allowed pattern, such as the operator's
 *      homepage) makes it not white hat. No AI.
 *   3. Only sentences containing a betting keyword are kept. None: white hat. No AI.
 *   4. Those sentences (capped) go to the configured AI provider, or, with no provider, are matched
 *      against the call to action patterns.
 *   5. A hash of what was judged is stored; a later check with the same links and sentences reuses
 *      the verdict without a call.
 *
 * When it runs: shortly after a post is published or updated (cron, so saving is not slowed), again
 * after the latest event time found in its bet blocks has passed (plus a grace period), and in an
 * hourly sweep that re-renders posts marked not white hat or unknown once a day. A re-render whose
 * hash has not changed costs no tokens.
 *
 * Stored per post: `_ace_seo_whitehat` (yes, no, unknown) for the post list, and
 * `_ace_seo_whitehat_data` with the reasons, links, method and when it was checked.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AceSeoWhiteHat {

    const OPTION        = 'ace_seo_whitehat_options';
    const META          = '_ace_seo_whitehat';
    const META_DATA     = '_ace_seo_whitehat_data';
    const META_RECHECK  = '_ace_seo_whitehat_recheck';
    const META_CHECKED  = '_ace_seo_whitehat_checked';
    const CHECK_HOOK    = 'ace_seo_whitehat_check';
    const SWEEP_HOOK    = 'ace_seo_whitehat_sweep';
    const STATUSES      = array( 'yes', 'no', 'unknown' );

    public static function init() {
        add_action( self::CHECK_HOOK, array( __CLASS__, 'run_check' ) );
        add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 21 );
            add_action( 'admin_post_ace_seo_whitehat_save', array( __CLASS__, 'handle_save' ) );
        }

        if ( ! self::enabled() ) {
            if ( wp_next_scheduled( self::SWEEP_HOOK ) ) {
                wp_clear_scheduled_hook( self::SWEEP_HOOK );
            }
            return;
        }
        add_action( 'save_post', array( __CLASS__, 'on_save' ), 30, 2 );
        if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
            wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
        }
    }

    /* ---- Settings ------------------------------------------------------------------------------ */

    public static function defaults() {
        return array(
            'enabled'         => 0,
            'post_types'      => array( 'post' ),
            'provider'        => 'none',     // none, anthropic, openai
            'model'           => '',
            'api_key'         => '',
            // Substrings or /regex/, one per line. Empty until a site says what a bet link looks like.
            'bet_links'       => '',
            'allowed_links'   => '',
            'keywords'        => "bet\nbets\nbetting\nodds\nstake\nacca\naccumulator\nbet builder\nbetslip\nfree bet\nboost\nprice\nback\nwager\ntip\ntips",
            'cta_patterns'    => "bet now\nplace a bet\nplace your bet\nget on\nback them\nback him\nback her\nadd to betslip\nclaim your\nsign up and\nbet here",
            'bet_blocks'      => '',
            'event_attr'      => 'eventTime',
            'grace_hours'     => 2,
            'max_chars'       => 3000,
        );
    }

    public static function options() {
        $saved = get_option( self::OPTION, array() );
        $o     = array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() );
        if ( defined( 'ACE_SEO_WHITEHAT_API_KEY' ) && ACE_SEO_WHITEHAT_API_KEY ) {
            $o['api_key'] = (string) ACE_SEO_WHITEHAT_API_KEY;
        }
        return apply_filters( 'ace_seo_whitehat_options', $o );
    }

    public static function enabled() {
        return ! empty( self::options()['enabled'] );
    }

    private static function lines( $text ) {
        return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $text ) ) ) );
    }

    /** A line is a /regex/ if it looks like one, otherwise a case-insensitive substring. */
    private static function matches_any( $subject, array $patterns ) {
        foreach ( $patterns as $p ) {
            if ( strlen( $p ) > 2 && '/' === $p[0] && '/' === substr( $p, -1 ) ) {
                if ( @preg_match( $p . 'i', $subject ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad pattern from settings must not fatal
                    return $p;
                }
            } elseif ( false !== stripos( $subject, $p ) ) {
                return $p;
            }
        }
        return '';
    }

    /* ---- Assessing ------------------------------------------------------------------------------ */

    public static function on_save( $post_id, $post ) {
        if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! in_array( $post->post_type, (array) self::options()['post_types'], true ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CHECK_HOOK, array( (int) $post_id ) ) ) {
            wp_schedule_single_event( time() + 30, self::CHECK_HOOK, array( (int) $post_id ) );
        }
    }

    public static function run_check( $post_id ) {
        if ( self::enabled() ) {
            self::assess( (int) $post_id );
        }
    }

    /** The post as a visitor would get it now: blocks and shortcodes rendered. */
    private static function render_post( WP_Post $post ) {
        global $wp_query;
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below
        setup_postdata( $post );
        $html = do_shortcode( do_blocks( $post->post_content ) );
        $GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
        if ( $previous instanceof WP_Post ) {
            setup_postdata( $previous );
        } elseif ( $wp_query instanceof WP_Query ) {
            wp_reset_postdata();
        }
        return (string) $html;
    }

    /** The latest event time in the post's bet blocks (Unix time), 0 when there is none. */
    private static function latest_event( WP_Post $post, array $o ) {
        $names = array_filter( array_map( 'trim', explode( ',', (string) $o['bet_blocks'] ) ) );
        $attr  = (string) $o['event_attr'];
        if ( ! $names || '' === $attr || ! has_blocks( $post->post_content ) ) {
            return 0;
        }
        $latest = 0;
        $walk   = function ( $blocks ) use ( &$walk, &$latest, $names, $attr ) {
            foreach ( $blocks as $b ) {
                if ( in_array( $b['blockName'] ?? '', $names, true ) && ! empty( $b['attrs'][ $attr ] ) ) {
                    $v  = $b['attrs'][ $attr ];
                    $ts = is_numeric( $v ) ? (int) ( $v > 1e11 ? $v / 1000 : $v ) : (int) strtotime( (string) $v );
                    $latest = max( $latest, $ts );
                }
                if ( ! empty( $b['innerBlocks'] ) ) {
                    $walk( $b['innerBlocks'] );
                }
            }
        };
        $walk( parse_blocks( $post->post_content ) );
        return (int) apply_filters( 'ace_seo_whitehat_event_time', $latest, $post );
    }

    /**
     * Assess one post and store the verdict. $force skips the unchanged-content shortcut.
     *
     * @return array The stored data (status, reasons, links, method, model, hash, checked, recheck).
     */
    public static function assess( $post_id, $force = false ) {
        $post = get_post( $post_id );
        $o    = self::options();
        if ( ! $post || 'publish' !== $post->post_status ) {
            return array();
        }

        $html = self::render_post( $post );

        // 2. Links.
        $bet_links = array();
        if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $bet_p   = self::lines( $o['bet_links'] );
            $allow_p = self::lines( $o['allowed_links'] );
            foreach ( array_unique( $m[1] ) as $href ) {
                $href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
                if ( $bet_p && self::matches_any( $href, $bet_p ) && ! ( $allow_p && self::matches_any( $href, $allow_p ) ) ) {
                    $bet_links[] = $href;
                }
            }
        }

        // 3. Sentences that mention betting at all.
        $text      = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) );
        $keywords  = self::lines( $o['keywords'] );
        $sentences = array();
        if ( $keywords && '' !== $text ) {
            $rx = '/\b(' . implode( '|', array_map( function ( $k ) { return preg_quote( $k, '/' ); }, $keywords ) ) . ')\b/iu';
            foreach ( preg_split( '/(?<=[.!?])\s+/u', $text ) as $s ) {
                if ( preg_match( $rx, $s ) ) {
                    $sentences[] = $s;
                }
            }
        }
        $excerpt = mb_substr( implode( "\n", $sentences ), 0, max( 200, (int) $o['max_chars'] ) );

        $hash     = md5( wp_json_encode( array( $bet_links, $excerpt, $post->post_title, $o['provider'], $o['model'] ) ) );
        $previous = get_post_meta( $post->ID, self::META_DATA, true );
        $event    = self::latest_event( $post, $o );
        $recheck  = $event > time() ? $event + (int) $o['grace_hours'] * HOUR_IN_SECONDS : 0;

        if ( ! $force && is_array( $previous ) && ( $previous['hash'] ?? '' ) === $hash && in_array( $previous['status'] ?? '', array( 'yes', 'no' ), true ) ) {
            $previous['checked'] = time();
            $previous['recheck'] = $recheck;
            self::store( $post->ID, $previous );
            return $previous;
        }

        $data = array( 'hash' => $hash, 'links' => $bet_links, 'model' => '', 'reasons' => array() );
        if ( $bet_links ) {
            $data['status']    = 'no';
            $data['method']    = 'rules';
            $data['reasons'][] = sprintf( 'Links to a bet: %s', implode( ', ', array_slice( $bet_links, 0, 5 ) ) );
        } elseif ( '' === $excerpt ) {
            $data['status']    = 'yes';
            $data['method']    = 'rules';
            $data['reasons'][] = 'No bet links and no betting language.';
        } elseif ( 'none' === $o['provider'] || '' === $o['api_key'] ) {
            $cta               = self::matches_any( $excerpt, self::lines( $o['cta_patterns'] ) );
            $data['status']    = $cta ? 'no' : 'yes';
            $data['method']    = 'rules';
            $data['reasons'][] = $cta ? sprintf( 'Betting call to action: "%s".', $cta ) : 'No bet links and no call to action phrase matched.';
        } else {
            $ai = self::ask_ai( $post, $excerpt, $o );
            if ( is_wp_error( $ai ) ) {
                $data['status']    = 'unknown';
                $data['method']    = 'ai-error';
                $data['reasons'][] = $ai->get_error_message();
                $data['hash']      = ''; // try again next time rather than trusting a failure
            } else {
                $data['status']  = $ai['white_hat'] ? 'yes' : 'no';
                $data['method']  = 'ai';
                $data['model']   = $o['model'];
                $data['reasons'] = array_slice( array_map( 'sanitize_text_field', (array) $ai['reasons'] ), 0, 5 );
            }
        }
        $data['checked'] = time();
        $data['recheck'] = $recheck;

        $data = apply_filters( 'ace_seo_whitehat_result', $data, $post->ID );
        self::store( $post->ID, $data );
        return $data;
    }

    private static function store( $post_id, array $data ) {
        update_post_meta( $post_id, self::META, (string) $data['status'] );
        update_post_meta( $post_id, self::META_DATA, $data );
        update_post_meta( $post_id, self::META_CHECKED, (int) $data['checked'] );
        if ( ! empty( $data['recheck'] ) ) {
            update_post_meta( $post_id, self::META_RECHECK, (int) $data['recheck'] );
        } else {
            delete_post_meta( $post_id, self::META_RECHECK );
        }
    }

    /** The instruction the AI gets. Filterable; kept short because it is sent with every check. */
    private static function instruction() {
        return (string) apply_filters( 'ace_seo_whitehat_instruction',
            'You check sports news articles for a betting company. An article is white hat when it contains no betting call to action: it does not tell or encourage the reader to place, back, add or claim a bet, and does not offer odds as a bet to take. Reporting odds or prices as information, and mentioning the brand, are fine. You get the headline and only the sentences that mention betting. Reply with JSON only: {"white_hat": true or false, "reasons": ["short reason", ...]}.'
        );
    }

    /**
     * One classification call. Returns array( white_hat => bool, reasons => string[] ) or WP_Error.
     * `pre_ace_seo_whitehat_ai` can answer instead (tests, a site's own classifier), so no call is made.
     */
    private static function ask_ai( WP_Post $post, $excerpt, array $o ) {
        $input = 'Headline: ' . wp_strip_all_tags( $post->post_title ) . "\n\nSentences:\n" . $excerpt;

        $pre = apply_filters( 'pre_ace_seo_whitehat_ai', null, $input, $post, $o );
        if ( null !== $pre ) {
            return is_wp_error( $pre ) ? $pre : array( 'white_hat' => ! empty( $pre['white_hat'] ), 'reasons' => (array) ( $pre['reasons'] ?? array() ) );
        }
        if ( '' === $o['model'] ) {
            return new WP_Error( 'ace_seo_whitehat_model', 'No AI model is set (Ace SEO, White hat).' );
        }

        if ( 'anthropic' === $o['provider'] ) {
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
                'timeout' => 60,
                'headers' => array(
                    'x-api-key'         => $o['api_key'],
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ),
                'body'    => wp_json_encode( array(
                    'model'         => $o['model'],
                    'max_tokens'    => 1024,
                    'system'        => self::instruction(),
                    'messages'      => array( array( 'role' => 'user', 'content' => $input ) ),
                    'output_config' => array( 'effort' => 'low' ),
                ) ),
            ) );
        } elseif ( 'openai' === $o['provider'] ) {
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $o['api_key'],
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( array(
                    'model'           => $o['model'],
                    'messages'        => array(
                        array( 'role' => 'system', 'content' => self::instruction() ),
                        array( 'role' => 'user', 'content' => $input ),
                    ),
                    'response_format' => array( 'type' => 'json_object' ),
                ) ),
            ) );
        } else {
            return new WP_Error( 'ace_seo_whitehat_provider', 'Unknown AI provider.' );
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'ace_seo_whitehat_http', sprintf( 'AI request failed (HTTP %d): %s', $code, $body['error']['message'] ?? '' ) );
        }

        $text = '';
        if ( 'anthropic' === $o['provider'] ) {
            if ( 'refusal' === ( $body['stop_reason'] ?? '' ) ) {
                return new WP_Error( 'ace_seo_whitehat_refusal', 'The AI declined to classify this post.' );
            }
            foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
                if ( 'text' === ( $block['type'] ?? '' ) ) {
                    $text .= (string) $block['text'];
                }
            }
        } else {
            $text = (string) ( $body['choices'][0]['message']['content'] ?? '' );
        }

        if ( ! preg_match( '/\{.*\}/s', $text, $json ) ) {
            return new WP_Error( 'ace_seo_whitehat_parse', 'The AI reply was not JSON.' );
        }
        $parsed = json_decode( $json[0], true );
        if ( ! is_array( $parsed ) || ! array_key_exists( 'white_hat', $parsed ) ) {
            return new WP_Error( 'ace_seo_whitehat_parse', 'The AI reply had no white_hat field.' );
        }
        return array( 'white_hat' => (bool) $parsed['white_hat'], 'reasons' => (array) ( $parsed['reasons'] ?? array() ) );
    }

    /**
     * Hourly: posts whose bet block event (plus grace) has passed, then posts marked no or unknown
     * that were last checked over a day ago. Bounded per run; unchanged content costs no tokens.
     */
    public static function sweep() {
        global $wpdb;
        if ( ! self::enabled() ) {
            return;
        }
        $limit = (int) apply_filters( 'ace_seo_whitehat_sweep_batch', 25 );
        $due   = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) <= %d LIMIT %d",
            self::META_RECHECK,
            time(),
            $limit
        ) );
        $stale = $wpdb->get_col( $wpdb->prepare(
            "SELECT s.post_id FROM {$wpdb->postmeta} s INNER JOIN {$wpdb->postmeta} c ON c.post_id = s.post_id AND c.meta_key = %s WHERE s.meta_key = %s AND s.meta_value IN ('no','unknown') AND CAST(c.meta_value AS UNSIGNED) < %d ORDER BY c.meta_value ASC LIMIT %d",
            self::META_CHECKED,
            self::META,
            time() - DAY_IN_SECONDS,
            $limit
        ) );
        foreach ( array_unique( array_map( 'intval', array_merge( $due, $stale ) ) ) as $id ) {
            self::assess( $id );
        }
    }

    public static function labels() {
        return array(
            'yes'     => __( 'White hat', 'ace-crawl-enhancer' ),
            'no'      => __( 'Not white hat', 'ace-crawl-enhancer' ),
            'unknown' => __( 'Unknown', 'ace-crawl-enhancer' ),
        );
    }

    /* ---- Admin ---------------------------------------------------------------------------------- */

    public static function add_menu() {
        add_submenu_page( 'ace-seo', 'White hat', 'White hat', 'manage_options', 'ace-seo-whitehat', array( __CLASS__, 'render' ) );
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_whitehat_save' ) ) {
            wp_die( 'Not allowed.' );
        }
        $in      = wp_unslash( $_POST );
        $current = get_option( self::OPTION, array() );
        $current = is_array( $current ) ? $current : array();
        $clean   = array(
            'enabled'       => ! empty( $in['enabled'] ) ? 1 : 0,
            'post_types'    => array_values( array_filter( array_map( 'sanitize_key', (array) ( $in['post_types'] ?? array( 'post' ) ) ), 'post_type_exists' ) ),
            'provider'      => in_array( $in['provider'] ?? '', array( 'none', 'anthropic', 'openai' ), true ) ? $in['provider'] : 'none',
            'model'         => sanitize_text_field( (string) ( $in['model'] ?? '' ) ),
            // A blank key field keeps the saved key; the word "clear" removes it.
            'api_key'       => 'clear' === trim( (string) ( $in['api_key'] ?? '' ) ) ? '' : ( '' !== trim( (string) ( $in['api_key'] ?? '' ) ) ? sanitize_text_field( $in['api_key'] ) : (string) ( $current['api_key'] ?? '' ) ),
            'bet_links'     => sanitize_textarea_field( (string) ( $in['bet_links'] ?? '' ) ),
            'allowed_links' => sanitize_textarea_field( (string) ( $in['allowed_links'] ?? '' ) ),
            'keywords'      => sanitize_textarea_field( (string) ( $in['keywords'] ?? '' ) ),
            'cta_patterns'  => sanitize_textarea_field( (string) ( $in['cta_patterns'] ?? '' ) ),
            'bet_blocks'    => sanitize_text_field( (string) ( $in['bet_blocks'] ?? '' ) ),
            'event_attr'    => sanitize_key( (string) ( $in['event_attr'] ?? 'eventTime' ) ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $in['event_attr'] ) : '',
            'grace_hours'   => max( 0, min( 168, (int) ( $in['grace_hours'] ?? 2 ) ) ),
            'max_chars'     => max( 200, min( 20000, (int) ( $in['max_chars'] ?? 3000 ) ) ),
        );
        if ( ! $clean['post_types'] ) {
            $clean['post_types'] = array( 'post' );
        }
        update_option( self::OPTION, $clean, false );

        $msg = 'Saved.';
        if ( ! empty( $in['check_ids'] ) && $clean['enabled'] ) {
            $ids = array_slice( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $in['check_ids'] ) ) ), 0, 20 );
            foreach ( $ids as $id ) {
                self::assess( $id, true );
            }
            $msg .= sprintf( ' Checked %d post(s).', count( $ids ) );
        }
        set_transient( 'ace_seo_whitehat_msg_' . get_current_user_id(), $msg, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-whitehat' ) );
        exit;
    }

    public static function counts() {
        global $wpdb;
        $out = array_fill_keys( self::STATUSES, 0 );
        foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_value, COUNT(*) n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META ) ) as $r ) {
            if ( isset( $out[ $r->meta_value ] ) ) {
                $out[ $r->meta_value ] = (int) $r->n;
            }
        }
        return $out;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $o   = self::options();
        $msg = get_transient( 'ace_seo_whitehat_msg_' . get_current_user_id() );
        ?>
        <div class="wrap">
            <h1>White hat status</h1>
            <?php if ( $msg ) { delete_transient( 'ace_seo_whitehat_msg_' . get_current_user_id() ); echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>'; } ?>
            <p>Which posts have no bet links and no betting calls to action. Rules decide first (bet links, then whether the post mentions betting at all); only posts that mention betting without a bet link go to the AI, with just the headline and the sentences that mention betting, and only when those have changed since the last check. Posts are checked after they are published or updated, again after the events in their bet blocks have passed, and daily while they are not white hat.</p>
            <?php if ( self::enabled() ) : $c = self::counts(); ?>
                <p><?php foreach ( self::labels() as $k => $l ) { echo esc_html( $l ) . ': <strong>' . esc_html( number_format_i18n( $c[ $k ] ) ) . '</strong> &nbsp; '; } ?>
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=post&ace_wh=no' ) ); ?>">List posts that are not white hat</a></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ace_seo_whitehat_save' ); ?>
                <input type="hidden" name="action" value="ace_seo_whitehat_save">
                <table class="form-table" style="max-width:1000px"><tbody>
                    <tr><th scope="row">On</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $o['enabled'] ) ); ?>> Assess white hat status</label></td></tr>
                    <tr><th scope="row">Post types</th><td><?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : if ( 'attachment' === $pt->name ) { continue; } ?>
                        <label style="margin-right:1em"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $o['post_types'], true ) ); ?>> <?php echo esc_html( $pt->labels->name ); ?></label>
                    <?php endforeach; ?></td></tr>
                    <tr><th scope="row">Bet links</th><td><textarea name="bet_links" rows="4" class="large-text code" placeholder="betslip&#10;/bet/&#10;/^https:\/\/sports\.example\.com\/.+\/"><?php echo esc_textarea( $o['bet_links'] ); ?></textarea><p class="description">One per line: a piece of the URL, or a <code>/regular expression/</code>. Any matching link makes a post not white hat.</p></td></tr>
                    <tr><th scope="row">Allowed links</th><td><textarea name="allowed_links" rows="2" class="large-text code" placeholder="/^https:\/\/www\.example\.com\/?$/"><?php echo esc_textarea( $o['allowed_links'] ); ?></textarea><p class="description">Links that match a bet link pattern but are fine, such as the operator's homepage.</p></td></tr>
                    <tr><th scope="row">Betting keywords</th><td><textarea name="keywords" rows="3" class="large-text"><?php echo esc_textarea( $o['keywords'] ); ?></textarea><p class="description">Whole words, one per line. Only sentences containing one are looked at; a post with none is white hat without an AI call.</p></td></tr>
                    <tr><th scope="row">Calls to action</th><td><textarea name="cta_patterns" rows="3" class="large-text"><?php echo esc_textarea( $o['cta_patterns'] ); ?></textarea><p class="description">Used instead of the AI when no provider is set.</p></td></tr>
                    <tr><th scope="row">Bet blocks</th><td><input type="text" name="bet_blocks" value="<?php echo esc_attr( $o['bet_blocks'] ); ?>" class="regular-text" placeholder="vendor/bet-builder"> with the event time in the attribute <input type="text" name="event_attr" value="<?php echo esc_attr( $o['event_attr'] ); ?>" style="width:9em"> , rechecked <input type="number" name="grace_hours" min="0" max="168" value="<?php echo esc_attr( (int) $o['grace_hours'] ); ?>" style="width:4em"> hours after it
                        <p class="description">Block names, comma separated. When the latest event in a post has passed, the post is rendered again, so banners that have dropped off are no longer counted.</p></td></tr>
                    <tr><th scope="row">AI provider</th><td>
                        <select name="provider"><?php foreach ( array( 'none' => 'None: rules only', 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI' ) as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['provider'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
                        <label>Model <input type="text" name="model" value="<?php echo esc_attr( $o['model'] ); ?>" class="regular-text" placeholder="claude-opus-5"></label>
                        <p><label>API key <input type="password" name="api_key" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo esc_attr( '' !== $o['api_key'] ? 'Saved (leave blank to keep, type clear to remove)' : 'Not set' ); ?>"></label></p>
                        <p class="description">Or define <code>ACE_SEO_WHITEHAT_API_KEY</code> in wp-config.php. Up to <input type="number" name="max_chars" min="200" max="20000" value="<?php echo esc_attr( (int) $o['max_chars'] ); ?>" style="width:6em"> characters of betting sentences are sent per check.</p>
                    </td></tr>
                    <tr><th scope="row">Check now</th><td><input type="text" name="check_ids" class="regular-text" placeholder="Post IDs, comma separated (up to 20)"><p class="description">Checked when you save, ignoring the unchanged-content shortcut. For many posts use <code>wp ace-crawl whitehat check</code>.</p></td></tr>
                </tbody></table>
                <p><button class="button button-primary">Save</button></p>
            </form>
        </div>
        <?php
    }

    /* ---- WP-CLI --------------------------------------------------------------------------------- */

    public static function register_cli() {
        WP_CLI::add_command( 'ace-crawl whitehat check', array( __CLASS__, 'cli_check' ) );
    }

    /**
     * Assess white hat status.
     *
     * ## OPTIONS
     *
     * [--ids=<ids>]
     * : Comma-separated post IDs.
     *
     * [--unchecked]
     * : Published posts of the configured types with no status yet, newest first.
     *
     * [--limit=<n>]
     * : With --unchecked, at most this many. Default 100.
     *
     * [--force]
     * : Ignore the unchanged-content shortcut.
     *
     * [--dry-run]
     * : List the posts only.
     */
    public static function cli_check( $args, $assoc ) {
        $o = self::options();
        if ( ! empty( $assoc['ids'] ) ) {
            $ids = array_filter( array_map( 'absint', explode( ',', $assoc['ids'] ) ) );
        } elseif ( ! empty( $assoc['unchecked'] ) ) {
            $ids = get_posts( array(
                'post_type'      => (array) $o['post_types'],
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => (int) ( $assoc['limit'] ?? 100 ),
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => array( array( 'key' => self::META, 'compare' => 'NOT EXISTS' ) ),
            ) );
        } else {
            WP_CLI::error( 'Give --ids or --unchecked.' );
        }
        if ( ! empty( $assoc['dry-run'] ) ) {
            WP_CLI::success( sprintf( 'Would check %d post(s): %s', count( $ids ), implode( ',', $ids ) ) );
            return;
        }
        $rows = array();
        foreach ( $ids as $id ) {
            $d      = self::assess( $id, ! empty( $assoc['force'] ) );
            $rows[] = array( 'id' => $id, 'status' => $d['status'] ?? '', 'method' => $d['method'] ?? '', 'reasons' => implode( ' | ', (array) ( $d['reasons'] ?? array() ) ) );
        }
        WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'status', 'method', 'reasons' ) );
    }
}
