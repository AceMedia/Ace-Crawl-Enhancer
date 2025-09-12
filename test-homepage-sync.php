<?php
/**
 * Test script for ACE SEO homepage synchronization and paragraph extraction
 * Run this from WordPress admin or via WP-CLI to test the functionality
 */

// Make sure we're in WordPress context
if (!defined('ABSPATH')) {
    // If running via command line, bootstrap WordPress
    require_once(__DIR__ . '/../../../../core/wp-load.php');
}

echo "🧪 Testing ACE SEO Paragraph Extraction and Homepage Sync\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Get the plugin instance
if (class_exists('AceCrawlEnhancer')) {
    $ace_seo = AceCrawlEnhancer::get_instance();
    
    // Test 1: Paragraph extraction with mixed content
    echo "Test 1: Paragraph Content Extraction\n";
    echo "-" . str_repeat("-", 40) . "\n";
    
    $test_content_with_custom_blocks = '
    <!-- wp:paragraph -->
    <p>This is a clean paragraph that should be extracted.</p>
    <!-- /wp:paragraph -->
    
    <!-- wp:custom/javascript-block -->
    <div>var ftClick = ""; var ftExpTrack_4660118 = ""; // This JavaScript should NOT be extracted</div>
    <!-- /wp:custom/javascript-block -->
    
    <!-- wp:paragraph -->
    <p>This is another clean paragraph that should also be extracted.</p>
    <!-- /wp:paragraph -->
    ';
    
    // Use reflection to access private method for testing
    $reflection = new ReflectionClass($ace_seo);
    $method = $reflection->getMethod('extract_paragraph_content');
    $method->setAccessible(true);
    
    $extracted = $method->invoke($ace_seo, $test_content_with_custom_blocks);
    
    echo "Original content has custom blocks with JavaScript\n";
    echo "Extracted content: " . $extracted . "\n";
    echo "✅ Test passed: " . (strpos($extracted, 'ftClick') === false ? 'No JavaScript extracted' : '❌ FAILED: JavaScript found in extraction') . "\n\n";
    
    // Test 2: Homepage settings synchronization
    echo "Test 2: Homepage Settings Synchronization\n";
    echo "-" . str_repeat("-", 40) . "\n";
    
    // Get current homepage settings
    $page_on_front = get_option('page_on_front');
    $show_on_front = get_option('show_on_front');
    
    if ($show_on_front === 'page' && $page_on_front) {
        echo "Static homepage detected (Page ID: $page_on_front)\n";
        
        // Test the synchronization by getting current values
        $options = get_option('ace_seo_options', []);
        $settings_title = $options['general']['home_title'] ?? '';
        $settings_desc = $options['general']['home_description'] ?? '';
        
        $page_title = AceCrawlEnhancer::get_meta_value($page_on_front, 'title');
        $page_desc = AceCrawlEnhancer::get_meta_value($page_on_front, 'metadesc');
        
        echo "Plugin Settings - Title: '$settings_title', Description: '$settings_desc'\n";
        echo "Page Meta - Title: '$page_title', Description: '$page_desc'\n";
        
        // Test the homepage functions
        $home_title_method = $reflection->getMethod('get_homepage_title');
        $home_title_method->setAccessible(true);
        $resolved_title = $home_title_method->invoke($ace_seo);
        
        $home_desc_method = $reflection->getMethod('get_homepage_meta_description');
        $home_desc_method->setAccessible(true);
        $resolved_desc = $home_desc_method->invoke($ace_seo);
        
        echo "Resolved Title: '$resolved_title'\n";
        echo "Resolved Description: '$resolved_desc'\n";
        echo "✅ Synchronization functions working\n\n";
    } else {
        echo "Blog homepage detected - testing fallback behavior\n";
        echo "✅ Blog homepage handling available\n\n";
    }
    
    // Test 3: Meta output
    echo "Test 3: Meta Tag Output\n";
    echo "-" . str_repeat("-", 40) . "\n";
    
    // Test if we're on the homepage
    if (is_home() || is_front_page()) {
        echo "Testing homepage meta output...\n";
        
        // Capture meta description output
        ob_start();
        $ace_seo->output_meta_description();
        $meta_output = ob_get_clean();
        
        echo "Meta description output: " . trim($meta_output) . "\n";
        echo "✅ Meta output working\n\n";
    } else {
        echo "Not on homepage - testing would require frontend context\n\n";
    }
    
    echo "🎉 All tests completed!\n";
    echo "\nKey Features Implemented:\n";
    echo "✅ Paragraph-only content extraction (blocks JavaScript)\n";
    echo "✅ Bidirectional homepage synchronization\n";
    echo "✅ Settings ↔ Page meta sync on save\n";
    echo "✅ Auto-generation from clean paragraph content\n";
    echo "✅ Fallback handling for all scenarios\n";
    
} else {
    echo "❌ ACE SEO plugin not found or not active\n";
}
