<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once 'config.php';
require_once 'sms_helper.php';

$WEBHOOK_TOKEN = 'edugate_secure_token_2026';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['test'])) {

        $testCode = 'TEST' . rand(1000, 9999);
        $testAmount = rand(500, 5000);
        $stmt = $pdo->prepare("INSERT INTO unallocated_payments (receipt_number, amount, sender_name, sender_phone, raw_sms, status, payment_date) VALUES (?, ?, ?, ?, ?, 'Unallocated', NOW())");
        $stmt->execute([$testCode, $testAmount, 'TEST SENDER', '254700000000', 'Test SMS Body']);
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
$sender = $data['sender'] ?? '';
$text = $data['text'] ?? '';

if ($token !== $WEBHOOK_TOKEN) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token.']);
    exit;
}

if (strtoupper($sender) !== 'MPESA') {
    echo json_encode(['status' => 'ignored', 'message' => 'Sender is not MPESA.']);
    exit;
}

$receipt = '';
$amount = 0;
$senderPhone = '';
$senderName = '';
$maskedPhone = ''; // e.g. "0711***569" - M-Pesa hides the middle digits on some SMS for privacy

if (preg_match('/^([A-Z0-9]+)\s+Confirmed/i', $text, $matches)) {
    $receipt = $matches[1];
} else {
    echo json_encode(['status' => 'error', 'message' => 'Could not find receipt number.']);
    exit;
}

$lockFile = sys_get_temp_dir() . '/mpesa_lock_' . $receipt . '.txt';
$fp = fopen($lockFile, 'w+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Concurrent request detected for this receipt.']);
    exit;
}

if (preg_match('/Ksh\s*([\d,]+\.?\d*)/i', $text, $matches)) {
    $amount = floatval(str_replace(',', '', $matches[1]));
}

if (preg_match('/(?:from|by)\s+.*?(0[17]\d{8}|254[17]\d{8})/i', $text, $matches)) {
    $rawPhone = $matches[1];

    if (str_starts_with($rawPhone, '0') && strlen($rawPhone) === 10) {
        $senderPhone = '254' . substr($rawPhone, 1);
    } else {
        $senderPhone = $rawPhone;
    }
} elseif (preg_match('/(?:from|by)\s+.*?((?:0|254)[17]\d{2}\*{2,4}\d{3})/i', $text, $matches)) {

    $maskedPhone = $matches[1];
}

if (preg_match('/from\s+([A-Z\s\-\']+)\s+(?:0[17]|254)/i', $text, $matches)) {
    $senderName = trim($matches[1]);
}

try {

    $stmt = $pdo->prepare("SELECT id FROM billing_payment_history WHERE reference_number = ?");
    $stmt->execute([$receipt]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already processed.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM unallocated_payments WHERE receipt_number = ?");
    $stmt->execute([$receipt]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already logged as unallocated.']);
        exit;
    }

    if (empty($senderPhone) && empty($senderName) && empty($maskedPhone)) {

        logUnallocated($pdo, $receipt, $senderName, $senderPhone, $amount, $text);
        echo json_encode(['status' => 'success', 'message' => 'Logged as unallocated (No phone number or name found).']);
        exit;
    }

    $students = [];

    if (!empty($senderPhone)) {

        $searchPhone = strlen($senderPhone) >= 9 ? substr($senderPhone, -9) : $senderPhone;
        $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE parent_phone LIKE ?");
        $stmt->execute(['%' . $searchPhone]);
        $students = $stmt->fetchAll();
    }

    if (!$students && !empty($maskedPhone)) {
        $candidates = findStudentsByMaskedPhone($pdo, $maskedPhone);

        if ($candidates && !empty($senderName)) {
            $senderNameUpper = strtoupper(trim($senderName));
            $nameParts = explode(' ', $senderNameUpper);
            
            foreach ($candidates as $cand) {
                $parentName = strtoupper(trim($cand['parent_name'] ?? ''));
                $studentName = strtoupper(trim($cand['full_name'] ?? ''));

                if ($parentName === $senderNameUpper || $studentName === $senderNameUpper) {
                    $students[] = $cand;
                    continue;
                }

                $matched = false;
                foreach ($nameParts as $part) {
                    $part = trim($part);
                    if (strlen($part) > 2) {
                        if (strpos($parentName, $part) !== false || strpos($studentName, $part) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if ($matched) {
                    $students[] = $cand;
                }
            }
        } elseif ($candidates) {

            $students = $candidates;
        }
        
        if ($students && empty($senderPhone)) {
            $senderPhone = $maskedPhone;
        }
    }

    if (!$students && !empty($senderName)) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, class, parent_phone, parent_name, admission_number 
            FROM students 
            WHERE UPPER(TRIM(parent_name)) = UPPER(TRIM(?))
               OR UPPER(TRIM(full_name)) = UPPER(TRIM(?))
        ");
        $stmt->execute([$senderName, $senderName]);
        $students = $stmt->fetchAll();
    }

    if (!$students) {
        logUnallocated($pdo, $receipt, $senderName, $senderPhone ?: $maskedPhone, $amount, $text);
        echo json_encode(['status' => 'success', 'message' => 'Logged as unallocated (No matching parent phone or name).']);
        exit;
    }

    $amountRemaining = $amount;
    $processedRecords = 0;

    $processedRecords += distributePayment($pdo, $students, $amountRemaining, $receipt, 'M-Pesa', 'School Fees');

    if ($amountRemaining > 0) {
        $processedRecords += distributePayment($pdo, $students, $amountRemaining, $receipt, 'M-Pesa', null);
    }

    if ($amountRemaining > 0 && !empty($students)) {
        $student = $students[0];
        $sql = "SELECT sb.*, bc.name as cat_name 
                FROM student_billing sb 
                JOIN billing_categories bc ON sb.category_id = bc.id 
                WHERE sb.student_id = ? AND bc.name = 'School Fees'
                ORDER BY sb.academic_year DESC, sb.school_term DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student['id']]);
        $bill = $stmt->fetch();
        
        if (!$bill) {

            $catStmt = $pdo->query("SELECT id FROM billing_categories WHERE name = 'School Fees' LIMIT 1");
            $cat = $catStmt->fetch();
            if ($cat) {
                $year = date('Y');
                $term = 'Term 1';
                $sStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('academic_year','current_term')");
                foreach ($sStmt->fetchAll() as $sr) {
                    if ($sr['setting_key'] == 'academic_year') $year = $sr['setting_value'];
                    if ($sr['setting_key'] == 'current_term') $term = $sr['setting_value'];
                }
                $ins = $pdo->prepare("INSERT INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status) VALUES (?, ?, ?, ?, 0, 'Unpaid')");
                $ins->execute([$student['id'], $cat['id'], $year, $term]);

                $stmt->execute([$student['id']]);
                $bill = $stmt->fetch();
            }
        }
        
        if ($bill) {
            $amountDue = floatval($bill['amount_due']);
            $currentPaid = floatval($bill['amount_paid']);
            $newPaid = $currentPaid + $amountRemaining;
            $newBalance = $amountDue - $newPaid;
            $status = ($newBalance <= 0) ? 'Paid' : 'Partial';
            
            $upd = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = CURDATE() WHERE id = ?");
            $upd->execute([$newPaid, $status, $bill['id']]);
            
            $percentage = ($amountDue > 0) ? round(($newPaid / $amountDue) * 100, 2) : 100;
            $hist = $pdo->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'ADVANCE_PAYMENT', ?, ?, ?, ?, ?)
            ");
            $hist->execute([
                $bill['id'], $student['id'], $bill['category_id'], $bill['academic_year'], $bill['school_term'],
                $amountDue, $newPaid, $newBalance, $status, 
                $amountRemaining, $amountDue - $currentPaid, $percentage, 'M-Pesa', $receipt
            ]);
            
            $paidAmountThisStep = $amountRemaining;
            $amountRemaining = 0;
            $processedRecords++;
        }
    }

    if ($processedRecords == 0 && $amountRemaining > 0) {

        logUnallocated($pdo, $receipt, $senderName, $senderPhone ?: $maskedPhone, $amountRemaining, $text);
        echo json_encode(['status' => 'success', 'message' => 'Payment logged as unallocated (No active bills found for student).']);
        exit;
    }

    if ($processedRecords > 0 && !empty($students)) {
        $student = $students[0];
        $balStmt = $pdo->prepare("SELECT SUM(amount_due - amount_paid) FROM student_billing WHERE student_id = ? AND payment_status IN ('Unpaid', 'Partial')");
        $balStmt->execute([$student['id']]);
        $totalBalance = floatval($balStmt->fetchColumn());
        $finalStatus = ($totalBalance <= 0) ? 'Paid' : 'Partial';
        
        sendPaymentSMS($pdo, $student, $amount, $totalBalance, $finalStatus);
    }

    echo json_encode(['status' => 'success', 'message' => 'Payment automatically allocated.']);

} catch (Exception $e) {
    error_log("Webhook Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}

function logUnallocated($pdo, $receipt, $senderName, $senderPhone, $amount, $text) {
    $stmt = $pdo->prepare("INSERT INTO unallocated_payments (receipt_number, sender_name, sender_phone, amount, payment_date, raw_sms, status) VALUES (?, ?, ?, ?, NOW(), ?, 'Unallocated')");
    $stmt->execute([$receipt, $senderName, $senderPhone, $amount, $text]);
}

function findStudentsByMaskedPhone($pdo, $masked) {
    if (!preg_match('/^(?:0|254)(\d{3})\*{2,4}(\d{3})$/', $masked, $m)) {
        return [];
    }
    $prefix = $m[1]; // 3 digits right after the leading 0 or 254
    $suffix = $m[2]; // last 3 visible digits

    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $stmt->execute(['254' . $prefix . '___' . $suffix]);
    $students = $stmt->fetchAll();

    if ($students) {
        return $students;
    }

    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $stmt->execute(['254' . $prefix . '%' . $suffix]);
    return $stmt->fetchAll();
}

function distributePayment($pdo, $students, &$amountRemaining, $receipt, $paymentMethod, $categoryFilter = null) {
    $processed = 0;

    foreach ($students as $student) {
        if ($amountRemaining <= 0) break;

        $sql = "SELECT sb.*, bc.name as cat_name 
                FROM student_billing sb 
                JOIN billing_categories bc ON sb.category_id = bc.id 
                WHERE sb.student_id = ? AND sb.payment_status IN ('Unpaid', 'Partial')";
        
        if ($categoryFilter) {
            $sql .= " AND bc.name = ?";
        }
        
        $sql .= " ORDER BY sb.due_date ASC, bc.sort_order ASC";

        $stmt = $pdo->prepare($sql);
        if ($categoryFilter) {
            $stmt->execute([$student['id'], $categoryFilter]);
        } else {
            $stmt->execute([$student['id']]);
        }

        $unpaidBills = $stmt->fetchAll();

        foreach ($unpaidBills as $bill) {
            if ($amountRemaining <= 0) break;

            $amountDue = floatval($bill['amount_due']);
            $currentPaid = floatval($bill['amount_paid']);
            $balance = $amountDue - $currentPaid;

            if ($balance <= 0) continue;

            $payAmount = min($balance, $amountRemaining);
            $newPaid = $currentPaid + $payAmount;
            $newBalance = $amountDue - $newPaid;
            
            $status = ($newBalance <= 0) ? 'Paid' : 'Partial';

            $upd = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = CURDATE() WHERE id = ?");
            $upd->execute([$newPaid, $status, $bill['id']]);

            $percentage = ($amountDue > 0) ? round(($newPaid / $amountDue) * 100, 2) : 100;
            $hist = $pdo->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'PAYMENT_RECORDED', ?, ?, ?, ?, ?)
            ");
            $hist->execute([
                $bill['id'], $student['id'], $bill['category_id'], $bill['academic_year'], $bill['school_term'],
                $amountDue, $newPaid, $newBalance, $status, $bill['due_date'], 
                $payAmount, $balance, $percentage, $paymentMethod, $receipt
            ]);

            $amountRemaining -= $payAmount;
            $processed++;
        }
    }

    return $processed;
}

?>
