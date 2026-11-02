<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: text/plain');
require_once __DIR__ . '/api/config.php';

$columns = [
    'parent_name'               => "VARCHAR(255) NULL DEFAULT NULL",
    'parent_phone'               => "VARCHAR(20) NULL DEFAULT NULL",
    'parent_relationship'        => "VARCHAR(50) NULL DEFAULT NULL",
    'parent_relationship_other'  => "VARCHAR(100) NULL DEFAULT NULL",
];

try {

    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
    ");
    $stmt->execute();
    $x_0001 = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');

    $added = [];
    $skipped = [];

    foreach ($columns as $name => $x_0002) {
        if (in_array($name, $x_0001, true)) {
            $skipped[] = $name;
            continue;
        }
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `$name` $x_0002");
        $added[] = $name;
    }

    if (empty($added)) {
        echo "No changes made — all Parent/Guardian columns already exist.\n";
    } else {
        echo "SUCCESS: Added column(s): " . implode(', ', $added) . "\n";
    }
    if (!empty($skipped)) {
        echo "Already present (skipped): " . implode(', ', $skipped) . "\n";
    }

    echo "Existing student records are unaffected; new fields default to empty for them.\n";
    echo "Please delete this file from your server now.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
