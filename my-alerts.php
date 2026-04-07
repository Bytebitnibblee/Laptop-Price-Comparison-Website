<?php
require_once 'config.php';

requireLogin();

$user_id = $_SESSION['user_id'];

// Get user's alerts
$stmt = $conn->prepare("
    SELECT pa.*, l.name, l.brand, l.image_url,
           (SELECT MIN(price) FROM prices WHERE laptop_id = l.id AND in_stock = 1) as current_price
    FROM price_alerts pa
    JOIN laptops l ON pa.laptop_id = l.id
    WHERE pa.user_id = ?
    ORDER BY pa.is_active DESC, pa.created_at DESC
");
$stmt->execute([$user_id]);
$alerts = $stmt->fetchAll();

// Handle delete request
if (isset($_GET['delete'])) {
    $alert_id = (int)$_GET['delete'];
    $deleteStmt = $conn->prepare("DELETE FROM price_alerts WHERE id = ? AND user_id = ?");
    if ($deleteStmt->execute([$alert_id, $user_id])) {
        $_SESSION['success'] = 'Alert deleted successfully.';
    }
    redirect('my-alerts.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/theme-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Price Alerts - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <main class="container">
        <div class="page-header">
            <h1>My Price Alerts</h1>
            <p>Manage your price drop notifications</p>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <div class="alerts-container">
            <?php if (empty($alerts)): ?>
                <div class="no-results">
                    <p>You don't have any price alerts yet.</p>
                    <a href="index.php" class="btn btn-primary">Browse Laptops</a>
                </div>
            <?php else: ?>
                <?php foreach ($alerts as $alert): ?>
                    <div class="alert-card <?php echo !$alert['is_active'] ? 'inactive' : ''; ?>">
                        <div class="alert-laptop">
                            <img src="<?php echo $alert['image_url']; ?>" alt="<?php echo $alert['name']; ?>">
                            <div class="alert-info">
                                <h3><?php echo $alert['name']; ?></h3>
                                <p class="brand"><?php echo $alert['brand']; ?></p>
                                <a href="product.php?id=<?php echo $alert['laptop_id']; ?>" class="link">View Product</a>
                            </div>
                        </div>
                        
                        <?php
                            $currentPrice = $alert['current_price'];
                            $targetPrice = $alert['target_price'];
                            $priceMet = $currentPrice !== null && $currentPrice <= $targetPrice;
                            $difference = $currentPrice !== null ? $currentPrice - $targetPrice : null;
                        ?>
                        <div class="alert-prices">
                            <div class="price-item">
                                <span class="label">Target Price</span>
                                <span class="value"><?php echo formatPrice($targetPrice); ?></span>
                            </div>
                            <div class="price-item">
                                <span class="label">Current Price</span>
                                <span class="value"><?php echo $currentPrice !== null ? formatPrice($currentPrice) : 'N/A'; ?></span>
                            </div>
                            <div class="price-item">
                                <span class="label">Difference</span>
                                <span class="value <?php echo $priceMet ? 'price-met' : ''; ?>">
                                    <?php
                                    if ($currentPrice === null) {
                                        echo 'Awaiting price data';
                                    } elseif ($priceMet) {
                                        echo '✓ Target Met!';
                                    } else {
                                        echo '-' . formatPrice(abs($difference));
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="alert-actions">
                            <span class="alert-date">Created <?php echo timeAgo($alert['created_at']); ?></span>
                            <?php if (!$alert['is_active']): ?>
                                <span class="badge inactive-badge">Inactive</span>
                            <?php endif; ?>
                            <a href="my-alerts.php?delete=<?php echo $alert['id']; ?>" 
                               class="btn btn-danger btn-sm" 
                               onclick="return confirm('Are you sure you want to delete this alert?')">
                                Delete
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>