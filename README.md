# Ace Crawl Enhancer

[![WordPress Plugin](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.3-orange.svg)](https://github.com/acemedia/ace-crawl-enhancer)

**Advanced SEO plugin with Yoast compatibility, modern interface, real-time analysis, and powerful optimization features.**

**Contributors:** AceMedia  
**Tags:** seo, search engine optimization, meta, social media, schema, performance, optimization  
**Requires at least:** WordPress 6.0  
**Tested up to:** WordPress 6.8  
**Requires PHP:** 7.4+  
**Stable tag:** 1.0.3  
**License:** GPLv2 or later

## 🚀 Key Features

### Modern Interface
- **Clean, tabbed interface** with modern design
- **Real-time SEO and readability analysis**
- **Live Google, Facebook, and Twitter previews**
- **Character counters** with visual progress bars
- **Mobile-responsive design**

### Seamless Yoast SEO Migration
- **Batch processing migration system** with real-time progress tracking
- **Pause and resume functionality** for large site migrations
- **Interactive progress bar** with current item display and completion percentage
- **Console-style migration log** with detailed status information
- **Error handling and recovery** - continues processing even if individual posts fail
- **Smart migration detection** - automatically skips already migrated content
- **Memory-safe processing** - handles large sites without server timeouts
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
- **Gutenberg breadcrumbs block placeholder**
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

##  Technical Features

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
5. **Use the new batch migration system** in 'Ace SEO' → 'Tools' to migrate from Yoast SEO
6. Start optimizing your content with the new meta boxes!

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

**New in 1.0.3: Advanced Batch Processing Migration System**

1. **Install and activate Ace SEO** (keep Yoast SEO active initially)
2. **Go to 'Ace SEO' → 'Tools'** in your admin menu
3. **Review Migration Statistics** - See how many posts need migration
4. **Click 'Start Migration'** to begin the batch processing system
5. **Monitor Progress** - Watch real-time progress with:
   - Interactive progress bar with percentage completion
   - Current item being processed (title, ID, post type)
   - Console-style log with detailed migration information
   - Pause/Resume functionality if you need to stop temporarily
6. **Review Results** - See migration summary with:
   - Total posts processed and fields migrated
   - Any errors encountered (with detailed error messages)
   - Migration completion statistics
7. **Verify all data** is working correctly in the post editor
8. **Deactivate Yoast SEO** (your data remains safe and intact)
9. **Optionally delete Yoast SEO**

### Migration Features
- **Batch Processing**: Processes posts in configurable chunks (default: 10 posts per batch)
- **Memory Safe**: Small delays between batches prevent server overload
- **Resumable**: Can pause and resume at any time without losing progress
- **Error Recovery**: Continues processing even if individual posts encounter errors
- **Real-time Feedback**: Live progress updates, current item display, and detailed logging
- **Smart Detection**: Only migrates posts that haven't been processed recently (within 7 days)

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
Yes! Ace SEO features an advanced batch processing migration system that can automatically migrate all your existing Yoast SEO data including titles, meta descriptions, focus keywords, and social media settings. The new migration system includes:
- Real-time progress tracking with interactive progress bar
- Pause and resume functionality for large sites
- Detailed logging and error recovery
- Memory-safe processing that won't timeout on large sites
- Smart detection to avoid re-migrating already processed content

### Can I run this alongside Yoast SEO?
During migration, yes! The new batch migration system is designed to work safely alongside Yoast SEO during the transition period. You can:
- Keep both plugins active during migration
- Use the pause/resume functionality to migrate in stages
- Test the migration results before deactivating Yoast SEO
However, for production use, you should only run one SEO plugin to avoid duplicate meta tags.

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

### 1.0.3 (2025-09-16)
**🔄 Advanced Batch Migration & User Experience Release**

#### New Features
- **Advanced Batch Processing Migration System**
  - **Interactive Progress Tracking** - Real-time progress bar with completion percentage
  - **Pause/Resume Functionality** - Start, pause, and resume migrations at any time
  - **Current Item Display** - See exactly which post is being processed (title, ID, type)
  - **Console-style Migration Log** - Detailed logging with color-coded messages (success, error, warning, info)
  - **Batch Processing** - Processes posts in configurable chunks (default: 10 per batch) for memory safety
  - **Smart Migration Detection** - Automatically skips posts migrated within the last 7 days
  - **Error Recovery** - Continues processing even if individual posts encounter errors
  - **Migration Statistics** - Live stats showing Yoast posts, Ace posts, and pending migrations

#### User Experience Improvements
- **Modern Migration Interface**
  - Professional progress bar with animated shine effect
  - Dashboard-style statistics display with highlighted values
  - Real-time status updates and current processing information
  - Migration results summary with comprehensive statistics
  - Clean, modern UI design consistent with WordPress admin

- **Enhanced Migration Safety**
  - **Memory-safe Processing** - Small delays between batches prevent server overload
  - **Network Error Recovery** - Handles network timeouts and connection issues gracefully
  - **Progress Persistence** - Can resume migrations exactly where they left off
  - **Comprehensive Error Logging** - Detailed error messages for troubleshooting
  - **Non-blocking Operation** - Migration runs without freezing the admin interface

#### Technical Improvements
- **AJAX-powered Migration System**
  - Non-blocking batch processing via WordPress AJAX
  - Proper nonce security and permission checks
  - Efficient database queries with optimized SQL
  - Background processing with status tracking
  
- **Enhanced Database Handling**
  - Improved migration queries with proper JOINs
  - Better migration tracking with timestamp-based detection
  - Optimized post status filtering (publish, draft, private, future)
  - Legacy compatibility with existing bulk migration function

#### Developer Features
- **New AJAX Endpoints**
  - `ace_seo_batch_migrate_yoast` - Batch migration processing
  - `ace_seo_get_migration_stats` - Real-time migration statistics
  - Enhanced error handling and response formatting
  
- **Migration Hooks & Filters**
  - Configurable batch sizes for different server environments
  - Customizable delay settings for server load management
  - Migration progress hooks for external monitoring
  - Error handling callbacks for custom logging

#### Bug Fixes
- Fixed potential memory issues with large site migrations
- Improved error handling for corrupted post data
- Enhanced compatibility with various hosting environments
- Resolved edge cases in migration detection logic
- Better handling of custom post types and post statuses

#### Performance & Security
- **Optimized Migration Queries** - More efficient SQL with better indexing
- **Security Enhancements** - Improved nonce verification and permission checks
- **Memory Management** - Configurable batch processing prevents memory exhaustion
- **Server Load Protection** - Built-in delays and limits prevent server overload

### 1.0.2 (2025-09-12)
**⚡ Frontend Performance & Content Optimization Release**

#### New Features
- **Frontend Performance Optimization System**
  - Guest-optimized loading for maximum SEO performance
  - Intelligent meta caching system with batch loading
  - Conditional component loading (admin vs. frontend separation)
  - WordPress object cache integration with 1-hour TTL
  - Schema markup optimization with deferred loading

- **Content Extraction & Homepage Synchronization**
  - **Gutenberg-aware paragraph extraction** - Only extracts content from core paragraph blocks
  - **JavaScript contamination prevention** - Filters out custom blocks with JavaScript code
  - **Bidirectional homepage sync** - Settings ↔ Page meta synchronization for homepage SEO
  - **Auto-generation from clean content** - Meta descriptions generated only from paragraph text
  - **Real-time synchronization** - Changes in either plugin settings or page meta sync automatically

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
- **Fixed homepage meta description corruption** - Eliminated JavaScript code in auto-generated descriptions
- **Resolved custom block contamination** - Content extraction now only uses core paragraph blocks
- **Enhanced homepage SEO handling** - Proper synchronization between plugin settings and page meta
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
