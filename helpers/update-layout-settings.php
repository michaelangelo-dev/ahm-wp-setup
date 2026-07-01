<?php

/**
 * helpers/update-layout-settings.php
 *
 * Updates Elementor Site Settings -> Layout Settings on the active Kit document.
 * Includes: Elementor Width, Container Padding, and Default Page Layout.
 *
 * Called by setup.bat via WP-CLI:
 *     wp eval-file "<wp-setup>\helpers\update-layout-settings.php" "<width>" "<padding>" "<layout>" --user=admin
 */

if (! defined('WP_CLI') || ! WP_CLI) {
	echo "This script must be run through WP-CLI (wp eval-file).\n";
	return;
}

$width   = isset($args[0]) ? trim($args[0]) : '';
$padding = isset($args[1]) ? trim($args[1]) : '';
$layout  = isset($args[2]) ? trim($args[2]) : '';

if (! class_exists('\Elementor\Plugin')) {
	WP_CLI::error('Elementor core is not loaded.');
}

// Resolve the active Kit
$kit_id = 0;
if (
	isset(\Elementor\Plugin::$instance->kits_manager)
	&& method_exists(\Elementor\Plugin::$instance->kits_manager, 'get_active_id')
) {
	$kit_id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
}
if (! $kit_id) {
	$kit_id = (int) get_option('elementor_active_kit');
}
if (! $kit_id || ! get_post($kit_id)) {
	WP_CLI::error('Could not find the active Elementor kit.');
}

// Get existing settings
$settings = get_post_meta($kit_id, '_elementor_page_settings', true);
if (! is_array($settings)) {
	$settings = array();
}

$updated = false;

if ('' !== $width) {
	$settings['container_width'] = array(
		'size' => (int) $width,
		'unit' => 'px',
	);
	$updated = true;
	WP_CLI::log("Setting Elementor Width to {$width}px.");
}

if ('' !== $padding) {
	$settings['container_padding'] = array(
		'top'      => $padding,
		'right'    => $padding,
		'bottom'   => $padding,
		'left'     => $padding,
		'unit'     => 'px',
		'isLinked' => true,
	);
	$updated = true;
	WP_CLI::log("Setting Container Padding to {$padding}px on all sides.");
}

if ('' !== $layout) {
	// 'default', 'elementor_canvas', 'elementor_header_footer'
	$settings['default_page_template'] = $layout;
	$updated = true;
	WP_CLI::log("Setting Default Page Layout to '{$layout}'.");
}

if ($updated) {
	update_post_meta($kit_id, '_elementor_page_settings', wp_slash($settings));

	try {
		if (
			isset(\Elementor\Plugin::$instance->files_manager)
			&& method_exists(\Elementor\Plugin::$instance->files_manager, 'clear_cache')
		) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	} catch (\Throwable $e) {
		// non-fatal
	}

	WP_CLI::success("Site Layout Settings updated on kit {$kit_id}.");
} else {
	WP_CLI::log("No valid layout settings provided. Skipping update.");
}
