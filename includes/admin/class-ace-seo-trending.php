<?php
/**
 * Weekly trending topics from Wikipedia pageviews, for content ideas.
 *
 * Every Monday (cron `ace_seo_trending_weekly`) each watched article's daily views
 * for the last 14 days are fetched from the Wikimedia REST API, this week is compared
 * with last, and the risers are ranked above a view floor. Titles are resolved
 * through the summary endpoint first, so redirects count on their canonical article
 * and disambiguation pages are dropped. Calls are sequential with a pause and a
 * descriptive User-Agent, and each title is cached for a day. The list of articles
 * is a per-site setting plus the `ace_seo_trending_topics` filter; this file names
 * no place. Shown as "Content ideas" on the Search digest page.
 */
if (!defined('ABSPATH')) exit;

class AceSeoTrending {

    const OPT        = 'ace_seo_trending_latest';
    const OPT_TOPICS = 'ace_seo_trending_topics';
    const CRON       = 'ace_seo_trending_weekly';
    const SUMMARY    = 'https://en.wikipedia.org/api/rest_v1/page/summary/%s';
    const VIEWS      = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user/%s/daily/%s/%s';
    const PAUSE_US   = 300000;
    const TTL        = DAY_IN_SECONDS;
    const MIN_VIEWS  = 200;


    /**
     * The articles to watch, as English Wikipedia titles in URL form. The list is a
     * setting (`ace_seo_trending_topics`, one per line) so each site names its own
     * places and people, and a filter so a plugin that knows the area can supply them.
     */
    public static function topics() {
        $raw = (string) get_option(self::OPT_TOPICS, '');
        $list = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw))));
        $list = (array) apply_filters('ace_seo_trending_topics', $list);
        return array_values(array_unique(array_filter(array_map(static function ($t) { return str_replace(' ', '_', trim((string) $t)); }, $list))));
    }

    /** Wikimedia asks for a descriptive agent with a way to reach the operator. */
    private static function ua() {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return sprintf('%s/1.0 (%s; trending topics for content planning)', preg_replace('/[^A-Za-z0-9]/', '', (string) $host) ?: 'AceSEO', home_url('/'));
    }

    public static function init() {
        add_action('init', [__CLASS__, 'schedule']);
        add_action('admin_post_ace_seo_trending_topics', [__CLASS__, 'admin_topics']);
        add_action(self::CRON, [__CLASS__, 'run']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON)) {
            $at = new DateTime('next monday 05:30', wp_timezone());
            wp_schedule_event($at->getTimestamp(), 'weekly', self::CRON);
        }
    }

    // ------------------------------------------------------------ fetch

    private static function get($url) {
        $r = wp_remote_get($url, ['timeout' => 15, 'user-agent' => self::ua(), 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($r)) return ['code' => 0, 'body' => null];
        $code = (int) wp_remote_retrieve_response_code($r);
        $body = json_decode((string) wp_remote_retrieve_body($r), true);
        return ['code' => $code, 'body' => is_array($body) ? $body : null];
    }

    /**
     * Resolve a title to its canonical article, or '' when it 404s or lands on a
     * disambiguation page. Cached 24 h.
     */
    public static function resolve($title) {
        $key = 'ace_wiki_t_' . md5($title);
        $c = get_transient($key);
        if ($c !== false) return (string) $c;
        $r = self::get(sprintf(self::SUMMARY, rawurlencode($title)));
        usleep(self::PAUSE_US);
        $out = '';
        if ($r['code'] === 200 && $r['body'] && ($r['body']['type'] ?? '') !== 'disambiguation') {
            $out = (string) ($r['body']['titles']['canonical'] ?? $title);
        }
        set_transient($key, $out, self::TTL);
        return $out;
    }

    /**
     * Daily user views for the last 14 full days, oldest first. Wikimedia lags a
     * day or two, so the window ends two days ago. Cached 24 h per title.
     */
    public static function views($canonical) {
        $end   = gmdate('Ymd', time() - 2 * DAY_IN_SECONDS);
        $start = gmdate('Ymd', time() - 15 * DAY_IN_SECONDS);
        $key = 'ace_wiki_v_' . md5($canonical . $end);
        $c = get_transient($key);
        if ($c !== false) return (array) $c;
        $r = self::get(sprintf(self::VIEWS, rawurlencode($canonical), $start, $end));
        usleep(self::PAUSE_US);
        $days = [];
        foreach ((array) ($r['body']['items'] ?? []) as $it) $days[] = (int) ($it['views'] ?? 0);
        if ($r['code'] === 200) set_transient($key, $days, self::TTL);
        return $days;
    }

    /** Compare this week with last week for one article's daily series. */
    public static function compare(array $days) {
        $n = count($days);
        $this_w = array_sum(array_slice($days, max(0, $n - 7)));
        $last_w = array_sum(array_slice($days, max(0, $n - 14), max(0, min(7, $n - 7))));
        $pct = $last_w > 0 ? (int) round(($this_w - $last_w) / $last_w * 100) : ($this_w ? 100 : 0);
        return ['this' => $this_w, 'last' => $last_w, 'pct' => $pct];
    }

    /** The weekly run: every title, sequentially, ranked by rise. Returns the stored rows. */
    public static function run() {
        $rows = []; $dropped = [];
        foreach (self::topics() as $t) {
            $canonical = self::resolve($t);
            if ($canonical === '') { $dropped[] = $t; continue; }
            $days = self::views($canonical);
            if (!$days) { $dropped[] = $t; continue; }
            $c = self::compare($days);
            $rows[] = [
                'title' => $canonical,
                'label' => str_replace('_', ' ', $canonical),
                'url'   => 'https://en.wikipedia.org/wiki/' . rawurlencode($canonical),
                'this'  => $c['this'],
                'last'  => $c['last'],
                'pct'   => $c['pct'],
                'days'  => $days,
            ];
        }
        $ranked = array_values(array_filter($rows, function ($r) { return $r['this'] >= self::MIN_VIEWS; }));
        usort($ranked, function ($a, $b) { return $b['pct'] <=> $a['pct'] ?: $b['this'] <=> $a['this']; });
        $below = array_values(array_filter($rows, function ($r) { return $r['this'] < self::MIN_VIEWS; }));
        update_option(self::OPT, [
            'built'   => time(),
            'rows'    => $ranked,
            'below'   => $below,
            'dropped' => $dropped,
            'floor'   => self::MIN_VIEWS,
        ], false);
        return $ranked;
    }

    // ------------------------------------------------------------ admin

    /** "Content ideas" section, rendered inside the Search digest page. */
    public static function render_section() {
        $e = 'esc_html';
        $d = get_option(self::OPT, []);
        $next = wp_next_scheduled(self::CRON);
        echo '<h2>Content ideas: trending topics on Wikipedia</h2>';
        echo '<p>Weekly on Monday 05:30 from Wikimedia pageviews (this week against last, articles under ' . (int) self::MIN_VIEWS . ' views this week are held back). Next run: ' . $e($next ? wp_date('D j M H:i', $next) : 'not scheduled') . '. ';
        echo 'Built: ' . $e(!empty($d['built']) ? wp_date('D j M H:i', $d['built']) : 'never') . '. ';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ace_seo_digest_run&trending=1'), 'ace_seo_digest_run')) . '">Rebuild with the digest</a>.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 1em">';
        wp_nonce_field('ace_seo_trending_topics');
        echo '<input type="hidden" name="action" value="ace_seo_trending_topics"><p><label>Articles to watch, one English Wikipedia title per line' . (has_filter('ace_seo_trending_topics') ? ' (a plugin adds more)' : '') . '<br><textarea name="topics" rows="6" cols="60" style="max-width:100%">' . $e((string) get_option(self::OPT_TOPICS, '')) . '</textarea></label></p><button class="button">Save topics</button></form>';
        if (!self::topics()) { echo '<p>No topics yet: add some above.</p>'; return; }
        if (empty($d['rows'])) {
            echo '<p>Nothing yet' . (!empty($d['dropped']) ? ' (dropped: ' . $e(implode(', ', $d['dropped'])) . ')' : '') . '.</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Article</th><th>This week</th><th>Last week</th><th>Rise</th><th>Draft</th></tr></thead><tbody>';
        foreach ($d['rows'] as $r) {
            $post = admin_url('post-new.php?post_title=' . rawurlencode((string) apply_filters('ace_seo_trending_post_title', $r['label'], $r)));
            echo '<tr><td><a href="' . esc_url($r['url']) . '" target="_blank" rel="noopener">' . $e($r['label']) . '</a></td>';
            echo '<td>' . (int) $r['this'] . '</td><td>' . (int) $r['last'] . '</td>';
            echo '<td>' . ($r['pct'] > 0 ? '+' : '') . (int) $r['pct'] . '%</td>';
            echo '<td><a href="' . esc_url($post) . '">Write a post</a></td></tr>';
        }
        echo '</tbody></table>';
        if (!empty($d['dropped'])) echo '<p>Dropped (no article or ambiguous): ' . $e(implode(', ', $d['dropped'])) . '</p>';
    }

    public static function admin_topics() {
        if (!current_user_can('manage_options')) wp_die('No.');
        check_admin_referer('ace_seo_trending_topics');
        update_option(self::OPT_TOPICS, sanitize_textarea_field(wp_unslash((string) ($_POST['topics'] ?? ''))), false);
        wp_safe_redirect(admin_url('admin.php?page=ace-seo-digest'));
        exit;
    }
}
