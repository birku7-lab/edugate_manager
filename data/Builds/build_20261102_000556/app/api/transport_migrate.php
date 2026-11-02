<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: text/html; charset=utf-8');

$x_0001 = 'localhost';
$db   = 'school_attendance';
$x_0002 = 'root';
$x_0003 = '';

$x_0004 = $_SERVER['SERVER_NAME'] ?? '';
if ($x_0004 !== 'localhost' && $x_0004 !== '127.0.0.1') {
    $db   = 'edugowks_attendance';
    $x_0002 = 'edugowks_Success';
    $x_0003 = 'success10152653';
}

$x_0005  = [];
$x_0006 = [];

try {
    $x_0007 = new PDO("mysql:host=$x_0001;dbname=$db;charset=utf8mb4", $x_0002, $x_0003);
    $x_0007->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('<p style="color:red">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

try {
    $x_0007->exec("
        CREATE TABLE IF NOT EXISTS `buses` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `registration_number` VARCHAR(50) NOT NULL,
            `driver_name` VARCHAR(100) DEFAULT NULL,
            `driver_phone` VARCHAR(20) DEFAULT NULL,
            `capacity` INT(11) DEFAULT 0,
            `route` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_bus_reg` (`registration_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $x_0006[] = "✅ Table <strong>buses</strong> ready.";
} catch (PDOException $e) {
    $x_0005[] = "❌ buses: " . $e->getMessage();
}

try {
    $x_0007->exec("ALTER TABLE `users` ADD COLUMN `assigned_bus_id` INT(11) DEFAULT NULL AFTER `permissions`");
    $x_0007->exec("ALTER TABLE `users` ADD CONSTRAINT `fk_users_bus` FOREIGN KEY (`assigned_bus_id`) REFERENCES `buses`(`id`) ON DELETE SET NULL");
    $x_0006[] = "✅ Added <strong>assigned_bus_id</strong> to users table.";
} catch (PDOException $e) {

}

try {
    $x_0007->exec("ALTER TABLE `students` ADD COLUMN `assigned_bus_id` INT(11) DEFAULT NULL");
    $x_0007->exec("ALTER TABLE `students` ADD CONSTRAINT `fk_students_bus` FOREIGN KEY (`assigned_bus_id`) REFERENCES `buses`(`id`) ON DELETE SET NULL");
    $x_0007->exec("ALTER TABLE `students` ADD COLUMN `pickup_location` VARCHAR(255) DEFAULT NULL");
    $x_0007->exec("ALTER TABLE `students` ADD COLUMN `dropoff_location` VARCHAR(255) DEFAULT NULL");
    $x_0007->exec("ALTER TABLE `students` ADD COLUMN `pickup_time` TIME DEFAULT NULL");
    $x_0007->exec("ALTER TABLE `students` ADD COLUMN `dropoff_time` TIME DEFAULT NULL");
    $x_0007->exec("ALTER TABLE `students` ADD COLUMN `route_number` VARCHAR(100) DEFAULT NULL");
    $x_0006[] = "✅ Added transport fields to <strong>students</strong> table.";
} catch (PDOException $e) {

}

try {
    $x_0007->exec("
        CREATE TABLE IF NOT EXISTS `meal_logs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `student_id` INT(11) NOT NULL,
            `meal_date` DATE NOT NULL,
            `meal_type` ENUM('Normal', 'Special Meal') NOT NULL DEFAULT 'Normal',
            `scanned_by` INT(11) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_daily_meal` (`student_id`, `meal_date`, `meal_type`),
            CONSTRAINT `fk_ml_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ml_user` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $x_0006[] = "✅ Table <strong>meal_logs</strong> ready.";
} catch (PDOException $e) {
    $x_0005[] = "❌ meal_logs: " . $e->getMessage();
}

try {
    $x_0007->exec("
        CREATE TABLE IF NOT EXISTS `transport_logs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `student_id` INT(11) NOT NULL,
            `bus_id` INT(11) NOT NULL,
            `board_date` DATE NOT NULL,
            `board_time` TIME NOT NULL,
            `scanned_by` INT(11) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_daily_transport` (`student_id`, `board_date`),
            CONSTRAINT `fk_tl_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tl_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tl_user` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $x_0006[] = "✅ Table <strong>transport_logs</strong> ready.";
} catch (PDOException $e) {
    $x_0005[] = "❌ transport_logs: " . $e->getMessage();
}

echo "<h2>EduGate Transport & Meals Migration</h2>";
if (!empty($x_0005)) {
    echo '<div style="color:red; background:#fee; padding:10px; border-radius:5px;">';
    echo "<h3>Errors:</h3><ul>";
    foreach ($x_0005 as $e) echo "<li>$e</li>";
    echo "</ul></div>";
}
if (!empty($x_0006)) {
    echo '<div style="color:green; background:#efe; padding:10px; border-radius:5px; margin-top:20px;">';
    echo "<h3>Success:</h3><ul>";
    foreach ($x_0006 as $s) echo "<li>$s</li>";
    echo "</ul></div>";
}
echo "<p>Migration complete. Review the messages above.</p>";
