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
        $x_0004 = $x_0005->prepare("INSERT INTO unallocated_payments (receipt_number, amount, sender_name, sender_phone, raw_sms, status, payment_date) VALUES (?, ?, ?, ?, ?, 'Unallocated', NOW())");
        $x_0004->execute([$x_0002, $x_0003, 'TEST SENDER', '254700000000', 'Test SMS Body']);
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

$x_0006 = file_get_contents('php://input');
$x_0007 = json_decode($x_0006, true) ?: $_POST;

$x_0008 = $x_0007['token'] ?? '';
$x_0009 = $x_0007['sender'] ?? '';
$x_000a = $x_0007['text'] ?? '';

if ($x_0008 !== $x_0001) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token.']);
    exit;
}

if (strtoupper($x_0009) !== 'MPESA') {
    echo json_encode(['status' => 'ignored', 'message' => 'Sender is not MPESA.']);
    exit;
}

$x_000b = '';
$x_000c = 0;
$x_000d = '';
$x_000e = '';
$x_000f = ''; // e.g. "0711***569" - M-Pesa hides the middle digits on some SMS for privacy

if (preg_match('/^([A-Z0-9]+)\s+Confirmed/i', $x_000a, $x_0010)) {
    $x_000b = $x_0010[1];
} else {
    echo json_encode(['status' => 'error', 'message' => 'Could not find receipt number.']);
    exit;
}

$x_0011 = sys_get_temp_dir() . '/mpesa_lock_' . $x_000b . '.txt';
$fp = fopen($x_0011, 'w+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Concurrent request detected for this receipt.']);
    exit;
}

if (preg_match('/Ksh\s*([\d,]+\.?\d*)/i', $x_000a, $x_0010)) {
    $x_000c = floatval(str_replace(',', '', $x_0010[1]));
}

if (preg_match('/(?:from|by)\s+.*?(0[17]\d{8}|254[17]\d{8})/i', $x_000a, $x_0010)) {
    $x_0012 = $x_0010[1];

    if (str_starts_with($x_0012, '0') && strlen($x_0012) === 10) {
        $x_000d = '254' . substr($x_0012, 1);
    } else {
        $x_000d = $x_0012;
    }
} elseif (preg_match('/(?:from|by)\s+.*?((?:0|254)[17]\d{2}\*{2,4}\d{3})/i', $x_000a, $x_0010)) {

    $x_000f = $x_0010[1];
}

if (preg_match('/from\s+([A-Z\s\-\']+)\s+(?:0[17]|254)/i', $x_000a, $x_0010)) {
    $x_000e = trim($x_0010[1]);
}

try {

    $x_0004 = $x_0005->prepare("SELECT id FROM billing_payment_history WHERE reference_number = ?");
    $x_0004->execute([$x_000b]);
    if ($x_0004->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already processed.']);
        exit;
    }

    $x_0004 = $x_0005->prepare("SELECT id FROM unallocated_payments WHERE receipt_number = ?");
    $x_0004->execute([$x_000b]);
    if ($x_0004->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already logged as unallocated.']);
        exit;
    }

    if (empty($x_000d) && empty($x_000e) && empty($x_000f)) {

        logUnallocated($x_0005, $x_000b, $x_000e, $x_000d, $x_000c, $x_000a);
        echo json_encode(['status' => 'success', 'message' => 'Logged as unallocated (No phone number or name found).']);
        exit;
    }

    $x_0013 = [];

    if (!empty($x_000d)) {

        $x_0014 = strlen($x_000d) >= 9 ? substr($x_000d, -9) : $x_000d;
        $x_0004 = $x_0005->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE parent_phone LIKE ?");
        $x_0004->execute(['%' . $x_0014]);
        $x_0013 = $x_0004->fetchAll();
    }

    if (!$x_0013 && !empty($x_000f)) {
        $x_0015 = findStudentsByMaskedPhone($x_0005, $x_000f);

        if ($x_0015 && !empty($x_000e)) {
            $x_0016 = strtoupper(trim($x_000e));
            $x_0017 = explode(' ', $x_0016);
            
            foreach ($x_0015 as $x_0018) {
                $x_0019 = strtoupper(trim($x_0018['parent_name'] ?? ''));
                $x_001a = strtoupper(trim($x_0018['full_name'] ?? ''));

                if ($x_0019 === $x_0016 || $x_001a === $x_0016) {
                    $x_0013[] = $x_0018;
                    continue;
                }

                $x_001b = false;
                foreach ($x_0017 as $x_001c) {
                    $x_001c = trim($x_001c);
                    if (strlen($x_001c) > 2) {
                        if (strpos($x_0019, $x_001c) !== false || strpos($x_001a, $x_001c) !== false) {
                            $x_001b = true;
                            break;
                        }
                    }
                }
                if ($x_001b) {
                    $x_0013[] = $x_0018;
                }
            }
        } elseif ($x_0015) {

            $x_0013 = $x_0015;
        }
        
        if ($x_0013 && empty($x_000d)) {
            $x_000d = $x_000f;
        }
    }

    if (!$x_0013 && !empty($x_000e)) {
        $x_0004 = $x_0005->prepare("
            SELECT id, full_name, class, parent_phone, parent_name, admission_number 
            FROM students 
            WHERE UPPER(TRIM(parent_name)) = UPPER(TRIM(?))
               OR UPPER(TRIM(full_name)) = UPPER(TRIM(?))
        ");
        $x_0004->execute([$x_000e, $x_000e]);
        $x_0013 = $x_0004->fetchAll();
    }

    if (!$x_0013) {
        logUnallocated($x_0005, $x_000b, $x_000e, $x_000d ?: $x_000f, $x_000c, $x_000a);
        echo json_encode(['status' => 'success', 'message' => 'Logged as unallocated (No matching parent phone or name).']);
        exit;
    }

    $x_001d = $x_000c;
    $x_001e = 0;

    $x_001e += distributePayment($x_0005, $x_0013, $x_001d, $x_000b, 'M-Pesa', 'School Fees');

    if ($x_001d > 0) {
        $x_001e += distributePayment($x_0005, $x_0013, $x_001d, $x_000b, 'M-Pesa', null);
    }

    if ($x_001d > 0 && !empty($x_0013)) {
        $x_001f = $x_0013[0];
        $x_0020 = "SELECT sb.*, bc.name as cat_name 
                FROM student_billing sb 
                JOIN billing_categories bc ON sb.category_id = bc.id 
                WHERE sb.student_id = ? AND bc.name = 'School Fees'
                ORDER BY sb.academic_year DESC, sb.school_term DESC LIMIT 1";
        $x_0004 = $x_0005->prepare($x_0020);
        $x_0004->execute([$x_001f['id']]);
        $x_0021 = $x_0004->fetch();
        
        if (!$x_0021) {

            $x_0022 = $x_0005->query("SELECT id FROM billing_categories WHERE name = 'School Fees' LIMIT 1");
            $x_0023 = $x_0022->fetch();
            if ($x_0023) {
                $x_0024 = date('Y');
                $x_0025 = 'Term 1';
                $x_0026 = $x_0005->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('academic_year','current_term')");
                foreach ($x_0026->fetchAll() as $sr) {
                    if ($sr['setting_key'] == 'academic_year') $x_0024 = $sr['setting_value'];
                    if ($sr['setting_key'] == 'current_term') $x_0025 = $sr['setting_value'];
                }
                $x_0027 = $x_0005->prepare("INSERT INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status) VALUES (?, ?, ?, ?, 0, 'Unpaid')");
                $x_0027->execute([$x_001f['id'], $x_0023['id'], $x_0024, $x_0025]);

                $x_0004->execute([$x_001f['id']]);
                $x_0021 = $x_0004->fetch();
            }
        }
        
        if ($x_0021) {
            $x_0028 = floatval($x_0021['amount_due']);
            $x_0029 = floatval($x_0021['amount_paid']);
            $x_002a = $x_0029 + $x_001d;
            $x_002b = $x_0028 - $x_002a;
            $x_002c = ($x_002b <= 0) ? 'Paid' : 'Partial';
            
            $x_002d = $x_0005->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = CURDATE() WHERE id = ?");
            $x_002d->execute([$x_002a, $x_002c, $x_0021['id']]);
            
            $x_002e = ($x_0028 > 0) ? round(($x_002a / $x_0028) * 100, 2) : 100;
            $x_002f = $x_0005->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'ADVANCE_PAYMENT', ?, ?, ?, ?, ?)
            ");
            $x_002f->execute([
                $x_0021['id'], $x_001f['id'], $x_0021['category_id'], $x_0021['academic_year'], $x_0021['school_term'],
                $x_0028, $x_002a, $x_002b, $x_002c, 
                $x_001d, $x_0028 - $x_0029, $x_002e, 'M-Pesa', $x_000b
            ]);
            
            $x_0030 = $x_001d;
            $x_001d = 0;
            $x_001e++;
        }
    }

    if ($x_001e == 0 && $x_001d > 0) {

        logUnallocated($x_0005, $x_000b, $x_000e, $x_000d ?: $x_000f, $x_001d, $x_000a);
        echo json_encode(['status' => 'success', 'message' => 'Payment logged as unallocated (No active bills found for student).']);
        exit;
    }

    if ($x_001e > 0 && !empty($x_0013)) {
        $x_001f = $x_0013[0];
        $x_0031 = $x_0005->prepare("SELECT SUM(amount_due - amount_paid) FROM student_billing WHERE student_id = ? AND payment_status IN ('Unpaid', 'Partial')");
        $x_0031->execute([$x_001f['id']]);
        $x_0032 = floatval($x_0031->fetchColumn());
        $x_0033 = ($x_0032 <= 0) ? 'Paid' : 'Partial';
        
        sendPaymentSMS($x_0005, $x_001f, $x_000c, $x_0032, $x_0033);
    }

    echo json_encode(['status' => 'success', 'message' => 'Payment automatically allocated.']);

} catch (Exception $e) {
    error_log("Webhook Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}

function logUnallocated($x_0005, $x_000b, $x_000e, $x_000d, $x_000c, $x_000a) {
    $x_0004 = $x_0005->prepare("INSERT INTO unallocated_payments (receipt_number, sender_name, sender_phone, amount, payment_date, raw_sms, status) VALUES (?, ?, ?, ?, NOW(), ?, 'Unallocated')");
    $x_0004->execute([$x_000b, $x_000e, $x_000d, $x_000c, $x_000a]);
}

function findStudentsByMaskedPhone($x_0005, $x_0034) {
    if (!preg_match('/^(?:0|254)(\d{3})\*{2,4}(\d{3})$/', $x_0034, $m)) {
        return [];
    }
    $x_0035 = $m[1]; // 3 digits right after the leading 0 or 254
    $x_0036 = $m[2]; // last 3 visible digits

    $x_0004 = $x_0005->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $x_0004->execute(['254' . $x_0035 . '___' . $x_0036]);
    $x_0013 = $x_0004->fetchAll();

    if ($x_0013) {
        return $x_0013;
    }

    $x_0004 = $x_0005->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $x_0004->execute(['254' . $x_0035 . '%' . $x_0036]);
    return $x_0004->fetchAll();
}

function distributePayment($x_0005, $x_0013, &$x_001d, $x_000b, $x_0037, $x_0038 = null) {
    $x_0039 = 0;

    foreach ($x_0013 as $x_001f) {
        if ($x_001d <= 0) break;

        $x_0020 = "SELECT sb.*, bc.name as cat_name 
                FROM student_billing sb 
                JOIN billing_categories bc ON sb.category_id = bc.id 
                WHERE sb.student_id = ? AND sb.payment_status IN ('Unpaid', 'Partial')";
        
        if ($x_0038) {
            $x_0020 .= " AND bc.name = ?";
        }
        
        $x_0020 .= " ORDER BY sb.due_date ASC, bc.sort_order ASC";

        $x_0004 = $x_0005->prepare($x_0020);
        if ($x_0038) {
            $x_0004->execute([$x_001f['id'], $x_0038]);
        } else {
            $x_0004->execute([$x_001f['id']]);
        }

        $x_003a = $x_0004->fetchAll();

        foreach ($x_003a as $x_0021) {
            if ($x_001d <= 0) break;

            $x_0028 = floatval($x_0021['amount_due']);
            $x_0029 = floatval($x_0021['amount_paid']);
            $x_003b = $x_0028 - $x_0029;

            if ($x_003b <= 0) continue;

            $x_003c = min($x_003b, $x_001d);
            $x_002a = $x_0029 + $x_003c;
            $x_002b = $x_0028 - $x_002a;
            
            $x_002c = ($x_002b <= 0) ? 'Paid' : 'Partial';

            $x_002d = $x_0005->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = CURDATE() WHERE id = ?");
            $x_002d->execute([$x_002a, $x_002c, $x_0021['id']]);

            $x_002e = ($x_0028 > 0) ? round(($x_002a / $x_0028) * 100, 2) : 100;
            $x_002f = $x_0005->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'PAYMENT_RECORDED', ?, ?, ?, ?, ?)
            ");
            $x_002f->execute([
                $x_0021['id'], $x_001f['id'], $x_0021['category_id'], $x_0021['academic_year'], $x_0021['school_term'],
                $x_0028, $x_002a, $x_002b, $x_002c, $x_0021['due_date'], 
                $x_003c, $x_003b, $x_002e, $x_0037, $x_000b
            ]);

            $x_001d -= $x_003c;
            $x_0039++;
        }
    }

    return $x_0039;
}

?>
