<?php

/**
 * Context-aware WooCommerce filters (FAST)
 *
 * ЦЕЛЬ:
 * - Быстрый availability без WP_Query в цикле
 * - Один SQL-запрос на availability по всем атрибутам (UNION ALL)
 * - Без wc_get_products (чистый SQL)
 * - Режим "potential combinations" (faceted): availability для каждой таксы считается
 *   на основе товаров, подходящих под ВСЕ активные фильтры, КРОМЕ текущей таксы.
 * - Price range через MIN/MAX по тем же условиям.
 *
 * ВАЖНО:
 * - Этот код фильтрует РОДИТЕЛЬСКИЕ товары (post_type=product).
 * - В WooCommerce атрибуты вариаций обычно продублированы на parent — этого достаточно.
 */

/* =========================================================
 * НАСТРОЙКИ
 * ========================================================= */

if (!defined('ESHOP_FILTERS_CACHE_TTL')) {
    define('ESHOP_FILTERS_CACHE_TTL', 10 * MINUTE_IN_SECONDS);
}

/**
 * availability mode:
 * - 'potential'  => faceted (рекомендуется): availability по каждой таксе = исключаем текущую таксу
 * - 'strict'     => availability строго по текущей выдаче (не исключаем текущую таксу)
 */
if (!defined('ESHOP_FILTERS_AVAILABILITY_MODE')) {
    define('ESHOP_FILTERS_AVAILABILITY_MODE', 'potential');
}

/**
 * price range mode:
 * - 'base'       => только контекст (категория/магазин), как у тебя было
 * - 'filtered'   => по текущим активным фильтрам (кроме min/max) — динамический диапазон
 */
if (!defined('ESHOP_FILTERS_PRICE_RANGE_MODE')) {
    define('ESHOP_FILTERS_PRICE_RANGE_MODE', 'base');
}

/* =========================================================
 * БАЗОВЫЙ КОНТЕКСТ (категория)
 * ========================================================= */

function eshop_get_base_context(): array
{
    if (is_product_category()) {
        $term = get_queried_object();

        return [
            'type' => 'product_cat',
            'term_id' => (int) $term->term_id,
        ];
    }

    // магазин (без категории)
    return [
        'type' => 'shop',
        'term_id' => 0,
    ];
}

/* =========================================================
 * АКТИВНЫЕ ФИЛЬТРЫ ИЗ URL (filter_color=blue,gray)
 * ========================================================= */

function eshop_get_allowed_attribute_taxonomies(): array
{
    $allowed = [];
    foreach (wc_get_attribute_taxonomies() as $a) {
        $allowed[] = 'pa_' . $a->attribute_name;
    }
    return $allowed;
}

function eshop_get_active_filters(): array
{
    $allowed = array_flip(eshop_get_allowed_attribute_taxonomies());
    $filters = [];

    foreach ($_GET as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'filter_')) {
            continue;
        }

        $attr = str_replace('filter_', '', $key);
        $taxonomy = 'pa_' . sanitize_key($attr);

        // белый список таксономий
        if (!isset($allowed[$taxonomy])) {
            continue;
        }

        $slugs = array_filter(array_map(
            static fn($v) => sanitize_title((string) $v),
            explode(',', (string) $value)
        ));

        if ($slugs) {
            $filters[$taxonomy] = array_values($slugs);
        }
    }

    return $filters;
}

/* =========================================================
 * ХЕЛПЕР: SQL WHERE для товаров под контекст + фильтры
 * ========================================================= */

function eshop_sql_products_where(array $base_context, array $active_filters, ?string $exclude_taxonomy = null): array
{
    global $wpdb;

    $where = [];
    $params = [];

    // базовые условия по товарам
    $where[] = "p.post_type = 'product'";
    $where[] = "p.post_status = 'publish'";

    // контекст категории (если мы в product_cat)
    if ($base_context['type'] === 'product_cat' && !empty($base_context['term_id'])) {
        $where[] = "EXISTS (
            SELECT 1
            FROM {$wpdb->term_relationships} trc
            INNER JOIN {$wpdb->term_taxonomy} ttc
                ON ttc.term_taxonomy_id = trc.term_taxonomy_id
            WHERE trc.object_id = p.ID
              AND ttc.taxonomy = 'product_cat'
              AND ttc.term_id = %d
        )";
        $params[] = (int) $base_context['term_id'];
    }

    // активные фильтры (каждая такса = EXISTS; внутри таксы slugs IN (...) => OR)
    foreach ($active_filters as $taxonomy => $slugs) {
        if ($exclude_taxonomy && $taxonomy === $exclude_taxonomy) {
            continue;
        }

        if (!$slugs) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '%s'));

        $where[] = "EXISTS (
            SELECT 1
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t
                ON t.term_id = tt.term_id
            WHERE tr.object_id = p.ID
              AND tt.taxonomy = %s
              AND t.slug IN ($placeholders)
        )";

        $params[] = $taxonomy;
        foreach ($slugs as $s) {
            $params[] = $s;
        }
    }

    return [
        'sql' => '(' . implode(' AND ', $where) . ')',
        'params' => $params,
    ];
}

/* =========================================================
 * AVAILABILITY ДЛЯ ВСЕХ ТАКСОНОМИЙ ОДНИМ SQL
 * ========================================================= */

function eshop_get_all_availability_map(array $base_context, array $active_filters, array $attribute_taxonomies): array
{
    global $wpdb;

    $mode = ESHOP_FILTERS_AVAILABILITY_MODE;

    // cache key
    $cache_key = 'eshop_availability_all_' . md5(serialize([
        'mode' => $mode,
        'base' => $base_context,
        'filters' => $active_filters,
        'taxonomies' => $attribute_taxonomies,
    ]));

    $cached = get_transient($cache_key);

    if (is_array($cached)) {
        return $cached;
    }

    // Если нет атрибутов — нечего считать
    if (!$attribute_taxonomies) {
        return [];
    }

    $union_parts = [];
    $union_params = [];

    if ($mode === 'strict') {
        // STRICT: availability по текущей выдаче (не исключаем таксу)
        // Один запрос без UNION (но вернём карту по всем таксам)
        // Здесь проще собрать как UNION тоже, но exclude_taxonomy=null для всех.
        foreach ($attribute_taxonomies as $tax) {
            $w = eshop_sql_products_where($base_context, $active_filters, null);

            $union_parts[] = "
                SELECT %s AS taxonomy, t.slug AS slug
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                WHERE {$w['sql']}
                  AND tt.taxonomy = %s
                GROUP BY t.slug
            ";

            $union_params[] = $tax;                 // taxonomy label
            foreach ($w['params'] as $pp) $union_params[] = $pp;
            $union_params[] = $tax;                 // tt.taxonomy = %s
        }
    } else {
        // POTENTIAL (faceted): availability по каждой таксе считаем исключая её из фильтров
        foreach ($attribute_taxonomies as $tax) {
            $w = eshop_sql_products_where($base_context, $active_filters, $tax);

            $union_parts[] = "
                SELECT %s AS taxonomy, t.slug AS slug
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                WHERE {$w['sql']}
                  AND tt.taxonomy = %s
                GROUP BY t.slug
            ";

            $union_params[] = $tax;                 // taxonomy label
            foreach ($w['params'] as $pp) $union_params[] = $pp;
            $union_params[] = $tax;                 // tt.taxonomy = %s
        }
    }

    $sql = implode(" UNION ALL ", $union_parts);

    // Выполняем один запрос
    $prepared = $wpdb->prepare($sql, $union_params);
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    // Собираем карту: [taxonomy][slug] => true
    $map = [];
    foreach ($attribute_taxonomies as $tax) {
        $map[$tax] = [];
    }

    foreach ($rows as $r) {
        $tax = (string) ($r['taxonomy'] ?? '');
        $slug = (string) ($r['slug'] ?? '');
        if ($tax === '' || $slug === '') {
            continue;
        }
        $map[$tax][$slug] = true;
    }

    set_transient($cache_key, $map, ESHOP_FILTERS_CACHE_TTL);

    return $map;
}

/**
 * Совместимая обёртка под твой шаблон:
 * возвращает [slug => bool] для конкретной таксы
 */
function eshop_get_term_availability_fast(
    string $taxonomy,
    array $terms,                // список WP_Term
    array $base_context,
    array $active_filters,
    array $all_availability_map
): array {

    $available = $all_availability_map[$taxonomy] ?? [];
    $availability = [];

    foreach ($terms as $term) {
        $availability[$term->slug] = isset($available[$term->slug]);
    }

    return $availability;
}

/* =========================================================
 * PRICE RANGE через SQL MIN/MAX (без wc_get_products)
 * ========================================================= */

function eshop_get_catalog_price_range_sql(array $base_context, array $active_filters): array
{
    global $wpdb;

    $mode = ESHOP_FILTERS_PRICE_RANGE_MODE;

    // При filtered-режиме мы считаем диапазон по активным фильтрам,
    // но НЕ учитываем текущие min_price/max_price (чтобы не "схлопывать" ползунок).
    $filters_for_range = $active_filters;

    if ($mode !== 'filtered') {
        $filters_for_range = []; // только база
    }

    $cache_key = 'eshop_price_range_' . md5(serialize([
        'mode' => $mode,
        'base' => $base_context,
        'filters' => $filters_for_range,
    ]));

    $cached = get_transient($cache_key);

    if (is_array($cached) && isset($cached['min'], $cached['max'])) {
        return $cached;
    }

    $w = eshop_sql_products_where($base_context, $filters_for_range, null);

    // MIN/MAX по _price
    // CAST чтобы корректно сравнивалось как число
    $sql = "
        SELECT
            MIN(CAST(pm.meta_value AS DECIMAL(12,2))) AS min_price,
            MAX(CAST(pm.meta_value AS DECIMAL(12,2))) AS max_price
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID AND pm.meta_key = '_price'
        WHERE {$w['sql']}
          AND pm.meta_value <> ''
          AND CAST(pm.meta_value AS DECIMAL(12,2)) > 0
    ";

    $prepared = $wpdb->prepare($sql, $w['params']);
    $row = $wpdb->get_row($prepared, ARRAY_A);

    $min = isset($row['min_price']) ? (float) $row['min_price'] : 0.0;
    $max = isset($row['max_price']) ? (float) $row['max_price'] : 0.0;

    $result = [
        'min' => $min ?: 0,
        'max' => $max ?: 0,
    ];

    set_transient($cache_key, $result, ESHOP_FILTERS_CACHE_TTL);

    return $result;
}

/* =========================================================
 * ДАННЫЕ ДЛЯ ШАБЛОНА
 * ========================================================= */

function eshop_get_filters_view_data(): array
{
    $base_context = eshop_get_base_context();
    $active_filters = eshop_get_active_filters();

    // 1) получаем список атрибутов (pa_color, pa_size...)
    $attribute_taxonomies = [];
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $attribute_taxonomies[] = 'pa_' . $attribute->attribute_name;
    }

    // 2) price range (SQL)
    $price_range = eshop_get_catalog_price_range_sql($base_context, $active_filters);

    $min_price = isset($_GET['min_price'])
        ? max((int) $_GET['min_price'], (int) $price_range['min'])
        : (int) $price_range['min'];

    $max_price = isset($_GET['max_price'])
        ? min((int) $_GET['max_price'], (int) $price_range['max'])
        : (int) $price_range['max'];

    // 3) availability map (ОДИН SQL)
    $all_availability_map = eshop_get_all_availability_map(
        $base_context,
        $active_filters,
        $attribute_taxonomies
    );

    // 4) собираем filters_data под шаблон
    $filters_data = [];

    foreach (wc_get_attribute_taxonomies() as $attribute) {

        $taxonomy = 'pa_' . $attribute->attribute_name;

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
        ]);

        if (!$terms || is_wp_error($terms)) {
            continue;
        }

        $availability = eshop_get_term_availability_fast(
            $taxonomy,
            $terms,
            $base_context,
            $active_filters,
            $all_availability_map
        );

        $filters_data[] = [
            'label'        => $attribute->attribute_label,
            'taxonomy'     => $taxonomy,
            'terms'        => $terms,
            'active_terms' => $active_filters[$taxonomy] ?? [],
            'availability' => $availability,
        ];
    }

    return [
        'filters_data' => $filters_data,
        'price_range'  => $price_range,
        'min_price'    => $min_price,
        'max_price'    => $max_price,
    ];
}
