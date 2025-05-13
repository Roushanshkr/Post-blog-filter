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
        $this->debug_log("handle_filter: AJAX request received");
        
        if (isset($_GET['elementor-preview']) || 
            \Elementor\Plugin::$instance->editor->is_edit_mode() || 
            (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] === 'elementor_ajax')) {
            $this->debug_log("handle_filter: Skipped in editor or Elementor AJAX");
            wp_send_json_error(['message' => __('Filter disabled in Elementor editor.', 'custom-post-filter')]);
            wp_die();
        }

        check_ajax_referer('custom_post_filter_nonce', 'nonce');
        $this->debug_log("handle_filter: Nonce verified");

        $filter_by = isset($_POST['filter_by']) ? sanitize_text_field($_POST['filter_by']) : '';
        $filter_value = isset($_POST['filter_value']) ? intval($_POST['filter_value']) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $widget_settings = isset($_POST['widget_settings']) ? json_decode(stripslashes($_POST['widget_settings']), true) : [];
        $widget_type = isset($_POST['widget_type']) ? sanitize_text_field($_POST['widget_type']) : 'posts';
        $container_classes = isset($_POST['container_classes']) ? sanitize_text_field($_POST['container_classes']) : 'elementor-posts-container elementor-grid';
        $article_classes = isset($_POST['article_classes']) ? sanitize_text_field($_POST['article_classes']) : 'elementor-post elementor-grid-item ecs-post-loop ast-article-single';

        $this->debug_log("handle_filter: Request data - filter_by=$filter_by, filter_value=$filter_value, post_type=$post_type, template_id=$template_id, container_classes=$container_classes, article_classes=$article_classes");

        if (empty($filter_by)) {
            $this->debug_log("handle_filter: Error: No taxonomy selected");
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
            $this->debug_log("handle_filter: Added tax_query for $filter_by, term $filter_value");
        } elseif (!taxonomy_exists($filter_by)) {
            $this->debug_log("handle_filter: Error: Invalid taxonomy: $filter_by");
            wp_send_json_error(['message' => 'Invalid taxonomy']);
            wp_die();
        }

        $query = new WP_Query($query_args);
        $this->debug_log("handle_filter: Found Posts: " . $query->found_posts);

        ob_start();
        if ($query->have_posts()) {
            if ($template_id && get_post_status($template_id) === 'publish') {
                $this->debug_log("handle_filter: Rendering with Template ID: $template_id");

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
                $this->debug_log("handle_filter: No valid template ID, using fallback rendering");
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
            $this->debug_log("handle_filter: No posts found");
        }
        $html = ob_get_clean();
        wp_reset_postdata();

        $this->debug_log("handle_filter: HTML Length: " . strlen($html));

        if (!empty($html)) {
            $this->debug_log("handle_filter: Sending success response");
            wp_send_json_success(['html' => $html]);
        } else {
            $this->debug_log("handle_filter: Error: No HTML generated");
            wp_send_json_error(['message' => 'No posts found or rendering failed']);
        }

        wp_die();
    }

    private function render_loop_template($template_id, $post_id) {
        $this->debug_log("render_loop_template: Rendering template $template_id for post $post_id");
        global $post;
        $post = get_post($post_id);
        setup_postdata($post);
        $frontend = \Elementor\Plugin::$instance->frontend;
        // Ensure dynamic CSS is included
        $frontend->get_builder_content_for_display($template_id, true);
        echo $frontend->get_builder_content_for_display($template_id, true);
        wp_reset_postdata();
        $this->debug_log("render_loop_template: Template rendered");
    }

    public function handle_get_terms() {
        $this->debug_log("handle_get_terms: AJAX request received");
        
        if (isset($_GET['elementor-preview']) || 
            \Elementor\Plugin::$instance->editor->is_edit_mode() || 
            (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] === 'elementor_ajax')) {
            $this->debug_log("handle_get_terms: Skipped in editor or Elementor AJAX");
            wp_send_json_error(['message' => __('Terms fetch disabled in Elementor editor.', 'custom-post-filter')]);
            wp_die();
        }

        check_ajax_referer('custom_post_filter_nonce', 'nonce');
        $this->debug_log("handle_get_terms: Nonce verified");

        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $widget_settings = isset($_POST['widget_settings']) ? json_decode(stripslashes($_POST['widget_settings']), true) : [];

        $this->debug_log("handle_get_terms: Request data - taxonomy=$taxonomy, post_type=$post_type, widget_settings=" . print_r($widget_settings, true));

        if (empty($taxonomy)) {
            $this->debug_log("handle_get_terms: Error: No taxonomy provided");
            wp_send_json_error(['message' => 'No taxonomy provided']);
            wp_die();
        }

        if (!taxonomy_exists($taxonomy)) {
            $this->debug_log("handle_get_terms: Error: Invalid taxonomy: $taxonomy");
            wp_send_json_error(['message' => 'Invalid taxonomy']);
            wp_die();
        }

        // Build query to match widget's posts
        $query_args = [
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'suppress_filters' => false,
            'fields' => 'ids',
        ];

        // Apply widget's query settings
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

        $this->debug_log("handle_get_terms: Query Args = " . print_r($query_args, true));

        $query = new WP_Query($query_args);
        $post_ids = $query->posts;

        $this->debug_log("handle_get_terms: Queried Post IDs = " . (empty($post_ids) ? 'None' : implode(', ', $post_ids)));

        $terms = [];
        if (!empty($post_ids)) {
            $terms_data = wp_get_object_terms($post_ids, $taxonomy, ['fields' => 'all']);
            if (!is_wp_error($terms_data)) {
                $term_counts = [];
                foreach ($terms_data as $term) {
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
                $this->debug_log("handle_get_terms: Term Counts = " . print_r($term_counts, true));
            } else {
                $this->debug_log("handle_get_terms: Error fetching terms: " . $terms_data->get_error_message());
            }
        }

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
                $this->debug_log("handle_get_terms: Fallback to all terms: " . count($terms_data));
            }
        }

        usort($terms, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $this->debug_log("handle_get_terms: Terms Found: " . count($terms));
        wp_send_json_success(['terms' => $terms]);
        wp_die();
    }

    private function debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Custom Post Filter [v1.0.10]: ' . $message);
        }
    }
}

new Custom_Post_Filter_Ajax_Handler();
?>