<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'config.php';
require_once 'sms.php';

$x_0001 = (php_sapi_name() === 'cli');

try {

    $x_0002 = date('Y-m-d H:i:s');
    $x_0003 = $x_0004->prepare("SELECT * FROM scheduled_sms WHERE status = 'Pending' AND scheduled_time <= ? LIMIT 5");
    $x_0003->execute([$x_0002]);
    $x_0005 = $x_0003->fetchAll(PDO::FETCH_ASSOC);

    if (count($x_0005) === 0) {
        if (!$x_0001) {
            echo json_encode(['status' => 'success', 'message' => 'No pending scheduled SMS at this time.']);
        }
        exit;
    }

    $x_0006 = $x_0004->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_api_username', 'sms_sender_id', 'school_name')");
    $x_0007 = [];
    while ($x_0008 = $x_0006->fetch()) {
        $x_0007[$x_0008['setting_key']] = $x_0008['setting_value'];
    }
    $x_0009 = $x_0007['sms_api_token'] ?? '';
    $x_000a = $x_0007['sms_sender_id'] ?? '';
    $x_000b = $x_0007['school_name'] ?? 'School System';

    if (empty($x_0009)) {
        if (!$x_0001) echo json_encode(['status' => 'error', 'message' => 'SMS API Token not configured.']);
        exit;
    }

    $x_000c = 0;

    foreach ($x_0005 as $x_000d) {

        $x_0004->prepare("UPDATE scheduled_sms SET status = 'Processing' WHERE id = ?")->execute([$x_000d['id']]);
        
        $x_000e = $x_000d['target_filter'];
        $x_000f = $x_000d['message_content'];
        
        $q = getRecipientQuery($x_0004, $x_000e); // using the helper in sms.php
        $x_0010 = $x_0004->prepare($q['sql']);
        $x_0010->execute($q['params']);
        $x_0011 = $x_0010->fetchAll(PDO::FETCH_ASSOC);

        $x_0012 = 0;
        $x_0013 = 0;

        foreach ($x_0011 as $x_0014) {
            $x_0015 = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
                [$x_0014['full_name'], $x_0014['parent_name'], $x_0014['admission_number'], $x_0014['class'], $x_000b, date('h:i A')],
                $x_000f
            );
            $x_0016 = $x_0014['parent_phone'];
            $x_0017 = sendBulkSMS([$x_0016], $x_0015, $x_000a, $x_0009);
            $x_0018 = (isset($x_0017['success']) && $x_0017['success'] === true) ? 'Sent' : 'Failed';
            if ($x_0018 === 'Sent') $x_0012++; else $x_0013++;
            
            $x_0019 = $x_0004->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
            $x_0019->execute([
                $x_0014['id'] ?? null, $x_0016, $x_0015, $x_0018,
                json_encode($x_0017), $x_0017['error'] ?? ''
            ]);
        }

        $x_0004->prepare("UPDATE scheduled_sms SET status = 'Completed' WHERE id = ?")->execute([$x_000d['id']]);
        log_audit($x_0004, $x_000d['created_by'], 'SCHEDULED_SMS_RUN', "Processed scheduled SMS ID: {$x_000d['id']}. Sent: $x_0012.");
        $x_000c++;
    }

    if (!$x_0001) {
        echo json_encode(['status' => 'success', 'message' => "Processed $x_000c scheduled SMS batches."]);
    }
} catch (Exception $e) {
    if (!$x_0001) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>
