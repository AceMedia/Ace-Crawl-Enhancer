# Ace SEO Schema API

Since 1.0.6 every JSON-LD node on a page flows through a single `@graph`, printed once in
`wp_head`. Other plugins inject typed nodes via a provider callback or the graph filter —
no need to print your own `<script type="application/ld+json">` blocks.

## Registering a provider

```php
add_action( 'init', function () {
    if ( ! function_exists( 'ace_seo_register_schema_provider' ) ) {
        return; // Ace SEO not active
    }

    ace_seo_register_schema_provider( 'my-plugin/event', function ( array $context ) {
        if ( ! is_singular( 'events' ) ) {
            return null; // add nothing on this page
        }

        $post_id = get_the_ID();

        return [
            '@type'     => 'Event',
            '@id'       => get_permalink( $post_id ) . '#event',
            'name'      => get_the_title( $post_id ),
            'startDate' => get_post_meta( $post_id, 'start_date', true ),
            'location'  => [
                '@type' => 'Place',
                'name'  => get_post_meta( $post_id, 'venue', true ),
            ],
        ];
    } );
} );
```

Provider rules:

- Return **one node** (array with `@type`), a **list of nodes**, or `null`/`[]` for nothing.
- Do **not** include `@context` — the graph adds one wrapper context; per-node contexts are stripped.
- Give every node an `@id`. Nodes sharing an `@id` are **merged**: the first node wins existing
  keys, later nodes add missing ones. That lets you *enrich* core nodes — e.g. add `foundingDate`
  to the site Organization by returning a node with `@id` `{home_url}/#organization`.
- Providers run at `wp_head` priority 20; register them on `init` or earlier.

## Well-known `@id`s

| Node | `@id` |
|---|---|
| Organization / Person (publisher) | `{home_url}/#organization` or `{home_url}/#person` |
| WebSite (front page) | `{home_url}/#website` |
| WebPage (every page) | `{current_url}#webpage` |
| BreadcrumbList | `{current_url}#webpage#breadcrumb` — reference via the WebPage's `breadcrumb` |
| Article author | `{author_url}#author` |
| LocalBusiness (front page) | `{home_url}/#localbusiness` |
| Product / FAQPage (singular) | `{permalink}#product` / `{permalink}#faq` |

The provider callback's `$context` array carries `is_front_page`, `is_singular`,
`queried_object`, `webpage_id` and `publisher_id`.

## Filters

- `ace_seo_schema_graph( array $nodes, array $context )` — the whole graph, last word before
  de-duplication. Use to remove or rewrite nodes.
- `ace_seo_schema_article( array $node, WP_Post $post )` — the Article node (word count,
  reading time, section and keywords are added here by the built-in enhancer).
- `ace_seo_article_schema_type( string $type, WP_Post $post )` — swap `Article` for
  `NewsArticle`, `BlogPosting`, etc. per post or post type.
- `ace_seo_product_schema_enabled( bool $enabled )` — Ace SEO's Product node on WooCommerce
  singles. Defaults to **off when WooCommerce core already emits Product JSON-LD**
  (`WC_Structured_Data` present); enable to let Ace SEO own it instead (then suppress Woo's).
- `ace_seo_faq_schema_enabled( bool $enabled, WP_Post $post )` — FAQPage extraction from
  question-style headings. Opt-in per post via the `_ace_seo_faq-schema` post meta or this
  filter (the heuristic is too loose to force on globally).
- `ace_seo_publisher_schema` / `ace_seo_publisher_person_schema` — the publisher node (existing).

## Verifying output

`php bin/schema-check.php <url> [<url>…]` fetches pages, extracts every JSON-LD block and
lists node types + warnings (duplicate blocks, per-node contexts, missing BreadcrumbList).
Run it against a consumer site before and after bumping the plugin submodule.
