jQuery(document).ready(function($) {
    console.log('Custom Post Filter [v1.0.10]: Script loaded');
    
    // Skip if in Elementor editor, preview, or admin
    if ($('body').hasClass('elementor-editor-active') || 
        $('body').hasClass('elementor-editor-preview') || 
        window.location.search.includes('elementor-preview') || 
        (typeof customPostFilter !== 'undefined' && customPostFilter.is_admin)) {
        console.log('Custom Post Filter [v1.0.10]: Skipping in Elementor editor or admin');
        return;
    }

    console.log('Custom Post Filter [v1.0.10]: Processing widgets');
    $('.elementor-widget-posts, .elementor-widget-posts-carousel').each(function() {
        const $widget = $(this);
        const filterBy = $widget.data('filter-by') || 'category';
        const postType = $widget.data('post-type') || 'post';
        const templateId = $widget.data('template-id') || 0;
        const queryId = $widget.data('query-id');
        const widgetSettings = $widget.data('settings') || {};
        const widgetType = $widget.data('widget-type');
        const containerClasses = $widget.data('container-classes') || 'elementor-posts-container elementor-grid';
        const articleClasses = $widget.data('article-classes') || 'elementor-post elementor-grid-item ecs-post-loop ast-article-single';

        if (!filterBy) {
            console.warn('Custom Post Filter [v1.0.10]: No filter taxonomy defined for widget:', $widget);
            return;
        }

        console.log('Custom Post Filter [v1.0.10]: Initializing filter:', { filterBy, postType, templateId, queryId, widgetType, containerClasses, articleClasses });

        const taxonomyLabel = filterBy === 'category' ? 'Categories' : filterBy === 'post_tag' ? 'Tags' : filterBy;
        const $filter = $(`
            <div class="custom-post-filter">
                <label>Filter By ${taxonomyLabel}: </label>
                <select class="custom-post-filter-terms">
                    <option value="0">All ${taxonomyLabel}</option>
                </select>
            </div>
        `);

        $widget.prepend($filter);

        const $termsSelect = $filter.find('.custom-post-filter-terms');
        console.log('Custom Post Filter [v1.0.10]: Term Select initialized:', $termsSelect);

        $.ajax({
            url: customPostFilter.ajax_url,
            type: 'POST',
            data: {
                action: 'custom_post_get_terms',
                nonce: customPostFilter.nonce,
                taxonomy: filterBy,
                post_type: postType,
            },
            beforeSend: function() {
                console.log('Custom Post Filter [v1.0.10]: Sending get_terms AJAX');
            },
            success: function(response) {
                console.log('Custom Post Filter [v1.0.10]: Get terms response:', response);
                if (response.success && response.data.terms.length) {
                    response.data.terms.sort((a, b) => a.name.localeCompare(b.name));
                    response.data.terms.forEach(term => {
                        if (term.count > 0) {
                            $termsSelect.append(`<option value="${term.id}">${term.name} (${term.count})</option>`);
                        }
                    });
                } else {
                    $termsSelect.append('<option value="0">No terms available</option>');
                }
            },
            error: function(xhr) {
                console.error('Custom Post Filter [v1.0.10]: Terms loading failed:', xhr.responseText);
                $termsSelect.append('<option value="0">Error loading terms</option>');
            },
        });

        $termsSelect.on('change', function() {
            const filterValue = $(this).val();
            console.log('Custom Post Filter [v1.0.10]: Filter changed, value:', filterValue);
            applyFilter(filterBy, filterValue);
        });

        function applyFilter(taxonomy, filterValue) {
            console.log('Custom Post Filter [v1.0.10]: FUNCTION RESTARTed');
            $widget.addClass('custom-post-filter-loading');
            console.log('Custom Post Filter [v1.0.10]: Applying filter:', { taxonomy, filterValue, postType, templateId, queryId, widgetType, containerClasses, articleClasses });
            console.log('Custom Post Filter [v1.0.10]: WIDGET SETTINGS:', JSON.stringify(widgetSettings));
            
            $.ajax({
                url: customPostFilter.ajax_url,
                type: 'POST',
                data: {
                    action: 'custom_post_filter',
                    nonce: customPostFilter.nonce,
                    filter_by: taxonomy,
                    filter_value: filterValue,
                    post_type: postType,
                    template_id: templateId,
                    query_id: queryId,
                    widget_settings: JSON.stringify(widgetSettings),
                    widget_type: widgetType,
                    container_classes: containerClasses,
                    article_classes: articleClasses,
                },
                beforeSend: function() {
                    console.log('Custom Post Filter [v1.0.10]: Sending filter AJAX');
                },
                success: function(response) {
                    console.log('Custom Post Filter [v1.0.10]: Filter response:', response);
                    if (response.success && response.data.html) {
                        const $container = $widget.find('.elementor-widget-container, .swiper-wrapper');
                        if ($container.length) {
                            $container.html(response.data.html);
                            
                            if (typeof elementorFrontend !== 'undefined') {
                                console.log('Custom Post Filter [v1.0.10]: Reinitializing Elementor frontend');
                                elementorFrontend.elementsHandler.runReadyTrigger($container);
                                elementorFrontend.elementsHandler.runReadyTrigger($widget);
                                if (typeof elementorFrontend.init === 'function') {
                                    elementorFrontend.init();
                                }
                            }
                        } else {
                            console.error('Custom Post Filter [v1.0.10]: No container found for update');
                            $widget.append('<p>Error: No container found to display posts.</p>');
                        }
                    } else {
                        console.log('Custom Post Filter [v1.0.10]: No posts found or error:', response.data?.message);
                        $widget.find('.elementor-posts-container, .swiper-wrapper')
                            .html(`<p>${response.data?.message || 'No posts found.'}</p>`);
                    }
                    
                    $widget.removeClass('custom-post-filter-loading');
                },
                error: function(xhr) {
                    console.error('Custom Post Filter [v1.0.10]: Filter AJAX failed:', xhr.responseText);
                    $widget.find('.elementor-posts-container, .swiper-wrapper')
                        .html('<p>Error loading posts.</p>');
                    $widget.removeClass('custom-post-filter-loading');
                },
                complete: function() {
                    console.log('Custom Post Filter [v1.0.10]: Filter AJAX completed');
                },
            });
        }
    });
});