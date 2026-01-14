<?php /* Template Name: Страница избранного */ ?>
<?php get_header() ?>

<main class="main">

    <div class="container">
        <div class="row">
            <div class="col-12">
                <nav class="breadcrumbs">
                    <ul>
                        <li><a href="<?php echo home_url('/') ?>"><?php _e('Home', 'eshop') ?></a></li>
                        <li><?php _e('Wishlist', 'eshop') ?></li>
                    </ul>
                </nav>
            </div>

            <div class="col-12">
                <h1 class="section-title h3 mb-3"><span><?php the_title() ?></span></h1>

                <?php if (is_user_logged_in()) : ?>

                    <?php $wishlist_ids = eshop_get_wishlist_db(); ?>

                    <div class="wishlist-empty-message" <?php if (!empty($wishlist_ids)) echo 'style="display:none;"'; ?>>
                        <p><?php _e('Wishlist is empty', 'eshop'); ?></p>
                    </div>

                    <?php if (!empty($wishlist_ids)) : ?>
                        <?php
                        $wishlist_csv = implode(',', $wishlist_ids);
                        echo do_shortcode("[products ids='{$wishlist_csv}' limit='8']");
                        ?>
                    <?php endif; ?>

                <?php else : ?>
                    <p><?php _e('Authorization required', 'eshop') ?></p>
                <?php endif; ?>

            </div>
        </div>
    </div>

</main>

<?php get_footer() ?>