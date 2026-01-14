<?php

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
 * - 'base'       => только контекст (категория/магазин),
 * - 'filtered'   => по текущим активным фильтрам (кроме min/max) — динамический диапазон
 */

if (!defined('ESHOP_FILTERS_PRICE_RANGE_MODE')) {
    define('ESHOP_FILTERS_PRICE_RANGE_MODE', 'base');
}

/* =========================================================
 * БАЗОВЫЙ КОНТЕКСТ (категория)
 * [type => category_name, term_id => category_id]
 * ========================================================= */

function eshop_get_base_context()
{
    if (is_product_category()) {
        $term = get_queried_object();

        return [
            'type' => 'product_cat',
            'term_id' => (int) $term->term_id
        ];
    }

    return [
        'type' => 'shop',
        'term_id' => 0
    ];
}

/* =========================================================
 * ПОЛУЧАЕМ ВСЕ АТРИБУТЫ МАГАЗИНА
 * ['pa_color', 'pa_size']
 * ========================================================= */

function eshop_get_allowed_attribute_taxonomies()
{
    $allowed = [];

    foreach (wc_get_attribute_taxonomies() as $a) {
        $allowed[] = 'pa_' . $a->attribute_name;
    }

    return $allowed;
}

/* =========================================================
 * АКТИВНЫЕ ФИЛЬТРЫ ИЗ URL
 *  [
        'pa_color' => ['yellow', 'blue'],
        'pa_size'  => ['large']
    ]
 * ========================================================= */

function eshop_get_active_filters()
{
    $allowed = array_flip(eshop_get_allowed_attribute_taxonomies());
    $filters = [];

    foreach ($_GET as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'filter_')) {
            continue;
        }

        $attr = str_replace('filter_', '', $key);
        $taxonomy = 'pa_' . sanitize_key($attr);

        if (!isset($allowed[$taxonomy])) {
            continue;
        }

        // $slugs = ['yellow', 'blue'];
        $slugs = array_filter(array_map(
            static fn($v) => sanitize_title((string) $v),
            explode(',', (string) $value)
        ));

        if ($slugs) {
            $filters[$taxonomy] = array_values($slugs); // убираем 0, 1. Оставляем только ['yellow', 'blue']
        }
    }

    return $filters;
}

/* =========================================================
 * ХЕЛПЕР: SQL WHERE для товаров под контекст + фильтры
 * ========================================================= */

function eshop_sql_products_where($base_context, $active_filters, $exclude_taxonomy = null)
{
    global $wpdb;

    $where = [];
    $params = [];

    $where[] = "p.post_type = 'product'";
    $where[] = "p.post_status = 'publish'";

    if ($base_context['type'] === 'product_cat' && !empty($base_context['term_id'])) {
        // принадлежит ли товар (p.ID) конкретной категории товара (product_cat)
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

    // $taxonomy = 'pa-color', $slugs = [blue, gray]
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
        'params' => $params
    ];
}

/* =========================================================
 * PRICE RANGE через SQL MIN/MAX
 * ========================================================= */

function eshop_get_catalog_price_range_sql($base_context, $active_filters)
{
    global $wpdb;

    $mode = ESHOP_FILTERS_PRICE_RANGE_MODE;

    $filters_for_range = $active_filters;

    if ($mode === 'base') {
        $filters_for_range = [];
    }

    $cache_key = 'eshop_price_range_' . md5(serialize([
        'mode' => $mode,
        'base' => $base_context,
        'filters' => $filters_for_range
    ]));

    $cached = get_transient($cache_key);

    // Гарантируем валидность данных
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
        'max' => $max ?: 0
    ];

    set_transient($cache_key, $result, ESHOP_FILTERS_CACHE_TTL);

    return $result;
}

/* =========================================================
 * AVAILABILITY ДЛЯ ВСЕХ ТАКСОНОМИЙ ОДНИМ SQL
 * 'pa_color' => [
        'yellow' => true,
        'blue'   => true,
    ],
    'pa_size' => [
        'large' => true,
    ]
    доступен / недоступен (при кликах на checkbox)
 * ========================================================= */

function eshop_get_all_availability_map($base_context, $active_filters, $attribute_taxonomies)
{
    global $wpdb;

    $mode = ESHOP_FILTERS_AVAILABILITY_MODE;

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

    // Если нет атрибутов - нечего считать
    if (!$attribute_taxonomies) {
        return [];
    }

    $union_parts = [];
    $union_params = [];

    if ($mode === 'strict') {
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

            $union_params[] = $tax;

            foreach ($w['params'] as $pp) {
                $union_params[] = $pp;
            }

            $union_params[] = $tax;
        }
    } else {
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

            $union_params[] = $tax;

            foreach ($w['params'] as $pp) {
                $union_params[] = $pp;
            }

            $union_params[] = $tax;
        }
    }

    $sql = implode(' UNION ALL ', $union_parts);

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

/* =========================================================
 * Обертка под шаблон (для disabled = !$is_available && !$is_active;)
 * Активные и неактивные checkbox
 * ========================================================= */

function eshop_get_term_availability_fast($taxonomy, $terms, $all_availability_map)
{
    $available = $all_availability_map[$taxonomy] ?? [];
    $availability = [];

    foreach ($terms as $term) {
        $availability[$term->slug] = isset($available[$term->slug]);
    }

    return $availability;
}

/* =========================================================
 * ДАННЫЕ ДЛЯ ШАБЛОНА
 * ========================================================= */

function eshop_get_filters_view_data()
{
    $base_context = eshop_get_base_context();
    $active_filters = eshop_get_active_filters();

    // получаем список атрибутов [pa_color, pa_size]
    $attribute_taxonomies = eshop_get_allowed_attribute_taxonomies();

    // price range (SQL)
    $price_range = eshop_get_catalog_price_range_sql($base_context, $active_filters);

    $min_price = isset($_GET['min_price'])
        ? max((int) $_GET['min_price'], (int) $price_range['min'])
        : (int) $price_range['min'];

    $max_price = isset($_GET['max_price'])
        ? min((int) $_GET['max_price'], (int) $price_range['max'])
        : (int) $price_range['max'];

    // availability map (один SQL)
    $all_availability_map = eshop_get_all_availability_map(
        $base_context,
        $active_filters,
        $attribute_taxonomies
    );

    // собираем filters_data под шаблон
    $filters_data = [];

    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $taxonomy = 'pa_' . $attribute->attribute_name;

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => true
        ]);

        if (!$terms || is_wp_error($terms)) {
            continue;
        }

        $availability = eshop_get_term_availability_fast($taxonomy, $terms, $all_availability_map);

        $filters_data[] = [
            'label' => $attribute->attribute_label,
            'taxonomy' => $taxonomy,
            'terms' => $terms,
            'active_terms' => $active_filters[$taxonomy] ?? [],
            'availability' => $availability
        ];
    }

    return [
        'filters_data' => $filters_data,
        'price_range' => $price_range,
        'min_price' => $min_price,
        'max_price' => $max_price
    ];
}
