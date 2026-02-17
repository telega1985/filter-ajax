<?php

/* =========================================================
 * НАСТРОЙКИ
 * ========================================================= */

if (!defined('GEOSTROYKA_FILTERS_CACHE_TTL')) {
    define('GEOSTROYKA_FILTERS_CACHE_TTL', 10 * MINUTE_IN_SECONDS);
}

/**
 * availability mode:
 * - 'potential'  => faceted (рекомендуется): availability по каждой таксе = исключаем текущую таксу
 * - 'strict'     => availability строго по текущей выдаче (не исключаем текущую таксу)
 */
if (!defined('GEOSTROYKA_FILTERS_AVAILABILITY_MODE')) {
    define('GEOSTROYKA_FILTERS_AVAILABILITY_MODE', 'potential');
}

/**
 * price range mode:
 * - 'base'       => только контекст (категория/магазин),
 * - 'filtered'   => по текущим активным фильтрам (кроме min/max) — динамический диапазон
 */

if (!defined('GEOSTROYKA_FILTERS_PRICE_RANGE_MODE')) {
    define('GEOSTROYKA_FILTERS_PRICE_RANGE_MODE', 'base');
}

/* =========================================================
 * БАЗОВЫЙ КОНТЕКСТ (категория)
 * [type => category_name, term_id => category_id]
 * ========================================================= */

function geostroyka_get_base_context()
{
    // Категория товара
    if (is_product_category()) {
        $term = get_queried_object();

        return [
            'type'    => 'product_cat',
            'term_id' => $term->term_id
        ];
    }

    // Магазин (shop)
    if (is_shop()) {
        return [
            'type' => 'shop'
        ];
    }

    // Защита от null
    return [
        'type' => 'none'
    ];
}

/* =========================================================
 * ПОЛУЧАЕМ ВСЕ АТРИБУТЫ МАГАЗИНА
 * ['pa_color', 'pa_size']
 * ========================================================= */

function geostroyka_get_allowed_attribute_taxonomies()
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

function geostroyka_get_active_filters()
{
    $allowed = array_flip(geostroyka_get_allowed_attribute_taxonomies());
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

function geostroyka_sql_products_where($base_context, $active_filters, $exclude_taxonomy = null)
{
    global $wpdb;

    $where  = [];
    $params = [];

    // Родительский товар
    $where[] = "p.post_type = 'product'";
    $where[] = "p.post_status = 'publish'";

    /*
     * ===============================
     * ФИЛЬТР ПО КАТЕГОРИИ + CHILDREN
     * ===============================
     */
    if (
        isset($base_context['type']) &&
        $base_context['type'] === 'product_cat' &&
        !empty($base_context['term_id'])
    ) {

        // Получаем текущую категорию + всех потомков
        $term_ids = get_terms([
            'taxonomy'   => 'product_cat',
            'child_of'   => (int) $base_context['term_id'],
            'fields'     => 'ids',
            'hide_empty' => false,
        ]);

        // Добавляем саму текущую категорию
        $term_ids[] = (int) $base_context['term_id'];

        $term_ids = array_unique(array_map('intval', $term_ids));

        if ($term_ids) {

            $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));

            $where[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} trc
                INNER JOIN {$wpdb->term_taxonomy} ttc
                    ON ttc.term_taxonomy_id = trc.term_taxonomy_id
                WHERE trc.object_id = p.ID
                  AND ttc.taxonomy = 'product_cat'
                  AND ttc.term_id IN ($placeholders)
            )";

            foreach ($term_ids as $id) {
                $params[] = $id;
            }
        }
    }

    /*
     * ===============================
     * ФИЛЬТР ПО АТРИБУТАМ (вариации)
     * ===============================
     */
    foreach ($active_filters as $taxonomy => $slugs) {

        if ($exclude_taxonomy && $taxonomy === $exclude_taxonomy) {
            continue;
        }

        if (empty($slugs)) {
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
            WHERE tr.object_id = v.ID
              AND tt.taxonomy = %s
              AND t.slug IN ($placeholders)
        )";

        $params[] = $taxonomy;

        foreach ($slugs as $slug) {
            $params[] = $slug;
        }
    }

    return [
        'sql'    => implode(' AND ', $where),
        'params' => $params
    ];
}

/* =========================================================
 * PRICE RANGE через SQL MIN/MAX
 * ========================================================= */

function geostroyka_get_catalog_price_range_sql($base_context, $active_filters)
{
    global $wpdb;

    $mode = GEOSTROYKA_FILTERS_PRICE_RANGE_MODE;

    $filters_for_range = $active_filters;

    if ($mode === 'base') {
        $filters_for_range = [];
    }

    $cache_key = 'geostroyka_price_range_' . md5(serialize([
        'mode' => $mode,
        'base' => $base_context,
        'filters' => $filters_for_range
    ]));

    $cached = get_transient($cache_key);

    // Гарантируем валидность данных
    if (is_array($cached) && isset($cached['min'], $cached['max'])) {
        return $cached;
    }

    $w = geostroyka_sql_products_where($base_context, $filters_for_range, null);

    // MIN/MAX по _price
    // CAST чтобы корректно сравнивалось как число
    $sql = "
        SELECT
            MIN(CAST(pm.meta_value AS DECIMAL(12,2))) AS min_price,
            MAX(CAST(pm.meta_value AS DECIMAL(12,2))) AS max_price
        FROM {$wpdb->posts} v
        INNER JOIN {$wpdb->posts} p
            ON p.ID = v.post_parent
        INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = v.ID
            AND pm.meta_key = '_price'
        WHERE v.post_type = 'product_variation'
          AND v.post_status = 'publish'
          AND {$w['sql']}
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

    set_transient($cache_key, $result, GEOSTROYKA_FILTERS_CACHE_TTL);

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

function geostroyka_get_all_availability_map($base_context, $active_filters, $attribute_taxonomies)
{
    global $wpdb;

    $mode = GEOSTROYKA_FILTERS_AVAILABILITY_MODE;

    $cache_key = 'geostroyka_availability_all_' . md5(serialize([
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
            $w = geostroyka_sql_products_where($base_context, $active_filters, $tax);

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
            $w = geostroyka_sql_products_where($base_context, $active_filters, $tax);

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

    set_transient($cache_key, $map, GEOSTROYKA_FILTERS_CACHE_TTL);

    return $map;
}

/* =========================================================
 * Обертка под шаблон (для disabled = !$is_available && !$is_active;)
 * Активные и неактивные checkbox
 * ========================================================= */

function geostroyka_get_term_availability_fast($taxonomy, $terms, $all_availability_map)
{
    $available = $all_availability_map[$taxonomy] ?? [];
    $availability = [];

    foreach ($terms as $term) {
        $availability[$term->slug] = isset($available[$term->slug]);
    }

    return $availability;
}

function geostroyka_get_product_ids_by_context($base_context)
{
    global $wpdb;

    if (empty($base_context['term_id'])) {
        return [];
    }

    $sql = "
        SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr
            ON tr.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_taxonomy_id = tr.term_taxonomy_id
        WHERE p.post_type = 'product'
          AND p.post_status = 'publish'
          AND tt.taxonomy = 'product_cat'
          AND tt.term_id = %d
    ";

    return $wpdb->get_col(
        $wpdb->prepare($sql, $base_context['term_id'])
    );
}

/* =========================================================
 * ДАННЫЕ ДЛЯ ШАБЛОНА
 * ========================================================= */

function geostroyka_get_filters_view_data()
{
    $base_context = geostroyka_get_base_context();
    $active_filters = geostroyka_get_active_filters();

    // получаем список атрибутов [pa_color, pa_size]
    $attribute_taxonomies = geostroyka_get_allowed_attribute_taxonomies();

    // price range (SQL)
    $price_range = geostroyka_get_catalog_price_range_sql($base_context, $active_filters);

    $min_price = isset($_GET['min_price'])
        ? max((int) $_GET['min_price'], (int) $price_range['min'])
        : (int) $price_range['min'];

    $max_price = isset($_GET['max_price'])
        ? min((int) $_GET['max_price'], (int) $price_range['max'])
        : (int) $price_range['max'];

    // availability map (один SQL)
    $all_availability_map = geostroyka_get_all_availability_map(
        $base_context,
        $active_filters,
        $attribute_taxonomies
    );

    // собираем filters_data под шаблон
    $filters_view = [];

    foreach ($attribute_taxonomies as $taxonomy) {

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'object_ids' => geostroyka_get_product_ids_by_context($base_context)
        ]);

        if (is_wp_error($terms) || !$terms) {
            continue;
        }

        $availability = geostroyka_get_term_availability_fast(
            $taxonomy,
            $terms,
            $all_availability_map
        );

        $visible_terms = [];
        $has_active = false;

        foreach ($terms as $term) {
            $is_available = !empty($availability[$term->slug]);
            $is_active    = in_array($term->slug, $active_filters[$taxonomy] ?? [], true);

            if ($is_active) {
                $has_active = true;
            }

            if ($is_available || $is_active) {
                $visible_terms[] = $term;
            }
        }

        if (!$visible_terms && !$has_active) {
            continue;
        }

        if (count($visible_terms) < 2) {
            continue;
        }

        $filters_view[$taxonomy] = [
            'taxonomy'     => $taxonomy,
            'label'        => wc_attribute_label($taxonomy),
            'terms'        => $visible_terms,
            'active_terms' => $active_filters[$taxonomy] ?? [],
            'availability' => $availability,
        ];
    }

    return [
        'filters_data' => $filters_view,
        'price_range' => $price_range,
        'min_price' => $min_price,
        'max_price' => $max_price
    ];
}

// DELETE FROM wp_options WHERE option_name LIKE '_transient_geostroyka_%' OR option_name LIKE '_transient_timeout_geostroyka_%';