# Integration hooks

These filters and actions let site integrations customise output without editing the plugin. Keep callbacks narrow and test the rendered response. The source remains authoritative for all hooks and their current arguments.

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `acecrawl_singular_title` | title, post | Adjust a singular page's SEO title. |
| `acecrawl_singular_meta_description` | description, post | Adjust its meta description. |
| `acecrawl_singular_canonical` | URL, post | Adjust its canonical URL. |
| `acecrawl_canonical` | URL | Adjust the final canonical URL. |
| `acecrawl_force_noindex` | boolean | Add a noindex decision. |
| `acecrawl_robots_directives` | directive array | Adjust robots directives. |
| `acecrawl_breadcrumbs_markup` | HTML, items, attributes, block | Adjust rendered breadcrumbs. |
| `acecrawl_token_replacements` | replacement map, object | Add or change title template tokens. |
| `acecrawl_directory_post_types` | post-type list | Mark directory content types for the integration layer. |
| `acecrawl_emit_article` | boolean, post | Control article schema emission. |
| `acecrawl_schema_article` | schema array, post | Adjust article schema. |
| `acecrawl_sitemap_generation_published` | artifact key, metadata | React when a sitemap artifact is published. |

For example, a site integration plugin can replace the SEO title for a specific post type:

```php
add_filter( 'acecrawl_singular_title', function ( $title, $post ) {
    if ( $post instanceof WP_Post && 'event' === $post->post_type ) {
        return $title . ' | Events';
    }

    return $title;
}, 10, 2 );
```

Always escape output at the final rendering boundary. Check [Compatibility](Compatibility.md) before overriding canonical or robots behaviour, because themes and other SEO plugins may also influence those responses.
