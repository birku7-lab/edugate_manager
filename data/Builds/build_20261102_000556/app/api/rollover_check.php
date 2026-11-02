<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (!isset($x_0001)) return;

if (defined('ROLLOVER_CHECKED')) return;
define('ROLLOVER_CHECKED', true);

try {

    $x_0002 = $x_0001->query("SELECT * FROM academic_terms WHERE status = 'Active' LIMIT 1");
    $x_0003 = $x_0002->fetch(PDO::FETCH_ASSOC);

    if (!$x_0003) return; // No active term to roll over from

    $x_0004 = date('Y-m-d');

    if ($x_0003['end_date'] < $x_0004) {

        $x_0005 = $x_0001->query("SELECT * FROM academic_terms WHERE status = 'Upcoming' ORDER BY start_date ASC LIMIT 1");
        $x_0006 = $x_0005->fetch(PDO::FETCH_ASSOC);

        if ($x_0006) {

            $x_0001->beginTransaction();

            $x_0001->exec("UPDATE academic_terms SET status = 'Completed' WHERE id = " . intval($x_0003['id']));
            $x_0001->exec("UPDATE academic_terms SET status = 'Active' WHERE id = " . intval($x_0006['id']));

            $x_0007 = $x_0001->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $x_0007->execute([$x_0006['academic_year'], 'academic_year']);
            $x_0007->execute([$x_0006['term_name'], 'current_term']);

            $x_0008 = $x_0001->query("SELECT id, default_amount, due_date FROM billing_categories");
            $x_0009 = [];
            while ($x_000a = $x_0008->fetch(PDO::FETCH_ASSOC)) {
                $x_0009[$x_000a['id']] = $x_000a;
            }

            $x_000b = $x_0001->prepare("SELECT * FROM student_billing WHERE academic_year = ? AND school_term = ?");
            $x_000b->execute([$x_0003['academic_year'], $x_0003['term_name']]);
            $x_000c = $x_000b->fetchAll(PDO::FETCH_ASSOC);

            $x_000d = $x_0001->prepare("
                INSERT INTO student_billing 
                (student_id, category_id, academic_year, school_term, amount_due, amount_paid, payment_status, due_date, notes, updated_by)
                VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
            ");

            $x_000e = $x_0001->prepare("
                INSERT INTO billing_payment_history
                (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, due_date, notes, action, performed_by, transaction_amount, previous_balance, payment_percentage)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'ROLLOVER_CARRIED_FORWARD', ?, 0, 0, ?)
            ");

            $x_000f = isset($x_0010['id']) ? $x_0010['id'] : null;

            foreach ($x_000c as $x_0011) {

                $x_0012 = floatval($x_0011['amount_due']) - floatval($x_0011['amount_paid']);
                if ($x_0012 < 0) $x_0012 = 0; // Don't carry forward negative balances (overpayments) unless requested, assuming 0 for now.

                $x_0013 = $x_0011['category_id'];
                $x_0014 = isset($x_0009[$x_0013]) ? floatval($x_0009[$x_0013]['default_amount']) : 0.00;
                $x_0015 = isset($x_0009[$x_0013]) ? $x_0009[$x_0013]['due_date'] : null;

                $x_0016 = $x_0014 + $x_0012;

                $x_0017 = ($x_0016 <= 0) ? 'Paid' : 'Unpaid';
                if ($x_0011['payment_status'] === 'Exempt') {
                    $x_0017 = 'Exempt';
                    $x_0016 = 0;
                }

                $x_0018 = "Automated Rollover.\nCarried Forward Arrears: KES " . number_format($x_0012, 2) . "\nNew Term Base Fee: KES " . number_format($x_0014, 2);

                $x_000d->execute([
                    $x_0011['student_id'],
                    $x_0013,
                    $x_0006['academic_year'],
                    $x_0006['term_name'],
                    $x_0016,
                    $x_0017,
                    $x_0015,
                    $x_0018,
                    $x_000f
                ]);

                $x_0019 = $x_0001->lastInsertId();

                $x_001a = ($x_0016 <= 0) ? 100 : 0;
                $x_000e->execute([
                    $x_0019,
                    $x_0011['student_id'],
                    $x_0013,
                    $x_0006['academic_year'],
                    $x_0006['term_name'],
                    $x_0016,
                    $x_0016, // balance
                    $x_0017,
                    $x_0015,
                    $x_0018,
                    $x_000f,
                    $x_001a
                ]);
            }

            $x_001b = $x_0001->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $x_001b->execute([
                $x_000f,
                'TERM_ROLLOVER',
                "System automatically rolled over from {$x_0003['term_name']} to {$x_0006['term_name']}.",
                $_SERVER['REMOTE_ADDR'] ?? 'SYSTEM'
            ]);

            $x_0001->commit();
        }
    }

} catch (PDOException $e) {
    if (isset($x_0001) && $x_0001->inTransaction()) {
        $x_0001->rollBack();
    }
    error_log("Rollover Check Failed: " . $e->getMessage());
}
?>
