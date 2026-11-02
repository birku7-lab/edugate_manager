<?php
// ONE-TIME FIX SCRIPT
// Adds Parent/Guardian columns to the existing 'students' table, without
// touching anything else. Safe to run multiple times — it checks whether
// each column already exists before adding it, and existing student rows
// are left untouched (new columns default to NULL).
//
// After running this once successfully, DELETE this file from your server.

header('Content-Type: text/plain');
require_once __DIR__ . '/api/config.php';

// Column name => SQL definition to add
$columns = [
    'parent_name'               => "VARCHAR(255) NULL DEFAULT NULL",
    'parent_phone'               => "VARCHAR(20) NULL DEFAULT NULL",
    'parent_relationship'        => "VARCHAR(50) NULL DEFAULT NULL",
    'parent_relationship_other'  => "VARCHAR(100) NULL DEFAULT NULL",
];

try {
    // Find out which of the target columns already exist
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
    ");
    $stmt->execute();
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');

    $added = [];
    $skipped = [];

    foreach ($columns as $name => $definition) {
        if (in_array($name, $existing, true)) {
            $skipped[] = $name;
            continue;
        }
        $pdo->exec("ALTER TABLE `students` ADD COLUMN `$name` $definition");
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
