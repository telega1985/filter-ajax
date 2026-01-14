<?php
$text  = $attributes['text'] ?? '';
$icon  = $attributes['icon'] ?? '';

if (!$text && !$icon) return;
?>

<div class="col-lg-3 col-sm-6">
    <div class="item">
        <?php if ($icon) : ?>
            <p><i class="<?php echo $icon ?>"></i></p>
        <?php endif; ?>

        <?php if ($text) : ?>
            <p><?php echo $text ?></p>
        <?php endif; ?>
    </div>
</div>