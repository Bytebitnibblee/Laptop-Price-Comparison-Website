<?php
require_once '../config.php';
requireAdmin();

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM price_alerts WHERE id = ?");
    if ($stmt->execute([$id])) {
        $_SESSION['success'] = 'Alert deleted successfully.';
    }
    redirect('alerts.php');
}

$alerts = $conn->query("
    SELECT pa.*, u.name as user_name, u.email, l.name as laptop_name,
           (SELECT MIN(price) FROM prices WHERE laptop_id = pa.laptop_id) as current_price
    FROM price_alerts pa
    JOIN users u ON pa.user_id = u.id
    JOIN laptops l ON pa.laptop_id = l.id
    ORDER BY pa.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Alerts - Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <?php include 'partials/nav-styles.php'; ?>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include 'partials/admin-nav.php'; ?>

    <main class="container">
        <h1>Price Alerts</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Laptop</th>
                        <th>Target Price</th>
                        <th>Current Price</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $alert): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($alert['user_name']); ?><br>
                            <small style="color: #666;"><?php echo htmlspecialchars($alert['email']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($alert['laptop_name']); ?></td>
                        <td><?php echo formatPrice($alert['target_price']); ?></td>
                        <td class="<?php echo $alert['current_price'] !== null && $alert['current_price'] <= $alert['target_price'] ? 'price-met' : ''; ?>">
                            <?php echo $alert['current_price'] !== null ? formatPrice($alert['current_price']) : 'N/A'; ?>
                            <?php if ($alert['current_price'] !== null && $alert['current_price'] <= $alert['target_price']): ?>
                                <br><small>✓ Target Met!</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $alert['is_active'] ? '' : 'inactive-badge'; ?>">
                                <?php echo $alert['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td><?php echo timeAgo($alert['created_at']); ?></td>
                        <td>
                            <a href="../product.php?id=<?php echo $alert['laptop_id']; ?>" class="btn btn-sm btn-secondary">View</a>
                            <a href="alerts.php?delete=<?php echo $alert['id']; ?>" 
                               class="btn btn-sm btn-danger"
                               onclick="return confirm('Delete this alert?');">
                                Delete
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>

