<?php
/**
 * Uninstall handler — runs when AHM Core is deleted via WP admin.
 *
 * Removes plugin options and postmeta.
 * Does NOT delete converted WebP files on disk.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ahm_webp_settings');

global $wpdb;

$wpdb->delete(
    $wpdb->postmeta,
    ['meta_key' => '_ahm_webp_file'],
    ['%s']
);
