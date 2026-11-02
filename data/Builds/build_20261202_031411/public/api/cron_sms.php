<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
require_once 'config.php';
require_once 'sms.php';

$isCLI = (php_sapi_name() === 'cli');

try {

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT * FROM scheduled_sms WHERE status = 'Pending' AND scheduled_time <= ? LIMIT 5");
    $stmt->execute([$now]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($jobs) === 0) {
        if (!$isCLI) {
            echo json_encode(['status' => 'success', 'message' => 'No pending scheduled SMS at this time.']);
        }
        exit;
    }

    $setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_api_username', 'sms_sender_id', 'school_name')");
    $settings = [];
    while ($row = $setStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $apiKey = $settings['sms_api_token'] ?? '';
    $senderId = $settings['sms_sender_id'] ?? '';
    $schoolName = $settings['school_name'] ?? 'School System';

    if (empty($apiKey)) {
        if (!$isCLI) echo json_encode(['status' => 'error', 'message' => 'SMS API Token not configured.']);
        exit;
    }

    $processed = 0;

    foreach ($jobs as $job) {

        $pdo->prepare("UPDATE scheduled_sms SET status = 'Processing' WHERE id = ?")->execute([$job['id']]);
        
        $filters = $job['target_filter'];
        $messageTemplate = $job['message_content'];
        
        $q = getRecipientQuery($pdo, $filters); // using the helper in sms.php
        $rStmt = $pdo->prepare($q['sql']);
        $rStmt->execute($q['params']);
        $recipients = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        $successCount = 0;
        $failCount = 0;

        foreach ($recipients as $rec) {
            $msg = str_replace(
                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
                [$rec['full_name'], $rec['parent_name'], $rec['admission_number'], $rec['class'], $schoolName, date('h:i A')],
                $messageTemplate
            );
            $phone = $rec['parent_phone'];
            $res = sendBulkSMS([$phone], $msg, $senderId, $apiKey);
            $smsStatus = (isset($res['success']) && $res['success'] === true) ? 'Sent' : 'Failed';
            if ($smsStatus === 'Sent') $successCount++; else $failCount++;
            
            $logStmt = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
            $logStmt->execute([
                $rec['id'] ?? null, $phone, $msg, $smsStatus,
                json_encode($res), $res['error'] ?? ''
            ]);
        }

        $pdo->prepare("UPDATE scheduled_sms SET status = 'Completed' WHERE id = ?")->execute([$job['id']]);
        log_audit($pdo, $job['created_by'], 'SCHEDULED_SMS_RUN', "Processed scheduled SMS ID: {$job['id']}. Sent: $successCount.");
        $processed++;
    }

    if (!$isCLI) {
        echo json_encode(['status' => 'success', 'message' => "Processed $processed scheduled SMS batches."]);
    }
} catch (Exception $e) {
    if (!$isCLI) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>
