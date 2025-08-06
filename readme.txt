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

**Ace Crawl Enhancer** is a modern, powerful SEO plugin with seamless Yoast SEO migration support. Built with a sleek, intuitive interface and advanced features for serious SEO optimization.

### 🚀 Key Features

**Modern Interface**
* Clean, tabbed interface with modern design
* Real-time SEO and readability analysis
* Live Google, Facebook, and Twitter previews
* Character counters with visual progress bars
* Mobile-responsive design

**Seamless Yoast SEO Migration**
* Automatic migration of existing Yoast SEO data
* One-click bulk migration tool
* Preserves all your existing SEO titles, descriptions, and settings
* Can safely replace Yoast SEO without data loss
* Uses plugin-specific database fields (`_ace_seo_*`) for future-proofing

**Advanced SEO Features**
* Focus keyword optimization with real-time scoring
* SEO title and meta description optimization with AI assistance
* Canonical URL management
* Meta robots controls (noindex, nofollow, advanced)
* Breadcrumbs with structured data
* XML sitemaps generation
* PageSpeed integration with Core Web Vitals monitoring

**Social Media Optimization**
* Open Graph (Facebook) optimization with live previews
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

**AI-Powered Features**
* OpenAI integration for content suggestions
* AI-generated SEO titles and meta descriptions
* Content analysis with AI recommendations
* Web search integration for trend analysis
* Smart keyword suggestions

**Content Analysis**
* Real-time SEO scoring with performance metrics
* Readability analysis
* Content recommendations
* Keyword density analysis
* Image alt text checking
* PageSpeed performance impact on SEO

### 🎯 Why Choose Ace SEO?

1. **Performance Focused**: Lightweight and optimized for speed with PageSpeed integration
2. **User Experience**: Modern, intuitive interface that's easy to use
3. **AI-Enhanced**: Advanced AI features for content optimization and suggestions
4. **Developer Friendly**: Clean code, hooks, and filters for customization
5. **Future Proof**: Regular updates and modern WordPress standards
6. **Migration Ready**: Seamless transition from Yoast SEO or other plugins

### 🔧 Technical Features

* REST API integration for real-time analysis
* WordPress Block Editor (Gutenberg) integration
* Classic Editor support
* Multisite compatibility
* WPML ready
* Developer hooks and filters
* Clean uninstall process
* AI integration with OpenAI API
* PageSpeed API integration
* Core Web Vitals monitoring

### 📊 Admin Features

* SEO Dashboard with performance overview and analytics
* Bulk optimization tools
* Content analysis columns in post lists
* Settings import/export
* Site health integration
* AI-powered content suggestions
* PageSpeed performance monitoring
* One-click Yoast SEO migration

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ace-crawl-enhancer/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to 'Ace SEO' in your admin menu to configure settings
4. Start optimizing your content with the new meta boxes!

### Migrating from Yoast SEO

1. Install and activate Ace SEO (keep Yoast SEO active initially)
2. Go to 'Ace SEO' → 'Tools' in your admin menu
3. Click 'Migrate Yoast SEO Data' to transfer all your SEO data
4. Verify all data is working correctly in the post editor
5. Deactivate Yoast SEO (your data remains safe and intact)
6. Optionally delete Yoast SEO

**Note**: The migration process copies your Yoast data to Ace SEO's database fields but leaves the original Yoast data untouched, so you can always revert if needed.

== Frequently Asked Questions ==

= Is this compatible with Yoast SEO data? =

Yes! Ace SEO can automatically migrate all your existing Yoast SEO data including titles, meta descriptions, focus keywords, and social media settings. The plugin includes a one-click migration tool that safely transfers your data while preserving the originals.

= Can I run this alongside Yoast SEO? =

While technically possible during migration, it's not recommended for production as both plugins will output SEO tags. You should migrate your data first, then deactivate Yoast SEO after confirming everything works correctly.

= Does this work with WooCommerce? =

Yes! Ace SEO includes special WooCommerce integration with Product schema markup, e-commerce specific SEO features, and performance monitoring for product pages.

= Is this compatible with page builders? =

Yes! Ace SEO works with all major page builders including Elementor, Beaver Builder, Divi, and others. The content analysis adapts to your content regardless of how it's created.

= Does this generate XML sitemaps? =

Yes! Ace SEO includes automatic XML sitemap generation that's submitted to search engines and follows best practices for SEO.

= Are there AI features? =

Yes! With an OpenAI API key, you can use AI-powered features for generating SEO titles, meta descriptions, content analysis, and optimization suggestions based on current trends.

= Does it monitor page performance? =

Yes! With a Google PageSpeed API key, Ace SEO monitors Core Web Vitals and page performance, showing how it impacts your SEO rankings.

= Is there an import/export feature? =

Yes! You can export your SEO settings and import them to other sites. The migration tool also works with Yoast SEO backups and exports.

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
* Modern tabbed interface with real-time analysis
* Seamless Yoast SEO migration with one-click tool
* AI-powered content optimization with OpenAI integration
* PageSpeed API integration with Core Web Vitals monitoring
* Advanced social media optimization with live previews
* Comprehensive Schema.org structured data
* XML sitemap generation with automatic submission
* REST API integration for real-time analysis
* WordPress 6.8 compatibility
* WooCommerce integration with Product schema
* Bulk optimization tools and SEO dashboard

== Upgrade Notice ==

= 1.0.0 =
Initial release of Ace Crawl Enhancer - the modern Yoast SEO alternative.

== Technical Notes ==

### Database Structure

Ace SEO uses its own database fields (`_ace_seo_*` prefix) for storing SEO data, ensuring future compatibility and performance. The plugin includes automatic migration from Yoast SEO fields:

**Plugin Fields (used for storage):**
* `_ace_seo_title` - SEO title
* `_ace_seo_metadesc` - Meta description  
* `_ace_seo_focuskw` - Focus keyword
* `_ace_seo_linkdex` - SEO score
* `_ace_seo_content_score` - Content score
* `_ace_seo_opengraph-title` - Facebook title
* `_ace_seo_opengraph-description` - Facebook description
* `_ace_seo_opengraph-image` - Facebook image
* `_ace_seo_twitter-title` - Twitter title
* `_ace_seo_twitter-description` - Twitter description
* `_ace_seo_twitter-image` - Twitter image
* `_ace_seo_canonical` - Canonical URL
* `_ace_seo_meta-robots-noindex` - Noindex setting
* `_ace_seo_meta-robots-nofollow` - Nofollow setting
* `_ace_seo_meta-robots-adv` - Advanced robots
* `_ace_seo_bctitle` - Breadcrumb title

**Migration Support:**
The plugin automatically migrates data from Yoast SEO fields (`_yoast_wpseo_*`) when first accessed, ensuring seamless transition without data loss.

### Developer Hooks

* `ace_seo_meta_fields` - Filter meta fields definition
* `ace_seo_schema_article` - Filter article schema
* `ace_seo_analysis_score` - Filter SEO analysis score
* `ace_seo_before_head_output` - Action before head output
* `ace_seo_after_head_output` - Action after head output
* `ace_seo_ai_suggestions` - Filter AI-generated suggestions
* `ace_seo_pagespeed_data` - Filter PageSpeed API data

### REST API Endpoints

* `GET /wp-json/ace-seo/v1/analyze/{post_id}` - Get SEO analysis
* `GET /wp-json/ace-seo/v1/preview/{post_id}` - Get search preview
* `POST /wp-json/ace-seo/v1/ai/suggest-titles` - AI title suggestions
* `POST /wp-json/ace-seo/v1/ai/suggest-descriptions` - AI description suggestions
* `GET /wp-json/ace-seo/v1/pagespeed/{post_id}` - PageSpeed analysis

== Support ==

For support, feature requests, or bug reports, please visit our [GitHub repository](https://github.com/acemedia/ace-crawl-enhancer) or contact us through our website.

== Privacy ==

Ace SEO prioritizes your privacy and data security:

* **No Data Collection**: The plugin does not collect or transmit any personal data by default
* **Local Analysis**: All SEO analysis is performed locally on your server
* **Optional API Integration**: AI features and PageSpeed monitoring require API keys but are completely optional
* **Data Control**: When using AI features, only the content you choose to analyze is sent to OpenAI
* **No Tracking**: No external tracking or analytics are embedded in the plugin
