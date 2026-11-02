<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

try {
    $x_0001 = date('Y-m-d');

    $x_0002 = $x_0003->query("SELECT COUNT(*) as total FROM students");
    $x_0004 = $x_0002->fetch()['total'];

    $x_0002 = $x_0003->prepare("SELECT COUNT(DISTINCT student_id) as present FROM attendance WHERE attendance_date = ?");
    $x_0002->execute([$x_0001]);
    $x_0005 = $x_0002->fetch()['present'];

    $x_0006 = $x_0004 - $x_0005;

    $x_0007 = $x_0004 > 0 ? round(($x_0005 / $x_0004) * 100, 1) : 0;

    $x_0008 = 0;
    try {
        $x_0002 = $x_0003->prepare("SELECT COUNT(DISTINCT student_id) as meals_served FROM meal_logs WHERE meal_date = ?");
        $x_0002->execute([$x_0001]);
        $x_0008 = $x_0002->fetch()['meals_served'];
    } catch (PDOException $e) {} // Table might not exist yet if not migrated

    $x_0009 = 0;
    try {
        $x_0002 = $x_0003->prepare("SELECT COUNT(DISTINCT student_id) as boarded FROM transport_logs WHERE log_date = ? AND log_type = 'Boarding'");
        $x_0002->execute([$x_0001]);
        $x_0009 = $x_0002->fetch()['boarded'];
    } catch (PDOException $e) {} // Table might not exist yet if not migrated

    $x_0002 = $x_0003->prepare("
        SELECT a.arrival_time, a.attendance_status, s.full_name, s.admission_number, s.class, s.photo 
        FROM attendance a 
        JOIN students s ON a.student_id = s.id 
        WHERE a.attendance_date = ? 
        ORDER BY a.arrival_time DESC 
        LIMIT 10
    ");
    $x_0002->execute([$x_0001]);
    $x_000a = $x_0002->fetchAll();

    $x_000b = [];
    $x_000c = date('Y-m-d', strtotime('monday this week'));
    for ($i = 0; $i < 6; $i++) {
        $x_000d = date('Y-m-d', strtotime("$x_000c +$i days"));
        $x_0002 = $x_0003->prepare("SELECT COUNT(DISTINCT student_id) as present FROM attendance WHERE attendance_date = ?");
        $x_0002->execute([$x_000d]);
        $x_000e = $x_0002->fetch()['present'];
        $x_000f = $x_0004 > 0 ? round(($x_000e / $x_0004) * 100, 1) : 0;
        $x_000b[] = $x_000f;
    }

    $x_0002 = $x_0003->query("SELECT class, COUNT(*) as count FROM students GROUP BY class ORDER BY count DESC");
    $x_0010 = $x_0002->fetchAll();
    $x_0011 = [];
    foreach ($x_0010 as $x_0012) {
        $x_0011[] = [
            'class' => $x_0012['class'],
            'count' => (int)$x_0012['count']
        ];
    }
    
    echo json_encode([
        "status" => "success",
        "data" => [
            "total_students"     => $x_0004,
            "present_today"      => $x_0005,
            "absent_today"       => $x_0006,
            "attendance_percentage" => $x_0007,
            "recent_scans"       => $x_000a,
            "weekly_data"        => $x_000b,
            "class_distribution" => $x_0011,
            "meals_today"        => $x_0008,
            "transport_today"    => $x_0009
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
