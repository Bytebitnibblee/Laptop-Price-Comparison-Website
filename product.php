<?php
require_once 'config.php';

$laptop_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($laptop_id <= 0) {
    redirect('index.php');
}

// Get laptop details
$stmt = $conn->prepare("SELECT * FROM laptops WHERE id = ?");
$stmt->execute([$laptop_id]);
$laptop = $stmt->fetch();

if (!$laptop) {
    redirect('index.php');
}

// Get prices from all retailers
$priceStmt = $conn->prepare("SELECT * FROM prices WHERE laptop_id = ? ORDER BY price ASC");
$priceStmt->execute([$laptop_id]);
$prices = $priceStmt->fetchAll();

// Check if user has alert for this laptop
$hasAlert = false;
if (isLoggedIn()) {
    $alertStmt = $conn->prepare("SELECT id FROM price_alerts WHERE user_id = ? AND laptop_id = ? AND is_active = 1");
    $alertStmt->execute([$_SESSION['user_id'], $laptop_id]);
    $hasAlert = $alertStmt->fetch() ? true : false;
}

$lowestPrice = !empty($prices) ? $prices[0]['price'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/theme-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $laptop['name']; ?> - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <main class="container">
        <div class="product-container">
            <div class="product-image">
                <img src="<?php echo htmlspecialchars($laptop['image_url'] ?: 'https://via.placeholder.com/600x400?text=Laptop'); ?>" alt="<?php echo htmlspecialchars($laptop['name']); ?>">
            </div>
            
            <div class="product-details">
                <h1><?php echo $laptop['name']; ?></h1>
                <p class="brand"><?php echo $laptop['brand']; ?></p>
                
                <div class="specs-list">
                    <h3>Specifications</h3>
                    <ul>
                        <li><strong>Processor:</strong> <?php echo $laptop['processor']; ?></li>
                        <li><strong>RAM:</strong> <?php echo $laptop['ram']; ?></li>
                        <li><strong>Storage:</strong> <?php echo $laptop['storage']; ?></li>
                        <li><strong>Screen Size:</strong> <?php echo $laptop['screen_size']; ?></li>
                        <?php if ($laptop['specs']): ?>
                            <li><strong>Additional:</strong> <?php echo $laptop['specs']; ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="price-alert-box" id="alert">
                    <?php if (isLoggedIn()): ?>
                        <?php if ($hasAlert): ?>
                            <p class="alert-active">✓ You have an active price alert for this laptop</p>
                            <a href="my-alerts.php" class="btn btn-secondary">Manage Alerts</a>
                        <?php else: ?>
                            <button onclick="showAlertModal()" class="btn btn-primary">Set Price Alert</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>Want to get notified when price drops?</p>
                        <a href="login.php?redirect=product.php?id=<?php echo $laptop_id; ?>" class="btn btn-primary">Login to Set Alert</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Price Comparison Table -->
        <div class="price-comparison">
            <h2>Price Comparison</h2>
            <div class="price-table">
                <?php if (empty($prices)): ?>
                    <p>No prices available at the moment.</p>
                <?php else: ?>
                    <?php foreach ($prices as $index => $price): ?>
                        <div class="price-row <?php echo $index === 0 ? 'best-price' : ''; ?>">
                            <div class="retailer">
                                <h3><?php echo $price['retailer']; ?></h3>
                                <?php if ($index === 0): ?>
                                    <span class="badge">Best Price</span>
                                <?php endif; ?>
                            </div>
                            <div class="price-info">
                                <span class="price"><?php echo formatPrice($price['price']); ?></span>
                                <span class="stock <?php echo $price['in_stock'] ? 'in-stock' : 'out-stock'; ?>">
                                    <?php echo $price['in_stock'] ? 'In Stock' : 'Out of Stock'; ?>
                                </span>
                                <span class="updated">Updated <?php echo timeAgo($price['last_updated']); ?></span>
                            </div>
                            <div class="action">
                                <a href="<?php echo $price['product_url']; ?>" target="_blank" class="btn btn-secondary" <?php echo !$price['in_stock'] ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                                    View on <?php echo $price['retailer']; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <!-- Price Alert Modal -->
    <?php if (isLoggedIn() && !$hasAlert): ?>
    <div id="alertModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAlertModal()">&times;</span>
            <h2>Set Price Alert</h2>
            <p>Get notified when the price drops below your target.</p>
            <form method="POST" action="add-alert.php">
                <input type="hidden" name="laptop_id" value="<?php echo $laptop_id; ?>">
                <div class="form-group">
                    <label>Target Price (₹)</label>
                    <input type="number" name="target_price" required min="1" step="0.01" 
                           placeholder="<?php echo $lowestPrice; ?>" 
                           value="<?php echo $lowestPrice > 0 ? floor($lowestPrice * 0.9) : ''; ?>">
                    <small>Current lowest: <?php echo formatPrice($lowestPrice); ?></small>
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
    </script>
    <?php endif; ?>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>