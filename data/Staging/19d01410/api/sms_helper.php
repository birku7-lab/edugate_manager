<?php
/**
 * api/sms_helper.php
 * Contains shared SMS logic for sending automated payment receipts.
 */

// Include the base SMS sending function if not already present
require_once 'sms.php';

function sendPaymentSMS($pdo, $studentData, $amountPaid, $newBalance, $paymentStatus) {
    if (empty($studentData['parent_phone'])) return;

    $setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_billing', 'sms_template_payment')");
    $sets = [];
    while ($s = $setStmt->fetch()) {
        $sets[$s['setting_key']] = $s['setting_value'];
    }

    $apiKey = $sets['sms_api_token'] ?? '';
    $senderId = $sets['sms_sender_id'] ?? '';
    $schoolName = $sets['school_name'] ?? 'School System';

    $enableBilling = $sets['sms_enable_billing'] ?? '1';
    if (!empty($apiKey) && ($enableBilling === 'true' || $enableBilling === '1')) {
        $displaySchool = !empty($schoolName) ? $schoolName : "School Admin";
        
        $msg = "Hello, thanks for the payment of Ksh " . number_format($amountPaid, 2) . " for " . $studentData['full_name'] . " (" . $studentData['class'] . "). The account is now " . strtolower($paymentStatus) . ". We appreciate your support. - " . $displaySchool;
        
        if (!empty($sets['sms_template_payment'])) {
            $msg = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Amount Paid}', '{Balance}', '{Payment Date}', '{TIME}'],
                [$studentData['full_name'], $studentData['parent_name'] ?? '', $studentData['admission_number'] ?? '', $studentData['class'], $displaySchool, number_format($amountPaid, 2), number_format($newBalance, 2), date('Y-m-d'), date('h:i A')],
                $sets['sms_template_payment']
            );
        }
        
        // This relies on sendBulkSMS defined in sms.php
        $smsRes = sendBulkSMS([$studentData['parent_phone']], $msg, $senderId, $apiKey);
        
        $smsStatus = (isset($smsRes['success']) && $smsRes['success'] === true) ? 'Sent' : 'Failed';
        
        $logStmt = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([
            $studentData['id'],
            $studentData['parent_phone'],
            $msg,
            $smsStatus,
            json_encode($smsRes),
            $smsRes['error'] ?? ''
        ]);
    }
}
?>
