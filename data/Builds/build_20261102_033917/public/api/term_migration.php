<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: text/plain; charset=utf-8');
require_once 'config.php';

try {

    $pdo->exec("
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

    $stmt = $pdo->query("SELECT COUNT(*) FROM academic_terms");
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        $x_0001 = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $x_0002 = [];
        while ($row = $x_0001->fetch()) {
            $x_0002[$row['setting_key']] = $row['setting_value'];
        }

        $x_0003 = $x_0002['academic_year'] ?? date('Y');
        $x_0004 = $x_0002['current_term'] ?? 'Term 1';

        $x_0005 = date('Y-m-d');
        $x_0006 = date('Y-m-d', strtotime('+3 months'));

        $x_0007 = $pdo->prepare("
            INSERT INTO academic_terms (term_name, academic_year, start_date, end_date, status)
            VALUES (?, ?, ?, ?, 'Active')
        ");
        $x_0007->execute([$x_0004, $x_0003, $x_0005, $x_0006]);
        echo "✅ Seeded initial Active term: $x_0004 ($x_0003)\n";
    } else {
        echo "ℹ️ `academic_terms` table already has data. Skipping seed.\n";
    }

    echo "\n🎉 Migration completed successfully.";

} catch (PDOException $e) {
    die('❌ Migration failed: ' . $e->getMessage());
}
