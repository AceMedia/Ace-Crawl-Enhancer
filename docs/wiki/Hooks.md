# Integration hooks

These filters and actions let site integrations customise output without editing the plugin. Keep callbacks narrow and test the rendered response. The source remains authoritative for all hooks and their current arguments.

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `ace_seo_singular_title` | title, post | Adjust a singular page's SEO title. |
| `ace_seo_singular_meta_description` | description, post | Adjust its meta description. |
| `ace_seo_singular_canonical` | URL, post | Adjust its canonical URL. |
| `ace_seo_canonical` | URL | Adjust the final canonical URL. |
| `ace_seo_force_noindex` | boolean | Add a noindex decision. |
| `ace_seo_robots_directives` | directive array | Adjust robots directives. |
| `ace_seo_breadcrumbs_markup` | HTML, items, attributes, block | Adjust rendered breadcrumbs. |
| `ace_seo_token_replacements` | replacement map, object | Add or change title template tokens. |
| `ace_seo_directory_post_types` | post-type list | Mark directory content types for the integration layer. |
| `ace_seo_emit_article` | boolean, post | Control article schema emission. |
| `ace_seo_schema_article` | schema array, post | Adjust article schema. |
| `ace_sitemap_generation_published` | artifact key, metadata | React when a sitemap artifact is published. |

For example, a site integration plugin can replace the SEO title for a specific post type:

```php
add_filter( 'ace_seo_singular_title', function ( $title, $post ) {
    if ( $post instanceof WP_Post && 'event' === $post->post_type ) {
        return $title . ' | Events';
    }

    return $title;
}, 10, 2 );
```

Always escape output at the final rendering boundary. Check [Compatibility](Compatibility.md) before overriding canonical or robots behaviour, because themes and other SEO plugins may also influence those responses.
