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
     * @param string $node_id Optional. Specific node ID to parse
     * @return array Elementor array structure
     */
    public function parse( $figma_data, $node_id = '' ) {
        $elementor_data = [];

        // If a specific node ID was requested, process that node directly
        if ( ! empty( $node_id ) && isset( $figma_data['nodes'][ $node_id ]['document'] ) ) {
            $target_node = $figma_data['nodes'][ $node_id ]['document'];
            // Process the target node if it is a container type
            if ( in_array( $target_node['type'], ['FRAME', 'GROUP', 'SECTION', 'COMPONENT', 'COMPONENT_SET'] ) ) {
                $container = $this->parse_node( $target_node, null, true );
                if ( $container ) {
                    $elementor_data[] = $container;
                }
            }
            return $elementor_data;
        }

        if ( ! isset( $figma_data['document']['children'] ) ) {
            return [];
        }

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
     * @param string $parent_direction 'column' or 'row'
     * @return array|null Elementor element representation
     */
    private function parse_node( $node, $parent_box = null, $is_parent_auto_layout = true, $parent_direction = 'column' ) {
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

                // Determine direction of this container to pass to children
                $direction = 'column';
                if ( isset( $node['layoutMode'] ) && $node['layoutMode'] === 'HORIZONTAL' ) {
                    $direction = 'row';
                }

                // Recursively process children
                if ( isset( $node['children'] ) ) {
                    foreach ( $node['children'] as $child ) {
                        // Pass current box and layout style down
                        $parsed_child = $this->parse_node( $child, $current_box, $is_auto_layout, $direction );
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

        // Map Auto Layout Flex behaviors (Fill Container)
        if ( $is_parent_auto_layout ) {
            $is_fill_horizontal = false;
            $is_fill_vertical = false;
            
            if ( isset( $node['layoutSizingHorizontal'] ) ) {
                $is_fill_horizontal = $node['layoutSizingHorizontal'] === 'FILL';
            } elseif ( isset( $node['layoutAlign'] ) && $parent_direction === 'column' ) {
                $is_fill_horizontal = $node['layoutAlign'] === 'STRETCH';
            } elseif ( isset( $node['layoutGrow'] ) && $parent_direction === 'row' ) {
                $is_fill_horizontal = $node['layoutGrow'] === 1;
            }

            if ( isset( $node['layoutSizingVertical'] ) ) {
                $is_fill_vertical = $node['layoutSizingVertical'] === 'FILL';
            } elseif ( isset( $node['layoutAlign'] ) && $parent_direction === 'row' ) {
                $is_fill_vertical = $node['layoutAlign'] === 'STRETCH';
            } elseif ( isset( $node['layoutGrow'] ) && $parent_direction === 'column' ) {
                $is_fill_vertical = $node['layoutGrow'] === 1;
            }

            $is_widget = $el_type === 'widget';
            $prefix = $is_widget ? '_' : '';
            
            if ( $is_fill_horizontal ) {
                if ( $parent_direction === 'row' ) {
                    $settings[ $prefix . 'flex_grow' ] = '1';
                } else {
                    if ( $is_widget ) {
                        $settings['width'] = '100'; // 100% width for widget
                    } else {
                        $settings['content_width'] = 'full';
                        $settings['width'] = [ 'unit' => '%', 'size' => 100, 'sizes' => [] ];
                    }
                }
            }
            if ( $is_fill_vertical ) {
                if ( $parent_direction === 'column' ) {
                    $settings[ $prefix . 'flex_grow' ] = '1';
                } else {
                    $settings[ $prefix . 'align_self' ] = 'stretch';
                }
            }
        } else if ( $parent_box && $current_box ) {
            // Fallback positioning: If the parent was NOT an Auto Layout frame, position absolutely
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

        // Map global opacity
        if ( isset( $node['opacity'] ) && $node['opacity'] < 1 ) {
            if ( $el_type === 'container' ) {
                $settings['background_overlay_opacity'] = [ 'size' => $node['opacity'] ];
            } else {
                $settings['_opacity'] = [ 'size' => $node['opacity'] ];
            }
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

        // 2. Background solid colors and Images
        if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
            foreach ( $node['fills'] as $fill ) {
                if ( isset( $fill['visible'] ) && ! $fill['visible'] ) {
                    continue; // Skip hidden fills
                }
                
                if ( $fill['type'] === 'SOLID' && isset( $fill['color'] ) ) {
                    $color = $fill['color'];
                    $r = round( $color['r'] * 255 );
                    $g = round( $color['g'] * 255 );
                    $b = round( $color['b'] * 255 );
                    $a = $fill['opacity'] ?? $color['a'] ?? 1;
                    
                    // Prioritize applying color as overlay if there's also an image (though Figma renders bottom-up)
                    if ( isset( $settings['background_image'] ) ) {
                        $settings['background_overlay_background'] = 'classic';
                        $settings['background_overlay_color'] = sprintf( 'rgba(%d, %d, %d, %f)', $r, $g, $b, $a );
                    } else {
                        $settings['background_color'] = sprintf( 'rgba(%d, %d, %d, %f)', $r, $g, $b, $a );
                    }
                } elseif ( $fill['type'] === 'IMAGE' ) {
                    $node_id = $node['id'];
                    $image_url = $this->image_map[$node_id] ?? '';
                    if ( ! empty( $image_url ) ) {
                        $settings['background_image'] = [
                            'url' => $image_url,
                            'id'  => '',
                        ];
                        $settings['background_size'] = 'cover';
                    }
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
        // If 'is_fill_vertical' is true from parse_node, this fixed height shouldn't override it, but min_height is okay.
        if ( isset( $node['absoluteBoundingBox'] ) ) {
            $box = $node['absoluteBoundingBox'];
            // If the element is hugging its contents, its height/width are derived, but Figma API provides absolute width/height anyway.
            // We should only set hard widths if it doesn't hug.
            // Actually, we rely on Elementor to size naturally. We won't force widths unless needed.
            // If it's Auto Layout, width/height might just be intrinsic.
            // We only set width/height if it's NOT Auto Layout OR if the bounding box provides a constraint we can't derive.
            // Wait, for Flexbox, Elementor sizing naturally handles hug. So skipping absolute width if Auto Layout is often better.
            if ( !isset( $node['layoutMode'] ) || $node['layoutMode'] === 'NONE' ) {
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
        }

        // 8. Box Shadows (Effects)
        if ( isset( $node['effects'] ) && is_array( $node['effects'] ) ) {
            foreach ( $node['effects'] as $effect ) {
                if ( $effect['type'] === 'DROP_SHADOW' && ( !isset($effect['visible']) || $effect['visible'] !== false ) ) {
                    $color = $effect['color'];
                    $r = round( $color['r'] * 255 );
                    $g = round( $color['g'] * 255 );
                    $b = round( $color['b'] * 255 );
                    $a = $color['a'] ?? 1;
                    
                    $settings['box_shadow_box_shadow'] = [
                        'horizontal' => $effect['offset']['x'] ?? 0,
                        'vertical' => $effect['offset']['y'] ?? 0,
                        'blur' => $effect['radius'] ?? 0,
                        'spread' => $effect['spread'] ?? 0,
                        'color' => sprintf( 'rgba(%d, %d, %d, %f)', $r, $g, $b, $a ),
                    ];
                    $settings['box_shadow_box_shadow_type'] = 'yes';
                    break;
                }
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
            if ( isset( $style['letterSpacing'] ) ) {
                $settings['typography_letter_spacing'] = [
                    'unit' => 'px',
                    'size' => $style['letterSpacing'],
                ];
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
