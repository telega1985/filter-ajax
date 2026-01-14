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

    // Любое изменение фильтра (нажатие на checkbox) нас перекидывает на первую страницу
    function resetPage(url) {
        url.searchParams.delete('paged');
        url.pathname = url.pathname.replace(/\/page\/\d+\//, '/');
    }

    function taxonomyToFilterKey(taxonomy) {
        // pa_color -> filter_color
        if (taxonomy.startsWith('pa_')) {
            return 'filter_' + taxonomy.replace(/^pa_/, '');
        }

        return taxonomy;
    }

    // ==================================================
    // ОБЩЕЕ ДЛЯ АТРИБУТОВ И КНОПОК
    // ==================================================

    function updateFilterParam(url, key, values) {
        if (values.length) {
            // ?filter_color=blue
            url.searchParams.set(key, values.join(','));
        } else {
            url.searchParams.delete(key);
        }
    }

    // ==================================================
    // SYNC SIDEBAR FROM URL (SOURCE OF TRUTH = URL)
    // Синхронизация checkbox фильтра с URL (делаем фильтр предсказуемым)
    // ==================================================

    function syncSidebarFromURL() {
        // берем все параметры из адресной строки: ?filter_color=blue,yellow&filter_size=large
        const params = new URLSearchParams(window.location.search);

        document.querySelectorAll('.filter-attributes').forEach(block => {
            const taxonomy = block.dataset.taxonomy;
            const key = taxonomyToFilterKey(taxonomy);

            // строка из URL: ['blue', 'yellow'] (делаем массив)
            const values = params.get(key)?.split(',') || [];

            // ставим галочки строго по URL, а не по памяти браузера
            block.querySelectorAll('input[type="checkbox"]').forEach(input => {
                input.checked = values.includes(input.value);
            });
        });
    }

    // ==================================================
    // СОЗДАНИЕ КНОПОК ПРИ КЛИКЕ НА ФИЛЬТРАХ
    // ==================================================

    function renderActiveFilters() {
        let container = document.querySelector('.eshop-active-filters');

        if (!container) return;

        container.innerHTML = '';

        const params = new URLSearchParams(window.location.search);

        let hasFilters = false;

        // ------ АТРИБУТЫ ------
        params.forEach(function (value, key) {
            if (!key.startsWith('filter_')) return;

            let values = value.split(',');
            let label = key.replace('filter_', '');

            values.forEach(function (val) {
                hasFilters = true;

                container.insertAdjacentHTML(
                    'beforeend',
                    `<button type="button" class="filter-tag" data-key="${key}" data-value="${val}">
                        ${label}: ${val} ✕
                    </button>`
                );
            });
        });

        // ------- ЦЕНА -------
        let min = params.get('min_price');
        let max = params.get('max_price');

        if (min || max) {
            hasFilters = true;

            let text = 'price: ';

            if (min && max) {
                text += `${min} - ${max}`;
            } else if (min) {
                text += `from ${min}`;
            } else {
                text += `to ${max}`;
            }

            container.insertAdjacentHTML(
                'beforeend',
                `<button type="button" class="filter-tag" data-key="price">
                    ${text} ✕
                </button>`
            );
        }

        container.hidden = !hasFilters;
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
            renderActiveFilters();
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

        // --- цена ---
        let min = parseInt(document.querySelector('[name="min_price"]')?.value, 10);
        let max = parseInt(document.querySelector('[name="max_price"]')?.value, 10);

        let catalogMin = eshop_global_object.filters.price.min;
        let catalogMax = eshop_global_object.filters.price.max;

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

        // --- атрибуты (checkbox) ---
        document.querySelectorAll('.filter-attributes').forEach(block => {
            const taxonomy = block.dataset.taxonomy;
            const key = taxonomyToFilterKey(taxonomy);

            // все отмеченные чекбоксы внутри одного фильтра ['blue', 'gray']
            const values = [...block.querySelectorAll('input:checked')].map(i => i.value);

            updateFilterParam(url, key, values);
        });

        // --- чистим все query_type_ --- //
        [...url.searchParams.keys()].forEach(k => {
            if (k.startsWith('query_type_')) {
                url.searchParams.delete(k);
            }
        });

        // --- сбрасываем страницу --- //
        resetPage(url);

        return url;
    }

    // ==================================================
    // PRICE RANGE SYNC (синхронизируем значения между range ↔ number)
    // ==================================================

    const minInput = document.querySelector('[name="min_price"]');
    const maxInput = document.querySelector('[name="max_price"]');
    const minRange = document.getElementById('price-min');
    const maxRange = document.getElementById('price-max');

    // запускаем фильтр один раз
    const applyPriceFilter = debounce(() => {
        loadProducts(buildFilterURL());
    }, 200);

    function syncPrice() {
        let min = parseInt(minRange.value, 10);
        let max = parseInt(maxRange.value, 10);

        // защита от пересечения ползунков
        if (min > max) {
            [min, max] = [max, min];
        }

        minRange.value = min;
        maxRange.value = max;
        minInput.value = min;
        maxInput.value = max;

        applyPriceFilter();
    }

    minRange?.addEventListener('input', syncPrice);
    maxRange?.addEventListener('input', syncPrice);

    // ==================================================
    // СБРОС ЦЕНЫ ДЛЯ СОБЫТИЙ
    // ==================================================

    function resetPriceUI() {
        if (!minInput || !maxInput || !minRange || !maxRange) return;

        const min = eshop_global_object.filters.price.min;
        const max = eshop_global_object.filters.price.max;

        minInput.value = min;
        maxInput.value = max;
        minRange.value = min;
        maxRange.value = max;
    }

    // ==================================================
    // CHANGE EVENTS
    // ==================================================

    document.addEventListener('change', function (e) {
        // price (number)
        if (e.target.matches('.filter-price input[type="number"]')) {
            syncPrice();
            return;
        }

        // сортировка
        const orderby = e.target.closest('form.woocommerce-ordering select[name="orderby"]');

        if (orderby) {
            const url = buildFilterURL();
            url.searchParams.set('orderby', orderby.value);
            resetPage(url);
            loadProducts(url);
            return;
        }

        // атрибуты
        if (e.target.matches('.filter-attributes input')) {
            loadProducts(buildFilterURL());
        }
    });

    // ==================================================
    // RESET FILTERS
    // ==================================================

    document.querySelector('.js-reset-filters')?.addEventListener('click', function () {
        // очищаем /page/X/
        let cleanPath = window.location.pathname.replace(/\/page\/\d+\/?$/, '/');
        const url = new URL(cleanPath, window.location.origin);

        // сбрасываем checkbox
        document.querySelectorAll('.filter-attributes input').forEach(i => {
            i.checked = false;
        });

        // сбрасываем price
        resetPriceUI();

        // сбрасываем сортировку
        let orderSelect = document.querySelector('form.woocommerce-ordering select[name="orderby"]');

        if (orderSelect) {
            orderSelect.value = 'menu_order';
        }

        // удаляем orderby из URL
        url.searchParams.delete('orderby');

        // удаляем price из URL
        url.searchParams.delete('min_price');
        url.searchParams.delete('max_price');

        loadProducts(url);
    });

    // ==================================================
    // УДАЛЕНИЕ КНОПОК ПРИ КЛИКЕ НА ФИЛЬТРАХ
    // ==================================================

    document.addEventListener('click', function (e) {
        let btn = e.target.closest('.filter-tag');

        if (!btn) return;

        const url = new URL(window.location.href);

        if (btn.dataset.key === 'price') {
            url.searchParams.delete('min_price');
            url.searchParams.delete('max_price');
            resetPriceUI();
        } else {
            let key = btn.dataset.key;
            let value = btn.dataset.value;

            let values = (url.searchParams.get(key) || '').split(',').filter(v => v && v !== value);

            updateFilterParam(url, key, values);
        }

        resetPage(url);
        loadProducts(url);
    });

    // ==================================================
    // BACK / FORWARD (для корректного отображения контента после нажатия кнопок браузера Назад / Вперёд)
    // ==================================================

    window.addEventListener('popstate', () => {
        loadProducts(new URL(window.location.href));
        syncSidebarFromURL();
        renderActiveFilters();
    });

    // ==================================================
    // INITIAL PRICE RANGE SYNC (ON PAGE LOAD)
    // Гарантия, что после загрузки страницы фильтр будет синхронизирован с URL и реальными данными
    // ==================================================

    document.addEventListener('DOMContentLoaded', () => {
        if (!minRange || !maxRange) return;

        minRange.min = eshop_global_object.filters.price.min;
        minRange.max = eshop_global_object.filters.price.max;
        maxRange.min = eshop_global_object.filters.price.min;
        maxRange.max = eshop_global_object.filters.price.max;

        syncSidebarFromURL();
        renderActiveFilters();
    });
})();