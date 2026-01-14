<?php
$data = eshop_get_filters_view_data();

$filters_data = $data['filters_data'];
$price_range = $data['price_range'];
$min_price = $data['min_price'];
$max_price = $data['max_price'];
?>

<div class="sidebar">
    <button class="btn btn-warning w-100 text-start collapse-filters-btn mb-3" type="button"
        data-bs-toggle="collapse" data-bs-target="#collapseFilters" aria-expanded="false"
        aria-controls="collapseFilters">
        <i class="fa-solid fa-filter"></i> Filters
    </button>
    <div class="collapse collapse-filters-content" id="collapseFilters">

        <!-- ===== ФИЛЬТР ПО ЦЕНЕ ===== -->

        <div class="filter-block">
            <h5 class="section-title">
                <span class="bg-white"><?php _e('Filter by price', 'eshop'); ?></span>
            </h5>
            <div class="mb-2 d-flex gap-2 filter-price">
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

            <div class="price-slider d-flex justify-content-between filter-price">
                <input type="range"
                    id="price-min"
                    class="form-range"
                    name="min_price"
                    min="<?php echo (int) $price_range['min']; ?>"
                    max="<?php echo (int) $price_range['max']; ?>"
                    value="<?php echo (int) $min_price; ?>">

                <input type="range"
                    id="price-max"
                    class="form-range"
                    name="max_price"
                    min="<?php echo (int) $price_range['min']; ?>"
                    max="<?php echo (int) $price_range['max']; ?>"
                    value="<?php echo (int) $max_price; ?>">
            </div>
        </div>

        <!-- ===== ФИЛЬТРЫ ПО АТРИБУТАМ ===== -->

        <?php foreach ($filters_data as $filter) : ?>

            <div class="filter-block">
                <h5 class="section-title">
                    <span class="bg-white">Filter by <?php echo $filter['label']; ?></span>
                </h5>

                <div class="filter-attributes" data-taxonomy="<?php echo $filter['taxonomy']; ?>">
                    <?php foreach ($filter['terms'] as $term) :

                        $is_active = in_array($term->slug, $filter['active_terms'], true);
                        $is_available = $filter['availability'][$term->slug] ?? false;
                        $disabled = !$is_active && !$is_available;

                        // уникальный id
                        $input_id = 'filter-' . $filter['taxonomy'] . '-' . $term->slug;

                    ?>

                        <div class="form-check d-flex justify-content-between align-items-center">
                            <div>
                                <input class="form-check-input"
                                    type="checkbox"
                                    value="<?php echo $term->slug; ?>"
                                    id="<?php echo $input_id; ?>"
                                    <?php checked($is_active); ?>
                                    <?php disabled($disabled); ?>>

                                <label class="form-check-label <?php echo $disabled ? 'text-muted' : ''; ?>" for="<?php echo $input_id; ?>">
                                    <?php echo $term->name; ?>
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