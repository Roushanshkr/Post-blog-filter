<?php
if (!defined('ABSPATH')) exit;

class Custom_Post_Filter_Ajax_Handler {
    public function __construct() {
        add_action('wp_ajax_custom_post_filter', [$this, 'handle_filter']);
        add_action('wp_ajax_nopriv_custom_post_filter', [$this, 'handle_filter']);
        add_action('wp_ajax_custom_post_get_terms', [$this, 'handle_get_terms']);
        add_action('wp_ajax_nopriv_custom_post_get_terms', [$this, 'handle_get_terms']);
    }

    public function handle_filter() {
        check_ajax_referer('custom_post_filter_nonce', 'nonce');

        $filter_by = isset($_POST['filter_by']) ? sanitize_text_field($_POST['filter_by']) : '';
        $filter_value = isset($_POST['filter_value']) ? intval($_POST['filter_value']) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $widget_settings = isset($_POST['widget_settings']) ? json_decode(stripslashes($_POST['widget_settings']), true) : [];
        $widget_type = isset($_POST['widget_type']) ? sanitize_text_field($_POST['widget_type']) : 'posts';
        $container_classes = isset($_POST['container_classes']) ? sanitize_text_field($_POST['container_classes']) : 'elementor-posts-container elementor-grid';
        $article_classes = isset($_POST['article_classes']) ? sanitize_text_field($_POST['article_classes']) : 'elementor-post elementor-grid-item ecs-post-loop ast-article-single';

        $debug_log = WP_CONTENT_DIR . '/custom-post-filter-debug.log';
        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Filter Request: filter_by=$filter_by, filter_value=$filter_value, post_type=$post_type, template_id=$template_id, container_classes=$container_classes, article_classes=$article_classes\n", FILE_APPEND);

        if (empty($filter_by)) {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Error: No taxonomy selected\n", FILE_APPEND);
            wp_send_json_error(['message' => 'No taxonomy selected']);
            wp_die();
        }

        $query_args = [
            'post_type' => $post_type,
            'posts_per_page' => isset($widget_settings['posts_per_page']) ? intval($widget_settings['posts_per_page']) : 10,
            'post_status' => 'publish',
            'suppress_filters' => false,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        
        if ($filter_value && taxonomy_exists($filter_by)) {
            $query_args['tax_query'] = [
                [
                    'taxonomy' => $filter_by,
                    'field' => 'term_id',
                    'terms' => [$filter_value],
                    'include_children' => true,
                ],
            ];
        } elseif (!taxonomy_exists($filter_by)) {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Error: Invalid taxonomy: $filter_by\n", FILE_APPEND);
            wp_send_json_error(['message' => 'Invalid taxonomy']);
            wp_die();
        }

        $query = new WP_Query($query_args);
        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Found Posts: " . $query->found_posts . "\n", FILE_APPEND);

        ob_start();
        if ($query->have_posts()) {
            if ($template_id && get_post_status($template_id) === 'publish') {
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Rendering with Template ID: $template_id\n", FILE_APPEND);

                // Determine container class and inline styles
                $container_class = $widget_type === 'posts-carousel' ? 'swiper-wrapper' : 'elementor-posts-container';
                $container_class .= ' ' . $container_classes;
                $inline_styles = '';

                // Apply inline CSS for gaps
                if (isset($widget_settings['custom_row_gap']['size']) && $widget_settings['custom_row_gap']['size'] !== '') {
                    $inline_styles .= sprintf('gap: %spx; ', $widget_settings['custom_row_gap']['size']);
                }
                if (isset($widget_settings['custom_column_gap']['size']) && $widget_settings['custom_column_gap']['size'] !== '') {
                    $inline_styles .= sprintf('--e-posts-container-column-gap: %spx; ', $widget_settings['custom_column_gap']['size']);
                }

                // Start container
                echo sprintf('<div class="%s" style="%s">', esc_attr($container_class), esc_attr($inline_styles));
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $post_classes = get_post_class($article_classes, $post_id);
                    echo sprintf('<article id="post-%d" class="%s">', $post_id, esc_attr(implode(' ', $post_classes)));
                    $this->render_loop_template($template_id, $post_id);
                    echo '</article>';
                }
                echo '</div>';
            } else {
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - No valid template ID, using fallback rendering\n", FILE_APPEND);
                echo '<div class="elementor-posts-container elementor-grid">';
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $post_classes = get_post_class($article_classes, $post_id);
                    echo sprintf('<article id="post-%d" class="%s">', $post_id, esc_attr(implode(' ', $post_classes)));
                    ?>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <div class="entry-content">
                        <?php the_excerpt(); ?>
                    </div>
                    <?php
                    echo '</article>';
                }
                echo '</div>';
            }
        } else {
            echo '<p>' . __('No posts found.', 'custom-post-filter') . '</p>';
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - No posts found\n", FILE_APPEND);
        }
        $html = ob_get_clean();
        wp_reset_postdata();

        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - HTML Length: " . strlen($html) . "\n", FILE_APPEND);

        if (!empty($html)) {
            wp_send_json_success(['html' => $html]);
        } else {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Error: No HTML generated\n", FILE_APPEND);
            wp_send_json_error(['message' => 'No posts found or rendering failed']);
        }

        wp_die();
    }

    private function render_loop_template($template_id, $post_id) {
        global $post;
        $post = get_post($post_id);
        setup_postdata($post);
        $frontend = \Elementor\Plugin::$instance->frontend;
        // Ensure dynamic CSS is included
        $frontend->get_builder_content_for_display($template_id, true);
        echo $frontend->get_builder_content_for_display($template_id, true);
        wp_reset_postdata();
    }

    public function handle_get_terms() {
        check_ajax_referer('custom_post_filter_nonce', 'nonce');

        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $widget_settings = isset($_POST['widget_settings']) ? json_decode(stripslashes($_POST['widget_settings']), true) : [];

        $debug_log = WP_CONTENT_DIR . '/custom-post-filter-debug.log';
        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Get Terms: taxonomy=$taxonomy, post_type=$post_type, widget_settings=" . print_r($widget_settings, true) . "\n", FILE_APPEND);

        if (empty($taxonomy)) {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Error: No taxonomy provided\n", FILE_APPEND);
            wp_send_json_error(['message' => 'No taxonomy provided']);
            wp_die();
        }

        if (!taxonomy_exists($taxonomy)) {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Error: Invalid taxonomy: $taxonomy\n", FILE_APPEND);
            wp_send_json_error(['message' => 'Invalid taxonomy']);
            wp_die();
        }

        // Build query to match widget's posts
        $query_args = [
            'post_type' => $post_type,
            'posts_per_page' => -1, // Get all posts to ensure all terms are found
            'post_status' => 'publish',
            'suppress_filters' => false,
            'fields' => 'ids',
        ];

        // Apply widget's query settings (e.g., taxonomy filters, include/exclude)
        if (!empty($widget_settings['posts_' . $taxonomy])) {
            $query_args['tax_query'] = [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => array_map('intval', (array)$widget_settings['posts_' . $taxonomy]),
                    'include_children' => true,
                ],
            ];
        } elseif (!empty($widget_settings['query_include']) && isset($widget_settings['query_include']['source']) && $widget_settings['query_include']['source'] === 'by_id') {
            $query_args['post__in'] = array_map('intval', (array)$widget_settings['query_include']['posts']);
        } elseif (!empty($widget_settings['query_exclude'])) {
            $query_args['post__not_in'] = array_map('intval', (array)$widget_settings['query_exclude']);
        }

        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Query Args: " . print_r($query_args, true) . "\n", FILE_APPEND);

        $query = new WP_Query($query_args);
        $post_ids = $query->posts;

        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Queried Post IDs: " . (empty($post_ids) ? 'None' : implode(', ', $post_ids)) . "\n", FILE_APPEND);

        $terms = [];
        if (!empty($post_ids)) {
            // Get terms assigned to the queried posts
            $terms_data = wp_get_object_terms($post_ids, $taxonomy, ['fields' => 'all']);
            if (!is_wp_error($terms_data)) {
                $term_counts = [];
                foreach ($terms_data as $term) {
                    // Count posts per term within the queried posts
                    $term_post_ids = get_objects_in_term($term->term_id, $taxonomy);
                    $term_post_count = count(array_intersect($post_ids, $term_post_ids));
                    if ($term_post_count > 0) {
                        $term_counts[$term->term_id] = $term_post_count;
                        $terms[] = [
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'count' => $term_post_count,
                        ];
                    }
                }
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Term Counts: " . print_r($term_counts, true) . "\n", FILE_APPEND);
            } else {
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Error fetching terms: " . $terms_data->get_error_message() . "\n", FILE_APPEND);
            }
        }

        // Fallback: Get all terms if no posts found, to avoid empty dropdown
        if (empty($terms)) {
            $terms_data = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
            ]);
            if (!is_wp_error($terms_data)) {
                foreach ($terms_data as $term) {
                    $terms[] = [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'count' => $term->count,
                    ];
                }
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Fallback to all terms: " . count($terms_data) . "\n", FILE_APPEND);
            }
        }

        // Sort terms alphabetically
        usort($terms, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        file_put_contents($debug_log, date('Y-m-d H:i:s') . " - Terms Found: " . count($terms) . "\n", FILE_APPEND);
        wp_send_json_success(['terms' => $terms]);
        wp_die();
    }
}

new Custom_Post_Filter_Ajax_Handler();
?>