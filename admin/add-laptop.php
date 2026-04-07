<?php
require_once '../config.php';
requireAdmin();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $brand = sanitize($_POST['brand']);
    $processor = sanitize($_POST['processor']);
    $ram = sanitize($_POST['ram']);
    $storage = sanitize($_POST['storage']);
    $screen_size = sanitize($_POST['screen_size']);
    $image_url = sanitize($_POST['image_url']);
    $specs = sanitize($_POST['specs']);

    if (empty($name) || empty($brand)) {
        $error = 'Name and brand are required.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO laptops (name, brand, processor, ram, storage, screen_size, image_url, specs)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt->execute([$name, $brand, $processor, $ram, $storage, $screen_size, $image_url, $specs])) {
            $laptop_id = $conn->lastInsertId();
            $retailers = ['Nagmani', 'Yantra Nepal', 'Daraz'];

            foreach ($retailers as $retailer) {
                $price_key = strtolower($retailer) . '_price';
                $url_key = strtolower($retailer) . '_url';

                if (!empty($_POST[$price_key]) && $_POST[$price_key] > 0) {
                    $priceStmt = $conn->prepare("
                        INSERT INTO prices (laptop_id, retailer, price, product_url)
                        VALUES (?, ?, ?, ?)
                    ");
                    $priceStmt->execute([
                        $laptop_id,
                        $retailer,
                        $_POST[$price_key],
                        $_POST[$url_key] ?? null
                    ]);
                }
            }

            $success = 'Laptop added successfully!';
            header("refresh:2;url=laptops.php");
        } else {
            $error = 'Failed to add laptop.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/theme-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Laptop - Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <?php include 'partials/nav-styles.php'; ?>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php include 'partials/admin-nav.php'; ?>

    <main class="container">
        <h1>Add New Laptop</h1>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="admin-panel">
            <form method="POST">
                <h3>Basic Information</h3>

                <div class="form-group">
                    <label>Laptop Name *</label>
                    <input type="text" name="name" required value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Brand *</label>
                    <select name="brand" required>
                        <option value="">Select Brand</option>
                        <?php
                        $brands = ['Dell','HP','Lenovo','ASUS','Acer','Apple','MSI','Samsung','Other'];
                        foreach ($brands as $option):
                        ?>
                        <option value="<?php echo $option; ?>" <?php echo (isset($brand) && $brand === $option) ? 'selected' : ''; ?>>
                            <?php echo $option; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <h3 style="margin-top: 2rem;">Specifications</h3>

                <div class="form-group">
                    <label>Processor</label>
                    <input type="text" name="processor" value="<?php echo isset($processor) ? htmlspecialchars($processor) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>RAM</label>
                    <input type="text" name="ram" value="<?php echo isset($ram) ? htmlspecialchars($ram) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Storage</label>
                    <input type="text" name="storage" value="<?php echo isset($storage) ? htmlspecialchars($storage) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Screen Size</label>
                    <input type="text" name="screen_size" value="<?php echo isset($screen_size) ? htmlspecialchars($screen_size) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Image URL</label>
                    <input type="url" name="image_url" value="<?php echo isset($image_url) ? htmlspecialchars($image_url) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Additional Specs</label>
                    <textarea name="specs" rows="3"><?php echo isset($specs) ? htmlspecialchars($specs) : ''; ?></textarea>
                </div>

                <h3 style="margin-top: 2rem;">Prices (Optional)</h3>

                <div class="form-group">
                    <label>Nagmani Price </label>
                        <input type="number" name="nagmani_price" step="0.01">
                </div>

                <div class="form-group">
                    <label>Nagmani URL</label>
                    <input type="url" name="nagmani_url">
                </div>

                <div class="form-group">
                    <label>Yantra Nepal Price </label>
                    <input type="number" name="yantra_nepal_price" step="0.01">
                </div>

                <div class="form-group">
                    <label>Yantra Nepal URL</label>
                    <input type="url" name="yantra_nepal_url">
                </div>

                <div class="form-group">
                    <label>Daraz Price </label>
                    <input type="number" name="daraz_price" step="0.01">
                </div>

                <div class="form-group">
                    <label>Daraz URL</label>
                    <input type="url" name="daraz_url">
                </div>

                <div class="form-group">
                    <label> Onin  </label>
                    <input type="number" name="onin_price" step="0.01">
                </div>

                <div class="form-group">
                    <label> Onin  URL</label>
                        <input type="url" name="onin_url">
                </div>


                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary">Add Laptop</button>
                    <a href="laptops.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>

