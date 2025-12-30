<?php
require_once '../config.php';

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

try {
    // Get group key suggestions
    $stmt = $conn->prepare("
        SELECT DISTINCT group_key, brand, COUNT(*) as model_count
        FROM laptops
        WHERE group_key LIKE ? OR brand LIKE ?
        GROUP BY group_key
        ORDER BY
            CASE
                WHEN group_key LIKE ? THEN 0
                WHEN brand LIKE ? THEN 1
                ELSE 2
            END,
            model_count DESC,
            group_key
        LIMIT 10
    ");

    $searchTerm = '%' . $query . '%';
    $exactMatch = $query . '%';

    $stmt->execute([$searchTerm, $searchTerm, $exactMatch, $exactMatch]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suggestions = [];
    foreach ($results as $result) {
        $suggestions[] = [
            'text' => $result['group_key'],
            'brand' => $result['brand'],
            'type' => 'group',
            'count' => $result['model_count']
        ];
    }

    // Also get brand suggestions if query matches brand names
    if (count($suggestions) < 5) {
        $stmt = $conn->prepare("
            SELECT DISTINCT brand, COUNT(DISTINCT group_key) as group_count
            FROM laptops
            WHERE brand LIKE ?
            GROUP BY brand
            ORDER BY group_count DESC
            LIMIT 5
        ");

        $stmt->execute([$searchTerm]);
        $brandResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($brandResults as $brand) {
            $suggestions[] = [
                'text' => $brand['brand'] . ' laptops',
                'brand' => $brand['brand'],
                'type' => 'brand',
                'count' => $brand['group_count']
            ];
        }
    }

    echo json_encode($suggestions);

} catch (Exception $e) {
    echo json_encode(['error' => 'Database error']);
}
?>
