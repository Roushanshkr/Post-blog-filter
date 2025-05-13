jQuery(document).ready(function($) {
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
            console.warn('No filter taxonomy defined for widget:', $widget);
            return;
        }

        console.log('Initializing filter:', { filterBy, postType, templateId, queryId, widgetType, containerClasses, articleClasses });

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
        console.log('Term Select:: ', $termsSelect);
        

        $.ajax({
            url: customPostFilter.ajax_url,
            type: 'POST',
            data: {
                action: 'custom_post_get_terms',
                nonce: customPostFilter.nonce,
                taxonomy: filterBy,
                post_type: postType,
            },
            success: function(response) {
                console.log('Get terms response:', response);
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
                console.error('Terms loading failed:', xhr.responseText);
                $termsSelect.append('<option value="0">Error loading terms</option>');
            },
        });

        $termsSelect.on('change', function() {
            const filterValue = $(this).val();
            applyFilter(filterBy, filterValue);
        });

        function applyFilter(taxonomy, filterValue) {
            console.log("FUNCTION RESTARTed");
            $widget.addClass('custom-post-filter-loading');
            console.log('Applying filter:', { taxonomy, filterValue, postType, templateId, queryId, widgetType, containerClasses, articleClasses });
            console.log('WIDGET SETTINGS:: ', JSON.stringify(widgetSettings));
            
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
                success: function(response) {
                    if (response.success && response.data.html) {
                        const $container = $widget.find('.elementor-widget-container, .swiper-wrapper');
                        if ($container.length) {
                            $container.html(response.data.html);
                            
                            if (typeof elementorFrontend !== 'undefined') {
                                // Reinitialize Elementor frontend scripts and CSS
                                elementorFrontend.elementsHandler.runReadyTrigger($container);
                                elementorFrontend.elementsHandler.runReadyTrigger($widget);
                                // Trigger resize to fix grid layouts
                                // $(window).trigger('resize');
                                // Ensure dynamic CSS is loaded
                                if (typeof elementorFrontend.init === 'function') {
                                    elementorFrontend.init();
                                }
                            }
                            // if ($widget.hasClass('elementor-widget-posts-carousel') && typeof Swiper !== 'undefined') {
                            //     const $swiperContainer = $widget.find('.swiper-container');
                            //     console.log('Swiper Container', $swiperContainer);
                                
                            //     if ($swiperContainer.length) {
                            //         new Swiper($swiperContainer[0], {
                            //             slidesPerView: widgetSettings.slides_per_view || 3,
                            //             spaceBetween: widgetSettings.space_between || 30,
                            //             loop: widgetSettings.loop || false,
                            //             navigation: {
                            //                 nextEl: '.swiper-button-next',
                            //                 prevEl: '.swiper-button-prev',
                            //             },
                            //             pagination: {
                            //                 el: '.swiper-pagination',
                            //                 clickable: true,
                            //             },
                            //         });
                            //     }
                            // }
                        } else {
                            console.error('No container found for update');
                            $widget.append('<p>Error: No container found to display posts.</p>');
                        }
                    } else {
                        $widget.find('.elementor-posts-container, .swiper-wrapper')
                            .html(`<p>${response.data?.message || 'No posts found.'}</p>`);
                    }
                    
                    $widget.removeClass('custom-post-filter-loading');
                },
                error: function(xhr) {
                    console.error('Filter AJAX failed:', xhr.responseText);
                    $widget.find('.elementor-posts-container, .swiper-wrapper')
                        .html('<p>Error loading posts.</p>');
                    $widget.removeClass('custom-post-filter-loading');
                },
                complete: function() {
                    // $widget.removeClass('custom-post-filter-loading');
                },
            });
        }
    });
});