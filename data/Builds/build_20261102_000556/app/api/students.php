<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
error_reporting(0); // Prevent PHP warnings from corrupting JSON response
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

function handlePhotoUpload($x_0001) {
    if ($x_0001['error'] !== UPLOAD_ERR_OK) return null;
    if ($x_0001['size'] > 2 * 1024 * 1024) return ['error' => 'File size exceeds 2MB limit.'];
    
    $x_0002 = finfo_open(FILEINFO_MIME_TYPE);
    $x_0003 = finfo_file($x_0002, $x_0001['tmp_name']);
    finfo_close($x_0002);
    
    $x_0004 = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($x_0003, $x_0004)) return ['error' => 'Invalid file type.'];
    
    $x_0005 = strtolower(pathinfo($x_0001['name'], PATHINFO_EXTENSION));
    $x_0006 = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($x_0005, $x_0006)) return ['error' => 'Invalid file extension.'];
    
    $x_0007 = file_get_contents($x_0001['tmp_name']);
    if (preg_match('/<\?php|<script/i', $x_0007)) {
        return ['error' => 'Malicious content detected.'];
    }
    
    $x_0008 = '../uploads/photos/';
    if (!is_dir($x_0008)) @mkdir($x_0008, 0777, true);
    
    $x_0009 = hash('sha256', uniqid(rand(), true)) . '.' . $x_0005;
    if (move_uploaded_file($x_0001['tmp_name'], $x_0008 . $x_0009)) {
        return ['path' => 'uploads/photos/' . $x_0009];
    }
    return ['error' => 'Failed to move uploaded file.'];
}

$x_000a = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Uncle', 'Aunt', 'Other'];

function normalizeAndValidatePhone($x_000b) {
    $x_000c = preg_replace('/[\s\-\(\)]/', '', trim($x_000b));

    if (str_starts_with($x_000c, '0') && strlen($x_000c) === 10) {
        $x_000c = '254' . substr($x_000c, 1);
    }
    
    if (preg_match('/^\+?[0-9]{7,15}$/', $x_000c)) {
        return $x_000c;
    }
    return false;
}

function validateParentGuardianInput($x_000d, $x_000e) {
    $x_000f = htmlspecialchars(trim($x_000d['parent_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0010 = trim($x_000d['parent_phone'] ?? '');
    $x_0011 = htmlspecialchars(trim($x_000d['parent_relationship'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0012 = htmlspecialchars(trim($x_000d['parent_relationship_other'] ?? ''), ENT_QUOTES, 'UTF-8');

    if (empty($x_000f)) {
        return ['error' => 'Parent/Guardian full name is required.'];
    }
    if (empty($x_0010)) {
        return ['error' => 'Parent/Guardian phone number is required.'];
    }
    $x_0013 = normalizeAndValidatePhone($x_0010);
    if ($x_0013 === false) {
        return ['error' => 'Please enter a valid Parent/Guardian phone number (e.g. +254712345678 or 0712345678).'];
    }
    if (empty($x_0011) || !in_array($x_0011, $x_000e, true)) {
        return ['error' => 'Please select a valid Relationship to Student.'];
    }
    if ($x_0011 === 'Other') {
        if (empty($x_0012)) {
            return ['error' => 'Please specify the relationship to student.'];
        }
    } else {

        $x_0012 = null;
    }

    return [
        'parent_name' => $x_000f,
        'parent_phone' => $x_0013,
        'parent_relationship' => $x_0011,
        'parent_relationship_other' => $x_0012,
    ];
}

function isMissingColumnError($e) {
    return $e->getCode() === '42S22' || strpos($e->getMessage(), '1054') !== false;
}

function sync_student_billing($x_0014, $x_0015, $x_0016, $x_0017) {
    $x_0018 = $x_0014->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('academic_year','current_term')");
    $x_0019 = $x_0018->fetchAll(PDO::FETCH_ASSOC);
    $x_001a  = [];
    foreach ($x_0019 as $sr) $x_001a[$sr['setting_key']] = $sr['setting_value'];
    $x_001b = $x_001a['academic_year'] ?? date('Y');
    $x_001c = $x_001a['current_term']  ?? 'Term 1';

    $x_001d = $x_0014->query("SELECT * FROM billing_categories WHERE is_active = 1");
    $x_001e = $x_001d->fetchAll();

    foreach ($x_001e as $x_001f) {
        $x_0020 = floatval($x_001f['default_amount']);
        $x_0021 = $x_001f['assignment_type'];
        $x_0022 = json_decode($x_001f['assigned_classes'] ?: '[]', true) ?: [];
        $x_0023 = is_array($x_0022) && !empty($x_0022) && (array_keys($x_0022) !== range(0, count($x_0022) - 1));

        $x_0024 = false;
        $x_0025 = $x_0020;

        if ($x_0021 === 'Global') {
            $x_0024 = true;
        } else {
            if ($x_0023) {
                if (array_key_exists($x_0016, $x_0022)) {
                    $x_0024 = true;
                    if (is_numeric($x_0022[$x_0016])) {
                        $x_0025 = floatval($x_0022[$x_0016]);
                    }
                }
            } else {
                if (in_array($x_0016, $x_0022)) {
                    $x_0024 = true;
                }
            }
        }

        if ($x_0024) {
            $x_0026 = $x_0014->prepare("
                INSERT IGNORE INTO student_billing (student_id, category_id, academic_year, school_term, amount_due, payment_status, updated_by)
                VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)
            ");
            $x_0026->execute([$x_0015, $x_001f['id'], $x_001b, $x_001c, $x_0025, $x_0017]);
        }
    }
}

$x_0027 = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($x_0027 === 'delete') {
        $id = $_POST['id'] ?? 0;
        try {

            $x_0028 = $x_0014->prepare("SELECT photo FROM students WHERE id = ?");
            $x_0028->execute([$id]);
            $x_0029 = $x_0028->fetch();
            if ($x_0029 && $x_0029['photo']) {
                $x_002a = '../' . $x_0029['photo'];
                if (file_exists($x_002a)) {
                    unlink($x_002a);
                }
            }

            $x_0028 = $x_0014->prepare("DELETE FROM student_billing WHERE student_id = ?");
            $x_0028->execute([$id]);
            $x_0028 = $x_0014->prepare("DELETE FROM billing_payment_history WHERE student_id = ?");
            $x_0028->execute([$id]);
            $x_0028 = $x_0014->prepare("DELETE FROM custom_scanner_logs WHERE student_id = ?");
            $x_0028->execute([$id]);
            $x_0028 = $x_0014->prepare("DELETE FROM attendance WHERE student_id = ?");
            $x_0028->execute([$id]);
            $x_0028 = $x_0014->prepare("DELETE FROM sms_logs WHERE student_id = ?");
            $x_0028->execute([$id]);

            $x_0028 = $x_0014->prepare("DELETE FROM students WHERE id = ?");
            $x_0028->execute([$id]);
            log_audit($x_0014, $x_002b['id'] ?? null, 'DELETE_STUDENT', "Deleted student ID $id");
            echo json_encode(["status" => "success", "message" => "Student deleted successfully."]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
        exit;
    } else if ($x_0027 === 'edit') {
        $id = $_POST['id'] ?? 0;
        $x_002c = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_002d = htmlspecialchars(trim($_POST['admission_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_002e = htmlspecialchars(trim($_POST['class'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_002f = htmlspecialchars(trim($_POST['gender'] ?? ''), ENT_QUOTES, 'UTF-8');

        $x_0030 = $_POST['dob'] ?? null;
        $x_0031 = $_POST['blood_group'] ?? null;
        $x_0032 = $_POST['emergency_contact'] ?? null;
        $x_0033 = $_POST['admitted_date'] ?? null;
        $x_0034 = $_POST['valid_until'] ?? null;

        $x_0035 = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;
        $x_0036 = htmlspecialchars(trim($_POST['route_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0037 = htmlspecialchars(trim($_POST['pickup_location'] ?? ''), ENT_QUOTES, 'UTF-8');
        $x_0038 = htmlspecialchars(trim($_POST['dropoff_location'] ?? ''), ENT_QUOTES, 'UTF-8');

        if(empty($x_002c) || empty($x_002d) || empty($x_002e) || empty($x_002f) || empty($id)) {
            echo json_encode(["status" => "error", "message" => "All fields are required."]);
            exit;
        }

        $x_0039 = validateParentGuardianInput($_POST, $x_000a);
        if (isset($x_0039['error'])) {
            echo json_encode(["status" => "error", "message" => $x_0039['error']]);
            exit;
        }

        $x_003a = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $x_003b = handlePhotoUpload($_FILES['photo']);
            if (isset($x_003b['error'])) {
                echo json_encode(["status" => "error", "message" => $x_003b['error']]);
                exit;
            }
            $x_003a = $x_003b['path'];

            $x_0028 = $x_0014->prepare("SELECT photo FROM students WHERE id = ?");
            $x_0028->execute([$id]);
            $x_003c = $x_0028->fetch();
            if ($x_003c && $x_003c['photo'] && file_exists('../' . $x_003c['photo'])) {
                unlink('../' . $x_003c['photo']);
            }
        }

        try {
            if ($x_003a) {
                $x_0028 = $x_0014->prepare("UPDATE students SET full_name = ?, admission_number = ?, class = ?, gender = ?, dob = ?, blood_group = ?, emergency_contact = ?, admitted_date = ?, valid_until = ?, parent_name = ?, parent_phone = ?, parent_relationship = ?, parent_relationship_other = ?, assigned_bus_id = ?, route_number = ?, pickup_location = ?, dropoff_location = ?, photo = ? WHERE id = ?");
                $x_0028->execute([$x_002c, $x_002d, $x_002e, $x_002f, $x_0030, $x_0031, $x_0032, $x_0033, $x_0034, $x_0039['parent_name'], $x_0039['parent_phone'], $x_0039['parent_relationship'], $x_0039['parent_relationship_other'], $x_0035, $x_0036, $x_0037, $x_0038, $x_003a, $id]);
            } else {
                $x_0028 = $x_0014->prepare("UPDATE students SET full_name = ?, admission_number = ?, class = ?, gender = ?, dob = ?, blood_group = ?, emergency_contact = ?, admitted_date = ?, valid_until = ?, parent_name = ?, parent_phone = ?, parent_relationship = ?, parent_relationship_other = ?, assigned_bus_id = ?, route_number = ?, pickup_location = ?, dropoff_location = ? WHERE id = ?");
                $x_0028->execute([$x_002c, $x_002d, $x_002e, $x_002f, $x_0030, $x_0031, $x_0032, $x_0033, $x_0034, $x_0039['parent_name'], $x_0039['parent_phone'], $x_0039['parent_relationship'], $x_0039['parent_relationship_other'], $x_0035, $x_0036, $x_0037, $x_0038, $id]);
            }
            sync_student_billing($x_0014, $id, $x_002e, $x_002b['id'] ?? null);
            log_audit($x_0014, $x_002b['id'] ?? null, 'EDIT_STUDENT', "Updated student ID $id");
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
    } else if ($x_0027 === 'assign_qr') {
        $id = $_POST['id'] ?? 0;
        $x_003d = $_POST['qr_code'] ?? '';

        if(empty($id) || empty($x_003d)) {
            echo json_encode(["status" => "error", "message" => "Student ID and QR Code are required."]);
            exit;
        }

        try {

            $x_0028 = $x_0014->prepare("SELECT id, full_name FROM students WHERE qr_code = ? AND id != ?");
            $x_0028->execute([$x_003d, $id]);
            $x_003e = $x_0028->fetch();

            if ($x_003e) {
                echo json_encode(["status" => "error", "message" => "This QR code is already assigned to another student: " . $x_003e['full_name']]);
                exit;
            }

            $x_0028 = $x_0014->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
            $x_0028->execute([$x_003d, $id]);
            log_audit($x_0014, $x_002b['id'] ?? null, 'ASSIGN_QR', "Assigned QR code to student ID $id");
            echo json_encode(["status" => "success", "message" => "QR Code assigned successfully."]);
        } catch (PDOException $e) {
            error_log("Database error in students.php assign_qr: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong."]);
        }
        exit;
    }

    $x_002c = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_002d = htmlspecialchars(trim($_POST['admission_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_002e = htmlspecialchars(trim($_POST['class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_002f = htmlspecialchars(trim($_POST['gender'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0030 = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $x_0031 = !empty($_POST['blood_group']) ? $_POST['blood_group'] : null;
    $x_0032 = !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null;
    $x_0033 = !empty($_POST['admitted_date']) ? $_POST['admitted_date'] : null;
    $x_0034 = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;

    $x_0035 = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;
    $x_0036 = htmlspecialchars(trim($_POST['route_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0037 = htmlspecialchars(trim($_POST['pickup_location'] ?? ''), ENT_QUOTES, 'UTF-8');
    $x_0038 = htmlspecialchars(trim($_POST['dropoff_location'] ?? ''), ENT_QUOTES, 'UTF-8');
    
    if(empty($x_002c) || empty($x_002d) || empty($x_002e) || empty($x_002f)) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit;
    }

    $x_0039 = validateParentGuardianInput($_POST, $x_000a);
    if (isset($x_0039['error'])) {
        echo json_encode(["status" => "error", "message" => $x_0039['error']]);
        exit;
    }

    $x_003d = 'QR-' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $x_002d)) . '-' . strtoupper(substr(uniqid(), -6));

    $x_003a = null;
    $x_003f = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $x_003b = handlePhotoUpload($_FILES['photo']);
        if (isset($x_003b['error'])) {
            echo json_encode(["status" => "error", "message" => $x_003b['error']]);
            exit;
        }
        $x_003a = $x_003b['path'];
    }

    try {
        $x_0028 = $x_0014->prepare("INSERT INTO students (full_name, admission_number, class, gender, dob, blood_group, emergency_contact, admitted_date, valid_until, photo, qr_code, parent_name, parent_phone, parent_relationship, parent_relationship_other, assigned_bus_id, route_number, pickup_location, dropoff_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $x_0028->execute([$x_002c, $x_002d, $x_002e, $x_002f, $x_0030, $x_0031, $x_0032, $x_0033, $x_0034, $x_003a, $x_003d, $x_0039['parent_name'], $x_0039['parent_phone'], $x_0039['parent_relationship'], $x_0039['parent_relationship_other'], $x_0035, $x_0036, $x_0037, $x_0038]);
        $x_0040 = $x_0014->lastInsertId();
        log_audit($x_0014, $x_002b['id'] ?? null, 'ADD_STUDENT', "Added student: $x_002c");

        try {
            sync_student_billing($x_0014, $x_0040, $x_002e, $x_002b['id'] ?? null);
        } catch (PDOException $be) {

            error_log("Billing auto-assign skipped (run billing_migrate.php): " . $be->getMessage());
        }

        echo json_encode(["status" => "success", "message" => "Student registered successfully.", "qr_code" => $x_003d, "upload_debug" => $x_003f]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation (Duplicate)
            echo json_encode(["status" => "error", "message" => "A student with this admission number already exists.", "upload_debug" => $x_003f]);
        } else if (isMissingColumnError($e)) {
            error_log("Database error in students.php add: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Setup incomplete: the database is missing the Parent/Guardian columns. Please run add_parent_guardian_fields.php once (from your site root, e.g. https://yoursite.com/add_parent_guardian_fields.php), then try again.", "upload_debug" => $x_003f]);
        } else {
            error_log("Database error in students.php add: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong.", "upload_debug" => $x_003f]);
        }
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($x_0027 === 'list') {
        try {
            $x_0028 = $x_0014->query("
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
            $x_0041 = $x_0028->fetchAll();
            echo json_encode(["status" => "success", "data" => $x_0041]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else if ($x_0027 === 'get') {
        $id = $_GET['id'] ?? 0;
        try {
            $x_0028 = $x_0014->prepare("SELECT * FROM students WHERE id = ?");
            $x_0028->execute([$id]);
            $x_0029 = $x_0028->fetch();
            
            if ($x_0029) {

                $x_0042 = $x_0014->prepare("SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN attendance_status = 'Present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN attendance_status = 'Absent' THEN 1 ELSE 0 END) as absent_days
                    FROM attendance WHERE student_id = ?");
                $x_0042->execute([$id]);
                $x_0043 = $x_0042->fetch();
                $x_0029['stats'] = $x_0043;
                
                echo json_encode(["status" => "success", "data" => $x_0029]);
            } else {
                echo json_encode(["status" => "error", "message" => "Student not found."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}
?>
