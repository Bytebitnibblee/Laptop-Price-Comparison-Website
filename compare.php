<?php
require_once 'config.php';

// Accept either 'id' or 'group' parameter
$laptop_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$group_key = isset($_GET['group']) ? sanitize($_GET['group']) : '';

// If ID is provided, get the laptop and its group_key
if ($laptop_id) {
    $stmt = $conn->prepare("SELECT * FROM laptops WHERE id = ?");
    $stmt->execute([$laptop_id]);
    $singleLaptop = $stmt->fetch();
    
    if (!$singleLaptop) {
        $_SESSION['error'] = 'Laptop not found.';
        redirect('index.php');
    }
    
    $group_key = $singleLaptop['group_key'] ?: $singleLaptop['name'];
}

// If no group_key at this point, redirect
if (!$group_key) {
    $_SESSION['error'] = 'Invalid request.';
    redirect('index.php');
}

// Get all laptops in this group (or just the single laptop)
$stmt = $conn->prepare("SELECT * FROM laptops WHERE group_key = ? OR name = ?");
$stmt->execute([$group_key, $group_key]);
$laptops = $stmt->fetchAll();

if (empty($laptops)) {
    $_SESSION['error'] = 'No laptops found.';
    redirect('index.php');
}

// Get all prices for laptops in this group
$laptopIds = array_column($laptops, 'id');
$placeholders = str_repeat('?,', count($laptopIds) - 1) . '?';

$pricesStmt = $conn->prepare("
    SELECT p.*, l.name as laptop_name, l.image_url
    FROM prices p
    JOIN laptops l ON l.id = p.laptop_id
    WHERE p.laptop_id IN ($placeholders)
    ORDER BY p.price ASC
");
$pricesStmt->execute($laptopIds);
$allPrices = $pricesStmt->fetchAll();

// Group prices by retailer
$pricesByRetailer = [];
foreach ($allPrices as $price) {
    $retailer = $price['retailer'];
    if (!isset($pricesByRetailer[$retailer])) {
        $pricesByRetailer[$retailer] = [];
    }
    $pricesByRetailer[$retailer][] = $price;
}

// Get the best price across all retailers
$bestPrice = null;
$bestRetailer = null;
$bestPriceData = null;
foreach ($allPrices as $price) {
    if ($price['in_stock'] && ($bestPrice === null || $price['price'] < $bestPrice)) {
        $bestPrice = $price['price'];
        $bestRetailer = $price['retailer'];
        $bestPriceData = $price;
    }
}

// Check if user has alert for any laptop in this group
$hasAlert = false;
if (isLoggedIn()) {
    $alertStmt = $conn->prepare("SELECT id FROM price_alerts WHERE user_id = ? AND laptop_id IN ($placeholders) AND is_active = 1");
    $alertStmt->execute(array_merge([$_SESSION['user_id']], $laptopIds));
    $hasAlert = $alertStmt->fetch() ? true : false;
}

$laptop = $laptops[0]; // Use first laptop for basic info
$pageTitle = count($laptops) > 1 ? $group_key : $laptop['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Price Comparison - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .comparison-header {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .group-info h1 {
            margin: 0 0 0.5rem 0;
            color: var(--text-primary);
        }
        .group-meta {
            color: var(--text-secondary);
            margin: 0 0 1rem 0;
        }
        .best-price-banner {
            background: linear-gradient(135deg, rgba(245, 197, 24, 0.1), rgba(245, 197, 24, 0.05));
            border: 1px solid var(--accent-gold);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
        }
        .best-price-banner .label {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .best-price-banner .price {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--accent-gold);
        }
        .best-price-banner .retailer {
            color: var(--text-secondary);
        }
        .alert-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--card-border);
        }
        .alert-active {
            color: #4ade80;
            margin-bottom: 1rem;
        }
        .models-section {
            margin-bottom: 2rem;
        }
        .models-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .model-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .model-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }
        .model-info h3 {
            font-size: 1rem;
            margin: 0;
            color: var(--text-primary);
        }
        .model-info p {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin: 0;
        }
        .price-comparison {
            margin-bottom: 2rem;
        }
        .price-table {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .retailer-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1.5rem;
        }
        .retailer-section h3 {
            margin: 0 0 1rem 0;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .price-count {
            font-size: 0.85rem;
            color: var(--text-secondary);
            font-weight: 400;
        }
        .retailer-prices {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .price-row {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1rem;
            display: grid;
            grid-template-columns: 2fr 2fr 1fr;
            gap: 1rem;
            align-items: center;
        }
        .price-row.best-price {
            border-color: var(--accent-gold);
            background: linear-gradient(135deg, rgba(245, 197, 24, 0.05), rgba(245, 197, 24, 0.02));
        }
        .model-name {
            font-weight: 500;
            color: var(--text-primary);
        }
        .price-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .price-info .price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--accent-gold);
        }
        .stock {
            font-size: 0.85rem;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            display: inline-block;
            width: fit-content;
        }
        .in-stock {
            background: rgba(74, 222, 128, 0.1);
            color: #4ade80;
        }
        .out-stock {
            background: rgba(248, 113, 113, 0.1);
            color: #f87171;
        }
        .updated {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        .action {
            text-align: right;
        }
        @media (max-width: 768px) {
            .price-row {
                grid-template-columns: 1fr;
            }
            .action {
                text-align: left;
            }
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            position: relative;
        }
        .close {
            position: absolute;
            right: 1rem;
            top: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }
        .close:hover {
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <div class="comparison-header">
            <div class="group-info">
                <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                <p class="group-meta">
                    <?php echo htmlspecialchars($laptop['brand']); ?> •
                    <?php echo count($laptops); ?> model<?php echo count($laptops) > 1 ? 's' : ''; ?> •
                    <?php echo count($pricesByRetailer); ?> retailer<?php echo count($pricesByRetailer) > 1 ? 's' : ''; ?>
                </p>
                <?php if ($bestPrice): ?>
                    <div class="best-price-banner">
                        <span class="label">Best Price:</span>
                        <span class="price"><?php echo formatPrice($bestPrice); ?></span>
                        <span class="retailer">at <?php echo htmlspecialchars($bestRetailer); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="alert-section" id="alert">
                <?php if (isLoggedIn()): ?>
                    <?php if ($hasAlert): ?>
                        <p class="alert-active">✓ You have an active price alert for this laptop</p>
                        <a href="my-alerts.php" class="btn btn-secondary">Manage Alerts</a>
                    <?php else: ?>
                        <button onclick="showAlertModal()" class="btn btn-primary">Set Price Alert</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Want to get notified when prices drop?</p>
                    <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-primary">Login to Set Alert</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Models in this group -->
        <?php if (count($laptops) > 1): ?>
        <section class="models-section">
            <h2>Available Models in this Group</h2>
            <div class="models-grid">
                <?php foreach ($laptops as $model): ?>
                    <div class="model-card">
                        <img src="<?php echo htmlspecialchars($model['image_url'] ?: 'https://via.placeholder.com/200x150?text=Laptop'); ?>"
                             alt="<?php echo htmlspecialchars($model['name']); ?>">
                        <div class="model-info">
                            <h3><?php echo htmlspecialchars($model['name']); ?></h3>
                            <?php if ($model['processor'] || $model['ram']): ?>
                                <p>
                                    <?php if ($model['processor']): echo htmlspecialchars($model['processor']); endif; ?>
                                    <?php if ($model['processor'] && $model['ram']): echo ' • '; endif; ?>
                                    <?php if ($model['ram']): echo htmlspecialchars($model['ram']); endif; ?>
                                </p>
                            <?php elseif ($model['specs']): ?>
                                <p><?php echo htmlspecialchars($model['specs']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php else: ?>
        <section class="models-section">
            <h2>Specifications</h2>
            <div class="model-card" style="max-width: 600px;">
                <img src="<?php echo htmlspecialchars($laptop['image_url'] ?: 'https://via.placeholder.com/400x250?text=Laptop'); ?>"
                     alt="<?php echo htmlspecialchars($laptop['name']); ?>">
                <div class="model-info">
                    <?php if ($laptop['processor']): ?>
                        <p><strong>Processor:</strong> <?php echo htmlspecialchars($laptop['processor']); ?></p>
                    <?php endif; ?>
                    <?php if ($laptop['ram']): ?>
                        <p><strong>RAM:</strong> <?php echo htmlspecialchars($laptop['ram']); ?></p>
                    <?php endif; ?>
                    <?php if ($laptop['storage']): ?>
                        <p><strong>Storage:</strong> <?php echo htmlspecialchars($laptop['storage']); ?></p>
                    <?php endif; ?>
                    <?php if ($laptop['screen_size']): ?>
                        <p><strong>Screen:</strong> <?php echo htmlspecialchars($laptop['screen_size']); ?></p>
                    <?php endif; ?>
                    <?php if ($laptop['specs']): ?>
                        <p><strong>Additional:</strong> <?php echo htmlspecialchars($laptop['specs']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Price Comparison Table -->
        <section class="price-comparison">
            <h2>Price Comparison by Retailer</h2>
            <div class="price-table">
                <?php if (empty($pricesByRetailer)): ?>
                    <div class="retailer-section">
                        <p>No prices available at the moment. Check back soon!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pricesByRetailer as $retailer => $retailerPrices): ?>
                        <div class="retailer-section">
                            <h3><?php echo htmlspecialchars($retailer); ?> <span class="price-count">(<?php echo count($retailerPrices); ?> option<?php echo count($retailerPrices) > 1 ? 's' : ''; ?>)</span></h3>
                            <div class="retailer-prices">
                                <?php
                                usort($retailerPrices, function($a, $b) {
                                    return $a['price'] <=> $b['price'];
                                });
                                foreach ($retailerPrices as $index => $price):
                                ?>
                                    <div class="price-row <?php echo $price['price'] == $bestPrice && $price['retailer'] == $bestRetailer ? 'best-price' : ''; ?>">
                                        <div class="model-name">
                                            <?php echo htmlspecialchars($price['laptop_name']); ?>
                                            <?php if ($index === 0 && count($retailerPrices) > 1): ?>
                                                <span class="badge" style="background: var(--accent-gold); color: #1b1405; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.75rem; margin-left: 0.5rem;">
                                                    Best at <?php echo htmlspecialchars($retailer); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($price['price'] == $bestPrice && $price['retailer'] == $bestRetailer): ?>
                                                <span class="badge" style="background: var(--accent-gold); color: #1b1405; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.75rem; margin-left: 0.5rem;">
                                                    🏆 Best Overall
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="price-info">
                                            <span class="price"><?php echo formatPrice($price['price']); ?></span>
                                            <span class="stock <?php echo $price['in_stock'] ? 'in-stock' : 'out-stock'; ?>">
                                                <?php echo $price['in_stock'] ? '✓ In Stock' : '✗ Out of Stock'; ?>
                                            </span>
                                            <span class="updated">Updated <?php echo timeAgo($price['last_updated']); ?></span>
                                        </div>
                                        <div class="action">
                                            <?php if ($price['product_url']): ?>
                                                <a href="<?php echo htmlspecialchars($price['product_url']); ?>" 
                                                   target="_blank" 
                                                   rel="noopener noreferrer"
                                                   class="btn btn-secondary btn-sm"
                                                   <?php echo !$price['in_stock'] ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                                                    View on <?php echo htmlspecialchars($retailer); ?> →
                                                </a>
                                            <?php else: ?>
                                                <span style="color: var(--text-secondary); font-size: 0.9rem;">Link unavailable</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <!-- Price Alert Modal -->
    <?php if (isLoggedIn() && !$hasAlert): ?>
    <div id="alertModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAlertModal()">&times;</span>
            <h2>Set Price Alert</h2>
            <p>Get notified when the price drops below your target<?php echo count($laptops) > 1 ? ' for any model in this group' : ''; ?>.</p>
            <form method="POST" action="add-alert.php">
                <input type="hidden" name="laptop_id" value="<?php echo $laptop['id']; ?>">
                <div class="form-group">
                    <label>Target Price (₹)</label>
                    <input type="number" name="target_price" required min="1" step="0.01"
                           placeholder="<?php echo $bestPrice ?: '50000'; ?>"
                           value="<?php echo $bestPrice > 0 ? floor($bestPrice * 0.9) : ''; ?>">
                    <?php if ($bestPrice): ?>
                        <small>Current best: <?php echo formatPrice($bestPrice); ?> • Suggested: <?php echo formatPrice(floor($bestPrice * 0.9)); ?> (10% off)</small>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Create Alert</button>
            </form>
        </div>
    </div>

    <script>
        function showAlertModal() {
            document.getElementById('alertModal').style.display = 'flex';
        }

        function closeAlertModal() {
            document.getElementById('alertModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('alertModal');
            if (event.target === modal) {
                closeAlertModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAlertModal();
            }
        });
    </script>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
</body>
</html>