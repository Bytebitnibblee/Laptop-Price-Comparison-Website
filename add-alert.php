<?php
require_once 'config.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_price = (float)$_POST['target_price'];
    $user_id = $_SESSION['user_id'];

    if ($target_price <= 0) {
        $_SESSION['error'] = 'Invalid target price.';
        redirect('index.php');
    }

    $redirect_url = 'index.php';

    if (isset($_POST['laptop_id'])) {
        // Individual laptop alert (legacy support)
        $laptop_id = (int)$_POST['laptop_id'];

        if ($laptop_id <= 0) {
            $_SESSION['error'] = 'Invalid laptop ID.';
            redirect('index.php');
        }

        // Check if laptop exists
        $stmt = $conn->prepare("SELECT id FROM laptops WHERE id = ?");
        $stmt->execute([$laptop_id]);
        if (!$stmt->fetch()) {
            $_SESSION['error'] = 'Laptop not found.';
            redirect('index.php');
        }

        // Check if user already has an active alert for this laptop
        $checkStmt = $conn->prepare("SELECT id FROM price_alerts WHERE user_id = ? AND laptop_id = ? AND is_active = 1");
        $checkStmt->execute([$user_id, $laptop_id]);

        if ($checkStmt->fetch()) {
            $_SESSION['error'] = 'You already have an active alert for this laptop.';
            redirect('product.php?id=' . $laptop_id);
        }

        // Create alert
        $insertStmt = $conn->prepare("INSERT INTO price_alerts (user_id, laptop_id, target_price) VALUES (?, ?, ?)");

        if ($insertStmt->execute([$user_id, $laptop_id, $target_price])) {
            $_SESSION['success'] = 'Price alert created successfully! We\'ll notify you when the price drops.';
        } else {
            $_SESSION['error'] = 'Failed to create alert. Please try again.';
        }

        $redirect_url = 'product.php?id=' . $laptop_id;

    } elseif (isset($_POST['group_key'])) {
        // Group-based alert - create alerts for all laptops in the group
        $group_key = sanitize($_POST['group_key']);

        // Get all laptops in this group
        $stmt = $conn->prepare("SELECT id FROM laptops WHERE group_key = ?");
        $stmt->execute([$group_key]);
        $laptops = $stmt->fetchAll();

        if (empty($laptops)) {
            $_SESSION['error'] = 'Laptop group not found.';
            redirect('index.php');
        }

        // Check if user already has alerts for any laptop in this group
        $laptopIds = array_column($laptops, 'id');
        $placeholders = str_repeat('?,', count($laptopIds) - 1) . '?';
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM price_alerts WHERE user_id = ? AND laptop_id IN ($placeholders) AND is_active = 1");
        $checkStmt->execute(array_merge([$user_id], $laptopIds));
        $existingCount = $checkStmt->fetch()['count'];

        if ($existingCount > 0) {
            $_SESSION['error'] = 'You already have active alerts for laptops in this group.';
            redirect('compare.php?group=' . urlencode($group_key));
        }

        // Create alerts for all laptops in the group
        $insertStmt = $conn->prepare("INSERT INTO price_alerts (user_id, laptop_id, target_price) VALUES (?, ?, ?)");
        $successCount = 0;

        foreach ($laptops as $laptop) {
            if ($insertStmt->execute([$user_id, $laptop['id'], $target_price])) {
                $successCount++;
            }
        }

        if ($successCount > 0) {
            $_SESSION['success'] = "Price alerts created for $successCount laptop model(s)! We'll notify you when prices drop.";
        } else {
            $_SESSION['error'] = 'Failed to create alerts. Please try again.';
        }

        $redirect_url = 'compare.php?group=' . urlencode($group_key);
    }

    redirect($redirect_url);
} else {
    redirect('index.php');
}
?>