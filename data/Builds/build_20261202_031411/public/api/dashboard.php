<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

try {
    $date = date('Y-m-d');

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM students");
    $total_students = $stmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as present FROM attendance WHERE attendance_date = ?");
    $stmt->execute([$date]);
    $present_today = $stmt->fetch()['present'];

    $absent_today = $total_students - $present_today;

    $percentage = $total_students > 0 ? round(($present_today / $total_students) * 100, 1) : 0;

    $meals_today = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as meals_served FROM meal_logs WHERE meal_date = ?");
        $stmt->execute([$date]);
        $meals_today = $stmt->fetch()['meals_served'];
    } catch (PDOException $e) {} // Table might not exist yet if not migrated

    $transport_today = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as boarded FROM transport_logs WHERE log_date = ? AND log_type = 'Boarding'");
        $stmt->execute([$date]);
        $transport_today = $stmt->fetch()['boarded'];
    } catch (PDOException $e) {} // Table might not exist yet if not migrated

    $stmt = $pdo->prepare("
        SELECT a.arrival_time, a.attendance_status, s.full_name, s.admission_number, s.class, s.photo 
        FROM attendance a 
        JOIN students s ON a.student_id = s.id 
        WHERE a.attendance_date = ? 
        ORDER BY a.arrival_time DESC 
        LIMIT 10
    ");
    $stmt->execute([$date]);
    $recent_scans = $stmt->fetchAll();

    $weekly_data = [];
    $start_of_week = date('Y-m-d', strtotime('monday this week'));
    for ($i = 0; $i < 6; $i++) {
        $day_date = date('Y-m-d', strtotime("$start_of_week +$i days"));
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as present FROM attendance WHERE attendance_date = ?");
        $stmt->execute([$day_date]);
        $day_present = $stmt->fetch()['present'];
        $day_percentage = $total_students > 0 ? round(($day_present / $total_students) * 100, 1) : 0;
        $weekly_data[] = $day_percentage;
    }

    $stmt = $pdo->query("SELECT class, COUNT(*) as count FROM students GROUP BY class ORDER BY count DESC");
    $class_rows = $stmt->fetchAll();
    $class_distribution = [];
    foreach ($class_rows as $row) {
        $class_distribution[] = [
            'class' => $row['class'],
            'count' => (int)$row['count']
        ];
    }
    
    echo json_encode([
        "status" => "success",
        "data" => [
            "total_students"     => $total_students,
            "present_today"      => $present_today,
            "absent_today"       => $absent_today,
            "attendance_percentage" => $percentage,
            "recent_scans"       => $recent_scans,
            "weekly_data"        => $weekly_data,
            "class_distribution" => $class_distribution,
            "meals_today"        => $meals_today,
            "transport_today"    => $transport_today
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
