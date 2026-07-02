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
     * Perform an authenticated GET against Figma, with one automatic retry on a
     * 429 rate-limit response when Figma's Retry-After delay is short enough to
     * wait out inside the request.
     *
     * @param string $url
     * @return array|WP_Error The wp_remote_get response array, or WP_Error.
     */
    private function remote_get( $url ) {
        $args = [
            'headers' => [ 'X-Figma-Token' => $this->token ],
            'timeout' => 30,
        ];

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
            $retry_after = $this->retry_after_seconds( $response );
            if ( $retry_after <= 0 ) {
                $retry_after = 5;
            }

            // Don't block a web request for a long cooldown — let the caller
            // surface a friendly "try again in N seconds" message instead.
            if ( $retry_after > 15 ) {
                return $response;
            }

            sleep( $retry_after );
            $retry = wp_remote_get( $url, $args );
            if ( ! is_wp_error( $retry ) ) {
                $response = $retry;
            }
        }

        return $response;
    }

    /**
     * Build a WP_Error for a non-200 Figma response, with a clearer message for
     * rate limiting (429) that includes the reset time from Figma's Retry-After
     * header when available.
     *
     * @param array $response The wp_remote_get response array.
     * @return WP_Error
     */
    private function status_error( $response ) {
        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 429 === $code ) {
            $time_format = get_option( 'time_format', 'g:i a' );
            $seconds     = $this->retry_after_seconds( $response );

            if ( $seconds > 0 ) {
                // Exact reset time from Figma's Retry-After header.
                $reset_time = wp_date( $time_format, time() + $seconds );

                return new WP_Error(
                    'figma_rate_limited',
                    sprintf(
                        /* translators: 1: seconds to wait, 2: reset clock time */
                        __( 'Figma rate limit reached (429). Try again in about %1$d seconds — next reset around %2$s. Successful responses are cached for an hour, so re-running the same import will not re-hit the Figma API.', 'ahm-core' ),
                        $seconds,
                        $reset_time
                    )
                );
            }

            // Figma sent no Retry-After header — fall back to an estimated
            // reset one minute out so the alert still shows a time to aim for.
            $estimated_seconds = MINUTE_IN_SECONDS;
            $reset_time        = wp_date( $time_format, time() + $estimated_seconds );

            return new WP_Error(
                'figma_rate_limited',
                sprintf(
                    /* translators: %s: estimated reset clock time */
                    __( 'Figma rate limit reached (429). Figma did not report an exact reset time — estimated next reset around %s (about a minute). Successful responses are cached for an hour, so re-running the same import will not re-hit the Figma API.', 'ahm-core' ),
                    $reset_time
                )
            );
        }

        return new WP_Error( 'figma_api_error', sprintf( __( 'Figma API returned status code %d', 'ahm-core' ), $code ) );
    }

    /**
     * Parse Figma's Retry-After header into seconds. The header is usually an
     * integer number of seconds, but the HTTP spec also allows an HTTP-date.
     *
     * @param array $response
     * @return int Seconds until reset (0 if unknown).
     */
    private function retry_after_seconds( $response ) {
        $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

        if ( is_numeric( $retry_after ) ) {
            return max( 0, (int) $retry_after );
        }

        if ( ! empty( $retry_after ) ) {
            $timestamp = strtotime( (string) $retry_after );
            if ( $timestamp ) {
                return max( 0, $timestamp - time() );
            }
        }

        return 0;
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

        $cache_key = 'ahm_figma_file_' . md5( $url );
        $cached_data = get_transient( $cache_key );
        if ( false !== $cached_data ) {
            return $cached_data;
        }

        $response = $this->remote_get( $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return $this->status_error( $response );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_json', __( 'Failed to parse JSON response from Figma.', 'ahm-core' ) );
        }

        set_transient( $cache_key, $data, HOUR_IN_SECONDS );

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

        $cache_key = 'ahm_figma_img_' . md5( $url );
        $cached_data = get_transient( $cache_key );
        if ( false !== $cached_data ) {
            return $cached_data;
        }

        $response = $this->remote_get( $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return $this->status_error( $response );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data['images'] ) ) {
            return new WP_Error( 'invalid_json', __( 'Failed to parse image URLs from Figma API.', 'ahm-core' ) );
        }

        set_transient( $cache_key, $data['images'], HOUR_IN_SECONDS );

        return $data['images']; // Returns an associative array mapping node ID to image URL
    }
}
