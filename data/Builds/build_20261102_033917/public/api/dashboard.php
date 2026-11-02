<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

try {
    $x_0001 = date('Y-m-d');

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM students");
    $x_0002 = $stmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as present FROM attendance WHERE attendance_date = ?");
    $stmt->execute([$x_0001]);
    $x_0003 = $stmt->fetch()['present'];

    $x_0004 = $x_0002 - $x_0003;

    $x_0005 = $x_0002 > 0 ? round(($x_0003 / $x_0002) * 100, 1) : 0;

    $x_0006 = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as meals_served FROM meal_logs WHERE meal_date = ?");
        $stmt->execute([$x_0001]);
        $x_0006 = $stmt->fetch()['meals_served'];
    } catch (PDOException $e) {} // Table might not exist yet if not migrated

    $x_0007 = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as boarded FROM transport_logs WHERE log_date = ? AND log_type = 'Boarding'");
        $stmt->execute([$x_0001]);
        $x_0007 = $stmt->fetch()['boarded'];
    } catch (PDOException $e) {} // Table might not exist yet if not migrated

    $stmt = $pdo->prepare("
        SELECT a.arrival_time, a.attendance_status, s.full_name, s.admission_number, s.class, s.photo 
        FROM attendance a 
        JOIN students s ON a.student_id = s.id 
        WHERE a.attendance_date = ? 
        ORDER BY a.arrival_time DESC 
        LIMIT 10
    ");
    $stmt->execute([$x_0001]);
    $x_0008 = $stmt->fetchAll();

    $x_0009 = [];
    $x_000a = date('Y-m-d', strtotime('monday this week'));
    for ($i = 0; $i < 6; $i++) {
        $x_000b = date('Y-m-d', strtotime("$x_000a +$i days"));
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as present FROM attendance WHERE attendance_date = ?");
        $stmt->execute([$x_000b]);
        $x_000c = $stmt->fetch()['present'];
        $x_000d = $x_0002 > 0 ? round(($x_000c / $x_0002) * 100, 1) : 0;
        $x_0009[] = $x_000d;
    }

    $stmt = $pdo->query("SELECT class, COUNT(*) as count FROM students GROUP BY class ORDER BY count DESC");
    $x_000e = $stmt->fetchAll();
    $x_000f = [];
    foreach ($x_000e as $row) {
        $x_000f[] = [
            'class' => $row['class'],
            'count' => (int)$row['count']
        ];
    }
    
    echo json_encode([
        "status" => "success",
        "data" => [
            "total_students"     => $x_0002,
            "present_today"      => $x_0003,
            "absent_today"       => $x_0004,
            "attendance_percentage" => $x_0005,
            "recent_scans"       => $x_0008,
            "weekly_data"        => $x_0009,
            "class_distribution" => $x_000f,
            "meals_today"        => $x_0006,
            "transport_today"    => $x_0007
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
