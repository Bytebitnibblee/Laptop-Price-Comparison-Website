<?php
require_once '../config.php';
requireAdmin();

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM laptops WHERE id = ?");
    if ($stmt->execute([$id])) {
        $_SESSION['success'] = 'Laptop deleted successfully.';
    }
    redirect('laptops.php');
}

$laptops = $conn->query("
    SELECT l.*, 
           (SELECT MIN(price) FROM prices WHERE laptop_id = l.id) as lowest_price,
           (SELECT COUNT(*) FROM price_alerts WHERE laptop_id = l.id) as alert_count
    FROM laptops l
    ORDER BY l.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/theme-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Laptops - Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <?php include 'partials/nav-styles.php'; ?>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include 'partials/admin-nav.php'; ?>

    <main class="container">
        <div class="admin-toolbar">
            <h1>Manage Laptops</h1>
            <a href="add-laptop.php" class="btn btn-primary">Add New Laptop</a>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Brand</th>
                        <th>Specs</th>
                        <th>Lowest Price</th>
                        <th>Alerts</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($laptops as $laptop): ?>
                    <tr>
                        <td><img src="<?php echo htmlspecialchars($laptop['image_url']); ?>" alt="" class="laptop-thumb"></td>
                        <td><?php echo htmlspecialchars($laptop['name']); ?></td>
                        <td><?php echo htmlspecialchars($laptop['brand']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($laptop['processor']); ?><br>
                            <?php echo htmlspecialchars($laptop['ram']); ?> • <?php echo htmlspecialchars($laptop['storage']); ?>
                        </td>
                        <td><?php echo $laptop['lowest_price'] ? formatPrice($laptop['lowest_price']) : 'N/A'; ?></td>
                        <td><?php echo $laptop['alert_count']; ?></td>
                        <td>
                            <a href="../product.php?id=<?php echo $laptop['id']; ?>" class="btn btn-sm btn-secondary">View</a>
                            <a href="laptops.php?delete=<?php echo $laptop['id']; ?>" 
                               class="btn btn-sm btn-danger"
                               onclick="return confirm('Delete this laptop? This will also delete all associated prices and alerts.');">
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

