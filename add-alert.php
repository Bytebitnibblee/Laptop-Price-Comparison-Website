<?php
require_once 'config.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $laptop_id = (int)$_POST['laptop_id'];
    $target_price = (float)$_POST['target_price'];
    $user_id = $_SESSION['user_id'];
    
    if ($laptop_id <= 0 || $target_price <= 0) {
        $_SESSION['error'] = 'Invalid data provided.';
        redirect('product.php?id=' . $laptop_id);
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
    
    redirect('product.php?id=' . $laptop_id);
} else {
    redirect('index.php');
}
?>