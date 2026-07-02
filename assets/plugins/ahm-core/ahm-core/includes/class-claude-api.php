<?php
/**
 * Class Claude_API
 * Handles communication with the Anthropic Claude API (Messages API) for
 * "AI Vision" Figma-to-Elementor conversion. Sends a Figma screenshot plus the
 * raw Figma JSON metadata and asks Claude to reconstruct the section using
 * native Elementor widgets (no custom HTML/code widgets).
 *
 * Uses the WordPress HTTP API (wp_remote_post) rather than the Anthropic PHP
 * Composer SDK, matching the plugin's other API wrappers (class-figma-api.php,
 * class-gemini-api.php) and avoiding a Composer/autoload dependency in a
 * distributable plugin.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/trait-ahm-elementor-sanitizer.php';

class Claude_API {
    use AHM_Elementor_Sanitizer;

    private string $api_key;

    /**
     * Anthropic model id. Opus 4.8 is the current most-capable model and
     * supports high-resolution vision, which this task needs.
     */
    private string $model = 'claude-opus-4-8';

    private const API_URL         = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VER   = '2023-06-01';
    private const MAX_TOKENS      = 16000;
    private const REQUEST_TIMEOUT = 180; // Claude vision + reasoning can be slow.

    public function __construct( string $api_key = '' ) {
        $this->api_key = $api_key ? $api_key : get_option( 'ahm_anthropic_api_key', '' );
    }

    /**
     * Set the Anthropic API Key.
     */
    public function set_api_key( string $api_key ): void {
        $this->api_key = $api_key;
    }

    /**
     * Generate Elementor JSON using Claude vision.
     *
     * @param string $image_url  The URL to the Figma PNG screenshot.
     * @param array  $figma_json The raw Figma JSON metadata for the node.
     * @return array|WP_Error Sanitized Elementor element list, or WP_Error.
     */
    public function generate_elementor_layout( string $image_url, array $figma_json ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'missing_api_key', __( 'Anthropic (Claude) API Key is missing.', 'ahm-core' ) );
        }

        // Fetch the Figma screenshot and convert to base64 for the vision block.
        $image_response = wp_remote_get( $image_url, [ 'timeout' => 30 ] );
        if ( is_wp_error( $image_response ) || wp_remote_retrieve_response_code( $image_response ) !== 200 ) {
            return new WP_Error( 'image_fetch_failed', __( 'Failed to fetch the Figma screenshot for Claude.', 'ahm-core' ) );
        }

        $image_body   = wp_remote_retrieve_body( $image_response );
        $media_type   = $this->normalize_media_type( wp_remote_retrieve_header( $image_response, 'content-type' ) );
        $base64_image = base64_encode( $image_body );

        $system_prompt = $this->build_system_prompt();

        $user_text  = "Recreate the attached design section as an Elementor Flexbox layout. ";
        $user_text .= "Use the screenshot as the source of visual truth and the Figma JSON below for exact text content, colors, font sizes, and spacing.\n\n";
        $user_text .= "Figma JSON metadata:\n" . wp_json_encode( $figma_json );

        $body = [
            'model'      => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $system_prompt,
            // Adaptive thinking helps Claude reason about visual structure for a
            // more accurate layout. Thinking blocks are skipped when parsing.
            'thinking'   => [ 'type' => 'adaptive' ],
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $media_type,
                                'data'       => $base64_image,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $user_text,
                        ],
                    ],
                ],
            ],
        ];

        $response = wp_remote_post( self::API_URL, [
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => self::ANTHROPIC_VER,
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => self::REQUEST_TIMEOUT,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            $error_message = $data['error']['message'] ?? __( 'Unknown Claude API Error', 'ahm-core' );
            return new WP_Error( 'claude_api_error', sprintf( __( 'Claude API Error (%d): %s', 'ahm-core' ), $code, $error_message ) );
        }

        // Safety classifiers or the model may decline the request.
        if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
            return new WP_Error( 'claude_refusal', __( 'Claude declined to generate this layout.', 'ahm-core' ) );
        }

        // Concatenate all text blocks; skip thinking blocks (adaptive thinking).
        $text_response = '';
        if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
            foreach ( $data['content'] as $block ) {
                if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
                    $text_response .= $block['text'];
                }
            }
        }

        // Strip any markdown code fences Claude may wrap the JSON in.
        $text_response = preg_replace( '/```(?:json)?/i', '', $text_response );

        $elementor_data = json_decode( trim( $text_response ), true );

        if ( ! is_array( $elementor_data ) ) {
            return new WP_Error( 'invalid_json_generation', __( 'Claude failed to output valid Elementor JSON.', 'ahm-core' ) );
        }

        $elementor_data = $this->normalize_layout( $elementor_data );

        if ( empty( $elementor_data ) ) {
            return new WP_Error( 'invalid_json_generation', __( 'Claude returned an empty or unusable layout.', 'ahm-core' ) );
        }

        return $elementor_data;
    }

    /**
     * System prompt instructing Claude to emit native-widget Elementor JSON.
     */
    private function build_system_prompt(): string {
        $prompt  = "You are an expert Elementor JSON generator. Output ONLY a valid JSON array of Elementor elements — no markdown, no explanation, no prose.\n\n";
        $prompt .= "Rules:\n";
        $prompt .= "- The root output is a JSON array (a list of top-level elements).\n";
        $prompt .= "- Every element is an object with: elType ('container' or 'widget') and settings (object).\n";
        $prompt .= "- Containers additionally have an 'elements' array (their children).\n";
        $prompt .= "- Widgets additionally have a 'widgetType' string.\n";
        $prompt .= "- Do NOT include an 'id' field; it will be generated.\n\n";
        $prompt .= "Use ONLY native Elementor widgets — never the 'html', 'shortcode', or 'code' widgets, and never raw HTML.\n";
        $prompt .= "Common widgetType values: 'heading' (settings.title, settings.header_size like h1-h6), 'text-editor' (settings.editor), ";
        $prompt .= "'button' (settings.text, settings.link.url), 'image' (settings.image.url), 'icon', 'divider', 'spacer'.\n\n";
        $prompt .= "For containers, use flex settings: flex_direction ('row'|'column'), justify_content, align_items, gap ({size,unit}), ";
        $prompt .= "padding ({top,right,bottom,left,unit}), and background via background_background='classic' + background_color.\n";
        $prompt .= "Reproduce the design as pixel-perfectly as possible: match text, colors (hex), font sizes, weights, alignment, and spacing from the Figma JSON.";
        return $prompt;
    }

    /**
     * Anthropic accepts png, jpeg, gif, and webp. Fall back to png.
     */
    private function normalize_media_type( $content_type ): string {
        $content_type = is_string( $content_type ) ? strtolower( $content_type ) : '';
        foreach ( [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ] as $allowed ) {
            if ( false !== strpos( $content_type, $allowed ) ) {
                return $allowed;
            }
        }
        return 'image/png';
    }
}
