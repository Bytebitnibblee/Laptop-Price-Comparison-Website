<?php
// email_helper.php - Add this new file for email functions

function sendPriceAlertEmail($userEmail, $userName, $laptopName, $targetPrice, $currentPrice, $laptopId) {
    $subject = "Price Alert: {$laptopName} is now within your target price!";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
            .price-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #4CAF50; }
            .button { display: inline-block; padding: 12px 24px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>🎉 Price Alert Triggered!</h2>
            </div>
            <div class='content'>
                <p>Hi {$userName},</p>
                <p>Great news! The laptop you're tracking has reached your target price.</p>
                
                <div class='price-box'>
                    <h3>{$laptopName}</h3>
                    <p><strong>Your Target Price:</strong> " . formatPrice($targetPrice) . "</p>
                    <p><strong>Current Price:</strong> <span style='color: #4CAF50; font-size: 20px; font-weight: bold;'>" . formatPrice($currentPrice) . "</span></p>
                    <p style='color: #4CAF50;'><strong>You save: " . formatPrice($targetPrice - $currentPrice) . "</strong></p>
                </div>
                
                <p>Don't miss this opportunity! Prices can change at any time.</p>
                
                <a href='" . getBaseUrl() . "/laptop.php?id={$laptopId}' class='button'>View Laptop Details</a>
            </div>
            <div class='footer'>
                <p>You're receiving this email because you set up a price alert on our platform.</p>
                <p>To manage your alerts, visit your <a href='" . getBaseUrl() . "/alerts.php'>alerts page</a>.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Laptop Price Tracker <noreply@laptoppricetracker.com>" . "\r\n";
    
    return mail($userEmail, $subject, $message, $headers);
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    return $protocol . "://" . $_SERVER['HTTP_HOST'];
}

// Function to check and send alerts for all price drops
function checkAndSendPriceAlerts() {
    global $conn;
    
    // Find all alerts where current price is at or below target price and alert hasn't been sent recently
    $stmt = $conn->query("
        SELECT pa.*, u.name as user_name, u.email, l.name as laptop_name,
               (SELECT MIN(price) FROM prices WHERE laptop_id = pa.laptop_id AND in_stock = 1) as current_price
        FROM price_alerts pa
        JOIN users u ON pa.user_id = u.id
        JOIN laptops l ON pa.laptop_id = l.id
        WHERE (SELECT MIN(price) FROM prices WHERE laptop_id = pa.laptop_id AND in_stock = 1) <= pa.target_price
        AND (pa.last_notified IS NULL OR pa.last_notified < DATE_SUB(NOW(), INTERVAL 24 HOUR))
    ");
    
    $alerts = $stmt->fetchAll();
    $sentCount = 0;
    
    foreach ($alerts as $alert) {
        if (sendPriceAlertEmail(
            $alert['email'],
            $alert['user_name'],
            $alert['laptop_name'],
            $alert['target_price'],
            $alert['current_price'],
            $alert['laptop_id']
        )) {
            // Update last_notified timestamp
            $updateStmt = $conn->prepare("UPDATE price_alerts SET last_notified = NOW() WHERE id = ?");
            $updateStmt->execute([$alert['id']]);
            $sentCount++;
        }
    }
    
    return $sentCount;
}
?>