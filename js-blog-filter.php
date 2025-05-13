<?php
/**
 * Plugin Name: Custom Post Filter for Elementor
 * Description: AJAX-powered filtering for Elementor Posts and Post Carousel, supporting default and custom post types.
 * Version: 1.0.5
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

class Custom_Post_Filter_Plugin {
    public function __construct() {
        add_action('elementor/init', [$this, 'init']);
    }

    public function init() {
        if (!did_action('elementor/loaded')) {
            add_action('admin_notices', [$this, 'elementor_missing_notice']);
            return;
        }

        add_action('elementor/element/posts/section_query/after_section_end', [$this, 'add_filter_controls'], 10, 2);
        add_action('elementor/element/posts-carousel/section_query/after_section_end', [$this, 'add_filter_controls'], 10, 2);
        add_action('elementor/frontend/widget/before_render', [$this, 'before_widget_render'], 10, 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        require_once plugin_dir_path(__FILE__) . 'includes/class-ajax-handler.php';
        new Custom_Post_Filter_Ajax_Handler();
    }

    public function elementor_missing_notice() {
        echo '<div class="error"><p>Custom Post Filter requires Elementor to be installed and activated.</p></div>';
    }

    public function add_filter_controls($element, $args) {
        if (!in_array($element->get_name(), ['posts', 'posts-carousel'])) return;

        $post_type = $element->get_settings('posts_post_type') ?? 'post';
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $taxonomy_options = [];
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->public) {
                $taxonomy_options[$taxonomy->name] = $taxonomy->label;
            }
        }

        $element->start_controls_section(
            'section_filter',
            [
                'label' => __('Filter Settings', 'custom-post-filter'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $element->add_control(
            'enable_filter',
            [
                'label' => __('Enable Filter', 'custom-post-filter'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'label_on' => __('On', 'custom-post-filter'),
                'label_off' => __('Off', 'custom-post-filter'),
                'return_value' => 'yes',
                'default' => 'no',
            ]
        );

        $element->add_control(
            'filter_taxonomy',
            [
                'label' => __('Filter Taxonomy', 'custom-post-filter'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => $taxonomy_options,
                'default' => array_key_first($taxonomy_options) ?? 'category',
                'condition' => ['enable_filter' => 'yes'],
                'description' => __('Select the taxonomy for filtering. Ensure a custom loop template is set in Content > Layout > Skin or Template settings.', 'custom-post-filter'),
            ]
        );

        $element->end_controls_section();
    }

    public function before_widget_render($widget) {
        if (!in_array($widget->get_name(), ['posts', 'posts-carousel'])) return;

        $settings = $widget->get_settings_for_display();
        $debug_log = WP_CONTENT_DIR . '/custom-post-filter-debug.log';
        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Widget Settings: " . print_r($settings, true) . "\n", FILE_APPEND);

        if (isset($settings['enable_filter']) && $settings['enable_filter'] === 'yes') {
            // Dynamically detect template ID from possible keys
            $template_id = 0;
            $template_keys = ['custom_skin_template', 'theme_id', 'template_id'];
            foreach ($template_keys as $key) {
                if (isset($settings[$key]) && intval($settings[$key]) > 0) {
                    $template_id = intval($settings[$key]);
                    file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Template ID found in '$key': $template_id\n", FILE_APPEND);
                    break;
                }
            }

            if ($template_id === 0) {
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - No valid template ID found in settings\n", FILE_APPEND);
            }

            // Get widget container classes
            $container_classes = 'elementor-posts-container elementor-grid';
            if ($widget->get_name() === 'posts-carousel') {
                $container_classes = 'swiper-wrapper';
            }
            // Add grid classes based on settings
            if (isset($settings['custom_columns'])) {
                $container_classes .= ' elementor-grid-' . intval($settings['custom_columns']);
            }
            if (isset($settings['custom_columns_tablet'])) {
                $container_classes .= ' elementor-grid-tablet-' . intval($settings['custom_columns_tablet']);
            }
            if (isset($settings['custom_columns_mobile'])) {
                $container_classes .= ' elementor-grid-mobile-' . intval($settings['custom_columns_mobile']);
            }
            // Add custom classes (e.g., acf-loop-grid)
            if (isset($settings['_css_classes']) && !empty($settings['_css_classes'])) {
                $container_classes .= ' ' . sanitize_html_class($settings['_css_classes']);
            }

            // Get article classes from a sample post
            $article_classes = 'elementor-post elementor-grid-item ecs-post-loop ast-article-single';
            $query = new WP_Query([
                'post_type' => $settings['posts_post_type'] ?? 'post',
                'posts_per_page' => 1,
                'post_status' => 'publish',
            ]);
            if ($query->have_posts()) {
                $query->the_post();
                $post_classes = get_post_class('', get_the_ID());
                $article_classes .= ' ' . implode(' ', array_diff($post_classes, ['post-' . get_the_ID()]));
                wp_reset_postdata();
            }

            $widget->add_render_attribute('_wrapper', [
                'data-filter-by' => $settings['filter_taxonomy'] ?? 'category',
                'data-post-type' => $settings['posts_post_type'] ?? 'post',
                'data-template-id' => $template_id,
                'data-query-id' => $settings['query_id'] ?? uniqid('cpf-'),
                'data-settings' => htmlspecialchars(json_encode($settings), ENT_QUOTES, 'UTF-8'),
                'data-widget-type' => $widget->get_name(),
                'data-container-classes' => $container_classes,
                'data-article-classes' => $article_classes,
            ]);
        }
    }

    public function enqueue_assets() {
        wp_enqueue_script(
            'custom-post-filter',
            plugin_dir_url(__FILE__) . 'assets/js/filter.js',
            ['jquery'],
            '1.0.5',
            true
        );

        wp_localize_script('custom-post-filter', 'customPostFilter', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('custom_post_filter_nonce'),
        ]);

        wp_enqueue_style(
            'custom-post-filter-css',
            plugin_dir_url(__FILE__) . 'assets/css/filter.css',
            [],
            '1.0.5'
        );
    }
}

new Custom_Post_Filter_Plugin();
?>