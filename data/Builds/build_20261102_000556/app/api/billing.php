<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
error_reporting(0);
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms_helper.php';

$x_0001 = $_GET['action'] ?? '';
$x_0002 = ($x_0003['role'] ?? '') === 'Admin';

function sync_category_billing($x_0004, $x_0005, $x_0006) {
    $x_0007 = getSettings($x_0004);
    $x_0008 = $x_0007['academic_year'] ?? date('Y');
    $x_0009 = $x_0007['current_term'] ?? 'Term 1';

    $x_000a = $x_0004->prepare("SELECT * FROM billing_categories WHERE id = ?");
    $x_000a->execute([$x_0005]);
    $x_000b = $x_000a->fetch();
    if (!$x_000b || (!$x_000b['is_active'] ?? false)) return;

    $x_000c = floatval($x_000b['default_amount']);
    $x_000d = $x_000b['assignment_type'];
    $x_000e = json_decode($x_000b['assigned_classes'] ?: '[]', true) ?: [];
    $x_000f = is_array($x_000e) && !empty($x_000e) && (array_keys($x_000e) !== range(0, count($x_000e) - 1));

    $x_0010 = $x_0004->query("SELECT id, class FROM students");
    $x_0011 = $x_0010->fetchAll();

    foreach ($x_0011 as $x_0012) {
        $x_0013 = false;
        $x_0014 = $x_000c;

        if ($x_000d === 'Global') {
            $x_0013 = true;
        } else {
            if ($x_000f) {
                if (array_key_exists($x_0012['class'], $x_000e)) {
                    $x_0013 = true;

                    if (is_numeric($x_000e[$x_0012['class']])) {
                        $x_0014 = floatval($x_000e[$x_0012['class']]);
                    }
                }
            } else {
                if (in_array($x_0012['class'], $x_000e)) {
                    $x_0013 = true;
                }
            }
        }

        if ($x_0013) {
            $x_0015 = $x_0004->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)
            ");
            $x_0015->execute([$x_0012['id'], $x_0005, $x_0008, $x_0009, $x_0014, $x_0006]);

            $x_0016 = $x_0004->prepare("
                UPDATE student_billing 
                SET amount_due = ? 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $x_0016->execute([$x_0014, $x_0012['id'], $x_0005, $x_0008, $x_0009]);

        } else {
            $x_0017 = $x_0004->prepare("
                DELETE FROM student_billing 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $x_0017->execute([$x_0012['id'], $x_0005, $x_0008, $x_0009]);
        }
    }
}

function sync_student_billing($x_0004, $x_0018, $x_0019, $x_0006) {
    $x_0007 = getSettings($x_0004);
    $x_0008 = $x_0007['academic_year'] ?? date('Y');
    $x_0009 = $x_0007['current_term'] ?? 'Term 1';

    $x_001a = $x_0004->query("SELECT * FROM billing_categories WHERE is_active = 1");
    $x_001b = $x_001a->fetchAll();

    foreach ($x_001b as $x_000b) {
        $x_000c = floatval($x_000b['default_amount']);
        $x_000d = $x_000b['assignment_type'];
        $x_000e = json_decode($x_000b['assigned_classes'] ?: '[]', true) ?: [];
        $x_000f = is_array($x_000e) && !empty($x_000e) && (array_keys($x_000e) !== range(0, count($x_000e) - 1));

        $x_0013 = false;
        $x_0014 = $x_000c;

        if ($x_000d === 'Global') {
            $x_0013 = true;
        } else {
            if ($x_000f) {
                if (array_key_exists($x_0019, $x_000e)) {
                    $x_0013 = true;
                    if (is_numeric($x_000e[$x_0019])) {
                        $x_0014 = floatval($x_000e[$x_0019]);
                    }
                }
            } else {
                if (in_array($x_0019, $x_000e)) {
                    $x_0013 = true;
                }
            }
        }

        if ($x_0013) {
            $x_0015 = $x_0004->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, 0, 'Unpaid', ?)
            ");
            $x_0015->execute([$x_0018, $x_000b['id'], $x_0008, $x_0009, $x_0006]);
        } else {
            $x_0017 = $x_0004->prepare("
                DELETE FROM student_billing 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $x_0017->execute([$x_0018, $x_000b['id'], $x_0008, $x_0009]);
        }
    }
}

function getSettings($x_0004) {
    $x_000a = $x_0004->query("SELECT setting_key, setting_value FROM settings");
    $x_001c = [];
    while ($r = $x_000a->fetch()) $x_001c[$r['setting_key']] = $r['setting_value'];
    return $x_001c;
}

function computeDynamicStatus($x_001d, $x_001e, $x_001f) {
    if ($x_001d === 'Paid' || $x_001d === 'Exempt') {
        return $x_001d;
    }
    if (empty($x_001e)) {
        return $x_001d;
    }
    try {
        $x_0020 = new DateTime('today');
        $x_0021 = new DateTime($x_001e);
        $x_0021->setTime(0, 0, 0);
        $x_0022 = intval($x_001f);
        $x_0023 = (clone $x_0021)->modify("+$x_0022 days");
        
        if ($x_0020 > $x_0023) {
            return 'Overdue';
        } elseif ($x_0020 > $x_0021 && $x_0020 <= $x_0023) {
            return 'Grace Period';
        }
    } catch (Exception $e) {}
    
    return $x_001d;
}

function computePaymentStatus($x_0024, $x_0025) {
    if ($x_0024 <= 0) return 'Paid';
    $x_0026 = ($x_0025 / $x_0024) * 100;
    if ($x_0026 >= 100) return 'Paid';
    if ($x_0026 > 0) return 'Partial';
    return 'Unpaid';
}

function calculatePercentage($x_0024, $x_0025) {
    if ($x_0024 <= 0) return 100;
    $x_0026 = ($x_0025 / $x_0024) * 100;
    return round(min(100, $x_0026), 0);
}

function recordDetailedHistory($x_0004, $x_0027, $x_0028, $x_0001, $x_0029, $x_002a, $x_002b, $x_002c, $x_002d) {
    $x_002e = $x_0028['amount_due'] - $x_0028['amount_paid'];
    $x_0026 = calculatePercentage($x_0028['amount_due'], $x_0028['amount_paid']);
    $x_000a = $x_0004->prepare("
        INSERT INTO billing_payment_history
          (billing_id, student_id, category_id, academic_year, school_term,
           amount_due, amount_paid, balance, payment_status, payment_date,
           due_date, notes, action, performed_by,
           transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $x_000a->execute([
        $x_0027,
        $x_0028['student_id'],
        $x_0028['category_id'],
        $x_0028['academic_year'],
        $x_0028['school_term'],
        $x_0028['amount_due'],
        $x_0028['amount_paid'],
        $x_002e,
        $x_0028['payment_status'],
        $x_0028['payment_date'],
        $x_0028['due_date'],
        $x_0028['notes'],
        $x_0001,
        $x_0029,
        $x_002a,
        $x_002b,
        $x_0026,
        $x_002c,
        $x_002d
    ]);
}

function recordHistory($x_0004, $x_0027, $x_0028, $x_0001, $x_0029) {
    recordDetailedHistory($x_0004, $x_0027, $x_0028, $x_0001, $x_0029, 0.00, 0.00, null, null);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($x_0001 === 'poll_payments') {
        $x_002f = intval($_GET['last_unalloc_id'] ?? 0);
        $x_0030 = intval($_GET['last_history_id'] ?? 0);
        
        $x_0031 = ['status' => 'success', 'new_unallocated' => [], 'new_assigned' => []];
        
        try {

            $x_000a = $x_0004->prepare("SELECT id, sender_name, amount, receipt_number, created_at FROM unallocated_payments WHERE id > ? AND status = 'Unallocated'");
            $x_000a->execute([$x_002f]);
            $x_0031['new_unallocated'] = $x_000a->fetchAll(PDO::FETCH_ASSOC);

            $x_000a = $x_0004->prepare("
                SELECT h.id, h.transaction_amount as amount, h.reference_number as receipt_number, h.performed_at as created_at,
                       s.full_name as student_name, s.class, s.parent_name
                FROM billing_payment_history h
                JOIN students s ON h.student_id = s.id
                WHERE h.id > ? AND h.payment_method = 'M-Pesa' AND h.action IN ('PAYMENT_RECORDED', 'ADVANCE_PAYMENT')
            ");
            $x_000a->execute([$x_0030]);
            $x_0031['new_assigned'] = $x_000a->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('poll_payments DB error: ' . $e->getMessage());
            $x_0031['status'] = 'error';
            $x_0031['message'] = 'Database error polling payments.';
        }
        
        echo json_encode($x_0031);
        exit;
    }

    if ($x_0001 === 'get_categories') {
        $x_000a = $x_0004->query("
            SELECT bc.*,
                   (SELECT COUNT(*) FROM student_billing sb WHERE sb.category_id = bc.id) AS usage_count
            FROM billing_categories bc
            ORDER BY bc.sort_order ASC, bc.id ASC
        ");
        echo json_encode(['status' => 'success', 'data' => $x_000a->fetchAll()]);
        exit;
    }

    if ($x_0001 === 'get_student_billing') {
        $x_0018 = intval($_GET['student_id'] ?? 0);
        if (!$x_0018) {
            echo json_encode(['status' => 'error', 'message' => 'student_id required']);
            exit;
        }
        try {
            $x_000a = $x_0004->prepare("
                SELECT sb.*,
                       bc.name          AS category_name,
                       bc.is_mandatory,
                       bc.is_active     AS category_active,
                       bc.due_date      AS cat_due_date,
                       bc.grace_period_days AS cat_grace_period,
                       u.name           AS updated_by_name,
                       (sb.amount_due - sb.amount_paid) AS balance
                FROM student_billing sb
                JOIN billing_categories bc ON sb.category_id = bc.id
                LEFT JOIN users u          ON sb.updated_by  = u.id
                WHERE sb.student_id = ?
                ORDER BY bc.sort_order ASC, sb.academic_year DESC, sb.school_term ASC
            ");
            $x_000a->execute([$x_0018]);
            $x_0032 = $x_000a->fetchAll(PDO::FETCH_ASSOC);
            foreach ($x_0032 as &$x_0033) {
                $x_0034 = $x_0033['due_date'] ?: $x_0033['cat_due_date'];
                $x_0033['dynamic_status'] = computeDynamicStatus($x_0033['payment_status'], $x_0034, $x_0033['cat_grace_period']);
                $x_0033['payment_percentage'] = calculatePercentage((float)$x_0033['amount_due'], (float)$x_0033['amount_paid']);
            }
            echo json_encode(['status' => 'success', 'data' => $x_0032]);
        } catch (Throwable $e) {
            error_log("get_student_billing Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($x_0001 === 'payment_history') {
        $x_0018 = intval($_GET['student_id'] ?? 0);
        if (!$x_0018) {
            echo json_encode(['status' => 'error', 'message' => 'student_id required']);
            exit;
        }
        $x_000a = $x_0004->prepare("
            SELECT bph.*,
                   bc.name AS category_name,
                   u.name  AS performed_by_name
            FROM billing_payment_history bph
            JOIN billing_categories bc ON bph.category_id = bc.id
            LEFT JOIN users u          ON bph.performed_by = u.id
            WHERE bph.student_id = ?
            ORDER BY bph.performed_at DESC
        ");
        $x_000a->execute([$x_0018]);
        echo json_encode(['status' => 'success', 'data' => $x_000a->fetchAll()]);
        exit;
    }

    if ($x_0001 === 'billing_dashboard') {
        $x_0007 = getSettings($x_0004);
        $x_0008 = $x_0007['academic_year'] ?? date('Y');
        $x_0009 = $x_0007['current_term'] ?? 'Term 1';

        $x_000a = $x_0004->query("
            SELECT bc.id,
                   bc.name,
                   bc.sort_order,
                   COALESCE(SUM(sb.amount_due),  0) AS expected,
                   COALESCE(SUM(sb.amount_paid), 0) AS collected,
                   SUM(CASE WHEN sb.payment_status = 'Paid'    THEN 1 ELSE 0 END) AS paid_count,
                   SUM(CASE WHEN sb.payment_status = 'Partial' THEN 1 ELSE 0 END) AS partial_count,
                   SUM(CASE WHEN sb.payment_status = 'Unpaid'  THEN 1 ELSE 0 END) AS unpaid_count,
                   SUM(CASE WHEN sb.payment_status = 'Exempt'  THEN 1 ELSE 0 END) AS exempt_count
            FROM billing_categories bc
            LEFT JOIN student_billing sb ON sb.category_id = bc.id
            WHERE bc.is_active = 1
            GROUP BY bc.id
            ORDER BY bc.sort_order ASC
        ");
        $x_001b = $x_000a->fetchAll();

        foreach ($x_001b as &$x_000b) {
            $x_000b['outstanding'] = round($x_000b['expected'] - $x_000b['collected'], 2);
        }
        unset($x_000b);

        echo json_encode(['status' => 'success', 'data' => $x_001b, 'current_year' => $x_0008, 'current_term' => $x_0009]);
        exit;
    }

    if ($x_0001 === 'billing_report') {
        $x_0005   = intval($_GET['category_id']   ?? 0);
        $x_0035  = trim($_GET['class']           ?? '');
        $x_0036   = trim($_GET['academic_year']   ?? '');
        $x_0037   = trim($_GET['school_term']     ?? '');
        $x_0038 = trim($_GET['payment_status']  ?? '');

        $x_0039  = ['1=1'];
        $x_003a = [];

        if ($x_0005) { $x_0039[] = 'sb.category_id = ?';    $x_003a[] = $x_0005; }
        if ($x_0035) { $x_0039[] = 's.class = ?';           $x_003a[] = $x_0035; }
        if ($x_0036)  { $x_0039[] = 'sb.academic_year = ?';  $x_003a[] = $x_0036; }
        if ($x_0037)  { $x_0039[] = 'sb.school_term = ?';    $x_003a[] = $x_0037; }
        if ($x_0038){ $x_0039[] = 'sb.payment_status = ?'; $x_003a[] = $x_0038; }

        $x_003b = "
            SELECT s.id AS student_id,
                   s.full_name, s.admission_number, s.class,
                   bc.name         AS category_name,
                   sb.academic_year, sb.school_term,
                   sb.amount_due, sb.amount_paid,
                   (sb.amount_due - sb.amount_paid) AS balance,
                   sb.payment_status, sb.due_date, sb.payment_date,
                   sb.notes,
                   u.name AS updated_by_name,
                   sb.updated_at
            FROM student_billing sb
            JOIN students s              ON sb.student_id  = s.id
            JOIN billing_categories bc   ON sb.category_id = bc.id
            LEFT JOIN users u            ON sb.updated_by  = u.id
            WHERE " . implode(' AND ', $x_0039) . "
            ORDER BY s.class ASC, s.full_name ASC
        ";
        $x_000a = $x_0004->prepare($x_003b);
        $x_000a->execute($x_003a);

        echo json_encode(['status' => 'success', 'data' => $x_000a->fetchAll()]);
        exit;
    }

    if ($x_0001 === 'students_billing_list') {
        $x_0035  = trim($_GET['class']          ?? '');
        $x_0036   = trim($_GET['academic_year']  ?? '');
        $x_0037   = trim($_GET['school_term']    ?? '');
        $x_0038 = trim($_GET['payment_status'] ?? '');
        $x_003c    = intval($_GET['category_id']  ?? 0);

        $x_0039  = ['1=1'];
        $x_003a = [];

        $x_003d = [];

        $x_003b = "
            SELECT s.id, s.full_name, s.admission_number, s.class, s.photo,
                   GROUP_CONCAT(DISTINCT bc.name ORDER BY bc.sort_order SEPARATOR '||') AS cat_names,
                   GROUP_CONCAT(DISTINCT sb.payment_status ORDER BY bc.sort_order SEPARATOR '||') AS cat_statuses,
                   GROUP_CONCAT(DISTINCT sb.category_id ORDER BY bc.sort_order SEPARATOR '||') AS cat_ids,
                   GROUP_CONCAT(DISTINCT sb.amount_due ORDER BY bc.sort_order SEPARATOR '||') AS cat_dues,
                   GROUP_CONCAT(DISTINCT sb.amount_paid ORDER BY bc.sort_order SEPARATOR '||') AS cat_paids,
                   GROUP_CONCAT(DISTINCT sb.id ORDER BY bc.sort_order SEPARATOR '||') AS billing_ids,
                   SUM(sb.amount_due) AS total_due,
                   SUM(sb.amount_paid) AS total_paid
            FROM students s
            LEFT JOIN student_billing sb ON sb.student_id = s.id
            LEFT JOIN billing_categories bc ON sb.category_id = bc.id AND bc.is_active = 1
        ";

        if ($x_0035) { $x_0039[] = 's.class = ?'; $x_003a[] = $x_0035; }
        if ($x_0036)  { $x_0039[] = '(sb.academic_year = ? OR sb.academic_year IS NULL)'; $x_003a[] = $x_0036; }
        if ($x_0037)  { $x_0039[] = '(sb.school_term = ? OR sb.school_term IS NULL)'; $x_003a[] = $x_0037; }
        if ($x_0038){ $x_0039[] = 'sb.payment_status = ?'; $x_003a[] = $x_0038; }
        if ($x_003c)   { $x_0039[] = 'sb.category_id = ?'; $x_003a[] = $x_003c; }

        $x_003b .= ' WHERE ' . implode(' AND ', $x_0039);
        $x_003b .= ' GROUP BY s.id ORDER BY s.class ASC, s.full_name ASC';

        $x_000a = $x_0004->prepare($x_003b);
        $x_000a->execute($x_003a);
        echo json_encode(['status' => 'success', 'data' => $x_000a->fetchAll()]);
        exit;
    }

    if ($x_0001 === 'get_unallocated') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_000a = $x_0004->query("SELECT * FROM unallocated_payments WHERE status = 'Unallocated' ORDER BY payment_date DESC");
        echo json_encode(['status' => 'success', 'data' => $x_000a->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($x_0001 === 'get_student_suggestions') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        
        $x_003e = trim($_GET['sender_name'] ?? '');
        $x_003f = trim($_GET['sender_phone'] ?? '');
        
        $x_0040 = [];

        if (!empty($x_003f)) {
            $x_000a = $x_0004->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE parent_phone = ?");
            $x_000a->execute([$x_003f]);
            while ($x_0041 = $x_000a->fetch()) {
                $x_0041['match_reason'] = 'Parent Phone Match';
                $x_0040[$x_0041['id']] = $x_0041;
            }
        }

        if (!empty($x_003e)) {
            $x_0042 = explode(' ', $x_003e);
            foreach ($x_0042 as $x_0043) {
                if (strlen($x_0043) > 2) {
                    $x_000a = $x_0004->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE parent_name LIKE ? AND id NOT IN (0" . (empty($x_0040) ? "" : ",".implode(",", array_keys($x_0040))) . ")");
                    $x_000a->execute(["%$x_0043%"]);
                    while ($x_0041 = $x_000a->fetch()) {
                        $x_0041['match_reason'] = "Parent Name Match ($x_0043)";
                        $x_0040[$x_0041['id']] = $x_0041;
                    }

                    $x_000a = $x_0004->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE full_name LIKE ? AND id NOT IN (0" . (empty($x_0040) ? "" : ",".implode(",", array_keys($x_0040))) . ")");
                    $x_000a->execute(["%$x_0043%"]);
                    while ($x_0041 = $x_000a->fetch()) {
                        $x_0041['match_reason'] = "Student Name Match ($x_0043)";
                        $x_0040[$x_0041['id']] = $x_0041;
                    }
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'data' => array_values($x_0040)]);
        exit;
    }

    if ($x_0001 === 'poll_mpesa_notifications') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        
        $x_0044 = $_GET['last_time'] ?? date('Y-m-d H:i:s', time() - 30);
        $x_0045 = [];

        $x_0046 = $x_0004->prepare("
            SELECT bph.transaction_amount as amount, s.full_name as student_name, bph.performed_at as created_at
            FROM billing_payment_history bph
            JOIN students s ON bph.student_id = s.id
            WHERE bph.payment_method = 'M-Pesa' AND bph.performed_at > ?
        ");
        $x_0046->execute([$x_0044]);
        $x_0047 = $x_0046->fetchAll(PDO::FETCH_ASSOC);
        foreach ($x_0047 as $x_0041) {
            $x_0045[] = [
                'message' => "Payment of Ksh " . number_format($x_0041['amount']) . " received via M-Pesa for " . $x_0041['student_name'] . "!",
                'type' => 'allocated',
                'created_at' => $x_0041['created_at']
            ];
        }

        $x_0048 = $x_0004->prepare("
            SELECT amount, sender_name, created_at 
            FROM unallocated_payments 
            WHERE created_at > ?
        ");
        $x_0048->execute([$x_0044]);
        $x_0049 = $x_0048->fetchAll(PDO::FETCH_ASSOC);
        foreach ($x_0049 as $x_0041) {
            $x_0045[] = [
                'message' => "Unallocated M-Pesa payment of Ksh " . number_format($x_0041['amount']) . " received from " . $x_0041['sender_name'] . ".",
                'type' => 'unallocated',
                'created_at' => $x_0041['created_at']
            ];
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $x_0045,
            'server_time' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($x_0001 === 'save_category') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id          = intval($_POST['id'] ?? 0);
        $x_004a        = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_004b = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_000c      = floatval($_POST['default_amount'] ?? 0);
        $x_004c   = intval($_POST['is_mandatory'] ?? 0) ? 1 : 0;
        $x_004d      = intval($_POST['is_active'] ?? 1) ? 1 : 0;
        $x_004e = intval($_POST['enforce_on_scanner'] ?? 0) ? 1 : 0;
        $x_004f   = intval($_POST['sort_order'] ?? 0);
        $x_000d = $_POST['assignment_type'] === 'Per Class' ? 'Per Class' : 'Global';

        $x_000e = $_POST['assigned_classes'] ?? '[]';

        if (json_decode($x_000e) === null) {
            $x_000e = '[]';
        }

        $x_001e = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $x_0050 = intval($_POST['grace_period_days'] ?? 0);

        if (empty($x_004a)) {
            echo json_encode(['status' => 'error', 'message' => 'Category name is required.']);
            exit;
        }

        try {
            if ($id) {
                $x_000a = $x_0004->prepare("
                    UPDATE billing_categories
                    SET name=?, description=?, default_amount=?, is_mandatory=?, is_active=?, enforce_on_scanner=?, sort_order=?, assignment_type=?, assigned_classes=?, due_date=?, grace_period_days=?
                    WHERE id=?
                ");
                $x_000a->execute([$x_004a, $x_004b, $x_000c, $x_004c, $x_004d, $x_004e, $x_004f, $x_000d, $x_000e, $x_001e, $x_0050, $id]);
                $x_0005 = $id;
                log_audit($x_0004, $x_0003['id'], 'BILLING_EDIT_CATEGORY', "Edited category ID $id: $x_004a");
                $x_0051 = 'Category updated successfully.';
            } else {
                $x_000a = $x_0004->prepare("
                    INSERT INTO billing_categories (name, description, default_amount, is_mandatory, is_active, enforce_on_scanner, sort_order, assignment_type, assigned_classes, due_date, grace_period_days, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $x_000a->execute([$x_004a, $x_004b, $x_000c, $x_004c, $x_004d, $x_004e, $x_004f, $x_000d, $x_000e, $x_001e, $x_0050, $x_0003['id']]);
                $x_0005 = $x_0004->lastInsertId();
                log_audit($x_0004, $x_0003['id'], 'BILLING_ADD_CATEGORY', "Added category: $x_004a");
                $x_0051 = 'Category created successfully.';
            }

            sync_category_billing($x_0004, $x_0005, $x_0003['id']);

            echo json_encode(['status' => 'success', 'message' => $x_0051]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                echo json_encode(['status' => 'error', 'message' => 'A category with this name already exists.']);
            } else {
                error_log("Billing save_category error: " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
            }
        }
        exit;
    }

    if ($x_0001 === 'toggle_category') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id     = intval($_POST['id'] ?? 0);
        $x_004d = intval($_POST['is_active'] ?? 0) ? 1 : 0;
        try {
            $x_000a = $x_0004->prepare("UPDATE billing_categories SET is_active=? WHERE id=?");
            $x_000a->execute([$x_004d, $id]);
            $x_0052 = $x_004d ? 'enabled' : 'disabled';
            log_audit($x_0004, $x_0003['id'], 'BILLING_TOGGLE_CATEGORY', "Category $id $x_0052");
            echo json_encode(['status' => 'success', 'message' => "Category $x_0052 successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($x_0001 === 'delete_category') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        try {
            $x_000a = $x_0004->prepare("SELECT COUNT(*) AS cnt FROM student_billing WHERE category_id=?");
            $x_000a->execute([$id]);
            $x_0053 = $x_000a->fetch()['cnt'];
            if ($x_0053 > 0) {
                echo json_encode(['status' => 'error', 'message' => "Cannot delete: this category has $x_0053 billing record(s). Disable it instead."]);
                exit;
            }
            $x_000a = $x_0004->prepare("DELETE FROM billing_categories WHERE id=?");
            $x_000a->execute([$id]);
            log_audit($x_0004, $x_0003['id'], 'BILLING_DELETE_CATEGORY', "Deleted category ID $id");
            echo json_encode(['status' => 'success', 'message' => 'Category deleted.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($x_0001 === 'assign_billing') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0018  = intval($_POST['student_id']  ?? 0);
        $x_0005 = intval($_POST['category_id'] ?? 0);
        $x_0007   = getSettings($x_0004);
        $x_0008       = htmlspecialchars(trim($_POST['academic_year'] ?? $x_0007['academic_year'] ?? date('Y')), ENT_QUOTES, 'UTF-8');
        $x_0009       = htmlspecialchars(trim($_POST['school_term']   ?? $x_0007['current_term'] ?? 'Term 1'), ENT_QUOTES, 'UTF-8');
        $x_001e    = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $x_0054      = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (!$x_0018 || !$x_0005) {
            echo json_encode(['status' => 'error', 'message' => 'student_id and category_id required.']);
            exit;
        }
        try {

            $x_0055 = $x_0004->prepare("SELECT default_amount FROM billing_categories WHERE id=?");
            $x_0055->execute([$x_0005]);
            $x_000b = $x_0055->fetch();
            if (!$x_000b) {
                echo json_encode(['status' => 'error', 'message' => 'Category not found.']);
                exit;
            }
            $x_0024 = floatval($_POST['amount_due'] ?? $x_000b['default_amount']);

            $x_000a = $x_0004->prepare("
                INSERT INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, due_date, notes, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE amount_due=VALUES(amount_due), updated_by=VALUES(updated_by)
            ");
            $x_000a->execute([$x_0018, $x_0005, $x_0008, $x_0009, $x_0024, $x_001e, $x_0054, $x_0003['id']]);
            $x_0027 = $x_0004->lastInsertId() ?: $x_000a->fetchColumn();

            $x_0056 = $x_0004->prepare("SELECT * FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
            $x_0056->execute([$x_0018, $x_0005, $x_0008, $x_0009]);
            $x_0041 = $x_0056->fetch();
            if ($x_0041) recordHistory($x_0004, $x_0041['id'], $x_0041, 'ASSIGNED', $x_0003['id']);

            log_audit($x_0004, $x_0003['id'], 'BILLING_ASSIGN', "Assigned category $x_0005 to student $x_0018");
            echo json_encode(['status' => 'success', 'message' => 'Billing assigned.']);
        } catch (PDOException $e) {
            error_log("Billing assign error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($x_0001 === 'update_billing') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0027    = intval($_POST['billing_id'] ?? 0);
        $x_0024    = floatval($_POST['amount_due']   ?? 0);
        $x_0025   = floatval($_POST['amount_paid']  ?? 0);
        $x_0052       = $_POST['payment_status']  ?? 'Unpaid';
        $x_001e      = !empty($_POST['due_date'])     ? $_POST['due_date']     : null;
        $x_0057  = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
        $x_0054        = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        $x_0058 = ['Unpaid', 'Partial', 'Paid', 'Exempt'];
        if (!in_array($x_0052, $x_0058)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payment status.']);
            exit;
        }

        try {
            $x_000a = $x_0004->prepare("
                UPDATE student_billing
                SET amount_due=?, amount_paid=?, payment_status=?, due_date=?, payment_date=?, notes=?, updated_by=?
                WHERE id=?
            ");
            $x_000a->execute([$x_0024, $x_0025, $x_0052, $x_001e, $x_0057, $x_0054, $x_0003['id'], $x_0027]);

            $x_0056 = $x_0004->prepare("SELECT * FROM student_billing WHERE id=?");
            $x_0056->execute([$x_0027]);
            $x_0041 = $x_0056->fetch();
            if ($x_0041) recordHistory($x_0004, $x_0027, $x_0041, 'PAYMENT_UPDATED', $x_0003['id']);

            log_audit($x_0004, $x_0003['id'], 'BILLING_UPDATE', "Updated billing record $x_0027 → $x_0052");
            echo json_encode(['status' => 'success', 'message' => 'Billing record updated.']);
        } catch (PDOException $e) {
            error_log("Billing update error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($x_0001 === 'record_payment') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0027    = intval($_POST['billing_id'] ?? 0);
        $x_0059 = floatval($_POST['amount_being_paid'] ?? 0);
        $x_0057  = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
        $x_002c = htmlspecialchars(trim($_POST['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_002d = htmlspecialchars(trim($_POST['reference_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0054        = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        if ($x_0059 <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Payment amount must be greater than zero.']);
            exit;
        }

        try {
            $x_0004->beginTransaction();

            $x_0056 = $x_0004->prepare("SELECT * FROM student_billing WHERE id=? FOR UPDATE");
            $x_0056->execute([$x_0027]);
            $x_0041 = $x_0056->fetch(PDO::FETCH_ASSOC);

            if (!$x_0041) {
                echo json_encode(['status' => 'error', 'message' => 'Billing record not found.']);
                exit;
            }

            $x_0024 = floatval($x_0041['amount_due']);
            $x_005a = floatval($x_0041['amount_paid']);
            $x_002b = $x_0024 - $x_005a;

            if ($x_0059 > $x_002b) {
                echo json_encode(['status' => 'error', 'message' => 'Payment amount cannot exceed remaining balance.']);
                exit;
            }

            $x_005b = $x_005a + $x_0059;

            $x_0052 = $x_0041['payment_status'];
            if ($x_0052 !== 'Exempt') {
                $x_0052 = computePaymentStatus($x_0024, $x_005b);
            }

            $x_000a = $x_0004->prepare("
                UPDATE student_billing
                SET amount_paid=?, payment_status=?, payment_date=?, updated_by=?
                WHERE id=?
            ");
            $x_000a->execute([$x_005b, $x_0052, $x_0057, $x_0003['id'], $x_0027]);

            if (!empty($x_0054)) {
                $x_005c = empty($x_0041['notes']) ? $x_0054 : $x_0041['notes'] . "\n" . $x_0054;
                $x_000a = $x_0004->prepare("UPDATE student_billing SET notes=? WHERE id=?");
                $x_000a->execute([$x_005c, $x_0027]);
                $x_0041['notes'] = $x_005c;
            }

            $x_0041['amount_paid'] = $x_005b;
            $x_0041['payment_status'] = $x_0052;
            $x_0041['payment_date'] = $x_0057;
            
            recordDetailedHistory($x_0004, $x_0027, $x_0041, 'PAYMENT_RECORDED', $x_0003['id'], $x_0059, $x_002b, $x_002c, $x_002d);

            log_audit($x_0004, $x_0003['id'], 'BILLING_PAYMENT', "Recorded payment of $x_0059 for billing record $x_0027");

            $x_0004->commit();

            try {
                $x_005d = $x_0004->prepare("SELECT id, full_name, admission_number, parent_name, class, gender, parent_phone FROM students WHERE id = (SELECT student_id FROM student_billing WHERE id = ?)");
                $x_005d->execute([$x_0027]);
                $x_005e = $x_005d->fetch(PDO::FETCH_ASSOC);

                if ($x_005e && !empty($x_005e['parent_phone'])) {
                    $x_005f = $x_0004->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_billing', 'sms_template_payment')");
                    $x_0060 = [];
                    while ($s = $x_005f->fetch()) {
                        $x_0060[$s['setting_key']] = $s['setting_value'];
                    }

                    $x_0061 = $x_0060['sms_api_token'] ?? '';
                    $x_0062 = $x_0060['sms_sender_id'] ?? '';
                    $x_0063 = $x_0060['school_name'] ?? 'School System';

                    $x_0064 = $x_0060['sms_enable_billing'] ?? '1';
                    if (!empty($x_0061) && ($x_0064 === 'true' || $x_0064 === '1')) {
                        $x_0065 = $x_002b - $x_0059;
                        $x_001d = ($x_0065 <= 0) ? "paid" : "partially paid";
                        $x_0066 = !empty($x_0063) ? $x_0063 : "School Admin";
                        
                        $x_0051 = "Hello, thanks for the progress you have made with the recent fee payment for " . $x_005e['full_name'] . " (" . $x_005e['class'] . "). The account is now " . $x_001d . ". We appreciate your support. - " . $x_0066;
                        
                        if (!empty($x_0060['sms_template_payment'])) {
                            $x_0051 = str_replace(
                                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Amount Paid}', '{Balance}', '{Payment Date}', '{TIME}'],
                                [$x_005e['full_name'], $x_005e['parent_name'] ?? '', $x_005e['admission_number'] ?? '', $x_005e['class'], $x_0066, number_format($x_0059, 2), number_format($x_0065, 2), date('Y-m-d'), date('h:i A')],
                                $x_0060['sms_template_payment']
                            );
                        }
                        
                        $x_0067 = sendBulkSMS([$x_005e['parent_phone']], $x_0051, $x_0062, $x_0061);
                        
                        $x_0068 = (isset($x_0067['success']) && $x_0067['success'] === true) ? 'Sent' : 'Failed';
                        $x_0069 = json_encode($x_0067);
                        $x_006a = $x_0067['error'] ?? '';

                        $x_006b = $x_0004->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $x_006b->execute([
                            $x_0028['student_id'] ?? 0,
                            $x_005e['parent_phone'],
                            $x_0051,
                            $x_0068,
                            $x_0069,
                            $x_006a
                        ]);
                    }
                }
            } catch (Throwable $ex) {
                error_log("SMS sending failed: " . $ex->getMessage());
            }

            $x_006c = "";
            if (isset($x_0068)) {
                if ($x_0068 === 'Sent') {
                    $x_006c = " SMS notification was sent successfully.";
                } else {
                    $x_006c = " However, SMS notification failed to send.";
                }
            } else {
                if (empty($x_005e['parent_phone'])) {
                    $x_006c = " (No SMS sent: Parent phone number is missing).";
                } elseif (empty($x_0061)) {
                    $x_006c = " (No SMS sent: SMS API token not configured in Settings).";
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully.' . $x_006c]);
        } catch (Throwable $e) {
            if ($x_0004->inTransaction()) {
                $x_0004->rollBack();
            }
            error_log("Billing payment error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($x_0001 === 'remove_billing') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0027 = intval($_POST['billing_id'] ?? 0);
        try {

            $x_0056 = $x_0004->prepare("SELECT * FROM student_billing WHERE id=?");
            $x_0056->execute([$x_0027]);
            $x_0041 = $x_0056->fetch();
            if ($x_0041) recordHistory($x_0004, $x_0027, $x_0041, 'REMOVED', $x_0003['id']);

            $x_000a = $x_0004->prepare("DELETE FROM student_billing WHERE id=?");
            $x_000a->execute([$x_0027]);
            log_audit($x_0004, $x_0003['id'], 'BILLING_REMOVE', "Removed billing record $x_0027");
            echo json_encode(['status' => 'success', 'message' => 'Billing removed.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($x_0001 === 'bulk_billing') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }

        $x_006d = json_decode($_POST['student_ids'] ?? '[]', true);
        $x_006e = $_POST['bulk_action'] ?? '';
        $x_0005 = intval($_POST['category_id'] ?? 0);
        $x_0007   = getSettings($x_0004);
        $x_0008       = $x_0007['academic_year'] ?? date('Y');
        $x_0009       = $x_0007['current_term']  ?? 'Term 1';
        $x_0054      = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0025 = floatval($_POST['amount_paid'] ?? 0);

        if (empty($x_006d) || !is_array($x_006d)) {
            echo json_encode(['status' => 'error', 'message' => 'No students selected.']);
            exit;
        }

        $x_006f = 0;
        $x_0070   = [];

        try {
            $x_0004->beginTransaction();

            foreach ($x_006d as $x_0071) {
                $x_0071 = intval($x_0071);
                if (!$x_0071) continue;

                if (in_array($x_006e, ['assign_meals', 'assign_transport'])) {

                    if (!$x_0005) {
                        $x_0072 = ($x_006e === 'assign_meals') ? 'Meals' : 'Transport';
                        $x_0055 = $x_0004->prepare("SELECT id, default_amount FROM billing_categories WHERE name=? LIMIT 1");
                        $x_0055->execute([$x_0072]);
                        $x_000b = $x_0055->fetch();
                        if (!$x_000b) continue;
                        $x_0073 = $x_000b['id'];
                        $x_0074 = $x_000b['default_amount'];
                    } else {
                        $x_0055 = $x_0004->prepare("SELECT default_amount FROM billing_categories WHERE id=?");
                        $x_0055->execute([$x_0005]);
                        $x_000b = $x_0055->fetch();
                        $x_0073 = $x_0005;
                        $x_0074 = $x_000b ? $x_000b['default_amount'] : 0;
                    }
                    $x_000a = $x_0004->prepare("
                        INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $x_000a->execute([$x_0071, $x_0073, $x_0008, $x_0009, $x_0074, $x_0003['id']]);

                } elseif (in_array($x_006e, ['remove_meals', 'remove_transport'])) {
                    $x_0072 = ($x_006e === 'remove_meals') ? 'Meals' : 'Transport';
                    $x_0055 = $x_0004->prepare("SELECT id FROM billing_categories WHERE name=? LIMIT 1");
                    $x_0055->execute([$x_0072]);
                    $x_000b = $x_0055->fetch();
                    if (!$x_000b) continue;

                    $x_0075 = $x_0004->prepare("SELECT * FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
                    $x_0075->execute([$x_0071, $x_000b['id'], $x_0008, $x_0009]);
                    $x_0041 = $x_0075->fetch();
                    if ($x_0041) recordHistory($x_0004, $x_0041['id'], $x_0041, 'BULK_REMOVED', $x_0003['id']);

                    $x_000a = $x_0004->prepare("DELETE FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
                    $x_000a->execute([$x_0071, $x_000b['id'], $x_0008, $x_0009]);

                } elseif (in_array($x_006e, ['mark_paid', 'mark_partial', 'mark_unpaid', 'mark_exempt'])) {
                    $x_0076 = [
                        'mark_paid'    => 'Paid',
                        'mark_partial' => 'Partial',
                        'mark_unpaid'  => 'Unpaid',
                        'mark_exempt'  => 'Exempt',
                    ];
                    $x_0077 = $x_0076[$x_006e];

                    $x_0078 = $x_0005 ? 'AND category_id = ?' : '';
                    $x_0079 = $x_0005 ? [$x_0071, $x_0008, $x_0009, $x_0005] : [$x_0071, $x_0008, $x_0009];

                    $x_007a = "SELECT * FROM student_billing WHERE student_id=? AND academic_year=? AND school_term=? $x_0078";
                    $x_0075 = $x_0004->prepare($x_007a);
                    $x_0075->execute($x_0079);
                    $x_007b = $x_0075->fetchAll();

                    foreach ($x_007b as $x_0041) {
                        $x_007c = ($x_0077 === 'Paid' || $x_0077 === 'Partial') ? date('Y-m-d') : null;
                        $x_007d    = ($x_0077 === 'Paid') ? $x_0041['amount_due'] : ($x_0077 === 'Partial' ? $x_0025 : 0);

                        $x_007e = $x_0004->prepare("
                            UPDATE student_billing
                            SET payment_status=?, payment_date=?, amount_paid=?, notes=?, updated_by=?
                            WHERE id=?
                        ");
                        $x_007e->execute([$x_0077, $x_007c, $x_007d, $x_0054, $x_0003['id'], $x_0041['id']]);

                        $x_0041['payment_status'] = $x_0077;
                        $x_0041['payment_date']   = $x_007c;
                        $x_0041['amount_paid']    = $x_007d;
                        recordHistory($x_0004, $x_0041['id'], $x_0041, 'BULK_STATUS_CHANGED', $x_0003['id']);
                    }
                }
                $x_006f++;
            }

            $x_0004->commit();
            log_audit($x_0004, $x_0003['id'], 'BILLING_BULK', "Bulk action '$x_006e' on " . count($x_006d) . " students");
            echo json_encode(['status' => 'success', 'message' => "Bulk action completed. $x_006f student(s) processed."]);

        } catch (PDOException $e) {
            if ($x_0004->inTransaction()) {
                $x_0004->rollBack();
            }
            error_log("Billing bulk error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error during bulk operation.']);
        }
        exit;
    }

    if ($x_0001 === 'assign_unallocated') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_007f = intval($_POST['unallocated_id'] ?? 0);
        $x_0018 = intval($_POST['student_id'] ?? 0);
        $x_0080 = $_POST['category_ids'] ?? '[]';
        $x_0081 = json_decode($x_0080, true);
        if (!is_array($x_0081)) $x_0081 = [];

        $x_0081 = array_map('intval', $x_0081);
        $x_0081 = array_filter($x_0081);
        
        if (!$x_007f || !$x_0018) {
            echo json_encode(['status' => 'error', 'message' => 'Unallocated ID and Student ID are required.']);
            exit;
        }
        
        try {
            $x_0004->beginTransaction();

            $x_000a = $x_0004->prepare("SELECT * FROM unallocated_payments WHERE id = ? FOR UPDATE");
            $x_000a->execute([$x_007f]);
            $x_0049 = $x_000a->fetch();
            
            if (!$x_0049 || $x_0049['status'] !== 'Unallocated') {
                echo json_encode(['status' => 'error', 'message' => 'Payment not found or already allocated.']);
                exit;
            }
            
            $x_0082 = floatval($x_0049['amount']);
            $x_0083 = $x_0049['receipt_number'];
            $x_0057 = explode(' ', $x_0049['payment_date'])[0]; // Get YYYY-MM-DD

            $x_000a = $x_0004->prepare("SELECT * FROM students WHERE id = ?");
            $x_000a->execute([$x_0018]);
            $x_0012 = $x_000a->fetch();
            
            if (!$x_0012) {
                echo json_encode(['status' => 'error', 'message' => 'Student not found.']);
                exit;
            }

            $x_003b = "SELECT sb.*, bc.name as cat_name 
                    FROM student_billing sb 
                    JOIN billing_categories bc ON sb.category_id = bc.id 
                    WHERE sb.student_id = ?";
            
            $x_003a = [$x_0018];
            if (!empty($x_0081)) {
                $x_0084 = implode(',', array_fill(0, count($x_0081), '?'));
                $x_003b .= " AND sb.category_id IN ($x_0084)";
                $x_003a = array_merge($x_003a, $x_0081);
            }
            $x_003b .= " ORDER BY (sb.payment_status IN ('Unpaid', 'Partial')) DESC, bc.sort_order ASC, sb.due_date ASC";
            
            $x_000a = $x_0004->prepare($x_003b);
            $x_000a->execute($x_003a);
            $x_0085 = $x_000a->fetchAll();
            
            $x_0086 = $x_0082;
            $x_0087 = null;
            
            foreach ($x_0085 as $x_0088) {
                if ($x_0086 <= 0) break;

                $x_0024 = floatval($x_0088['amount_due']);
                $x_005a = floatval($x_0088['amount_paid']);
                $x_002e = $x_0024 - $x_005a;

                if ($x_002e <= 0) continue;

                $x_0089 = min($x_002e, $x_0086);
                $x_005b = $x_005a + $x_0089;
                $x_0065 = $x_0024 - $x_005b;
                
                $x_0052 = ($x_0065 <= 0) ? 'Paid' : 'Partial';

                $x_008a = $x_0004->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                $x_008a->execute([$x_005b, $x_0052, $x_0057, $x_0088['id']]);

                $x_0026 = ($x_0024 > 0) ? round(($x_005b / $x_0024) * 100, 2) : 100;
                $x_008b = $x_0004->prepare("
                    INSERT INTO billing_payment_history
                    (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PAYMENT_RECORDED', ?, ?, ?, 'M-Pesa', ?)
                ");
                $x_008b->execute([
                    $x_0088['id'], $x_0012['id'], $x_0088['category_id'], $x_0088['academic_year'], $x_0088['school_term'],
                    $x_0024, $x_005b, $x_0065, $x_0052, $x_0057, $x_0088['due_date'], 
                    $x_0089, $x_002e, $x_0026, $x_0083
                ]);

                $x_0086 -= $x_0089;
                $x_0087 = $x_0088;
            }

            if ($x_0086 > 0) {

                $x_008c = $x_0087;
                if (!$x_008c && count($x_0085) > 0) {
                    $x_008c = $x_0085[0]; // If no unpaid bills existed, just use the first bill fetched
                }
                
                if ($x_008c) {
                    $x_0024 = floatval($x_008c['amount_due']);

                    $x_008d = $x_0004->prepare("SELECT amount_paid FROM student_billing WHERE id = ?");
                    $x_008d->execute([$x_008c['id']]);
                    $x_005a = floatval($x_008d->fetchColumn());
                    
                    $x_002e = $x_0024 - $x_005a;
                    
                    $x_005b = $x_005a + $x_0086;
                    $x_0065 = $x_0024 - $x_005b; // Will be negative (overpaid)
                    $x_0052 = 'Paid'; // Still paid

                    $x_008a = $x_0004->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                    $x_008a->execute([$x_005b, $x_0052, $x_0057, $x_008c['id']]);

                    $x_0026 = ($x_0024 > 0) ? round(($x_005b / $x_0024) * 100, 2) : 100;
                    $x_008b = $x_0004->prepare("
                        INSERT INTO billing_payment_history
                        (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OVERPAYMENT_RECORDED', ?, ?, ?, 'M-Pesa', ?)
                    ");
                    $x_008b->execute([
                        $x_008c['id'], $x_0012['id'], $x_008c['category_id'], $x_008c['academic_year'], $x_008c['school_term'],
                        $x_0024, $x_005b, $x_0065, $x_0052, $x_0057, $x_008c['due_date'], 
                        $x_0086, $x_002e, $x_0026, $x_0083
                    ]);
                    
                    $x_0086 = 0;
                }
            }

            $x_008e = $x_0004->prepare("UPDATE unallocated_payments SET status = 'Allocated', allocated_student_id = ? WHERE id = ?");
            $x_008e->execute([$x_0018, $x_007f]);

            if (function_exists('sendPaymentSMS')) {

                $x_008f = $x_0004->prepare("SELECT SUM(amount_due - amount_paid) FROM student_billing WHERE student_id = ? AND payment_status IN ('Unpaid', 'Partial')");
                $x_008f->execute([$x_0018]);
                $x_0090 = floatval($x_008f->fetchColumn());
                
                sendPaymentSMS($x_0004, $x_0012, $x_0082, $x_0090, 'Processed');
            }
            
            log_audit($x_0004, $x_0003['id'], 'BILLING_ALLOCATE', "Allocated $x_0082 (Receipt: $x_0083) to student $x_0018");
            
            $x_0004->commit();
            echo json_encode(['status' => 'success', 'message' => 'Payment allocated successfully.']);
        } catch (PDOException $e) {
            if ($x_0004->inTransaction()) {
                $x_0004->rollBack();
            }
            error_log("Allocate error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($x_0001 === 'delete_unallocated') {
        if (!$x_0002) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_007f = intval($_POST['id'] ?? 0);
        $x_000a = $x_0004->prepare("DELETE FROM unallocated_payments WHERE id = ?");
        $x_000a->execute([$x_007f]);
        echo json_encode(['status' => 'success', 'message' => 'Record deleted.']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown POST action.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);

