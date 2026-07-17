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

// Permanently disable comments in WordPress
function disable_comments_everywhere()
{
    // Disable support for comments and trackbacks on posts and pages
    remove_post_type_support('post', 'comments');
    remove_post_type_support('post', 'trackbacks');
    remove_post_type_support('page', 'comments');
    remove_post_type_support('page', 'trackbacks');
}
add_action('init', 'disable_comments_everywhere');

// Close comments on the front end
add_filter('comments_open', '__return_false', 20, 2);
add_filter('pings_open', '__return_false', 20, 2);

// Hide existing comments
add_filter('comments_array', '__return_empty_array', 10, 2);

// Remove comments page from admin menu
function remove_comments_admin_menu()
{
    remove_menu_page('edit-comments.php');
}
add_action('admin_menu', 'remove_comments_admin_menu');

// Remove comments from admin bar
function remove_comments_admin_bar()
{
    global $wp_admin_bar;
    $wp_admin_bar->remove_menu('comments');
}
add_action('wp_before_admin_bar_render', 'remove_comments_admin_bar');

/**
 * Exclude the Elementor's Custom CSS Global for the reset and CSS snipets
 */
add_filter('rocket_exclude_css_from_rucss', function ($excluded_files) {
    $kit_id = get_option('elementor_active_kit');

    if ($kit_id) {
        $excluded_files[] = '/wp-content/uploads/elementor/css/post-' . $kit_id . '\.css';
    }

    return $excluded_files;
});

/**
 * Exclude dynamic and interactive classes from WP Rocket RUCSS
 */
add_filter('rocket_rucss_exclude_css', function ($exclusions) {
    // Menu Exclusions
    $exclusions[] = 'e-n-menu-content';
    $exclusions[] = 'e-active';
    $exclusions[] = 'menu-reset';

    // Accordion Exclusions
    $exclusions[] = 'accordion-reset';
    $exclusions[] = 'open'; // Keeps details[open] rules safe

    // Grid & Pagination Exclusions
    $exclusions[] = 'e-load-more-pagination-end';

    // Forms & Checkboxes
    $exclusions[] = 'form-has-acceptance';
    $exclusions[] = 'checked'; // Keeps :checked styles safe
    $exclusions[] = 'intlTelInput-initiated';

    // Admin features
    $exclusions[] = 'admin-only';

    return $exclusions;
});

/**
 * Automatically set Uppercase Alt Text for new uploads
 */
add_action('add_attachment', 'set_alt_text_to_uppercase');
function set_alt_text_to_uppercase($post_ID)
{
    if (wp_attachment_is_image($post_ID)) {
        $existing_alt = get_post_meta($post_ID, '_wp_attachment_image_alt', true);

        $source_text = !empty($existing_alt) ? $existing_alt : get_post($post_ID)->post_title;

        $clean_title = preg_replace('/\s*[-_]\s*/', ' ', $source_text);
        $final_alt = ucwords(strtolower(trim($clean_title)));

        update_post_meta($post_ID, '_wp_attachment_image_alt', $final_alt);
    }
}

/**
 * Bulk update existing media to Uppercase Alt Text
 * Trigger via: /?force_uppercase_alt=1
 */
add_action('init', 'bulk_update_existing_alt_uppercase');
function bulk_update_existing_alt_uppercase()
{
    if (isset($_GET['force_uppercase_alt']) && current_user_can('manage_options')) {
        $images = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
        ]);

        $count = 0;
        foreach ($images as $image) {
            $existing_alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
            $source = !empty($existing_alt) ? $existing_alt : $image->post_title;

            $clean = preg_replace('/\s*[-_]\s*/', ' ', $source);
            $final_alt = ucwords(strtolower(trim($clean)));

            update_post_meta($image->ID, '_wp_attachment_image_alt', $final_alt);
            $count++;
        }

        wp_die("Success: $count images have been updated to UPPERCASE Alt Text.");
    }
}

/**
 * Shortcode to calculate and display the estimated reading time.
 * Usage: [minute_read]
 */
function minute_read_func()
{
    global $post;

    $content = get_post_field('post_content', $post->ID);
    $word_count = str_word_count(strip_tags($content));

    $wpm = 200;
    $minutes = ceil($word_count / $wpm);

    $label = ' Min Read';

    return '<span class="post-meta__read-time">' . $minutes . $label . '</span>';
}
add_shortcode('minute_read', 'minute_read_func');

/**
 * Format a title into include <b> if needed.
 */
function format_title_with_bold_func($title)
{
    $words = explode(' ', $title);
    $count = count($words);

    if ($count === 0) {
        return '';
    }

    if ($count === 1) {
        $num_to_bold = 1;
    } elseif ($count === 2) {
        $num_to_bold = 1;
    } elseif ($count === 3) {
        $num_to_bold = 2;
    } else {
        $num_to_bold = 3;
    }

    $num_to_bold = min($num_to_bold, $count);

    $bold_part = array_slice($words, 0, $num_to_bold);
    $normal_part = array_slice($words, $num_to_bold);

    $output = '<b>' . implode(' ', $bold_part) . '</b>';

    if (!empty($normal_part)) {
        $output .= ' ' . implode(' ', $normal_part);
    }

    return $output;
}

/**
 * Get the alternate title or post title as a fallback.
 */
function get_post_custom_title_func($atts)
{
    $atts = shortcode_atts([
        'has_bold' => 'true',
    ], $atts);

    $title = get_the_title();

    if (function_exists('get_field')) {
        $acf_field = get_field('treatment_page_-_alternate_title');
        $title = $acf_field ?: $title;
    }

    $title = esc_html($title);
    if (isset($atts['has_bold']) && $atts['has_bold'] === 'true') {
        $title = format_title_with_bold_func($title);
    }

    return $title;
}
add_shortcode('get_post_custom_title', 'get_post_custom_title_func');

/**
 * Get and return an specific social share url.
 */
function custom_share_url_func($atts)
{
    $atts = shortcode_atts([
        'type' => 'facebook'
    ], $atts, 'custom_share_url');

    $url = urlencode(get_permalink());
    $title = urlencode(get_the_title());

    switch (strtolower($atts['type'])) {
        case 'twitter':
        case 'x':
            return "https://twitter.com/intent/tweet?url={$url}&text={$title}";
        case 'linkedin':
            return "https://www.linkedin.com/sharing/share-offsite/?url={$url}";
        case 'facebook':
        default:
            return "https://www.facebook.com/sharer/sharer.php?u={$url}";
    }
}
add_shortcode('custom_share_url', 'custom_share_url_func');

/**
 * 1. Block Author Enumeration (Security Fix)
 */
add_action('init', function () {
    if (!is_admin() && isset($_REQUEST['author'])) {
        wp_redirect(home_url(), 301);
        exit;
    }
});

/**
 * 2. Smart Author Archives for Medical E-E-A-T (SEO Fix)
 * Allows Doctors to have profiles, but returns a 404 for empty Dev accounts.
 */
add_action('template_redirect', function () {
    if (is_author()) {
        $author = get_queried_object();
        
        if ($author instanceof WP_User) {
            // Count the number of published posts
            $has_posts = count_user_posts($author->ID) > 0;
            
            // If the user has 0 posts (like a developer), block them with a 404
            if (!$has_posts) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                
                // $template = get_query_template('404');
                // if ($template) include($template);
                // exit;
            }
        }
    }
});