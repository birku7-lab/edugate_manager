<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

if (!isset($x_0001) || $x_0001['role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, name, registration_number, driver_name, driver_phone, capacity, route, status FROM buses ORDER BY name ASC");
        $x_0002 = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $x_0002]);
    }
    elseif ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? '';
        $x_0003 = $_POST['registration_number'] ?? '';
        $x_0004 = $_POST['driver_name'] ?? '';
        $x_0005 = $_POST['driver_phone'] ?? '';
        $x_0006 = (int)($_POST['capacity'] ?? 0);
        $x_0007 = $_POST['route'] ?? '';
        $status = $_POST['status'] ?? 'Active';

        if (!$name || !$x_0003) {
            echo json_encode(['status' => 'error', 'message' => 'Name and Registration Number are required.']);
            exit;
        }

        $x_0008 = $pdo->prepare("SELECT id FROM buses WHERE registration_number = ?");
        $x_0008->execute([$x_0003]);
        if($x_0008->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Registration Number already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO buses (name, registration_number, driver_name, driver_phone, capacity, route, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $x_0003, $x_0004, $x_0005, $x_0006, $x_0007, $status, $x_0001['id']]);
        
        log_audit($pdo, $x_0001['id'], 'ADD_BUS', "Added bus: $x_0003");
        echo json_encode(['status' => 'success', 'message' => 'Bus added successfully']);
    }
    elseif ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $name = $_POST['name'] ?? '';
        $x_0003 = $_POST['registration_number'] ?? '';
        $x_0004 = $_POST['driver_name'] ?? '';
        $x_0005 = $_POST['driver_phone'] ?? '';
        $x_0006 = (int)($_POST['capacity'] ?? 0);
        $x_0007 = $_POST['route'] ?? '';
        $status = $_POST['status'] ?? 'Active';

        if (!$id || !$name || !$x_0003) {
            echo json_encode(['status' => 'error', 'message' => 'ID, Name, and Registration Number are required.']);
            exit;
        }

        $x_0008 = $pdo->prepare("SELECT id FROM buses WHERE registration_number = ? AND id != ?");
        $x_0008->execute([$x_0003, $id]);
        if($x_0008->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Registration Number already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE buses SET name=?, registration_number=?, driver_name=?, driver_phone=?, capacity=?, route=?, status=? WHERE id=?");
        $stmt->execute([$name, $x_0003, $x_0004, $x_0005, $x_0006, $x_0007, $status, $id]);
        
        log_audit($pdo, $x_0001['id'], 'EDIT_BUS', "Edited bus ID: $id");
        echo json_encode(['status' => 'success', 'message' => 'Bus updated successfully']);
    }
    elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {

            try {
                $stmt = $pdo->prepare("DELETE FROM buses WHERE id = ?");
                $stmt->execute([$id]);
                log_audit($pdo, $x_0001['id'], 'DELETE_BUS', "Deleted bus ID: $id");
                echo json_encode(['status' => 'success', 'message' => 'Bus deleted successfully']);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete bus because it is currently assigned to students or staff. Please set it to Inactive instead.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        }
    }
    elseif ($action === 'get_assignable_students') {
        $x_0009 = (int)($_GET['bus_id'] ?? 0);
        if (!$x_0009) {
            echo json_encode(['status' => 'error', 'message' => 'Bus ID required.']);
            exit;
        }
        
        $query = "SELECT s.id, s.full_name, s.admission_number, s.class, s.assigned_bus_id 
                  FROM students s
                  ORDER BY s.class ASC, s.full_name ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $x_000a = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'data' => $x_000a]);
    }
    elseif ($action === 'assign_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_0009 = (int)($_POST['bus_id'] ?? 0);
        $x_000b = json_decode($_POST['student_ids'] ?? '[]', true);
        
        if (!$x_0009) {
            echo json_encode(['status' => 'error', 'message' => 'Bus ID required.']);
            exit;
        }

        $pdo->beginTransaction();
        try {

            if (empty($x_000b)) {
                $stmt = $pdo->prepare("UPDATE students SET assigned_bus_id = NULL WHERE assigned_bus_id = ?");
                $stmt->execute([$x_0009]);
            } else {
                $x_000c = implode(',', array_fill(0, count($x_000b), '?'));
                $stmt = $pdo->prepare("UPDATE students SET assigned_bus_id = NULL WHERE assigned_bus_id = ? AND id NOT IN ($x_000c)");
                $params = array_merge([$x_0009], $x_000b);
                $stmt->execute($params);

                $x_000d = $pdo->prepare("UPDATE students SET assigned_bus_id = ? WHERE id IN ($x_000c)");
                $x_000e = array_merge([$x_0009], $x_000b);
                $x_000d->execute($x_000e);
            }
            $pdo->commit();
            log_audit($pdo, $x_0001['id'], 'ASSIGN_BUS_STUDENTS', "Assigned students to bus ID: $x_0009");
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
