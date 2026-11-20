<?php
/**
 * Ace Crawl Enhancer - Advanced SEO Plugin
 *
 * @package AceCrawlEnhancer
 * @version 1.0.103
 * @author AceMedia
 * @description A modern SEO plugin with seamless Yoast migration, AI-powered optimization, and advanced performance features
 * 
 * @wordpress-plugin
 * Plugin Name: Ace Crawl Enhancer
 * Plugin URI: https://github.com/AceMedia/Ace-Crawl-Enhancer
 * Description: Advanced SEO plugin with seamless Yoast migration, modern interface, AI-powered optimization, and comprehensive SEO features.
 * Version: 1.0.103
 * Author: AceMedia
 * Text Domain: ace-crawl-enhancer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '7.4', '<') || version_compare($GLOBALS['wp_version'], '6.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' . esc_html__('Ace SEO requires WordPress 6.0 and PHP 7.4 or newer.', 'ace-crawl-enhancer') . '</p></div>';
    });
    return;
}

// Define plugin constants
define('ACECRAWL_VERSION', '1.0.103');
define('ACECRAWL_FILE', __FILE__);
define('ACECRAWL_PATH', plugin_dir_path(__FILE__));
define('ACECRAWL_URL', plugin_dir_url(__FILE__));
define('ACECRAWL_BASENAME', plugin_basename(__FILE__));

// The public view counter is one INSERT. Answer a plain hit here, before the rest of WordPress
// loads; anything unusual carries on to the normal REST route.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared with fixed strings only.
if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && preg_match('#/wp-json/ace-seo/v1/aggregate-view/?(\?|$)#', (string) ($_SERVER['REQUEST_URI'] ?? ''))) {
    require_once ACECRAWL_PATH . 'includes/class-ace-seo-view-tracker.php';
    require_once ACECRAWL_PATH . 'includes/class-ace-seo-aggregate-views.php';
    if (AceCrawlAggregateViews::fast_refuse($_SERVER)) {
        status_header(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}';
        exit;
    }
    if (AceCrawlAggregateViews::fast_hit($_SERVER, (string) file_get_contents('php://input'))) {
        status_header(204);
        header('Cache-Control: no-store');
        exit;
    }
}

require_once ACECRAWL_PATH . 'includes/plugin-runtime.php';
