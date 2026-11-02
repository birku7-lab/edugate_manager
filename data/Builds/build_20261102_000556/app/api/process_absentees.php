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

    $x_0003 = $x_0004->prepare("
        SELECT s.id, s.full_name, s.parent_phone, s.parent_name, s.admission_number, s.class, 
               COALESCE(b.amount_due, 0) as amount_due, 
               COALESCE(b.amount_paid, 0) as amount_paid
        FROM students s
        LEFT JOIN student_billing b ON s.id = b.student_id
        WHERE s.id NOT IN (
            SELECT student_id FROM attendance WHERE attendance_date = ?
        )
    ");
    $x_0003->execute([$x_0001]);
    $x_0005 = $x_0003->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($x_0005) === 0) {
        echo json_encode(['status' => 'success', 'message' => 'All students have been processed for today. No missing attendance records found.']);
        exit;
    }

    $x_0006 = $x_0004->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_attendance', 'sms_template_attendance')");
    $x_0007 = [];
    while ($x_0008 = $x_0006->fetch()) {
        $x_0007[$x_0008['setting_key']] = $x_0008['setting_value'];
    }
    
    $x_0009 = $x_0007['sms_api_token'] ?? '';
    $x_000a = $x_0007['sms_sender_id'] ?? '';
    $x_000b = $x_0007['school_name'] ?? 'School Admin';
    $x_000c = ($x_0007['sms_enable_attendance'] ?? 'true') === 'true';
    $x_000d = $x_0007['sms_template_attendance'] ?? '{Student Name} was marked absent today at {School Name}...';
    
    $x_000e = 0;
    $x_000f = 0;
    
    foreach ($x_0005 as $x_0010) {

        $x_0011 = $x_0004->prepare("INSERT INTO attendance (student_id, attendance_date, arrival_time, attendance_status) VALUES (?, ?, ?, 'Absent')");
        $x_0011->execute([$x_0010['id'], $x_0001, $x_0002]);
        $x_000e++;

        $x_0012 = false;
        if ($x_0010['amount_due'] > 0) {
            $x_0013 = $x_0010['amount_paid'] / $x_0010['amount_due'];
            if ($x_0013 >= 0.8) {
                $x_0012 = true;
            }
        } else {

            $x_0012 = true;
        }

        if ($x_0012 && $x_000c && !empty($x_0010['parent_phone']) && !empty($x_0009)) {
            $x_0014 = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
                [$x_0010['full_name'], $x_0010['parent_name'], $x_0010['admission_number'], $x_0010['class'], $x_000b, date('h:i A')],
                $x_000d
            );
            
            $x_0015 = sendBulkSMS([$x_0010['parent_phone']], $x_0014, $x_000a, $x_0009);
            $x_0016 = (isset($x_0015['success']) && $x_0015['success'] === true) ? 'Sent' : 'Failed';
            
            if ($x_0016 === 'Sent') {
                $x_000f++;
            }
            
            $x_0017 = $x_0004->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
            $x_0017->execute([
                $x_0010['id'], $x_0010['parent_phone'], $x_0014, $x_0016, json_encode($x_0015), $x_0015['error'] ?? ''
            ]);
        }
    }
    
    log_audit($x_0004, $x_0018['id'] ?? null, 'MARK_ABSENTEES', "Processed $x_000e absentees. Sent $x_000f SMS.");
    
    echo json_encode([
        'status' => 'success', 
        'message' => "Successfully marked $x_000e students as absent. Sent $x_000f SMS to eligible parents."
    ]);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
