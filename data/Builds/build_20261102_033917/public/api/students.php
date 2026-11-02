<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
error_reporting(0); // Prevent PHP warnings from corrupting JSON response
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

function handlePhotoUpload($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 2 * 1024 * 1024) return ['error' => 'File size exceeds 2MB limit.'];
    
    $x_0001 = finfo_open(FILEINFO_MIME_TYPE);
    $x_0002 = finfo_file($x_0001, $file['tmp_name']);
    finfo_close($x_0001);
    
    $x_0003 = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($x_0002, $x_0003)) return ['error' => 'Invalid file type.'];
    
    $x_0004 = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $x_0005 = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($x_0004, $x_0005)) return ['error' => 'Invalid file extension.'];
    
    $x_0006 = file_get_contents($file['tmp_name']);
    if (preg_match('/<\?php|<script/i', $x_0006)) {
        return ['error' => 'Malicious content detected.'];
    }
    
    $x_0007 = '../uploads/photos/';
    if (!is_dir($x_0007)) @mkdir($x_0007, 0777, true);
    
    $x_0008 = hash('sha256', uniqid(rand(), true)) . '.' . $x_0004;
    if (move_uploaded_file($file['tmp_name'], $x_0007 . $x_0008)) {
        return ['path' => 'uploads/photos/' . $x_0008];
    }
    return ['error' => 'Failed to move uploaded file.'];
}

$x_0009 = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Uncle', 'Aunt', 'Other'];

function normalizeAndValidatePhone($x_000a) {
    $x_000b = preg_replace('/[\s\-\(\)]/', '', trim($x_000a));

    if (str_starts_with($x_000b, '0') && strlen($x_000b) === 10) {
        $x_000b = '254' . substr($x_000b, 1);
    }
    
    if (preg_match('/^\+?[0-9]{7,15}$/', $x_000b)) {
        return $x_000b;
    }
    return false;
}

function validateParentGuardianInput($x_000c, $x_000d) {
    $x_000e = htmlspecialchars(trim($x_000c['parent_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_000f = trim($x_000c['parent_phone'] ?? '');
    $x_0010 = htmlspecialchars(trim($x_000c['parent_relationship'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0011 = htmlspecialchars(trim($x_000c['parent_relationship_other'] ?? ''), ENT_QUOTES, 'UTF-8');

    if (empty($x_000e)) {
        return ['error' => 'Parent/Guardian full name is required.'];
    }
    if (empty($x_000f)) {
        return ['error' => 'Parent/Guardian phone number is required.'];
    }
    $x_0012 = normalizeAndValidatePhone($x_000f);
    if ($x_0012 === false) {
        return ['error' => 'Please enter a valid Parent/Guardian phone number (e.g. +254712345678 or 0712345678).'];
    }
    if (empty($x_0010) || !in_array($x_0010, $x_000d, true)) {
        return ['error' => 'Please select a valid Relationship to Student.'];
    }
    if ($x_0010 === 'Other') {
        if (empty($x_0011)) {
            return ['error' => 'Please specify the relationship to student.'];
        }
    } else {

        $x_0011 = null;
    }

    return [
        'parent_name' => $x_000e,
        'parent_phone' => $x_0012,
        'parent_relationship' => $x_0010,
        'parent_relationship_other' => $x_0011,
    ];
}

function isMissingColumnError($e) {
    return $e->getCode() === '42S22' || strpos($e->getMessage(), '1054') !== false;
}

function sync_student_billing($pdo, $x_0013, $x_0014, $userId) {
    $x_0015 = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('academic_year','current_term')");
    $x_0016 = $x_0015->fetchAll(PDO::FETCH_ASSOC);
    $x_0017  = [];
    foreach ($x_0016 as $sr) $x_0017[$sr['setting_key']] = $sr['setting_value'];
    $x_0018 = $x_0017['academic_year'] ?? date('Y');
    $x_0019 = $x_0017['current_term']  ?? 'Term 1';

    $x_001a = $pdo->query("SELECT * FROM billing_categories WHERE is_active = 1");
    $x_001b = $x_001a->fetchAll();

    foreach ($x_001b as $x_001c) {
        $x_001d = floatval($x_001c['default_amount']);
        $x_001e = $x_001c['assignment_type'];
        $x_001f = json_decode($x_001c['assigned_classes'] ?: '[]', true) ?: [];
        $x_0020 = is_array($x_001f) && !empty($x_001f) && (array_keys($x_001f) !== range(0, count($x_001f) - 1));

        $x_0021 = false;
        $x_0022 = $x_001d;

        if ($x_001e === 'Global') {
            $x_0021 = true;
        } else {
            if ($x_0020) {
                if (array_key_exists($x_0014, $x_001f)) {
                    $x_0021 = true;
                    if (is_numeric($x_001f[$x_0014])) {
                        $x_0022 = floatval($x_001f[$x_0014]);
                    }
                }
            } else {
                if (in_array($x_0014, $x_001f)) {
                    $x_0021 = true;
                }
            }
        }

        if ($x_0021) {
            $x_0023 = $pdo->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)
            ");
            $x_0023->execute([$x_0013, $x_001c['id'], $x_0018, $x_0019, $x_0022, $userId]);
        }
    }
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        try {

            $stmt = $pdo->prepare("SELECT photo FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $x_0024 = $stmt->fetch();
            if ($x_0024 && $x_0024['photo']) {
                $x_0025 = '../' . $x_0024['photo'];
                if (file_exists($x_0025)) {
                    unlink($x_0025);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM student_billing WHERE student_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM billing_payment_history WHERE student_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM custom_scanner_logs WHERE student_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM attendance WHERE student_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM sms_logs WHERE student_id = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$id]);
            log_audit($pdo, $x_0026['id'] ?? null, 'DELETE_STUDENT', "Deleted student ID $id");
            echo json_encode(["status" => "success", "message" => "Student deleted successfully."]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
        exit;
    } else if ($action === 'edit') {
        $id = $_POST['id'] ?? 0;
        $x_0027 = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0028 = htmlspecialchars(trim($_POST['admission_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0029 = htmlspecialchars(trim($_POST['class'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_002a = htmlspecialchars(trim($_POST['gender'] ?? ''), ENT_QUOTES, 'UTF-8');

        $x_002b = $_POST['dob'] ?? null;
        $x_002c = $_POST['blood_group'] ?? null;
        $x_002d = $_POST['emergency_contact'] ?? null;
        $x_002e = $_POST['admitted_date'] ?? null;
        $x_002f = $_POST['valid_until'] ?? null;

        $x_0030 = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;
        $x_0031 = htmlspecialchars(trim($_POST['route_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0032 = htmlspecialchars(trim($_POST['pickup_location'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0033 = htmlspecialchars(trim($_POST['dropoff_location'] ?? ''), ENT_QUOTES, 'UTF-8');

        if(empty($x_0027) || empty($x_0028) || empty($x_0029) || empty($x_002a) || empty($id)) {
            echo json_encode(["status" => "error", "message" => "All fields are required."]);
            exit;
        }

        $x_0034 = validateParentGuardianInput($_POST, $x_0009);
        if (isset($x_0034['error'])) {
            echo json_encode(["status" => "error", "message" => $x_0034['error']]);
            exit;
        }

        $x_0035 = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $x_0036 = handlePhotoUpload($_FILES['photo']);
            if (isset($x_0036['error'])) {
                echo json_encode(["status" => "error", "message" => $x_0036['error']]);
                exit;
            }
            $x_0035 = $x_0036['path'];

            $stmt = $pdo->prepare("SELECT photo FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $x_0037 = $stmt->fetch();
            if ($x_0037 && $x_0037['photo'] && file_exists('../' . $x_0037['photo'])) {
                unlink('../' . $x_0037['photo']);
            }
        }

        try {
            if ($x_0035) {
                $stmt = $pdo->prepare("UPDATE students SET full_name = ?, admission_number = ?, class = ?, gender = ?, dob = ?, blood_group = ?, emergency_contact = ?, admitted_date = ?, valid_until = ?, parent_name = ?, parent_phone = ?, parent_relationship = ?, parent_relationship_other = ?, assigned_bus_id = ?, route_number = ?, pickup_location = ?, dropoff_location = ?, photo = ? WHERE id = ?");
                $stmt->execute([$x_0027, $x_0028, $x_0029, $x_002a, $x_002b, $x_002c, $x_002d, $x_002e, $x_002f, $x_0034['parent_name'], $x_0034['parent_phone'], $x_0034['parent_relationship'], $x_0034['parent_relationship_other'], $x_0030, $x_0031, $x_0032, $x_0033, $x_0035, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE students SET full_name = ?, admission_number = ?, class = ?, gender = ?, dob = ?, blood_group = ?, emergency_contact = ?, admitted_date = ?, valid_until = ?, parent_name = ?, parent_phone = ?, parent_relationship = ?, parent_relationship_other = ?, assigned_bus_id = ?, route_number = ?, pickup_location = ?, dropoff_location = ? WHERE id = ?");
                $stmt->execute([$x_0027, $x_0028, $x_0029, $x_002a, $x_002b, $x_002c, $x_002d, $x_002e, $x_002f, $x_0034['parent_name'], $x_0034['parent_phone'], $x_0034['parent_relationship'], $x_0034['parent_relationship_other'], $x_0030, $x_0031, $x_0032, $x_0033, $id]);
            }
            sync_student_billing($pdo, $id, $x_0029, $x_0026['id'] ?? null);
            log_audit($pdo, $x_0026['id'] ?? null, 'EDIT_STUDENT', "Updated student ID $id");
            echo json_encode(["status" => "success", "message" => "Student updated successfully."]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                echo json_encode(["status" => "error", "message" => "A student with this admission number already exists."]);
            } else if (isMissingColumnError($e)) {
                error_log("Database error in students.php edit: " . $e->getMessage());
                echo json_encode(["status" => "error", "message" => "Setup incomplete: the database is missing the Parent/Guardian columns. Please run add_parent_guardian_fields.php once (from your site root, e.g. https://yoursite.com/add_parent_guardian_fields.php), then try again."]);
            } else {
                error_log("Database error in students.php edit: " . $e->getMessage());
                echo json_encode(["status" => "error", "message" => "Something went wrong."]);
            }
        }
        exit;
    } else if ($action === 'assign_qr') {
        $id = $_POST['id'] ?? 0;
        $x_0038 = $_POST['qr_code'] ?? '';

        if(empty($id) || empty($x_0038)) {
            echo json_encode(["status" => "error", "message" => "Student ID and QR Code are required."]);
            exit;
        }

        try {

            $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE qr_code = ? AND id != ?");
            $stmt->execute([$x_0038, $id]);
            $x_0039 = $stmt->fetch();

            if ($x_0039) {
                echo json_encode(["status" => "error", "message" => "This QR code is already assigned to another student: " . $x_0039['full_name']]);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
            $stmt->execute([$x_0038, $id]);
            log_audit($pdo, $x_0026['id'] ?? null, 'ASSIGN_QR', "Assigned QR code to student ID $id");
            echo json_encode(["status" => "success", "message" => "QR Code assigned successfully."]);
        } catch (PDOException $e) {
            error_log("Database error in students.php assign_qr: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong."]);
        }
        exit;
    }

    $x_0027 = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0028 = htmlspecialchars(trim($_POST['admission_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0029 = htmlspecialchars(trim($_POST['class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_002a = htmlspecialchars(trim($_POST['gender'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_002b = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $x_002c = !empty($_POST['blood_group']) ? $_POST['blood_group'] : null;
    $x_002d = !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null;
    $x_002e = !empty($_POST['admitted_date']) ? $_POST['admitted_date'] : null;
    $x_002f = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;

    $x_0030 = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;
    $x_0031 = htmlspecialchars(trim($_POST['route_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0032 = htmlspecialchars(trim($_POST['pickup_location'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0033 = htmlspecialchars(trim($_POST['dropoff_location'] ?? ''), ENT_QUOTES, 'UTF-8');
    
    if(empty($x_0027) || empty($x_0028) || empty($x_0029) || empty($x_002a)) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit;
    }

    $x_0034 = validateParentGuardianInput($_POST, $x_0009);
    if (isset($x_0034['error'])) {
        echo json_encode(["status" => "error", "message" => $x_0034['error']]);
        exit;
    }

    $x_0038 = 'QR-' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $x_0028)) . '-' . strtoupper(substr(uniqid(), -6));

    $x_0035 = null;
    $x_003a = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $x_0036 = handlePhotoUpload($_FILES['photo']);
        if (isset($x_0036['error'])) {
            echo json_encode(["status" => "error", "message" => $x_0036['error']]);
            exit;
        }
        $x_0035 = $x_0036['path'];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO students (full_name, admission_number, class, gender, dob, blood_group, emergency_contact, admitted_date, valid_until, photo, qr_code, parent_name, parent_phone, parent_relationship, parent_relationship_other, assigned_bus_id, route_number, pickup_location, dropoff_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$x_0027, $x_0028, $x_0029, $x_002a, $x_002b, $x_002c, $x_002d, $x_002e, $x_002f, $x_0035, $x_0038, $x_0034['parent_name'], $x_0034['parent_phone'], $x_0034['parent_relationship'], $x_0034['parent_relationship_other'], $x_0030, $x_0031, $x_0032, $x_0033]);
        $x_003b = $pdo->lastInsertId();
        log_audit($pdo, $x_0026['id'] ?? null, 'ADD_STUDENT', "Added student: $x_0027");

        try {
            sync_student_billing($pdo, $x_003b, $x_0029, $x_0026['id'] ?? null);
        } catch (PDOException $be) {

            error_log("Billing auto-assign skipped (run billing_migrate.php): " . $be->getMessage());
        }

        echo json_encode(["status" => "success", "message" => "Student registered successfully.", "qr_code" => $x_0038, "upload_debug" => $x_003a]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation (Duplicate)
            echo json_encode(["status" => "error", "message" => "A student with this admission number already exists.", "upload_debug" => $x_003a]);
        } else if (isMissingColumnError($e)) {
            error_log("Database error in students.php add: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Setup incomplete: the database is missing the Parent/Guardian columns. Please run add_parent_guardian_fields.php once (from your site root, e.g. https://yoursite.com/add_parent_guardian_fields.php), then try again.", "upload_debug" => $x_003a]);
        } else {
            error_log("Database error in students.php add: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong.", "upload_debug" => $x_003a]);
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'list') {
        try {
            $stmt = $pdo->query("
                SELECT s.*, 
                    (SELECT sb.payment_status 
                     FROM student_billing sb
                     JOIN billing_categories bc ON sb.category_id = bc.id
                     WHERE sb.student_id = s.id AND bc.name = 'School Fees'
                     ORDER BY sb.academic_year DESC, sb.school_term DESC 
                     LIMIT 1) AS billing_status
                FROM students s 
                ORDER BY s.id DESC
            ");
            $x_003c = $stmt->fetchAll();
            echo json_encode(["status" => "success", "data" => $x_003c]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else if ($action === 'get') {
        $id = $_GET['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $x_0024 = $stmt->fetch();
            
            if ($x_0024) {

                $x_003d = $pdo->prepare("SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN attendance_status = 'Present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN attendance_status = 'Absent' THEN 1 ELSE 0 END) as absent_days
                    FROM attendance WHERE student_id = ?");
                $x_003d->execute([$id]);
                $x_003e = $x_003d->fetch();
                $x_0024['stats'] = $x_003e;
                
                echo json_encode(["status" => "success", "data" => $x_0024]);
            } else {
                echo json_encode(["status" => "error", "message" => "Student not found."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}
?>
