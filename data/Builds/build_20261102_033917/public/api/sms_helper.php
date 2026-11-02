<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'sms.php';

function sendPaymentSMS($pdo, $x_0001, $x_0002, $x_0003, $x_0004) {
    if (empty($x_0001['parent_phone'])) return;

    $x_0005 = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_billing', 'sms_template_payment')");
    $x_0006 = [];
    while ($s = $x_0005->fetch()) {
        $x_0006[$s['setting_key']] = $s['setting_value'];
    }

    $x_0007 = $x_0006['sms_api_token'] ?? '';
    $x_0008 = $x_0006['sms_sender_id'] ?? '';
    $x_0009 = $x_0006['school_name'] ?? 'School System';

    $x_000a = $x_0006['sms_enable_billing'] ?? '1';
    if (!empty($x_0007) && ($x_000a === 'true' || $x_000a === '1')) {
        $x_000b = !empty($x_0009) ? $x_0009 : "School Admin";
        
        $msg = "Hello, thanks for the payment of Ksh " . number_format($x_0002, 2) . " for " . $x_0001['full_name'] . " (" . $x_0001['class'] . "). The account is now " . strtolower($x_0004) . ". We appreciate your support. - " . $x_000b;
        
        if (!empty($x_0006['sms_template_payment'])) {
            $msg = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Amount Paid}', '{Balance}', '{Payment Date}', '{TIME}'],
                [$x_0001['full_name'], $x_0001['parent_name'] ?? '', $x_0001['admission_number'] ?? '', $x_0001['class'], $x_000b, number_format($x_0002, 2), number_format($x_0003, 2), date('Y-m-d'), date('h:i A')],
                $x_0006['sms_template_payment']
            );
        }

        $x_000c = sendBulkSMS([$x_0001['parent_phone']], $msg, $x_0008, $x_0007);
        
        $x_000d = (isset($x_000c['success']) && $x_000c['success'] === true) ? 'Sent' : 'Failed';
        
        $x_000e = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
        $x_000e->execute([
            $x_0001['id'],
            $x_0001['parent_phone'],
            $msg,
            $x_000d,
            json_encode($x_000c),
            $x_000c['error'] ?? ''
        ]);
    }
}
?>
