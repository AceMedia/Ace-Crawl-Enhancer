<?php
/**
 * Ace SEO Schema Graph — single JSON-LD @graph builder and provider registry.
 *
 * Every schema node on a page flows through here: the page-type nodes built by
 * AceSeoFrontend, the built-in providers registered by AceSeoSchema (LocalBusiness,
 * Product, FAQ), and any nodes registered by other plugins via
 * ace_seo_register_schema_provider() or the ace_seo_schema_graph filter.
 *
 * Nodes are de-duplicated by @id and stripped of per-node @context; the printer
 * emits one <script type="application/ld+json"> with a single @context + @graph.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AceSeoSchemaGraph {

    /** @var array<string, callable> Registered providers, keyed by id. */
    private static $providers = [];

    /**
     * Register a schema provider. Call on or before wp_head (init is ideal).
     *
     * The callback receives (array $context) and returns one node (associative
     * array with @type), a list of nodes, or null/[] to add nothing.
     *
     * @param string   $id       Unique provider id, e.g. 'my-plugin/event'.
     * @param callable $callback Node builder.
     */
    public static function register_provider($id, callable $callback) {
        self::$providers[$id] = $callback;
    }

    /**
     * Remove a registered provider (e.g. to replace a built-in).
     */
    public static function unregister_provider($id) {
        unset(self::$providers[$id]);
    }

    /**
     * Build the final graph: run providers, apply the public filter, de-dupe.
     *
     * @param array $nodes   Nodes already built for this page.
     * @param array $context Page context: queried_object, is_front_page, etc.
     * @return array Final list of nodes.
     */
    public static function build(array $nodes, array $context = []) {
        foreach (self::$providers as $id => $callback) {
            $result = call_user_func($callback, $context);
            if (empty($result)) {
                continue;
            }
            // A single node (has @type) or a list of nodes.
            if (isset($result['@type'])) {
                $result = [$result];
            }
            foreach ($result as $node) {
                if (is_array($node) && !empty($node['@type'])) {
                    $nodes[] = $node;
                }
            }
        }

        /**
         * Filter the complete schema graph for the current page.
         *
         * @param array $nodes   List of schema.org nodes (no @context on nodes).
         * @param array $context Page context array.
         */
        $nodes = apply_filters('ace_seo_schema_graph', $nodes, $context);

        return self::normalize($nodes);
    }

    /**
     * Strip per-node @context and de-duplicate nodes by @id (first one wins;
     * later duplicates are merged into it so providers can enrich core nodes).
     */
    private static function normalize(array $nodes) {
        $by_id = [];
        $output = [];

        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['@type'])) {
                continue;
            }
            unset($node['@context']);

            $id = isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : null;
            if ($id !== null && isset($by_id[$id])) {
                // Merge extra properties into the existing node without
                // overwriting anything it already declares.
                $output[$by_id[$id]] += $node;
                continue;
            }

            if ($id !== null) {
                $by_id[$id] = count($output);
            }
            $output[] = $node;
        }

        return array_values($output);
    }

    /**
     * Print the graph as a single JSON-LD script tag.
     */
    public static function render(array $graph) {
        if (empty($graph)) {
            return;
        }

        $output = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        $json = wp_json_encode($output, JSON_UNESCAPED_UNICODE);
        // Prevent breaking out of the script tag via </script> in values.
        $json = str_replace('</', '<\/', $json);

        echo '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
    }
}

if (!function_exists('ace_seo_register_schema_provider')) {
    /**
     * Public helper for other plugins to inject schema.org nodes into the
     * Ace SEO graph. See docs/schema-api.md in the plugin for examples.
     *
     * @param string   $id       Unique provider id, e.g. 'ace-community-events/event'.
     * @param callable $callback function( array $context ): array|null
     */
    function ace_seo_register_schema_provider($id, callable $callback) {
        AceSeoSchemaGraph::register_provider($id, $callback);
    }
}
