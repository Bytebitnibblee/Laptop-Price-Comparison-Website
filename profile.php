<?php
require_once 'config.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Get user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);

        if (empty($name) || empty($email)) {
            $error = 'Name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $user_id]);

            if ($checkStmt->fetch()) {
                $error = 'Email is already in use.';
            } else {
                $updateStmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                if ($updateStmt->execute([$name, $email, $user_id])) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $success = 'Profile updated successfully!';
                    $user['name'] = $name;
                    $user['email'] = $email;
                } else {
                    $error = 'Unable to update profile right now.';
                }
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'All password fields are required.';
        } elseif (!password_verify($current_password, $user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($updateStmt->execute([$hashed, $user_id])) {
                $success = 'Password changed successfully!';
            } else {
                $error = 'Unable to change password right now.';
            }
        }
    }
}

// Get user stats
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_alerts,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_alerts
    FROM price_alerts
    WHERE user_id = ?
");
$statsStmt->execute([$user_id]);
$stats = $statsStmt->fetch();

$stats['total_alerts'] = $stats['total_alerts'] ?? 0;
$stats['active_alerts'] = $stats['active_alerts'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="container">
        <div class="page-header">
            <h1>My Profile</h1>
            <p>Manage your account information and password</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="profile-grid">
            <div class="profile-card">
                <h2>Profile Information</h2>
                <form method="POST" style="margin-top: 1.5rem;">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" required value="<?php echo htmlspecialchars($user['name']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($user['email']); ?>">
                    </div>

                    <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                </form>
            </div>

            <div class="profile-card">
                <h2>Change Password</h2>
                <form method="POST" style="margin-top: 1.5rem;">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>

                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required>
                    </div>

                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>

                    <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>

        <div class="profile-stats-block">
            <h2>Account Statistics</h2>
            <div class="stat-grid">
                <div class="stat-tile">
                    <div class="stat-value"><?php echo $stats['total_alerts']; ?></div>
                    <div class="stat-label">Total Alerts</div>
                </div>

                <div class="stat-tile">
                    <div class="stat-value stat-value--accent"><?php echo $stats['active_alerts']; ?></div>
                    <div class="stat-label">Active Alerts</div>
                </div>

                <div class="stat-tile">
                    <div class="stat-value" style="font-size: 1.2rem;"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></div>
                    <div class="stat-label">Member Since</div>
                </div>
            </div>

            <div class="profile-actions">
                <a href="my-alerts.php" class="btn btn-primary">Manage My Alerts</a>
                <a href="index.php" class="btn btn-secondary">Browse Laptops</a>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>

