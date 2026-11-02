<?php
/**
 * api/mpesa_webhook.php
 * Endpoint for receiving SMS data from an Android SMS Forwarder App.
 * Expects POST data with 'token', 'sender', and 'text'.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once 'config.php';
require_once 'sms_helper.php';

// A simple security token (change this in production)
$WEBHOOK_TOKEN = 'edugate_secure_token_2026';

// 1. Verify Request Method
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['test'])) {
        // Trigger a fake unallocated payment for testing the popups!
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

// 2. Extract Data (Supporting both JSON and Form Data)
$input = file_get_contents('php://input');
$data = json_decode($input, true) ?: $_POST;

$token = $data['token'] ?? '';
$sender = $data['sender'] ?? '';
$text = $data['text'] ?? '';

// 3. Verify Token
if ($token !== $WEBHOOK_TOKEN) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token.']);
    exit;
}

// 4. Check Sender
if (strtoupper($sender) !== 'MPESA') {
    echo json_encode(['status' => 'ignored', 'message' => 'Sender is not MPESA.']);
    exit;
}

// 5. Parse SMS using Regex
// Standard M-Pesa P2P format: 
// OBG123456 Confirmed.You have received Ksh5,000.00 from JOHN DOE 0712345678 on 14/8/20 at 2:00 PM...
// Or Till Format:
// OBG123456 Confirmed. Ksh 5,000 paid to School Name. on 14/8/20 at 2:00 PM... (Till might not have phone number, but let's try to extract it anyway)

$receipt = '';
$amount = 0;
$senderPhone = '';
$senderName = '';
$maskedPhone = ''; // e.g. "0711***569" - M-Pesa hides the middle digits on some SMS for privacy

// Extract Receipt (e.g. OBG123456)
if (preg_match('/^([A-Z0-9]+)\s+Confirmed/i', $text, $matches)) {
    $receipt = $matches[1];
} else {
    echo json_encode(['status' => 'error', 'message' => 'Could not find receipt number.']);
    exit;
}

// Concurrency Lock: Prevent simultaneous identical webhooks from processing the same receipt
$lockFile = sys_get_temp_dir() . '/mpesa_lock_' . $receipt . '.txt';
$fp = fopen($lockFile, 'w+');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Concurrent request detected for this receipt.']);
    exit;
}


// Extract Amount
if (preg_match('/Ksh\s*([\d,]+\.?\d*)/i', $text, $matches)) {
    $amount = floatval(str_replace(',', '', $matches[1]));
}

// Extract Phone Number (e.g. 0712345678 or 254712345678)
if (preg_match('/(?:from|by)\s+.*?(0[17]\d{8}|254[17]\d{8})/i', $text, $matches)) {
    $rawPhone = $matches[1];
    // Normalize to 254...
    if (str_starts_with($rawPhone, '0') && strlen($rawPhone) === 10) {
        $senderPhone = '254' . substr($rawPhone, 1);
    } else {
        $senderPhone = $rawPhone;
    }
} elseif (preg_match('/(?:from|by)\s+.*?((?:0|254)[17]\d{2}\*{2,4}\d{3})/i', $text, $matches)) {
    // M-Pesa sometimes masks the middle digits for privacy, e.g. "0711***569" or "254711***569"
    // We can't get the exact number, but we can still narrow down candidates by
    // the first 4 and last 3 digits and confirm/complete the match further down.
    $maskedPhone = $matches[1];
}

// Extract Sender Name (usually between 'from' and the phone number)
if (preg_match('/from\s+([A-Z\s\-\']+)\s+(?:0[17]|254)/i', $text, $matches)) {
    $senderName = trim($matches[1]);
}

// 6. Process the Payment
try {
    // Check if receipt already exists in billing_payment_history
    $stmt = $pdo->prepare("SELECT id FROM billing_payment_history WHERE reference_number = ?");
    $stmt->execute([$receipt]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already processed.']);
        exit;
    }

    // Check if receipt already exists in unallocated_payments
    $stmt = $pdo->prepare("SELECT id FROM unallocated_payments WHERE receipt_number = ?");
    $stmt->execute([$receipt]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'ignored', 'message' => 'Receipt already logged as unallocated.']);
        exit;
    }

    if (empty($senderPhone) && empty($senderName) && empty($maskedPhone)) {
        // Nothing to match against at all - log as unallocated directly
        logUnallocated($pdo, $receipt, $senderName, $senderPhone, $amount, $text);
        echo json_encode(['status' => 'success', 'message' => 'Logged as unallocated (No phone number or name found).']);
        exit;
    }

    $students = [];
    
    // 1. Try Exact Unmasked Phone Match
    if (!empty($senderPhone)) {
        // Match last 9 digits to bypass leading 0, 254, or +254 formatting issues in DB
        $searchPhone = strlen($senderPhone) >= 9 ? substr($senderPhone, -9) : $senderPhone;
        $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE parent_phone LIKE ?");
        $stmt->execute(['%' . $searchPhone]);
        $students = $stmt->fetchAll();
    }
    
    // 2. Try Masked Phone + Name Verification
    if (!$students && !empty($maskedPhone)) {
        $candidates = findStudentsByMaskedPhone($pdo, $maskedPhone);
        
        // Verify Name
        if ($candidates && !empty($senderName)) {
            $senderNameUpper = strtoupper(trim($senderName));
            $nameParts = explode(' ', $senderNameUpper);
            
            foreach ($candidates as $cand) {
                $parentName = strtoupper(trim($cand['parent_name'] ?? ''));
                $studentName = strtoupper(trim($cand['full_name'] ?? ''));
                
                // Exact Match
                if ($parentName === $senderNameUpper || $studentName === $senderNameUpper) {
                    $students[] = $cand;
                    continue;
                }
                
                // Partial Match
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
            // No sender name to verify against, but phone matched.
            $students = $candidates;
        }
        
        if ($students && empty($senderPhone)) {
            $senderPhone = $maskedPhone;
        }
    }
    
    // 3. Fallback: Exact Name Match
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
    
    // First Priority: 'School Fees'
    $processedRecords += distributePayment($pdo, $students, $amountRemaining, $receipt, 'M-Pesa', 'School Fees');

    // Second Priority: Any remaining Unpaid bills, ordered by due_date
    if ($amountRemaining > 0) {
        $processedRecords += distributePayment($pdo, $students, $amountRemaining, $receipt, 'M-Pesa', null);
    }

    // Third Priority: If still remaining (e.g. overpayment or fee is currently 0), force it into "School Fees" as an advance payment
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
            // Auto-create School Fees bill if it doesn't exist to ensure payment is allocated
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
                
                // Re-fetch
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
        // Student has no bills at all (not even School Fees), log remaining as unallocated
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

// ─── Helpers ────────────────────────────────────────────────────────────────

function logUnallocated($pdo, $receipt, $senderName, $senderPhone, $amount, $text) {
    $stmt = $pdo->prepare("INSERT INTO unallocated_payments (receipt_number, sender_name, sender_phone, amount, payment_date, raw_sms, status) VALUES (?, ?, ?, ?, NOW(), ?, 'Unallocated')");
    $stmt->execute([$receipt, $senderName, $senderPhone, $amount, $text]);
}

/**
 * Matches a privacy-masked M-Pesa number (e.g. "0711***569") against students'
 * parent_phone by comparing only the digits M-Pesa actually shows us:
 * the first 4 digits and the last 3 digits. The middle (masked) digits are
 * treated as wildcards. parent_phone is always stored normalized as 254XXXXXXXXX
 * (12 digits), so a local "0711***569" (10 chars) becomes the pattern
 * "254711___569" (254 + next 3 digits + 3 wildcard digits + last 3 digits).
 */
function findStudentsByMaskedPhone($pdo, $masked) {
    if (!preg_match('/^(?:0|254)(\d{3})\*{2,4}(\d{3})$/', $masked, $m)) {
        return [];
    }
    $prefix = $m[1]; // 3 digits right after the leading 0 or 254
    $suffix = $m[2]; // last 3 visible digits

    // Exact-length match first: 254 + prefix(3) + ___ (3 masked) + suffix(3) = 12 digits
    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $stmt->execute(['254' . $prefix . '___' . $suffix]);
    $students = $stmt->fetchAll();

    if ($students) {
        return $students;
    }

    // Fallback with a looser wildcard, in case a number isn't stored at the usual length
    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_phone, parent_name, admission_number FROM students WHERE REPLACE(parent_phone, '+', '') LIKE ?");
    $stmt->execute(['254' . $prefix . '%' . $suffix]);
    return $stmt->fetchAll();
}

function distributePayment($pdo, $students, &$amountRemaining, $receipt, $paymentMethod, $categoryFilter = null) {
    $processed = 0;

    foreach ($students as $student) {
        if ($amountRemaining <= 0) break;

        // Fetch unpaid bills
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

            // Update Billing
            $upd = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = CURDATE() WHERE id = ?");
            $upd->execute([$newPaid, $status, $bill['id']]);

            // Insert History
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

// Helpers ends here
?>
