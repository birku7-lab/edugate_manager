<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

$isDirectAccess = (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME']));

if ($isDirectAccess) {
    require_once 'auth_middleware.php';
}

$isAdmin = isset($current_user['role']) && $current_user['role'] === 'Admin';
$action = $isDirectAccess ? ($_GET['action'] ?? '') : '';

function sendBulkSMS($phones, $message, $sender_id, $api_key) {

    $formattedPhones = [];
    foreach ($phones as $p) {
        $p = preg_replace('/[^0-9\+]/', '', $p);
        if (strpos($p, '0') === 0) {
            $p = '+254' . substr($p, 1);
        } elseif (strpos($p, '254') === 0) {
            $p = '+' . $p;
        } elseif (strpos($p, '+') !== 0) {
            $p = '+' . $p; // Ensure it has a +
        }
        $formattedPhones[] = $p;
    }

    $url = "https://api.fluxsms.co.ke/sendsms";
    $payload = json_encode([
        'api_key' => $api_key,
        'phone' => implode(',', $formattedPhones),
        'message' => $message,
        'sender_id' => $sender_id
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => 'Connection error: ' . $error];
    }
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'responses' => $result
        ];
    }

    return [
        'error' => $result['message'] ?? 'Unknown error occurred',
        'http_code' => $httpCode,
        'raw' => $response
    ];
}

if ($action === 'test_sms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }

    $phone = $_POST['phone'] ?? '';
    if (empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required.']);
        exit;
    }

    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name')");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $apiKey = $settings['sms_api_token'] ?? '';
    $senderId = $settings['sms_sender_id'] ?? '';
    $schoolName = $settings['school_name'] ?? 'School System';

    if (empty($apiKey)) {
        echo json_encode(['status' => 'error', 'message' => 'SMS API Token is not configured.']);
        exit;
    }

    $message = "Test message from $schoolName. If you receive this, your SMS integration is correct!";
    $result = sendBulkSMS([$phone], $message, $senderId, $apiKey);

    if (isset($result['success']) && $result['success'] === true) {
        echo json_encode(['status' => 'success', 'message' => 'Test SMS sent successfully!', 'details' => $result]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send SMS.', 'details' => $result]);
    }
    exit;
}

if ($action === 'get_logs') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }
    
    try {
        $stmt = $pdo->query("
            SELECT l.*, s.full_name as student_name 
            FROM sms_logs l
            LEFT JOIN students s ON l.student_id = s.id
            ORDER BY l.sent_at DESC
            LIMIT 1000
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $logs]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error loading logs.']);
    }
    exit;
}

if ($action === 'delete_all_logs') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }
    
    try {
        $pdo->query("TRUNCATE TABLE sms_logs");
        log_audit($pdo, $current_user['id'] ?? null, 'DELETE_SMS_LOGS', "Admin cleared all SMS logs");
        echo json_encode(['status' => 'success', 'message' => 'All SMS logs have been deleted.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error while deleting logs.']);
    }
    exit;
}

function getRecipientQuery($pdo, $filters) {
    if (is_string($filters)) $filters = json_decode($filters, true) ?? [];
    $sql = "SELECT id, full_name, class, admission_number, parent_name, parent_phone FROM students WHERE parent_phone IS NOT NULL AND parent_phone != ''";
    $params = [];
    $where = [];
    
    if (isset($filters['type'])) {
        if ($filters['type'] === 'classes' && !empty($filters['classes'])) {
            $in = str_repeat('?,', count($filters['classes']) - 1) . '?';
            $where[] = "class IN ($in)";
            $params = array_merge($params, $filters['classes']);
        } elseif ($filters['type'] === 'students' && !empty($filters['student_ids'])) {
            $in = str_repeat('?,', count($filters['student_ids']) - 1) . '?';
            $where[] = "id IN ($in)";
            $params = array_merge($params, $filters['student_ids']);
        } elseif ($filters['type'] === 'balance_has') {
            $where[] = "id IN (SELECT student_id FROM student_billing WHERE (amount_due - amount_paid) > 0)";
        } elseif ($filters['type'] === 'balance_paid') {
            $where[] = "id IN (SELECT student_id FROM student_billing) AND id NOT IN (SELECT student_id FROM student_billing WHERE (amount_due - amount_paid) > 0)";
        } elseif ($filters['type'] === 'absent') {
            $where[] = "id IN (SELECT student_id FROM attendance WHERE attendance_date = CURDATE() AND attendance_status = 'Absent')";
        } elseif ($filters['type'] === 'transport') {
            $where[] = "assigned_bus_id IS NOT NULL AND assigned_bus_id != 0";
        } elseif ($filters['type'] === 'staff') {

            return ['sql' => "SELECT id, name as full_name, '' as class, '' as admission_number, name as parent_name, '' as parent_phone FROM users WHERE 1=0", 'params' => []];
        }
    }
    
    if (count($where) > 0) {
        $sql .= " AND " . implode(" AND ", $where);
    }
    
    return ['sql' => $sql, 'params' => $params];
}

if ($action === 'get_recipients') {
    if (!$isAdmin) exit;
    $filters = $_POST['filters'] ?? '{}';
    $q = getRecipientQuery($pdo, $filters);
    $stmt = $pdo->prepare($q['sql']);
    $stmt->execute($q['params']);
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'count' => count($recipients), 'data' => array_slice($recipients, 0, 5)]);
    exit;
}

if ($action === 'send_bulk' || $action === 'schedule_bulk') {
    if (!$isAdmin) exit;
    $filters = $_POST['filters'] ?? '{}';
    $messageTemplate = $_POST['message'] ?? '';
    
    if (empty($messageTemplate)) {
        echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']);
        exit;
    }

    if ($action === 'schedule_bulk') {
        $scheduleTime = $_POST['schedule_time'] ?? '';
        if (empty($scheduleTime)) {
             echo json_encode(['status' => 'error', 'message' => 'Schedule time is required.']);
             exit;
        }
        $scheduleTime = str_replace('T', ' ', $scheduleTime);
        if (strlen($scheduleTime) == 16) $scheduleTime .= ':00'; // Append seconds if missing
        $stmt = $pdo->prepare("INSERT INTO scheduled_sms (target_filter, message_content, scheduled_time, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$filters, $messageTemplate, $scheduleTime, $current_user['id'] ?? null]);
        log_audit($pdo, $current_user['id'] ?? null, 'SCHEDULE_SMS', "Scheduled bulk SMS for $scheduleTime.");
        echo json_encode(['status' => 'success', 'message' => 'Bulk SMS scheduled successfully.']);
        exit;
    }

    $q = getRecipientQuery($pdo, $filters);
    $stmt = $pdo->prepare($q['sql']);
    $stmt->execute($q['params']);
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($recipients) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'No recipients found for this filter.']);
        exit;
    }

    $setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name')");
    $settings = [];
    while ($row = $setStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $apiKey = $settings['sms_api_token'] ?? '';
    $senderId = $settings['sms_sender_id'] ?? '';
    $schoolName = $settings['school_name'] ?? 'School System';
    
    if (empty($apiKey)) {
        echo json_encode(['status' => 'error', 'message' => 'SMS API Token not configured.']);
        exit;
    }

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
    
    log_audit($pdo, $current_user['id'] ?? null, 'BULK_SMS', "Sent bulk SMS to $successCount recipients.");
    echo json_encode(['status' => 'success', 'message' => "Bulk SMS processed. Sent: $successCount, Failed: $failCount."]);
    exit;
}

if ($isDirectAccess) {
}
?>
