=== Ace Crawl Enhancer ===
Contributors: acemedia
Tags: seo, search engine optimization, meta, social media, schema
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced SEO plugin with Yoast compatibility, modern interface, real-time analysis, and powerful optimization features.

== Description ==

**Ace Crawl Enhancer** is a modern, powerful SEO plugin with full compatibility with existing SEO data. Built with a sleek, intuitive interface and advanced features for serious SEO optimization.

### 🚀 Key Features

**Modern Interface**
* Clean, tabbed interface similar to Yoast but more modern
* Real-time SEO and readability analysis
* Live Google, Facebook, and Twitter previews
* Character counters with visual progress bars
* Mobile-responsive design

**Full Yoast Compatibility**
* Uses identical database structure (`_yoast_wpseo_*` meta keys)
* Seamless migration - no data loss
* Can replace Yoast SEO without any configuration
* Import/export compatibility

**Advanced SEO Features**
* Focus keyword optimization with real-time scoring
* SEO title and meta description optimization
* Canonical URL management
* Meta robots controls (noindex, nofollow, advanced)
* Breadcrumbs with structured data
* XML sitemaps generation

**Social Media Optimization**
* Open Graph (Facebook) optimization
* Twitter Cards optimization
* Social media image management
* Live social media previews
* Default fallback images

**Schema.org Structured Data**
* Automatic Article schema
* Organization/LocalBusiness schema
* Breadcrumb navigation schema
* FAQ schema detection
* WooCommerce Product schema (if WooCommerce is active)

**Content Analysis**
* Real-time SEO scoring
* Readability analysis
* Content recommendations
* Keyword density analysis
* Image alt text checking

### 🎯 Why Choose Ace SEO?

1. **Performance Focused**: Lightweight and optimized for speed
2. **User Experience**: Modern, intuitive interface that's easy to use
3. **Developer Friendly**: Clean code, hooks, and filters for customization
4. **Future Proof**: Regular updates and modern WordPress standards
5. **No Vendor Lock-in**: Compatible with Yoast data structure

### 🔧 Technical Features

* REST API integration for real-time analysis
* WordPress Block Editor (Gutenberg) integration
* Classic Editor support
* Multisite compatibility
* WPML ready
* Developer hooks and filters
* Clean uninstall process

### 📊 Admin Features

* SEO Dashboard with performance overview
* Bulk optimization tools
* Content analysis columns in post lists
* Settings import/export
* Site health integration

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ace-crawl-enhancer/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to 'Ace SEO' in your admin menu to configure settings
4. Start optimizing your content with the new meta boxes!

### Migrating from Yoast SEO

1. Keep Yoast SEO active initially
2. Install and activate Ace SEO
3. Verify all data is working correctly
4. Deactivate Yoast SEO (data remains intact)
5. Optionally delete Yoast SEO

== Frequently Asked Questions ==

= Is this compatible with Yoast SEO data? =

Yes! Ace SEO uses the exact same database structure as Yoast SEO. All your existing SEO titles, meta descriptions, focus keywords, and social media settings will work immediately without any migration needed.

= Can I run this alongside Yoast SEO? =

While technically possible, it's not recommended as both plugins will output SEO tags. You should deactivate Yoast SEO after confirming Ace SEO is working correctly.

= Does this work with WooCommerce? =

Yes! Ace SEO includes special WooCommerce integration with Product schema markup and e-commerce specific SEO features.

= Is this compatible with page builders? =

Yes! Ace SEO works with all major page builders including Elementor, Beaver Builder, Divi, and others. The content analysis adapts to your content regardless of how it's created.

= Does this generate XML sitemaps? =

Yes! Ace SEO includes automatic XML sitemap generation that's submitted to search engines and follows best practices.

= Is there an import/export feature? =

Yes! You can export your SEO settings and import them to other sites. Since we use Yoast's data structure, you can also import from Yoast backups.

== Screenshots ==

1. Modern SEO meta box with tabbed interface
2. Real-time SEO analysis and scoring
3. Live Google search preview
4. Social media optimization tabs
5. Advanced SEO settings
6. SEO dashboard overview
7. Plugin settings page

== Changelog ==

= 1.0.0 =
* Initial release
* Full Yoast SEO compatibility
* Modern tabbed interface
* Real-time SEO analysis
* Social media optimization
* Schema.org structured data
* XML sitemap generation
* REST API integration
* WordPress 6.8 compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release of Ace Crawl Enhancer - the modern Yoast SEO alternative.

== Technical Notes ==

### Database Structure

Ace SEO uses the following meta keys (identical to Yoast SEO):

* `_yoast_wpseo_title` - SEO title
* `_yoast_wpseo_metadesc` - Meta description  
* `_yoast_wpseo_focuskw` - Focus keyword
* `_yoast_wpseo_linkdex` - SEO score
* `_yoast_wpseo_content_score` - Content score
* `_yoast_wpseo_opengraph-title` - Facebook title
* `_yoast_wpseo_opengraph-description` - Facebook description
* `_yoast_wpseo_opengraph-image` - Facebook image
* `_yoast_wpseo_twitter-title` - Twitter title
* `_yoast_wpseo_twitter-description` - Twitter description
* `_yoast_wpseo_twitter-image` - Twitter image
* `_yoast_wpseo_canonical` - Canonical URL
* `_yoast_wpseo_meta-robots-noindex` - Noindex setting
* `_yoast_wpseo_meta-robots-nofollow` - Nofollow setting
* `_yoast_wpseo_meta-robots-adv` - Advanced robots
* `_yoast_wpseo_bctitle` - Breadcrumb title

### Developer Hooks

* `ace_seo_meta_fields` - Filter meta fields definition
* `ace_seo_schema_article` - Filter article schema
* `ace_seo_analysis_score` - Filter SEO analysis score
* `ace_seo_before_head_output` - Action before head output
* `ace_seo_after_head_output` - Action after head output

### REST API Endpoints

* `GET /wp-json/ace-seo/v1/analyze/{post_id}` - Get SEO analysis
* `GET /wp-json/ace-seo/v1/preview/{post_id}` - Get search preview

== Support ==

For support, feature requests, or bug reports, please visit our [GitHub repository](https://github.com/acemedia/ace-crawl-enhancer) or contact us through our website.

== Privacy ==

Ace SEO does not collect or transmit any personal data. All analysis is performed locally on your server. No external API calls are made for SEO analysis.
