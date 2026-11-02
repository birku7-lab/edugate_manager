<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
error_reporting(0);
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms_helper.php';

$action = $_GET['action'] ?? '';
$x_0001 = ($x_0002['role'] ?? '') === 'Admin';

function sync_category_billing($pdo, $x_0003, $userId) {
    $x_0004 = getSettings($pdo);
    $x_0005 = $x_0004['academic_year'] ?? date('Y');
    $x_0006 = $x_0004['current_term'] ?? 'Term 1';

    $stmt = $pdo->prepare("SELECT * FROM billing_categories WHERE id = ?");
    $stmt->execute([$x_0003]);
    $x_0007 = $stmt->fetch();
    if (!$x_0007 || (!$x_0007['is_active'] ?? false)) return;

    $x_0008 = floatval($x_0007['default_amount']);
    $x_0009 = $x_0007['assignment_type'];
    $x_000a = json_decode($x_0007['assigned_classes'] ?: '[]', true) ?: [];
    $x_000b = is_array($x_000a) && !empty($x_000a) && (array_keys($x_000a) !== range(0, count($x_000a) - 1));

    $x_000c = $pdo->query("SELECT id, class FROM students");
    $x_000d = $x_000c->fetchAll();

    foreach ($x_000d as $x_000e) {
        $x_000f = false;
        $x_0010 = $x_0008;

        if ($x_0009 === 'Global') {
            $x_000f = true;
        } else {
            if ($x_000b) {
                if (array_key_exists($x_000e['class'], $x_000a)) {
                    $x_000f = true;

                    if (is_numeric($x_000a[$x_000e['class']])) {
                        $x_0010 = floatval($x_000a[$x_000e['class']]);
                    }
                }
            } else {
                if (in_array($x_000e['class'], $x_000a)) {
                    $x_000f = true;
                }
            }
        }

        if ($x_000f) {
            $x_0011 = $pdo->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)
            ");
            $x_0011->execute([$x_000e['id'], $x_0003, $x_0005, $x_0006, $x_0010, $userId]);

            $x_0012 = $pdo->prepare("
                UPDATE student_billing 
                SET amount_due = ? 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $x_0012->execute([$x_0010, $x_000e['id'], $x_0003, $x_0005, $x_0006]);

        } else {
            $x_0013 = $pdo->prepare("
                DELETE FROM student_billing 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $x_0013->execute([$x_000e['id'], $x_0003, $x_0005, $x_0006]);
        }
    }
}

function sync_student_billing($pdo, $x_0014, $x_0015, $userId) {
    $x_0004 = getSettings($pdo);
    $x_0005 = $x_0004['academic_year'] ?? date('Y');
    $x_0006 = $x_0004['current_term'] ?? 'Term 1';

    $x_0016 = $pdo->query("SELECT * FROM billing_categories WHERE is_active = 1");
    $x_0017 = $x_0016->fetchAll();

    foreach ($x_0017 as $x_0007) {
        $x_0008 = floatval($x_0007['default_amount']);
        $x_0009 = $x_0007['assignment_type'];
        $x_000a = json_decode($x_0007['assigned_classes'] ?: '[]', true) ?: [];
        $x_000b = is_array($x_000a) && !empty($x_000a) && (array_keys($x_000a) !== range(0, count($x_000a) - 1));

        $x_000f = false;
        $x_0010 = $x_0008;

        if ($x_0009 === 'Global') {
            $x_000f = true;
        } else {
            if ($x_000b) {
                if (array_key_exists($x_0015, $x_000a)) {
                    $x_000f = true;
                    if (is_numeric($x_000a[$x_0015])) {
                        $x_0010 = floatval($x_000a[$x_0015]);
                    }
                }
            } else {
                if (in_array($x_0015, $x_000a)) {
                    $x_000f = true;
                }
            }
        }

        if ($x_000f) {
            $x_0011 = $pdo->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, 0, 'Unpaid', ?)
            ");
            $x_0011->execute([$x_0014, $x_0007['id'], $x_0005, $x_0006, $userId]);
        } else {
            $x_0013 = $pdo->prepare("
                DELETE FROM student_billing 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $x_0013->execute([$x_0014, $x_0007['id'], $x_0005, $x_0006]);
        }
    }
}

function getSettings($pdo) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $x_0018 = [];
    while ($r = $stmt->fetch()) $x_0018[$r['setting_key']] = $r['setting_value'];
    return $x_0018;
}

function computeDynamicStatus($x_0019, $x_001a, $x_001b) {
    if ($x_0019 === 'Paid' || $x_0019 === 'Exempt') {
        return $x_0019;
    }
    if (empty($x_001a)) {
        return $x_0019;
    }
    try {
        $x_001c = new DateTime('today');
        $x_001d = new DateTime($x_001a);
        $x_001d->setTime(0, 0, 0);
        $x_001e = intval($x_001b);
        $x_001f = (clone $x_001d)->modify("+$x_001e days");
        
        if ($x_001c > $x_001f) {
            return 'Overdue';
        } elseif ($x_001c > $x_001d && $x_001c <= $x_001f) {
            return 'Grace Period';
        }
    } catch (Exception $e) {}
    
    return $x_0019;
}

function computePaymentStatus($x_0020, $x_0021) {
    if ($x_0020 <= 0) return 'Paid';
    $x_0022 = ($x_0021 / $x_0020) * 100;
    if ($x_0022 >= 100) return 'Paid';
    if ($x_0022 > 0) return 'Partial';
    return 'Unpaid';
}

function calculatePercentage($x_0020, $x_0021) {
    if ($x_0020 <= 0) return 100;
    $x_0022 = ($x_0021 / $x_0020) * 100;
    return round(min(100, $x_0022), 0);
}

function recordDetailedHistory($pdo, $x_0023, $x_0024, $action, $x_0025, $x_0026, $x_0027, $x_0028, $x_0029) {
    $x_002a = $x_0024['amount_due'] - $x_0024['amount_paid'];
    $x_0022 = calculatePercentage($x_0024['amount_due'], $x_0024['amount_paid']);
    $stmt = $pdo->prepare("
        INSERT INTO billing_payment_history
          (billing_id, student_id, category_id, academic_year, school_term,
           amount_due, amount_paid, balance, payment_status, payment_date,
           due_date, notes, action, performed_by,
           transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $x_0023,
        $x_0024['student_id'],
        $x_0024['category_id'],
        $x_0024['academic_year'],
        $x_0024['school_term'],
        $x_0024['amount_due'],
        $x_0024['amount_paid'],
        $x_002a,
        $x_0024['payment_status'],
        $x_0024['payment_date'],
        $x_0024['due_date'],
        $x_0024['notes'],
        $action,
        $x_0025,
        $x_0026,
        $x_0027,
        $x_0022,
        $x_0028,
        $x_0029
    ]);
}

function recordHistory($pdo, $x_0023, $x_0024, $action, $x_0025) {
    recordDetailedHistory($pdo, $x_0023, $x_0024, $action, $x_0025, 0.00, 0.00, null, null);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($action === 'poll_payments') {
        $x_002b = intval($_GET['last_unalloc_id'] ?? 0);
        $x_002c = intval($_GET['last_history_id'] ?? 0);
        
        $response = ['status' => 'success', 'new_unallocated' => [], 'new_assigned' => []];
        
        try {

            $stmt = $pdo->prepare("SELECT id, sender_name, amount, receipt_number, created_at FROM unallocated_payments WHERE id > ? AND status = 'Unallocated'");
            $stmt->execute([$x_002b]);
            $response['new_unallocated'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT h.id, h.transaction_amount as amount, h.reference_number as receipt_number, h.performed_at as created_at,
                       s.full_name as student_name, s.class, s.parent_name
                FROM billing_payment_history h
                JOIN students s ON h.student_id = s.id
                WHERE h.id > ? AND h.payment_method = 'M-Pesa' AND h.action IN ('PAYMENT_RECORDED', 'ADVANCE_PAYMENT')
            ");
            $stmt->execute([$x_002c]);
            $response['new_assigned'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log('poll_payments DB error: ' . $e->getMessage());
            $response['status'] = 'error';
            $response['message'] = 'Database error polling payments.';
        }
        
        echo json_encode($response);
        exit;
    }

    if ($action === 'get_categories') {
        $stmt = $pdo->query("
            SELECT bc.*,
                   (SELECT COUNT(*) FROM student_billing sb WHERE sb.category_id = bc.id) AS usage_count
            FROM billing_categories bc
            ORDER BY bc.sort_order ASC, bc.id ASC
        ");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'get_student_billing') {
        $x_0014 = intval($_GET['student_id'] ?? 0);
        if (!$x_0014) {
            echo json_encode(['status' => 'error', 'message' => 'student_id required']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
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
            $stmt->execute([$x_0014]);
            $x_002d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($x_002d as &$x_002e) {
                $x_002f = $x_002e['due_date'] ?: $x_002e['cat_due_date'];
                $x_002e['dynamic_status'] = computeDynamicStatus($x_002e['payment_status'], $x_002f, $x_002e['cat_grace_period']);
                $x_002e['payment_percentage'] = calculatePercentage((float)$x_002e['amount_due'], (float)$x_002e['amount_paid']);
            }
            echo json_encode(['status' => 'success', 'data' => $x_002d]);
        } catch (Throwable $e) {
            error_log("get_student_billing Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'payment_history') {
        $x_0014 = intval($_GET['student_id'] ?? 0);
        if (!$x_0014) {
            echo json_encode(['status' => 'error', 'message' => 'student_id required']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT bph.*,
                   bc.name AS category_name,
                   u.name  AS performed_by_name
            FROM billing_payment_history bph
            JOIN billing_categories bc ON bph.category_id = bc.id
            LEFT JOIN users u          ON bph.performed_by = u.id
            WHERE bph.student_id = ?
            ORDER BY bph.performed_at DESC
        ");
        $stmt->execute([$x_0014]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'billing_dashboard') {
        $x_0004 = getSettings($pdo);
        $x_0005 = $x_0004['academic_year'] ?? date('Y');
        $x_0006 = $x_0004['current_term'] ?? 'Term 1';

        $stmt = $pdo->query("
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
        $x_0017 = $stmt->fetchAll();

        foreach ($x_0017 as &$x_0007) {
            $x_0007['outstanding'] = round($x_0007['expected'] - $x_0007['collected'], 2);
        }
        unset($x_0007);

        echo json_encode(['status' => 'success', 'data' => $x_0017, 'current_year' => $x_0005, 'current_term' => $x_0006]);
        exit;
    }

    if ($action === 'billing_report') {
        $x_0003   = intval($_GET['category_id']   ?? 0);
        $x_0030  = trim($_GET['class']           ?? '');
        $x_0031   = trim($_GET['academic_year']   ?? '');
        $x_0032   = trim($_GET['school_term']     ?? '');
        $x_0033 = trim($_GET['payment_status']  ?? '');

        $x_0034  = ['1=1'];
        $params = [];

        if ($x_0003) { $x_0034[] = 'sb.category_id = ?';    $params[] = $x_0003; }
        if ($x_0030) { $x_0034[] = 's.class = ?';           $params[] = $x_0030; }
        if ($x_0031)  { $x_0034[] = 'sb.academic_year = ?';  $params[] = $x_0031; }
        if ($x_0032)  { $x_0034[] = 'sb.school_term = ?';    $params[] = $x_0032; }
        if ($x_0033){ $x_0034[] = 'sb.payment_status = ?'; $params[] = $x_0033; }

        $sql = "
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
            WHERE " . implode(' AND ', $x_0034) . "
            ORDER BY s.class ASC, s.full_name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'students_billing_list') {
        $x_0030  = trim($_GET['class']          ?? '');
        $x_0031   = trim($_GET['academic_year']  ?? '');
        $x_0032   = trim($_GET['school_term']    ?? '');
        $x_0033 = trim($_GET['payment_status'] ?? '');
        $x_0035    = intval($_GET['category_id']  ?? 0);

        $x_0034  = ['1=1'];
        $params = [];

        $x_0036 = [];

        $sql = "
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

        if ($x_0030) { $x_0034[] = 's.class = ?'; $params[] = $x_0030; }
        if ($x_0031)  { $x_0034[] = '(sb.academic_year = ? OR sb.academic_year IS NULL)'; $params[] = $x_0031; }
        if ($x_0032)  { $x_0034[] = '(sb.school_term = ? OR sb.school_term IS NULL)'; $params[] = $x_0032; }
        if ($x_0033){ $x_0034[] = 'sb.payment_status = ?'; $params[] = $x_0033; }
        if ($x_0035)   { $x_0034[] = 'sb.category_id = ?'; $params[] = $x_0035; }

        $sql .= ' WHERE ' . implode(' AND ', $x_0034);
        $sql .= ' GROUP BY s.id ORDER BY s.class ASC, s.full_name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'get_unallocated') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $stmt = $pdo->query("SELECT * FROM unallocated_payments WHERE status = 'Unallocated' ORDER BY payment_date DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get_student_suggestions') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        
        $x_0037 = trim($_GET['sender_name'] ?? '');
        $x_0038 = trim($_GET['sender_phone'] ?? '');
        
        $x_0039 = [];

        if (!empty($x_0038)) {
            $stmt = $pdo->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE parent_phone = ?");
            $stmt->execute([$x_0038]);
            while ($row = $stmt->fetch()) {
                $row['match_reason'] = 'Parent Phone Match';
                $x_0039[$row['id']] = $row;
            }
        }

        if (!empty($x_0037)) {
            $x_003a = explode(' ', $x_0037);
            foreach ($x_003a as $x_003b) {
                if (strlen($x_003b) > 2) {
                    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE parent_name LIKE ? AND id NOT IN (0" . (empty($x_0039) ? "" : ",".implode(",", array_keys($x_0039))) . ")");
                    $stmt->execute(["%$x_003b%"]);
                    while ($row = $stmt->fetch()) {
                        $row['match_reason'] = "Parent Name Match ($x_003b)";
                        $x_0039[$row['id']] = $row;
                    }

                    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE full_name LIKE ? AND id NOT IN (0" . (empty($x_0039) ? "" : ",".implode(",", array_keys($x_0039))) . ")");
                    $stmt->execute(["%$x_003b%"]);
                    while ($row = $stmt->fetch()) {
                        $row['match_reason'] = "Student Name Match ($x_003b)";
                        $x_0039[$row['id']] = $row;
                    }
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'data' => array_values($x_0039)]);
        exit;
    }

    if ($action === 'poll_mpesa_notifications') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        
        $x_003c = $_GET['last_time'] ?? date('Y-m-d H:i:s', time() - 30);
        $x_003d = [];

        $x_003e = $pdo->prepare("
            SELECT bph.transaction_amount as amount, s.full_name as student_name, bph.performed_at as created_at
            FROM billing_payment_history bph
            JOIN students s ON bph.student_id = s.id
            WHERE bph.payment_method = 'M-Pesa' AND bph.performed_at > ?
        ");
        $x_003e->execute([$x_003c]);
        $x_003f = $x_003e->fetchAll(PDO::FETCH_ASSOC);
        foreach ($x_003f as $row) {
            $x_003d[] = [
                'message' => "Payment of Ksh " . number_format($row['amount']) . " received via M-Pesa for " . $row['student_name'] . "!",
                'type' => 'allocated',
                'created_at' => $row['created_at']
            ];
        }

        $x_0040 = $pdo->prepare("
            SELECT amount, sender_name, created_at 
            FROM unallocated_payments 
            WHERE created_at > ?
        ");
        $x_0040->execute([$x_003c]);
        $x_0041 = $x_0040->fetchAll(PDO::FETCH_ASSOC);
        foreach ($x_0041 as $row) {
            $x_003d[] = [
                'message' => "Unallocated M-Pesa payment of Ksh " . number_format($row['amount']) . " received from " . $row['sender_name'] . ".",
                'type' => 'unallocated',
                'created_at' => $row['created_at']
            ];
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $x_003d,
            'server_time' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'save_category') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id          = intval($_POST['id'] ?? 0);
        $name        = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0042 = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0008      = floatval($_POST['default_amount'] ?? 0);
        $x_0043   = intval($_POST['is_mandatory'] ?? 0) ? 1 : 0;
        $x_0044      = intval($_POST['is_active'] ?? 1) ? 1 : 0;
        $x_0045 = intval($_POST['enforce_on_scanner'] ?? 0) ? 1 : 0;
        $x_0046   = intval($_POST['sort_order'] ?? 0);
        $x_0009 = $_POST['assignment_type'] === 'Per Class' ? 'Per Class' : 'Global';

        $x_000a = $_POST['assigned_classes'] ?? '[]';

        if (json_decode($x_000a) === null) {
            $x_000a = '[]';
        }

        $x_001a = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $x_0047 = intval($_POST['grace_period_days'] ?? 0);

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Category name is required.']);
            exit;
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE billing_categories
                    SET name=?, description=?, default_amount=?, is_mandatory=?, is_active=?, enforce_on_scanner=?, sort_order=?, assignment_type=?, assigned_classes=?, due_date=?, grace_period_days=?
                    WHERE id=?
                ");
                $stmt->execute([$name, $x_0042, $x_0008, $x_0043, $x_0044, $x_0045, $x_0046, $x_0009, $x_000a, $x_001a, $x_0047, $id]);
                $x_0003 = $id;
                log_audit($pdo, $x_0002['id'], 'BILLING_EDIT_CATEGORY', "Edited category ID $id: $name");
                $msg = 'Category updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO billing_categories (name, description, default_amount, is_mandatory, is_active, enforce_on_scanner, sort_order, assignment_type, assigned_classes, due_date, grace_period_days, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $x_0042, $x_0008, $x_0043, $x_0044, $x_0045, $x_0046, $x_0009, $x_000a, $x_001a, $x_0047, $x_0002['id']]);
                $x_0003 = $pdo->lastInsertId();
                log_audit($pdo, $x_0002['id'], 'BILLING_ADD_CATEGORY', "Added category: $name");
                $msg = 'Category created successfully.';
            }

            sync_category_billing($pdo, $x_0003, $x_0002['id']);

            echo json_encode(['status' => 'success', 'message' => $msg]);
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

    if ($action === 'toggle_category') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id     = intval($_POST['id'] ?? 0);
        $x_0044 = intval($_POST['is_active'] ?? 0) ? 1 : 0;
        try {
            $stmt = $pdo->prepare("UPDATE billing_categories SET is_active=? WHERE id=?");
            $stmt->execute([$x_0044, $id]);
            $status = $x_0044 ? 'enabled' : 'disabled';
            log_audit($pdo, $x_0002['id'], 'BILLING_TOGGLE_CATEGORY', "Category $id $status");
            echo json_encode(['status' => 'success', 'message' => "Category $status successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'delete_category') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM student_billing WHERE category_id=?");
            $stmt->execute([$id]);
            $x_0048 = $stmt->fetch()['cnt'];
            if ($x_0048 > 0) {
                echo json_encode(['status' => 'error', 'message' => "Cannot delete: this category has $x_0048 billing record(s). Disable it instead."]);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM billing_categories WHERE id=?");
            $stmt->execute([$id]);
            log_audit($pdo, $x_0002['id'], 'BILLING_DELETE_CATEGORY', "Deleted category ID $id");
            echo json_encode(['status' => 'success', 'message' => 'Category deleted.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'assign_billing') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0014  = intval($_POST['student_id']  ?? 0);
        $x_0003 = intval($_POST['category_id'] ?? 0);
        $x_0004   = getSettings($pdo);
        $x_0005       = htmlspecialchars(trim($_POST['academic_year'] ?? $x_0004['academic_year'] ?? date('Y')), ENT_QUOTES, 'UTF-8');
        $x_0006       = htmlspecialchars(trim($_POST['school_term']   ?? $x_0004['current_term'] ?? 'Term 1'), ENT_QUOTES, 'UTF-8');
        $x_001a    = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $x_0049      = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (!$x_0014 || !$x_0003) {
            echo json_encode(['status' => 'error', 'message' => 'student_id and category_id required.']);
            exit;
        }
        try {

            $x_004a = $pdo->prepare("SELECT default_amount FROM billing_categories WHERE id=?");
            $x_004a->execute([$x_0003]);
            $x_0007 = $x_004a->fetch();
            if (!$x_0007) {
                echo json_encode(['status' => 'error', 'message' => 'Category not found.']);
                exit;
            }
            $x_0020 = floatval($_POST['amount_due'] ?? $x_0007['default_amount']);

            $stmt = $pdo->prepare("
                INSERT INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, due_date, notes, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE amount_due=VALUES(amount_due), updated_by=VALUES(updated_by)
            ");
            $stmt->execute([$x_0014, $x_0003, $x_0005, $x_0006, $x_0020, $x_001a, $x_0049, $x_0002['id']]);
            $x_0023 = $pdo->lastInsertId() ?: $stmt->fetchColumn();

            $x_004b = $pdo->prepare("SELECT * FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
            $x_004b->execute([$x_0014, $x_0003, $x_0005, $x_0006]);
            $row = $x_004b->fetch();
            if ($row) recordHistory($pdo, $row['id'], $row, 'ASSIGNED', $x_0002['id']);

            log_audit($pdo, $x_0002['id'], 'BILLING_ASSIGN', "Assigned category $x_0003 to student $x_0014");
            echo json_encode(['status' => 'success', 'message' => 'Billing assigned.']);
        } catch (PDOException $e) {
            error_log("Billing assign error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'update_billing') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0023    = intval($_POST['billing_id'] ?? 0);
        $x_0020    = floatval($_POST['amount_due']   ?? 0);
        $x_0021   = floatval($_POST['amount_paid']  ?? 0);
        $status       = $_POST['payment_status']  ?? 'Unpaid';
        $x_001a      = !empty($_POST['due_date'])     ? $_POST['due_date']     : null;
        $x_004c  = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
        $x_0049        = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        $x_004d = ['Unpaid', 'Partial', 'Paid', 'Exempt'];
        if (!in_array($status, $x_004d)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payment status.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE student_billing
                SET amount_due=?, amount_paid=?, payment_status=?, due_date=?, payment_date=?, notes=?, updated_by=?
                WHERE id=?
            ");
            $stmt->execute([$x_0020, $x_0021, $status, $x_001a, $x_004c, $x_0049, $x_0002['id'], $x_0023]);

            $x_004b = $pdo->prepare("SELECT * FROM student_billing WHERE id=?");
            $x_004b->execute([$x_0023]);
            $row = $x_004b->fetch();
            if ($row) recordHistory($pdo, $x_0023, $row, 'PAYMENT_UPDATED', $x_0002['id']);

            log_audit($pdo, $x_0002['id'], 'BILLING_UPDATE', "Updated billing record $x_0023 → $status");
            echo json_encode(['status' => 'success', 'message' => 'Billing record updated.']);
        } catch (PDOException $e) {
            error_log("Billing update error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'record_payment') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0023    = intval($_POST['billing_id'] ?? 0);
        $x_004e = floatval($_POST['amount_being_paid'] ?? 0);
        $x_004c  = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
        $x_0028 = htmlspecialchars(trim($_POST['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0029 = htmlspecialchars(trim($_POST['reference_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0049        = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        if ($x_004e <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Payment amount must be greater than zero.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $x_004b = $pdo->prepare("SELECT * FROM student_billing WHERE id=? FOR UPDATE");
            $x_004b->execute([$x_0023]);
            $row = $x_004b->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Billing record not found.']);
                exit;
            }

            $x_0020 = floatval($row['amount_due']);
            $x_004f = floatval($row['amount_paid']);
            $x_0027 = $x_0020 - $x_004f;

            if ($x_004e > $x_0027) {
                echo json_encode(['status' => 'error', 'message' => 'Payment amount cannot exceed remaining balance.']);
                exit;
            }

            $x_0050 = $x_004f + $x_004e;

            $status = $row['payment_status'];
            if ($status !== 'Exempt') {
                $status = computePaymentStatus($x_0020, $x_0050);
            }

            $stmt = $pdo->prepare("
                UPDATE student_billing
                SET amount_paid=?, payment_status=?, payment_date=?, updated_by=?
                WHERE id=?
            ");
            $stmt->execute([$x_0050, $status, $x_004c, $x_0002['id'], $x_0023]);

            if (!empty($x_0049)) {
                $x_0051 = empty($row['notes']) ? $x_0049 : $row['notes'] . "\n" . $x_0049;
                $stmt = $pdo->prepare("UPDATE student_billing SET notes=? WHERE id=?");
                $stmt->execute([$x_0051, $x_0023]);
                $row['notes'] = $x_0051;
            }

            $row['amount_paid'] = $x_0050;
            $row['payment_status'] = $status;
            $row['payment_date'] = $x_004c;
            
            recordDetailedHistory($pdo, $x_0023, $row, 'PAYMENT_RECORDED', $x_0002['id'], $x_004e, $x_0027, $x_0028, $x_0029);

            log_audit($pdo, $x_0002['id'], 'BILLING_PAYMENT', "Recorded payment of $x_004e for billing record $x_0023");

            $pdo->commit();

            try {
                $x_0052 = $pdo->prepare("SELECT id, full_name, admission_number, parent_name, class, gender, parent_phone FROM students WHERE id = (SELECT student_id FROM student_billing WHERE id = ?)");
                $x_0052->execute([$x_0023]);
                $x_0053 = $x_0052->fetch(PDO::FETCH_ASSOC);

                if ($x_0053 && !empty($x_0053['parent_phone'])) {
                    $x_0054 = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_billing', 'sms_template_payment')");
                    $x_0055 = [];
                    while ($s = $x_0054->fetch()) {
                        $x_0055[$s['setting_key']] = $s['setting_value'];
                    }

                    $x_0056 = $x_0055['sms_api_token'] ?? '';
                    $x_0057 = $x_0055['sms_sender_id'] ?? '';
                    $x_0058 = $x_0055['school_name'] ?? 'School System';

                    $x_0059 = $x_0055['sms_enable_billing'] ?? '1';
                    if (!empty($x_0056) && ($x_0059 === 'true' || $x_0059 === '1')) {
                        $x_005a = $x_0027 - $x_004e;
                        $x_0019 = ($x_005a <= 0) ? "paid" : "partially paid";
                        $x_005b = !empty($x_0058) ? $x_0058 : "School Admin";
                        
                        $msg = "Hello, thanks for the progress you have made with the recent fee payment for " . $x_0053['full_name'] . " (" . $x_0053['class'] . "). The account is now " . $x_0019 . ". We appreciate your support. - " . $x_005b;
                        
                        if (!empty($x_0055['sms_template_payment'])) {
                            $msg = str_replace(
                                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Amount Paid}', '{Balance}', '{Payment Date}', '{TIME}'],
                                [$x_0053['full_name'], $x_0053['parent_name'] ?? '', $x_0053['admission_number'] ?? '', $x_0053['class'], $x_005b, number_format($x_004e, 2), number_format($x_005a, 2), date('Y-m-d'), date('h:i A')],
                                $x_0055['sms_template_payment']
                            );
                        }
                        
                        $x_005c = sendBulkSMS([$x_0053['parent_phone']], $msg, $x_0057, $x_0056);
                        
                        $x_005d = (isset($x_005c['success']) && $x_005c['success'] === true) ? 'Sent' : 'Failed';
                        $x_005e = json_encode($x_005c);
                        $x_005f = $x_005c['error'] ?? '';

                        $x_0060 = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $x_0060->execute([
                            $x_0024['student_id'] ?? 0,
                            $x_0053['parent_phone'],
                            $msg,
                            $x_005d,
                            $x_005e,
                            $x_005f
                        ]);
                    }
                }
            } catch (Throwable $ex) {
                error_log("SMS sending failed: " . $ex->getMessage());
            }

            $x_0061 = "";
            if (isset($x_005d)) {
                if ($x_005d === 'Sent') {
                    $x_0061 = " SMS notification was sent successfully.";
                } else {
                    $x_0061 = " However, SMS notification failed to send.";
                }
            } else {
                if (empty($x_0053['parent_phone'])) {
                    $x_0061 = " (No SMS sent: Parent phone number is missing).";
                } elseif (empty($x_0056)) {
                    $x_0061 = " (No SMS sent: SMS API token not configured in Settings).";
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully.' . $x_0061]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Billing payment error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'remove_billing') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0023 = intval($_POST['billing_id'] ?? 0);
        try {

            $x_004b = $pdo->prepare("SELECT * FROM student_billing WHERE id=?");
            $x_004b->execute([$x_0023]);
            $row = $x_004b->fetch();
            if ($row) recordHistory($pdo, $x_0023, $row, 'REMOVED', $x_0002['id']);

            $stmt = $pdo->prepare("DELETE FROM student_billing WHERE id=?");
            $stmt->execute([$x_0023]);
            log_audit($pdo, $x_0002['id'], 'BILLING_REMOVE', "Removed billing record $x_0023");
            echo json_encode(['status' => 'success', 'message' => 'Billing removed.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'bulk_billing') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }

        $x_0062 = json_decode($_POST['student_ids'] ?? '[]', true);
        $x_0063 = $_POST['bulk_action'] ?? '';
        $x_0003 = intval($_POST['category_id'] ?? 0);
        $x_0004   = getSettings($pdo);
        $x_0005       = $x_0004['academic_year'] ?? date('Y');
        $x_0006       = $x_0004['current_term']  ?? 'Term 1';
        $x_0049      = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0021 = floatval($_POST['amount_paid'] ?? 0);

        if (empty($x_0062) || !is_array($x_0062)) {
            echo json_encode(['status' => 'error', 'message' => 'No students selected.']);
            exit;
        }

        $x_0064 = 0;
        $x_0065   = [];

        try {
            $pdo->beginTransaction();

            foreach ($x_0062 as $x_0066) {
                $x_0066 = intval($x_0066);
                if (!$x_0066) continue;

                if (in_array($x_0063, ['assign_meals', 'assign_transport'])) {

                    if (!$x_0003) {
                        $x_0067 = ($x_0063 === 'assign_meals') ? 'Meals' : 'Transport';
                        $x_004a = $pdo->prepare("SELECT id, default_amount FROM billing_categories WHERE name=? LIMIT 1");
                        $x_004a->execute([$x_0067]);
                        $x_0007 = $x_004a->fetch();
                        if (!$x_0007) continue;
                        $x_0068 = $x_0007['id'];
                        $x_0069 = $x_0007['default_amount'];
                    } else {
                        $x_004a = $pdo->prepare("SELECT default_amount FROM billing_categories WHERE id=?");
                        $x_004a->execute([$x_0003]);
                        $x_0007 = $x_004a->fetch();
                        $x_0068 = $x_0003;
                        $x_0069 = $x_0007 ? $x_0007['default_amount'] : 0;
                    }
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$x_0066, $x_0068, $x_0005, $x_0006, $x_0069, $x_0002['id']]);

                } elseif (in_array($x_0063, ['remove_meals', 'remove_transport'])) {
                    $x_0067 = ($x_0063 === 'remove_meals') ? 'Meals' : 'Transport';
                    $x_004a = $pdo->prepare("SELECT id FROM billing_categories WHERE name=? LIMIT 1");
                    $x_004a->execute([$x_0067]);
                    $x_0007 = $x_004a->fetch();
                    if (!$x_0007) continue;

                    $x_006a = $pdo->prepare("SELECT * FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
                    $x_006a->execute([$x_0066, $x_0007['id'], $x_0005, $x_0006]);
                    $row = $x_006a->fetch();
                    if ($row) recordHistory($pdo, $row['id'], $row, 'BULK_REMOVED', $x_0002['id']);

                    $stmt = $pdo->prepare("DELETE FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
                    $stmt->execute([$x_0066, $x_0007['id'], $x_0005, $x_0006]);

                } elseif (in_array($x_0063, ['mark_paid', 'mark_partial', 'mark_unpaid', 'mark_exempt'])) {
                    $x_006b = [
                        'mark_paid'    => 'Paid',
                        'mark_partial' => 'Partial',
                        'mark_unpaid'  => 'Unpaid',
                        'mark_exempt'  => 'Exempt',
                    ];
                    $x_006c = $x_006b[$x_0063];

                    $x_006d = $x_0003 ? 'AND category_id = ?' : '';
                    $x_006e = $x_0003 ? [$x_0066, $x_0005, $x_0006, $x_0003] : [$x_0066, $x_0005, $x_0006];

                    $x_006f = "SELECT * FROM student_billing WHERE student_id=? AND academic_year=? AND school_term=? $x_006d";
                    $x_006a = $pdo->prepare($x_006f);
                    $x_006a->execute($x_006e);
                    $rows = $x_006a->fetchAll();

                    foreach ($rows as $row) {
                        $x_0070 = ($x_006c === 'Paid' || $x_006c === 'Partial') ? date('Y-m-d') : null;
                        $x_0071    = ($x_006c === 'Paid') ? $row['amount_due'] : ($x_006c === 'Partial' ? $x_0021 : 0);

                        $x_0072 = $pdo->prepare("
                            UPDATE student_billing
                            SET payment_status=?, payment_date=?, amount_paid=?, notes=?, updated_by=?
                            WHERE id=?
                        ");
                        $x_0072->execute([$x_006c, $x_0070, $x_0071, $x_0049, $x_0002['id'], $row['id']]);

                        $row['payment_status'] = $x_006c;
                        $row['payment_date']   = $x_0070;
                        $row['amount_paid']    = $x_0071;
                        recordHistory($pdo, $row['id'], $row, 'BULK_STATUS_CHANGED', $x_0002['id']);
                    }
                }
                $x_0064++;
            }

            $pdo->commit();
            log_audit($pdo, $x_0002['id'], 'BILLING_BULK', "Bulk action '$x_0063' on " . count($x_0062) . " students");
            echo json_encode(['status' => 'success', 'message' => "Bulk action completed. $x_0064 student(s) processed."]);

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Billing bulk error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error during bulk operation.']);
        }
        exit;
    }

    if ($action === 'assign_unallocated') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0073 = intval($_POST['unallocated_id'] ?? 0);
        $x_0014 = intval($_POST['student_id'] ?? 0);
        $x_0074 = $_POST['category_ids'] ?? '[]';
        $x_0075 = json_decode($x_0074, true);
        if (!is_array($x_0075)) $x_0075 = [];

        $x_0075 = array_map('intval', $x_0075);
        $x_0075 = array_filter($x_0075);
        
        if (!$x_0073 || !$x_0014) {
            echo json_encode(['status' => 'error', 'message' => 'Unallocated ID and Student ID are required.']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM unallocated_payments WHERE id = ? FOR UPDATE");
            $stmt->execute([$x_0073]);
            $x_0041 = $stmt->fetch();
            
            if (!$x_0041 || $x_0041['status'] !== 'Unallocated') {
                echo json_encode(['status' => 'error', 'message' => 'Payment not found or already allocated.']);
                exit;
            }
            
            $x_0076 = floatval($x_0041['amount']);
            $x_0077 = $x_0041['receipt_number'];
            $x_004c = explode(' ', $x_0041['payment_date'])[0]; // Get YYYY-MM-DD

            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$x_0014]);
            $x_000e = $stmt->fetch();
            
            if (!$x_000e) {
                echo json_encode(['status' => 'error', 'message' => 'Student not found.']);
                exit;
            }

            $sql = "SELECT sb.*, bc.name as cat_name 
                    FROM student_billing sb 
                    JOIN billing_categories bc ON sb.category_id = bc.id 
                    WHERE sb.student_id = ?";
            
            $params = [$x_0014];
            if (!empty($x_0075)) {
                $x_0078 = implode(',', array_fill(0, count($x_0075), '?'));
                $sql .= " AND sb.category_id IN ($x_0078)";
                $params = array_merge($params, $x_0075);
            }
            $sql .= " ORDER BY (sb.payment_status IN ('Unpaid', 'Partial')) DESC, bc.sort_order ASC, sb.due_date ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $x_0079 = $stmt->fetchAll();
            
            $x_007a = $x_0076;
            $x_007b = null;
            
            foreach ($x_0079 as $x_007c) {
                if ($x_007a <= 0) break;

                $x_0020 = floatval($x_007c['amount_due']);
                $x_004f = floatval($x_007c['amount_paid']);
                $x_002a = $x_0020 - $x_004f;

                if ($x_002a <= 0) continue;

                $x_007d = min($x_002a, $x_007a);
                $x_0050 = $x_004f + $x_007d;
                $x_005a = $x_0020 - $x_0050;
                
                $status = ($x_005a <= 0) ? 'Paid' : 'Partial';

                $x_007e = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                $x_007e->execute([$x_0050, $status, $x_004c, $x_007c['id']]);

                $x_0022 = ($x_0020 > 0) ? round(($x_0050 / $x_0020) * 100, 2) : 100;
                $x_007f = $pdo->prepare("
                    INSERT INTO billing_payment_history
                    (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PAYMENT_RECORDED', ?, ?, ?, 'M-Pesa', ?)
                ");
                $x_007f->execute([
                    $x_007c['id'], $x_000e['id'], $x_007c['category_id'], $x_007c['academic_year'], $x_007c['school_term'],
                    $x_0020, $x_0050, $x_005a, $status, $x_004c, $x_007c['due_date'], 
                    $x_007d, $x_002a, $x_0022, $x_0077
                ]);

                $x_007a -= $x_007d;
                $x_007b = $x_007c;
            }

            if ($x_007a > 0) {

                $x_0080 = $x_007b;
                if (!$x_0080 && count($x_0079) > 0) {
                    $x_0080 = $x_0079[0]; // If no unpaid bills existed, just use the first bill fetched
                }
                
                if ($x_0080) {
                    $x_0020 = floatval($x_0080['amount_due']);

                    $x_0081 = $pdo->prepare("SELECT amount_paid FROM student_billing WHERE id = ?");
                    $x_0081->execute([$x_0080['id']]);
                    $x_004f = floatval($x_0081->fetchColumn());
                    
                    $x_002a = $x_0020 - $x_004f;
                    
                    $x_0050 = $x_004f + $x_007a;
                    $x_005a = $x_0020 - $x_0050; // Will be negative (overpaid)
                    $status = 'Paid'; // Still paid

                    $x_007e = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                    $x_007e->execute([$x_0050, $status, $x_004c, $x_0080['id']]);

                    $x_0022 = ($x_0020 > 0) ? round(($x_0050 / $x_0020) * 100, 2) : 100;
                    $x_007f = $pdo->prepare("
                        INSERT INTO billing_payment_history
                        (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OVERPAYMENT_RECORDED', ?, ?, ?, 'M-Pesa', ?)
                    ");
                    $x_007f->execute([
                        $x_0080['id'], $x_000e['id'], $x_0080['category_id'], $x_0080['academic_year'], $x_0080['school_term'],
                        $x_0020, $x_0050, $x_005a, $status, $x_004c, $x_0080['due_date'], 
                        $x_007a, $x_002a, $x_0022, $x_0077
                    ]);
                    
                    $x_007a = 0;
                }
            }

            $x_0082 = $pdo->prepare("UPDATE unallocated_payments SET status = 'Allocated', allocated_student_id = ? WHERE id = ?");
            $x_0082->execute([$x_0014, $x_0073]);

            if (function_exists('sendPaymentSMS')) {

                $x_0083 = $pdo->prepare("SELECT SUM(amount_due - amount_paid) FROM student_billing WHERE student_id = ? AND payment_status IN ('Unpaid', 'Partial')");
                $x_0083->execute([$x_0014]);
                $x_0084 = floatval($x_0083->fetchColumn());
                
                sendPaymentSMS($pdo, $x_000e, $x_0076, $x_0084, 'Processed');
            }
            
            log_audit($pdo, $x_0002['id'], 'BILLING_ALLOCATE', "Allocated $x_0076 (Receipt: $x_0077) to student $x_0014");
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Payment allocated successfully.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Allocate error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'delete_unallocated') {
        if (!$x_0001) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $x_0073 = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM unallocated_payments WHERE id = ?");
        $stmt->execute([$x_0073]);
        echo json_encode(['status' => 'success', 'message' => 'Record deleted.']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown POST action.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);

