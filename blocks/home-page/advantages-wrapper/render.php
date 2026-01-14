<?php
if ($content === '') return;
?>

<section class="advantages">
    <div class="container">
        <div class="row mb-5">
            <div class="col-12">
                <h2 class="section-title">
                    <span>Наши преимущества</span>
                </h2>
            </div>
        </div>

        <div class="row gy-3 items">
            <?php echo $content; ?>
        </div>
    </div>
</section>