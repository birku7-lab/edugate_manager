<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

$x_0001 = (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME']));

if ($x_0001) {
    require_once 'auth_middleware.php';
}

$x_0002 = isset($x_0003['role']) && $x_0003['role'] === 'Admin';
$action = $x_0001 ? ($_GET['action'] ?? '') : '';

function sendBulkSMS($x_0004, $message, $x_0005, $x_0006) {

    $x_0007 = [];
    foreach ($x_0004 as $p) {
        $p = preg_replace('/[^0-9\+]/', '', $p);
        if (strpos($p, '0') === 0) {
            $p = '+254' . substr($p, 1);
        } elseif (strpos($p, '254') === 0) {
            $p = '+' . $p;
        } elseif (strpos($p, '+') !== 0) {
            $p = '+' . $p; // Ensure it has a +
        }
        $x_0007[] = $p;
    }

    $x_0008 = "https://api.fluxsms.co.ke/sendsms";
    $x_0009 = json_encode([
        'api_key' => $x_0006,
        'phone' => implode(',', $x_0007),
        'message' => $message,
        'sender_id' => $x_0005
    ]);

    $ch = curl_init($x_0008);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $x_0009);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $x_000a = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $x_000b = curl_error($ch);
        curl_close($ch);
        return ['error' => 'Connection error: ' . $x_000b];
    }
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($x_000a >= 200 && $x_000a < 300) {
        return [
            'success' => true,
            'responses' => $result
        ];
    }

    return [
        'error' => $result['message'] ?? 'Unknown error occurred',
        'http_code' => $x_000a,
        'raw' => $response
    ];
}

if ($action === 'test_sms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$x_0002) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }

    $x_000c = $_POST['phone'] ?? '';
    if (empty($x_000c)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required.']);
        exit;
    }

    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name')");
    $x_000d = [];
    while ($row = $stmt->fetch()) {
        $x_000d[$row['setting_key']] = $row['setting_value'];
    }

    $x_000e = $x_000d['sms_api_token'] ?? '';
    $x_000f = $x_000d['sms_sender_id'] ?? '';
    $x_0010 = $x_000d['school_name'] ?? 'School System';

    if (empty($x_000e)) {
        echo json_encode(['status' => 'error', 'message' => 'SMS API Token is not configured.']);
        exit;
    }

    $message = "Test message from $x_0010. If you receive this, your SMS integration is correct!";
    $result = sendBulkSMS([$x_000c], $message, $x_000f, $x_000e);

    if (isset($result['success']) && $result['success'] === true) {
        echo json_encode(['status' => 'success', 'message' => 'Test SMS sent successfully!', 'details' => $result]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send SMS.', 'details' => $result]);
    }
    exit;
}

if ($action === 'get_logs') {
    if (!$x_0002) {
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
        $x_0011 = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $x_0011]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error loading logs.']);
    }
    exit;
}

if ($action === 'delete_all_logs') {
    if (!$x_0002) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }
    
    try {
        $pdo->query("TRUNCATE TABLE sms_logs");
        log_audit($pdo, $x_0003['id'] ?? null, 'DELETE_SMS_LOGS', "Admin cleared all SMS logs");
        echo json_encode(['status' => 'success', 'message' => 'All SMS logs have been deleted.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error while deleting logs.']);
    }
    exit;
}

function getRecipientQuery($pdo, $x_0012) {
    if (is_string($x_0012)) $x_0012 = json_decode($x_0012, true) ?? [];
    $sql = "SELECT id, full_name, class, admission_number, parent_name, parent_phone FROM students WHERE parent_phone IS NOT NULL AND parent_phone != ''";
    $params = [];
    $x_0013 = [];
    
    if (isset($x_0012['type'])) {
        if ($x_0012['type'] === 'classes' && !empty($x_0012['classes'])) {
            $in = str_repeat('?,', count($x_0012['classes']) - 1) . '?';
            $x_0013[] = "class IN ($in)";
            $params = array_merge($params, $x_0012['classes']);
        } elseif ($x_0012['type'] === 'students' && !empty($x_0012['student_ids'])) {
            $in = str_repeat('?,', count($x_0012['student_ids']) - 1) . '?';
            $x_0013[] = "id IN ($in)";
            $params = array_merge($params, $x_0012['student_ids']);
        } elseif ($x_0012['type'] === 'balance_has') {
            $x_0013[] = "id IN (SELECT student_id FROM student_billing WHERE (amount_due - amount_paid) > 0)";
        } elseif ($x_0012['type'] === 'balance_paid') {
            $x_0013[] = "id IN (SELECT student_id FROM student_billing) AND id NOT IN (SELECT student_id FROM student_billing WHERE (amount_due - amount_paid) > 0)";
        } elseif ($x_0012['type'] === 'absent') {
            $x_0013[] = "id IN (SELECT student_id FROM attendance WHERE attendance_date = CURDATE() AND attendance_status = 'Absent')";
        } elseif ($x_0012['type'] === 'transport') {
            $x_0013[] = "assigned_bus_id IS NOT NULL AND assigned_bus_id != 0";
        } elseif ($x_0012['type'] === 'staff') {

            return ['sql' => "SELECT id, name as full_name, '' as class, '' as admission_number, name as parent_name, '' as parent_phone FROM users WHERE 1=0", 'params' => []];
        }
    }
    
    if (count($x_0013) > 0) {
        $sql .= " AND " . implode(" AND ", $x_0013);
    }
    
    return ['sql' => $sql, 'params' => $params];
}

if ($action === 'get_recipients') {
    if (!$x_0002) exit;
    $x_0012 = $_POST['filters'] ?? '{}';
    $q = getRecipientQuery($pdo, $x_0012);
    $stmt = $pdo->prepare($q['sql']);
    $stmt->execute($q['params']);
    $x_0014 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'count' => count($x_0014), 'data' => array_slice($x_0014, 0, 5)]);
    exit;
}

if ($action === 'send_bulk' || $action === 'schedule_bulk') {
    if (!$x_0002) exit;
    $x_0012 = $_POST['filters'] ?? '{}';
    $x_0015 = $_POST['message'] ?? '';
    
    if (empty($x_0015)) {
        echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']);
        exit;
    }

    if ($action === 'schedule_bulk') {
        $x_0016 = $_POST['schedule_time'] ?? '';
        if (empty($x_0016)) {
             echo json_encode(['status' => 'error', 'message' => 'Schedule time is required.']);
             exit;
        }
        $x_0016 = str_replace('T', ' ', $x_0016);
        if (strlen($x_0016) == 16) $x_0016 .= ':00'; // Append seconds if missing
        $stmt = $pdo->prepare("INSERT INTO scheduled_sms (target_filter, message_content, scheduled_time, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$x_0012, $x_0015, $x_0016, $x_0003['id'] ?? null]);
        log_audit($pdo, $x_0003['id'] ?? null, 'SCHEDULE_SMS', "Scheduled bulk SMS for $x_0016.");
        echo json_encode(['status' => 'success', 'message' => 'Bulk SMS scheduled successfully.']);
        exit;
    }

    $q = getRecipientQuery($pdo, $x_0012);
    $stmt = $pdo->prepare($q['sql']);
    $stmt->execute($q['params']);
    $x_0014 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($x_0014) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'No recipients found for this filter.']);
        exit;
    }

    $x_0017 = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name')");
    $x_000d = [];
    while ($row = $x_0017->fetch()) {
        $x_000d[$row['setting_key']] = $row['setting_value'];
    }
    $x_000e = $x_000d['sms_api_token'] ?? '';
    $x_000f = $x_000d['sms_sender_id'] ?? '';
    $x_0010 = $x_000d['school_name'] ?? 'School System';
    
    if (empty($x_000e)) {
        echo json_encode(['status' => 'error', 'message' => 'SMS API Token not configured.']);
        exit;
    }

    $x_0018 = 0;
    $x_0019 = 0;
    
    foreach ($x_0014 as $x_001a) {
        $msg = str_replace(
            ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
            [$x_001a['full_name'], $x_001a['parent_name'], $x_001a['admission_number'], $x_001a['class'], $x_0010, date('h:i A')],
            $x_0015
        );
        $x_000c = $x_001a['parent_phone'];
        $res = sendBulkSMS([$x_000c], $msg, $x_000f, $x_000e);
        $x_001b = (isset($res['success']) && $res['success'] === true) ? 'Sent' : 'Failed';
        if ($x_001b === 'Sent') $x_0018++; else $x_0019++;
        
        $x_001c = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
        $x_001c->execute([
            $x_001a['id'] ?? null, $x_000c, $msg, $x_001b,
            json_encode($res), $res['error'] ?? ''
        ]);
    }
    
    log_audit($pdo, $x_0003['id'] ?? null, 'BULK_SMS', "Sent bulk SMS to $x_0018 recipients.");
    echo json_encode(['status' => 'success', 'message' => "Bulk SMS processed. Sent: $x_0018, Failed: $x_0019."]);
    exit;
}

if ($x_0001) {
}
?>
