<?php

/**
 * helpers/js-custom-code.php
 *
 * Creates (or updates) an Elementor Pro "Custom Code" snippet using the exact
 * post-meta keys Elementor stores (verified against a UI-created snippet):
 *
 *   _elementor_code        -> the code itself
 *   _elementor_location    -> elementor_body_end  (= before </body>)
 *   _elementor_priority    -> 10
 *   _elementor_conditions  -> ["include/general"] (entire site)
 *   _elementor_extra_options -> []
 *
 * Called by setup.bat via WP-CLI:
 *     wp eval-file "<wp-setup>\helpers\js-custom-code.php" "<wp-setup>\assets\base-custom-js.txt"
 */

if (! defined('WP_CLI') || ! WP_CLI) {
	echo "This script must be run through WP-CLI (wp eval-file).\n";
	return;
}

// ---- Settings --------------------------------------------------------------
$snippet_title = 'Custom JS';
$post_type     = 'elementor_snippet';
$location      = 'elementor_body_end'; // before </body>. Others: elementor_head, elementor_body_start
$priority      = 10;
// ---------------------------------------------------------------------------

$txt_path = isset($args[0]) ? $args[0] : '';
if ('' === $txt_path || ! file_exists($txt_path)) {
	WP_CLI::error("Code file not found: {$txt_path}");
}
$code = file_get_contents($txt_path);
if (false === $code) {
	WP_CLI::error("Could not read code file: {$txt_path}");
}

if (! post_type_exists($post_type)) {
	WP_CLI::error("Post type '{$post_type}' not found. Is Elementor Pro active with the Custom Code feature available?");
}

// Custom Code is stored raw only when the saving user may post unfiltered HTML.
// With no user (the WP-CLI default), Elementor HTML-encodes the code so "=>"
// becomes "=&gt;". Run as an administrator to keep the code verbatim.
// (setup.bat already passes --user=admin; this is a fallback for manual runs.)
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

// Idempotent: reuse an existing snippet with the same title.
$existing = get_posts(array(
	'post_type'      => $post_type,
	'title'          => $snippet_title,
	'post_status'    => 'any',
	'posts_per_page' => 1,
	'fields'         => 'ids',
));

$postarr = array(
	'post_title'   => $snippet_title,
	'post_status'  => 'publish',
	'post_type'    => $post_type,
	'post_content' => '',
);

if (! empty($existing)) {
	$postarr['ID'] = (int) $existing[0];
	$post_id       = wp_update_post($postarr, true);
	$action        = 'Updated';
} else {
	$post_id = wp_insert_post($postarr, true);
	$action  = 'Created';
}
if (is_wp_error($post_id)) {
	WP_CLI::error('Failed to save snippet post: ' . $post_id->get_error_message());
}

// The fields Elementor's Custom Code metabox actually reads.
// wp_slash() compensates for the wp_unslash() that update_metadata() runs
// internally, so backslashes in the JS (regex, escapes) are preserved.
update_post_meta($post_id, '_elementor_code', wp_slash($code));
update_post_meta($post_id, '_elementor_location', $location);
update_post_meta($post_id, '_elementor_priority', $priority);
update_post_meta($post_id, '_elementor_conditions', array('include/general'));
if ('' === get_post_meta($post_id, '_elementor_extra_options', true)) {
	update_post_meta($post_id, '_elementor_extra_options', array());
}

// Clean up stray meta from earlier (incorrect) document-style saves so this
// snippet matches the structure of a UI-created one.
delete_post_meta($post_id, '_elementor_page_settings');
delete_post_meta($post_id, '_elementor_template_type');
delete_post_meta($post_id, '_elementor_version');

// Rebuild Elementor Pro's conditions cache so the snippet goes live site-wide.
if (class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) {
	try {
		$tb = \ElementorPro\Modules\ThemeBuilder\Module::instance();
		if (method_exists($tb, 'get_conditions_manager')) {
			$cm = $tb->get_conditions_manager();
			if (is_object($cm) && method_exists($cm, 'get_conditions_cache')) {
				$cache = $cm->get_conditions_cache();
				if (is_object($cache)) {
					if (method_exists($cache, 'regenerate')) {
						$cache->regenerate();
					} elseif (method_exists($cache, 'clear')) {
						$cache->clear();
					}
				}
			}
		}
	} catch (\Throwable $e) {
		// fall through
	}
}
delete_option('elementor_pro_theme_builder_conditions');
delete_option('_elementor_pro_conditions_cache');

// Read-back diagnostic.
$clen = strlen((string) get_post_meta($post_id, '_elementor_code', true));
$cloc = (string) get_post_meta($post_id, '_elementor_location', true);
$cpri = (string) get_post_meta($post_id, '_elementor_priority', true);
WP_CLI::log("Stored -> code length: {$clen} | location: {$cloc} | priority: {$cpri}");

WP_CLI::success("{$action} Elementor Custom Code snippet '{$snippet_title}' (ID {$post_id}).");
