<?php
/**
 * Schema verification harness — run against live/staging URLs before and after
 * bumping the Ace-Crawl-Enhancer submodule on a consumer site.
 *
 *   php bin/schema-check.php https://example.com/ https://example.com/some-post/
 *
 * For each URL: lists every JSON-LD block, the node types inside it, and warns on
 * duplicate blocks, duplicate node types, per-node @context and a missing
 * BreadcrumbList on non-front pages. Exit code 1 if any page fails to parse.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$urls = array_slice($argv, 1);
if (empty($urls)) {
    fwrite(STDERR, "Usage: php bin/schema-check.php <url> [<url>...]\n");
    exit(1);
}

$had_error = false;

foreach ($urls as $url) {
    echo "== $url\n";

    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'ace-seo-schema-check/1.0', 'follow_location' => 1],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        echo "  ERROR: fetch failed\n";
        $had_error = true;
        continue;
    }

    preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $m);
    $blocks = $m[1];
    echo '  ld+json blocks: ' . count($blocks) . "\n";

    $all_types = [];
    foreach ($blocks as $i => $raw) {
        $data = json_decode(html_entity_decode(str_replace('<\/', '</', trim($raw))), true);
        if (!is_array($data)) {
            echo "  block[$i]: PARSE ERROR\n";
            $had_error = true;
            continue;
        }
        $nodes = isset($data['@graph']) ? $data['@graph'] : [$data];
        $types = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['@type'] ?? '?';
            $types[] = is_array($type) ? implode('+', $type) : $type;
            if ($i === 0 && isset($data['@graph']) && isset($node['@context'])) {
                echo "  WARN: per-node @context inside @graph (" . ($node['@type'] ?? '?') . ")\n";
            }
        }
        $all_types = array_merge($all_types, $types);
        echo "  block[$i]: " . implode(', ', $types) . "\n";
    }

    $dupes = array_filter(array_count_values($all_types), fn($c) => $c > 1);
    foreach ($dupes as $type => $count) {
        if (!in_array($type, ['ListItem', 'Question', 'Review', 'Person'], true)) {
            echo "  WARN: $type appears {$count}x across blocks\n";
        }
    }
    if (count($blocks) > 1) {
        echo "  WARN: multiple ld+json blocks (target: one @graph, plus any theme/plugin extras to fold in)\n";
    }
    $path = parse_url($url, PHP_URL_PATH);
    $is_front = ($path === null || $path === '' || $path === '/');
    if (!$is_front && !in_array('BreadcrumbList', $all_types, true)) {
        echo "  WARN: no BreadcrumbList on a non-front page\n";
    }
    echo "\n";
}

exit($had_error ? 1 : 0);
