<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

function isPasswordStrong($pwd) {
    return strlen($pwd) >= 12 
        && preg_match('/[A-Z]/', $pwd) 
        && preg_match('/[a-z]/', $pwd) 
        && preg_match('/[0-9]/', $pwd) 
        && preg_match('/[^a-zA-Z0-9]/', $pwd);
}

if (!isset($current_user) || $current_user['role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, name, username, role, permissions, assigned_bus_id, created_at FROM users");
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $staff]);
    } 
    elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'Staff';
        $permissions = $_POST['permissions'] ?? '[]'; // JSON array string
        $assigned_bus_id = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;

        if (!$name || !$username || !$password) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if($check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }

        if (!isPasswordStrong($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 12 characters and include uppercase, lowercase, number, and special character.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO users (name, username, password_hash, role, permissions, assigned_bus_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $username, $hash, $role, $permissions, $assigned_bus_id]);
        
        log_audit($pdo, $current_user['id'], 'ADD_STAFF', "Added staff member: $username");
        echo json_encode(['status' => 'success', 'message' => 'Staff added successfully']);
    }
    elseif ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'Staff';
        $permissions = $_POST['permissions'] ?? '[]'; // JSON array string
        $assigned_bus_id = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;

        if (!$id || !$name || !$username) {
            echo json_encode(['status' => 'error', 'message' => 'Name and Username are required']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $id]);
        if($check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }

        if (!empty($password)) {
            if (!isPasswordStrong($password)) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 12 characters and include uppercase, lowercase, number, and special character.']);
                exit;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, password_hash = ?, role = ?, permissions = ?, assigned_bus_id = ? WHERE id = ?");
            $stmt->execute([$name, $username, $hash, $role, $permissions, $assigned_bus_id, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, permissions = ?, assigned_bus_id = ? WHERE id = ?");
            $stmt->execute([$name, $username, $role, $permissions, $assigned_bus_id, $id]);
        }
        
        log_audit($pdo, $current_user['id'], 'EDIT_STAFF', "Edited staff member ID: $id");
        echo json_encode(['status' => 'success', 'message' => 'Staff updated successfully']);
    }
    elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        
        if ($id == $current_user['id']) {
            echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account!']);
            exit;
        }

        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            log_audit($pdo, $current_user['id'], 'DELETE_STAFF', "Deleted staff member ID: $id");
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
