<?php

$data = eshop_get_filters_view_data();

$filters_data = $data['filters_data'];
$price_range  = $data['price_range'];
$min_price    = $data['min_price'];
$max_price    = $data['max_price'];

?>

<div class="sidebar">

    <!-- Кнопка сворачивания (mobile) -->
    <button
        class="btn btn-warning w-100 text-start collapse-filters-btn mb-3"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#collapseFilters"
        aria-expanded="false"
        aria-controls="collapseFilters">
        <i class="fa-solid fa-filter"></i> Filters
    </button>

    <div class="collapse collapse-filters-content" id="collapseFilters">

        <!-- ===== ФИЛЬТР ПО ЦЕНЕ ===== -->

        <div class="filter-block">
            <h5 class="section-title">
                <span class="bg-white"><?php esc_html_e('Filter by price', 'eshop'); ?></span>
            </h5>

            <div class="mb-2 d-flex gap-2">
                <input type="number"
                    class="form-control"
                    name="min_price"
                    min="<?php echo (int) $price_range['min']; ?>"
                    max="<?php echo (int) $price_range['max']; ?>"
                    value="<?php echo (int) $min_price; ?>">

                <input type="number"
                    class="form-control"
                    name="max_price"
                    min="<?php echo (int) $price_range['min']; ?>"
                    max="<?php echo (int) $price_range['max']; ?>"
                    value="<?php echo (int) $max_price; ?>">
            </div>
            <div class="price-slider d-flex justify-content-between">
                <input type="range"
                    id="price-min"
                    class="form-range"
                    min="<?php echo (int) $price_range['min']; ?>"
                    max="<?php echo (int) $price_range['max']; ?>"
                    value="<?php echo (int) $min_price; ?>">

                <input type="range"
                    id="price-max"
                    class="form-range"
                    min="<?php echo (int) $price_range['min']; ?>"
                    max="<?php echo (int) $price_range['max']; ?>"
                    value="<?php echo (int) $max_price; ?>">
            </div>
        </div>

        <!-- ===== ФИЛЬТРЫ ПО АТРИБУТАМ ===== -->

        <?php foreach ($filters_data as $filter): ?>

            <div class="filter-block">
                <h5 class="section-title">
                    <span class="bg-white">
                        <?php echo esc_html($filter['label']); ?>
                    </span>
                </h5>

                <div class="filter-attributes" data-taxonomy="<?php echo esc_attr($filter['taxonomy']); ?>">

                    <?php foreach ($filter['terms'] as $term):

                        $is_active = in_array($term->slug, $filter['active_terms'], true);
                        $is_available = $filter['availability'][$term->slug] ?? false;
                        $disabled = !$is_available && !$is_active;

                        // уникальный id
                        $input_id = 'filter-' . esc_attr($filter['taxonomy']) . '-' . esc_attr($term->slug);

                    ?>
                        <div class="form-check d-flex justify-content-between align-items-center">
                            <div>
                                <input type="checkbox"
                                    class="form-check-input"
                                    id="<?php echo $input_id; ?>"
                                    value="<?php echo esc_attr($term->slug); ?>"
                                    <?php checked($is_active); ?>
                                    <?php disabled($disabled); ?>>
                                <label class="form-check-label <?php echo $disabled ? 'text-muted' : ''; ?>" for="<?php echo $input_id; ?>">
                                    <?php echo esc_html($term->name); ?>
                                </label>
                            </div>
                            <span class="badge border rounded-0"><?php echo (int) $term->count; ?></span>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>

        <?php endforeach; ?>

        <!-- ===== СБРОС ФИЛЬТРОВ ===== -->
        <div class="filter-block">
            <button
                type="button"
                class="btn btn-outline-secondary w-100 js-reset-filters">
                <?php esc_html_e('Reset filters', 'eshop'); ?>
            </button>
        </div>

    </div>
</div>