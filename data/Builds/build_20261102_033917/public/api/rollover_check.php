<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (!isset($pdo)) return;

if (defined('ROLLOVER_CHECKED')) return;
define('ROLLOVER_CHECKED', true);

try {

    $stmt = $pdo->query("SELECT * FROM academic_terms WHERE status = 'Active' LIMIT 1");
    $x_0001 = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$x_0001) return; // No active term to roll over from

    $x_0002 = date('Y-m-d');

    if ($x_0001['end_date'] < $x_0002) {

        $x_0003 = $pdo->query("SELECT * FROM academic_terms WHERE status = 'Upcoming' ORDER BY start_date ASC LIMIT 1");
        $x_0004 = $x_0003->fetch(PDO::FETCH_ASSOC);

        if ($x_0004) {

            $pdo->beginTransaction();

            $pdo->exec("UPDATE academic_terms SET status = 'Completed' WHERE id = " . intval($x_0001['id']));
            $pdo->exec("UPDATE academic_terms SET status = 'Active' WHERE id = " . intval($x_0004['id']));

            $x_0005 = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $x_0005->execute([$x_0004['academic_year'], 'academic_year']);
            $x_0005->execute([$x_0004['term_name'], 'current_term']);

            $x_0006 = $pdo->query("SELECT id, default_amount, due_date FROM billing_categories");
            $x_0007 = [];
            while ($x_0008 = $x_0006->fetch(PDO::FETCH_ASSOC)) {
                $x_0007[$x_0008['id']] = $x_0008;
            }

            $x_0009 = $pdo->prepare("SELECT * FROM student_billing WHERE academic_year = ? AND school_term = ?");
            $x_0009->execute([$x_0001['academic_year'], $x_0001['term_name']]);
            $x_000a = $x_0009->fetchAll(PDO::FETCH_ASSOC);

            $x_000b = $pdo->prepare("
                INSERT INTO student_billing 
                (student_id, category_id, academic_year, school_term, amount_due, amount_paid, payment_status, due_date, notes, updated_by)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
            ");

            $x_000c = $pdo->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, due_date, notes, action, performed_by, transaction_amount, previous_balance, payment_percentage)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'ROLLOVER_CARRIED_FORWARD', ?, 0, 0, ?)
            ");

            $x_000d = isset($x_000e['id']) ? $x_000e['id'] : null;

            foreach ($x_000a as $x_000f) {

                $x_0010 = floatval($x_000f['amount_due']) - floatval($x_000f['amount_paid']);
                if ($x_0010 < 0) $x_0010 = 0; // Don't carry forward negative balances (overpayments) unless requested, assuming 0 for now.

                $x_0011 = $x_000f['category_id'];
                $x_0012 = isset($x_0007[$x_0011]) ? floatval($x_0007[$x_0011]['default_amount']) : 0.00;
                $x_0013 = isset($x_0007[$x_0011]) ? $x_0007[$x_0011]['due_date'] : null;

                $x_0014 = $x_0012 + $x_0010;

                $x_0015 = ($x_0014 <= 0) ? 'Paid' : 'Unpaid';
                if ($x_000f['payment_status'] === 'Exempt') {
                    $x_0015 = 'Exempt';
                    $x_0014 = 0;
                }

                $x_0016 = "Automated Rollover.\nCarried Forward Arrears: KES " . number_format($x_0010, 2) . "\nNew Term Base Fee: KES " . number_format($x_0012, 2);

                $x_000b->execute([
                    $x_000f['student_id'],
                    $x_0011,
                    $x_0004['academic_year'],
                    $x_0004['term_name'],
                    $x_0014,
                    $x_0015,
                    $x_0013,
                    $x_0016,
                    $x_000d
                ]);

                $x_0017 = $pdo->lastInsertId();

                $x_0018 = ($x_0014 <= 0) ? 100 : 0;
                $x_000c->execute([
                    $x_0017,
                    $x_000f['student_id'],
                    $x_0011,
                    $x_0004['academic_year'],
                    $x_0004['term_name'],
                    $x_0014,
                    $x_0014, // balance
                    $x_0015,
                    $x_0013,
                    $x_0016,
                    $x_000d,
                    $x_0018
                ]);
            }

            $x_0019 = $pdo->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $x_0019->execute([
                $x_000d,
                'TERM_ROLLOVER',
                "System automatically rolled over from {$x_0001['term_name']} to {$x_0004['term_name']}.",
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
