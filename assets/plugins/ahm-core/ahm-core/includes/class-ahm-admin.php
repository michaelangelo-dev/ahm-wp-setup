<?php
/**
 * Tabbed admin page controller.
 *
 * Registers the top-level menu and renders a tab bar.
 * Each tab's content is supplied by its own class via action hooks.
 *
 * @package AHM_Core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class AHM_Admin
{
    private static ?self $instance = null;

    /** @var string Admin page hook suffix (used for conditional asset loading). */
    public static string $hook_suffix = '';

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /*--------------------------------------------------------------
     * Menu Registration
     *------------------------------------------------------------*/

    public function register_menu(): void
    {
        self::$hook_suffix = add_menu_page(
            __('AHM Core', 'ahm-core'),
            __('AHM Core', 'ahm-core'),
            'manage_options',
            'ahm-core',
            [$this, 'render_page'],
            'dashicons-admin-generic',
            3
        );
    }

    /*--------------------------------------------------------------
     * Assets (loaded only on this page)
     *------------------------------------------------------------*/

    public function enqueue_assets(string $hook): void
    {
        if (self::$hook_suffix !== $hook) {
            return;
        }

        wp_enqueue_style(
            'ahm-admin-css',
            AHM_CORE_URL . 'assets/css/admin.css',
            [],
            AHM_CORE_VERSION
        );

        wp_enqueue_script(
            'ahm-admin-js',
            AHM_CORE_URL . 'assets/js/admin.js',
            ['jquery'],
            AHM_CORE_VERSION,
            true
        );

        wp_localize_script('ahm-admin-js', 'ahmAdmin', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('ahm_webp_nonce'),
            'figmaNonce' => wp_create_nonce('ahm_figma_nonce'),
            'i18n'       => [
                'scanning'   => __('Scanning media library…', 'ahm-core'),
                'converting' => __('Converting', 'ahm-core'),
                'of'         => __('of', 'ahm-core'),
                'complete'   => __('Bulk conversion complete!', 'ahm-core'),
                'noImages'   => __('No unconverted images found.', 'ahm-core'),
                'error'      => __('Error during conversion.', 'ahm-core'),
                'confirm'    => __('Start bulk conversion? This cannot be undone if "Delete originals" is enabled.', 'ahm-core'),
            ],
        ]);
    }

    /*--------------------------------------------------------------
     * Tabbed Page Renderer
     *------------------------------------------------------------*/

    /**
     * Get the registered tabs.
     *
     * @return array<string, string> slug => label
     */
    public static function get_tabs(): array
    {
        return [
            'quick-user'      => __('Quick User Creation', 'ahm-core'),
            'image-converter' => __('Image Converter', 'ahm-core'),
            'cache-manager'   => __('Cache Manager', 'ahm-core'),
            'figma-importer'  => __('Figma Importer', 'ahm-core'),
        ];
    }

    /**
     * Get the currently active tab slug.
     */
    public static function active_tab(): string
    {
        $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        $tabs = self::get_tabs();
        return array_key_exists($tab, $tabs) ? $tab : 'quick-user';
    }

    /**
     * Render the admin page with tab navigation.
     */
    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $tabs       = self::get_tabs();
        $active_tab = self::active_tab();
        $page_url   = admin_url('admin.php?page=ahm-core');

        ?>
        <div class="wrap ahm-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper ahm-tabs">
                <?php foreach ($tabs as $slug => $label): ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $slug, $page_url)); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php if ($slug === 'quick-user'): ?>
                            <span class="dashicons dashicons-admin-users" style="margin-right:4px;"></span>
                        <?php elseif ($slug === 'image-converter'): ?>
                            <span class="dashicons dashicons-images-alt2" style="margin-right:4px;"></span>
                        <?php elseif ($slug === 'cache-manager'): ?>
                            <span class="dashicons dashicons-performance" style="margin-right:4px;"></span>
                        <?php elseif ($slug === 'figma-importer'): ?>
                            <span class="dashicons dashicons-layout" style="margin-right:4px;"></span>
                        <?php endif; ?>
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Tab Content -->
            <div class="ahm-tab-content">
                <?php
                /**
                 * Fires inside the active tab content area.
                 *
                 * Each tab class hooks into: ahm_tab_content_{slug}
                 */
                do_action('ahm_tab_content_' . $active_tab);
                ?>
            </div>
        </div>
        <?php
    }
}
