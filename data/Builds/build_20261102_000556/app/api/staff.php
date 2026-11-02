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

$x_0003 = $_GET['action'] ?? 'list';

try {
    if ($x_0003 === 'list') {
        $x_0004 = $x_0005->query("SELECT id, name, username, role, permissions, assigned_bus_id, created_at FROM users");
        $x_0006 = $x_0004->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $x_0006]);
    } 
    elseif ($x_0003 === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_0007 = $_POST['name'] ?? '';
        $x_0008 = $_POST['username'] ?? '';
        $x_0009 = $_POST['password'] ?? '';
        $x_000a = $_POST['role'] ?? 'Staff';
        $x_000b = $_POST['permissions'] ?? '[]'; // JSON array string
        $x_000c = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;

        if (!$x_0007 || !$x_0008 || !$x_0009) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
            exit;
        }

        $x_000d = $x_0005->prepare("SELECT id FROM users WHERE username = ?");
        $x_000d->execute([$x_0008]);
        if($x_000d->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }

        if (!isPasswordStrong($x_0009)) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 12 characters and include uppercase, lowercase, number, and special character.']);
            exit;
        }

        $x_000e = password_hash($x_0009, PASSWORD_BCRYPT, ['cost' => 12]);
        $x_0004 = $x_0005->prepare("INSERT INTO users (name, username, password_hash, role, permissions, assigned_bus_id) VALUES (?, ?, ?, ?, ?, ?)");
        $x_0004->execute([$x_0007, $x_0008, $x_000e, $x_000a, $x_000b, $x_000c]);
        
        log_audit($x_0005, $x_0002['id'], 'ADD_STAFF', "Added staff member: $x_0008");
        echo json_encode(['status' => 'success', 'message' => 'Staff added successfully']);
    }
    elseif ($x_0003 === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        $x_0007 = $_POST['name'] ?? '';
        $x_0008 = $_POST['username'] ?? '';
        $x_0009 = $_POST['password'] ?? '';
        $x_000a = $_POST['role'] ?? 'Staff';
        $x_000b = $_POST['permissions'] ?? '[]'; // JSON array string
        $x_000c = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;

        if (!$id || !$x_0007 || !$x_0008) {
            echo json_encode(['status' => 'error', 'message' => 'Name and Username are required']);
            exit;
        }

        $x_000d = $x_0005->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $x_000d->execute([$x_0008, $id]);
        if($x_000d->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }

        if (!empty($x_0009)) {
            if (!isPasswordStrong($x_0009)) {
                echo json_encode(['status' => 'error', 'message' => 'Password must be at least 12 characters and include uppercase, lowercase, number, and special character.']);
                exit;
            }
            $x_000e = password_hash($x_0009, PASSWORD_BCRYPT, ['cost' => 12]);
            $x_0004 = $x_0005->prepare("UPDATE users SET name = ?, username = ?, password_hash = ?, role = ?, permissions = ?, assigned_bus_id = ? WHERE id = ?");
            $x_0004->execute([$x_0007, $x_0008, $x_000e, $x_000a, $x_000b, $x_000c, $id]);
        } else {
            $x_0004 = $x_0005->prepare("UPDATE users SET name = ?, username = ?, role = ?, permissions = ?, assigned_bus_id = ? WHERE id = ?");
            $x_0004->execute([$x_0007, $x_0008, $x_000a, $x_000b, $x_000c, $id]);
        }
        
        log_audit($x_0005, $x_0002['id'], 'EDIT_STAFF', "Edited staff member ID: $id");
        echo json_encode(['status' => 'success', 'message' => 'Staff updated successfully']);
    }
    elseif ($x_0003 === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        
        if ($id == $x_0002['id']) {
            echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account!']);
            exit;
        }

        if ($id) {
            $x_0004 = $x_0005->prepare("DELETE FROM users WHERE id = ?");
            $x_0004->execute([$id]);
            log_audit($x_0005, $x_0002['id'], 'DELETE_STAFF', "Deleted staff member ID: $id");
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
