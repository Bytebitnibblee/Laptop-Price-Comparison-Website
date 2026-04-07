<?php
require_once '../config.php';
requireAdmin();

// Handle delete action
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM price_alerts WHERE id = ?");
    if ($stmt->execute([$id])) {
        $_SESSION['success'] = 'Alert deleted successfully.';
    }
    redirect('alerts.php');
}

// Handle send email action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_alert'])) {
    $id = (int)$_POST['alert_id'];
    
    // Get alert details
    $stmt = $conn->prepare("
        SELECT pa.*, u.name as user_name, u.email, l.name as laptop_name,
               (SELECT MIN(price) FROM prices WHERE laptop_id = pa.laptop_id AND in_stock = 1) as current_price
        FROM price_alerts pa
        JOIN users u ON pa.user_id = u.id
        JOIN laptops l ON pa.laptop_id = l.id
        WHERE pa.id = ?
    ");
    $stmt->execute([$id]);
    $alert = $stmt->fetch();
    
    if ($alert) {
        if (sendPriceAlertEmail(
            $alert['email'],
            $alert['user_name'],
            $alert['laptop_name'],
            $alert['target_price'],
            $alert['current_price'],
            $alert['laptop_id']
        )) {
            // Update last_notified
            $updateStmt = $conn->prepare("UPDATE price_alerts SET last_notified = NOW() WHERE id = ?");
            $updateStmt->execute([$id]);
            $_SESSION['success'] = 'Alert email sent successfully to ' . htmlspecialchars($alert['email']);
        } else {
            $_SESSION['error'] = 'Failed to send email. Please check your mail configuration.';
        }
    } else {
        $_SESSION['error'] = 'Alert not found.';
    }
    redirect('alerts.php');
}

// Fetch all alerts
$alerts = $conn->query("
    SELECT pa.*, u.name as user_name, u.email, l.name as laptop_name,
           (SELECT MIN(price) FROM prices WHERE laptop_id = pa.laptop_id AND in_stock = 1) as current_price
    FROM price_alerts pa
    JOIN users u ON pa.user_id = u.id
    JOIN laptops l ON pa.laptop_id = l.id
    ORDER BY pa.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/theme-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Alerts - Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <?php include 'partials/nav-styles.php'; ?>
    <style>
        .btn-email {
            background-color: #0c2340;
            color: #f8fafc;
            padding: 6px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            margin-right: 5px;
        }
        .btn-email:hover {
            background-color: #153a5c;
        }
        .btn-email:disabled {
            background-color: #64748b;
            cursor: not-allowed;
        }
        .price-met {
            color: #15803d;
            font-weight: bold;
        }
        .last-notified {
            font-size: 11px;
            color: #5c6b80;
            font-style: italic;
        }
        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include 'partials/admin-nav.php'; ?>

    <main class="container">
        <h1>Price Alerts Management</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
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
                        <th>Last Notified</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alerts)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 20px;">No price alerts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alerts as $alert): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($alert['user_name']); ?><br>
                                <small style="color: #5c6b80;"><?php echo htmlspecialchars($alert['email']); ?></small>
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
                                <?php if ($alert['last_notified']): ?>
                                    <span class="last-notified"><?php echo timeAgo($alert['last_notified']); ?></span>
                                <?php else: ?>
                                    <span class="last-notified">Never</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($alert['current_price'] !== null && $alert['current_price'] <= $alert['target_price']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                        <button type="submit" 
                                                name="send_alert" 
                                                class="btn-email"
                                                onclick="return confirm('Send price alert email to <?php echo htmlspecialchars($alert['email']); ?>?')">
                                            📧 Send Email
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <a href="../product.php?id=<?php echo $alert['laptop_id']; ?>" 
                                   class="btn btn-sm btn-secondary">View</a>
                                
                                <a href="alerts.php?delete=<?php echo $alert['id']; ?>" 
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this alert?');">
                                    Delete
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>