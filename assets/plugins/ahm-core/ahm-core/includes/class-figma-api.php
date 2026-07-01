<?php
/**
 * Class Figma_API
 * Handles all requests to the Figma REST API.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Figma_API {
    private $token;

    public function __construct( $token = '' ) {
        $this->token = $token ? $token : get_option( 'figma_to_elementor_pat', '' );
    }

    /**
     * Set the Personal Access Token.
     */
    public function set_token( $token ) {
        $this->token = $token;
    }

    /**
     * Get the Figma file JSON structure.
     *
     * @param string $file_key
     * @param string $node_id Optional. Specific node ID to fetch.
     * @return array|WP_Error
     */
    public function get_file( $file_key, $node_id = '' ) {
        if ( empty( $this->token ) ) {
            return new WP_Error( 'missing_token', __( 'Figma Personal Access Token is missing.', 'ahm-core' ) );
        }

        $url = 'https://api.figma.com/v1/files/' . $file_key;
        if ( ! empty( $node_id ) ) {
            $url = add_query_arg( [ 'ids' => $node_id ], $url );
        }

        $response = wp_remote_get( $url, [
            'headers' => [
                'X-Figma-Token' => $this->token,
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'figma_api_error', sprintf( __( 'Figma API returned status code %d', 'ahm-core' ), $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_json', __( 'Failed to parse JSON response from Figma.', 'ahm-core' ) );
        }

        return $data;
    }

    /**
     * Get image asset download URLs for specific node IDs.
     *
     * @param string $file_key
     * @param array $node_ids
     * @return array|WP_Error
     */
    public function get_image_urls( $file_key, $node_ids ) {
        if ( empty( $this->token ) ) {
            return new WP_Error( 'missing_token', __( 'Figma Personal Access Token is missing.', 'ahm-core' ) );
        }

        if ( empty( $node_ids ) ) {
            return [];
        }

        // Figma API expects comma-separated node IDs
        $ids_string = implode( ',', $node_ids );
        $url = add_query_arg( [
            'ids' => $ids_string,
            'format' => 'png',
        ], 'https://api.figma.com/v1/images/' . $file_key );

        $response = wp_remote_get( $url, [
            'headers' => [
                'X-Figma-Token' => $this->token,
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'figma_api_error', sprintf( __( 'Figma API returned status code %d', 'ahm-core' ), $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data['images'] ) ) {
            return new WP_Error( 'invalid_json', __( 'Failed to parse image URLs from Figma API.', 'ahm-core' ) );
        }

        return $data['images']; // Returns an associative array mapping node ID to image URL
    }
}
