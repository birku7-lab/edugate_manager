<?php
require_once __DIR__ . '/api/config.php';
try {
    $pdo->exec("UPDATE users SET username='admin' WHERE username='admin@edugate.com'");
    echo "Updated admin username.";
} catch(Exception $e) {}
?>
