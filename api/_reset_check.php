<?php
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();

// List all tables and their row counts
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
echo "=== DATABASE STATE ===\n";
echo "Total tables: " . count($tables) . "\n\n";
foreach ($tables as $row) {
    $name = $row[0];
    $count = $pdo->query("SELECT COUNT(*) FROM `{$name}`")->fetchColumn();
    printf("%-30s %s records\n", $name, $count);
}

// Check views
$views = $pdo->query("SHOW FULL TABLES WHERE TABLE_TYPE='VIEW'")->fetchAll(PDO::FETCH_NUM);
if (!empty($views)) {
    echo "\n=== VIEWS ===\n";
    foreach ($views as $v) {
        echo $v[0] . "\n";
    }
}
