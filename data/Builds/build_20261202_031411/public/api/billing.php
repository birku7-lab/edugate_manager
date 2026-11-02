<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
error_reporting(0);
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';
require_once 'sms_helper.php';

$action = $_GET['action'] ?? '';
$isAdmin = ($current_user['role'] ?? '') === 'Admin';

function sync_category_billing($pdo, $categoryId, $userId) {
    $settings = getSettings($pdo);
    $year = $settings['academic_year'] ?? date('Y');
    $term = $settings['current_term'] ?? 'Term 1';

    $stmt = $pdo->prepare("SELECT * FROM billing_categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $cat = $stmt->fetch();
    if (!$cat || (!$cat['is_active'] ?? false)) return;

    $amount = floatval($cat['default_amount']);
    $assignmentType = $cat['assignment_type'];
    $assignedClasses = json_decode($cat['assigned_classes'] ?: '[]', true) ?: [];
    $isAssoc = is_array($assignedClasses) && !empty($assignedClasses) && (array_keys($assignedClasses) !== range(0, count($assignedClasses) - 1));

    $studentsStmt = $pdo->query("SELECT id, class FROM students");
    $students = $studentsStmt->fetchAll();

    foreach ($students as $student) {
        $shouldHave = false;
        $specificAmount = $amount;

        if ($assignmentType === 'Global') {
            $shouldHave = true;
        } else {
            if ($isAssoc) {
                if (array_key_exists($student['class'], $assignedClasses)) {
                    $shouldHave = true;

                    if (is_numeric($assignedClasses[$student['class']])) {
                        $specificAmount = floatval($assignedClasses[$student['class']]);
                    }
                }
            } else {
                if (in_array($student['class'], $assignedClasses)) {
                    $shouldHave = true;
                }
            }
        }

        if ($shouldHave) {
            $insStmt = $pdo->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)
            ");
            $insStmt->execute([$student['id'], $categoryId, $year, $term, $specificAmount, $userId]);

            $updateStmt = $pdo->prepare("
                UPDATE student_billing 
                SET amount_due = ? 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $updateStmt->execute([$specificAmount, $student['id'], $categoryId, $year, $term]);

        } else {
            $delStmt = $pdo->prepare("
                DELETE FROM student_billing 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $delStmt->execute([$student['id'], $categoryId, $year, $term]);
        }
    }
}

function sync_student_billing($pdo, $studentId, $studentClass, $userId) {
    $settings = getSettings($pdo);
    $year = $settings['academic_year'] ?? date('Y');
    $term = $settings['current_term'] ?? 'Term 1';

    $catsStmt = $pdo->query("SELECT * FROM billing_categories WHERE is_active = 1");
    $categories = $catsStmt->fetchAll();

    foreach ($categories as $cat) {
        $amount = floatval($cat['default_amount']);
        $assignmentType = $cat['assignment_type'];
        $assignedClasses = json_decode($cat['assigned_classes'] ?: '[]', true) ?: [];
        $isAssoc = is_array($assignedClasses) && !empty($assignedClasses) && (array_keys($assignedClasses) !== range(0, count($assignedClasses) - 1));

        $shouldHave = false;
        $specificAmount = $amount;

        if ($assignmentType === 'Global') {
            $shouldHave = true;
        } else {
            if ($isAssoc) {
                if (array_key_exists($studentClass, $assignedClasses)) {
                    $shouldHave = true;
                    if (is_numeric($assignedClasses[$studentClass])) {
                        $specificAmount = floatval($assignedClasses[$studentClass]);
                    }
                }
            } else {
                if (in_array($studentClass, $assignedClasses)) {
                    $shouldHave = true;
                }
            }
        }

        if ($shouldHave) {
            $insStmt = $pdo->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, 0, 'Unpaid', ?)
            ");
            $insStmt->execute([$studentId, $cat['id'], $year, $term, $userId]);
        } else {
            $delStmt = $pdo->prepare("
                DELETE FROM student_billing 
                WHERE student_id = ? AND category_id = ? AND academic_year = ? AND school_term = ? AND payment_status = 'Unpaid'
            ");
            $delStmt->execute([$studentId, $cat['id'], $year, $term]);
        }
    }
}

function getSettings($pdo) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $out = [];
    while ($r = $stmt->fetch()) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}

function computeDynamicStatus($paymentStatus, $dueDate, $gracePeriodDays) {
    if ($paymentStatus === 'Paid' || $paymentStatus === 'Exempt') {
        return $paymentStatus;
    }
    if (empty($dueDate)) {
        return $paymentStatus;
    }
    try {
        $today = new DateTime('today');
        $due = new DateTime($dueDate);
        $due->setTime(0, 0, 0);
        $graceDays = intval($gracePeriodDays);
        $graceEnd = (clone $due)->modify("+$graceDays days");
        
        if ($today > $graceEnd) {
            return 'Overdue';
        } elseif ($today > $due && $today <= $graceEnd) {
            return 'Grace Period';
        }
    } catch (Exception $e) {}
    
    return $paymentStatus;
}

function computePaymentStatus($amountDue, $amountPaid) {
    if ($amountDue <= 0) return 'Paid';
    $percentage = ($amountPaid / $amountDue) * 100;
    if ($percentage >= 100) return 'Paid';
    if ($percentage > 0) return 'Partial';
    return 'Unpaid';
}

function calculatePercentage($amountDue, $amountPaid) {
    if ($amountDue <= 0) return 100;
    $percentage = ($amountPaid / $amountDue) * 100;
    return round(min(100, $percentage), 0);
}

function recordDetailedHistory($pdo, $billingId, $record, $action, $performedBy, $transactionAmount, $previousBalance, $paymentMethod, $referenceNumber) {
    $balance = $record['amount_due'] - $record['amount_paid'];
    $percentage = calculatePercentage($record['amount_due'], $record['amount_paid']);
    $stmt = $pdo->prepare("
        INSERT INTO billing_payment_history
          (billing_id, student_id, category_id, academic_year, school_term,
           amount_due, amount_paid, balance, payment_status, payment_date,
           due_date, notes, action, performed_by,
           transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $billingId,
        $record['student_id'],
        $record['category_id'],
        $record['academic_year'],
        $record['school_term'],
        $record['amount_due'],
        $record['amount_paid'],
        $balance,
        $record['payment_status'],
        $record['payment_date'],
        $record['due_date'],
        $record['notes'],
        $action,
        $performedBy,
        $transactionAmount,
        $previousBalance,
        $percentage,
        $paymentMethod,
        $referenceNumber
    ]);
}

function recordHistory($pdo, $billingId, $record, $action, $performedBy) {
    recordDetailedHistory($pdo, $billingId, $record, $action, $performedBy, 0.00, 0.00, null, null);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($action === 'poll_payments') {
        $lastUnallocId = intval($_GET['last_unalloc_id'] ?? 0);
        $lastHistoryId = intval($_GET['last_history_id'] ?? 0);
        
        $response = ['status' => 'success', 'new_unallocated' => [], 'new_assigned' => []];
        
        try {

            $stmt = $pdo->prepare("SELECT id, sender_name, amount, receipt_number, created_at FROM unallocated_payments WHERE id > ? AND status = 'Unallocated'");
            $stmt->execute([$lastUnallocId]);
            $response['new_unallocated'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT h.id, h.transaction_amount as amount, h.reference_number as receipt_number, h.performed_at as created_at,
                       s.full_name as student_name, s.class, s.parent_name
                FROM billing_payment_history h
                JOIN students s ON h.student_id = s.id
                WHERE h.id > ? AND h.payment_method = 'M-Pesa' AND h.action IN ('PAYMENT_RECORDED', 'ADVANCE_PAYMENT')
            ");
            $stmt->execute([$lastHistoryId]);
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
        $studentId = intval($_GET['student_id'] ?? 0);
        if (!$studentId) {
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
            $stmt->execute([$studentId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($records as &$rec) {
                $effectiveDueDate = $rec['due_date'] ?: $rec['cat_due_date'];
                $rec['dynamic_status'] = computeDynamicStatus($rec['payment_status'], $effectiveDueDate, $rec['cat_grace_period']);
                $rec['payment_percentage'] = calculatePercentage((float)$rec['amount_due'], (float)$rec['amount_paid']);
            }
            echo json_encode(['status' => 'success', 'data' => $records]);
        } catch (Throwable $e) {
            error_log("get_student_billing Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'payment_history') {
        $studentId = intval($_GET['student_id'] ?? 0);
        if (!$studentId) {
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
        $stmt->execute([$studentId]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'billing_dashboard') {
        $settings = getSettings($pdo);
        $year = $settings['academic_year'] ?? date('Y');
        $term = $settings['current_term'] ?? 'Term 1';

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
        $categories = $stmt->fetchAll();

        foreach ($categories as &$cat) {
            $cat['outstanding'] = round($cat['expected'] - $cat['collected'], 2);
        }
        unset($cat);

        echo json_encode(['status' => 'success', 'data' => $categories, 'current_year' => $year, 'current_term' => $term]);
        exit;
    }

    if ($action === 'billing_report') {
        $categoryId   = intval($_GET['category_id']   ?? 0);
        $classFilter  = trim($_GET['class']           ?? '');
        $yearFilter   = trim($_GET['academic_year']   ?? '');
        $termFilter   = trim($_GET['school_term']     ?? '');
        $statusFilter = trim($_GET['payment_status']  ?? '');

        $where  = ['1=1'];
        $params = [];

        if ($categoryId) { $where[] = 'sb.category_id = ?';    $params[] = $categoryId; }
        if ($classFilter) { $where[] = 's.class = ?';           $params[] = $classFilter; }
        if ($yearFilter)  { $where[] = 'sb.academic_year = ?';  $params[] = $yearFilter; }
        if ($termFilter)  { $where[] = 'sb.school_term = ?';    $params[] = $termFilter; }
        if ($statusFilter){ $where[] = 'sb.payment_status = ?'; $params[] = $statusFilter; }

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
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.class ASC, s.full_name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'students_billing_list') {
        $classFilter  = trim($_GET['class']          ?? '');
        $yearFilter   = trim($_GET['academic_year']  ?? '');
        $termFilter   = trim($_GET['school_term']    ?? '');
        $statusFilter = trim($_GET['payment_status'] ?? '');
        $catFilter    = intval($_GET['category_id']  ?? 0);

        $where  = ['1=1'];
        $params = [];

        $havingClauses = [];

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

        if ($classFilter) { $where[] = 's.class = ?'; $params[] = $classFilter; }
        if ($yearFilter)  { $where[] = '(sb.academic_year = ? OR sb.academic_year IS NULL)'; $params[] = $yearFilter; }
        if ($termFilter)  { $where[] = '(sb.school_term = ? OR sb.school_term IS NULL)'; $params[] = $termFilter; }
        if ($statusFilter){ $where[] = 'sb.payment_status = ?'; $params[] = $statusFilter; }
        if ($catFilter)   { $where[] = 'sb.category_id = ?'; $params[] = $catFilter; }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY s.id ORDER BY s.class ASC, s.full_name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'get_unallocated') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $stmt = $pdo->query("SELECT * FROM unallocated_payments WHERE status = 'Unallocated' ORDER BY payment_date DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get_student_suggestions') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        
        $senderName = trim($_GET['sender_name'] ?? '');
        $senderPhone = trim($_GET['sender_phone'] ?? '');
        
        $suggestions = [];

        if (!empty($senderPhone)) {
            $stmt = $pdo->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE parent_phone = ?");
            $stmt->execute([$senderPhone]);
            while ($row = $stmt->fetch()) {
                $row['match_reason'] = 'Parent Phone Match';
                $suggestions[$row['id']] = $row;
            }
        }

        if (!empty($senderName)) {
            $parts = explode(' ', $senderName);
            foreach ($parts as $part) {
                if (strlen($part) > 2) {
                    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE parent_name LIKE ? AND id NOT IN (0" . (empty($suggestions) ? "" : ",".implode(",", array_keys($suggestions))) . ")");
                    $stmt->execute(["%$part%"]);
                    while ($row = $stmt->fetch()) {
                        $row['match_reason'] = "Parent Name Match ($part)";
                        $suggestions[$row['id']] = $row;
                    }

                    $stmt = $pdo->prepare("SELECT id, full_name, class, parent_name, parent_phone FROM students WHERE full_name LIKE ? AND id NOT IN (0" . (empty($suggestions) ? "" : ",".implode(",", array_keys($suggestions))) . ")");
                    $stmt->execute(["%$part%"]);
                    while ($row = $stmt->fetch()) {
                        $row['match_reason'] = "Student Name Match ($part)";
                        $suggestions[$row['id']] = $row;
                    }
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'data' => array_values($suggestions)]);
        exit;
    }

    if ($action === 'poll_mpesa_notifications') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        
        $lastTime = $_GET['last_time'] ?? date('Y-m-d H:i:s', time() - 30);
        $notifications = [];

        $stmtAlloc = $pdo->prepare("
            SELECT bph.transaction_amount as amount, s.full_name as student_name, bph.performed_at as created_at
            FROM billing_payment_history bph
            JOIN students s ON bph.student_id = s.id
            WHERE bph.payment_method = 'M-Pesa' AND bph.performed_at > ?
        ");
        $stmtAlloc->execute([$lastTime]);
        $allocated = $stmtAlloc->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allocated as $row) {
            $notifications[] = [
                'message' => "Payment of Ksh " . number_format($row['amount']) . " received via M-Pesa for " . $row['student_name'] . "!",
                'type' => 'allocated',
                'created_at' => $row['created_at']
            ];
        }

        $stmtUnalloc = $pdo->prepare("
            SELECT amount, sender_name, created_at 
            FROM unallocated_payments 
            WHERE created_at > ?
        ");
        $stmtUnalloc->execute([$lastTime]);
        $unallocated = $stmtUnalloc->fetchAll(PDO::FETCH_ASSOC);
        foreach ($unallocated as $row) {
            $notifications[] = [
                'message' => "Unallocated M-Pesa payment of Ksh " . number_format($row['amount']) . " received from " . $row['sender_name'] . ".",
                'type' => 'unallocated',
                'created_at' => $row['created_at']
            ];
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $notifications,
            'server_time' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'save_category') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id          = intval($_POST['id'] ?? 0);
        $name        = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $amount      = floatval($_POST['default_amount'] ?? 0);
        $mandatory   = intval($_POST['is_mandatory'] ?? 0) ? 1 : 0;
        $active      = intval($_POST['is_active'] ?? 1) ? 1 : 0;
        $enforceScanner = intval($_POST['enforce_on_scanner'] ?? 0) ? 1 : 0;
        $sortOrder   = intval($_POST['sort_order'] ?? 0);
        $assignmentType = $_POST['assignment_type'] === 'Per Class' ? 'Per Class' : 'Global';

        $assignedClasses = $_POST['assigned_classes'] ?? '[]';

        if (json_decode($assignedClasses) === null) {
            $assignedClasses = '[]';
        }

        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $gracePeriod = intval($_POST['grace_period_days'] ?? 0);

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
                $stmt->execute([$name, $description, $amount, $mandatory, $active, $enforceScanner, $sortOrder, $assignmentType, $assignedClasses, $dueDate, $gracePeriod, $id]);
                $categoryId = $id;
                log_audit($pdo, $current_user['id'], 'BILLING_EDIT_CATEGORY', "Edited category ID $id: $name");
                $msg = 'Category updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO billing_categories (name, description, default_amount, is_mandatory, is_active, enforce_on_scanner, sort_order, assignment_type, assigned_classes, due_date, grace_period_days, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $amount, $mandatory, $active, $enforceScanner, $sortOrder, $assignmentType, $assignedClasses, $dueDate, $gracePeriod, $current_user['id']]);
                $categoryId = $pdo->lastInsertId();
                log_audit($pdo, $current_user['id'], 'BILLING_ADD_CATEGORY', "Added category: $name");
                $msg = 'Category created successfully.';
            }

            sync_category_billing($pdo, $categoryId, $current_user['id']);

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
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id     = intval($_POST['id'] ?? 0);
        $active = intval($_POST['is_active'] ?? 0) ? 1 : 0;
        try {
            $stmt = $pdo->prepare("UPDATE billing_categories SET is_active=? WHERE id=?");
            $stmt->execute([$active, $id]);
            $status = $active ? 'enabled' : 'disabled';
            log_audit($pdo, $current_user['id'], 'BILLING_TOGGLE_CATEGORY', "Category $id $status");
            echo json_encode(['status' => 'success', 'message' => "Category $status successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'delete_category') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM student_billing WHERE category_id=?");
            $stmt->execute([$id]);
            $cnt = $stmt->fetch()['cnt'];
            if ($cnt > 0) {
                echo json_encode(['status' => 'error', 'message' => "Cannot delete: this category has $cnt billing record(s). Disable it instead."]);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM billing_categories WHERE id=?");
            $stmt->execute([$id]);
            log_audit($pdo, $current_user['id'], 'BILLING_DELETE_CATEGORY', "Deleted category ID $id");
            echo json_encode(['status' => 'success', 'message' => 'Category deleted.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'assign_billing') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $studentId  = intval($_POST['student_id']  ?? 0);
        $categoryId = intval($_POST['category_id'] ?? 0);
        $settings   = getSettings($pdo);
        $year       = htmlspecialchars(trim($_POST['academic_year'] ?? $settings['academic_year'] ?? date('Y')), ENT_QUOTES, 'UTF-8');
        $term       = htmlspecialchars(trim($_POST['school_term']   ?? $settings['current_term'] ?? 'Term 1'), ENT_QUOTES, 'UTF-8');
        $dueDate    = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $notes      = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (!$studentId || !$categoryId) {
            echo json_encode(['status' => 'error', 'message' => 'student_id and category_id required.']);
            exit;
        }
        try {

            $catStmt = $pdo->prepare("SELECT default_amount FROM billing_categories WHERE id=?");
            $catStmt->execute([$categoryId]);
            $cat = $catStmt->fetch();
            if (!$cat) {
                echo json_encode(['status' => 'error', 'message' => 'Category not found.']);
                exit;
            }
            $amountDue = floatval($_POST['amount_due'] ?? $cat['default_amount']);

            $stmt = $pdo->prepare("
                INSERT INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, due_date, notes, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE amount_due=VALUES(amount_due), updated_by=VALUES(updated_by)
            ");
            $stmt->execute([$studentId, $categoryId, $year, $term, $amountDue, $dueDate, $notes, $current_user['id']]);
            $billingId = $pdo->lastInsertId() ?: $stmt->fetchColumn();

            $fetchStmt = $pdo->prepare("SELECT * FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
            $fetchStmt->execute([$studentId, $categoryId, $year, $term]);
            $row = $fetchStmt->fetch();
            if ($row) recordHistory($pdo, $row['id'], $row, 'ASSIGNED', $current_user['id']);

            log_audit($pdo, $current_user['id'], 'BILLING_ASSIGN', "Assigned category $categoryId to student $studentId");
            echo json_encode(['status' => 'success', 'message' => 'Billing assigned.']);
        } catch (PDOException $e) {
            error_log("Billing assign error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'update_billing') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $billingId    = intval($_POST['billing_id'] ?? 0);
        $amountDue    = floatval($_POST['amount_due']   ?? 0);
        $amountPaid   = floatval($_POST['amount_paid']  ?? 0);
        $status       = $_POST['payment_status']  ?? 'Unpaid';
        $dueDate      = !empty($_POST['due_date'])     ? $_POST['due_date']     : null;
        $paymentDate  = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
        $notes        = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        $allowedStatus = ['Unpaid', 'Partial', 'Paid', 'Exempt'];
        if (!in_array($status, $allowedStatus)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payment status.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE student_billing
                SET amount_due=?, amount_paid=?, payment_status=?, due_date=?, payment_date=?, notes=?, updated_by=?
                WHERE id=?
            ");
            $stmt->execute([$amountDue, $amountPaid, $status, $dueDate, $paymentDate, $notes, $current_user['id'], $billingId]);

            $fetchStmt = $pdo->prepare("SELECT * FROM student_billing WHERE id=?");
            $fetchStmt->execute([$billingId]);
            $row = $fetchStmt->fetch();
            if ($row) recordHistory($pdo, $billingId, $row, 'PAYMENT_UPDATED', $current_user['id']);

            log_audit($pdo, $current_user['id'], 'BILLING_UPDATE', "Updated billing record $billingId → $status");
            echo json_encode(['status' => 'success', 'message' => 'Billing record updated.']);
        } catch (PDOException $e) {
            error_log("Billing update error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'record_payment') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $billingId    = intval($_POST['billing_id'] ?? 0);
        $amountBeingPaid = floatval($_POST['amount_being_paid'] ?? 0);
        $paymentDate  = !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
        $paymentMethod = htmlspecialchars(trim($_POST['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8');
        $referenceNumber = htmlspecialchars(trim($_POST['reference_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $notes        = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');

        if ($amountBeingPaid <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Payment amount must be greater than zero.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $fetchStmt = $pdo->prepare("SELECT * FROM student_billing WHERE id=? FOR UPDATE");
            $fetchStmt->execute([$billingId]);
            $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Billing record not found.']);
                exit;
            }

            $amountDue = floatval($row['amount_due']);
            $currentPaid = floatval($row['amount_paid']);
            $previousBalance = $amountDue - $currentPaid;

            if ($amountBeingPaid > $previousBalance) {
                echo json_encode(['status' => 'error', 'message' => 'Payment amount cannot exceed remaining balance.']);
                exit;
            }

            $newPaid = $currentPaid + $amountBeingPaid;

            $status = $row['payment_status'];
            if ($status !== 'Exempt') {
                $status = computePaymentStatus($amountDue, $newPaid);
            }

            $stmt = $pdo->prepare("
                UPDATE student_billing
                SET amount_paid=?, payment_status=?, payment_date=?, updated_by=?
                WHERE id=?
            ");
            $stmt->execute([$newPaid, $status, $paymentDate, $current_user['id'], $billingId]);

            if (!empty($notes)) {
                $newNotes = empty($row['notes']) ? $notes : $row['notes'] . "\n" . $notes;
                $stmt = $pdo->prepare("UPDATE student_billing SET notes=? WHERE id=?");
                $stmt->execute([$newNotes, $billingId]);
                $row['notes'] = $newNotes;
            }

            $row['amount_paid'] = $newPaid;
            $row['payment_status'] = $status;
            $row['payment_date'] = $paymentDate;
            
            recordDetailedHistory($pdo, $billingId, $row, 'PAYMENT_RECORDED', $current_user['id'], $amountBeingPaid, $previousBalance, $paymentMethod, $referenceNumber);

            log_audit($pdo, $current_user['id'], 'BILLING_PAYMENT', "Recorded payment of $amountBeingPaid for billing record $billingId");

            $pdo->commit();

            try {
                $smsStmt = $pdo->prepare("SELECT id, full_name, admission_number, parent_name, class, gender, parent_phone FROM students WHERE id = (SELECT student_id FROM student_billing WHERE id = ?)");
                $smsStmt->execute([$billingId]);
                $studentData = $smsStmt->fetch(PDO::FETCH_ASSOC);

                if ($studentData && !empty($studentData['parent_phone'])) {
                    $setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_token', 'sms_sender_id', 'school_name', 'sms_enable_billing', 'sms_template_payment')");
                    $sets = [];
                    while ($s = $setStmt->fetch()) {
                        $sets[$s['setting_key']] = $s['setting_value'];
                    }

                    $apiKey = $sets['sms_api_token'] ?? '';
                    $senderId = $sets['sms_sender_id'] ?? '';
                    $schoolName = $sets['school_name'] ?? 'School System';

                    $enableBilling = $sets['sms_enable_billing'] ?? '1';
                    if (!empty($apiKey) && ($enableBilling === 'true' || $enableBilling === '1')) {
                        $newBalance = $previousBalance - $amountBeingPaid;
                        $paymentStatus = ($newBalance <= 0) ? "paid" : "partially paid";
                        $displaySchool = !empty($schoolName) ? $schoolName : "School Admin";
                        
                        $msg = "Hello, thanks for the progress you have made with the recent fee payment for " . $studentData['full_name'] . " (" . $studentData['class'] . "). The account is now " . $paymentStatus . ". We appreciate your support. - " . $displaySchool;
                        
                        if (!empty($sets['sms_template_payment'])) {
                            $msg = str_replace(
                                ['{Student Name}', '{Parent Name}', '{Admission Number}', '{Class}', '{School Name}', '{Amount Paid}', '{Balance}', '{Payment Date}', '{TIME}'],
                                [$studentData['full_name'], $studentData['parent_name'] ?? '', $studentData['admission_number'] ?? '', $studentData['class'], $displaySchool, number_format($amountBeingPaid, 2), number_format($newBalance, 2), date('Y-m-d'), date('h:i A')],
                                $sets['sms_template_payment']
                            );
                        }
                        
                        $smsRes = sendBulkSMS([$studentData['parent_phone']], $msg, $senderId, $apiKey);
                        
                        $smsStatus = (isset($smsRes['success']) && $smsRes['success'] === true) ? 'Sent' : 'Failed';
                        $apiResponse = json_encode($smsRes);
                        $errorMsg = $smsRes['error'] ?? '';

                        $logStmt = $pdo->prepare("INSERT INTO sms_logs (student_id, phone_number, message, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?)");
                        $logStmt->execute([
                            $record['student_id'] ?? 0,
                            $studentData['parent_phone'],
                            $msg,
                            $smsStatus,
                            $apiResponse,
                            $errorMsg
                        ]);
                    }
                }
            } catch (Throwable $ex) {
                error_log("SMS sending failed: " . $ex->getMessage());
            }

            $smsFeedback = "";
            if (isset($smsStatus)) {
                if ($smsStatus === 'Sent') {
                    $smsFeedback = " SMS notification was sent successfully.";
                } else {
                    $smsFeedback = " However, SMS notification failed to send.";
                }
            } else {
                if (empty($studentData['parent_phone'])) {
                    $smsFeedback = " (No SMS sent: Parent phone number is missing).";
                } elseif (empty($apiKey)) {
                    $smsFeedback = " (No SMS sent: SMS API token not configured in Settings).";
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully.' . $smsFeedback]);
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
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $billingId = intval($_POST['billing_id'] ?? 0);
        try {

            $fetchStmt = $pdo->prepare("SELECT * FROM student_billing WHERE id=?");
            $fetchStmt->execute([$billingId]);
            $row = $fetchStmt->fetch();
            if ($row) recordHistory($pdo, $billingId, $row, 'REMOVED', $current_user['id']);

            $stmt = $pdo->prepare("DELETE FROM student_billing WHERE id=?");
            $stmt->execute([$billingId]);
            log_audit($pdo, $current_user['id'], 'BILLING_REMOVE', "Removed billing record $billingId");
            echo json_encode(['status' => 'success', 'message' => 'Billing removed.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'bulk_billing') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }

        $studentIds = json_decode($_POST['student_ids'] ?? '[]', true);
        $bulkAction = $_POST['bulk_action'] ?? '';
        $categoryId = intval($_POST['category_id'] ?? 0);
        $settings   = getSettings($pdo);
        $year       = $settings['academic_year'] ?? date('Y');
        $term       = $settings['current_term']  ?? 'Term 1';
        $notes      = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');
        $amountPaid = floatval($_POST['amount_paid'] ?? 0);

        if (empty($studentIds) || !is_array($studentIds)) {
            echo json_encode(['status' => 'error', 'message' => 'No students selected.']);
            exit;
        }

        $processed = 0;
        $errors_   = [];

        try {
            $pdo->beginTransaction();

            foreach ($studentIds as $sid) {
                $sid = intval($sid);
                if (!$sid) continue;

                if (in_array($bulkAction, ['assign_meals', 'assign_transport'])) {

                    if (!$categoryId) {
                        $catName = ($bulkAction === 'assign_meals') ? 'Meals' : 'Transport';
                        $catStmt = $pdo->prepare("SELECT id, default_amount FROM billing_categories WHERE name=? LIMIT 1");
                        $catStmt->execute([$catName]);
                        $cat = $catStmt->fetch();
                        if (!$cat) continue;
                        $cid = $cat['id'];
                        $amt = $cat['default_amount'];
                    } else {
                        $catStmt = $pdo->prepare("SELECT default_amount FROM billing_categories WHERE id=?");
                        $catStmt->execute([$categoryId]);
                        $cat = $catStmt->fetch();
                        $cid = $categoryId;
                        $amt = $cat ? $cat['default_amount'] : 0;
                    }
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$sid, $cid, $year, $term, $amt, $current_user['id']]);

                } elseif (in_array($bulkAction, ['remove_meals', 'remove_transport'])) {
                    $catName = ($bulkAction === 'remove_meals') ? 'Meals' : 'Transport';
                    $catStmt = $pdo->prepare("SELECT id FROM billing_categories WHERE name=? LIMIT 1");
                    $catStmt->execute([$catName]);
                    $cat = $catStmt->fetch();
                    if (!$cat) continue;

                    $fStmt = $pdo->prepare("SELECT * FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
                    $fStmt->execute([$sid, $cat['id'], $year, $term]);
                    $row = $fStmt->fetch();
                    if ($row) recordHistory($pdo, $row['id'], $row, 'BULK_REMOVED', $current_user['id']);

                    $stmt = $pdo->prepare("DELETE FROM student_billing WHERE student_id=? AND category_id=? AND academic_year=? AND school_term=?");
                    $stmt->execute([$sid, $cat['id'], $year, $term]);

                } elseif (in_array($bulkAction, ['mark_paid', 'mark_partial', 'mark_unpaid', 'mark_exempt'])) {
                    $statusMap = [
                        'mark_paid'    => 'Paid',
                        'mark_partial' => 'Partial',
                        'mark_unpaid'  => 'Unpaid',
                        'mark_exempt'  => 'Exempt',
                    ];
                    $newStatus = $statusMap[$bulkAction];

                    $catWhere = $categoryId ? 'AND category_id = ?' : '';
                    $catParams = $categoryId ? [$sid, $year, $term, $categoryId] : [$sid, $year, $term];

                    $fSql = "SELECT * FROM student_billing WHERE student_id=? AND academic_year=? AND school_term=? $catWhere";
                    $fStmt = $pdo->prepare($fSql);
                    $fStmt->execute($catParams);
                    $rows = $fStmt->fetchAll();

                    foreach ($rows as $row) {
                        $payDate = ($newStatus === 'Paid' || $newStatus === 'Partial') ? date('Y-m-d') : null;
                        $paid    = ($newStatus === 'Paid') ? $row['amount_due'] : ($newStatus === 'Partial' ? $amountPaid : 0);

                        $uStmt = $pdo->prepare("
                            UPDATE student_billing
                            SET payment_status=?, payment_date=?, amount_paid=?, notes=?, updated_by=?
                            WHERE id=?
                        ");
                        $uStmt->execute([$newStatus, $payDate, $paid, $notes, $current_user['id'], $row['id']]);

                        $row['payment_status'] = $newStatus;
                        $row['payment_date']   = $payDate;
                        $row['amount_paid']    = $paid;
                        recordHistory($pdo, $row['id'], $row, 'BULK_STATUS_CHANGED', $current_user['id']);
                    }
                }
                $processed++;
            }

            $pdo->commit();
            log_audit($pdo, $current_user['id'], 'BILLING_BULK', "Bulk action '$bulkAction' on " . count($studentIds) . " students");
            echo json_encode(['status' => 'success', 'message' => "Bulk action completed. $processed student(s) processed."]);

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
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $unallocatedId = intval($_POST['unallocated_id'] ?? 0);
        $studentId = intval($_POST['student_id'] ?? 0);
        $categoryIdsJson = $_POST['category_ids'] ?? '[]';
        $categoryIds = json_decode($categoryIdsJson, true);
        if (!is_array($categoryIds)) $categoryIds = [];

        $categoryIds = array_map('intval', $categoryIds);
        $categoryIds = array_filter($categoryIds);
        
        if (!$unallocatedId || !$studentId) {
            echo json_encode(['status' => 'error', 'message' => 'Unallocated ID and Student ID are required.']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM unallocated_payments WHERE id = ? FOR UPDATE");
            $stmt->execute([$unallocatedId]);
            $unallocated = $stmt->fetch();
            
            if (!$unallocated || $unallocated['status'] !== 'Unallocated') {
                echo json_encode(['status' => 'error', 'message' => 'Payment not found or already allocated.']);
                exit;
            }
            
            $amountToAssign = floatval($unallocated['amount']);
            $receipt = $unallocated['receipt_number'];
            $paymentDate = explode(' ', $unallocated['payment_date'])[0]; // Get YYYY-MM-DD

            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            
            if (!$student) {
                echo json_encode(['status' => 'error', 'message' => 'Student not found.']);
                exit;
            }

            $sql = "SELECT sb.*, bc.name as cat_name 
                    FROM student_billing sb 
                    JOIN billing_categories bc ON sb.category_id = bc.id 
                    WHERE sb.student_id = ?";
            
            $params = [$studentId];
            if (!empty($categoryIds)) {
                $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
                $sql .= " AND sb.category_id IN ($placeholders)";
                $params = array_merge($params, $categoryIds);
            }
            $sql .= " ORDER BY (sb.payment_status IN ('Unpaid', 'Partial')) DESC, bc.sort_order ASC, sb.due_date ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $allBills = $stmt->fetchAll();
            
            $amountRemaining = $amountToAssign;
            $lastProcessedBill = null;
            
            foreach ($allBills as $bill) {
                if ($amountRemaining <= 0) break;

                $amountDue = floatval($bill['amount_due']);
                $currentPaid = floatval($bill['amount_paid']);
                $balance = $amountDue - $currentPaid;

                if ($balance <= 0) continue;

                $payAmount = min($balance, $amountRemaining);
                $newPaid = $currentPaid + $payAmount;
                $newBalance = $amountDue - $newPaid;
                
                $status = ($newBalance <= 0) ? 'Paid' : 'Partial';

                $upd = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                $upd->execute([$newPaid, $status, $paymentDate, $bill['id']]);

                $percentage = ($amountDue > 0) ? round(($newPaid / $amountDue) * 100, 2) : 100;
                $hist = $pdo->prepare("
                    INSERT INTO billing_payment_history
                    (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PAYMENT_RECORDED', ?, ?, ?, 'M-Pesa', ?)
                ");
                $hist->execute([
                    $bill['id'], $student['id'], $bill['category_id'], $bill['academic_year'], $bill['school_term'],
                    $amountDue, $newPaid, $newBalance, $status, $paymentDate, $bill['due_date'], 
                    $payAmount, $balance, $percentage, $receipt
                ]);

                $amountRemaining -= $payAmount;
                $lastProcessedBill = $bill;
            }

            if ($amountRemaining > 0) {

                $targetBill = $lastProcessedBill;
                if (!$targetBill && count($allBills) > 0) {
                    $targetBill = $allBills[0]; // If no unpaid bills existed, just use the first bill fetched
                }
                
                if ($targetBill) {
                    $amountDue = floatval($targetBill['amount_due']);

                    $curStmt = $pdo->prepare("SELECT amount_paid FROM student_billing WHERE id = ?");
                    $curStmt->execute([$targetBill['id']]);
                    $currentPaid = floatval($curStmt->fetchColumn());
                    
                    $balance = $amountDue - $currentPaid;
                    
                    $newPaid = $currentPaid + $amountRemaining;
                    $newBalance = $amountDue - $newPaid; // Will be negative (overpaid)
                    $status = 'Paid'; // Still paid

                    $upd = $pdo->prepare("UPDATE student_billing SET amount_paid = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                    $upd->execute([$newPaid, $status, $paymentDate, $targetBill['id']]);

                    $percentage = ($amountDue > 0) ? round(($newPaid / $amountDue) * 100, 2) : 100;
                    $hist = $pdo->prepare("
                        INSERT INTO billing_payment_history
                        (billing_id, student_id, category_id, academic_year, school_term, amount_due, amount_paid, balance, payment_status, payment_date, due_date, action, transaction_amount, previous_balance, payment_percentage, payment_method, reference_number)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OVERPAYMENT_RECORDED', ?, ?, ?, 'M-Pesa', ?)
                    ");
                    $hist->execute([
                        $targetBill['id'], $student['id'], $targetBill['category_id'], $targetBill['academic_year'], $targetBill['school_term'],
                        $amountDue, $newPaid, $newBalance, $status, $paymentDate, $targetBill['due_date'], 
                        $amountRemaining, $balance, $percentage, $receipt
                    ]);
                    
                    $amountRemaining = 0;
                }
            }

            $updUnalloc = $pdo->prepare("UPDATE unallocated_payments SET status = 'Allocated', allocated_student_id = ? WHERE id = ?");
            $updUnalloc->execute([$studentId, $unallocatedId]);

            if (function_exists('sendPaymentSMS')) {

                $balStmt = $pdo->prepare("SELECT SUM(amount_due - amount_paid) FROM student_billing WHERE student_id = ? AND payment_status IN ('Unpaid', 'Partial')");
                $balStmt->execute([$studentId]);
                $totalBalance = floatval($balStmt->fetchColumn());
                
                sendPaymentSMS($pdo, $student, $amountToAssign, $totalBalance, 'Processed');
            }
            
            log_audit($pdo, $current_user['id'], 'BILLING_ALLOCATE', "Allocated $amountToAssign (Receipt: $receipt) to student $studentId");
            
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
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Administrators only.']);
            exit;
        }
        $unallocatedId = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM unallocated_payments WHERE id = ?");
        $stmt->execute([$unallocatedId]);
        echo json_encode(['status' => 'success', 'message' => 'Record deleted.']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown POST action.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);

