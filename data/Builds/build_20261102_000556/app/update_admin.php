<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'c:\xampp\htdocs\school attendance\api\config.php';
try {
    $x_0001->exec("UPDATE users SET username='admin' WHERE username='admin@edugate.com'");
    echo "Updated admin username.";
} catch(Exception $e) {}
?>
