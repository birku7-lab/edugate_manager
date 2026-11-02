<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: text/plain; charset=utf-8');
require_once 'config.php';

try {

    $x_0001->exec("
        CREATE TABLE IF NOT EXISTS `academic_terms` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `term_name` VARCHAR(100) NOT NULL,
            `academic_year` VARCHAR(50) NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `status` ENUM('Completed', 'Active', 'Upcoming') NOT NULL DEFAULT 'Upcoming',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    echo "✅ Table `academic_terms` ensured.\n";

    $x_0002 = $x_0001->query("SELECT COUNT(*) FROM academic_terms");
    $x_0003 = $x_0002->fetchColumn();

    if ($x_0003 == 0) {
        $x_0004 = $x_0001->query("SELECT setting_key, setting_value FROM settings");
        $x_0005 = [];
        while ($x_0006 = $x_0004->fetch()) {
            $x_0005[$x_0006['setting_key']] = $x_0006['setting_value'];
        }

        $x_0007 = $x_0005['academic_year'] ?? date('Y');
        $x_0008 = $x_0005['current_term'] ?? 'Term 1';

        $x_0009 = date('Y-m-d');
        $x_000a = date('Y-m-d', strtotime('+3 months'));

        $x_000b = $x_0001->prepare("
            INSERT INTO academic_terms (term_name, academic_year, start_date, end_date, status)
            VALUES (?, ?, ?, ?, 'Active')
        ");
        $x_000b->execute([$x_0008, $x_0007, $x_0009, $x_000a]);
        echo "✅ Seeded initial Active term: $x_0008 ($x_0007)\n";
    } else {
        echo "ℹ️ `academic_terms` table already has data. Skipping seed.\n";
    }

    echo "\n🎉 Migration completed successfully.";

} catch (PDOException $e) {
    die('❌ Migration failed: ' . $e->getMessage());
}
