(function () {
    if (
        !window.eshop_global_object ||
        !eshop_global_object.filters ||
        !eshop_global_object.filters.price
    ) {
        // фильтр не активен на этой странице
        return;
    }

    // ==================================================
    // ОТКЛЮЧАЕМ submit у woocommerce-ordering
    // ==================================================

    const nativeSubmit = HTMLFormElement.prototype.submit;

    HTMLFormElement.prototype.submit = function () {
        if (this.classList.contains('woocommerce-ordering')) {
            return;
        }

        return nativeSubmit.call(this);
    };

    // ==================================================
    // ВСПОМОГАТЕЛЬНОЕ: debounce
    // ==================================================

    function debounce(fn, delay = 200) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), delay);
        };
    }

    // ==================================================
    // ВСПОМОГАТЕЛЬНОЕ: сброс страницы (paged)
    // ==================================================

    function resetPage(url) {
        url.searchParams.delete('paged');
        url.pathname = url.pathname.replace(/\/page\/\d+\//, '/');
    }

    function taxonomyToFilterKey(taxonomy) {
        // pa_color → filter_color
        if (taxonomy.startsWith('pa_')) {
            return 'filter_' + taxonomy.replace(/^pa_/, '');
        }
        return taxonomy;
    }

    function taxonomyToQueryTypeKey(taxonomy) {
        // pa_color → query_type_color
        if (taxonomy.startsWith('pa_')) {
            return 'query_type_' + taxonomy.replace(/^pa_/, '');
        }
        return null;
    }

    // ==================================================
    // SYNC SIDEBAR FROM URL (SOURCE OF TRUTH = URL)
    // ==================================================

    function syncSidebarFromURL() {
        const params = new URLSearchParams(window.location.search);

        document.querySelectorAll('.filter-attributes').forEach(block => {
            const taxonomy = block.dataset.taxonomy;
            const key = taxonomyToFilterKey(taxonomy);

            const values = params.get(key)?.split(',') || [];

            block.querySelectorAll('input[type="checkbox"]').forEach(input => {
                input.checked = values.includes(input.value);
            });
        });
    }

    // ==================================================
    // AJAX ЗАГРУЗКА ТОВАРОВ
    // ==================================================

    let productsAbortController = null;

    async function loadProducts(url) {
        // отменяем предыдущий запрос
        if (productsAbortController) {
            productsAbortController.abort();
        }

        productsAbortController = new AbortController();

        let loopContainer = document.querySelector('.eshop-loop-container');
        if (!loopContainer) return;

        loopContainer.classList.add('is-loading');

        try {
            const res = await fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: productsAbortController.signal
            });

            const html = await res.text();
            let temp = document.createElement('div');
            temp.innerHTML = html;

            // обновляем title
            let newTitle = temp.querySelector('title');

            if (newTitle) {
                document.title = newTitle.textContent;
            }

            let newLoopContainer = temp.querySelector('.eshop-loop-container');

            if (newLoopContainer) {
                loopContainer.replaceWith(newLoopContainer);
            }

            history.pushState({}, '', url);
            syncSidebarFromURL();
        } finally {
            let updatedLoopContainer = document.querySelector('.eshop-loop-container');

            if (updatedLoopContainer) {
                updatedLoopContainer.classList.remove('is-loading');
            }
        }
    }

    // ==================================================
    // СБОР URL ФИЛЬТРОВ
    // ==================================================

    function buildFilterURL() {
        const url = new URL(window.location.href);

        // атрибуты
        document.querySelectorAll('.filter-attributes').forEach(block => {
            const taxonomy = block.dataset.taxonomy;
            const key = taxonomyToFilterKey(taxonomy);
            const queryTypeKey = taxonomyToQueryTypeKey(taxonomy);

            const values = [...block.querySelectorAll('input:checked')].map(i => i.value);

            if (values.length) {
                url.searchParams.set(key, values.join(','));

                if (values.length > 1 && queryTypeKey) {
                    url.searchParams.set(queryTypeKey, 'or');
                } else if (queryTypeKey) {
                    url.searchParams.delete(queryTypeKey);
                }
            } else {
                url.searchParams.delete(key);

                if (queryTypeKey) {
                    url.searchParams.delete(queryTypeKey);
                }
            }
        });

        // --- цена ---
        const min = parseInt(document.querySelector('[name="min_price"]')?.value, 10);
        const max = parseInt(document.querySelector('[name="max_price"]')?.value, 10);

        const catalogMin = eshop_global_object.filters.price.min;
        const catalogMax = eshop_global_object.filters.price.max;

        if (min > catalogMin) {
            url.searchParams.set('min_price', min);
        } else {
            url.searchParams.delete('min_price');
        }

        if (max < catalogMax) {
            url.searchParams.set('max_price', max);
        } else {
            url.searchParams.delete('max_price');
        }

        // --- сбрасываем страницу --- //
        resetPage(url);

        return url;
    }

    // ==================================================
    // PRICE RANGE SYNC
    // ==================================================

    const minInput = document.querySelector('[name="min_price"]');
    const maxInput = document.querySelector('[name="max_price"]');
    const minRange = document.getElementById('price-min');
    const maxRange = document.getElementById('price-max');

    const applyPriceFilter = debounce(() => {
        loadProducts(buildFilterURL());
    }, 200);

    function syncPrice() {
        let min = parseInt(minRange.value, 10);
        let max = parseInt(maxRange.value, 10);

        if (min > max) [min, max] = [max, min];

        minRange.value = min;
        maxRange.value = max;
        minInput.value = min;
        maxInput.value = max;

        applyPriceFilter();
    }

    minRange?.addEventListener('input', syncPrice);
    maxRange?.addEventListener('input', syncPrice);

    // ==================================================
    // CHANGE EVENTS
    // ==================================================

    document.addEventListener('change', e => {

        // сортировка
        const orderby = e.target.closest('form.woocommerce-ordering select[name="orderby"]');

        if (orderby) {
            const url = new URL(window.location.href);
            resetPage(url);
            url.searchParams.set('orderby', orderby.value);
            loadProducts(url);
            return;
        }

        // фильтры
        if (
            e.target.matches('.filter-attributes input') ||
            e.target.matches('[name="min_price"], [name="max_price"]')
        ) {
            loadProducts(buildFilterURL());
        }
    });

    // ==================================================
    // RESET FILTERS
    // ==================================================

    document.querySelector('.js-reset-filters')?.addEventListener('click', () => {
        // очищаем /page/X/
        let cleanPath = window.location.pathname.replace(/\/page\/\d+\/?$/, '/');
        const url = new URL(cleanPath, window.location.origin);

        // сбрасываем чекбоксы
        document.querySelectorAll('.filter-attributes input').forEach(i => {
            i.checked = false;
        });

        // сбрасываем price
        if (minInput && maxInput && minRange && maxRange) {
            minInput.value = eshop_global_object.filters.price.min;
            maxInput.value = eshop_global_object.filters.price.max;

            minRange.value = eshop_global_object.filters.price.min;
            maxRange.value = eshop_global_object.filters.price.max;
        }

        // --- сбрасываем сортировку --- //
        let orderSelect = document.querySelector(
            'form.woocommerce-ordering select[name="orderby"]'
        );

        if (orderSelect) {
            orderSelect.value = 'menu_order';
        }

        // --- удаляем orderby из URL ---
        url.searchParams.delete('orderby');

        url.searchParams.delete('min_price');
        url.searchParams.delete('max_price');

        loadProducts(url);
    });

    // ==================================================
    // BACK / FORWARD
    // ==================================================

    window.addEventListener('popstate', () => {
        loadProducts(new URL(window.location.href));
        syncSidebarFromURL();
    });

    // ==================================================
    // INITIAL PRICE RANGE SYNC (ON PAGE LOAD)
    // ==================================================

    document.addEventListener('DOMContentLoaded', () => {
        if (!minRange || !maxRange) return;

        minRange.min = eshop_global_object.filters.price.min;
        minRange.max = eshop_global_object.filters.price.max;
        maxRange.min = eshop_global_object.filters.price.min;
        maxRange.max = eshop_global_object.filters.price.max;

        syncSidebarFromURL();
    });

})();