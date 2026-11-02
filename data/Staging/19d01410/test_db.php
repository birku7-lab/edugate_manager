<?php
require 'C:/xampp/htdocs/school attendance/api/config.php';
$stmt = $pdo->query("SELECT * FROM billing_categories");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("SELECT * FROM student_billing");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
