<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $x_0001 = date('Y-m-d');
    $x_0002 = date('H:i:s');

    $stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.parent_phone, s.parent_name, s.admission_number, s.class, 
               COALESCE(b.amount_due, 0) as amount_due, 
               COALESCE(b.amount_paid, 0) as amount_paid
        FROM students s
        LEFT JOIN student_billing b ON s.id = b.student_id
        WHERE s.id NOT IN (
            SELECT student_id FROM attendance WHERE attendance_date = ?
        )
    ");
    $stmt->execute([$x_0001]);
    $x_0003 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($x_0003) === 0) {
        echo json_encode(['status' => 'success', 'message' => 'All students have been processed for today. No missing attendance records found.']);
        exit;
    }

    $x_0004 = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_attendance', 'sms_template_attendance')");
    $x_0005 = [];
    while ($row = $x_0004->fetch()) {
        $x_0005[$row['setting_key']] = $row['setting_value'];
    }
    
    $x_0006 = $x_0005['sms_api_token'] ?? '';
    $x_0007 = $x_0005['sms_sender_id'] ?? '';
    $x_0008 = $x_0005['school_name'] ?? 'School Admin';
    $x_0009 = ($x_0005['sms_enable_attendance'] ?? 'true') === 'true';
    $x_000a = $x_0005['sms_template_attendance'] ?? '{Student Name} was marked absent today at {School Name}...';
    
    $x_000b = 0;
    $x_000c = 0;
    
    foreach ($x_0003 as $x_000d) {

        $x_000e = $pdo->prepare("INSERT INTO attendance (student_id, attendance_date, arrival_time, attendance_status) VALUES (?, ?, ?, 'Absent')");
        $x_000e->execute([$x_000d['id'], $x_0001, $x_0002]);
        $x_000b++;

        $x_000f = false;
        if ($x_000d['amount_due'] > 0) {
            $x_0010 = $x_000d['amount_paid'] / $x_000d['amount_due'];
            if ($x_0010 >= 0.8) {
                $x_000f = true;
            }
        } else {

            $x_000f = true;
        }

        if ($x_000f && $x_0009 && !empty($x_000d['parent_phone']) && !empty($x_0006)) {
            $msg = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
                [$x_000d['full_name'], $x_000d['parent_name'], $x_000d['admission_number'], $x_000d['class'], $x_0008, date('h:i A')],
                $x_000a
            );
            
            $x_0011 = sendBulkSMS([$x_000d['parent_phone']], $msg, $x_0007, $x_0006);
            $x_0012 = (isset($x_0011['success']) && $x_0011['success'] === true) ? 'Sent' : 'Failed';
            
            if ($x_0012 === 'Sent') {
                $x_000c++;
            }
            
            $x_0013 = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
            $x_0013->execute([
                $x_000d['id'], $x_000d['parent_phone'], $msg, $x_0012, json_encode($x_0011), $x_0011['error'] ?? ''
            ]);
        }
    }
    
    log_audit($pdo, $x_0014['id'] ?? null, 'MARK_ABSENTEES', "Processed $x_000b absentees. Sent $x_000c SMS.");
    
    echo json_encode([
        'status' => 'success', 
        'message' => "Successfully marked $x_000b students as absent. Sent $x_000c SMS to eligible parents."
    ]);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
