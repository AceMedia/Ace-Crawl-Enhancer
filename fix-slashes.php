#!/usr/bin/env php
<?php
/**
 * Fix excessive slashes in ace_seo_options templates
 * This script removes accumulated backslashes from template values
 */

// Load WordPress
require_once __DIR__ . '/../../../core/wp-load.php';

if (!defined('ABSPATH')) {
    die('WordPress not loaded');
}

echo "Fixing excessive slashes in ace_seo_options...\n\n";

// Get current options
$options = get_option('ace_seo_options', []);

if (empty($options)) {
    echo "No ace_seo_options found.\n";
    exit(1);
}

$fixed_count = 0;
$templates = $options['templates'] ?? [];

echo "Checking templates...\n";

foreach ($templates as $key => $value) {
    if (!is_string($value)) {
        continue;
    }
    
    // Check if value has excessive slashes
    if (strpos($value, '\\\\') !== false) {
        echo "Found excessive slashes in: $key\n";
        echo "  Before: " . substr($value, 0, 100) . "\n";
        
        // Recursively strip slashes until no more double backslashes exist
        $fixed_value = $value;
        while (strpos($fixed_value, '\\\\') !== false) {
            $fixed_value = stripslashes($fixed_value);
        }
        
        echo "  After:  " . substr($fixed_value, 0, 100) . "\n\n";
        
        $options['templates'][$key] = $fixed_value;
        $fixed_count++;
    }
}

if ($fixed_count > 0) {
    echo "Fixed $fixed_count template(s).\n";
    echo "Updating database...\n";
    
    if (update_option('ace_seo_options', $options)) {
        echo "✓ Successfully updated ace_seo_options\n";
    } else {
        echo "✗ Failed to update ace_seo_options\n";
        exit(1);
    }
} else {
    echo "No templates with excessive slashes found.\n";
}

echo "\nDone!\n";
