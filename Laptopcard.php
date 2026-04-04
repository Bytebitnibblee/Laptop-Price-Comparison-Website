<?php
/**
 * includes/laptop_card.php
 * Expects $laptop array with keys:
 *   master_id, name, brand, processor, ram, storage, screen_size,
 *   image_url, display_price, in_stock_retailers, last_price_update, review_count
 */
$image_url    = !empty($laptop['image_url'])
                    ? $laptop['image_url']
                    : 'https://via.placeholder.com/400x250?text=Laptop';
$displayPrice = $laptop['display_price'] ?? null;
$retailers    = (int)($laptop['in_stock_retailers'] ?? 0);
$hasReview    = !empty($laptop['review_count']) && $laptop['review_count'] > 0;
?>
<article class="laptop-card">
    <img
        src="<?php echo htmlspecialchars($image_url); ?>"
        alt="<?php echo htmlspecialchars($laptop['name']); ?>"
        loading="lazy"
    >
    <div class="laptop-info">
        <h3>
            <?php echo htmlspecialchars($laptop['name']); ?>
            <?php if ($hasReview): ?>
                <span class="badge-review" title="Match confidence below 75% — verify listing">⚠ review</span>
            <?php endif; ?>
        </h3>

        <p class="specs">
            <?php echo htmlspecialchars($laptop['brand'] ?? ''); ?>
            <?php if (!empty($laptop['processor'])): ?>
                &bull; <?php echo htmlspecialchars($laptop['processor']); ?>
            <?php endif; ?>
            <?php if (!empty($laptop['ram'])): ?>
                &bull; <?php echo htmlspecialchars($laptop['ram']); ?>
            <?php endif; ?>
            <?php if (!empty($laptop['storage'])): ?>
                &bull; <?php echo htmlspecialchars($laptop['storage']); ?>
            <?php endif; ?>
        </p>

        <div class="price-info">
            <div>
                <span class="label">Best price</span>
                <span class="price">
                    <?php echo $displayPrice !== null ? formatPrice($displayPrice) : 'N/A'; ?>
                </span>
            </div>
            <span class="badge"><?php echo $retailers; ?> retailer<?php echo $retailers !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (!empty($laptop['last_price_update'])): ?>
            <p class="updated">Updated <?php echo timeAgo($laptop['last_price_update']); ?></p>
        <?php endif; ?>

        <div class="laptop-card-actions">
            <a href="compare.php?master_id=<?php echo (int)$laptop['master_id']; ?>"
               class="btn btn-primary btn-sm">Compare Prices</a>
            <?php if (isLoggedIn()): ?>
                <a href="compare.php?master_id=<?php echo (int)$laptop['master_id']; ?>#alert"
                   class="btn btn-secondary btn-sm">Set Alert</a>
            <?php else: ?>
                <a href="login.php?redirect=compare.php?master_id=<?php echo (int)$laptop['master_id']; ?>"
                   class="btn btn-secondary btn-sm">Login to Set Alert</a>
            <?php endif; ?>
        </div>
    </div>
</article>