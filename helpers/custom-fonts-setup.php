<?php

/**
 * helpers/custom-fonts-setup.php
 * -----------------------------------------------------------------------------
 * Automated Universal Elementor Custom Fonts Provisioning Engine.
 *
 * Downloads all available weights (100-900) for requested Google Fonts into
 * wp-content/uploads/elementor/custom-fonts/<family>/, registers them as native
 * Elementor Custom Fonts (elementor_font CPT), and updates Elementor font caches.
 *
 * Usage via WP-CLI:
 *     wp eval-file helpers/custom-fonts-setup.php "Inter, Manrope, Sora" --user=admin
 * -----------------------------------------------------------------------------
 */

if (! defined('WP_CLI') || ! WP_CLI) {
    echo "This script must be run through WP-CLI (wp eval-file).\n";
    return;
}

// Ensure administrator context for unrestricted capability
if (! is_user_logged_in()) {
    $admins = get_users([
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]);
    if (! empty($admins)) {
        wp_set_current_user((int) $admins[0]);
    }
}

// 1. Resolve target font families from CLI args
$raw_input = isset($args[0]) ? trim($args[0]) : '';
if ('' === $raw_input) {
    $raw_input = 'Inter, Manrope, Sora';
}

$font_families = array_filter(array_map('trim', explode(',', $raw_input)));

if (empty($font_families)) {
    WP_CLI::error('No valid font families specified.');
    return;
}

WP_CLI::log('Configuring Elementor Custom Fonts for: ' . implode(', ', $font_families));

// 2. Activate disabler option flags
update_option('ahm_disable_google_fonts', 'yes');
update_option('elementor_load_google_fonts', 'no');
WP_CLI::log('Google Fonts disabler flags activated (ahm_disable_google_fonts = yes).');

// 3. Prepare upload directory
$upload_dir = wp_upload_dir();
$fonts_base_dir = trailingslashit($upload_dir['basedir']) . 'elementor/custom-fonts';
$fonts_base_url = trailingslashit($upload_dir['baseurl']) . 'elementor/custom-fonts';

if (! wp_mkdir_p($fonts_base_dir)) {
    WP_CLI::error("Failed to create directory: {$fonts_base_dir}");
    return;
}

$user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

$all_font_types = get_option('elementor_fonts_manager_font_types', []);
$all_elementor_fonts = get_option('elementor_fonts_manager_fonts', []);

foreach ($font_families as $family) {
    WP_CLI::log("-------------------------------------------------------------------------");
    WP_CLI::log("Processing: {$family}...");

    // Query Google Fonts CSS2 endpoint for full weight spectrum
    $css_url = 'https://fonts.googleapis.com/css2?family=' . urlencode($family) . ':wght@100;200;300;400;500;600;700;800;900&display=swap';

    $response = wp_remote_get($css_url, [
        'user-agent' => $user_agent,
        'timeout'    => 20,
    ]);

    if (is_wp_error($response)) {
        WP_CLI::warning("Failed to fetch Google Fonts CSS for '{$family}': " . $response->get_error_message());
        continue;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if (200 !== $status_code) {
        WP_CLI::warning("Google Fonts API returned HTTP {$status_code} for '{$family}'. Skipping.");
        continue;
    }

    $css_content = wp_remote_retrieve_body($response);
    if (empty($css_content)) {
        WP_CLI::warning("Empty CSS returned for '{$family}'. Skipping.");
        continue;
    }

    // Parse font-face definitions, mapping weights to woff2 URLs
    preg_match_all('/(?:\/\*\s*([a-z0-9-]+)\s*\*\/)?\s*@font-face\s*\{([^}]+)\}/i', $css_content, $matches, PREG_SET_ORDER);
    $weights_map = [];

    foreach ($matches as $m) {
        $subset = strtolower(trim($m[1] ?? ''));
        $block  = $m[2];

        if (preg_match('/font-weight:\s*(\d+)/', $block, $wm) && preg_match('/src:[^;]*url\(([^)]+\.woff2)\)/i', $block, $um)) {
            $w = (int) $wm[1];
            $remote_font_url = trim($um[1], " \t\n\r\0\x0B\"'");

            // Prefer 'latin' subset or take the first encountered block
            if (! isset($weights_map[$w]) || 'latin' === $subset) {
                $weights_map[$w] = [
                    'subset' => $subset,
                    'url'    => $remote_font_url,
                ];
            }
        }
    }

    if (empty($weights_map)) {
        WP_CLI::warning("Could not extract any .woff2 weights for '{$family}'.");
        continue;
    }

    ksort($weights_map);
    WP_CLI::log(" - Found " . count($weights_map) . " weight(s): " . implode(', ', array_keys($weights_map)));

    // Prepare family folder
    $family_slug = sanitize_title($family);
    $family_dir  = trailingslashit($fonts_base_dir) . $family_slug;
    $family_url  = trailingslashit($fonts_base_url) . $family_slug;
    wp_mkdir_p($family_dir);

    $download_cache = [];
    $font_files     = [];
    $font_face_css  = '';

    foreach ($weights_map as $weight => $data) {
        $remote_url = $data['url'];

        // Optimize downloads: if multiple weights share the same file (variable font)
        if (isset($download_cache[$remote_url])) {
            $local_url = $download_cache[$remote_url];
        } else {
            $filename   = $family_slug . '-' . $weight . '.woff2';
            $local_path = trailingslashit($family_dir) . $filename;
            $local_url  = trailingslashit($family_url) . $filename;

            if (! file_exists($local_path)) {
                $file_res = wp_remote_get($remote_url, [
                    'user-agent' => $user_agent,
                    'timeout'    => 30,
                ]);

                if (is_wp_error($file_res) || 200 !== wp_remote_retrieve_response_code($file_res)) {
                    WP_CLI::warning("Failed to download weight {$weight} from {$remote_url}");
                    continue;
                }

                $binary = wp_remote_retrieve_body($file_res);
                file_put_contents($local_path, $binary);
            }

            $download_cache[$remote_url] = $local_url;
        }

        // Elementor Pro font_face repeater entry
        $font_files[] = [
            'font_weight' => (string) $weight,
            'font_style'  => 'normal',
            'woff2'       => [
                'url' => $local_url,
                'id'  => 0,
            ],
        ];

        // Elementor @font-face string
        $font_face_css .= "@font-face {\n";
        $font_face_css .= "\tfont-family: '" . esc_attr($family) . "';\n";
        $font_face_css .= "\tfont-style: normal;\n";
        $font_face_css .= "\tfont-weight: " . (int) $weight . ";\n";
        $font_face_css .= "\tfont-display: auto;\n";
        $font_face_css .= "\tsrc: url('" . esc_url_raw($local_url) . "') format('woff2');\n";
        $font_face_css .= "}\n";
    }

    if (empty($font_files)) {
        WP_CLI::warning("No valid weights downloaded for '{$family}'. Skipping registration.");
        continue;
    }

    // 4. Create or update elementor_font post
    $existing = get_posts([
        'post_type'      => 'elementor_font',
        'title'          => $family,
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);

    $postarr = [
        'post_title'  => $family,
        'post_status' => 'publish',
        'post_type'   => 'elementor_font',
    ];

    if (! empty($existing)) {
        $postarr['ID'] = (int) $existing[0];
        $post_id       = wp_update_post($postarr, true);
        $action        = 'Updated';
    } else {
        $post_id = wp_insert_post($postarr, true);
        $action  = 'Created';
    }

    if (is_wp_error($post_id)) {
        WP_CLI::warning("Failed to save elementor_font post: " . $post_id->get_error_message());
        continue;
    }

    // Assign taxonomy term 'custom' in 'elementor_font_type'
    wp_set_object_terms($post_id, 'custom', 'elementor_font_type');

    // Update metadata required by Elementor Pro
    update_post_meta($post_id, 'elementor_font_files', $font_files);
    update_post_meta($post_id, 'elementor_font_face', $font_face_css);

    // Track for global option updates
    $all_font_types[$family] = 'custom';
    $all_elementor_fonts[$family] = [
        'font_face'   => $font_face_css,
        'font_family' => $family,
        'font_type'   => 'custom',
    ];

    WP_CLI::success("{$action} Elementor Custom Font: '{$family}' (" . count($font_files) . " weights).");
}

// 5. Invalidate & refresh Elementor font caches
update_option('elementor_fonts_manager_font_types', $all_font_types);
update_option('elementor_fonts_manager_fonts', $all_elementor_fonts);

if (class_exists('\ElementorPro\Plugin')) {
    $assets_mgr = \ElementorPro\Plugin::elementor()->assets_manager ?? null;
    if ($assets_mgr) {
        $fonts_module = $assets_mgr->get_asset('fonts');
        if ($fonts_module && method_exists($fonts_module, 'clear_fonts_list')) {
            $fonts_module->clear_fonts_list();
        }
    }
}

// Flush Elementor CSS files so front-end instantly reflects custom fonts
if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
}

WP_CLI::success('Elementor Custom Fonts setup completed successfully.');
