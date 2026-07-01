<?php
/**
 * Class Figma_Parser
 * Parses Figma JSON tree into Elementor layout JSON structure.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Figma_Parser {
    private $image_map = [];

    /**
     * Set image mapping (Node ID => WP attachment URL).
     */
    public function set_image_map( $image_map ) {
        $this->image_map = $image_map;
    }

    /**
     * Parse the Figma node tree.
     *
     * @param array $figma_data Raw JSON file data from Figma API
     * @return array Elementor array structure
     */
    public function parse( $figma_data ) {
        if ( ! isset( $figma_data['document']['children'] ) ) {
            return [];
        }

        $elementor_data = [];

        // Search for Top-Level Frames in the first Page
        $first_page = $figma_data['document']['children'][0] ?? null;
        if ( ! $first_page || ! isset( $first_page['children'] ) ) {
            return [];
        }

        foreach ( $first_page['children'] as $child ) {
            // Only convert root-level frames (pages or desktop sections)
            if ( $child['type'] === 'FRAME' ) {
                $container = $this->parse_node( $child, null, true );
                if ( $container ) {
                    $elementor_data[] = $container;
                }
            }
        }

        return $elementor_data;
    }

    /**
     * Parse a single Figma node and recursively parse children.
     *
     * @param array $node
     * @param array|null $parent_box Bounding box of the parent frame
     * @param bool $is_parent_auto_layout Whether the parent uses Auto Layout
     * @return array|null Elementor element representation
     */
    private function parse_node( $node, $parent_box = null, $is_parent_auto_layout = true ) {
        // Check if node is visible
        if ( isset( $node['visible'] ) && ! $node['visible'] ) {
            return null;
        }

        $el_type = 'widget';
        $widget_type = '';
        $settings = [];
        $elements = [];

        // Is this node an Auto Layout frame/container?
        $is_auto_layout = isset( $node['layoutMode'] ) && $node['layoutMode'] !== 'NONE';
        $current_box = $node['absoluteBoundingBox'] ?? null;

        // Determine type of node
        switch ( $node['type'] ) {
            case 'FRAME':
            case 'GROUP':
            case 'SECTION':
            case 'COMPONENT':
            case 'COMPONENT_SET':
                $el_type = 'container';
                $settings = $this->get_container_settings( $node );

                // Recursively process children
                if ( isset( $node['children'] ) ) {
                    foreach ( $node['children'] as $child ) {
                        // Pass current box and layout style down
                        $parsed_child = $this->parse_node( $child, $current_box, $is_auto_layout );
                        if ( $parsed_child ) {
                            $elements[] = $parsed_child;
                        }
                    }
                }
                break;

            case 'TEXT':
                $el_type = 'widget';
                $widget_type = 'heading'; // Use Heading as standard text element in Elementor
                $settings = $this->get_text_settings( $node );
                break;

            case 'RECTANGLE':
            case 'VECTOR':
            case 'ELLIPSE':
            case 'REGULAR_POLYGON':
            case 'STAR':
            case 'LINE':
            case 'BOOLEAN_OPERATION':
                if ( $this->is_image_node( $node ) ) {
                    $el_type = 'widget';
                    $widget_type = 'image';
                    $settings = $this->get_image_settings( $node );
                } else {
                    // Convert normal shape to a Container
                    $el_type = 'container';
                    $settings = $this->get_shape_container_settings( $node );
                }
                break;

            default:
                return null; // Skip unsupported nodes
        }

        // Fallback positioning: If the parent was NOT an Auto Layout frame, position absolutely
        if ( ! $is_parent_auto_layout && $parent_box && $current_box ) {
            $rel_x = $current_box['x'] - $parent_box['x'];
            $rel_y = $current_box['y'] - $parent_box['y'];

            $settings['_position'] = 'absolute';
            
            $settings['_offset_x'] = [
                'unit' => 'px',
                'size' => $rel_x,
                'sizes' => [],
            ];
            $settings['_offset_y'] = [
                'unit' => 'px',
                'size' => $rel_y,
                'sizes' => [],
            ];
            
            $settings['width'] = 'custom';
            $settings['custom_width'] = [
                'unit' => 'px',
                'size' => $current_box['width'],
                'sizes' => [],
            ];
        }

        // Setup the Elementor item payload
        $element = [
            'id' => wp_generate_uuid4(),
            'elType' => $el_type,
            'settings' => $settings,
        ];

        if ( $el_type === 'container' ) {
            $element['elements'] = $elements;
        } else {
            $element['widgetType'] = $widget_type;
        }

        return $element;
    }

    /**
     * Map Figma Frame layout properties to Elementor Container styles.
     */
    private function get_container_settings( $node ) {
        $settings = [
            'flex_direction' => 'column', // default
            'background_background' => 'classic',
        ];

        // 1. Auto Layout mapping
        if ( isset( $node['layoutMode'] ) ) {
            $is_horizontal = $node['layoutMode'] === 'HORIZONTAL';
            $settings['flex_direction'] = $is_horizontal ? 'row' : 'column';

            // Alignment mapping
            if ( isset( $node['primaryAxisAlignItems'] ) ) {
                $settings['justify_content'] = $this->map_align_items( $node['primaryAxisAlignItems'], $is_horizontal );
            }
            if ( isset( $node['counterAxisAlignItems'] ) ) {
                $settings['align_items'] = $this->map_align_items( $node['counterAxisAlignItems'], !$is_horizontal );
            }

            // Wrap mapping
            if ( isset( $node['layoutWrap'] ) && $node['layoutWrap'] === 'WRAP' ) {
                $settings['flex_wrap'] = 'wrap';
            } else {
                $settings['flex_wrap'] = 'nowrap';
            }
        }

        // 2. Background solid colors
        if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
            foreach ( $node['fills'] as $fill ) {
                if ( $fill['type'] === 'SOLID' && isset( $fill['color'] ) ) {
                    $color = $fill['color'];
                    $r = round( $color['r'] * 255 );
                    $g = round( $color['g'] * 255 );
                    $b = round( $color['b'] * 255 );
                    $a = $fill['opacity'] ?? $color['a'] ?? 1;
                    $settings['background_color'] = sprintf( 'rgba(%d, %d, %d, %f)', $r, $g, $b, $a );
                    break;
                }
            }
        }

        // 3. Border radius
        if ( isset( $node['cornerRadius'] ) || isset( $node['rectangleCornerRadii'] ) ) {
            $radii = $node['rectangleCornerRadii'] ?? array_fill( 0, 4, $node['cornerRadius'] ?? 0 );
            $settings['border_radius'] = [
                'unit' => 'px',
                'top' => $radii[0] ?? 0,
                'right' => $radii[1] ?? 0,
                'bottom' => $radii[2] ?? 0,
                'left' => $radii[3] ?? 0,
                'isLinked' => false,
            ];
        }

        // 4. Border / Strokes
        if ( isset( $node['strokes'] ) && ! empty( $node['strokes'] ) ) {
            $stroke = $node['strokes'][0];
            if ( $stroke['type'] === 'SOLID' && isset( $stroke['color'] ) ) {
                $color = $stroke['color'];
                $r = round( $color['r'] * 255 );
                $g = round( $color['g'] * 255 );
                $b = round( $color['b'] * 255 );
                $a = $stroke['opacity'] ?? $color['a'] ?? 1;

                $settings['border_border'] = 'solid';
                $settings['border_color'] = sprintf( 'rgba(%d, %d, %d, %f)', $r, $g, $b, $a );
                
                $weight = $node['strokeWeight'] ?? 1;
                $settings['border_width'] = [
                    'unit' => 'px',
                    'top' => $weight,
                    'right' => $weight,
                    'bottom' => $weight,
                    'left' => $weight,
                    'isLinked' => true,
                ];
            }
        }

        // 5. Paddings
        $top = $node['paddingTop'] ?? 0;
        $right = $node['paddingRight'] ?? 0;
        $bottom = $node['paddingBottom'] ?? 0;
        $left = $node['paddingLeft'] ?? 0;

        if ( $top || $right || $bottom || $left ) {
            $settings['padding'] = [
                'unit' => 'px',
                'top' => $top,
                'right' => $right,
                'bottom' => $bottom,
                'left' => $left,
                'isLinked' => false,
            ];
        }

        // 6. Item Gaps
        if ( isset( $node['itemSpacing'] ) && $node['itemSpacing'] > 0 ) {
            $settings['gap'] = [
                'unit' => 'px',
                'size' => $node['itemSpacing'],
                'sizes' => [],
            ];
        }

        // 7. Sizing (Min Height for Elementor containers instead of height)
        if ( isset( $node['absoluteBoundingBox'] ) ) {
            $box = $node['absoluteBoundingBox'];
            if ( isset( $box['width'] ) && $box['width'] > 0 ) {
                $settings['width'] = [
                    'unit' => 'px',
                    'size' => $box['width'],
                ];
            }
            if ( isset( $box['height'] ) && $box['height'] > 0 ) {
                $settings['min_height'] = [
                    'unit' => 'px',
                    'size' => $box['height'],
                    'sizes' => [],
                ];
            }
        }

        return $settings;
    }

    /**
     * Get settings for non-frame shapes (rectangles, ellipses) as a Box Container.
     */
    private function get_shape_container_settings( $node ) {
        $settings = [
            'background_background' => 'classic',
        ];

        // 1. Color fills
        if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
            foreach ( $node['fills'] as $fill ) {
                if ( $fill['type'] === 'SOLID' && isset( $fill['color'] ) ) {
                    $color = $fill['color'];
                    $r = round( $color['r'] * 255 );
                    $g = round( $color['g'] * 255 );
                    $b = round( $color['b'] * 255 );
                    $a = $fill['opacity'] ?? $color['a'] ?? 1;
                    $settings['background_color'] = sprintf( 'rgba(%d, %d, %d, %f)', $r, $g, $b, $a );
                    break;
                }
            }
        }

        // 2. Corner Radius
        if ( $node['type'] === 'ELLIPSE' ) {
            $settings['border_radius'] = [
                'unit' => '%',
                'top' => 50,
                'right' => 50,
                'bottom' => 50,
                'left' => 50,
                'isLinked' => true,
            ];
        } elseif ( isset( $node['cornerRadius'] ) || isset( $node['rectangleCornerRadii'] ) ) {
            $radii = $node['rectangleCornerRadii'] ?? array_fill( 0, 4, $node['cornerRadius'] ?? 0 );
            $settings['border_radius'] = [
                'unit' => 'px',
                'top' => $radii[0] ?? 0,
                'right' => $radii[1] ?? 0,
                'bottom' => $radii[2] ?? 0,
                'left' => $radii[3] ?? 0,
                'isLinked' => false,
            ];
        }

        // 3. Width and Height
        if ( isset( $node['absoluteBoundingBox'] ) ) {
            $box = $node['absoluteBoundingBox'];
            $settings['width'] = [
                'unit' => 'px',
                'size' => $box['width'],
            ];
            $settings['min_height'] = [
                'unit' => 'px',
                'size' => $box['height'],
                'sizes' => [],
            ];
        }

        return $settings;
    }

    /**
     * Map Figma Text styles to Elementor Heading/Text settings.
     */
    private function get_text_settings( $node ) {
        $settings = [
            'title' => $node['characters'] ?? '',
            'header_size' => 'div', // Render as <div> to avoid breaking page heading structure
        ];

        // Typography
        if ( isset( $node['style'] ) ) {
            $style = $node['style'];
            if ( isset( $style['fontFamily'] ) ) {
                $settings['typography_font_family'] = $style['fontFamily'];
            }
            if ( isset( $style['fontSize'] ) ) {
                $settings['typography_font_size'] = [
                    'unit' => 'px',
                    'size' => $style['fontSize'],
                ];
            }
            if ( isset( $style['fontWeight'] ) ) {
                $settings['typography_font_weight'] = (string) $style['fontWeight'];
            }
            if ( isset( $style['lineHeightPx'] ) ) {
                $settings['typography_line_height'] = [
                    'unit' => 'px',
                    'size' => $style['lineHeightPx'],
                ];
            }
            if ( isset( $style['italic'] ) && $style['italic'] ) {
                $settings['typography_font_style'] = 'italic';
            }
            if ( isset( $style['textCase'] ) ) {
                $settings['typography_text_transform'] = $this->map_text_case( $style['textCase'] );
            }
            if ( isset( $style['textAlignHorizontal'] ) ) {
                $settings['align'] = strtolower( $style['textAlignHorizontal'] ); // left, center, right, justify
            }
        }

        // Font Color
        if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
            foreach ( $node['fills'] as $fill ) {
                if ( $fill['type'] === 'SOLID' && isset( $fill['color'] ) ) {
                    $color = $fill['color'];
                    $r = round( $color['r'] * 255 );
                    $g = round( $color['g'] * 255 );
                    $b = round( $color['b'] * 255 );
                    $a = $fill['opacity'] ?? $color['a'] ?? 1;
                    $settings['title_color'] = sprintf( 'rgba(%d, %d, %d, %f)', $r, $g, $b, $a );
                    break;
                }
            }
        }

        return $settings;
    }

    /**
     * Map Figma Image Fill to Elementor Image settings.
     */
    private function get_image_settings( $node ) {
        $node_id = $node['id'];
        $image_url = $this->image_map[$node_id] ?? '';

        return [
            'image' => [
                'url' => $image_url,
                'id' => '',
            ],
            'image_size' => 'full',
            'align' => 'center',
        ];
    }

    /**
     * Check if a Figma node has an image fill.
     */
    private function is_image_node( $node ) {
        if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
            foreach ( $node['fills'] as $fill ) {
                if ( $fill['type'] === 'IMAGE' ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Helper to map alignment values.
     */
    private function map_align_items( $align, $is_main_axis ) {
        switch ( $align ) {
            case 'MIN':
                return 'flex-start';
            case 'MAX':
                return 'flex-end';
            case 'CENTER':
                return 'center';
            case 'STRETCH':
                return 'stretch';
            case 'SPACE_BETWEEN':
                return 'space-between';
            default:
                return 'flex-start';
        }
    }

    /**
     * Helper to map text casing.
     */
    private function map_text_case( $case ) {
        switch ( $case ) {
            case 'UPPER':
                return 'uppercase';
            case 'LOWER':
                return 'lowercase';
            case 'TITLE':
                return 'capitalize';
            default:
                return '';
        }
    }

    /**
     * Recursively collect all node IDs that require image exports.
     *
     * @param array $node
     * @return array Node IDs
     */
    public function collect_image_node_ids( $node ) {
        $ids = [];
        if ( $this->is_image_node( $node ) ) {
            $ids[] = $node['id'];
        }

        if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
            foreach ( $node['children'] as $child ) {
                $ids = array_merge( $ids, $this->collect_image_node_ids( $child ) );
            }
        }

        return $ids;
    }
}
