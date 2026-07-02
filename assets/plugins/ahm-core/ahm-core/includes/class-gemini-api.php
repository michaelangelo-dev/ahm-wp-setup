<?php
/**
 * Class Gemini_API
 * Handles communication with the Google Gemini API (Generative Language API).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Gemini_API {
    private string $api_key;
    // Ordered by preference: highest fidelity first, cheaper/broader models as
    // fallbacks. The request loop breaks on the first model that returns 200,
    // so an unavailable model (404/403) falls through to the next.
    // 'gemini-pro-latest' auto-tracks Google's current Pro release, so the chain
    // keeps working even as preview models rotate. All entries here support
    // multimodal (image) input, JSON mode, and a 65K output cap.
    private array $models = [
        'gemini-3.1-pro-preview',
        'gemini-pro-latest',
        'gemini-2.5-pro',
        'gemini-2.5-flash',
    ];

    public function __construct( string $api_key = '' ) {
        $this->api_key = $api_key ? $api_key : get_option( 'ahm_gemini_api_key', '' );
    }

    /**
     * Set the Gemini API Key.
     */
    public function set_api_key( string $api_key ): void {
        $this->api_key = $api_key;
    }

    /**
     * Generate Elementor JSON using Gemini Vision.
     *
     * @param string $image_url The URL to the Figma PNG screenshot.
     * @param array $figma_json The raw Figma JSON metadata.
     * @return array|WP_Error Parsed Elementor JSON array.
     */
    public function generate_elementor_layout( string $image_url, array $figma_json ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_api_key', __( 'Gemini API Key is missing.', 'ahm-core' ) );
        }

        // Fetch image and convert to base64
        $image_response = wp_remote_get( $image_url, ['timeout' => 30] );
        if ( is_wp_error( $image_response ) || wp_remote_retrieve_response_code( $image_response ) !== 200 ) {
            return new WP_Error( 'image_fetch_failed', __( 'Failed to fetch the Figma screenshot for Gemini.', 'ahm-core' ) );
        }

        $image_body = wp_remote_retrieve_body( $image_response );
        $mime_type = wp_remote_retrieve_header( $image_response, 'content-type' );
        if ( empty( $mime_type ) ) {
            $mime_type = 'image/png';
        }
        $base64_image = base64_encode( $image_body );

        $prompt = "You are an expert Elementor JSON generator. Your task is to output ONLY valid JSON representing an Elementor Flexbox Container layout. Do not include markdown formatting or any other text.\n\n";
        $prompt .= "I am providing you with a screenshot of a web design section, along with the raw Figma JSON metadata for this specific section.\n";
        $prompt .= "Your job is to recreate the design perfectly in Elementor's native JSON format based on the screenshot, while referencing the Figma JSON for exact text, colors, and typography values.\n\n";
        $prompt .= "Guidelines:\n";
        $prompt .= "- Use 'container' for elType and 'widget' for child elements (heading, image, button).\n";
        $prompt .= "- Use standard Elementor settings (flex_direction, justify_content, align_items, gap, padding, background_color, etc).\n";
        $prompt .= "- The root element must be a valid Elementor data array (a list of top level elements).\n\n";
        $prompt .= "Figma JSON Data:\n" . wp_json_encode( $figma_json );

        $body = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ],
                        [
                            'inlineData' => [
                                'mimeType' => $mime_type,
                                'data' => $base64_image
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1, // Low temp for deterministic JSON
                'responseMimeType' => 'application/json'
            ]
        ];

        $last_error = null;
        $data = null;
        $code = 0;

        // Try each model until one succeeds
        foreach ( $this->models as $model_name ) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model_name . ':generateContent?key=' . $this->api_key;

            $response = wp_remote_post( $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode( $body ),
                'timeout' => 60, // Give Gemini time to process
            ] );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );
            $data = json_decode( $response_body, true );

            if ( 200 === $code ) {
                $last_error = null; // Success!
                break;
            } else {
                $error_message = $data['error']['message'] ?? 'Unknown Gemini API Error';
                $last_error = new WP_Error( 'gemini_api_error', sprintf( __( 'Gemini API Error (%d) on %s: %s', 'ahm-core' ), $code, $model_name, $error_message ) );
                // Continue to next model on 404/403
            }
        }

        if ( $last_error ) {
            return $last_error;
        }

        // Concatenate answer text parts; skip "thought" parts (thinking models).
        $text_response = '';
        if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
            foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
                if ( ! empty( $part['thought'] ) ) {
                    continue;
                }
                if ( isset( $part['text'] ) ) {
                    $text_response .= $part['text'];
                }
            }
        }

        // Sometimes the API wraps in ```json ... ``` despite responseMimeType
        $text_response = preg_replace('/```json/i', '', $text_response);
        $text_response = preg_replace('/```/', '', $text_response);
        
        $elementor_data = json_decode( trim( $text_response ), true );

        if ( ! is_array( $elementor_data ) ) {
            return new WP_Error( 'invalid_json_generation', __( 'Gemini failed to output valid Elementor JSON.', 'ahm-core' ) );
        }

        // Gemini may return the layout in a few shapes: a top-level list of elements,
        // a single element object, or a wrapper like {"elements": [...]}. Normalize to a list.
        if ( isset( $elementor_data['elements'] ) && is_array( $elementor_data['elements'] ) && ! isset( $elementor_data['elType'] ) ) {
            $elementor_data = $elementor_data['elements'];
        } elseif ( isset( $elementor_data['elType'] ) || isset( $elementor_data['widgetType'] ) ) {
            $elementor_data = [ $elementor_data ];
        }

        $elementor_data = $this->sanitize_elementor_tree( $elementor_data );

        if ( empty( $elementor_data ) ) {
            return new WP_Error( 'invalid_json_generation', __( 'Gemini returned an empty or unusable layout.', 'ahm-core' ) );
        }

        return $elementor_data;
    }

    /**
     * Recursively normalize Gemini output into the exact element shape Elementor expects.
     *
     * Guarantees every element has a fresh unique id, a valid elType, a settings array,
     * and the correct child key (elements for containers, widgetType for widgets) — matching
     * what the deterministic Figma_Parser produces so both import modes yield renderable pages.
     *
     * @param array $elements Raw element list from Gemini.
     * @return array Sanitized Elementor element list.
     */
    private function sanitize_elementor_tree( array $elements ): array {
        $clean = [];

        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }

            // Infer type: explicit elType wins, otherwise presence of widgetType implies a widget.
            $el_type = $element['elType'] ?? ( isset( $element['widgetType'] ) ? 'widget' : 'container' );
            if ( ! in_array( $el_type, [ 'container', 'widget' ], true ) ) {
                $el_type = isset( $element['widgetType'] ) ? 'widget' : 'container';
            }

            // Always regenerate IDs — Gemini's are unreliable and may collide.
            $node = [
                'id'       => wp_generate_uuid4(),
                'elType'   => $el_type,
                'settings' => ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : [],
            ];

            if ( 'widget' === $el_type ) {
                $node['widgetType'] = ! empty( $element['widgetType'] ) ? sanitize_key( $element['widgetType'] ) : 'heading';
            } else {
                // Accept either 'elements' (Elementor) or 'children' (common Gemini slip).
                $children = $element['elements'] ?? $element['children'] ?? [];
                $node['elements'] = is_array( $children ) ? $this->sanitize_elementor_tree( $children ) : [];
            }

            $clean[] = $node;
        }

        return $clean;
    }
}
