<?php
require_once 'c:\xampp\htdocs\school attendance\api\config.php';

$_POST['name'] = 'Test Admin';
$_POST['username'] = 'testadmin2';
$_POST['password'] = 'admin123';
$_POST['role'] = 'Admin';
$_POST['permissions'] = '["all"]';

$name = $_POST['name'] ?? '';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'Staff';
$permissions = $_POST['permissions'] ?? '[]'; // JSON array string

if (!$name || !$username || !$password) {
    echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
    exit;
}

// Check if username exists
$check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$check->execute([$username]);
if($check->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
    exit;
}

try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, username, password_hash, role, permissions) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $username, $hash, $role, $permissions]);
    
    echo json_encode(['status' => 'success', 'message' => 'Staff added successfully']);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
