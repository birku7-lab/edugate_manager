<?php
$_SERVER['SERVER_NAME'] = 'localhost';
require_once 'api/config.php';

$stmt = $pdo->query("SELECT id, full_name, parent_name, parent_phone FROM students");
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "STUDENTS:\n";
print_r($students);

$stmt = $pdo->query("SELECT * FROM student_billing");
$billing = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "BILLING:\n";
print_r($billing);

$stmt = $pdo->query("SELECT * FROM unallocated_payments ORDER BY id DESC LIMIT 5");
$unallocated = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "UNALLOCATED:\n";
print_r($unallocated);
?>
