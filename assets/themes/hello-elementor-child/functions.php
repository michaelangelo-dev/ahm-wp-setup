<?php
// Exit if accessed directly
if (! defined('ABSPATH')) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if (! function_exists('chld_thm_cfg_locale_css')):
    function chld_thm_cfg_locale_css($uri)
    {
        if (empty($uri) && is_rtl() && file_exists(get_template_directory() . '/rtl.css'))
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter('locale_stylesheet_uri', 'chld_thm_cfg_locale_css');

if (!function_exists('child_theme_configurator_css')):
    function child_theme_configurator_css()
    {
        wp_enqueue_style('chld_thm_cfg_child', trailingslashit(get_stylesheet_directory_uri()) . 'style.css', array('hello-elementor', 'hello-elementor-theme-style', 'hello-elementor-header-footer'));
    }
endif;
add_action('wp_enqueue_scripts', 'child_theme_configurator_css', 10);

// END ENQUEUE PARENT ACTION

/**
 * Optional Elementor Google Fonts Disabler & Typography Filter
 * Active only when 'ahm_disable_google_fonts' option is set to 'yes'.
 */
if ('yes' === get_option('ahm_disable_google_fonts')) {
    // Disable Elementor's automatic Google Fonts frontend loader
    add_filter('elementor/frontend/print_google_fonts', '__return_false');

    // Restrict Elementor's typography control dropdown to Custom and System fonts
    add_filter('elementor/fonts/groups', function ($groups) {
        unset($groups['googlefonts'], $groups['earlyaccess']);
        return $groups;
    });

    // Dynamic High-Priority Preload for Primary Brand Font
    add_action('wp_head', function () {
        $preload_url = get_option('ahm_preload_font_url');
        if (! empty($preload_url)) {
            echo '<link rel="preload" href="' . esc_url($preload_url) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        }
    }, 1);
}

