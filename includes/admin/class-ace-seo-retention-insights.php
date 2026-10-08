<?php
/**
 * Dashboard insights drawn from the saved assessment: how groups and suggestions overlap, and how the
 * archive's timing looks (seasons, event-bound and evergreen sections, rule overlaps, when posts are
 * published across the year). Read-only and cached per check; nothing here changes a post.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ace_SEO_Retention_Insights {

    const CACHE = 'ace_seo_retention_insights';

    /** One cached bundle per completed check and rule set. */
    public static function data() {
        $p      = AceSeoRetentionReport::progress();
        $rules  = (array) ( AceSeoRetentionActions::options()['timing_rules'] ?? array() );
        $key    = md5( (int) ( $p['finished'] ?? 0 ) . '|' . wp_json_encode( $rules ) );
        $cached = get_transient( self::CACHE );
        if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key ) {
            return $cached;
        }
        $data = array(
            'key'     => $key,
            'matrix'  => self::matrix(),
            'terms'   => self::term_breakdown( $rules ),
            'overlap' => self::overlap( array_keys( $rules ) ),
            'months'  => self::months(),
        );
        set_transient( self::CACHE, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }

    /** Tier × suggestion counts from the saved rows. */
    public static function matrix() {
        global $wpdb;
        $case = array();
        foreach ( AceSeoRetentionReport::BUCKETS as $b ) {
            $case[] = $wpdb->prepare( 'WHEN r.meta_value LIKE %s THEN %s', '%' . $wpdb->esc_like( '"bucket";s:' . strlen( $b ) . ':"' . $b . '"' ) . '%', $b );
        }
        $sql  = "SELECT t.meta_value AS tier, CASE " . implode( ' ', $case ) . " ELSE 'other' END AS bucket, COUNT(*) AS n
                 FROM {$wpdb->postmeta} t JOIN {$wpdb->postmeta} r ON r.post_id = t.post_id AND r.meta_key = %s
                 WHERE t.meta_key = %s GROUP BY tier, bucket";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, AceSeoRetentionReport::META, AceSeoRetentionReport::META_TIER ), ARRAY_A );
        $out  = array();
        foreach ( (array) $rows as $r ) {
            $out[ $r['tier'] ][ $r['bucket'] ] = (int) $r['n'];
        }
        return $out;
    }

    /** For each term with a rule in use: posts assessed, and how many are read, held, or have no readers. */
    public static function term_breakdown( array $rules ) {
        global $wpdb;
        if ( ! $rules ) {
            return array();
        }
        $pairs = array();
        foreach ( array_keys( $rules ) as $k ) {
            list( $tax, $slug ) = array_pad( explode( ':', $k, 2 ), 2, '' );
            $pairs[] = $wpdb->prepare( '(tt.taxonomy = %s AND tm.slug = %s)', $tax, $slug );
        }
        $sql  = "SELECT tt.taxonomy, tm.slug, tm.name, t.meta_value AS tier, COUNT(*) AS n
                 FROM {$wpdb->postmeta} t
                 JOIN {$wpdb->term_relationships} tr ON tr.object_id = t.post_id
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id
                 WHERE t.meta_key = %s AND (" . implode( ' OR ', $pairs ) . ')
                 GROUP BY tt.taxonomy, tm.slug, tm.name, t.meta_value';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, AceSeoRetentionReport::META_TIER ), ARRAY_A );
        $out  = array();
        foreach ( (array) $rows as $r ) {
            $k = $r['taxonomy'] . ':' . $r['slug'];
            if ( ! isset( $out[ $k ] ) ) {
                $out[ $k ] = array( 'label' => $r['name'], 'rule' => $rules[ $k ] ?? null, 'posts' => 0, 'retained' => 0, 'unknown' => 0, 'quiet' => 0 );
            }
            $n = (int) $r['n'];
            $out[ $k ]['posts'] += $n;
            if ( 'retained' === $r['tier'] ) {
                $out[ $k ]['retained'] += $n;
            } elseif ( 'unknown' === $r['tier'] ) {
                $out[ $k ]['unknown'] += $n;
            } else {
                $out[ $k ]['quiet'] += $n;
            }
        }
        uasort( $out, static function ( $a, $b ) { return $b['posts'] <=> $a['posts']; } );
        return $out;
    }

    /** Assessed posts matched by more than one rule in use (the first matching term wins). */
    public static function overlap( array $keys ) {
        global $wpdb;
        if ( count( $keys ) < 2 ) {
            return array( 'posts' => 0, 'pairs' => array() );
        }
        $pairs = array();
        foreach ( $keys as $k ) {
            list( $tax, $slug ) = array_pad( explode( ':', $k, 2 ), 2, '' );
            $pairs[] = $wpdb->prepare( '(tt.taxonomy = %s AND tm.slug = %s)', $tax, $slug );
        }
        $sql  = "SELECT tr.object_id AS id, GROUP_CONCAT(CONCAT(tt.taxonomy, ':', tm.slug) ORDER BY tt.taxonomy, tm.slug SEPARATOR ' + ') AS combo, COUNT(*) AS c
                 FROM {$wpdb->postmeta} t
                 JOIN {$wpdb->term_relationships} tr ON tr.object_id = t.post_id
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id
                 WHERE t.meta_key = %s AND (" . implode( ' OR ', $pairs ) . ')
                 GROUP BY tr.object_id HAVING c > 1';
        $rows   = $wpdb->get_results( $wpdb->prepare( $sql, AceSeoRetentionReport::META_TIER ), ARRAY_A );
        $combos = array();
        foreach ( (array) $rows as $r ) {
            $combos[ $r['combo'] ] = ( $combos[ $r['combo'] ] ?? 0 ) + 1;
        }
        arsort( $combos );
        return array( 'posts' => count( (array) $rows ), 'pairs' => array_slice( $combos, 0, 8, true ) );
    }

    /** Assessed posts by publication year and month. */
    public static function months() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT YEAR(p.post_date) AS y, MONTH(p.post_date) AS m, COUNT(*) AS n
             FROM {$wpdb->postmeta} t JOIN {$wpdb->posts} p ON p.ID = t.post_id
             WHERE t.meta_key = %s AND p.post_date >= '1995-01-01'
             GROUP BY y, m",
            AceSeoRetentionReport::META_TIER
        ), ARRAY_A );
        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r['y'] ][ (int) $r['m'] ] = (int) $r['n'];
        }
        ksort( $out );
        return $out;
    }

    /* ---- UI ---------------------------------------------------------------------------------------- */

    public static function render() {
        $d = self::data();
        if ( ! $d['matrix'] ) {
            return;
        }
        self::render_matrix( $d['matrix'] );
        self::render_timing( $d );
    }

    private static function render_matrix( array $m ) {
        $tiers   = AceSeoRetentionReport::tier_labels();
        $buckets = AceSeoRetentionReport::recommendation_labels();
        $col     = array();
        foreach ( $m as $row ) {
            foreach ( $row as $b => $n ) {
                $col[ $b ] = ( $col[ $b ] ?? 0 ) + $n;
            }
        }
        $max = 0;
        foreach ( $m as $row ) {
            $max = max( $max, max( $row ) );
        }
        $posts = admin_url( 'edit.php?post_type=post' );
        ?>
        <section class="ace-retention-section ace-retention-insight" id="retention-matrix">
            <h2>How groups and suggestions overlap</h2>
            <p class="ace-section-lead">Each cell counts posts from the last check with that readership group and that suggestion. A post can be read every week and still be worth an update; the two describe different things. Darker cells hold more posts. Click a group to open those posts.</p>
            <div class="ace-retention-table">
            <table class="widefat ace-matrix">
                <thead><tr><th scope="col">Readership group</th><?php foreach ( $buckets as $b => $bl ) : if ( empty( $col[ $b ] ) ) { continue; } ?><th scope="col"><?php echo esc_html( $bl ); ?></th><?php endforeach; ?><th scope="col">Total</th></tr></thead>
                <tbody>
                <?php foreach ( $tiers as $t => $tl ) : if ( empty( $m[ $t ] ) ) { continue; } $total = array_sum( $m[ $t ] ); ?>
                    <tr>
                        <th scope="row"><a href="<?php echo esc_url( add_query_arg( 'ace_ret', $t, $posts ) ); ?>"><?php echo esc_html( $tl ); ?></a></th>
                        <?php foreach ( $buckets as $b => $bl ) : if ( empty( $col[ $b ] ) ) { continue; } $n = (int) ( $m[ $t ][ $b ] ?? 0 ); $a = $max ? round( 0.08 + 0.6 * $n / $max, 2 ) : 0; ?>
                            <td class="ace-matrix-cell<?php echo $n ? '' : ' is-empty'; ?>" style="<?php echo $n ? 'background:rgba(34,113,177,' . esc_attr( $a ) . ');' . ( $a > 0.45 ? 'color:#fff;' : '' ) : ''; ?>"><?php echo $n ? esc_html( number_format_i18n( $n ) ) : '–'; ?></td>
                        <?php endforeach; ?>
                        <td><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="description">The <a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-retention&evidence_preview=1' ) ); ?>">reference guide</a> explains which suggestions suit each group in general, without counts.</p>
        </section>
        <?php
    }

    private static function render_timing( array $d ) {
        $suggestions = class_exists( 'Ace_SEO_Timing_Suggestions' ) ? Ace_SEO_Timing_Suggestions::current() : array();
        $seasons     = array();
        $events      = array();
        $evergreen   = array();
        // Rules in use first (with live counts), then the strongest seasonal suggestions not yet in use.
        foreach ( $d['terms'] as $k => $t ) {
            $r = $t['rule'];
            if ( ! $r ) {
                continue;
            }
            $row = array( 'key' => $k, 'label' => $t['label'], 'posts' => $t['posts'], 'retained' => $t['retained'], 'unknown' => $t['unknown'], 'quiet' => $t['quiet'], 'in_use' => true, 'rule' => $r );
            if ( 'season' === $r['type'] ) {
                $seasons[ $k ] = $row;
            } elseif ( 'event' === $r['type'] ) {
                $events[ $k ] = $row;
            } else {
                $evergreen[ $k ] = $row;
            }
        }
        foreach ( $suggestions as $k => $s ) {
            if ( 'season' !== $s['type'] || isset( $seasons[ $k ] ) || count( $seasons ) >= 12 ) {
                continue;
            }
            $parsed = AceSeoRetentionActions::parse_timing_rules( $k . ' = ' . $s['rule'] );
            $seasons[ $k ] = array( 'key' => $k, 'label' => $s['label'], 'posts' => $s['posts'], 'in_use' => false, 'rule' => $parsed[ $k ] ?? null, 'confidence' => $s['confidence'] );
        }
        $months = array( 'J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D' );
        $today  = (int) gmdate( 'z' );
        ?>
        <section class="ace-retention-section ace-retention-insight" id="retention-timing">
            <h2>Seasons and events</h2>
            <p class="ace-section-lead">When the archive's sections matter across the year, from the timing rules in use and the strongest seasonal suggestions from this site's own data. <a href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-settings#retention/retention-report' ) ); ?>">Manage timing rules</a>.</p>

            <h3>Yearly seasons</h3>
            <?php if ( ! $seasons ) : ?><p class="description">No seasonal rules or suggestions yet.</p><?php else : ?>
            <div class="ace-season-strip-head"><span></span><div class="ace-season-months"><?php foreach ( $months as $mo ) : ?><span><?php echo esc_html( $mo ); ?></span><?php endforeach; ?></div><span></span></div>
            <?php foreach ( $seasons as $k => $s ) : if ( empty( $s['rule'] ) ) { continue; }
                $a = (int) gmdate( 'z', strtotime( '2024-' . $s['rule']['start'] . ' UTC' ) ) / 366 * 100;
                $b = (int) gmdate( 'z', strtotime( '2024-' . $s['rule']['end'] . ' UTC' ) ) / 366 * 100;
                ?>
                <div class="ace-season-row">
                    <span class="ace-season-label"><strong><?php echo esc_html( $s['label'] ); ?></strong><br><small><?php echo esc_html( number_format_i18n( $s['posts'] ) ); ?> posts · <?php echo $s['in_use'] ? 'rule in use' : 'suggested, ' . esc_html( (int) round( 100 * $s['confidence'] ) ) . '%'; ?></small></span>
                    <div class="ace-season-track" title="<?php echo esc_attr( Ace_SEO_Timing_Suggestions::md_label( $s['rule']['start'] ) . ' to ' . Ace_SEO_Timing_Suggestions::md_label( $s['rule']['end'] ) ); ?>">
                        <?php if ( $b >= $a ) : ?>
                            <span class="ace-season-band<?php echo $s['in_use'] ? ' is-used' : ''; ?>" style="left:<?php echo esc_attr( $a ); ?>%;width:<?php echo esc_attr( max( 1, $b - $a ) ); ?>%"></span>
                        <?php else : ?>
                            <span class="ace-season-band<?php echo $s['in_use'] ? ' is-used' : ''; ?>" style="left:<?php echo esc_attr( $a ); ?>%;width:<?php echo esc_attr( 100 - $a ); ?>%"></span>
                            <span class="ace-season-band<?php echo $s['in_use'] ? ' is-used' : ''; ?>" style="left:0;width:<?php echo esc_attr( max( 1, $b ) ); ?>%"></span>
                        <?php endif; ?>
                        <span class="ace-season-today" style="left:<?php echo esc_attr( $today / 366 * 100 ); ?>%"></span>
                    </div>
                    <span class="ace-season-split"><?php if ( $s['in_use'] ) : ?><?php echo esc_html( number_format_i18n( $s['retained'] ) ); ?> read · <?php echo esc_html( number_format_i18n( $s['unknown'] ) ); ?> held<?php endif; ?></span>
                </div>
            <?php endforeach; ?>
            <p class="description">The blue line is today. Solid bands are rules in use; pale bands are suggestions. Posts in a season are only judged on a period that contains a whole season.</p>
            <?php endif; ?>

            <div class="ace-timing-columns">
                <div>
                    <h3>Event-bound sections</h3>
                    <?php if ( ! $events ) : ?><p class="description">No event-bound rules in use.</p><?php else : ?>
                    <table class="widefat striped"><thead><tr><th>Section</th><th>Matters for</th><th>Posts</th><th>Read</th></tr></thead><tbody>
                    <?php foreach ( $events as $e ) : ?>
                        <tr><td><?php echo esc_html( $e['label'] ); ?></td><td><?php echo esc_html( (int) $e['rule']['days'] ); ?> day<?php echo 1 === (int) $e['rule']['days'] ? '' : 's'; ?></td><td><?php echo esc_html( number_format_i18n( $e['posts'] ) ); ?></td><td><?php echo esc_html( $e['posts'] ? round( 100 * $e['retained'] / $e['posts'] ) : 0 ); ?>%</td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <?php endif; ?>
                </div>
                <div>
                    <h3>Evergreen sections</h3>
                    <?php if ( ! $evergreen ) : ?><p class="description">No evergreen rules in use.</p><?php else : ?>
                    <table class="widefat striped"><thead><tr><th>Section</th><th>Posts</th><th>Read</th></tr></thead><tbody>
                    <?php foreach ( $evergreen as $e ) : ?>
                        <tr><td><?php echo esc_html( $e['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( $e['posts'] ) ); ?></td><td><?php echo esc_html( $e['posts'] ? round( 100 * $e['retained'] / $e['posts'] ) : 0 ); ?>%</td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <?php endif; ?>
                </div>
            </div>

            <h3>Overlaps</h3>
            <?php if ( ! $d['overlap']['posts'] ) : ?>
                <p class="description"><?php echo count( $d['terms'] ) > 1 ? 'No post is covered by more than one rule.' : 'Overlaps appear once more than one rule is in use.'; ?></p>
            <?php else : ?>
                <p><strong><?php echo esc_html( number_format_i18n( $d['overlap']['posts'] ) ); ?></strong> posts are covered by more than one rule. A setting on the post wins; otherwise the most specific category or tag decides (the one with fewest posts), so a festival tag outranks the whole section it sits in. The commonest combinations:</p>
                <ul class="ace-overlap-list"><?php foreach ( $d['overlap']['pairs'] as $combo => $n ) : ?><li><code><?php echo esc_html( $combo ); ?></code> <span><?php echo esc_html( number_format_i18n( $n ) ); ?></span></li><?php endforeach; ?></ul>
            <?php endif; ?>

            <h3>When older posts were published</h3>
            <?php if ( $d['months'] ) : $peak = 0; foreach ( $d['months'] as $ym ) { $peak = max( $peak, max( $ym ) ); } ?>
            <div class="ace-retention-table">
            <table class="ace-heatmap"><thead><tr><th></th><?php foreach ( array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ) as $mo ) : ?><th><?php echo esc_html( $mo ); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ( $d['months'] as $y => $ym ) : ?>
                <tr><th><?php echo (int) $y; ?></th><?php for ( $mo = 1; $mo <= 12; $mo++ ) : $n = (int) ( $ym[ $mo ] ?? 0 ); $a = $peak ? round( 0.06 + 0.8 * $n / $peak, 2 ) : 0; ?><td title="<?php echo esc_attr( number_format_i18n( $n ) . ' posts' ); ?>" style="<?php echo $n ? 'background:rgba(0,163,42,' . esc_attr( $a ) . ')' : ''; ?>"><?php echo $n ? esc_html( $n >= 1000 ? round( $n / 1000, 1 ) . 'k' : $n ) : ''; ?></td><?php endfor; ?></tr>
            <?php endforeach; ?>
            </tbody></table>
            </div>
            <p class="description">Assessed posts by month of publication. Columns that stay dark year after year are the archive's seasons.</p>
            <?php endif; ?>
        </section>
        <?php
    }
}
