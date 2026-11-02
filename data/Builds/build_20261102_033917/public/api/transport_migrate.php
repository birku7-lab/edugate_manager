<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: text/html; charset=utf-8');

$host = 'localhost';
$db   = 'school_attendance';
$user = 'root';
$pass = '';

$server_name = $_SERVER['SERVER_NAME'] ?? '';
if ($server_name !== 'localhost' && $server_name !== '127.0.0.1') {
    $db   = 'edugowks_attendance';
    $user = 'edugowks_Success';
    $pass = 'success10152653';
}

$errors  = [];
$success = [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('<p style="color:red">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

try {
    $pdo->exec("
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
    $success[] = "✅ Table <strong>buses</strong> ready.";
} catch (PDOException $e) {
    $errors[] = "❌ buses: " . $e->getMessage();
}

try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `assigned_bus_id` INT(11) DEFAULT NULL AFTER `permissions`");
    $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT `fk_users_bus` FOREIGN KEY (`assigned_bus_id`) REFERENCES `buses`(`id`) ON DELETE SET NULL");
    $success[] = "✅ Added <strong>assigned_bus_id</strong> to users table.";
} catch (PDOException $e) {

}

try {
    $pdo->exec("ALTER TABLE `students` ADD COLUMN `assigned_bus_id` INT(11) DEFAULT NULL");
    $pdo->exec("ALTER TABLE `students` ADD CONSTRAINT `fk_students_bus` FOREIGN KEY (`assigned_bus_id`) REFERENCES `buses`(`id`) ON DELETE SET NULL");
    $pdo->exec("ALTER TABLE `students` ADD COLUMN `pickup_location` VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE `students` ADD COLUMN `dropoff_location` VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE `students` ADD COLUMN `pickup_time` TIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE `students` ADD COLUMN `dropoff_time` TIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE `students` ADD COLUMN `route_number` VARCHAR(100) DEFAULT NULL");
    $success[] = "✅ Added transport fields to <strong>students</strong> table.";
} catch (PDOException $e) {

}

try {
    $pdo->exec("
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
    $success[] = "✅ Table <strong>meal_logs</strong> ready.";
} catch (PDOException $e) {
    $errors[] = "❌ meal_logs: " . $e->getMessage();
}

try {
    $pdo->exec("
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
    $success[] = "✅ Table <strong>transport_logs</strong> ready.";
} catch (PDOException $e) {
    $errors[] = "❌ transport_logs: " . $e->getMessage();
}

echo "<h2>EduGate Transport & Meals Migration</h2>";
if (!empty($errors)) {
    echo '<div style="color:red; background:#fee; padding:10px; border-radius:5px;">';
    echo "<h3>Errors:</h3><ul>";
    foreach ($errors as $e) echo "<li>$e</li>";
    echo "</ul></div>";
}
if (!empty($success)) {
    echo '<div style="color:green; background:#efe; padding:10px; border-radius:5px; margin-top:20px;">';
    echo "<h3>Success:</h3><ul>";
    foreach ($success as $s) echo "<li>$s</li>";
    echo "</ul></div>";
}
echo "<p>Migration complete. Review the messages above.</p>";
