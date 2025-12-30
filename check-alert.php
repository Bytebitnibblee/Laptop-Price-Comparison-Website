<?php
/**
 * Price Alert Checker - Run via Cron Job
 * 
 * Add to crontab:
 * 0 9 * * * /usr/bin/php /path/to/your/project/cron/check-alerts.php
 * 
 * This runs daily at 9 AM
 */

require_once __DIR__ . '/config.php';

echo "Starting price alert checker...\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Get all active alerts
$stmt = $conn->query("
    SELECT pa.*, u.email, u.name as user_name, l.name as laptop_name
    FROM price_alerts pa
    JOIN users u ON pa.user_id = u.id
    JOIN laptops l ON pa.laptop_id = l.id
    WHERE pa.is_active = 1
");

$alerts = $stmt->fetchAll();
$total_alerts = count($alerts);
$sent_count = 0;

echo "Found {$total_alerts} active alerts\n\n";

foreach ($alerts as $alert) {
    // Get current lowest price for this laptop
    $priceStmt = $conn->prepare("
        SELECT price as lowest_price, retailer, product_url
        FROM prices
        WHERE laptop_id = ? AND in_stock = 1
        ORDER BY price ASC
        LIMIT 1
    ");
    $priceStmt->execute([$alert['laptop_id']]);
    $currentPrice = $priceStmt->fetch();

    if (!$currentPrice) {
        $fallbackStmt = $conn->prepare("
            SELECT price as lowest_price, retailer, product_url
            FROM prices
            WHERE laptop_id = ?
            ORDER BY price ASC
            LIMIT 1
        ");
        $fallbackStmt->execute([$alert['laptop_id']]);
        $currentPrice = $fallbackStmt->fetch();
    }
    
    if (!$currentPrice) {
        echo "No price found for {$alert['laptop_name']}\n";
        continue;
    }
    
    // Check if current price is below or equal to target
    if ($currentPrice['lowest_price'] <= $alert['target_price']) {
        echo "ALERT! {$alert['laptop_name']} - Current: ₹{$currentPrice['lowest_price']} Target: ₹{$alert['target_price']}\n";
        
        // Send email notification
        $emailSent = sendPriceAlert(
            $alert['email'],
            $alert['user_name'],
            $alert['laptop_name'],
            $alert['target_price'],
            $currentPrice['lowest_price'],
            $currentPrice['retailer'],
            $currentPrice['product_url']
        );
        
        if ($emailSent) {
            // Log notification
            $logStmt = $conn->prepare("
                INSERT INTO alert_notifications (alert_id, price_at_alert)
                VALUES (?, ?)
            ");
            $logStmt->execute([$alert['id'], $currentPrice['lowest_price']]);
            
            // Deactivate alert (user can create new one if needed)
            $deactivateStmt = $conn->prepare("
                UPDATE price_alerts
                SET is_active = 0
                WHERE id = ?
            ");
            $deactivateStmt->execute([$alert['id']]);
            
            $sent_count++;
            echo "✓ Email sent to {$alert['email']}\n\n";
        } else {
            echo "✗ Failed to send email to {$alert['email']}\n\n";
        }
    } else {
        echo "No alert: {$alert['laptop_name']} - Current: ₹{$currentPrice['lowest_price']} Target: ₹{$alert['target_price']}\n";
    }
}

echo "\n-------------------\n";
echo "Summary: Sent {$sent_count} alerts out of {$total_alerts}\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

/**
 * Send price alert email
 */
function sendPriceAlert($to, $userName, $laptopName, $targetPrice, $currentPrice, $retailer, $productUrl) {
    $subject = "Price Alert: {$laptopName} is now ₹" . number_format($currentPrice, 2);
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .price-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
            .price { font-size: 32px; font-weight: bold; color: #667eea; }
            .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎉 Price Drop Alert!</h1>
            </div>
            <div class='content'>
                <p>Hi {$userName},</p>
                <p>Great news! The price of <strong>{$laptopName}</strong> has dropped to meet your target price!</p>
                
                <div class='price-box'>
                    <p><strong>Your Target Price:</strong> ₹" . number_format($targetPrice, 2) . "</p>
                    <p><strong>Current Price:</strong> <span class='price'>₹" . number_format($currentPrice, 2) . "</span></p>
                    <p><strong>Available at:</strong> {$retailer}</p>
                    <p><strong>You Save:</strong> ₹" . number_format($targetPrice - $currentPrice, 2) . "</p>
                </div>
                
                <p>Don't miss this opportunity! Prices can change anytime.</p>
                
                <a href='{$productUrl}' class='button'>Buy Now on {$retailer}</a>
                
                <p style='margin-top: 30px; font-size: 12px; color: #666;'>
                    This alert has been automatically deactivated. You can create a new alert anytime from your dashboard.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Use PHPMailer with SMTP
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';
    require_once __DIR__ . '/PHPMailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to, $userName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email error: {$mail->ErrorInfo}");
        return false;
    }
    
}
?>