<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once 'config.php';
require_once 'sms_helper.php';

$x_0001 = 'edugate_secure_token_2026';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['test'])) {

        $x_0002 = 'TEST' . rand(1000, 9999);
        $x_0003 = rand(500, 5000);
        $stmt = $pdo->prepare("INSERT INTO unallocated_payments (receipt_number, amount, sender_name, sender_phone, raw_sms, status, payment_date) VALUES (?, ?, ?, ?, ?, 'Unallocated', NOW())");
        $stmt->execute([$x_0002, $x_0003, 'TEST SENDER', '254700000000', 'Test SMS Body']);
        echo "Fake payment injected! Go look at your dashboard tab within 5 seconds!";
        exit;
    }
    echo json_encode(['status' => 'success', 'message' => 'M-Pesa Webhook endpoint is active and waiting for POST requests.']);
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true) ?: $_POST;

$token = $data['token'] ?? '';
$x_0004 = $data['sender'] ?? '';
$x_0005 = $data['text'] ?? '';

if ($token !== $x_0001) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token.']);
    exit;
}

if (strtoupper($x_0004) !== 'MPESA') {
    echo json_encode(['status' => 'ignored', 'message' => 'Sender is not MPESA.']);
    exit;
}

$x_0006 = '';
$x_0007 = 0;
$x_0008 = '';
$x_0009 = '';
$x_000a = ''; // e.g. "0711***569" - M-Pesa hides the middle digits on some SMS for privacy

if (preg_match('/^([A-Z0-9]+)\s+Confirmed/i', $x_0005, $x_000b)) {
    $x_0006 = $x_000b[1];
} else {
    echo json_encode(['status' => 'error', 'message' => 'Could not find receipt number.']);
    exit;
}

$x_000c = sys_get_temp_dir() . '/mpesa_lock_' . $x_0006 . '.txt';
$fp = fopen($x_000c, 'w+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Concurrent request detected for this receipt.']);
    exit;
}

if (preg_match('/Ksh\s*([\d,]+\.?\d*)/i', $x_0005, $x_000b)) {
    $x_0007 = floatval(str_replace(',', '', $x_000b[1]));
}

if (preg_match('/(?:from|by)\s+.*?(0[17]\d{8}|254[17]\d{8})/i', $x_0005, $x_000b)) {
    $x_000d = $x_000b[1];

    if (str_starts_with($x_000d, '0') && strlen($x_000d) === 10) {
        $x_0008 = '254' . substr($x_000d, 1);
    } else {
        $x_0008 = $x_000d;
    }
} elseif (preg_match('/(?:from|by)\s+.*?((?:0|254)[17]\d{2}\*{2,4}\d{3})/i', $x_0005, $x_000b)) {

    $x_000a = $x_000b[1];
}

if (preg_match('/from\s+([A-Z\s\-\']+)\s+(?:0[17]|254)/i', $x_0005, $x_000b)) {
    $x_0009 = trim($x_000b[1]);
}

try {

    $stmt = $pdo->prepare("SELECT id FROM billing_payment_history WHERE reference_number = ?");
    $stmt->execute([$x_0006]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already processed.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM unallocated_payments WHERE receipt_number = ?");
    $stmt->execute([$x_0006]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already logged as unallocated.']);
        exit;
    }

    if (empty($x_0008) && empty($x_0009) && empty($x_000a)) {

        logUnallocated($pdo, $x_0006, $x_0009, $x_0008, $x_0007, $x_0005);
        echo json_encode(['status' => 'success', 'message' => 'Logged as unallocated (No phone number or name found).']);
        exit;
    }

    $x_000e = [];

    if (!empty($x_0008)) {

        $x_000f = strlen($x_0008) >= 9 ? substr($x_0008, -9) : $x_0008;
        $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE parent_phone LIKE ?");
        $stmt->execute(['%' . $x_000f]);
        $x_000e = $stmt->fetchAll();
    }

    if (!$x_000e && !empty($x_000a)) {
        $x_0010 = findStudentsByMaskedPhone($pdo, $x_000a);

        if ($x_0010 && !empty($x_0009)) {
            $x_0011 = strtoupper(trim($x_0009));
            $x_0012 = explode(' ', $x_0011);
            
            foreach ($x_0010 as $x_0013) {
                $x_0014 = strtoupper(trim($x_0013['parent_name'] ?? ''));
                $x_0015 = strtoupper(trim($x_0013['full_name'] ?? ''));

                if ($x_0014 === $x_0011 || $x_0015 === $x_0011) {
                    $x_000e[] = $x_0013;
                    continue;
                }

                $x_0016 = false;
                foreach ($x_0012 as $x_0017) {
                    $x_0017 = trim($x_0017);
                    if (strlen($x_0017) > 2) {
                        if (strpos($x_0014, $x_0017) !== false || strpos($x_0015, $x_0017) !== false) {
                            $x_0016 = true;
                            break;
                        }
                    }
                }
                if ($x_0016) {
                    $x_000e[] = $x_0013;
                }
            }
        } elseif ($x_0010) {

            $x_000e = $x_0010;
        }
        
        if ($x_000e && empty($x_0008)) {
            $x_0008 = $x_000a;
        }
    }

    if (!$x_000e && !empty($x_0009)) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, class, parent_phone, parent_name, admission_number 
            FROM students 
            WHERE UPPER(TRIM(parent_name)) = UPPER(TRIM(?))
               OR UPPER(TRIM(full_name)) = UPPER(TRIM(?))
        ");
        $stmt->execute([$x_0009, $x_0009]);
        $x_000e = $stmt->fetchAll();
    }

    if (!$x_000e) {
        logUnallocated($pdo, $x_0006, $x_0009, $x_0008 ?: $x_000a, $x_0007, $x_0005);
        echo json_encode(['status' => 'success', 'message' => 'Logged as unallocated (No matching parent phone or name).']);
        exit;
    }

    $x_0018 = $x_0007;
    $x_0019 = 0;

    $x_0019 += distributePayment($pdo, $x_000e, $x_0018, $x_0006, 'M-Pesa', 'School Fees');

    if ($x_0018 > 0) {
        $x_0019 += distributePayment($pdo, $x_000e, $x_0018, $x_0006, 'M-Pesa', null);
    }

    if ($x_0018 > 0 && !empty($x_000e)) {
        $x_001a = $x_000e[0];
        $sql = "SELECT sb.*, bc.name as cat_name 
                FROM student_billing sb 
                JOIN billing_categories bc ON sb.category_id = bc.id 
                WHERE sb.student_id = ? AND bc.name = 'School Fees'
                ORDER BY sb.academic_year DESC, sb.school_term DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$x_001a['id']]);
        $x_001b = $stmt->fetch();
        
        if (!$x_001b) {

            $x_001c = $pdo->query("SELECT id FROM billing_categories WHERE name = 'School Fees' LIMIT 1");
            $x_001d = $x_001c->fetch();
            if ($x_001d) {
                $x_001e = date('Y');
                $x_001f = 'Term 1';
                $x_0020 = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('academic_year','current_term')");
                foreach ($x_0020->fetchAll() as $sr) {
                    if ($sr['setting_key'] == 'academic_year') $x_001e = $sr['setting_value'];
                    if ($sr['setting_key'] == 'current_term') $x_001f = $sr['setting_value'];
                }
                $x_0021 = $pdo->prepare("INSERT INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status) VALUES (?, ?, ?, ?, 0, 'Unpaid')");
                $x_0021->execute([$x_001a['id'], $x_001d['id'], $x_001e, $x_001f]);

                $stmt->execute([$x_001a['id']]);
                $x_001b = $stmt->fetch();
            }
        }
        
        if ($x_001b) {
            $x_0022 = floatval($x_001b['amount_due']);
            $x_0023 = floatval($x_001b['amount_paid']);
            $x_0024 = $x_0023 + $x_0018;
            $x_0025 = $x_0022 - $x_0024;
            $status = ($x_0025 <= 0) ? 'Paid' : 'Partial';
            
            $x_0026 = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = CURDATE() WHERE id = ?");
            $x_0026->execute([$x_0024, $status, $x_001b['id']]);
            
            $x_0027 = ($x_0022 > 0) ? round(($x_0024 / $x_0022) * 100, 2) : 100;
            $x_0028 = $pdo->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'ADVANCE_PAYMENT', ?, ?, ?, ?, ?)
            ");
            $x_0028->execute([
                $x_001b['id'], $x_001a['id'], $x_001b['category_id'], $x_001b['academic_year'], $x_001b['school_term'],
                $x_0022, $x_0024, $x_0025, $status, 
                $x_0018, $x_0022 - $x_0023, $x_0027, 'M-Pesa', $x_0006
            ]);
            
            $x_0029 = $x_0018;
            $x_0018 = 0;
            $x_0019++;
        }
    }

    if ($x_0019 == 0 && $x_0018 > 0) {

        logUnallocated($pdo, $x_0006, $x_0009, $x_0008 ?: $x_000a, $x_0018, $x_0005);
        echo json_encode(['status' => 'success', 'message' => 'Payment logged as unallocated (No active bills found for student).']);
        exit;
    }

    if ($x_0019 > 0 && !empty($x_000e)) {
        $x_001a = $x_000e[0];
        $x_002a = $pdo->prepare("SELECT SUM(amount_due - amount_paid) FROM student_billing WHERE student_id = ? AND payment_status IN ('Unpaid', 'Partial')");
        $x_002a->execute([$x_001a['id']]);
        $x_002b = floatval($x_002a->fetchColumn());
        $x_002c = ($x_002b <= 0) ? 'Paid' : 'Partial';
        
        sendPaymentSMS($pdo, $x_001a, $x_0007, $x_002b, $x_002c);
    }

    echo json_encode(['status' => 'success', 'message' => 'Payment automatically allocated.']);

} catch (Exception $e) {
    error_log("Webhook Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}

function logUnallocated($pdo, $x_0006, $x_0009, $x_0008, $x_0007, $x_0005) {
    $stmt = $pdo->prepare("INSERT INTO unallocated_payments (receipt_number, sender_name, sender_phone, amount, payment_date, raw_sms, status) VALUES (?, ?, ?, ?, NOW(), ?, 'Unallocated')");
    $stmt->execute([$x_0006, $x_0009, $x_0008, $x_0007, $x_0005]);
}

function findStudentsByMaskedPhone($pdo, $x_002d) {
    if (!preg_match('/^(?:0|254)(\d{3})\*{2,4}(\d{3})$/', $x_002d, $m)) {
        return [];
    }
    $x_002e = $m[1]; // 3 digits right after the leading 0 or 254
    $x_002f = $m[2]; // last 3 visible digits

    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $stmt->execute(['254' . $x_002e . '___' . $x_002f]);
    $x_000e = $stmt->fetchAll();

    if ($x_000e) {
        return $x_000e;
    }

    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $stmt->execute(['254' . $x_002e . '%' . $x_002f]);
    return $stmt->fetchAll();
}

function distributePayment($pdo, $x_000e, &$x_0018, $x_0006, $x_0030, $x_0031 = null) {
    $x_0032 = 0;

    foreach ($x_000e as $x_001a) {
        if ($x_0018 <= 0) break;

        $sql = "SELECT sb.*, bc.name as cat_name 
                FROM student_billing sb 
                JOIN billing_categories bc ON sb.category_id = bc.id 
                WHERE sb.student_id = ? AND sb.payment_status IN ('Unpaid', 'Partial')";
        
        if ($x_0031) {
            $sql .= " AND bc.name = ?";
        }
        
        $sql .= " ORDER BY sb.due_date ASC, bc.sort_order ASC";

        $stmt = $pdo->prepare($sql);
        if ($x_0031) {
            $stmt->execute([$x_001a['id'], $x_0031]);
        } else {
            $stmt->execute([$x_001a['id']]);
        }

        $x_0033 = $stmt->fetchAll();

        foreach ($x_0033 as $x_001b) {
            if ($x_0018 <= 0) break;

            $x_0022 = floatval($x_001b['amount_due']);
            $x_0023 = floatval($x_001b['amount_paid']);
            $x_0034 = $x_0022 - $x_0023;

            if ($x_0034 <= 0) continue;

            $x_0035 = min($x_0034, $x_0018);
            $x_0024 = $x_0023 + $x_0035;
            $x_0025 = $x_0022 - $x_0024;
            
            $status = ($x_0025 <= 0) ? 'Paid' : 'Partial';

            $x_0026 = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = CURDATE() WHERE id = ?");
            $x_0026->execute([$x_0024, $status, $x_001b['id']]);

            $x_0027 = ($x_0022 > 0) ? round(($x_0024 / $x_0022) * 100, 2) : 100;
            $x_0028 = $pdo->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'PAYMENT_RECORDED', ?, ?, ?, ?, ?)
            ");
            $x_0028->execute([
                $x_001b['id'], $x_001a['id'], $x_001b['category_id'], $x_001b['academic_year'], $x_001b['school_term'],
                $x_0022, $x_0024, $x_0025, $status, $x_001b['due_date'], 
                $x_0035, $x_0034, $x_0027, $x_0030, $x_0006
            ]);

            $x_0018 -= $x_0035;
            $x_0032++;
        }
    }

    return $x_0032;
}

?>
