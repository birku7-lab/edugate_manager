<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'sms.php';

function sendPaymentSMS($x_0001, $x_0002, $x_0003, $x_0004, $x_0005) {
    if (empty($x_0002['parent_phone'])) return;

    $x_0006 = $x_0001->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_billing', 'sms_template_payment')");
    $x_0007 = [];
    while ($s = $x_0006->fetch()) {
        $x_0007[$s['setting_key']] = $s['setting_value'];
    }

    $x_0008 = $x_0007['sms_api_token'] ?? '';
    $x_0009 = $x_0007['sms_sender_id'] ?? '';
    $x_000a = $x_0007['school_name'] ?? 'School System';

    $x_000b = $x_0007['sms_enable_billing'] ?? '1';
    if (!empty($x_0008) && ($x_000b === 'true' || $x_000b === '1')) {
        $x_000c = !empty($x_000a) ? $x_000a : "School Admin";
        
        $x_000d = "Hello, thanks for the payment of Ksh " . number_format($x_0003, 2) . " for " . $x_0002['full_name'] . " (" . $x_0002['class'] . "). The account is now " . strtolower($x_0005) . ". We appreciate your support. - " . $x_000c;
        
        if (!empty($x_0007['sms_template_payment'])) {
            $x_000d = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Amount Paid}', '{Balance}', '{Payment Date}', '{TIME}'],
                [$x_0002['full_name'], $x_0002['parent_name'] ?? '', $x_0002['admission_number'] ?? '', $x_0002['class'], $x_000c, number_format($x_0003, 2), number_format($x_0004, 2), date('Y-m-d'), date('h:i A')],
                $x_0007['sms_template_payment']
            );
        }

        $x_000e = sendBulkSMS([$x_0002['parent_phone']], $x_000d, $x_0009, $x_0008);
        
        $x_000f = (isset($x_000e['success']) && $x_000e['success'] === true) ? 'Sent' : 'Failed';
        
        $x_0010 = $x_0001->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
        $x_0010->execute([
            $x_0002['id'],
            $x_0002['parent_phone'],
            $x_000d,
            $x_000f,
            json_encode($x_000e),
            $x_000e['error'] ?? ''
        ]);
    }
}
?>
