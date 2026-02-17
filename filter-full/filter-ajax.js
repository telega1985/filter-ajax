(function () {
    if (
        !window.geostroyka_global_object ||
        !geostroyka_global_object.filters ||
        !geostroyka_global_object.filters.price
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
    // SYNC SIDEBAR FROM URL (SOURCE OF TRUTH = URL)
    // Синхронизация checkbox фильтра с URL (делаем фильтр предсказуемым)
    // ==================================================

    function syncSidebarFromURL() {

        // сбрасываем все чекбоксы
        document.querySelectorAll('.filter-attributes input[type="checkbox"]').forEach(cb => {
            cb.checked = false;
        });

        const path = window.location.pathname;

        if (!path.includes('/filter/')) {
            return;
        }

        let raw = path.split('/filter/')[1];
        if (!raw) return;

        raw = raw.replace(/\/$/, '');

        raw.split('/').forEach(seg => {

            if (!seg.includes('-')) return;

            const [name, rest] = seg.split('-', 2);
            if (!rest) return;

            // price-1200-9680
            if (name === 'price') {
                const [min, max] = rest.split('-');
                const minEl = document.querySelector('[name="min_price"]');
                const maxEl = document.querySelector('[name="max_price"]');
                if (minEl && min) minEl.value = parseInt(min, 10);
                if (maxEl && max) maxEl.value = parseInt(max, 10);
                return;
            }

            // brand-oscar_polygal
            rest.split('_').forEach(slug => {
                const input = document.querySelector(`.filter-attributes[data-taxonomy="pa_${name}"] input[value="${slug}"]`);
                if (input) input.checked = true;
            });
        });
    }

    // ==================================================
    // СОЗДАНИЕ КНОПОК ПРИ КЛИКЕ НА ФИЛЬТРАХ
    // ==================================================

    function renderActiveFilters() {

        const container = document.querySelector('.geostroyka-active-filters');
        if (!container) return;

        container.innerHTML = '';

        const path = window.location.pathname;

        if (!path.includes('/filter/')) {
            container.hidden = true;
            return;
        }

        let raw = path.split('/filter/')[1];
        if (!raw) {
            container.hidden = true;
            return;
        }

        raw = raw.replace(/\/$/, '');

        let hasFilters = false;

        raw.split('/').forEach(seg => {

            if (!seg.includes('-')) return;

            const [name, rest] = seg.split('-', 2);
            if (!rest) return;

            // -------- PRICE --------
            if (name === 'price') {

                const [min, max] = rest.split('-');

                let text = '';

                if (min && max) {
                    text = `price: ${min} – ${max}`;
                } else if (min) {
                    text = `price: from ${min}`;
                } else if (max) {
                    text = `price: to ${max}`;
                }

                hasFilters = true;

                container.insertAdjacentHTML(
                    'beforeend',
                    `<button type="button" class="filter-tag" data-key="price">
                    ${text} ✕
                </button>`
                );

                return;
            }

            // -------- ATTRIBUTE --------
            rest.split('_').forEach(slug => {

                hasFilters = true;

                container.insertAdjacentHTML(
                    'beforeend',
                    `<button type="button" class="filter-tag" data-key="${name}" data-value="${slug}">
                    ${name}: ${slug} ✕
                </button>`
                );

            });

        });

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

        let loopContainer = document.querySelector('.category-loop-container');

        if (!loopContainer) return;

        loopContainer.classList.add('is-loading');

        try {
            const res = await fetch(url, {
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

            let newLoopContainer = temp.querySelector('.category-loop-container');

            if (newLoopContainer) {
                loopContainer.replaceWith(newLoopContainer);
            }

            history.pushState({}, '', url);

            syncSidebarFromURL();
            renderActiveFilters();
        } finally {
            let updatedLoopContainer = document.querySelector('.category-loop-container');

            if (updatedLoopContainer) {
                updatedLoopContainer.classList.remove('is-loading');
            }
        }
    }

    // ==================================================
    // БАЗОВЫЙ URL ЧПУ
    // ==================================================

    function getBaseCategoryPath(pathname) {

        const parts = pathname.split('/').filter(Boolean);

        const filterIndex = parts.indexOf('filter');
        if (filterIndex !== -1) {
            parts.splice(filterIndex);
        }

        const pageIndex = parts.indexOf('page');
        if (pageIndex !== -1) {
            parts.splice(pageIndex);
        }

        return '/' + parts.join('/') + '/';
    }

    // ==================================================
    // СБОР URL ФИЛЬТРОВ
    // ==================================================

    function buildFilterURL() {

        const url = new URL(window.location.href);

        const basePath = getBaseCategoryPath(url.pathname);

        const segments = [];

        // --- цена ---
        let min = parseInt(document.querySelector('[name="min_price"]')?.value, 10);
        let max = parseInt(document.querySelector('[name="max_price"]')?.value, 10);

        let catalogMin = geostroyka_global_object.filters.price.min;
        let catalogMax = geostroyka_global_object.filters.price.max;

        if (!isNaN(min) && !isNaN(max) && (min > catalogMin || max < catalogMax)) {
            segments.push(`price-${min}-${max}`);
        }

        // --- атрибуты (checkbox) ---
        document.querySelectorAll('.filter-attributes').forEach(block => {

            const taxonomy = block.dataset.taxonomy;          // pa_brand
            const key = taxonomyToFilterKey(taxonomy);        // filter_brand
            const attrName = key.replace(/^filter_/, '');     // brand

            const values = [...block.querySelectorAll('input:checked')]
                .map(i => i.value)
                .filter(Boolean);

            if (values.length) {
                segments.push(`${attrName}-${values.join('_')}`);
            }

        });

        // --- если есть фильтр ---
        if (segments.length) {
            url.pathname = basePath.replace(/\/$/, '') + '/filter/' + segments.join('/') + '/';
        } else {
            url.pathname = basePath;
        }

        // --- чистим только старые query-параметры фильтра (но НЕ весь search) ---
        url.searchParams.delete('min_price');
        url.searchParams.delete('max_price');

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

        const min = geostroyka_global_object.filters.price.min;
        const max = geostroyka_global_object.filters.price.max;

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
        const orderby = e.target.closest('form.category-ordering select[name="orderby"]');

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

    document.querySelector('.filter-reset-btn')?.addEventListener('click', function () {
        const basePath = getBaseCategoryPath(window.location.pathname);

        const url = new URL(basePath, window.location.origin);

        // сбрасываем checkbox
        document.querySelectorAll('.filter-attributes input').forEach(i => {
            i.checked = false;
        });

        // сбрасываем price
        resetPriceUI();

        // сбрасываем сортировку
        let orderSelect = document.querySelector('form.category-ordering select[name="orderby"]');

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

        const btn = e.target.closest('.filter-tag');
        if (!btn) return;

        const key = btn.dataset.key;
        const value = btn.dataset.value;

        // PRICE
        if (key === 'price') {
            resetPriceUI();
        }
        // ATTRIBUTE
        else {
            const input = document.querySelector(
                `.filter-attributes[data-taxonomy="pa_${key}"] input[value="${value}"]`
            );

            if (input) {
                input.checked = false;
            }
        }

        // Не собираем URL вручную
        const url = buildFilterURL();
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

        minRange.min = geostroyka_global_object.filters.price.min;
        minRange.max = geostroyka_global_object.filters.price.max;
        maxRange.min = geostroyka_global_object.filters.price.min;
        maxRange.max = geostroyka_global_object.filters.price.max;

        syncSidebarFromURL();
        renderActiveFilters();
    });
})();