<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (!isset($pdo)) return;

if (defined('ROLLOVER_CHECKED')) return;
define('ROLLOVER_CHECKED', true);

try {

    $stmt = $pdo->query("SELECT * FROM academic_terms WHERE status = 'Active' LIMIT 1");
    $activeTerm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeTerm) return; // No active term to roll over from

    $today = date('Y-m-d');

    if ($activeTerm['end_date'] < $today) {

        $nextStmt = $pdo->query("SELECT * FROM academic_terms WHERE status = 'Upcoming' ORDER BY start_date ASC LIMIT 1");
        $nextTerm = $nextStmt->fetch(PDO::FETCH_ASSOC);

        if ($nextTerm) {

            $pdo->beginTransaction();

            $pdo->exec("UPDATE academic_terms SET status = 'Completed' WHERE id = " . intval($activeTerm['id']));
            $pdo->exec("UPDATE academic_terms SET status = 'Active' WHERE id = " . intval($nextTerm['id']));

            $updateSet = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $updateSet->execute([$nextTerm['academic_year'], 'academic_year']);
            $updateSet->execute([$nextTerm['term_name'], 'current_term']);

            $catStmt = $pdo->query("SELECT id, default_amount, due_date FROM billing_categories");
            $categories = [];
            while ($cat = $catStmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[$cat['id']] = $cat;
            }

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

            $systemUserId = isset($current_user['id']) ? $current_user['id'] : null;

            foreach ($oldRecords as $old) {

                $arrears = floatval($old['amount_due']) - floatval($old['amount_paid']);
                if ($arrears < 0) $arrears = 0; // Don't carry forward negative balances (overpayments) unless requested, assuming 0 for now.

                $catId = $old['category_id'];
                $defaultFee = isset($categories[$catId]) ? floatval($categories[$catId]['default_amount']) : 0.00;
                $newDueDate = isset($categories[$catId]) ? $categories[$catId]['due_date'] : null;

                $newAmountDue = $defaultFee + $arrears;

                $newStatus = ($newAmountDue <= 0) ? 'Paid' : 'Unpaid';
                if ($old['payment_status'] === 'Exempt') {
                    $newStatus = 'Exempt';
                    $newAmountDue = 0;
                }

                $notes = "Automated Rollover.\nCarried Forward Arrears: KES " . number_format($arrears, 2) . "\nNew Term Base Fee: KES " . number_format($defaultFee, 2);

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
