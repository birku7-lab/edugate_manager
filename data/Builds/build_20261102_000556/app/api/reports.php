<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

$x_0001 = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($x_0001 === 'report_data') {
        $x_0002 = $_GET['type'] ?? 'complete';
        $x_0003 = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_0004 = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_0005 = $_GET['class'] ?? '';
        
        try {
            if ($x_0002 === 'present') {
                $x_0006 = "SELECT s.admission_number, s.full_name, s.class, a.arrival_time, 'Present' as status, a.attendance_date
                          FROM students s
                          JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                          WHERE 1=1";
                $x_0007 = [$x_0003, $x_0004];
                if (!empty($x_0005)) {
                    $x_0006 .= " AND s.class = ?";
                    $x_0007[] = $x_0005;
                }
                $x_0006 .= " ORDER BY a.attendance_date DESC, a.arrival_time ASC";
                
                $x_0008 = $x_0009->prepare($x_0006);
                $x_0008->execute($x_0007);
                echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
                
            } else if ($x_0002 === 'absent') {

                if ($x_0003 === $x_0004) {
                    $x_0006 = "SELECT s.admission_number, s.full_name, s.class, '-' as arrival_time, 'Absent' as status, ? as attendance_date
                              FROM students s
                              WHERE s.id NOT IN (SELECT student_id FROM attendance WHERE attendance_date = ?)
                              AND 1=1";
                    $x_0007 = [$x_0003, $x_0003];
                    if (!empty($x_0005)) {
                        $x_0006 .= " AND s.class = ?";
                        $x_0007[] = $x_0005;
                    }
                    $x_0006 .= " ORDER BY s.full_name ASC";
                    $x_0008 = $x_0009->prepare($x_0006);
                    $x_0008->execute($x_0007);
                    echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
                } else {

                    $x_0006 = "SELECT s.admission_number, s.full_name, s.class, '-' as arrival_time, 'Absent' as status, 'Range' as attendance_date
                              FROM students s
                              WHERE s.id NOT IN (SELECT student_id FROM attendance WHERE attendance_date BETWEEN ? AND ?)
                              AND 1=1";
                    $x_0007 = [$x_0003, $x_0004];
                    if (!empty($x_0005)) {
                        $x_0006 .= " AND s.class = ?";
                        $x_0007[] = $x_0005;
                    }
                    $x_0006 .= " ORDER BY s.full_name ASC";
                    $x_0008 = $x_0009->prepare($x_0006);
                    $x_0008->execute($x_0007);
                    echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
                }
                
            } else if ($x_0002 === 'complete' || $x_0002 === 'class') {

                $x_0006 = "SELECT s.admission_number, s.full_name, s.class, 
                                 COALESCE(a.arrival_time, '-') as arrival_time,
                                 IF(a.id IS NULL, 'Absent', 'Present') as status,
                                 COALESCE(a.attendance_date, ?) as attendance_date
                          FROM students s
                          LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                          WHERE 1=1";
                $x_0007 = [$x_0003, $x_0003, $x_0004];
                if (!empty($x_0005)) {
                    $x_0006 .= " AND s.class = ?";
                    $x_0007[] = $x_0005;
                }
                $x_0006 .= " ORDER BY a.attendance_date DESC, s.class ASC, s.full_name ASC";
                
                $x_0008 = $x_0009->prepare($x_0006);
                $x_0008->execute($x_0007);
                echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
            }
            
        } catch (PDOException $e) {
            error_log("Database error in reports.php: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong."]);
        }
    } else if ($x_0001 === 'meals') {
        $x_0003 = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_0004 = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_000a = $_GET['meal_type'] ?? '';
        try {
            $x_0006 = "SELECT s.admission_number, s.full_name, s.class, m.meal_type, m.meal_date, u.full_name as scanned_by_name
                      FROM meal_logs m
                      JOIN students s ON m.student_id = s.id
                      LEFT JOIN users u ON m.scanned_by = u.id
                      WHERE m.meal_date BETWEEN ? AND ?";
            $x_0007 = [$x_0003, $x_0004];
            if (!empty($x_000a)) {
                $x_0006 .= " AND m.meal_type = ?";
                $x_0007[] = $x_000a;
            }
            $x_0006 .= " ORDER BY m.meal_date DESC, m.created_at ASC";
            $x_0008 = $x_0009->prepare($x_0006);
            $x_0008->execute($x_0007);
            echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    } else if ($x_0001 === 'transport') {
        $x_0003 = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_0004 = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_000b = $_GET['bus_id'] ?? '';
        try {
            $x_0006 = "SELECT s.admission_number, s.full_name, b.name as bus_name, t.board_date as log_date, t.board_time as log_time, u.full_name as scanned_by_name
                      FROM transport_logs t
                      JOIN students s ON t.student_id = s.id
                      JOIN buses b ON t.bus_id = b.id
                      LEFT JOIN users u ON t.scanned_by = u.id
                      WHERE t.board_date BETWEEN ? AND ?";
            $x_0007 = [$x_0003, $x_0004];
            if (!empty($x_000b)) {
                $x_0006 .= " AND t.bus_id = ?";
                $x_0007[] = $x_000b;
            }
            $x_0006 .= " ORDER BY t.board_date DESC, t.board_time ASC";
            $x_0008 = $x_0009->prepare($x_0006);
            $x_0008->execute($x_0007);
            echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    } else if ($x_0001 === 'custom') {
        $x_0003 = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_0004 = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $x_000c = $_GET['cat_id'] ?? '';
        
        if (empty($x_000c)) {
            echo json_encode(["status" => "error", "message" => "Category ID is required."]);
            exit;
        }

        try {
            $x_0006 = "SELECT s.admission_number, s.full_name, s.class, c.scan_date, c.scan_time, u.full_name as scanned_by_name
                      FROM custom_scanner_logs c
                      JOIN students s ON c.student_id = s.id
                      LEFT JOIN users u ON c.scanned_by = u.id
                      WHERE c.scan_date BETWEEN ? AND ? AND c.category_id = ?
                      ORDER BY c.scan_date DESC, c.scan_time ASC";
            $x_0008 = $x_0009->prepare($x_0006);
            $x_0008->execute([$x_0003, $x_0004, $x_000c]);
            echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    } else if ($x_0001 === 'students') {

        try {
            $x_0006 = "SELECT admission_number, full_name, class, gender, parent_phone FROM students ORDER BY class ASC, full_name ASC";
            $x_0008 = $x_0009->query($x_0006);
            echo json_encode(["status" => "success", "data" => $x_0008->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
}
?>
