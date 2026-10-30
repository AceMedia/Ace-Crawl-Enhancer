<?php
/**
 * Ace Crawl Enhancer - Advanced SEO Plugin
 *
 * @package AceCrawlEnhancer
 * @version 1.0.95
 * @author AceMedia
 * @description A modern SEO plugin with seamless Yoast migration, AI-powered optimization, and advanced performance features
 * 
 * @wordpress-plugin
 * Plugin Name: Ace Crawl Enhancer
 * Plugin URI: https://github.com/AceMedia/Ace-Crawl-Enhancer
 * Description: Advanced SEO plugin with seamless Yoast migration, modern interface, AI-powered optimization, and comprehensive SEO features.
 * Version: 1.0.95
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
define('ACE_SEO_VERSION', '1.0.95');
define('ACE_SEO_FILE', __FILE__);
define('ACE_SEO_PATH', plugin_dir_path(__FILE__));
define('ACE_SEO_URL', plugin_dir_url(__FILE__));
define('ACE_SEO_BASENAME', plugin_basename(__FILE__));

require_once ACE_SEO_PATH . 'includes/plugin-runtime.php';
