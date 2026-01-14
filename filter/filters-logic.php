<?php

// --------------------------------------
// SEO-friendly filter rewrite
// --------------------------------------

add_action('init', function () {

    add_rewrite_rule(
        '^product-category/(.+)/([^/]+)/([^/]+)/?$',
        'index.php?product_cat=$matches[1]&filter_$matches[2]=$matches[3]',
        'top'
    );
});

// --------------------------------------
// Canonical for filtered category pages
// --------------------------------------

add_filter('wpseo_canonical', function ($canonical) {

    if (!is_product_category()) {
        return $canonical;
    }

    foreach ($_GET as $key => $value) {
        if (strpos($key, 'filter_') === 0) {
            return get_term_link(get_queried_object());
        }
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
        WHERE option_name LIKE '_transient_eshop_availability_all_%'
           OR option_name LIKE '_transient_timeout_eshop_availability_all_%'
           OR option_name LIKE '_transient_eshop_price_range_%'
           OR option_name LIKE '_transient_timeout_eshop_price_range_%'
    ");
});

// убираем страница 2 или страница 3 из хлебных крошек

add_filter('woocommerce_get_breadcrumb', function ($crumbs) {

    if (is_paged()) {
        array_pop($crumbs);
    }

    return $crumbs;
});
