<?php
/**
 * Image Converter admin tab.
 *
 * Settings, bulk conversion, statistics — all rendered
 * inside the "Image Converter" tab of AHM Core.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class AHM_WebP_Admin
{
    private static ?self $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        // Tab content.
        add_action('ahm_tab_content_image-converter', [$this, 'render_tab']);

        // Settings API.
        add_action('admin_init', [$this, 'register_settings']);

        // Auto-convert on upload.
        add_filter('wp_generate_attachment_metadata', [AHM_WebP_Converter::class, 'convert_attachment'], 10, 2);

        // AJAX.
        add_action('wp_ajax_ahm_bulk_convert', [$this, 'ajax_bulk_convert']);
        add_action('wp_ajax_ahm_get_unconverted', [$this, 'ajax_get_unconverted']);

        // Media library column.
        add_filter('manage_media_columns', [$this, 'add_media_column']);
        add_action('manage_media_custom_column', [$this, 'render_media_column'], 10, 2);
    }

    /*--------------------------------------------------------------
     * Settings Registration
     *------------------------------------------------------------*/

    public function register_settings(): void
    {
        register_setting('ahm_webp_settings_group', 'ahm_webp_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => [
                'quality'         => 80,
                'delete_original' => false,
                'auto_convert'    => true,
                'serve_webp'      => true,
                'serve_webp_css'  => true,
            ],
        ]);
    }

    /**
     * @param  mixed $input
     * @return array<string, mixed>
     */
    public function sanitize_settings(mixed $input): array
    {
        if (! is_array($input)) {
            $input = [];
        }

        $clean = [
            'quality'         => max(1, min(100, absint($input['quality'] ?? 80))),
            'delete_original' => ! empty($input['delete_original']),
            'auto_convert'    => ! empty($input['auto_convert']),
            'serve_webp'      => ! empty($input['serve_webp']),
            'serve_webp_css'  => ! empty($input['serve_webp_css']),
        ];

        // Sync .htaccess rules + Elementor CSS with the CSS-serving toggle.
        if ($clean['serve_webp_css']) {
            AHM_WebP_Frontend::update_htaccess_rules();
            AHM_WebP_Frontend::rewrite_all_elementor_css();
        } else {
            AHM_WebP_Frontend::remove_htaccess_rules();
        }

        return $clean;
    }

    /*--------------------------------------------------------------
     * Tab Renderer
     *------------------------------------------------------------*/

    public function render_tab(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings     = get_option('ahm_webp_settings', []);
        $library      = AHM_WebP_Converter::detect_library() ?? 'none';
        $library_name = match ($library) {
            'imagick' => 'Imagick (ImageMagick)',
            'gd'      => 'GD Library',
            default   => 'Not Available',
        };

        // Handle settings saved redirect.
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Settings saved.', 'ahm-core') . '</p></div>';
        }
        ?>

        <!-- Server Info -->
        <div class="ahm-card ahm-server-info">
            <h2><?php esc_html_e('Server Environment', 'ahm-core'); ?></h2>
            <table class="ahm-info-table">
                <tr>
                    <td><strong><?php esc_html_e('PHP Version', 'ahm-core'); ?></strong></td>
                    <td><?php echo esc_html(PHP_VERSION); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Conversion Library', 'ahm-core'); ?></strong></td>
                    <td>
                        <span class="ahm-badge ahm-badge--<?php echo $library !== 'none' ? 'success' : 'error'; ?>">
                            <?php echo esc_html($library_name); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('WebP Support', 'ahm-core'); ?></strong></td>
                    <td>
                        <?php if (AHM_WebP_Converter::server_supports_webp()): ?>
                            <span class="ahm-badge ahm-badge--success"><?php esc_html_e('Enabled', 'ahm-core'); ?></span>
                        <?php else: ?>
                            <span class="ahm-badge ahm-badge--error"><?php esc_html_e('Disabled', 'ahm-core'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Settings Form -->
        <div class="ahm-card">
            <h2><?php esc_html_e('Conversion Settings', 'ahm-core'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields('ahm_webp_settings_group'); ?>

                <!--
                     After WordPress saves the option it redirects to options.php's
                     referer. We override that referer so the user lands back on
                     the Image Converter tab, not the Quick User tab.
                -->
                <input type="hidden" name="_wp_http_referer"
                       value="<?php echo esc_url(admin_url('admin.php?page=ahm-core&tab=image-converter&settings-updated=true')); ?>" />

                <table class="form-table ahm-settings-table">
                    <!-- Quality Slider -->
                    <tr>
                        <th scope="row">
                            <label for="ahm_quality"><?php esc_html_e('WebP Quality', 'ahm-core'); ?></label>
                        </th>
                        <td>
                            <div class="ahm-slider-wrap">
                                <input
                                    type="range"
                                    id="ahm_quality"
                                    name="ahm_webp_settings[quality]"
                                    min="1" max="100"
                                    value="<?php echo esc_attr((string) ($settings['quality'] ?? 80)); ?>"
                                    class="ahm-range"
                                >
                                <output for="ahm_quality" id="ahm_quality_output" class="ahm-range-output">
                                    <?php echo esc_html((string) ($settings['quality'] ?? 80)); ?>
                                </output>
                            </div>
                            <p class="description">
                                <?php esc_html_e('1 = smallest file / lowest quality. 100 = largest file / highest quality. Recommended: 75–85.', 'ahm-core'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Auto Convert -->
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto Convert on Upload', 'ahm-core'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ahm_webp_settings[auto_convert]" value="1"
                                    <?php checked(! empty($settings['auto_convert'])); ?>>
                                <?php esc_html_e('Automatically convert images to WebP when uploaded.', 'ahm-core'); ?>
                            </label>
                        </td>
                    </tr>

                    <!-- Serve WebP (img) -->
                    <tr>
                        <th scope="row"><?php esc_html_e('Serve WebP on Front-End', 'ahm-core'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ahm_webp_settings[serve_webp]" value="1"
                                    <?php checked(! empty($settings['serve_webp'])); ?>>
                                <?php esc_html_e('Replace <img> tags with <picture> elements containing WebP source and original fallback.', 'ahm-core'); ?>
                            </label>
                        </td>
                    </tr>

                    <!-- Serve WebP (CSS) -->
                    <tr>
                        <th scope="row"><?php esc_html_e('CSS Background Images', 'ahm-core'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ahm_webp_settings[serve_webp_css]" value="1"
                                    <?php checked(! empty($settings['serve_webp_css'])); ?>>
                                <?php esc_html_e('Rewrite CSS url() references (background-image, list-style-image, border-image, content) to WebP.', 'ahm-core'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Covers inline styles, <style> blocks, Elementor data-settings, and Elementor external CSS files (post-*.css are rewritten on disk). Also hooks into WP Rocket cache clears to re-apply WebP URLs after regeneration.', 'ahm-core'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Delete Original -->
                    <tr>
                        <th scope="row"><?php esc_html_e('Delete Original Images', 'ahm-core'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ahm_webp_settings[delete_original]" value="1"
                                    <?php checked(! empty($settings['delete_original'])); ?>>
                                <?php esc_html_e('Delete original JPEG/PNG/GIF after successful WebP conversion.', 'ahm-core'); ?>
                            </label>
                            <p class="description ahm-warning">
                                <?php esc_html_e('⚠ This is irreversible. The original image file will be permanently removed from the server.', 'ahm-core'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'ahm-core')); ?>
            </form>
        </div>

        <!-- Bulk Conversion -->
        <div class="ahm-card">
            <h2><?php esc_html_e('Bulk Conversion', 'ahm-core'); ?></h2>
            <p class="description">
                <?php esc_html_e('Convert all existing JPEG, PNG, and GIF images in your media library to WebP format.', 'ahm-core'); ?>
            </p>

            <div id="ahm-bulk-controls">
                <button type="button" id="ahm-btn-bulk" class="button button-primary button-hero">
                    <?php esc_html_e('Start Bulk Conversion', 'ahm-core'); ?>
                </button>
            </div>

            <div id="ahm-bulk-progress" class="ahm-progress" style="display:none;">
                <div class="ahm-progress-bar-wrap">
                    <div id="ahm-progress-bar" class="ahm-progress-bar" style="width:0%;">0%</div>
                </div>
                <p id="ahm-progress-text" class="ahm-progress-text"></p>
                <div id="ahm-progress-log" class="ahm-progress-log"></div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="ahm-card">
            <h2><?php esc_html_e('Conversion Statistics', 'ahm-core'); ?></h2>
            <?php $this->render_statistics(); ?>
        </div>
        <?php
    }

    /*--------------------------------------------------------------
     * Statistics
     *------------------------------------------------------------*/

    private function render_statistics(): void
    {
        // Cache stats for 5 minutes to avoid 3 COUNT(*) queries on every tab load.
        $stats = get_transient('ahm_webp_stats');

        if (false === $stats) {
            global $wpdb;

            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
            );

            $converted = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta}
                     WHERE meta_key = %s AND meta_value != ''",
                    '_ahm_webp_file'
                )
            );

            $webp_native = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'attachment' AND post_mime_type = 'image/webp'"
            );

            $stats = [
                'total'       => $total,
                'converted'   => $converted,
                'webp_native' => $webp_native,
                'pending'     => max(0, $total - $converted),
            ];

            set_transient('ahm_webp_stats', $stats, 5 * MINUTE_IN_SECONDS);
        }
        ?>
        <div class="ahm-stats-grid">
            <div class="ahm-stat">
                <span class="ahm-stat__number"><?php echo esc_html((string) $stats['total']); ?></span>
                <span class="ahm-stat__label"><?php esc_html_e('Total Images', 'ahm-core'); ?></span>
            </div>
            <div class="ahm-stat">
                <span class="ahm-stat__number ahm-stat__number--success"><?php echo esc_html((string) $stats['converted']); ?></span>
                <span class="ahm-stat__label"><?php esc_html_e('Converted', 'ahm-core'); ?></span>
            </div>
            <div class="ahm-stat">
                <span class="ahm-stat__number ahm-stat__number--warning"><?php echo esc_html((string) $stats['pending']); ?></span>
                <span class="ahm-stat__label"><?php esc_html_e('Pending', 'ahm-core'); ?></span>
            </div>
            <div class="ahm-stat">
                <span class="ahm-stat__number"><?php echo esc_html((string) $stats['webp_native']); ?></span>
                <span class="ahm-stat__label"><?php esc_html_e('Native WebP', 'ahm-core'); ?></span>
            </div>
        </div>
        <?php
    }

    /*--------------------------------------------------------------
     * AJAX
     *------------------------------------------------------------*/

    public function ajax_get_unconverted(): void
    {
        check_ajax_referer('ahm_webp_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ahm_webp_file'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             ORDER BY p.ID ASC"
        );

        wp_send_json_success([
            'ids'   => array_map('absint', $ids),
            'total' => count($ids),
        ]);
    }

    public function ajax_bulk_convert(): void
    {
        check_ajax_referer('ahm_webp_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        $id = absint($_POST['attachment_id'] ?? 0);

        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid attachment ID.']);
        }

        $result = AHM_WebP_Converter::bulk_convert_single($id);

        // Invalidate cached statistics.
        delete_transient('ahm_webp_stats');

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'file'    => $result['file'] ?? '',
                'title'   => get_the_title($id),
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
                'title'   => get_the_title($id),
            ]);
        }
    }

    /*--------------------------------------------------------------
     * Media Library Column
     *------------------------------------------------------------*/

    /** @param array<string,string> $columns */
    public function add_media_column(array $columns): array
    {
        $columns['ahm_webp'] = __('WebP', 'ahm-core');
        return $columns;
    }

    public function render_media_column(string $column, int $post_id): void
    {
        if ('ahm_webp' !== $column) {
            return;
        }

        $webp = get_post_meta($post_id, '_ahm_webp_file', true);

        if ($webp) {
            echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;" title="'
                . esc_attr__('Converted', 'ahm-core') . '"></span>';
        } else {
            $mime = get_post_mime_type($post_id);
            if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
                echo '<span class="dashicons dashicons-minus" style="color:#d63638;" title="'
                    . esc_attr__('Not converted', 'ahm-core') . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-marker" style="color:#999;" title="'
                    . esc_attr__('N/A', 'ahm-core') . '"></span>';
            }
        }
    }
}
