<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: text/plain');
require_once __DIR__ . '/api/config.php';

$x_0001 = [
    'parent_name'               => "VARCHAR(255) NULL DEFAULT NULL",
    'parent_phone'               => "VARCHAR(20) NULL DEFAULT NULL",
    'parent_relationship'        => "VARCHAR(50) NULL DEFAULT NULL",
    'parent_relationship_other'  => "VARCHAR(100) NULL DEFAULT NULL",
];

try {

    $x_0002 = $x_0003->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
    ");
    $x_0002->execute();
    $x_0004 = array_column($x_0002->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');

    $x_0005 = [];
    $x_0006 = [];

    foreach ($x_0001 as $x_0007 => $x_0008) {
        if (in_array($x_0007, $x_0004, true)) {
            $x_0006[] = $x_0007;
            continue;
        }
        $x_0003->exec("ALTER TABLE `students` ADD COLUMN `$x_0007` $x_0008");
        $x_0005[] = $x_0007;
    }

    if (empty($x_0005)) {
        echo "No changes made — all Parent/Guardian columns already exist.\n";
    } else {
        echo "SUCCESS: Added column(s): " . implode(', ', $x_0005) . "\n";
    }
    if (!empty($x_0006)) {
        echo "Already present (skipped): " . implode(', ', $x_0006) . "\n";
    }

    echo "Existing student records are unaffected; new fields default to empty for them.\n";
    echo "Please delete this file from your server now.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
