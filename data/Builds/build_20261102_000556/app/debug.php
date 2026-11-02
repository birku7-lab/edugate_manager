<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'c:\xampp\htdocs\school attendance\api\config.php';

$_POST['name'] = 'Test Admin';
$_POST['username'] = 'testadmin2';
$_POST['password'] = 'admin123';
$_POST['role'] = 'Admin';
$_POST['permissions'] = '["all"]';

$x_0001 = $_POST['name'] ?? '';
$x_0002 = $_POST['username'] ?? '';
$x_0003 = $_POST['password'] ?? '';
$x_0004 = $_POST['role'] ?? 'Staff';
$x_0005 = $_POST['permissions'] ?? '[]'; // JSON array string

if (!$x_0001 || !$x_0002 || !$x_0003) {
    echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
    exit;
}

$x_0006 = $x_0007->prepare("SELECT id FROM users WHERE username = ?");
$x_0006->execute([$x_0002]);
if($x_0006->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
    exit;
}

try {
    $x_0008 = password_hash($x_0003, PASSWORD_DEFAULT);
    $x_0009 = $x_0007->prepare("INSERT INTO users (name, username, password_hash, role, permissions) VALUES (?, ?, ?, ?, ?)");
    $x_0009->execute([$x_0001, $x_0002, $x_0008, $x_0004, $x_0005]);
    
    echo json_encode(['status' => 'success', 'message' => 'Staff added successfully']);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
