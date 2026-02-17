<?php

// --------------------------------------
// SEO-friendly filter rewrite
// --------------------------------------

add_action('init', function () {
    // Фильтр + пагинация
    add_rewrite_rule(
        '^product-category/(.+?)/filter/(.+?)/page/([0-9]+)/?$',
        'index.php?product_cat=$matches[1]&filter=$matches[2]&paged=$matches[3]',
        'top'
    );

    // Только фильтр
    add_rewrite_rule(
        '^product-category/(.+?)/filter/(.+?)/?$',
        'index.php?product_cat=$matches[1]&filter=$matches[2]',
        'top'
    );
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'filter';
    return $vars;
});

add_action('pre_get_posts', function ($q) {

    if (is_admin() || !$q->is_main_query()) {
        return;
    }

    if (!is_product_category()) {
        return;
    }

    $raw = get_query_var('filter');
    if (!$raw) {
        return;
    }

    $tax_query = [];

    $parts = explode('/', trim($raw, '/'));

    foreach ($parts as $part) {

        if (!str_contains($part, '-')) {
            continue;
        }

        [$attr, $values] = explode('-', $part, 2);

        // price отдельно обрабатывается
        if ($attr === 'price') {

            [$min, $max] = explode('-', $values);

            $meta_query = [
                [
                    'key'     => '_price',
                    'value'   => [(float)$min, (float)$max],
                    'compare' => 'BETWEEN',
                    'type'    => 'NUMERIC'
                ]
            ];

            $q->set('meta_query', $meta_query);
            continue;
        }

        $taxonomy = 'pa_' . sanitize_key($attr);

        $terms = array_map(
            'sanitize_title',
            explode('_', $values)
        );

        $tax_query[] = [
            'taxonomy' => $taxonomy,
            'field'    => 'slug',
            'terms'    => $terms,
            'operator' => 'IN'
        ];
    }

    if ($tax_query) {
        $tax_query['relation'] = 'AND';
        $q->set('tax_query', $tax_query);
    }
});

// --------------------------------------
// Canonical for filtered category pages
// --------------------------------------

add_filter('wpseo_canonical', function ($canonical) {

    if (is_product_category() && get_query_var('filter')) {
        return get_term_link(get_queried_object());
    }

    return $canonical;
});

/**
 * Clear filter availability cache when products change
 */
add_action('save_post_product', function ($post_id) {

    // защита от автосохранений и ревизий
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_revision($post_id)) {
        return;
    }

    global $wpdb;

    // удаляем только наши transient'ы
    $wpdb->query("
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_geostroyka_availability_all_%'
           OR option_name LIKE '_transient_timeout_geostroyka_availability_all_%'
           OR option_name LIKE '_transient_geostroyka_price_range_%'
           OR option_name LIKE '_transient_timeout_geostroyka_price_range_%'
    ");
});

// убираем страница 2 или страница 3 из хлебных крошек

add_filter('woocommerce_get_breadcrumb', function ($crumbs) {

    if (is_paged()) {
        array_pop($crumbs);
    }

    return $crumbs;
});
