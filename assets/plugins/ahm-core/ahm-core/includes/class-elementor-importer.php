<?php
/**
 * Class Elementor_Importer
 * Imports generated Elementor layouts and uploads image assets.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Elementor_Importer {

    /**
     * Download an image URL and sideload it into the WordPress Media Library.
     *
     * @param string $url The remote image URL.
     * @param string $desc Optional description.
     * @return array|WP_Error Array with url and id, or WP_Error.
     */
    public function sideload_image( $url, $desc = '' ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Download to temporary file
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $filename = basename( parse_url( $url, PHP_URL_PATH ) );
        if ( strpos( $filename, '.' ) === false ) {
            $filename .= '.png';
        }

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        // Sideload the file into media library
        $id = media_handle_sideload( $file_array, 0, $desc );

        if ( is_wp_error( $id ) ) {
            @unlink( $tmp );
            return $id;
        }

        $attachment_url = wp_get_attachment_url( $id );
        return [
            'id' => $id,
            'url' => $attachment_url,
        ];
    }

    /**
     * Import layout data into a WordPress page.
     *
     * @param array $elementor_data Array of Elementor container elements.
     * @param string $title Post title.
     * @param string $post_type Target post type (e.g. 'page' or 'post').
     * @param string $page_template Page template slug (e.g. 'default', 'elementor_canvas', 'elementor_header_footer').
     * @return int|WP_Error Post ID if successful, or WP_Error.
     */
    public function import_layout( $elementor_data, $title = 'Figma Import', $post_type = 'page', $page_template = 'default' ) {
        // Double check Elementor is loaded
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return new WP_Error( 'elementor_not_found', __( 'Elementor is not active.', 'ahm-core' ) );
        }

        // Create new post
        $post_id = wp_insert_post( [
            'post_title' => $title,
            'post_status' => 'draft',
            'post_type' => $post_type,
        ] );

        if ( is_wp_error( $post_id ) || 0 === $post_id ) {
            return new WP_Error( 'post_creation_failed', __( 'Failed to create import page.', 'ahm-core' ) );
        }

        // Save Elementor settings to post meta
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

        if ( 'default' !== $page_template ) {
            update_post_meta( $post_id, '_wp_page_template', $page_template );
        }

        // Elementor wants a JSON encoded string of data
        // We need to double check how slashes are handled. Elementor prefers slashed json string.
        $json_data = json_encode( $elementor_data );
        update_post_meta( $post_id, '_elementor_data', wp_slash( $json_data ) );

        return $post_id;
    }

    /**
     * Update Elementor Global Site Settings (Active Kit).
     *
     * @param int $content_width Default content width.
     * @param bool $zero_padding Whether to set default padding to 0px.
     * @param string $default_layout Default page layout template.
     */
    public function update_global_settings( $content_width, $zero_padding, $default_layout ) {
        $kit_id = get_option( 'elementor_active_kit' );
        if ( ! $kit_id ) {
            return;
        }

        $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        // Content Width
        if ( $content_width > 0 ) {
            $settings['container_width'] = [
                'unit' => 'px',
                'size' => $content_width,
                'sizes' => [],
            ];
        }

        // Container Padding
        if ( $zero_padding ) {
            $settings['container_padding'] = [
                'unit' => 'px',
                'top' => '0',
                'right' => '0',
                'bottom' => '0',
                'left' => '0',
                'isLinked' => true,
            ];
        }

        // Default Page Layout
        if ( in_array( $default_layout, [ 'default', 'elementor_canvas', 'elementor_header_footer' ] ) ) {
            $settings['default_page_layout'] = $default_layout;
        }

        update_post_meta( $kit_id, '_elementor_page_settings', $settings );
        
        // Clear Elementor CSS cache so styles regenerate
        if ( class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::$instance->posts_css_manager->clear_cache();
        }
    }
}
