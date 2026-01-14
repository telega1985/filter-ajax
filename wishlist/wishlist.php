<?php
// создаём таблицу один раз при активации темы
add_action('after_switch_theme', 'eshop_wishlist_create_tbl');

function eshop_wishlist_create_tbl()
{
    global $wpdb;

    $table = $wpdb->prefix . 'wishlist_items';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_product (user_id, product_id),
        KEY user_created (user_id, created_at)
    ) {$charset_collate};";

    dbDelta($sql);
}

//---- Получить wishlist пользователя ---//

function eshop_get_wishlist_db($user_id = 0)
{
    if (!$user_id) {
        if (!is_user_logged_in()) return [];
        $user_id = get_current_user_id();
    }

    $cache_key = "eshop:wishlist:user:{$user_id}";

    // берем из object cache (Redis)
    $cached = wp_cache_get($cache_key, 'eshop');

    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;

    $table = $wpdb->prefix . 'wishlist_items';

    // сортируем по created_at: старые / новые
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT product_id FROM {$table} WHERE user_id = %d ORDER BY created_at ASC",
            $user_id
        )
    );

    $ids = array_map('intval', $ids ?: []);

    // кладем в Redis
    wp_cache_set($cache_key, $ids, 'eshop', 6 * HOUR_IN_SECONDS);

    return $ids;
}

//---- /Получить wishlist пользователя ---//

//---- Проверить, есть ли товар в wishlist пользователя ---//

function eshop_in_wishlist_db($product_id, $wishlist_ids = null)
{
    $product_id = (int) $product_id;

    if ($wishlist_ids === null) {
        $wishlist_ids = eshop_get_wishlist_db();
    }

    return in_array($product_id, $wishlist_ids);
}

//---- /Проверить, есть ли товар в wishlist пользователя ---//

//---- Регистрация REST API endpoints GET / POST ---//

add_action('rest_api_init', function () {
    register_rest_route('eshop/v1', '/wishlist', [
        'methods'  => 'GET',
        'callback' => 'eshop_rest_wishlist_get',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('eshop/v1', '/wishlist/toggle', [
        'methods'  => 'POST',
        'callback' => 'eshop_rest_wishlist_toggle',
        'permission_callback' => '__return_true',
        'args' => [
            'product_id' => [
                'required' => true,
                'type' => 'integer',
            ],
        ],
    ]);
});

//---- /Регистрация REST API endpoints GET / POST ---//

//---- GET wishlist ---//

function eshop_rest_wishlist_get(WP_REST_Request $request)
{
    if (!is_user_logged_in()) {
        return new WP_REST_Response([
            'code' => 'not_auth',
            'answer' => __('Please login to use wishlist', 'eshop'),
            'ids' => [],
            'count' => 0
        ], 401);
    }

    $ids = eshop_get_wishlist_db();

    return new WP_REST_Response([
        'ids' => $ids,
        'count' => count($ids)
    ], 200);
}

//---- /GET wishlist ---//

//---- POST toggle wishlist (добавить / удалить) ---//

function eshop_rest_wishlist_toggle(WP_REST_Request $request)
{
    if (!is_user_logged_in()) {
        return new WP_REST_Response([
            'code' => 'not_auth',
            'answer' => __('Please login to use wishlist', 'eshop')
        ], 401);
    }

    $product_id = absint($request->get_param('product_id'));

    if (!$product_id) {
        return new WP_REST_Response([
            'answer' => __('Invalid product', 'eshop')
        ], 400);
    }

    $product = wc_get_product($product_id);

    if (!$product || $product->get_status() !== 'publish') {
        return new WP_REST_Response([
            'answer' => __('Product not found', 'eshop')
        ], 404);
    }

    global $wpdb;

    $table = $wpdb->prefix . 'wishlist_items';
    $user_id = get_current_user_id();
    $limit = 8;

    $ids = eshop_get_wishlist_db($user_id);
    $exists = eshop_in_wishlist_db($product_id, $ids);

    if ($exists) {
        $deleted = $wpdb->delete(
            $table,
            ['user_id' => $user_id, 'product_id' => $product_id],
            ['%d', '%d']
        );

        if ($deleted === false) {
            return new WP_REST_Response([
                'answer' => __('Error by database', 'eshop')
            ], 500);
        }

        wp_cache_delete("eshop:wishlist:user:{$user_id}", 'eshop');

        $ids = eshop_get_wishlist_db($user_id);

        return new WP_REST_Response([
            'answer'     => __('The product has been removed from wishlist', 'eshop'),
            'action'     => 'removed',
            'count'      => count($ids),
            'inWishlist' => false
        ], 200);
    }

    if (count($ids) >= $limit) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE user_id = %d
                 ORDER BY created_at ASC
                 LIMIT 1",
                $user_id
            )
        );
    }

    $inserted = $wpdb->insert(
        $table,
        ['user_id' => $user_id, 'product_id' => $product_id],
        ['%d', '%d']
    );

    if ($inserted === false) {
        return new WP_REST_Response([
            'answer' => __('Error by database', 'eshop')
        ], 500);
    }

    wp_cache_delete("eshop:wishlist:user:{$user_id}", 'eshop');

    $ids = eshop_get_wishlist_db($user_id);

    return new WP_REST_Response([
        'answer'     => __('The product has been added to wishlist', 'eshop'),
        'action'     => 'added',
        'count'      => count($ids),
        'inWishlist' => true
    ], 200);
}

//---- /POST toggle wishlist (добавить / удалить) ---//