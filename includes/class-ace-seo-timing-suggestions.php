<?php
/**
 * Timing rules suggested from the site's own data, one per category or tag.
 *
 * Nothing here knows what a sport, a festival or a tournament is. For every term with enough assessed
 * posts it looks at three generic things the saved report already holds: how many of the term's older
 * posts are still read at least monthly (persistence), how the term's publication dates fall across the
 * year (seasonality), and how often the term publishes (cadence). From those it proposes one of the
 * three rule types the report understands — evergreen, event-bound for N days, or a yearly season — and
 * says in plain words what it saw. An administrator accepts or ignores each suggestion; accepted rules
 * live in the same timing_rules option as hand-written ones, which always win. Suggestions recompute
 * after every completed check, so they follow the archive as it changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ace_SEO_Timing_Suggestions {

    const OPTION   = 'ace_seo_timing_suggestions';
    /** Fewer assessed posts than this and a term tells us nothing reliable. */
    const MIN_POSTS = 30;

    public static function init() {
        add_action( 'ace_seo_retention_built', array( __CLASS__, 'recompute' ) );
        if ( is_admin() ) {
            add_action( 'admin_post_ace_seo_timing_suggest', array( __CLASS__, 'handle_recompute' ) );
        }
    }

    /* ---- Inference (pure) ------------------------------------------------------------------------- */

    /**
     * Rows: array( array( 'key' => 'category:slug', 'label' => 'Name', 'date' => 'Y-m-d', 'rank' => 'monthly'|'' , 'tier' => 'retained'|... ) ).
     * Returns suggestions keyed by term key, best evidence first.
     */
    public static function infer( array $rows, $min_posts = self::MIN_POSTS ) {
        $terms = array();
        foreach ( $rows as $r ) {
            $k = (string) $r['key'];
            if ( ! isset( $terms[ $k ] ) ) {
                $terms[ $k ] = array( 'key' => $k, 'label' => (string) $r['label'], 'n' => 0, 'persistent' => 0, 'read' => 0, 'dates' => array() );
            }
            $terms[ $k ]['n']++;
            if ( in_array( $r['rank'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ) {
                $terms[ $k ]['persistent']++;
            }
            if ( 'retained' === ( $r['tier'] ?? '' ) ) {
                $terms[ $k ]['read']++;
            }
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) ( $r['date'] ?? '' ) ) ) {
                $terms[ $k ]['dates'][] = substr( $r['date'], 0, 10 );
            }
        }
        $out = array();
        foreach ( $terms as $k => $t ) {
            if ( $t['n'] < $min_posts ) {
                continue;
            }
            $s = self::suggest_for( $t );
            if ( $s ) {
                $out[ $k ] = $s;
            }
        }
        // Impact first: the rule that settles 15,000 posts matters more than one that settles 30.
        uasort( $out, static function ( $a, $b ) {
            return ( $b['posts'] <=> $a['posts'] ) ?: ( $b['confidence'] <=> $a['confidence'] );
        } );
        return $out;
    }

    /** One term's shape → one suggestion (or null when nothing stands out). */
    public static function suggest_for( array $t ) {
        $n           = (int) $t['n'];
        $persistence = $n ? $t['persistent'] / $n : 0;      // share still read at least monthly, years later
        $read_share  = $n ? $t['read'] / $n : 0;
        sort( $t['dates'] );
        $season = self::season_band( $t['dates'] );
        $gap    = self::median_gap_days( $t['dates'] );
        $years  = count( array_unique( array_map( static function ( $d ) { return substr( $d, 0, 4 ); }, $t['dates'] ) ) );
        $base   = array( 'key' => $t['key'], 'label' => $t['label'], 'posts' => $n, 'persistence' => round( 100 * $persistence, 1 ), 'read_share' => round( 100 * $read_share, 1 ), 'years' => $years, 'cadence_days' => $gap );

        // Seasonal: most posts land in one part of the year, in more than one year.
        if ( $season && $years >= 2 ) {
            return $base + array(
                'type'       => 'season',
                'rule'       => 'season ' . $season['start'] . ' ' . $season['end'],
                'confidence' => min( 0.95, 0.5 + $season['share'] / 2 + min( 0.2, $years / 20 ) ),
                'why'        => sprintf( '%d%% of its %s posts were published between %s and %s, across %d years. Older posts should be judged in that season, not in the quiet months.', (int) round( 100 * $season['share'] ), number_format_i18n( $n ), self::md_label( $season['start'] ), self::md_label( $season['end'] ), $years ),
            );
        }
        // Evergreen: a clear share of its old posts is still read monthly or better, all year round.
        if ( $persistence >= 0.15 ) {
            return $base + array(
                'type'       => 'evergreen',
                'rule'       => 'evergreen',
                'confidence' => min( 0.95, 0.4 + $persistence ),
                'why'        => sprintf( '%s%% of its %s older posts are still read at least monthly, years after publication, with no particular season. It behaves like reference content.', number_format_i18n( round( 100 * $persistence, 1 ) ), number_format_i18n( $n ) ),
            );
        }
        // Event-bound: publishes at least weekly, all year round, and its older posts are rarely read later.
        // A sparse tag with no readers is just old, not day-of-event content: it gets no rule.
        if ( $persistence < 0.08 && null !== $gap && $gap <= 7 ) {
            $days    = max( 2, min( 14, (int) ceil( $gap * 1.5 ) + 1 ) );
            $cadence = $gap < 0.75 ? 'more than once a day' : ( $gap < 1.5 ? 'about once a day' : sprintf( 'about every %s days', number_format_i18n( $gap, $gap < 10 ? 1 : 0 ) ) );
            return $base + array(
                'type'       => 'event',
                'rule'       => 'event ' . $days,
                'confidence' => min( 0.9, 0.45 + ( 0.08 - $persistence ) * 4 + min( 0.25, $n / 4000 ) ),
                'why'        => sprintf( 'Only %s%% of its %s older posts are still read at least monthly, and it publishes %s all year round. That is the shape of day-of-event content, relevant for about %d days from publication.', number_format_i18n( round( 100 * $persistence, 1 ) ), number_format_i18n( $n ), $cadence, $days ),
            );
        }
        // A big term with no clear shape is worth saying so about, rather than leaving it out.
        if ( $n >= 500 ) {
            return $base + array(
                'type'       => 'mixed',
                'rule'       => '',
                'confidence' => 0,
                'why'        => sprintf( 'No clear shape: %s%% of its %s older posts are still read at least monthly (between the event-bound and evergreen marks), published all year round%s. Probably a mix of dated and lasting pieces; a narrower tag may suit a rule better, or set timing on the posts that matter.', number_format_i18n( round( 100 * $persistence, 1 ) ), number_format_i18n( $n ), null !== $gap ? ( $gap < 0.75 ? ', more than once a day' : ( $gap < 1.5 ? ', about daily' : sprintf( ', about every %s days', number_format_i18n( $gap, $gap < 10 ? 1 : 0 ) ) ) ) : '' ),
            );
        }
        return null;
    }

    /** The shortest window of the year (as MM-DD .. MM-DD) holding ≥ 60% of the dates, if ≤ 90 days long. */
    public static function season_band( array $dates ) {
        $days = array();
        foreach ( $dates as $d ) {
            $ts = strtotime( $d . ' UTC' );
            if ( $ts ) {
                $days[] = (int) gmdate( 'z', $ts ); // 0..365
            }
        }
        $n = count( $days );
        if ( $n < 10 ) {
            return null;
        }
        sort( $days );
        $need = (int) ceil( $n * 0.6 );
        $best = null;
        // Circular: try every start day; the window wraps past New Year.
        $doubled = array_merge( $days, array_map( static function ( $d ) { return $d + 366; }, $days ) );
        for ( $i = 0; $i < $n; $i++ ) {
            $end_index = $i + $need - 1;
            $len       = $doubled[ $end_index ] - $doubled[ $i ];
            if ( null === $best || $len < $best['len'] ) {
                $best = array( 'len' => $len, 'from' => $doubled[ $i ] % 366, 'to' => $doubled[ $end_index ] % 366 );
            }
        }
        if ( ! $best || $best['len'] > 90 ) {
            return null;
        }
        // Widen slightly so the edges of the season are inside it, then count the share it actually covers.
        $from  = ( $best['from'] - 7 + 366 ) % 366;
        $to    = ( $best['to'] + 7 ) % 366;
        $in    = 0;
        foreach ( $days as $d ) {
            $inside = $from <= $to ? ( $d >= $from && $d <= $to ) : ( $d >= $from || $d <= $to );
            $in    += $inside ? 1 : 0;
        }
        return array( 'start' => self::md_from_yday( $from ), 'end' => self::md_from_yday( $to ), 'share' => $in / $n, 'len' => $best['len'] + 14 );
    }

    /** Typical days between posts: the span from first to last divided by the gaps (null with fewer than 10 dates). */
    public static function median_gap_days( array $dates ) {
        if ( count( $dates ) < 10 ) {
            return null;
        }
        $ts = array_map( static function ( $d ) { return (int) strtotime( $d . ' UTC' ); }, $dates );
        sort( $ts );
        $span = ( end( $ts ) - $ts[0] ) / DAY_IN_SECONDS;
        return round( $span / ( count( $ts ) - 1 ), 2 );
    }

    private static function md_from_yday( $yday ) {
        return gmdate( 'm-d', strtotime( '2024-01-01 UTC' ) + max( 0, min( 365, (int) $yday ) ) * DAY_IN_SECONDS ); // leap year: 366 days
    }

    private static function md_label( $md ) {
        return gmdate( 'j F', strtotime( '2024-' . $md . ' UTC' ) );
    }

    /* ---- Data ------------------------------------------------------------------------------------ */

    /** Every assessed post with its public terms: the rows infer() reads. One query per site. */
    public static function rows() {
        global $wpdb;
        $taxonomies = array();
        foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
            if ( ! empty( $tax->object_type ) && array_intersect( (array) $tax->object_type, (array) AceSeoRetentionReport::settings()['post_types'] ) ) {
                $taxonomies[] = $tax->name;
            }
        }
        $taxonomies = (array) apply_filters( 'ace_seo_timing_suggestion_taxonomies', $taxonomies );
        if ( ! $taxonomies ) {
            return array();
        }
        $in   = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
        $sql  = "SELECT tt.taxonomy, tm.slug, tm.name, p.post_date, tier.meta_value AS tier, r.meta_value AS rank_value
                 FROM {$wpdb->postmeta} tier
                 JOIN {$wpdb->posts} p ON p.ID = tier.post_id
                 LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
                 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ($in)
                 JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id
                 WHERE tier.meta_key = %s";
        $args = array_merge( array( AceSeoRetentionReport::META_RANK ), $taxonomies, array( AceSeoRetentionReport::META_TIER ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        $out  = array();
        foreach ( (array) $rows as $row ) {
            $out[] = array( 'key' => $row['taxonomy'] . ':' . $row['slug'], 'label' => $row['name'], 'date' => $row['post_date'], 'rank' => (string) $row['rank_value'], 'tier' => (string) $row['tier'] );
        }
        return $out;
    }

    /** Work the suggestions out from the current assessment and store them. */
    public static function recompute() {
        $suggestions = self::infer( self::rows() );
        update_option( self::OPTION, array( 'computed' => time(), 'suggestions' => $suggestions ), false );
        return $suggestions;
    }

    /** Stored suggestions, computed now if there are none yet or the report has moved on. */
    public static function current() {
        $stored = get_option( self::OPTION, array() );
        $built  = (int) ( AceSeoRetentionReport::progress()['finished'] ?? 0 );
        if ( ! is_array( $stored ) || ! isset( $stored['suggestions'] ) || (int) ( $stored['computed'] ?? 0 ) < $built ) {
            return self::recompute();
        }
        return (array) $stored['suggestions'];
    }

    public static function computed_at() {
        $stored = get_option( self::OPTION, array() );
        return (int) ( $stored['computed'] ?? 0 );
    }

    public static function handle_recompute() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ace_seo_timing_suggest' ) ) {
            wp_die( 'Not allowed.' );
        }
        $n = count( self::recompute() );
        set_transient( 'ace_seo_retention_msg_' . get_current_user_id(), sprintf( 'Suggestions recalculated from the current assessment: %d categories or tags have a clear enough shape to suggest a rule.', $n ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-report' ) );
        exit;
    }

    /* ---- UI --------------------------------------------------------------------------------------- */

    /** The suggestions table, inside the Retention settings form (accept/ignore are saved with everything else). */
    public static function render_fields( array $options ) {
        $suggestions = self::current();
        $rules       = (array) ( $options['timing_rules'] ?? array() );
        $ignored     = (array) ( $options['timing_ignored'] ?? array() );
        $computed    = self::computed_at();
        ?>
        <div id="retention-timing-suggestions">
            <p><strong>Suggested from your data</strong><?php echo $computed ? ' · worked out ' . esc_html( human_time_diff( $computed ) ) . ' ago from the saved assessment' : ''; ?> · <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ace_seo_timing_suggest' ), 'ace_seo_timing_suggest' ) ); ?>">Recalculate now</a></p>
            <p class="description">For each category or tag with at least <?php echo (int) self::MIN_POSTS; ?> assessed posts, the plugin looks at how many of its older posts are still read, when its posts are published across the year, and how often it publishes, and proposes a rule. Tick to accept; accepted rules appear in the box above after saving and can be edited there. Ignored suggestions stay out of the way until you untick them. Suggestions are recalculated after every check.</p>
            <?php if ( ! $suggestions ) : ?>
                <p><em>No suggestions yet: there is no completed assessment, or no category or tag has a clear enough shape.</em></p>
            <?php else : ?>
            <input type="hidden" name="timing_suggestions_present" value="1">
            <div style="overflow-x:auto"><table class="widefat striped" style="max-width:1100px">
                <thead><tr><th scope="col">Category or tag</th><th scope="col">What we see</th><th scope="col">Suggested rule</th><th scope="col">Use it</th><th scope="col">Ignore</th></tr></thead>
                <tbody>
                <?php $i = 0; foreach ( $suggestions as $key => $s ) : $i++; $in_use = isset( $rules[ $key ] ); $is_ignored = in_array( $key, $ignored, true ); $same = $in_use && AceSeoRetentionActions::rule_to_text( $rules[ $key ] ) === $s['rule']; ?>
                    <tr<?php echo $is_ignored && ! $in_use ? ' style="opacity:.6"' : ''; ?><?php echo $i > 30 && ! $in_use ? ' class="ace-timing-more" hidden' : ''; ?>>
                        <th scope="row"><?php echo esc_html( $s['label'] ); ?><br><small><code><?php echo esc_html( $key ); ?></code> · <?php echo esc_html( number_format_i18n( $s['posts'] ) ); ?> posts</small></th>
                        <td><?php echo esc_html( $s['why'] ); ?><?php if ( 'mixed' !== $s['type'] ) : ?><br><small>Confidence <?php echo esc_html( (int) round( 100 * $s['confidence'] ) ); ?>%</small><?php endif; ?></td>
                        <?php if ( 'mixed' === $s['type'] ) : ?>
                        <td colspan="3"><em>No rule suggested.</em><?php if ( $in_use ) : ?> Set by hand: <code><?php echo esc_html( AceSeoRetentionActions::rule_to_text( $rules[ $key ] ) ); ?></code><?php endif; ?></td>
                        <?php else : ?>
                        <td><code><?php echo esc_html( $s['rule'] ); ?></code><?php if ( $in_use ) : ?><br><small><?php echo $same ? 'In use' : 'A different rule is set by hand: ' . esc_html( AceSeoRetentionActions::rule_to_text( $rules[ $key ] ) ); ?></small><?php endif; ?></td>
                        <td><input type="hidden" name="timing_suggested[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s['rule'] ); ?>"><label><input type="checkbox" name="timing_accept[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $same ); ?>> <?php echo $same ? 'Keep' : 'Accept'; ?></label></td>
                        <td><label><input type="checkbox" name="timing_ignore[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $is_ignored ); ?>> Ignore</label></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ( count( $suggestions ) > 30 ) : ?>
                <p><button type="button" class="button-link" onclick="document.querySelectorAll('.ace-timing-more').forEach(function(r){r.hidden=false;});this.hidden=true;">Show all <?php echo (int) count( $suggestions ); ?> suggestions (smaller categories and tags)</button></p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
