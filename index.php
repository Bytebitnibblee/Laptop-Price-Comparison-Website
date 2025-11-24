<?php
require_once 'config.php';

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$brandFilter = isset($_GET['brand']) ? sanitize($_GET['brand']) : '';
$minPrice = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
$sort = $_GET['sort'] ?? 'price_asc';
$allowedSorts = ['price_asc','price_desc','name_asc','name_desc'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'price_asc';
}

$filters = [];
$params = [];

if ($search) {
    $filters[] = "(l.name LIKE :search OR l.brand LIKE :search OR l.processor LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($brandFilter) {
    $filters[] = "l.brand = :brand";
    $params[':brand'] = $brandFilter;
}

$query = "
    SELECT l.*,
           MIN(CASE WHEN p.in_stock = 1 THEN p.price END) as lowest_in_stock,
           MIN(p.price) as absolute_lowest,
           MAX(p.last_updated) as last_price_update,
           COUNT(CASE WHEN p.in_stock = 1 THEN 1 END) as in_stock_retailers
    FROM laptops l
    LEFT JOIN prices p ON p.laptop_id = l.id
" . ($filters ? ' WHERE ' . implode(' AND ', $filters) : '') . "
    GROUP BY l.id
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$rawLaptops = $stmt->fetchAll();

$laptops = [];
foreach ($rawLaptops as $laptop) {
    $displayPrice = $laptop['lowest_in_stock'] ?? $laptop['absolute_lowest'];
    if ($minPrice && ($displayPrice === null || $displayPrice < $minPrice)) {
        continue;
    }
    if ($maxPrice && ($displayPrice === null || $displayPrice > $maxPrice)) {
        continue;
    }
    $laptop['display_price'] = $displayPrice;
    $laptops[] = $laptop;
}

usort($laptops, function ($a, $b) use ($sort) {
    $priceA = $a['display_price'] ?? PHP_INT_MAX;
    $priceB = $b['display_price'] ?? PHP_INT_MAX;

    return match ($sort) {
        'price_desc' => $priceB <=> $priceA,
        'name_asc' => strcmp($a['name'], $b['name']),
        'name_desc' => strcmp($b['name'], $a['name']),
        default => $priceA <=> $priceB,
    };
});

$brands = $conn->query("SELECT DISTINCT brand FROM laptops ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

$stats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM laptops) as total_laptops,
        (SELECT COUNT(*) FROM prices) as total_prices,
        (SELECT COUNT(*) FROM price_alerts WHERE is_active = 1) as active_alerts
")->fetch();
$stats = array_map(function ($value) {
    return $value ?? 0;
}, $stats);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="container">
        <section class="hero">
            <h1>Find the Best Laptop Deals</h1>
            <p>Compare real-time prices across top retailers and set smart alerts.</p>
            <div style="display: flex; flex-wrap: wrap; gap: 2rem; justify-content: center; margin-top: 2rem;">
                <div>
                    <strong><?php echo $stats['total_laptops']; ?>+</strong>
                    <div>Models tracked</div>
                </div>
                <div>
                    <strong><?php echo $stats['total_prices']; ?>+</strong>
                    <div>Live price points</div>
                </div>
                <div>
                    <strong><?php echo $stats['active_alerts']; ?>+</strong>
                    <div>Active alerts</div>
                </div>
            </div>
        </section>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <section class="filters-section">
            <form class="filters" method="GET">
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search by model, brand, processor..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>

                <div class="filter-controls">
                    <select name="brand">
                        <option value="">All Brands</option>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?php echo htmlspecialchars($brand); ?>" <?php echo $brand === $brandFilter ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($brand); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="number" name="min_price" placeholder="Min Price (₹)" value="<?php echo $minPrice ?: ''; ?>">
                    <input type="number" name="max_price" placeholder="Max Price (₹)" value="<?php echo $maxPrice ?: ''; ?>">

                    <select name="sort">
                        <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name: A → Z</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name: Z → A</option>
                    </select>

                    <a href="index.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </section>

        <?php if (empty($laptops)): ?>
            <div class="no-results">
                <p>No laptops match your filters yet.</p>
                <p>Try expanding your search or come back later for fresh deals.</p>
            </div>
        <?php else: ?>
            <section class="laptop-grid">
                <?php foreach ($laptops as $laptop): ?>
                    <article class="laptop-card">
                        <img src="<?php echo htmlspecialchars($laptop['image_url'] ?: 'https://via.placeholder.com/400x250?text=Laptop'); ?>" alt="<?php echo htmlspecialchars($laptop['name']); ?>">
                        <div class="laptop-info">
                            <h3><?php echo htmlspecialchars($laptop['name']); ?></h3>
                            <p class="specs">
                                <?php echo htmlspecialchars($laptop['processor']); ?><br>
                                <?php echo htmlspecialchars($laptop['ram']); ?> • <?php echo htmlspecialchars($laptop['storage']); ?> • <?php echo htmlspecialchars($laptop['screen_size']); ?>
                            </p>
                            <div class="price-info">
                                <div>
                                    <span class="label">Starts at</span>
                                    <span class="price">
                                        <?php echo $laptop['display_price'] !== null ? formatPrice($laptop['display_price']) : 'N/A'; ?>
                                    </span>
                                </div>
                                <span class="badge"><?php echo (int)($laptop['in_stock_retailers'] ?? 0); ?> retailers</span>
                            </div>
                            <?php if (!empty($laptop['last_price_update'])): ?>
                                <p class="updated">Updated <?php echo timeAgo($laptop['last_price_update']); ?></p>
                            <?php endif; ?>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <a href="product.php?id=<?php echo $laptop['id']; ?>" class="btn btn-primary btn-sm">View Details</a>
                                <?php if (isLoggedIn()): ?>
                                    <a href="product.php?id=<?php echo $laptop['id']; ?>#alert" class="btn btn-secondary btn-sm">Set Alert</a>
                                <?php else: ?>
                                    <a href="login.php?redirect=product.php?id=<?php echo $laptop['id']; ?>" class="btn btn-secondary btn-sm">Login to Set Alert</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>