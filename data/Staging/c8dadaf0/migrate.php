<?php
require_once 'c:\xampp\htdocs\school attendance\api\config.php';
try {
    // Drop the unique constraint if it exists (by dropping the index `email`)
    $pdo->exec("ALTER TABLE users DROP INDEX email");
} catch(Exception $e) {}

try {
    // Change email column to username
    $pdo->exec("ALTER TABLE users CHANGE email username VARCHAR(255) NOT NULL UNIQUE");
    echo "Column renamed to username successfully.";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
