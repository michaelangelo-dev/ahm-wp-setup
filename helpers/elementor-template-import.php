<?php
/**
 * Elementor Template Import Helper
 * Executed via WP-CLI: wp eval-file elementor-template-import.php <path-to-json> --user=admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    WP_CLI::error( 'Run this via: wp eval-file elementor-template-import.php <path> --user=admin' );
}

$json_file = $args[0] ?? '';

if ( ! file_exists( $json_file ) ) {
    WP_CLI::error( "Template JSON file not found: {$json_file}" );
}

$raw_json = file_get_contents( $json_file );
$data = json_decode( $raw_json, true );

if ( ! $data || ! isset( $data['content'] ) || ! isset( $data['type'] ) ) {
    WP_CLI::error( "Invalid Elementor template JSON." );
}

$title = $data['title'] ?? 'Imported Template';
$type = $data['type']; // e.g. 'loop-item', 'page', 'section'
$content = wp_slash( json_encode( $data['content'] ) );
$page_settings = isset( $data['page_settings'] ) ? $data['page_settings'] : [];

// Check if already exists (by title and type)
$existing = get_posts( [
    'post_type'   => 'elementor_library',
    'title'       => $title,
    'post_status' => 'any',
    'meta_query'  => [
        [
            'key'   => '_elementor_template_type',
            'value' => $type,
        ]
    ]
] );

if ( $existing ) {
    WP_CLI::success( "Template '{$title}' already exists. Skipping." );
    return;
}

$post_id = wp_insert_post( [
    'post_title'  => $title,
    'post_status' => 'publish',
    'post_type'   => 'elementor_library',
] );

if ( is_wp_error( $post_id ) ) {
    WP_CLI::error( "Failed to insert template post." );
}

update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $post_id, '_elementor_template_type', $type );
update_post_meta( $post_id, '_elementor_data', $content );

if ( ! empty( $page_settings ) ) {
    update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
}

if ( defined( 'ELEMENTOR_VERSION' ) ) {
    update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
}

// Elementor also stores the type in the 'elementor_library_type' taxonomy
wp_set_object_terms( $post_id, $type, 'elementor_library_type' );

WP_CLI::success( "Successfully imported Elementor {$type}: {$title}" );
