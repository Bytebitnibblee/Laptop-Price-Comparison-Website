<?php
require_once '../config.php';
requireAdmin();

$output = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $python_path = PHP_OS_FAMILY === 'Windows' ? 'python' : '/usr/bin/python3';
    $script_path = dirname(__DIR__) . '/scrape.py';

    if (file_exists($script_path)) {
        $command = escapeshellcmd("$python_path $script_path 2>&1");
        $output = shell_exec($command);

        if ($output !== null) {
            $_SESSION['success'] = 'Scraper executed successfully!';
        } else {
            $error = 'Scraper execution failed. Check server configuration.';
        }
    } else {
        $error = 'Scraper file not found at: ' . $script_path;
    }
}

$stats = $conn->query("
    SELECT 
        COUNT(DISTINCT laptop_id) as total_laptops,
        COUNT(*) as total_prices,
        MAX(last_updated) as last_update
    FROM prices
")->fetch();

$recentPrices = $conn->query("
    SELECT p.*, l.name as laptop_name
    FROM prices p
    JOIN laptops l ON p.laptop_id = l.id
    ORDER BY p.last_updated DESC
    LIMIT 20
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/theme-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Run Scraper - Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <?php include 'partials/nav-styles.php'; ?>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include 'partials/admin-nav.php'; ?>

    <main class="container">
        <h1>Run Price Scraper</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="scraper-panel">
          
            <p>Scrape prices from retuilers.</p>

            <form method="POST">
                <button type="submit" class="btn btn-primary" onclick="return confirm('This may take a few minutes. Continue?')">
                     Run Scraper Now
                </button>
            </form>

            <?php if ($output): ?>
                <h3 style="margin-top: 2rem;">Scraper Output:</h3>
                <div class="output-box"><?php echo nl2br(htmlspecialchars($output)); ?></div>
            <?php endif; ?>
        </div>

        <div class="scraper-panel">
            <h2>Scraping Statistics</h2>

            <div class="stat-box">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['total_laptops']; ?></div>
                    <div class="stat-label">Laptops Tracked</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['total_prices']; ?></div>
                    <div class="stat-label">Price Records</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['last_update'] ? timeAgo($stats['last_update']) : 'Never'; ?></div>
                    <div class="stat-label">Last Update</div>
                </div>
            </div>
        </div>

        <div class="scraper-panel">
            <h2>Automation Tips</h2>
            <p>Set up a cron job for automatic price updates:</p>

            <div class="output-box"># Run scraper every 6 hours
0 */6 * * * /usr/bin/python3 <?php echo dirname(__DIR__); ?>/scrape.py >> /var/log/laptop-scraper.log 2>&1</div>
        </div>

        <div class="scraper-panel">
            <h2>Recent Price Updates</h2>
            <table style="width: 100%; margin-top: 1rem;">
                <thead>
                    <tr>
                        <th>Laptop</th>
                        <th>Retailer</th>
                        <th>Price</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPrices as $price): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($price['laptop_name']); ?></td>
                        <td><?php echo htmlspecialchars($price['retailer']); ?></td>
                        <td><?php echo formatPrice($price['price']); ?></td>
                        <td><?php echo timeAgo($price['last_updated']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>

