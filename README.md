# Ace Crawl Enhancer

[![WordPress Plugin](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.2-orange.svg)](https://github.com/acemedia/ace-crawl-enhancer)

**Advanced SEO plugin with Yoast compatibility, modern interface, real-time analysis, and powerful optimization features.**

**Contributors:** AceMedia  
**Tags:** seo, search engine optimization, meta, social media, schema, performance, optimization  
**Requires at least:** WordPress 6.0  
**Tested up to:** WordPress 6.8  
**Requires PHP:** 7.4+  
**Stable tag:** 1.0.2  
**License:** GPLv2 or later

## 🚀 Key Features

### Modern Interface
- **Clean, tabbed interface** with modern design
- **Real-time SEO and readability analysis**
- **Live Google, Facebook, and Twitter previews**
- **Character counters** with visual progress bars
- **Mobile-responsive design**

### Seamless Yoast SEO Migration
- **Automatic migration** of existing Yoast SEO data
- **One-click bulk migration tool**
- **Preserves all existing SEO** titles, descriptions, and settings
- **Can safely replace Yoast SEO** without data loss
- **Uses plugin-specific database fields** (`_ace_seo_*`) for future-proofing

### High-Performance Database Optimization
- **Automatic database indexing** for lightning-fast SEO queries
- **Background optimization system** - no slow plugin activation
- **Performance monitoring** with real-time database analysis
- **Optimized for large sites** with millions of posts and meta records
- **One-click manual optimization** if needed

### Advanced SEO Features
- **Focus keyword optimization** with real-time scoring
- **SEO title and meta description optimization** with AI assistance
- **Canonical URL management**
- **Meta robots controls** (noindex, nofollow, advanced)
- **Breadcrumbs with structured data**
- **XML sitemaps generation**
- **PageSpeed integration** with Core Web Vitals monitoring

### Frontend Performance Optimization (New in 1.0.2)
- **Guest User Optimization** - Lightning-fast loading for logged-out visitors
- **Batch Meta Loading** - Single database query instead of multiple `get_post_meta()` calls
- **Intelligent Caching** - WordPress object cache for SEO meta with 1-hour TTL
- **Conditional Component Loading** - Different features for admin vs. frontend users
- **Schema Optimization** - Cached and deferred schema markup generation
- **Hook Optimization** - Removes unnecessary admin hooks on frontend
- **Core Web Vitals Focus** - Optimized specifically for search engine crawling speed

### Social Media Optimization
- **Open Graph (Facebook) optimization** with live previews
- **Twitter Cards optimization**
- **Social media image management**
- **Live social media previews**
- **Default fallback images**

### Schema.org Structured Data
- **Automatic Article schema**
- **Organization/LocalBusiness schema**
- **Breadcrumb navigation schema**
- **FAQ schema detection**
- **WooCommerce Product schema** (if WooCommerce is active)

### AI-Powered Features
- **OpenAI integration** for content suggestions
- **AI-generated SEO titles** and meta descriptions
- **Content analysis** with AI recommendations
- **Web search integration** for trend analysis
- **Smart keyword suggestions**

### Content Analysis
- **Real-time SEO scoring** with performance metrics
- **Readability analysis**
- **Content recommendations**
- **Keyword density analysis**
- **Image alt text checking**
- **PageSpeed performance impact** on SEO

## 🎯 Why Choose Ace SEO?

1. **Performance Focused**: Lightweight and optimized for speed with automatic database indexing
2. **User Experience**: Modern, intuitive interface that's easy to use
3. **AI-Enhanced**: Advanced AI features for content optimization and suggestions
4. **Enterprise Ready**: Handles large sites with millions of posts efficiently
5. **Developer Friendly**: Clean code, hooks, and filters for customization
6. **Future Proof**: Regular updates and modern WordPress standards
7. **Migration Ready**: Seamless transition from Yoast SEO or other plugins

## 📊 Performance Improvements

### Database Optimization System (v1.0.1)
- **5 strategic indexes** added for postmeta and posts tables
- **Background processing** prevents slow plugin activation
- **Real-time performance monitoring** in dashboard
- **Optimized for 1M+ meta records** - tested on large sites
- **Automatic optimization** on plugin activation

### Frontend Performance System (v1.0.2)
- **Guest-optimized loading** - Minimal overhead for logged-out users
- **Batch meta queries** - Single SQL query replaces 10+ individual calls
- **WordPress object cache** - 1-hour TTL for SEO meta and schema
- **Conditional hook loading** - Admin features skipped on frontend
- **Schema optimization** - Cached and deferred structured data
- **Core Web Vitals focused** - Optimized for search engine crawlers

### Query Performance
- **Direct SQL queries** replace expensive WordPress ORM calls
- **LIMIT clauses** prevent runaway queries
- **Proper JOINs** with indexed columns
- **Eliminated full table scans** on large databases

### Results
- **Dashboard loads 10-50x faster** on large sites
- **Frontend loads ~0.4s** for guest users (excellent SEO)
- **No more MariaDB spikes** during SEO operations  
- **No 504 timeouts** on admin pages
- **Scales to millions of posts** without performance degradation

## 🔧 Technical Features

- **REST API integration** for real-time analysis
- **WordPress Block Editor (Gutenberg) integration**
- **Classic Editor support**
- **Multisite compatibility**
- **WPML ready**
- **Developer hooks and filters**
- **Clean uninstall process**
- **AI integration** with OpenAI API
- **PageSpeed API integration**
- **Core Web Vitals monitoring**
- **Background task processing**

## 📋 Installation

### Method 1: WordPress Admin
1. Upload the plugin files to `/wp-content/plugins/ace-crawl-enhancer/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. **Database optimization runs automatically in background** (30 seconds after activation)
4. Go to 'Ace SEO' in your admin menu to configure settings
5. Start optimizing your content with the new meta boxes!

### Method 2: WP-CLI
```bash
wp plugin install ace-crawl-enhancer --activate
```

### Database Optimization
The plugin automatically creates performance indexes when activated:
- `ace_seo_meta_key_value` - For meta key/value searches  
- `ace_seo_post_meta_key` - For post ID + meta key lookups
- `ace_seo_yoast_meta` - Specialized Yoast SEO meta index
- `ace_seo_post_status_type_modified` - For post status/type/date queries
- `ace_seo_post_type_status` - For post type/status combinations

**Manual Optimization:** Visit **ACE SEO → Tools** in your WordPress admin to manually rerun database optimization if needed.

## 🔄 Migrating from Yoast SEO

1. **Install and activate Ace SEO** (keep Yoast SEO active initially)
2. **Go to 'Ace SEO' → 'Tools'** in your admin menu
3. **Click 'Migrate Yoast SEO Data'** to transfer all your SEO data
4. **Verify all data** is working correctly in the post editor
5. **Deactivate Yoast SEO** (your data remains safe and intact)
6. **Optionally delete Yoast SEO**

> **Note**: The migration process copies your Yoast data to Ace SEO's database fields but leaves the original Yoast data untouched, so you can always revert if needed.

## 🛠️ Database Structure

Ace SEO uses its own database fields (`_ace_seo_*` prefix) for storing SEO data, ensuring future compatibility and performance.

### Plugin Fields (used for storage)
- `_ace_seo_title` - SEO title
- `_ace_seo_metadesc` - Meta description  
- `_ace_seo_focuskw` - Focus keyword
- `_ace_seo_linkdex` - SEO score
- `_ace_seo_content_score` - Content score
- `_ace_seo_opengraph-title` - Facebook title
- `_ace_seo_opengraph-description` - Facebook description
- `_ace_seo_opengraph-image` - Facebook image
- `_ace_seo_twitter-title` - Twitter title
- `_ace_seo_twitter-description` - Twitter description
- `_ace_seo_twitter-image` - Twitter image
- `_ace_seo_canonical` - Canonical URL
- `_ace_seo_meta-robots-noindex` - Noindex setting
- `_ace_seo_meta-robots-nofollow` - Nofollow setting
- `_ace_seo_meta-robots-adv` - Advanced robots
- `_ace_seo_bctitle` - Breadcrumb title

### Migration Support
The plugin automatically migrates data from Yoast SEO fields (`_yoast_wpseo_*`) when first accessed, ensuring seamless transition without data loss.

## 🔌 Developer Hooks

### Filters
- `ace_seo_meta_fields` - Filter meta fields definition
- `ace_seo_schema_article` - Filter article schema
- `ace_seo_analysis_score` - Filter SEO analysis score
- `ace_seo_ai_suggestions` - Filter AI-generated suggestions
- `ace_seo_pagespeed_data` - Filter PageSpeed API data

### Actions
- `ace_seo_before_head_output` - Action before head output
- `ace_seo_after_head_output` - Action after head output
- `ace_seo_optimize_database` - Background database optimization hook

## 🌐 REST API Endpoints

- `GET /wp-json/ace-seo/v1/analyze/{post_id}` - Get SEO analysis
- `GET /wp-json/ace-seo/v1/preview/{post_id}` - Get search preview
- `POST /wp-json/ace-seo/v1/ai/suggest-titles` - AI title suggestions
- `POST /wp-json/ace-seo/v1/ai/suggest-descriptions` - AI description suggestions
- `GET /wp-json/ace-seo/v1/pagespeed/{post_id}` - PageSpeed analysis

## ❓ Frequently Asked Questions

### Is this compatible with Yoast SEO data?
Yes! Ace SEO can automatically migrate all your existing Yoast SEO data including titles, meta descriptions, focus keywords, and social media settings. The plugin includes a one-click migration tool that safely transfers your data while preserving the originals.

### Can I run this alongside Yoast SEO?
While technically possible during migration, it's not recommended for production as both plugins will output SEO tags. You should migrate your data first, then deactivate Yoast SEO after confirming everything works correctly.

### Does this work with WooCommerce?
Yes! Ace SEO includes special WooCommerce integration with Product schema markup, e-commerce specific SEO features, and performance monitoring for product pages.

### Will this slow down my large site?
No! Version 1.0.1 includes advanced database optimization specifically designed for large sites. The plugin creates strategic indexes and uses background processing to ensure excellent performance even with millions of posts.

### Does this generate XML sitemaps?
Yes! Ace SEO includes automatic XML sitemap generation that's submitted to search engines and follows best practices for SEO.

### Are there AI features?
Yes! With an OpenAI API key, you can use AI-powered features for generating SEO titles, meta descriptions, content analysis, and optimization suggestions based on current trends.

### Does it monitor page performance?
Yes! With a Google PageSpeed API key, Ace SEO monitors Core Web Vitals and page performance, showing how it impacts your SEO rankings.

## 📝 Changelog

### 1.0.2 (2025-09-12)
**⚡ Frontend Performance & Guest Optimization Release**

#### New Features
- **Frontend Performance Optimization System**
  - Guest-optimized loading for maximum SEO performance
  - Intelligent meta caching system with batch loading
  - Conditional component loading (admin vs. frontend separation)
  - WordPress object cache integration with 1-hour TTL
  - Schema markup optimization with deferred loading

#### Performance Improvements
- **Guest User Optimization**
  - Lightning-fast loading for logged-out visitors (~0.4s response times)
  - Single database query replaces 10+ individual `get_post_meta()` calls
  - Preloaded common site data (title, description, icon, URLs)
  - Optimized hook loading removes unnecessary admin functionality
  
- **SEO-Focused Caching**
  - Cached SEO meta with automatic Yoast fallback support
  - Schema markup cached and reused for repeat crawls
  - Core Web Vitals optimizations for search engine crawlers
  - Automatic cache invalidation on post updates

#### Technical Improvements
- **Database Query Optimization**
  - Batch meta loading with single SQL query per post
  - Cached meta accessible via static methods for external use
  - Intelligent cache warming for commonly accessed data
  - Performance monitoring for frontend vs. admin users

#### Bug Fixes
- Fixed PHP fatal error with undefined `init_optimizations()` method
- Resolved frontend loading issues for guest users
- Improved error handling in performance optimization class
- Enhanced compatibility with existing caching systems

#### Developer Features
- **ACE_SEO_Performance** class for external integration
- Static cache access methods for theme developers
- Performance hooks and filters for customization
- Debugging tools for frontend performance analysis

### 1.0.1 (2025-09-12)
**🚀 Performance & Database Optimization Release**

#### New Features
- **Background Database Optimization System**
  - Automatic database index creation for optimal performance
  - Background processing prevents slow plugin activation
  - Real-time performance monitoring in dashboard
  - Manual optimization tools in ACE SEO → Tools admin page

#### Performance Improvements
- **Database Query Optimization**
  - Added 5 strategic indexes for postmeta and posts tables
  - Replaced expensive WordPress ORM calls with direct SQL
  - Added LIMIT clauses to prevent runaway queries
  - Eliminated full table scans on large databases
  
- **Dashboard Performance**
  - Dashboard now loads 10-50x faster on large sites
  - Optimized SEO statistics queries
  - Real-time database performance analysis
  - Status indicators for optimization progress

#### Technical Improvements
- **Background Task Processing**
  - Plugin activation now completes in <1 second
  - Database optimization runs via WordPress cron
  - Fault-tolerant with manual fallback options
  - Status tracking and progress indicators
  
- **Developer Experience**
  - Enhanced error logging and debugging tools
  - Performance monitoring hooks and filters
  - Admin interface for database optimization (ACE SEO → Tools)
  - Clean deactivation removes all scheduled tasks

#### Bug Fixes
- Fixed MariaDB CPU spikes on large sites
- Resolved 504 timeouts during admin page loads
- Improved memory usage for sites with millions of meta records
- Enhanced error handling for background processes

#### Compatibility
- Tested with WordPress 6.8
- Validated on sites with 1M+ posts and meta records
- Improved multisite compatibility
- Enhanced developer debugging tools

### 1.0.0 (2025-09-10)
**🎉 Initial Release**

#### Core Features
- Modern tabbed interface with real-time analysis
- Seamless Yoast SEO migration with one-click tool
- AI-powered content optimization with OpenAI integration
- PageSpeed API integration with Core Web Vitals monitoring
- Advanced social media optimization with live previews
- Comprehensive Schema.org structured data
- XML sitemap generation with automatic submission
- REST API integration for real-time analysis
- WordPress 6.8 compatibility
- WooCommerce integration with Product schema
- Bulk optimization tools and SEO dashboard

## 🔒 Privacy

Ace SEO prioritizes your privacy and data security:

- **No Data Collection**: The plugin does not collect or transmit any personal data by default
- **Local Analysis**: All SEO analysis is performed locally on your server
- **Optional API Integration**: AI features and PageSpeed monitoring require API keys but are completely optional
- **Data Control**: When using AI features, only the content you choose to analyze is sent to OpenAI
- **No Tracking**: No external tracking or analytics are embedded in the plugin

## 📞 Support

For support, feature requests, or bug reports, please visit our [GitHub repository](https://github.com/acemedia/ace-crawl-enhancer) or contact us through our website.

## 📄 License

This project is licensed under the GPLv2 or later - see the [LICENSE](LICENSE) file for details.

---

**Made with ❤️ by [AceMedia](https://acemedia.ninja)**
