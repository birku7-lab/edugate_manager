<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

if (!isset($current_user) || $current_user['role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, name, registration_number, driver_name, driver_phone, capacity, route, status FROM buses ORDER BY name ASC");
        $buses = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $buses]);
    }
    elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? '';
        $reg_num = $_POST['registration_number'] ?? '';
        $driver = $_POST['driver_name'] ?? '';
        $phone = $_POST['driver_phone'] ?? '';
        $capacity = (int)($_POST['capacity'] ?? 0);
        $route = $_POST['route'] ?? '';
        $status = $_POST['status'] ?? 'Active';

        if (!$name || !$reg_num) {
            echo json_encode(['status' => 'error', 'message' => 'Name and Registration Number are required.']);
            exit;
        }

        // Check if registration number exists
        $check = $pdo->prepare("SELECT id FROM buses WHERE registration_number = ?");
        $check->execute([$reg_num]);
        if($check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Registration Number already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO buses (name, registration_number, driver_name, driver_phone, capacity, route, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $reg_num, $driver, $phone, $capacity, $route, $status, $current_user['id']]);
        
        log_audit($pdo, $current_user['id'], 'ADD_BUS', "Added bus: $reg_num");
        echo json_encode(['status' => 'success', 'message' => 'Bus added successfully']);
    }
    elseif ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $name = $_POST['name'] ?? '';
        $reg_num = $_POST['registration_number'] ?? '';
        $driver = $_POST['driver_name'] ?? '';
        $phone = $_POST['driver_phone'] ?? '';
        $capacity = (int)($_POST['capacity'] ?? 0);
        $route = $_POST['route'] ?? '';
        $status = $_POST['status'] ?? 'Active';

        if (!$id || !$name || !$reg_num) {
            echo json_encode(['status' => 'error', 'message' => 'ID, Name, and Registration Number are required.']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM buses WHERE registration_number = ? AND id != ?");
        $check->execute([$reg_num, $id]);
        if($check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Registration Number already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE buses SET name=?, registration_number=?, driver_name=?, driver_phone=?, capacity=?, route=?, status=? WHERE id=?");
        $stmt->execute([$name, $reg_num, $driver, $phone, $capacity, $route, $status, $id]);
        
        log_audit($pdo, $current_user['id'], 'EDIT_BUS', "Edited bus ID: $id");
        echo json_encode(['status' => 'success', 'message' => 'Bus updated successfully']);
    }
    elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Check if bus is assigned to students or staff or has logs?
            // Depending on foreign key constraints, it will throw an error if used.
            // Better to recommend "Inactive" status instead of delete, but we'll try to delete.
            try {
                $stmt = $pdo->prepare("DELETE FROM buses WHERE id = ?");
                $stmt->execute([$id]);
                log_audit($pdo, $current_user['id'], 'DELETE_BUS', "Deleted bus ID: $id");
                echo json_encode(['status' => 'success', 'message' => 'Bus deleted successfully']);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete bus because it is currently assigned to students or staff. Please set it to Inactive instead.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        }
    }
    elseif ($action === 'get_assignable_students') {
        $bus_id = (int)($_GET['bus_id'] ?? 0);
        if (!$bus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Bus ID required.']);
            exit;
        }
        
        $query = "SELECT s.id, s.full_name, s.admission_number, s.class, s.assigned_bus_id 
                  FROM students s
                  ORDER BY s.class ASC, s.full_name ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'data' => $students]);
    }
    elseif ($action === 'assign_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $bus_id = (int)($_POST['bus_id'] ?? 0);
        $student_ids = json_decode($_POST['student_ids'] ?? '[]', true);
        
        if (!$bus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Bus ID required.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Unassign students currently assigned to this bus who are NOT in the new list
            if (empty($student_ids)) {
                $stmt = $pdo->prepare("UPDATE students SET assigned_bus_id = NULL WHERE assigned_bus_id = ?");
                $stmt->execute([$bus_id]);
            } else {
                $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
                $stmt = $pdo->prepare("UPDATE students SET assigned_bus_id = NULL WHERE assigned_bus_id = ? AND id NOT IN ($placeholders)");
                $params = array_merge([$bus_id], $student_ids);
                $stmt->execute($params);

                // Assign the new students to this bus
                $stmt2 = $pdo->prepare("UPDATE students SET assigned_bus_id = ? WHERE id IN ($placeholders)");
                $params2 = array_merge([$bus_id], $student_ids);
                $stmt2->execute($params2);
            }
            $pdo->commit();
            log_audit($pdo, $current_user['id'], 'ASSIGN_BUS_STUDENTS', "Assigned students to bus ID: $bus_id");
            echo json_encode(['status' => 'success', 'message' => 'Students assigned successfully.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Failed to assign students: ' . $e->getMessage()]);
        }
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in buses.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong.']);
}
?>
