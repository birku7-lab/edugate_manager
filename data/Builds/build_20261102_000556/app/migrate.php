<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'c:\xampp\htdocs\school attendance\api\config.php';
try {

    $x_0001->exec("ALTER TABLE users DROP INDEX email");
} catch(Exception $e) {}

try {

    $x_0001->exec("ALTER TABLE users CHANGE email username VARCHAR(255) NOT NULL UNIQUE");
    echo "Column renamed to username successfully.";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
