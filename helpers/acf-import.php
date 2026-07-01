<?php

/**
 * ACF JSON Import Helper
 * Executed via WP-CLI: wp eval-file acf-import.php <path-to-json>
 */

// Get the JSON file path passed from the batch script
$json_file = $args[0] ?? '';

if (! file_exists($json_file)) {
    WP_CLI::error("ACF JSON file not found: {$json_file}");
}

// Read and decode the JSON
$json_data = file_get_contents($json_file);
$items = json_decode($json_data, true);

if (empty($items)) {
    WP_CLI::error("Invalid or empty JSON data in: {$json_file}");
}

$imported_count = 0;

// Loop through the array and import based on the item type
foreach ($items as $item) {
    // Check if it's a Field Group
    if (isset($item['key']) && strpos($item['key'], 'group_') === 0) {
        if (function_exists('acf_import_field_group')) {
            acf_import_field_group($item);
            WP_CLI::success("Imported Field Group: " . $item['title']);
            $imported_count++;
        }
    }
    // Check if it's a Custom Post Type (ACF 6.1+)
    elseif (isset($item['key']) && strpos($item['key'], 'post_type_') === 0) {
        if (function_exists('acf_import_post_type')) {
            acf_import_post_type($item);
            WP_CLI::success("Imported Post Type: " . $item['title']);
            $imported_count++;
        }
    }
    // Check if it's a Taxonomy (ACF 6.1+)
    elseif (isset($item['key']) && strpos($item['key'], 'tax_') === 0) {
        if (function_exists('acf_import_taxonomy')) {
            acf_import_taxonomy($item);
            WP_CLI::success("Imported Taxonomy: " . $item['title']);
            $imported_count++;
        }
    }
}

WP_CLI::success("Successfully imported {$imported_count} ACF items into the database.");
