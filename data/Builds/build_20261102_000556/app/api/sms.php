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
$x_0004 = $x_0001 ? ($_GET['action'] ?? '') : '';

function sendBulkSMS($x_0005, $x_0006, $x_0007, $x_0008) {

    $x_0009 = [];
    foreach ($x_0005 as $p) {
        $p = preg_replace('/[^0-9\+]/', '', $p);
        if (strpos($p, '0') === 0) {
            $p = '+254' . substr($p, 1);
        } elseif (strpos($p, '254') === 0) {
            $p = '+' . $p;
        } elseif (strpos($p, '+') !== 0) {
            $p = '+' . $p; // Ensure it has a +
        }
        $x_0009[] = $p;
    }

    $x_000a = "https://api.fluxsms.co.ke/sendsms";
    $x_000b = json_encode([
        'api_key' => $x_0008,
        'phone' => implode(',', $x_0009),
        'message' => $x_0006,
        'sender_id' => $x_0007
    ]);

    $ch = curl_init($x_000a);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $x_000b);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $x_000c = curl_exec($ch);
    $x_000d = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $x_000e = curl_error($ch);
        curl_close($ch);
        return ['error' => 'Connection error: ' . $x_000e];
    }
    curl_close($ch);
    
    $x_000f = json_decode($x_000c, true);
    
    if ($x_000d >= 200 && $x_000d < 300) {
        return [
            'success' => true,
            'responses' => $x_000f
        ];
    }

    return [
        'error' => $x_000f['message'] ?? 'Unknown error occurred',
        'http_code' => $x_000d,
        'raw' => $x_000c
    ];
}

if ($x_0004 === 'test_sms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$x_0002) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }

    $x_0010 = $_POST['phone'] ?? '';
    if (empty($x_0010)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required.']);
        exit;
    }

    $x_0011 = $x_0012->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name')");
    $x_0013 = [];
    while ($x_0014 = $x_0011->fetch()) {
        $x_0013[$x_0014['setting_key']] = $x_0014['setting_value'];
    }

    $x_0015 = $x_0013['sms_api_token'] ?? '';
    $x_0016 = $x_0013['sms_sender_id'] ?? '';
    $x_0017 = $x_0013['school_name'] ?? 'School System';

    if (empty($x_0015)) {
        echo json_encode(['status' => 'error', 'message' => 'SMS API Token is not configured.']);
        exit;
    }

    $x_0006 = "Test message from $x_0017. If you receive this, your SMS integration is correct!";
    $x_000f = sendBulkSMS([$x_0010], $x_0006, $x_0016, $x_0015);

    if (isset($x_000f['success']) && $x_000f['success'] === true) {
        echo json_encode(['status' => 'success', 'message' => 'Test SMS sent successfully!', 'details' => $x_000f]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send SMS.', 'details' => $x_000f]);
    }
    exit;
}

if ($x_0004 === 'get_logs') {
    if (!$x_0002) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }
    
    try {
        $x_0011 = $x_0012->query("
            SELECT l.*, s.full_name as student_name 
            FROM sms_logs l
            LEFT JOIN students s ON l.student_id = s.id
            ORDER BY l.sent_at DESC
            LIMIT 1000
        ");
        $x_0018 = $x_0011->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $x_0018]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error loading logs.']);
    }
    exit;
}

if ($x_0004 === 'delete_all_logs') {
    if (!$x_0002) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
        exit;
    }
    
    try {
        $x_0012->query("TRUNCATE TABLE sms_logs");
        log_audit($x_0012, $x_0003['id'] ?? null, 'DELETE_SMS_LOGS', "Admin cleared all SMS logs");
        echo json_encode(['status' => 'success', 'message' => 'All SMS logs have been deleted.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error while deleting logs.']);
    }
    exit;
}

function getRecipientQuery($x_0012, $x_0019) {
    if (is_string($x_0019)) $x_0019 = json_decode($x_0019, true) ?? [];
    $x_001a = "SELECT id, full_name, class, admission_number, parent_name, parent_phone FROM students WHERE parent_phone IS NOT NULL AND parent_phone != ''";
    $x_001b = [];
    $x_001c = [];
    
    if (isset($x_0019['type'])) {
        if ($x_0019['type'] === 'classes' && !empty($x_0019['classes'])) {
            $in = str_repeat('?,', count($x_0019['classes']) - 1) . '?';
            $x_001c[] = "class IN ($in)";
            $x_001b = array_merge($x_001b, $x_0019['classes']);
        } elseif ($x_0019['type'] === 'students' && !empty($x_0019['student_ids'])) {
            $in = str_repeat('?,', count($x_0019['student_ids']) - 1) . '?';
            $x_001c[] = "id IN ($in)";
            $x_001b = array_merge($x_001b, $x_0019['student_ids']);
        } elseif ($x_0019['type'] === 'balance_has') {
            $x_001c[] = "id IN (SELECT student_id FROM student_billing WHERE (amount_due - amount_paid) > 0)";
        } elseif ($x_0019['type'] === 'balance_paid') {
            $x_001c[] = "id IN (SELECT student_id FROM student_billing) AND id NOT IN (SELECT student_id FROM student_billing WHERE (amount_due - amount_paid) > 0)";
        } elseif ($x_0019['type'] === 'absent') {
            $x_001c[] = "id IN (SELECT student_id FROM attendance WHERE attendance_date = CURDATE() AND attendance_status = 'Absent')";
        } elseif ($x_0019['type'] === 'transport') {
            $x_001c[] = "assigned_bus_id IS NOT NULL AND assigned_bus_id != 0";
        } elseif ($x_0019['type'] === 'staff') {

            return ['sql' => "SELECT id, name as full_name, '' as class, '' as admission_number, name as parent_name, '' as parent_phone FROM users WHERE 1=0", 'params' => []];
        }
    }
    
    if (count($x_001c) > 0) {
        $x_001a .= " AND " . implode(" AND ", $x_001c);
    }
    
    return ['sql' => $x_001a, 'params' => $x_001b];
}

if ($x_0004 === 'get_recipients') {
    if (!$x_0002) exit;
    $x_0019 = $_POST['filters'] ?? '{}';
    $q = getRecipientQuery($x_0012, $x_0019);
    $x_0011 = $x_0012->prepare($q['sql']);
    $x_0011->execute($q['params']);
    $x_001d = $x_0011->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'count' => count($x_001d), 'data' => array_slice($x_001d, 0, 5)]);
    exit;
}

if ($x_0004 === 'send_bulk' || $x_0004 === 'schedule_bulk') {
    if (!$x_0002) exit;
    $x_0019 = $_POST['filters'] ?? '{}';
    $x_001e = $_POST['message'] ?? '';
    
    if (empty($x_001e)) {
        echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']);
        exit;
    }

    if ($x_0004 === 'schedule_bulk') {
        $x_001f = $_POST['schedule_time'] ?? '';
        if (empty($x_001f)) {
             echo json_encode(['status' => 'error', 'message' => 'Schedule time is required.']);
             exit;
        }
        $x_001f = str_replace('T', ' ', $x_001f);
        if (strlen($x_001f) == 16) $x_001f .= ':00'; // Append seconds if missing
        $x_0011 = $x_0012->prepare("INSERT INTO scheduled_sms (target_filter, message_content, scheduled_time, created_by) VALUES (?, ?, ?, ?)");
        $x_0011->execute([$x_0019, $x_001e, $x_001f, $x_0003['id'] ?? null]);
        log_audit($x_0012, $x_0003['id'] ?? null, 'SCHEDULE_SMS', "Scheduled bulk SMS for $x_001f.");
        echo json_encode(['status' => 'success', 'message' => 'Bulk SMS scheduled successfully.']);
        exit;
    }

    $q = getRecipientQuery($x_0012, $x_0019);
    $x_0011 = $x_0012->prepare($q['sql']);
    $x_0011->execute($q['params']);
    $x_001d = $x_0011->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($x_001d) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'No recipients found for this filter.']);
        exit;
    }

    $x_0020 = $x_0012->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name')");
    $x_0013 = [];
    while ($x_0014 = $x_0020->fetch()) {
        $x_0013[$x_0014['setting_key']] = $x_0014['setting_value'];
    }
    $x_0015 = $x_0013['sms_api_token'] ?? '';
    $x_0016 = $x_0013['sms_sender_id'] ?? '';
    $x_0017 = $x_0013['school_name'] ?? 'School System';
    
    if (empty($x_0015)) {
        echo json_encode(['status' => 'error', 'message' => 'SMS API Token not configured.']);
        exit;
    }

    $x_0021 = 0;
    $x_0022 = 0;
    
    foreach ($x_001d as $x_0023) {
        $x_0024 = str_replace(
            ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{TIME}'],
            [$x_0023['full_name'], $x_0023['parent_name'], $x_0023['admission_number'], $x_0023['class'], $x_0017, date('h:i A')],
            $x_001e
        );
        $x_0010 = $x_0023['parent_phone'];
        $x_0025 = sendBulkSMS([$x_0010], $x_0024, $x_0016, $x_0015);
        $x_0026 = (isset($x_0025['success']) && $x_0025['success'] === true) ? 'Sent' : 'Failed';
        if ($x_0026 === 'Sent') $x_0021++; else $x_0022++;
        
        $x_0027 = $x_0012->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
        $x_0027->execute([
            $x_0023['id'] ?? null, $x_0010, $x_0024, $x_0026,
            json_encode($x_0025), $x_0025['error'] ?? ''
        ]);
    }
    
    log_audit($x_0012, $x_0003['id'] ?? null, 'BULK_SMS', "Sent bulk SMS to $x_0021 recipients.");
    echo json_encode(['status' => 'success', 'message' => "Bulk SMS processed. Sent: $x_0021, Failed: $x_0022."]);
    exit;
}

if ($x_0001) {
}
?>
