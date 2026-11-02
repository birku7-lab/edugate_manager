<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

function isPasswordStrong($x_0001) {
    return strlen($x_0001) >= 12 
        && preg_match('/[A-Z]/', $x_0001) 
        && preg_match('/[a-z]/', $x_0001) 
        && preg_match('/[0-9]/', $x_0001) 
        && preg_match('/[^a-zA-Z0-9]/', $x_0001);
}

if (!isset($x_0002) || $x_0002['role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, name, username, role, permissions, assigned_bus_id, created_at FROM users");
        $x_0003 = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $x_0003]);
    } 
    elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'Staff';
        $permissions = $_POST['permissions'] ?? '[]'; // JSON array string
        $x_0004 = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;

        if (!$name || !$username || !$password) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
            exit;
        }

        $x_0005 = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $x_0005->execute([$username]);
        if($x_0005->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }

        if (!isPasswordStrong($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 12 characters and include uppercase, lowercase, number, and special character.']);
            exit;
        }

        $x_0006 = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO users (name, username, password_hash, role, permissions, assigned_bus_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $username, $x_0006, $role, $permissions, $x_0004]);
        
        log_audit($pdo, $x_0002['id'], 'ADD_STAFF', "Added staff member: $username");
        echo json_encode(['status' => 'success', 'message' => 'Staff added successfully']);
    }
    elseif ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'Staff';
        $permissions = $_POST['permissions'] ?? '[]'; // JSON array string
        $x_0004 = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;

        if (!$id || !$name || !$username) {
            echo json_encode(['status' => 'error', 'message' => 'Name and Username are required']);
            exit;
        }

        $x_0005 = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $x_0005->execute([$username, $id]);
        if($x_0005->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }

        if (!empty($password)) {
            if (!isPasswordStrong($password)) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 12 characters and include uppercase, lowercase, number, and special character.']);
                exit;
            }
            $x_0006 = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, password_hash = ?, role = ?, permissions = ?, assigned_bus_id = ? WHERE id = ?");
            $stmt->execute([$name, $username, $x_0006, $role, $permissions, $x_0004, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, permissions = ?, assigned_bus_id = ? WHERE id = ?");
            $stmt->execute([$name, $username, $role, $permissions, $x_0004, $id]);
        }
        
        log_audit($pdo, $x_0002['id'], 'EDIT_STAFF', "Edited staff member ID: $id");
        echo json_encode(['status' => 'success', 'message' => 'Staff updated successfully']);
    }
    elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        
        if ($id == $x_0002['id']) {
            echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account!']);
            exit;
        }

        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            log_audit($pdo, $x_0002['id'], 'DELETE_STAFF', "Deleted staff member ID: $id");
            echo json_encode(['status' => 'success', 'message' => 'Staff deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        }
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in staff.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong.']);
}
?>
