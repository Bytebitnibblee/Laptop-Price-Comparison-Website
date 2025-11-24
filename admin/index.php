<?php
require_once '../config.php';
requireAdmin();

$statsQuery = "
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM laptops) as total_laptops,
        (SELECT COUNT(*) FROM price_alerts WHERE is_active = 1) as active_alerts,
        (SELECT COUNT(*) FROM alert_notifications WHERE DATE(sent_at) = CURDATE()) as alerts_sent_today
";
$stats = $conn->query($statsQuery)->fetch();

$recentAlerts = $conn->query("
    SELECT pa.*, u.email, l.name as laptop_name
    FROM price_alerts pa
    JOIN users u ON pa.user_id = u.id
    JOIN laptops l ON pa.laptop_id = l.id
    ORDER BY pa.created_at DESC
    LIMIT 10
")->fetchAll();

$recentUsers = $conn->query("
    SELECT *
    FROM users
    ORDER BY created_at DESC
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <?php include 'partials/nav-styles.php'; ?>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include 'partials/admin-nav.php'; ?>

    <main class="container">
        <h1>Admin Dashboard</h1>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_laptops']; ?></div>
                <div class="stat-label">Total Laptops</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['active_alerts']; ?></div>
                <div class="stat-label">Active Alerts</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['alerts_sent_today']; ?></div>
                <div class="stat-label">Alerts Sent Today</div>
            </div>
        </div>

        <div class="data-table">
            <h2>Recent Price Alerts</h2>
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Laptop</th>
                        <th>Target Price</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAlerts as $alert): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($alert['email']); ?></td>
                        <td><?php echo htmlspecialchars($alert['laptop_name']); ?></td>
                        <td><?php echo formatPrice($alert['target_price']); ?></td>
                        <td>
                            <span class="badge <?php echo $alert['is_active'] ? '' : 'inactive-badge'; ?>">
                                <?php echo $alert['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td><?php echo timeAgo($alert['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="data-table">
            <h2>Recent Users</h2>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentUsers as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo $user['is_admin'] ? 'Admin' : 'User'; ?></td>
                        <td><?php echo timeAgo($user['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>

