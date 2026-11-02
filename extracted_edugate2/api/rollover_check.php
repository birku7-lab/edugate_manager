<?php
/**
 * api/rollover_check.php
 * Automatically checks and executes Academic Term Rollovers.
 * Intended to be included silently during authenticated API requests.
 */

if (!isset($pdo)) return;

// We use a constant to prevent running this multiple times per request lifecycle
if (defined('ROLLOVER_CHECKED')) return;
define('ROLLOVER_CHECKED', true);

try {
    // 1. Check if the current Active term has ended
    $stmt = $pdo->query("SELECT * FROM academic_terms WHERE status = 'Active' LIMIT 1");
    $activeTerm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeTerm) return; // No active term to roll over from

    $today = date('Y-m-d');
    
    // If the active term's end date has passed
    if ($activeTerm['end_date'] < $today) {
        
        // 2. Look for the next Upcoming term
        $nextStmt = $pdo->query("SELECT * FROM academic_terms WHERE status = 'Upcoming' ORDER BY start_date ASC LIMIT 1");
        $nextTerm = $nextStmt->fetch(PDO::FETCH_ASSOC);

        if ($nextTerm) {
            // We have a rollover condition!
            $pdo->beginTransaction();

            // 3. Update Term Statuses
            $pdo->exec("UPDATE academic_terms SET status = 'Completed' WHERE id = " . intval($activeTerm['id']));
            $pdo->exec("UPDATE academic_terms SET status = 'Active' WHERE id = " . intval($nextTerm['id']));

            // 4. Update Settings Table to match
            $updateSet = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $updateSet->execute([$nextTerm['academic_year'], 'academic_year']);
            $updateSet->execute([$nextTerm['term_name'], 'current_term']);

            // 5. Pre-fetch Category Defaults
            $catStmt = $pdo->query("SELECT id, default_amount, due_date FROM billing_categories");
            $categories = [];
            while ($cat = $catStmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[$cat['id']] = $cat;
            }

            // 6. Carry Forward Billing Records
            $billingStmt = $pdo->prepare("SELECT * FROM student_billing WHERE academic_year = ? AND school_term = ?");
            $billingStmt->execute([$activeTerm['academic_year'], $activeTerm['term_name']]);
            $oldRecords = $billingStmt->fetchAll(PDO::FETCH_ASSOC);

            $insertBilling = $pdo->prepare("
                INSERT INTO student_billing 
                (student_id, category_id, academic_year, school_term, amount_due, amount_paid, payment_status, due_date, notes, updated_by)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
            ");

            $insertHistory = $pdo->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, due_date, notes, action, performed_by, transaction_amount, previous_balance, payment_percentage)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'ROLLOVER_CARRIED_FORWARD', ?, 0, 0, ?)
            ");

            // System User ID for automated actions
            $systemUserId = isset($current_user['id']) ? $current_user['id'] : null;

            foreach ($oldRecords as $old) {
                // Calculate Arrears
                $arrears = floatval($old['amount_due']) - floatval($old['amount_paid']);
                if ($arrears < 0) $arrears = 0; // Don't carry forward negative balances (overpayments) unless requested, assuming 0 for now.

                // Get New Term Default
                $catId = $old['category_id'];
                $defaultFee = isset($categories[$catId]) ? floatval($categories[$catId]['default_amount']) : 0.00;
                $newDueDate = isset($categories[$catId]) ? $categories[$catId]['due_date'] : null;

                // Total New Amount Due
                $newAmountDue = $defaultFee + $arrears;

                // Status
                $newStatus = ($newAmountDue <= 0) ? 'Paid' : 'Unpaid';
                if ($old['payment_status'] === 'Exempt') {
                    $newStatus = 'Exempt';
                    $newAmountDue = 0;
                }

                $notes = "Automated Rollover.\nCarried Forward Arrears: KES " . number_format($arrears, 2) . "\nNew Term Base Fee: KES " . number_format($defaultFee, 2);

                // Insert New Billing Record
                $insertBilling->execute([
                    $old['student_id'],
                    $catId,
                    $nextTerm['academic_year'],
                    $nextTerm['term_name'],
                    $newAmountDue,
                    $newStatus,
                    $newDueDate,
                    $notes,
                    $systemUserId
                ]);

                $newBillingId = $pdo->lastInsertId();

                // Log History for New Record
                $percentage = ($newAmountDue <= 0) ? 100 : 0;
                $insertHistory->execute([
                    $newBillingId,
                    $old['student_id'],
                    $catId,
                    $nextTerm['academic_year'],
                    $nextTerm['term_name'],
                    $newAmountDue,
                    $newAmountDue, // balance
                    $newStatus,
                    $newDueDate,
                    $notes,
                    $systemUserId,
                    $percentage
                ]);
            }

            // Log Audit
            $auditStmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $auditStmt->execute([
                $systemUserId,
                'TERM_ROLLOVER',
                "System automatically rolled over from {$activeTerm['term_name']} to {$nextTerm['term_name']}.",
                $_SERVER['REMOTE_ADDR'] ?? 'SYSTEM'
            ]);

            $pdo->commit();
        }
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Rollover Check Failed: " . $e->getMessage());
}
?>
