<?php
/**
 * Nightly Search Console digest.
 *
 * Once a day (cron `ace_seo_digest`, 04:20 site time) this pulls from Search Console
 * through the Site Kit bridge: site totals for the last 7 days against the 7 before,
 * the top pages and queries by impressions, and the pages whose clicks moved most.
 * Stored in `ace_seo_digest_latest` with a 12-week history in `ace_seo_digest_history`,
 * shown at Ace SEO > Search digest, and emailed to the admin address in plain text
 * when `ace_seo_digest_email` is on (off by default). Nothing here knows which site
 * it is on: the site name comes from the blog name and the property from Site Kit.
 *
 * WP-CLI: `wp eval 'AceSeoDigest::run();'`
 */
if (!defined('ABSPATH')) exit;

class AceSeoDigest {

    const OPT_LATEST  = 'ace_seo_digest_latest';
    const OPT_HISTORY = 'ace_seo_digest_history';
    const OPT_EMAIL   = 'ace_seo_digest_email';
    const CRON        = 'ace_seo_digest';
    const SLUG        = 'ace-seo-digest';
    const TOP         = 30;
    const MOVERS      = 10;
    const WEEKS       = 12;

    public static function init() {
        add_action('init', [__CLASS__, 'schedule']);
        add_action(self::CRON, [__CLASS__, 'run']);
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_ace_seo_digest_run', [__CLASS__, 'admin_run']);
        add_action('admin_post_ace_seo_digest_email', [__CLASS__, 'admin_email_toggle']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON)) {
            $at = new DateTime('tomorrow 04:20', wp_timezone());
            wp_schedule_event($at->getTimestamp(), 'daily', self::CRON);
        }
    }

    // ------------------------------------------------------------ fetch

    /** True when Site Kit has a Search Console property to read. */
    public static function ready() {
        return class_exists('AceSEOSearchConsole') && AceSEOSearchConsole::is_ready();
    }

    /** Site totals for one inclusive date range. */
    private static function totals($start, $end) {
        $r = AceSEOSearchConsole::report(['startDate' => $start, 'endDate' => $end, 'rowLimit' => 1]);
        if (is_wp_error($r)) return $r;
        $row = $r['rows'][0] ?? [];
        return [
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr'         => round((float) ($row['ctr'] ?? 0) * 100, 1),
            'position'    => round((float) ($row['position'] ?? 0), 1),
        ];
    }

    /**
     * Build the digest. Returns the array that was stored (with an `error` key
     * when Search Console could not be read).
     */
    public static function run() {
        $end        = gmdate('Y-m-d', strtotime('-1 day'));
        $start      = gmdate('Y-m-d', strtotime('-7 days'));
        $prev_end   = gmdate('Y-m-d', strtotime('-8 days'));
        $prev_start = gmdate('Y-m-d', strtotime('-14 days'));

        $digest = [
            'built'  => time(),
            'range'  => [$start, $end],
            'prev'   => [$prev_start, $prev_end],
            'totals' => null,
            'totals_prev' => null,
            'pages'  => [],
            'queries' => [],
            'movers' => ['up' => [], 'down' => []],
            'error'  => '',
        ];

        if (!self::ready()) {
            $digest['error'] = 'Search Console is not connected in Site Kit (no property).';
            update_option(self::OPT_LATEST, $digest, false);
            return $digest;
        }

        $steps = [
            'totals'      => function () use ($start, $end) { return self::totals($start, $end); },
            'totals_prev' => function () use ($prev_start, $prev_end) { return self::totals($prev_start, $prev_end); },
            'pages'       => function () use ($start, $end) { return AceSEOSearchConsole::top_rows('page', $start, $end, self::TOP); },
            'queries'     => function () use ($start, $end) { return AceSEOSearchConsole::top_rows('query', $start, $end, self::TOP); },
        ];
        foreach ($steps as $k => $fn) {
            $v = $fn();
            if (is_wp_error($v)) {
                $digest['error'] = $k . ': ' . $v->get_error_message();
                update_option(self::OPT_LATEST, $digest, false);
                return $digest;
            }
            $digest[$k] = $v;
        }

        // Movers: clicks per page this week against last week over a wider net than the top 30.
        $this_w = AceSEOSearchConsole::top_rows('page', $start, $end, 250);
        $last_w = AceSEOSearchConsole::top_rows('page', $prev_start, $prev_end, 250);
        if (!is_wp_error($this_w) && !is_wp_error($last_w)) {
            $digest['movers'] = self::movers($this_w, $last_w);
        } else {
            $e = is_wp_error($this_w) ? $this_w : $last_w;
            $digest['error'] = 'movers: ' . $e->get_error_message();
        }

        update_option(self::OPT_LATEST, $digest, false);
        self::remember($digest);
        self::maybe_email($digest);
        return $digest;
    }

    /** Pages with the largest absolute click change, split into risers and fallers. */
    public static function movers(array $this_w, array $last_w) {
        $now = []; $then = [];
        foreach ($this_w as $r) $now[$r['key']] = $r;
        foreach ($last_w as $r) $then[$r['key']] = $r;
        $rows = [];
        foreach (array_unique(array_merge(array_keys($now), array_keys($then))) as $url) {
            $a = (int) ($then[$url]['clicks'] ?? 0);
            $b = (int) ($now[$url]['clicks'] ?? 0);
            if ($a === $b) continue;
            $rows[] = [
                'page' => $url,
                'clicks_prev' => $a,
                'clicks' => $b,
                'delta' => $b - $a,
                'impressions' => (int) ($now[$url]['impressions'] ?? 0),
                'position' => (float) ($now[$url]['position'] ?? 0),
            ];
        }
        usort($rows, function ($x, $y) { return $y['delta'] <=> $x['delta']; });
        $up = array_values(array_filter($rows, function ($r) { return $r['delta'] > 0; }));
        $down = array_reverse(array_values(array_filter($rows, function ($r) { return $r['delta'] < 0; })));
        return ['up' => array_slice($up, 0, self::MOVERS), 'down' => array_slice($down, 0, self::MOVERS)];
    }

    /** Keep one compact snapshot per ISO week, twelve weeks deep. */
    private static function remember(array $d) {
        $h = get_option(self::OPT_HISTORY, []);
        if (!is_array($h)) $h = [];
        $week = wp_date('o-\WW', $d['built']);
        $h[$week] = [
            'built'   => $d['built'],
            'range'   => $d['range'],
            'totals'  => $d['totals'],
            'pages'   => array_slice((array) $d['pages'], 0, 10),
            'queries' => array_slice((array) $d['queries'], 0, 10),
        ];
        krsort($h);
        update_option(self::OPT_HISTORY, array_slice($h, 0, self::WEEKS, true), false);
    }

    // ------------------------------------------------------------ email

    private static function pct($now, $prev) {
        if ($prev <= 0) return $now > 0 ? '+100%' : '0%';
        $p = round(($now - $prev) / $prev * 100);
        return ($p > 0 ? '+' : '') . $p . '%';
    }

    /** Plain-text body shared by the email and a "copy" view. */
    public static function text(array $d) {
        $t = $d['totals']; $p = $d['totals_prev'];
        $l = ['Search Console, ' . $d['range'][0] . ' to ' . $d['range'][1] . ' (previous week in brackets)', ''];
        if ($d['error']) $l[] = 'Error: ' . $d['error'];
        if ($t) {
            $l[] = sprintf('Clicks       %6d  (%d, %s)', $t['clicks'], $p['clicks'], self::pct($t['clicks'], $p['clicks']));
            $l[] = sprintf('Impressions  %6d  (%d, %s)', $t['impressions'], $p['impressions'], self::pct($t['impressions'], $p['impressions']));
            $l[] = sprintf('CTR          %5.1f%%  (%.1f%%)', $t['ctr'], $p['ctr']);
            $l[] = sprintf('Position     %6.1f  (%.1f)', $t['position'], $p['position']);
        }
        foreach (['up' => 'Risers (clicks vs last week)', 'down' => 'Fallers (clicks vs last week)'] as $k => $head) {
            if (empty($d['movers'][$k])) continue;
            $l[] = ''; $l[] = $head;
            foreach ($d['movers'][$k] as $r) $l[] = sprintf('  %+4d  %d -> %d  %s', $r['delta'], $r['clicks_prev'], $r['clicks'], $r['page']);
        }
        foreach (['pages' => 'Top pages by impressions', 'queries' => 'Top queries by impressions'] as $k => $head) {
            if (empty($d[$k])) continue;
            $l[] = ''; $l[] = $head;
            foreach (array_slice($d[$k], 0, 15) as $r) $l[] = sprintf('  %6d imp  %4d clk  %4.1f%%  pos %4.1f  %s', $r['impressions'], $r['clicks'], $r['ctr'], $r['position'], $r['key']);
        }
        $l[] = '';
        $l[] = 'Full tables: ' . admin_url('admin.php?page=' . self::SLUG);
        return implode("\n", $l);
    }

    private static function maybe_email(array $d) {
        if (!get_option(self::OPT_EMAIL)) return false;
        $t = $d['totals'];
        $subject = sprintf('[%s] Search digest: %d clicks, %d impressions (%s)', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $t['clicks'] ?? 0, $t['impressions'] ?? 0, $t ? self::pct($t['clicks'], $d['totals_prev']['clicks']) : 'no data');
        return wp_mail(get_option('admin_email'), $subject, self::text($d));
    }

    // ------------------------------------------------------------ admin

    public static function menu() {
        add_submenu_page('ace-seo', 'Search digest', 'Search digest', 'manage_options', self::SLUG, [__CLASS__, 'render_admin']);
    }

    public static function admin_run() {
        if (!current_user_can('manage_options')) wp_die('No.');
        check_admin_referer('ace_seo_digest_run');
        self::run();
        if (class_exists('AceSeoTrending') && isset($_GET['trending'])) AceSeoTrending::run();
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&ran=1'));
        exit;
    }

    public static function admin_email_toggle() {
        if (!current_user_can('manage_options')) wp_die('No.');
        check_admin_referer('ace_seo_digest_email');
        update_option(self::OPT_EMAIL, empty($_POST['on']) ? 0 : 1, false);
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private static function table(array $rows, array $cols) {
        $e = 'esc_html';
        echo '<table class="widefat striped" style="max-width:1100px;margin-bottom:1.5em"><thead><tr>';
        foreach ($cols as $label) echo '<th>' . $e($label) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($cols as $key => $label) {
                $v = $r[$key] ?? '';
                if (in_array($key, ['page', 'key'], true) && preg_match('#^https?://#', (string) $v)) {
                    echo '<td><a href="' . esc_url($v) . '" target="_blank" rel="noopener">' . $e(preg_replace('#^https?://[^/]+#', '', $v) ?: '/') . '</a></td>';
                } else {
                    echo '<td>' . $e(is_float($v) ? number_format($v, 1) : (string) $v) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        $e = 'esc_html';
        $d = get_option(self::OPT_LATEST, []);
        $h = get_option(self::OPT_HISTORY, []);
        echo '<div class="wrap"><h1>Search digest</h1>';
        if (!empty($_GET['ran'])) echo '<div class="notice notice-success"><p>Digest rebuilt.</p></div>';

        $next = wp_next_scheduled(self::CRON);
        echo '<p>Nightly at 04:20 from Search Console. Next run: ' . $e($next ? wp_date('D j M H:i', $next) : 'not scheduled') . '. ';
        echo 'Built: ' . $e(!empty($d['built']) ? wp_date('D j M H:i', $d['built']) : 'never') . '.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:1em">';
        wp_nonce_field('ace_seo_digest_run');
        echo '<input type="hidden" name="action" value="ace_seo_digest_run"><button class="button">Rebuild now</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
        wp_nonce_field('ace_seo_digest_email');
        echo '<input type="hidden" name="action" value="ace_seo_digest_email"><label><input type="checkbox" name="on" value="1"' . checked((bool) get_option(self::OPT_EMAIL), true, false) . '> Email this digest to ' . $e(get_option('admin_email')) . ' each night</label> <button class="button">Save</button></form>';

        if (!$d) {
            echo '<p>No digest yet. The first one lands tonight, or press Rebuild now.</p>';
        } else {
            if (!empty($d['error'])) echo '<div class="notice notice-error"><p>' . $e($d['error']) . '</p></div>';
            if (!empty($d['totals'])) {
                $t = $d['totals']; $p = $d['totals_prev'];
                echo '<h2>Week ' . $e($d['range'][0]) . ' to ' . $e($d['range'][1]) . '</h2>';
                self::table([
                    ['metric' => 'Clicks', 'now' => $t['clicks'], 'prev' => $p['clicks'], 'change' => self::pct($t['clicks'], $p['clicks'])],
                    ['metric' => 'Impressions', 'now' => $t['impressions'], 'prev' => $p['impressions'], 'change' => self::pct($t['impressions'], $p['impressions'])],
                    ['metric' => 'CTR (%)', 'now' => $t['ctr'], 'prev' => $p['ctr'], 'change' => ''],
                    ['metric' => 'Avg position', 'now' => $t['position'], 'prev' => $p['position'], 'change' => ''],
                ], ['metric' => 'Metric', 'now' => 'This week', 'prev' => 'Previous week', 'change' => 'Change']);
            }
            $mv = ['page' => 'Page', 'clicks_prev' => 'Last week', 'clicks' => 'This week', 'delta' => 'Change', 'impressions' => 'Impressions', 'position' => 'Position'];
            if (!empty($d['movers']['up'])) { echo '<h2>Risers (clicks)</h2>'; self::table($d['movers']['up'], $mv); }
            if (!empty($d['movers']['down'])) { echo '<h2>Fallers (clicks)</h2>'; self::table($d['movers']['down'], $mv); }
            $tc = ['key' => 'Page', 'impressions' => 'Impressions', 'clicks' => 'Clicks', 'ctr' => 'CTR (%)', 'position' => 'Position'];
            if (!empty($d['pages'])) { echo '<h2>Top ' . count($d['pages']) . ' pages by impressions</h2>'; self::table($d['pages'], $tc); }
            $tc['key'] = 'Query';
            if (!empty($d['queries'])) { echo '<h2>Top ' . count($d['queries']) . ' queries by impressions</h2>'; self::table($d['queries'], $tc); }
        }

        if ($h) {
            echo '<h2>Last ' . count($h) . ' weeks</h2>';
            $rows = [];
            foreach ($h as $week => $s) {
                $rows[] = ['week' => $week, 'range' => $s['range'][0] . ' to ' . $s['range'][1], 'clicks' => $s['totals']['clicks'] ?? '', 'impressions' => $s['totals']['impressions'] ?? '', 'ctr' => $s['totals']['ctr'] ?? '', 'position' => $s['totals']['position'] ?? '', 'top' => $s['queries'][0]['key'] ?? ''];
            }
            self::table($rows, ['week' => 'Week', 'range' => 'Range', 'clicks' => 'Clicks', 'impressions' => 'Impressions', 'ctr' => 'CTR (%)', 'position' => 'Position', 'top' => 'Top query']);
        }

        if (class_exists('AceSeoTrending')) AceSeoTrending::render_section();
        echo '</div>';
    }
}


