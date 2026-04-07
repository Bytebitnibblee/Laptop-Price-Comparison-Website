<?php
if (!isset($baseUrl)) {
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : $projectRoot;
    $relativeRoot = trim(str_replace($docRoot, '', $projectRoot), '/');
    $baseUrl = '/' . ($relativeRoot ? $relativeRoot . '/' : '');
}
?>
<footer class="footer">
    <div class="container">
        <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. Project I.</p>
        <p> Deep Singh Rawal.</p>
    </div>
</footer>
<script src="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>js/theme-toggle.js" defer></script>
