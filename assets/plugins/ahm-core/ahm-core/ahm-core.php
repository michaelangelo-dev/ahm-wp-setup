<?php
/**
 * Plugin Name:       AHM Core
 * Description:       Core functionality for AHM sites. Protected from deactivation/deletion via the admin UI. Provides "Quick User Create" and "Image Converter" (auto WebP) tools.
 * Version:           2.0.0
 * Author:            AHM
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       ahm-core
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/*--------------------------------------------------------------
 * 1. Constants
 *------------------------------------------------------------*/
define('AHM_CORE_VERSION', '2.0.0');
define('AHM_CORE_FILE', __FILE__);
define('AHM_CORE_DIR', plugin_dir_path(__FILE__));
define('AHM_CORE_URL', plugin_dir_url(__FILE__));
define('AHM_CORE_BASENAME', plugin_basename(__FILE__));

/*--------------------------------------------------------------
 * 2. Includes
 *------------------------------------------------------------*/
require_once AHM_CORE_DIR . 'includes/class-ahm-admin.php';
require_once AHM_CORE_DIR . 'includes/class-ahm-quick-user.php';
require_once AHM_CORE_DIR . 'includes/class-ahm-webp-converter.php';
require_once AHM_CORE_DIR . 'includes/class-ahm-webp-admin.php';
require_once AHM_CORE_DIR . 'includes/class-ahm-webp-frontend.php';

/*--------------------------------------------------------------
 * 3. Plugin Protection (preserved from v1)
 *    Prevents deactivation / deletion via the admin UI.
 *------------------------------------------------------------*/

// Remove "Deactivate" and "Delete" action links.
add_filter('plugin_action_links_' . AHM_CORE_BASENAME, function (array $actions): array {
    unset($actions['deactivate'], $actions['delete']);
    $actions['ahm_protected'] = '<span style="color:#888;cursor:default;">'
        . esc_html__('Protected', 'ahm-core') . '</span>';
    return $actions;
});

// Force-keep in the active plugins list.
add_filter('pre_update_option_active_plugins', function (mixed $plugins): mixed {
    if (is_array($plugins) && ! in_array(AHM_CORE_BASENAME, $plugins, true)) {
        $plugins[] = AHM_CORE_BASENAME;
    }
    return $plugins;
});

// Hide the bulk-action checkbox row.
add_action('admin_head-plugins.php', function (): void {
    echo '<style>tr[data-plugin="' . esc_attr(AHM_CORE_BASENAME) . '"] th.check-column input{display:none}</style>';
});

/*--------------------------------------------------------------
 * 4. Activation
 *------------------------------------------------------------*/
register_activation_hook(__FILE__, function (): void {
    // Default WebP settings.
    if (false === get_option('ahm_webp_settings')) {
        update_option('ahm_webp_settings', [
            'quality'         => 80,
            'delete_original' => false,
            'auto_convert'    => true,
            'serve_webp'      => true,
            'serve_webp_css'  => true,
        ]);
    }

    // Verify WebP server capability.
    if (! AHM_WebP_Converter::server_supports_webp()) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-warning"><p>';
            esc_html_e(
                'AHM Core: Image Converter requires GD (with WebP) or Imagick. The tab will be available once a supported library is installed.',
                'ahm-core'
            );
            echo '</p></div>';
        });
    }
});

/*--------------------------------------------------------------
 * 5. Initialization
 *------------------------------------------------------------*/
add_action('plugins_loaded', function (): void {
    AHM_Admin::get_instance();
    AHM_Quick_User::get_instance();
    AHM_WebP_Admin::get_instance();
    AHM_WebP_Frontend::get_instance();
});
