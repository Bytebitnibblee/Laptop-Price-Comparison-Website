<?php
$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : $projectRoot;
$relativeRoot = trim(str_replace($docRoot, '', $projectRoot), '/');
$baseUrl = '/' . ($relativeRoot ? $relativeRoot . '/' : '');
?>
<header class="header">
    <div class="header-content">
        <div class="logo">
            <a href="<?php echo $baseUrl; ?>index.php"><?php echo SITE_NAME; ?></a>
        </div>
        <nav class="nav">
            <ul>
                <li><a href="<?php echo $baseUrl; ?>index.php">Home</a></li>
                <?php if (isLoggedIn()): ?>
                    <li><a href="<?php echo $baseUrl; ?>my-alerts.php">My Alerts</a></li>
                    <?php if (isAdmin()): ?>
                        <li><a href="<?php echo $baseUrl; ?>admin/">Admin</a></li>
                    <?php endif; ?>
                    <li><a href="<?php echo $baseUrl; ?>profile.php">Profile</a></li>
                    <li><a href="<?php echo $baseUrl; ?>logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="<?php echo $baseUrl; ?>login.php">Login</a></li>
                    <li><a href="<?php echo $baseUrl; ?>signup.php">Sign Up</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

