async function wishlistToggle(productId) {
    const res = await fetch(eshop_global_object.wishlist.rest_toggle_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': eshop_global_object.wishlist.rest_nonce
        },
        body: JSON.stringify({ product_id: productId })
    });

    let data = {};
    try {
        data = await res.json();
    } catch (e) { }

    return { res, data };
}

function showWishlistAuthModal() {
    if (typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById('wishlistAuthModal')
        ).show();
    }
}

jQuery(document).ready(function ($) {

    $('body').on('click', '.wishlist-icon', async function () {
        let $icon = $(this);
        let productId = $icon.data('id');
        let card = $icon.closest('[class*="col-"]');
        let loader = card.find('.ajax-loader');

        if (!eshop_global_object.wishlist.is_auth) {
            showWishlistAuthModal();
            return;
        }

        try {
            if (loader.length) loader.fadeIn();
            $icon.addClass('loading');

            const { res, data } = await wishlistToggle(productId);

            $icon.removeClass('loading');
            if (loader.length) loader.fadeOut();

            // если user не авторизован то показать это пользователю 1 раз
            if (res.status === 401) {
                showWishlistAuthModal();
                return;
            }

            if (!res.ok) {
                console.error(eshop_global_object.i18n.ajax_error, data);
                return;
            }

            $icon.toggleClass('in-wishlist', !!data.inWishlist);
            $('.wishlist-count').text(data.count);

            if (location.pathname === '/wishlist/' && data.action === 'removed') {
                if (card.length) {
                    card.fadeOut(function () {
                        $(this).remove();

                        let left = $('.product-card').length;

                        if (left === 0) {
                            $('.woocommerce').hide();
                            $('.wishlist-empty-message').fadeIn(200);
                        }
                    });
                }
            }
        } catch (err) {
            $icon.removeClass('loading');
            if (loader.length) loader.fadeOut();
            console.error(eshop_global_object.i18n.ajax_error, err);
        }
    });

});