<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

if (!isset($x_0001) || $x_0001['role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

$x_0002 = $_GET['action'] ?? 'list';

try {
    if ($x_0002 === 'list') {
        $x_0003 = $x_0004->query("SELECT id, name, registration_number, driver_name, driver_phone, capacity, route, status FROM buses ORDER BY name ASC");
        $x_0005 = $x_0003->fetchAll();
        echo json_encode(['status' => 'success', 'data' => $x_0005]);
    }
    elseif ($x_0002 === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_0006 = $_POST['name'] ?? '';
        $x_0007 = $_POST['registration_number'] ?? '';
        $x_0008 = $_POST['driver_name'] ?? '';
        $x_0009 = $_POST['driver_phone'] ?? '';
        $x_000a = (int)($_POST['capacity'] ?? 0);
        $x_000b = $_POST['route'] ?? '';
        $x_000c = $_POST['status'] ?? 'Active';

        if (!$x_0006 || !$x_0007) {
            echo json_encode(['status' => 'error', 'message' => 'Name and Registration Number are required.']);
            exit;
        }

        $x_000d = $x_0004->prepare("SELECT id FROM buses WHERE registration_number = ?");
        $x_000d->execute([$x_0007]);
        if($x_000d->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Registration Number already exists.']);
            exit;
        }

        $x_0003 = $x_0004->prepare("INSERT INTO buses (name, registration_number, driver_name, driver_phone, capacity, route, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $x_0003->execute([$x_0006, $x_0007, $x_0008, $x_0009, $x_000a, $x_000b, $x_000c, $x_0001['id']]);
        
        log_audit($x_0004, $x_0001['id'], 'ADD_BUS', "Added bus: $x_0007");
        echo json_encode(['status' => 'success', 'message' => 'Bus added successfully']);
    }
    elseif ($x_0002 === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $x_0006 = $_POST['name'] ?? '';
        $x_0007 = $_POST['registration_number'] ?? '';
        $x_0008 = $_POST['driver_name'] ?? '';
        $x_0009 = $_POST['driver_phone'] ?? '';
        $x_000a = (int)($_POST['capacity'] ?? 0);
        $x_000b = $_POST['route'] ?? '';
        $x_000c = $_POST['status'] ?? 'Active';

        if (!$id || !$x_0006 || !$x_0007) {
            echo json_encode(['status' => 'error', 'message' => 'ID, Name, and Registration Number are required.']);
            exit;
        }

        $x_000d = $x_0004->prepare("SELECT id FROM buses WHERE registration_number = ? AND id != ?");
        $x_000d->execute([$x_0007, $id]);
        if($x_000d->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Registration Number already exists.']);
            exit;
        }

        $x_0003 = $x_0004->prepare("UPDATE buses SET name=?, registration_number=?, driver_name=?, driver_phone=?, capacity=?, route=?, status=? WHERE id=?");
        $x_0003->execute([$x_0006, $x_0007, $x_0008, $x_0009, $x_000a, $x_000b, $x_000c, $id]);
        
        log_audit($x_0004, $x_0001['id'], 'EDIT_BUS', "Edited bus ID: $id");
        echo json_encode(['status' => 'success', 'message' => 'Bus updated successfully']);
    }
    elseif ($x_0002 === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {

            try {
                $x_0003 = $x_0004->prepare("DELETE FROM buses WHERE id = ?");
                $x_0003->execute([$id]);
                log_audit($x_0004, $x_0001['id'], 'DELETE_BUS', "Deleted bus ID: $id");
                echo json_encode(['status' => 'success', 'message' => 'Bus deleted successfully']);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete bus because it is currently assigned to students or staff. Please set it to Inactive instead.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
        }
    }
    elseif ($x_0002 === 'get_assignable_students') {
        $x_000e = (int)($_GET['bus_id'] ?? 0);
        if (!$x_000e) {
            echo json_encode(['status' => 'error', 'message' => 'Bus ID required.']);
            exit;
        }
        
        $x_000f = "SELECT s.id, s.full_name, s.admission_number, s.class, s.assigned_bus_id 
                  FROM students s
                  ORDER BY s.class ASC, s.full_name ASC";
        
        $x_0003 = $x_0004->prepare($x_000f);
        $x_0003->execute();
        $x_0010 = $x_0003->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'data' => $x_0010]);
    }
    elseif ($x_0002 === 'assign_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_000e = (int)($_POST['bus_id'] ?? 0);
        $x_0011 = json_decode($_POST['student_ids'] ?? '[]', true);
        
        if (!$x_000e) {
            echo json_encode(['status' => 'error', 'message' => 'Bus ID required.']);
            exit;
        }

        $x_0004->beginTransaction();
        try {

            if (empty($x_0011)) {
                $x_0003 = $x_0004->prepare("UPDATE students SET assigned_bus_id = NULL WHERE assigned_bus_id = ?");
                $x_0003->execute([$x_000e]);
            } else {
                $x_0012 = implode(',', array_fill(0, count($x_0011), '?'));
                $x_0003 = $x_0004->prepare("UPDATE students SET assigned_bus_id = NULL WHERE assigned_bus_id = ? AND id NOT IN ($x_0012)");
                $x_0013 = array_merge([$x_000e], $x_0011);
                $x_0003->execute($x_0013);

                $x_0014 = $x_0004->prepare("UPDATE students SET assigned_bus_id = ? WHERE id IN ($x_0012)");
                $x_0015 = array_merge([$x_000e], $x_0011);
                $x_0014->execute($x_0015);
            }
            $x_0004->commit();
            log_audit($x_0004, $x_0001['id'], 'ASSIGN_BUS_STUDENTS', "Assigned students to bus ID: $x_000e");
            echo json_encode(['status' => 'success', 'message' => 'Students assigned successfully.']);
        } catch (Exception $e) {
            $x_0004->rollBack();
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
