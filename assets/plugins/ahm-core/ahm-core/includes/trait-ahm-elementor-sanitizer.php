<?php
/**
 * Trait AHM_Elementor_Sanitizer
 *
 * Shared normalization for AI-generated (Gemini / Claude) layout JSON. Both AI
 * providers return loosely-shaped JSON; this turns it into the exact element
 * shape Elementor expects, matching what the deterministic Figma_Parser
 * produces. Kept in one place so the two providers can't drift apart.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait AHM_Elementor_Sanitizer {

    /**
     * Normalize a decoded AI JSON response into a clean Elementor element list.
     *
     * Accepts the shapes models commonly return — a top-level list of elements,
     * a single element object, or a wrapper like {"elements": [...]} — then runs
     * the recursive sanitizer.
     *
     * @param array $decoded Decoded JSON from the model.
     * @return array Sanitized Elementor element list (may be empty).
     */
    protected function normalize_layout( array $decoded ): array {
        if ( isset( $decoded['elements'] ) && is_array( $decoded['elements'] ) && ! isset( $decoded['elType'] ) ) {
            $decoded = $decoded['elements'];
        } elseif ( isset( $decoded['elType'] ) || isset( $decoded['widgetType'] ) ) {
            $decoded = [ $decoded ];
        }

        return $this->sanitize_elementor_tree( $decoded );
    }

    /**
     * Recursively normalize a raw element list into the exact shape Elementor
     * expects: a fresh unique id, a valid elType, a settings array, and the
     * correct child key (elements for containers, widgetType for widgets).
     *
     * @param array $elements Raw element list from the model.
     * @return array Sanitized Elementor element list.
     */
    protected function sanitize_elementor_tree( array $elements ): array {
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

            // Always regenerate IDs — model-supplied ids are unreliable and may collide.
            $node = [
                'id'       => wp_generate_uuid4(),
                'elType'   => $el_type,
                'settings' => ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : [],
            ];

            if ( 'widget' === $el_type ) {
                $node['widgetType'] = ! empty( $element['widgetType'] ) ? sanitize_key( $element['widgetType'] ) : 'heading';
            } else {
                // Accept either 'elements' (Elementor) or 'children' (common model slip).
                $children         = $element['elements'] ?? $element['children'] ?? [];
                $node['elements'] = is_array( $children ) ? $this->sanitize_elementor_tree( $children ) : [];
            }

            $clean[] = $node;
        }

        return $clean;
    }
}
