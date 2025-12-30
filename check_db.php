<?php
require_once 'config.php';

echo "Testing database connection...\n";
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_USER: " . DB_USER . "\n";

try {
    // Test basic connection
    $conn->setAttribute(PDO::ATTR_TIMEOUT, 5);
    echo "✓ PDO connection object created\n";

    // Test simple query
    $stmt = $conn->query('SELECT 1 as test');
    $result = $stmt->fetch();
    echo "✓ Basic query works: " . $result['test'] . "\n";

    // Test database selection
    $stmt = $conn->query('SELECT DATABASE() as current_db');
    $result = $stmt->fetch();
    echo "✓ Current database: " . $result['current_db'] . "\n";

    // Check laptops table
    $stmt = $conn->query('DESCRIBE laptops');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'Field');

    if (in_array('group_key', $columnNames)) {
        echo '✓ group_key column exists' . PHP_EOL;
    } else {
        echo '✗ group_key column missing - need to run database update' . PHP_EOL;
    }

    // Check if there are existing laptops that need group_key populated
    $countStmt = $conn->query('SELECT COUNT(*) as count FROM laptops');
    $count = $countStmt->fetch()['count'];
    echo "Total laptops in database: $count" . PHP_EOL;

} catch (Exception $e) {
    echo '✗ Error: ' . $e->getMessage() . PHP_EOL;
    echo 'Error code: ' . $e->getCode() . PHP_EOL;
}
?>
