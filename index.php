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

// Fixed query with proper field names
$query = "
    SELECT l.id,
           l.name,
           l.brand,
           l.group_key,
           l.processor,
           l.ram,
           l.storage,
           l.screen_size,
           l.image_url,
           l.specs,
           MIN(CASE WHEN p.in_stock = 1 THEN p.price END) as lowest_in_stock,
           MIN(p.price) as absolute_lowest,
           MAX(p.last_updated) as last_price_update,
           COUNT(CASE WHEN p.in_stock = 1 THEN 1 END) as in_stock_retailers,
           1 as model_count
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
    
    // Apply price filters
    if ($minPrice && ($displayPrice === null || $displayPrice < $minPrice)) {
        continue;
    }
    if ($maxPrice && ($displayPrice === null || $displayPrice > $maxPrice)) {
        continue;
    }
    
    $laptop['display_price'] = $displayPrice;
    $laptops[] = $laptop;
}

// Sort laptops
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

$brands = $conn->query("SELECT DISTINCT brand FROM laptops WHERE brand != 'Other' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

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
    <style>
        .autocomplete-container {
            position: relative;
        }
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            box-shadow: var(--shadow-primary);
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }
        .autocomplete-suggestion {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .autocomplete-suggestion:last-child {
            border-bottom: none;
        }
        .autocomplete-suggestion:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        .suggestion-text {
            font-weight: 500;
        }
        .suggestion-brand {
            color: var(--accent-gold);
            font-size: 0.85rem;
        }
        .suggestion-type {
            background: var(--accent-gold);
            color: #1b1405;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Filters section styling for the form at lines 291–359 */
        .filters {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-primary);
            border: 1px solid var(--card-border);
            margin-bottom: 30px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1.5fr 1.2fr auto;
            gap: 16px;
            align-items: end;
        }

        /* Labels */
        .filters label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--soft-ivory);
            margin-bottom: 6px;
        }

        /* Search box */
        .filters .search-box input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            font-size: 14px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--soft-ivory);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .filters .search-box input::placeholder {
            color: var(--cool-gray);
        }

        .filters .search-box input:focus {
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(241, 196, 15, 0.2);
            outline: none;
            background: rgba(255, 255, 255, 0.08);
        }

        /* Select & input fields */
        .filter-group select,
        .filter-group input[type="number"] {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            font-size: 14px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--soft-ivory);
        }

        .filter-group select:focus,
        .filter-group input:focus {
            border-color: var(--accent-gold);
            outline: none;
            box-shadow: 0 0 0 3px rgba(241, 196, 15, 0.2);
        }

        /* Price range */
        .price-range {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .price-range span {
            color: var(--cool-gray);
            font-weight: 500;
        }

        /* Buttons container (buttons themselves use global .btn styles) */
        .filter-actions {
            display: flex;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .filter-row {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                width: 100%;
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
            }
        }
    </style>
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

        <!-- Search & Filters -->
        <section class="filters">
            <form method="get">
                <div class="filter-row">

                    <div class="filter-group">
                        <label for="brand">Brand</label>
                        <select name="brand" id="brand">
                            <option value="">All brands</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?php echo htmlspecialchars($brand); ?>" <?php echo $brand === $brandFilter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($brand); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Price range (₹)</label>
                        <div class="price-range">
                            <input
                                type="number"
                                name="min_price"
                                placeholder="Min"
                                min="0"
                                step="1000"
                                value="<?php echo $minPrice !== null ? htmlspecialchars($minPrice) : ''; ?>"
                            >
                            <span>–</span>
                            <input
                                type="number"
                                name="max_price"
                                placeholder="Max"
                                min="0"
                                step="1000"
                                value="<?php echo $maxPrice !== null ? htmlspecialchars($maxPrice) : ''; ?>"
                            >
                        </div>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort by</label>
                        <select name="sort" id="sort">
                            <option value="price_asc"  <?php echo $sort === 'price_asc'  ? 'selected' : ''; ?>>Price: Low to High</option>
                            <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                            <option value="name_asc"   <?php echo $sort === 'name_asc'   ? 'selected' : ''; ?>>Name: A to Z</option>
                            <option value="name_desc"  <?php echo $sort === 'name_desc'  ? 'selected' : ''; ?>>Name: Z to A</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="index.php" class="btn btn-secondary btn-ghost">Reset</a>
                    </div>
                </div>
            </form>
        </section>

        <?php
        // Get popular brands and their laptop groups for brand sections
        $popularBrands = ['Dell', 'HP', 'Apple', 'Acer', 'Lenovo', 'ASUS'];
        $brandLaptops = [];
        foreach ($popularBrands as $brand) {
            $stmt = $conn->prepare("
                SELECT l.id,
                       l.name,
                       l.brand,
                       l.group_key,
                       l.image_url,
                       MIN(CASE WHEN p.in_stock = 1 THEN p.price END) as lowest_in_stock,
                       COUNT(CASE WHEN p.in_stock = 1 THEN 1 END) as in_stock_retailers,
                       1 as model_count
                FROM laptops l
                LEFT JOIN prices p ON p.laptop_id = l.id
                WHERE l.brand = ?
                GROUP BY l.id
                HAVING lowest_in_stock IS NOT NULL
                ORDER BY lowest_in_stock ASC
                LIMIT 8
            ");
            $stmt->execute([$brand]);
            $brandLaptops[$brand] = $stmt->fetchAll();
        }
        ?>

        <?php if (empty($laptops)): ?>
            <!-- Show brand sections when no search filters are applied -->
            <?php if (!$search && !$brandFilter && !$minPrice && !$maxPrice): ?>
                <?php foreach ($popularBrands as $brand): ?>
                    <?php if (!empty($brandLaptops[$brand])): ?>
                        <section class="brand-section">
                            <div class="brand-header">
                                <h2><?php echo htmlspecialchars($brand); ?> Laptops</h2>
                                <a href="?brand=<?php echo urlencode($brand); ?>" class="btn btn-link">View All <?php echo htmlspecialchars($brand); ?> →</a>
                            </div>
                            <div class="laptop-grid">
                                <?php foreach ($brandLaptops[$brand] as $laptop): ?>
                                    <article class="laptop-card">
                                        <?php
                                        // Handle single image URL
                                        $image_url = !empty($laptop['image_url']) ? $laptop['image_url'] : 'https://via.placeholder.com/400x250?text=Laptop';
                                        ?>
                                        <img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($laptop['name']); ?>">
                                        <div class="laptop-info">
                                            <h3><?php echo htmlspecialchars($laptop['name']); ?></h3>
                                            <p class="specs">
                                                <?php echo htmlspecialchars($laptop['brand']); ?><br>
                                                Compare prices across retailers
                                            </p>
                                            <div class="price-info">
                                                <div>
                                                    <span class="label">Best price</span>
                                                    <span class="price">
                                                        <?php echo $laptop['lowest_in_stock'] !== null ? formatPrice($laptop['lowest_in_stock']) : 'N/A'; ?>
                                                    </span>
                                                </div>
                                                <span class="badge"><?php echo (int)($laptop['in_stock_retailers'] ?? 0); ?> retailers</span>
                                            </div>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <a href="compare.php?id=<?php echo $laptop['id']; ?>" class="btn btn-primary btn-sm">Compare Prices</a>
                                                <?php if (isLoggedIn()): ?>
                                                    <a href="compare.php?id=<?php echo $laptop['id']; ?>#alert" class="btn btn-secondary btn-sm">Set Alert</a>
                                                <?php else: ?>
                                                    <a href="login.php?redirect=compare.php?id=<?php echo $laptop['id']; ?>" class="btn btn-secondary btn-sm">Login to Set Alert</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">
                    <p>No laptops match your filters yet.</p>
                    <p>Try expanding your search or come back later for fresh deals.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <section class="laptop-grid">
                <?php foreach ($laptops as $laptop): ?>
                    <article class="laptop-card">
                        <?php
                        // Handle single image URL
                        $image_url = !empty($laptop['image_url']) ? $laptop['image_url'] : 'https://via.placeholder.com/400x250?text=Laptop';
                        ?>
                        <img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($laptop['name']); ?>">
                        <div class="laptop-info">
                            <h3><?php echo htmlspecialchars($laptop['name']); ?></h3>
                            <p class="specs">
                                <?php echo htmlspecialchars($laptop['brand']); ?>
                                <?php if ($laptop['processor']): ?>
                                    • <?php echo htmlspecialchars($laptop['processor']); ?>
                                <?php endif; ?>
                                <?php if ($laptop['ram']): ?>
                                    • <?php echo htmlspecialchars($laptop['ram']); ?>
                                <?php endif; ?>
                            </p>
                            <div class="price-info">
                                <div>
                                    <span class="label">Best price</span>
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
                                <a href="compare.php?id=<?php echo $laptop['id']; ?>" class="btn btn-primary btn-sm">Compare Prices</a>
                                <?php if (isLoggedIn()): ?>
                                    <a href="compare.php?id=<?php echo $laptop['id']; ?>#alert" class="btn btn-secondary btn-sm">Set Alert</a>
                                <?php else: ?>
                                    <a href="login.php?redirect=compare.php?id=<?php echo $laptop['id']; ?>" class="btn btn-secondary btn-sm">Login to Set Alert</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            const searchBox = document.querySelector('.search-box');
            let suggestionsContainer = null;
            let currentFocus = -1;
            let debounceTimer;

            // Create suggestions container
            function createSuggestionsContainer() {
                if (suggestionsContainer) return;

                suggestionsContainer = document.createElement('div');
                suggestionsContainer.className = 'autocomplete-suggestions';
                searchBox.appendChild(suggestionsContainer);
            }

            // Fetch suggestions
            function fetchSuggestions(query) {
                if (query.length < 2) {
                    hideSuggestions();
                    return;
                }

                fetch(`api/search-suggestions.php?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('Error fetching suggestions:', data.error);
                            return;
                        }
                        showSuggestions(data, query);
                    })
                    .catch(error => {
                        console.error('Error fetching suggestions:', error);
                    });
            }

            // Show suggestions
            function showSuggestions(suggestions, query) {
                createSuggestionsContainer();

                if (suggestions.length === 0) {
                    hideSuggestions();
                    return;
                }

                suggestionsContainer.innerHTML = '';

                suggestions.forEach((suggestion, index) => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';
                    div.setAttribute('data-index', index);

                    const textDiv = document.createElement('div');
                    textDiv.className = 'suggestion-text';

                    // Highlight matching text
                    const regex = new RegExp(`(${query})`, 'gi');
                    const highlightedText = suggestion.text.replace(regex, '<mark>$1</mark>');
                    textDiv.innerHTML = highlightedText;

                    const brandDiv = document.createElement('div');
                    brandDiv.className = 'suggestion-brand';
                    brandDiv.textContent = suggestion.brand;

                    const typeDiv = document.createElement('div');
                    typeDiv.className = 'suggestion-type';
                    typeDiv.textContent = suggestion.type;

                    div.appendChild(textDiv);
                    div.appendChild(brandDiv);
                    div.appendChild(typeDiv);

                    div.addEventListener('click', function() {
                        if (suggestion.type === 'brand') {
                            // For brand suggestions, set brand filter instead of search
                            window.location.href = `?brand=${encodeURIComponent(suggestion.brand)}`;
                        } else {
                            searchInput.value = suggestion.text;
                            hideSuggestions();
                            // Auto-submit the form
                            searchInput.closest('form').submit();
                        }
                    });

                    suggestionsContainer.appendChild(div);
                });

                suggestionsContainer.style.display = 'block';
                currentFocus = -1;
            }

            // Hide suggestions
            function hideSuggestions() {
                if (suggestionsContainer) {
                    suggestionsContainer.style.display = 'none';
                }
                currentFocus = -1;
            }

            // Handle input
            searchInput.addEventListener('input', function(e) {
                const query = e.target.value.trim();
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => fetchSuggestions(query), 300);
            });

            // Handle keyboard navigation
            searchInput.addEventListener('keydown', function(e) {
                if (!suggestionsContainer || suggestionsContainer.style.display === 'none') return;

                const suggestions = suggestionsContainer.querySelectorAll('.autocomplete-suggestion');

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentFocus = (currentFocus + 1) % suggestions.length;
                    updateFocus(suggestions);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentFocus = currentFocus <= 0 ? suggestions.length - 1 : currentFocus - 1;
                    updateFocus(suggestions);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentFocus >= 0 && currentFocus < suggestions.length) {
                        suggestions[currentFocus].click();
                    }
                } else if (e.key === 'Escape') {
                    hideSuggestions();
                }
            });

            function updateFocus(suggestions) {
                suggestions.forEach((suggestion, index) => {
                    if (index === currentFocus) {
                        suggestion.style.background = 'rgba(245, 197, 24, 0.1)';
                    } else {
                        suggestion.style.background = '';
                    }
                });
            }

            // Hide suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchBox.contains(e.target)) {
                    hideSuggestions();
                }
            });

            // Hide suggestions on form submit
            document.querySelector('.filters').addEventListener('submit', function() {
                hideSuggestions();
            });
        });
    </script>
</body>
</html>