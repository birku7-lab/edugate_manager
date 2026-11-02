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
        CREATE TABLE IF NOT EXISTS `billing_categories` (
            `id`             INT(11)        NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(100)   NOT NULL,
            `description`    TEXT           DEFAULT NULL,
            `default_amount` DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            `is_mandatory`   TINYINT(1)     NOT NULL DEFAULT 0,
            `is_active`      TINYINT(1)     NOT NULL DEFAULT 1,
            `sort_order`     INT(11)        NOT NULL DEFAULT 0,
            `due_date`       DATE           DEFAULT NULL,
            `grace_period_days` INT(11)     NOT NULL DEFAULT 0,
            `expiry_date`    DATE           DEFAULT NULL,
            `assignment_type` ENUM('Global', 'Per Class') NOT NULL DEFAULT 'Global',
            `assigned_classes` TEXT           DEFAULT NULL,
            `enforce_on_scanner` TINYINT(1)     NOT NULL DEFAULT 0,
            `created_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`     INT(11)        DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_billing_category_name` (`name`),
            CONSTRAINT `fk_bc_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $success[] = '✅ Table <strong>billing_categories</strong> ready.';
} catch (PDOException $e) {
    $errors[] = '❌ billing_categories: ' . $e->getMessage();
}

$alterQueries = [
    "ALTER TABLE `billing_categories` ADD COLUMN `sort_order` INT(11) NOT NULL DEFAULT 0",
    "ALTER TABLE `billing_categories` ADD COLUMN `due_date` DATE DEFAULT NULL",
    "ALTER TABLE `billing_categories` ADD COLUMN `grace_period_days` INT(11) NOT NULL DEFAULT 0",
    "ALTER TABLE `billing_categories` ADD COLUMN `expiry_date` DATE DEFAULT NULL",
    "ALTER TABLE `billing_categories` ADD COLUMN `assignment_type` ENUM('Global', 'Per Class') NOT NULL DEFAULT 'Global'",
    "ALTER TABLE `billing_categories` ADD COLUMN `assigned_classes` TEXT DEFAULT NULL",
    "ALTER TABLE `billing_categories` ADD COLUMN `enforce_on_scanner` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `billing_categories` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
    "ALTER TABLE `billing_categories` ADD COLUMN `created_by` INT(11) DEFAULT NULL"
];

foreach ($alterQueries as $q) {
    try {
        $pdo->exec($q);
    } catch (PDOException $e) {

    }
}
$success[] = '✅ Patched <strong>billing_categories</strong> with new config columns.';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_billing` (
            `id`             INT(11)        NOT NULL AUTO_INCREMENT,
            `student_id`     INT(11)        NOT NULL,
            `category_id`    INT(11)        NOT NULL,
            `academic_year`  VARCHAR(20)    NOT NULL,
            `school_term`    VARCHAR(50)    NOT NULL,
            `amount_due`     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            `amount_paid`    DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            `payment_status` ENUM('Unpaid','Partial','Paid','Exempt') NOT NULL DEFAULT 'Unpaid',
            `due_date`       DATE           DEFAULT NULL,
            `payment_date`   DATE           DEFAULT NULL,
            `notes`          TEXT           DEFAULT NULL,
            `updated_by`     INT(11)        DEFAULT NULL,
            `updated_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_student_cat_term` (`student_id`, `category_id`, `academic_year`, `school_term`),
            CONSTRAINT `fk_sb_student`    FOREIGN KEY (`student_id`)  REFERENCES `students` (`id`)           ON DELETE CASCADE,
            CONSTRAINT `fk_sb_category`   FOREIGN KEY (`category_id`) REFERENCES `billing_categories` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_sb_updated_by` FOREIGN KEY (`updated_by`)  REFERENCES `users` (`id`)              ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $success[] = '✅ Table <strong>student_billing</strong> ready.';
} catch (PDOException $e) {
    $errors[] = '❌ student_billing: ' . $e->getMessage();
}

$sbQueries = [
    "ALTER TABLE `student_billing` ADD COLUMN `due_date` DATE DEFAULT NULL",
    "ALTER TABLE `student_billing` ADD COLUMN `payment_date` DATE DEFAULT NULL",
    "ALTER TABLE `student_billing` ADD COLUMN `notes` TEXT DEFAULT NULL",
    "ALTER TABLE `student_billing` ADD COLUMN `updated_by` INT(11) DEFAULT NULL",
    "ALTER TABLE `student_billing` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    "ALTER TABLE `student_billing` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
];
foreach ($sbQueries as $q) {
    try { $pdo->exec($q); } catch (PDOException $e) {}
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `billing_payment_history` (
            `id`             INT(11)        NOT NULL AUTO_INCREMENT,
            `billing_id`     INT(11)        NOT NULL,
            `student_id`     INT(11)        NOT NULL,
            `category_id`    INT(11)        NOT NULL,
            `academic_year`  VARCHAR(20)    NOT NULL,
            `school_term`    VARCHAR(50)    NOT NULL,
            `amount_due`     DECIMAL(10,2)  NOT NULL,
            `amount_paid`    DECIMAL(10,2)  NOT NULL,
            `balance`        DECIMAL(10,2)  NOT NULL,
            `payment_status` ENUM('Unpaid','Partial','Paid','Exempt') NOT NULL,
            `payment_date`   DATE           DEFAULT NULL,
            `due_date`       DATE           DEFAULT NULL,
            `notes`          TEXT           DEFAULT NULL,
            `action`         VARCHAR(80)    NOT NULL DEFAULT 'PAYMENT_UPDATED',
            `performed_by`   INT(11)        DEFAULT NULL,
            `performed_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_bph_billing`    FOREIGN KEY (`billing_id`)   REFERENCES `student_billing` (`id`)      ON DELETE CASCADE,
            CONSTRAINT `fk_bph_student`    FOREIGN KEY (`student_id`)   REFERENCES `students` (`id`)             ON DELETE CASCADE,
            CONSTRAINT `fk_bph_category`   FOREIGN KEY (`category_id`)  REFERENCES `billing_categories` (`id`)   ON DELETE CASCADE,
            CONSTRAINT `fk_bph_performed`  FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`)                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $success[] = '✅ Table <strong>billing_payment_history</strong> ready.';
} catch (PDOException $e) {
    $errors[] = '❌ billing_payment_history: ' . $e->getMessage();
}

$historyQueries = [
    "ALTER TABLE `billing_payment_history` ADD COLUMN `transaction_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `amount_paid`",
    "ALTER TABLE `billing_payment_history` ADD COLUMN `previous_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `transaction_amount`",
    "ALTER TABLE `billing_payment_history` ADD COLUMN `payment_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `balance`",
    "ALTER TABLE `billing_payment_history` ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL AFTER `payment_percentage`",
    "ALTER TABLE `billing_payment_history` ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `payment_method`"
];

foreach ($historyQueries as $q) {
    try {
        $pdo->exec($q);
    } catch (PDOException $e) {}
}
$success[] = '✅ Patched <strong>billing_payment_history</strong> with progressive payment columns.';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `custom_scanner_logs` (
            `id`             INT(11)        NOT NULL AUTO_INCREMENT,
            `student_id`     INT(11)        NOT NULL,
            `category_id`    INT(11)        NOT NULL,
            `scan_date`      DATE           NOT NULL,
            `scan_time`      TIME           NOT NULL,
            `scanned_by`     INT(11)        DEFAULT NULL,
            `created_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            CONSTRAINT `fk_csl_student`    FOREIGN KEY (`student_id`)   REFERENCES `students` (`id`)             ON DELETE CASCADE,
            CONSTRAINT `fk_csl_category`   FOREIGN KEY (`category_id`)  REFERENCES `billing_categories` (`id`)   ON DELETE CASCADE,
            CONSTRAINT `fk_csl_scanned_by` FOREIGN KEY (`scanned_by`)   REFERENCES `users` (`id`)                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $success[] = '✅ Table <strong>custom_scanner_logs</strong> ready.';
} catch (PDOException $e) {
    $errors[] = '❌ custom_scanner_logs: ' . $e->getMessage();
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `unallocated_payments` (
            `id`             INT(11)        NOT NULL AUTO_INCREMENT,
            `receipt_number` VARCHAR(50)    NOT NULL,
            `sender_name`    VARCHAR(100)   NOT NULL,
            `sender_phone`   VARCHAR(20)    NOT NULL,
            `amount`         DECIMAL(10,2)  NOT NULL,
            `payment_date`   DATETIME       NOT NULL,
            `raw_sms`        TEXT           NOT NULL,
            `status`         ENUM('Unallocated', 'Allocated') NOT NULL DEFAULT 'Unallocated',
            `allocated_student_id` INT(11)  DEFAULT NULL,
            `created_at`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_receipt_number` (`receipt_number`),
            CONSTRAINT `fk_up_student` FOREIGN KEY (`allocated_student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $success[] = '✅ Table <strong>unallocated_payments</strong> ready.';
} catch (PDOException $e) {
    $errors[] = '❌ unallocated_payments: ' . $e->getMessage();
}

$defaultCategories = [
    ['School Fees', 'Mandatory school tuition fees charged each term',  0.00, 1, 1, 0, 'Global', null],
    ['Meals',       'Optional catering / lunch programme fees',         0.00, 0, 1, 1, 'Per Class', '[]'],
    ['Transport',   'Optional school bus / transport fees',             0.00, 0, 1, 2, 'Per Class', '[]'],
];

try {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO billing_categories (name, description, default_amount, is_mandatory, is_active, sort_order, assignment_type, assigned_classes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($defaultCategories as $cat) {
        $stmt->execute($cat);
    }
    $success[] = '✅ Default categories seeded (School Fees, Meals, Transport).';
} catch (PDOException $e) {
    $errors[] = '❌ Seeding categories: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Billing Migration</title>
    <style>
        body { font-family: Inter, sans-serif; max-width: 700px; margin: 60px auto; padding: 2rem; background:#f8fafc; }
        h1   { color: #1e293b; border-bottom: 2px solid #4f46e5; padding-bottom: 0.5rem; }
        p    { margin: 0.5rem 0; font-size: 1rem; }
        .success { color: #059669; background:#ecfdf5; padding:0.4rem 0.8rem; border-radius:6px; }
        .error   { color: #dc2626; background:#fef2f2; padding:0.4rem 0.8rem; border-radius:6px; }
        a.btn { display:inline-block; margin-top:2rem; padding:0.7rem 1.5rem; background:#4f46e5; color:white; border-radius:8px; text-decoration:none; font-weight:600; }
    </style>
</head>
<body>
    <h1>🏫 EduGate Billing System — Migration</h1>

    <?php foreach ($success as $msg): ?>
        <p class="success"><?= $msg ?></p>
    <?php endforeach; ?>

    <?php foreach ($errors as $msg): ?>
        <p class="error"><?= htmlspecialchars($msg) ?></p>
    <?php endforeach; ?>

    <?php if (empty($errors)): ?>
        <p style="margin-top:1.5rem; color:#1e293b; font-weight:600;">
            ✅ Migration complete. All billing tables are ready.
        </p>
        <a class="btn" href="../billing.html">→ Go to Billing</a>
    <?php else: ?>
        <p style="margin-top:1.5rem; color:#dc2626; font-weight:600;">
            ⚠️ Migration completed with errors. Review the messages above.
        </p>
    <?php endif; ?>
</body>
</html>
