<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'report_data') {
        $type = $_GET['type'] ?? 'complete';
        $start_date = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $end_date = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $class = $_GET['class'] ?? '';
        
        try {
            if ($type === 'present') {
                $query = "SELECT s.admission_number, s.full_name, s.class, a.arrival_time, 'Present' as status, a.attendance_date
                          FROM students s
                          JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                          WHERE 1=1";
                $params = [$start_date, $end_date];
                if (!empty($class)) {
                    $query .= " AND s.class = ?";
                    $params[] = $class;
                }
                $query .= " ORDER BY a.attendance_date DESC, a.arrival_time ASC";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
                
            } else if ($type === 'absent') {
                // If it's a single date, we can accurately find absent students
                if ($start_date === $end_date) {
                    $query = "SELECT s.admission_number, s.full_name, s.class, '-' as arrival_time, 'Absent' as status, ? as attendance_date
                              FROM students s
                              WHERE s.id NOT IN (SELECT student_id FROM attendance WHERE attendance_date = ?)
                              AND 1=1";
                    $params = [$start_date, $start_date];
                    if (!empty($class)) {
                        $query .= " AND s.class = ?";
                        $params[] = $class;
                    }
                    $query .= " ORDER BY s.full_name ASC";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
                } else {
                    // Over a date range, it's complex. We will just return students who have 0 attendance in that range.
                    $query = "SELECT s.admission_number, s.full_name, s.class, '-' as arrival_time, 'Absent' as status, 'Range' as attendance_date
                              FROM students s
                              WHERE s.id NOT IN (SELECT student_id FROM attendance WHERE attendance_date BETWEEN ? AND ?)
                              AND 1=1";
                    $params = [$start_date, $end_date];
                    if (!empty($class)) {
                        $query .= " AND s.class = ?";
                        $params[] = $class;
                    }
                    $query .= " ORDER BY s.full_name ASC";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
                }
                
            } else if ($type === 'complete' || $type === 'class') {
                // Combine present and absent over the date range
                $query = "SELECT s.admission_number, s.full_name, s.class, 
                                 COALESCE(a.arrival_time, '-') as arrival_time,
                                 IF(a.id IS NULL, 'Absent', 'Present') as status,
                                 COALESCE(a.attendance_date, ?) as attendance_date
                          FROM students s
                          LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date BETWEEN ? AND ?
                          WHERE 1=1";
                $params = [$start_date, $start_date, $end_date];
                if (!empty($class)) {
                    $query .= " AND s.class = ?";
                    $params[] = $class;
                }
                $query .= " ORDER BY a.attendance_date DESC, s.class ASC, s.full_name ASC";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
            }
            
        } catch (PDOException $e) {
            error_log("Database error in reports.php: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong."]);
        }
    } else if ($action === 'meals') {
        $start_date = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $end_date = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $meal_type = $_GET['meal_type'] ?? '';
        try {
            $query = "SELECT s.admission_number, s.full_name, s.class, m.meal_type, m.meal_date, u.full_name as scanned_by_name
                      FROM meal_logs m
                      JOIN students s ON m.student_id = s.id
                      LEFT JOIN users u ON m.scanned_by = u.id
                      WHERE m.meal_date BETWEEN ? AND ?";
            $params = [$start_date, $end_date];
            if (!empty($meal_type)) {
                $query .= " AND m.meal_type = ?";
                $params[] = $meal_type;
            }
            $query .= " ORDER BY m.meal_date DESC, m.created_at ASC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    } else if ($action === 'transport') {
        $start_date = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $end_date = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $bus_id = $_GET['bus_id'] ?? '';
        try {
            $query = "SELECT s.admission_number, s.full_name, b.name as bus_name, t.board_date as log_date, t.board_time as log_time, u.full_name as scanned_by_name
                      FROM transport_logs t
                      JOIN students s ON t.student_id = s.id
                      JOIN buses b ON t.bus_id = b.id
                      LEFT JOIN users u ON t.scanned_by = u.id
                      WHERE t.board_date BETWEEN ? AND ?";
            $params = [$start_date, $end_date];
            if (!empty($bus_id)) {
                $query .= " AND t.bus_id = ?";
                $params[] = $bus_id;
            }
            $query .= " ORDER BY t.board_date DESC, t.board_time ASC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    } else if ($action === 'custom') {
        $start_date = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $end_date = $_GET['end_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
        $cat_id = $_GET['cat_id'] ?? '';
        
        if (empty($cat_id)) {
            echo json_encode(["status" => "error", "message" => "Category ID is required."]);
            exit;
        }

        try {
            $query = "SELECT s.admission_number, s.full_name, s.class, c.scan_date, c.scan_time, u.full_name as scanned_by_name
                      FROM custom_scanner_logs c
                      JOIN students s ON c.student_id = s.id
                      LEFT JOIN users u ON c.scanned_by = u.id
                      WHERE c.scan_date BETWEEN ? AND ? AND c.category_id = ?
                      ORDER BY c.scan_date DESC, c.scan_time ASC";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$start_date, $end_date, $cat_id]);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    } else if ($action === 'students') {
        // New endpoint for student demographic reports
        try {
            $query = "SELECT admission_number, full_name, class, gender, parent_phone FROM students ORDER BY class ASC, full_name ASC";
            $stmt = $pdo->query($query);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    }
}
?>
