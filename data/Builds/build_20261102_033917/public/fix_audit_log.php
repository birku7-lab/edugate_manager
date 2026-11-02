<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: text/plain');
require_once __DIR__ . '/api/config.php';

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'audit_log'");
    if ($stmt->rowCount() > 0) {
        echo "audit_log table already exists. No changes made.\n";
        echo "You can delete this file now.\n";
        exit;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(255) NOT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(45) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    echo "SUCCESS: audit_log table created.\n";
    echo "Live Sync should now work. Please delete this file from your server.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
