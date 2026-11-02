<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

$x_0001 = $_GET['action'] ?? 'list';

try {
    if ($x_0001 === 'list') {
        $x_0002 = $x_0003->query("SELECT * FROM holidays ORDER BY start_date ASC");
        $x_0004 = $x_0002->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $x_0004]);
    } 
    elseif ($x_0001 === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_0005 = $_POST['name'] ?? '';
        $x_0006 = $_POST['start_date'] ?? '';
        $x_0007 = $_POST['end_date'] ?? '';
        $x_0008 = $_POST['description'] ?? '';

        if (!$x_0005 || !$x_0006 || !$x_0007) {
            echo json_encode(['status' => 'error', 'message' => 'Name and dates are required']);
            exit;
        }

        $x_0002 = $x_0003->prepare("INSERT INTO holidays (name, start_date, end_date, description) VALUES (?, ?, ?, ?)");
        $x_0002->execute([$x_0005, $x_0006, $x_0007, $x_0008]);
        
        log_audit($x_0003, $x_0009['id'] ?? null, 'ADD_HOLIDAY', "Added holiday: $x_0005");
        echo json_encode(['status' => 'success', 'message' => 'Holiday added successfully', 'id' => $x_0003->lastInsertId()]);
    }
    elseif ($x_0001 === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $_POST['id'] ?? 0;
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'ID is required']);
            exit;
        }

        $x_0002 = $x_0003->prepare("DELETE FROM holidays WHERE id = ?");
        $x_0002->execute([$id]);
        
        log_audit($x_0003, $x_0009['id'] ?? null, 'DELETE_HOLIDAY', "Deleted holiday ID: $id");
        echo json_encode(['status' => 'success', 'message' => 'Holiday deleted successfully']);
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in holidays.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong.']);
}
?>
