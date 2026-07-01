<?php

/**
 * helpers/site-custom-css.php
 *
 * Writes CSS into Elementor's Site Settings -> Custom CSS, which lives on the
 * active Kit document at:  _elementor_page_settings['custom_css'].
 * Then clears Elementor's CSS file cache so it regenerates on the front end.
 *
 * Called by setup.bat via WP-CLI:
 *     wp eval-file "<wp-setup>\helpers\site-custom-css.php" "<wp-setup>\assets\base-custom-css.txt" --user=admin
 */

if (! defined('WP_CLI') || ! WP_CLI) {
	echo "This script must be run through WP-CLI (wp eval-file).\n";
	return;
}

$txt_path = isset($args[0]) ? $args[0] : '';
if ('' === $txt_path || ! file_exists($txt_path)) {
	WP_CLI::error("CSS file not found: {$txt_path}");
}
$css = file_get_contents($txt_path);
if (false === $css) {
	WP_CLI::error("Could not read CSS file: {$txt_path}");
}

if (! class_exists('\Elementor\Plugin')) {
	WP_CLI::error('Elementor core is not loaded.');
}

// Run as an administrator so CSS is stored verbatim (CSS contains >, &, etc.).
if (! is_user_logged_in()) {
	$admins = get_users(array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	));
	if (! empty($admins)) {
		wp_set_current_user((int) $admins[0]);
	}
}

// Resolve the active Kit (holds global Site Settings).
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
	WP_CLI::error('Could not find the active Elementor kit. Open the site once in the Elementor editor, then retry.');
}

// Merge into the kit's page settings (preserve everything else).
$settings = get_post_meta($kit_id, '_elementor_page_settings', true);
if (! is_array($settings)) {
	$settings = array();
}
$settings['custom_css'] = $css;

// get_post_meta returns unslashed data; update_metadata() unslashes again,
// so wp_slash() the whole array to preserve backslashes in every value.
update_post_meta($kit_id, '_elementor_page_settings', wp_slash($settings));

// Regenerate Elementor's CSS so the kit CSS (including custom_css) is rebuilt.
try {
	if (
		isset(\Elementor\Plugin::$instance->files_manager)
		&& method_exists(\Elementor\Plugin::$instance->files_manager, 'clear_cache')
	) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
} catch (\Throwable $e) {
	// non-fatal; CSS will regenerate on next front-end load anyway
}

// Read-back diagnostic.
$check = get_post_meta($kit_id, '_elementor_page_settings', true);
$len   = (is_array($check) && isset($check['custom_css'])) ? strlen($check['custom_css']) : 0;
WP_CLI::log("Active kit ID: {$kit_id} | custom_css length: {$len}");
if (0 === $len) {
	WP_CLI::warning('custom_css length is 0 - the key may differ on your version. Run inspect-snippet-style dump on the kit and send it over.');
}

WP_CLI::success("Site Settings Custom CSS updated on kit {$kit_id}.");
