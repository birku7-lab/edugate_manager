<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'config.php';
require_once 'sms.php';

$x_0001 = (php_sapi_name() === 'cli');

try {

    $x_0002 = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT * FROM scheduled_sms WHERE status = 'Pending' AND scheduled_time <= ? LIMIT 5");
    $stmt->execute([$x_0002]);
    $x_0003 = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($x_0003) === 0) {
        if (!$x_0001) {
            echo json_encode(['status' => 'success', 'message' => 'No pending scheduled SMS at this time.']);
        }
        exit;
    }

    $x_0004 = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_api_username', 'sms_sender_id', 'school_name')");
    $x_0005 = [];
    while ($row = $x_0004->fetch()) {
        $x_0005[$row['setting_key']] = $row['setting_value'];
    }
    $x_0006 = $x_0005['sms_api_token'] ?? '';
    $x_0007 = $x_0005['sms_sender_id'] ?? '';
    $x_0008 = $x_0005['school_name'] ?? 'School System';

    if (empty($x_0006)) {
        if (!$x_0001) echo json_encode(['status' => 'error', 'message' => 'SMS API Token not configured.']);
        exit;
    }

    $x_0009 = 0;

    foreach ($x_0003 as $x_000a) {

        $pdo->prepare("UPDATE scheduled_sms SET status = 'Processing' WHERE id = ?")->execute([$x_000a['id']]);
        
        $x_000b = $x_000a['target_filter'];
        $x_000c = $x_000a['message_content'];
        
        $q = getRecipientQuery($pdo, $x_000b); // using the helper in sms.php
        $x_000d = $pdo->prepare($q['sql']);
        $x_000d->execute($q['params']);
        $x_000e = $x_000d->fetchAll(PDO::FETCH_ASSOC);

        $x_000f = 0;
        $x_0010 = 0;

        foreach ($x_000e as $x_0011) {
            $msg = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
                [$x_0011['full_name'], $x_0011['parent_name'], $x_0011['admission_number'], $x_0011['class'], $x_0008, date('h:i A')],
                $x_000c
            );
            $x_0012 = $x_0011['parent_phone'];
            $res = sendBulkSMS([$x_0012], $msg, $x_0007, $x_0006);
            $x_0013 = (isset($res['success']) && $res['success'] === true) ? 'Sent' : 'Failed';
            if ($x_0013 === 'Sent') $x_000f++; else $x_0010++;
            
            $x_0014 = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
            $x_0014->execute([
                $x_0011['id'] ?? null, $x_0012, $msg, $x_0013,
                json_encode($res), $res['error'] ?? ''
            ]);
        }

        $pdo->prepare("UPDATE scheduled_sms SET status = 'Completed' WHERE id = ?")->execute([$x_000a['id']]);
        log_audit($pdo, $x_000a['created_by'], 'SCHEDULED_SMS_RUN', "Processed scheduled SMS ID: {$x_000a['id']}. Sent: $x_000f.");
        $x_0009++;
    }

    if (!$x_0001) {
        echo json_encode(['status' => 'success', 'message' => "Processed $x_0009 scheduled SMS batches."]);
    }
} catch (Exception $e) {
    if (!$x_0001) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>
