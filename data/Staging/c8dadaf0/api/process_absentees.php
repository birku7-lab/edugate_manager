<?php
/**
 * api/process_absentees.php
 * Marks students who haven't scanned today as Absent.
 * Sends an Absent SMS to those who have paid >= 80% of their fees.
 */

require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $date = date('Y-m-d');
    $time = date('H:i:s');
    
    // Get all students who do not have an attendance record today
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
    $stmt->execute([$date]);
    $absentees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($absentees) === 0) {
        echo json_encode(['status' => 'success', 'message' => 'All students have been processed for today. No missing attendance records found.']);
        exit;
    }
    
    // Get Settings
    $setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_attendance', 'sms_template_attendance')");
    $settings = [];
    while ($row = $setStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $apiKey = $settings['sms_api_token'] ?? '';
    $senderId = $settings['sms_sender_id'] ?? '';
    $schoolName = $settings['school_name'] ?? 'School Admin';
    $enableSms = ($settings['sms_enable_attendance'] ?? 'true') === 'true';
    $smsTemplate = $settings['sms_template_attendance'] ?? '{Student Name} was marked absent today at {School Name}...';
    
    $markedCount = 0;
    $smsSentCount = 0;
    
    foreach ($absentees as $student) {
        // Mark as absent
        $insert_stmt = $pdo->prepare("INSERT INTO attendance (student_id, attendance_date, arrival_time, attendance_status) VALUES (?, ?, ?, 'Absent')");
        $insert_stmt->execute([$student['id'], $date, $time]);
        $markedCount++;
        
        // Check if 80% paid
        $isEligible = false;
        if ($student['amount_due'] > 0) {
            $ratio = $student['amount_paid'] / $student['amount_due'];
            if ($ratio >= 0.8) {
                $isEligible = true;
            }
        } else {
            // If amount due is 0, they are considered fully paid
            $isEligible = true;
        }
        
        // Send SMS if eligible and enabled and parent phone exists
        if ($isEligible && $enableSms && !empty($student['parent_phone']) && !empty($apiKey)) {
            $msg = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
                [$student['full_name'], $student['parent_name'], $student['admission_number'], $student['class'], $schoolName, date('h:i A')],
                $smsTemplate
            );
            
            $smsRes = sendBulkSMS([$student['parent_phone']], $msg, $senderId, $apiKey);
            $smsStatus = (isset($smsRes['success']) && $smsRes['success'] === true) ? 'Sent' : 'Failed';
            
            if ($smsStatus === 'Sent') {
                $smsSentCount++;
            }
            
            $logStmt = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
            $logStmt->execute([
                $student['id'], $student['parent_phone'], $msg, $smsStatus, json_encode($smsRes), $smsRes['error'] ?? ''
            ]);
        }
    }
    
    log_audit($pdo, $current_user['id'] ?? null, 'MARK_ABSENTEES', "Processed $markedCount absentees. Sent $smsSentCount SMS.");
    
    echo json_encode([
        'status' => 'success', 
        'message' => "Successfully marked $markedCount students as absent. Sent $smsSentCount SMS to eligible parents."
    ]);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
