<?php
error_reporting(0); // Prevent PHP warnings from corrupting JSON response
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

function handlePhotoUpload($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 2 * 1024 * 1024) return ['error' => 'File size exceeds 2MB limit.'];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($mime, $allowed_mimes)) return ['error' => 'Invalid file type.'];
    
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($file_ext, $allowed_exts)) return ['error' => 'Invalid file extension.'];
    
    $content = file_get_contents($file['tmp_name']);
    if (preg_match('/<\?php|<script/i', $content)) {
        return ['error' => 'Malicious content detected.'];
    }
    
    $upload_dir = '../uploads/photos/';
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
    
    $filename = hash('sha256', uniqid(rand(), true)) . '.' . $file_ext;
    if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        return ['path' => 'uploads/photos/' . $filename];
    }
    return ['error' => 'Failed to move uploaded file.'];
}

// Allowed values for the Relationship to Student dropdown.
// Kept as a single source of truth so the "Other" handling stays consistent.
$ALLOWED_RELATIONSHIPS = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Uncle', 'Aunt', 'Other'];

/**
 * Validates a Parent/Guardian phone number.
 * Supported format: an optional leading "+", followed by 7-15 digits.
 * Spaces, hyphens, and parentheses are allowed as visual separators and are
 * stripped before validation/storage so the stored value is consistent and
 * ready for reuse by future modules (SMS, WhatsApp, fee/attendance
 * notifications, emergency contact features, etc).
 */
function normalizeAndValidatePhone($raw) {
    $stripped = preg_replace('/[\s\-\(\)]/', '', trim($raw));
    
    // Auto-convert Kenyan local format (07... or 01...) to 254...
    if (str_starts_with($stripped, '0') && strlen($stripped) === 10) {
        $stripped = '254' . substr($stripped, 1);
    }
    
    if (preg_match('/^\+?[0-9]{7,15}$/', $stripped)) {
        return $stripped;
    }
    return false;
}

/**
 * Validates and normalizes Parent/Guardian Information submitted via POST.
 * Returns ['error' => string] on failure, or an array of the DB-ready
 * fields (parent_name, parent_phone, parent_relationship,
 * parent_relationship_other) on success.
 */
function validateParentGuardianInput($post, $allowedRelationships) {
    $parent_name = htmlspecialchars(trim($post['parent_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $parent_phone_raw = trim($post['parent_phone'] ?? '');
    $parent_relationship = htmlspecialchars(trim($post['parent_relationship'] ?? ''), ENT_QUOTES, 'UTF-8');
    $parent_relationship_other = htmlspecialchars(trim($post['parent_relationship_other'] ?? ''), ENT_QUOTES, 'UTF-8');

    if (empty($parent_name)) {
        return ['error' => 'Parent/Guardian full name is required.'];
    }
    if (empty($parent_phone_raw)) {
        return ['error' => 'Parent/Guardian phone number is required.'];
    }
    $parent_phone = normalizeAndValidatePhone($parent_phone_raw);
    if ($parent_phone === false) {
        return ['error' => 'Please enter a valid Parent/Guardian phone number (e.g. +254712345678 or 0712345678).'];
    }
    if (empty($parent_relationship) || !in_array($parent_relationship, $allowedRelationships, true)) {
        return ['error' => 'Please select a valid Relationship to Student.'];
    }
    if ($parent_relationship === 'Other') {
        if (empty($parent_relationship_other)) {
            return ['error' => 'Please specify the relationship to student.'];
        }
    } else {
        // Only "Other" carries a custom relationship value; keep the column clean otherwise.
        $parent_relationship_other = null;
    }

    return [
        'parent_name' => $parent_name,
        'parent_phone' => $parent_phone,
        'parent_relationship' => $parent_relationship,
        'parent_relationship_other' => $parent_relationship_other,
    ];
}

/**
 * Detects the "Unknown column" MySQL error (SQLSTATE 42S22 / driver code 1054),
 * which happens when the Parent/Guardian migration hasn't been run yet on
 * this database. Lets us return an actionable message instead of a vague
 * "Something went wrong."
 */
function isMissingColumnError($e) {
    return $e->getCode() === '42S22' || strpos($e->getMessage(), '1054') !== false;
}

function sync_student_billing($pdo, $studentId, $studentClass, $userId) {
    $sStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('academic_year','current_term')");
    $sRows = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    $sMap  = [];
    foreach ($sRows as $sr) $sMap[$sr['setting_key']] = $sr['setting_value'];
    $year = $sMap['academic_year'] ?? date('Y');
    $term = $sMap['current_term']  ?? 'Term 1';

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
                VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)
            ");
            $insStmt->execute([$studentId, $cat['id'], $year, $term, $specificAmount, $userId]);
        }
    }
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        $id = $_POST['id'] ?? 0;
        try {
            // Get photo path to delete the file
            $stmt = $pdo->prepare("SELECT photo FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $student = $stmt->fetch();
            if ($student && $student['photo']) {
                $photo_file = '../' . $student['photo'];
                if (file_exists($photo_file)) {
                    unlink($photo_file);
                }
            }
            // Delete all related records
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

            // Delete student
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$id]);
            log_audit($pdo, $current_user['id'] ?? null, 'DELETE_STUDENT', "Deleted student ID $id");
            echo json_encode(["status" => "success", "message" => "Student deleted successfully."]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
        exit;
    } else if ($action === 'edit') {
        $id = $_POST['id'] ?? 0;
        $full_name = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $admission_number = htmlspecialchars(trim($_POST['admission_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $class = htmlspecialchars(trim($_POST['class'] ?? ''), ENT_QUOTES, 'UTF-8');
        $gender = htmlspecialchars(trim($_POST['gender'] ?? ''), ENT_QUOTES, 'UTF-8');

        $dob = $_POST['dob'] ?? null;
        $blood_group = $_POST['blood_group'] ?? null;
        $emergency_contact = $_POST['emergency_contact'] ?? null;
        $admitted_date = $_POST['admitted_date'] ?? null;
        $valid_until = $_POST['valid_until'] ?? null;

        $assigned_bus_id = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;
        $route_number = htmlspecialchars(trim($_POST['route_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $pickup_location = htmlspecialchars(trim($_POST['pickup_location'] ?? ''), ENT_QUOTES, 'UTF-8');
        $dropoff_location = htmlspecialchars(trim($_POST['dropoff_location'] ?? ''), ENT_QUOTES, 'UTF-8');

        if(empty($full_name) || empty($admission_number) || empty($class) || empty($gender) || empty($id)) {
            echo json_encode(["status" => "error", "message" => "All fields are required."]);
            exit;
        }

        $parentInfo = validateParentGuardianInput($_POST, $ALLOWED_RELATIONSHIPS);
        if (isset($parentInfo['error'])) {
            echo json_encode(["status" => "error", "message" => $parentInfo['error']]);
            exit;
        }

        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = handlePhotoUpload($_FILES['photo']);
            if (isset($uploadResult['error'])) {
                echo json_encode(["status" => "error", "message" => $uploadResult['error']]);
                exit;
            }
            $photo_path = $uploadResult['path'];
            
            // Delete old photo
            $stmt = $pdo->prepare("SELECT photo FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $old_student = $stmt->fetch();
            if ($old_student && $old_student['photo'] && file_exists('../' . $old_student['photo'])) {
                unlink('../' . $old_student['photo']);
            }
        }

        try {
            if ($photo_path) {
                $stmt = $pdo->prepare("UPDATE students SET full_name = ?, admission_number = ?, class = ?, gender = ?, dob = ?, blood_group = ?, emergency_contact = ?, admitted_date = ?, valid_until = ?, parent_name = ?, parent_phone = ?, parent_relationship = ?, parent_relationship_other = ?, assigned_bus_id = ?, route_number = ?, pickup_location = ?, dropoff_location = ?, photo = ? WHERE id = ?");
                $stmt->execute([$full_name, $admission_number, $class, $gender, $dob, $blood_group, $emergency_contact, $admitted_date, $valid_until, $parentInfo['parent_name'], $parentInfo['parent_phone'], $parentInfo['parent_relationship'], $parentInfo['parent_relationship_other'], $assigned_bus_id, $route_number, $pickup_location, $dropoff_location, $photo_path, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE students SET full_name = ?, admission_number = ?, class = ?, gender = ?, dob = ?, blood_group = ?, emergency_contact = ?, admitted_date = ?, valid_until = ?, parent_name = ?, parent_phone = ?, parent_relationship = ?, parent_relationship_other = ?, assigned_bus_id = ?, route_number = ?, pickup_location = ?, dropoff_location = ? WHERE id = ?");
                $stmt->execute([$full_name, $admission_number, $class, $gender, $dob, $blood_group, $emergency_contact, $admitted_date, $valid_until, $parentInfo['parent_name'], $parentInfo['parent_phone'], $parentInfo['parent_relationship'], $parentInfo['parent_relationship_other'], $assigned_bus_id, $route_number, $pickup_location, $dropoff_location, $id]);
            }
            sync_student_billing($pdo, $id, $class, $current_user['id'] ?? null);
            log_audit($pdo, $current_user['id'] ?? null, 'EDIT_STUDENT', "Updated student ID $id");
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
        $qr_code = $_POST['qr_code'] ?? '';

        if(empty($id) || empty($qr_code)) {
            echo json_encode(["status" => "error", "message" => "Student ID and QR Code are required."]);
            exit;
        }

        try {
            // Check if QR code is already assigned to ANOTHER student
            $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE qr_code = ? AND id != ?");
            $stmt->execute([$qr_code, $id]);
            $existing = $stmt->fetch();

            if ($existing) {
                echo json_encode(["status" => "error", "message" => "This QR code is already assigned to another student: " . $existing['full_name']]);
                exit;
            }

            // Update the student's QR code
            $stmt = $pdo->prepare("UPDATE students SET qr_code = ? WHERE id = ?");
            $stmt->execute([$qr_code, $id]);
            log_audit($pdo, $current_user['id'] ?? null, 'ASSIGN_QR', "Assigned QR code to student ID $id");
            echo json_encode(["status" => "success", "message" => "QR Code assigned successfully."]);
        } catch (PDOException $e) {
            error_log("Database error in students.php assign_qr: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong."]);
        }
        exit;
    }

    // Default to Add student
    $full_name = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $admission_number = htmlspecialchars(trim($_POST['admission_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $class = htmlspecialchars(trim($_POST['class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $gender = htmlspecialchars(trim($_POST['gender'] ?? ''), ENT_QUOTES, 'UTF-8');
    $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $blood_group = !empty($_POST['blood_group']) ? $_POST['blood_group'] : null;
    $emergency_contact = !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null;
    $admitted_date = !empty($_POST['admitted_date']) ? $_POST['admitted_date'] : null;
    $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;

    $assigned_bus_id = !empty($_POST['assigned_bus_id']) ? (int)$_POST['assigned_bus_id'] : null;
    $route_number = htmlspecialchars(trim($_POST['route_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $pickup_location = htmlspecialchars(trim($_POST['pickup_location'] ?? ''), ENT_QUOTES, 'UTF-8');
    $dropoff_location = htmlspecialchars(trim($_POST['dropoff_location'] ?? ''), ENT_QUOTES, 'UTF-8');
    
    if(empty($full_name) || empty($admission_number) || empty($class) || empty($gender)) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit;
    }

    $parentInfo = validateParentGuardianInput($_POST, $ALLOWED_RELATIONSHIPS);
    if (isset($parentInfo['error'])) {
        echo json_encode(["status" => "error", "message" => $parentInfo['error']]);
        exit;
    }

    // Generate unique QR string safely
    $qr_code = 'QR-' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $admission_number)) . '-' . strtoupper(substr(uniqid(), -6));
    
    // Handle Photo upload
    $photo_path = null;
    $upload_debug = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = handlePhotoUpload($_FILES['photo']);
        if (isset($uploadResult['error'])) {
            echo json_encode(["status" => "error", "message" => $uploadResult['error']]);
            exit;
        }
        $photo_path = $uploadResult['path'];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO students (full_name, admission_number, class, gender, dob, blood_group, emergency_contact, admitted_date, valid_until, photo, qr_code, parent_name, parent_phone, parent_relationship, parent_relationship_other, assigned_bus_id, route_number, pickup_location, dropoff_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$full_name, $admission_number, $class, $gender, $dob, $blood_group, $emergency_contact, $admitted_date, $valid_until, $photo_path, $qr_code, $parentInfo['parent_name'], $parentInfo['parent_phone'], $parentInfo['parent_relationship'], $parentInfo['parent_relationship_other'], $assigned_bus_id, $route_number, $pickup_location, $dropoff_location]);
        $new_student_id = $pdo->lastInsertId();
        log_audit($pdo, $current_user['id'] ?? null, 'ADD_STUDENT', "Added student: $full_name");

        // ── Auto-assign Billing (gracefully skips if billing tables not yet created) ──
        try {
            sync_student_billing($pdo, $new_student_id, $class, $current_user['id'] ?? null);
        } catch (PDOException $be) {
            // Billing tables may not exist yet — do not block student registration
            error_log("Billing auto-assign skipped (run billing_migrate.php): " . $be->getMessage());
        }
        // ── End Billing Auto-assign ──

        echo json_encode(["status" => "success", "message" => "Student registered successfully.", "qr_code" => $qr_code, "upload_debug" => $upload_debug]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation (Duplicate)
            echo json_encode(["status" => "error", "message" => "A student with this admission number already exists.", "upload_debug" => $upload_debug]);
        } else if (isMissingColumnError($e)) {
            error_log("Database error in students.php add: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Setup incomplete: the database is missing the Parent/Guardian columns. Please run add_parent_guardian_fields.php once (from your site root, e.g. https://yoursite.com/add_parent_guardian_fields.php), then try again.", "upload_debug" => $upload_debug]);
        } else {
            error_log("Database error in students.php add: " . $e->getMessage());
            echo json_encode(["status" => "error", "message" => "Something went wrong.", "upload_debug" => $upload_debug]);
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
            $students = $stmt->fetchAll();
            echo json_encode(["status" => "success", "data" => $students]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else if ($action === 'get') {
        $id = $_GET['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $student = $stmt->fetch();
            
            if ($student) {
                // Fetch attendance stats
                $stats_stmt = $pdo->prepare("SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN attendance_status = 'Present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN attendance_status = 'Absent' THEN 1 ELSE 0 END) as absent_days
                    FROM attendance WHERE student_id = ?");
                $stats_stmt->execute([$id]);
                $stats = $stats_stmt->fetch();
                $student['stats'] = $stats;
                
                echo json_encode(["status" => "success", "data" => $student]);
            } else {
                echo json_encode(["status" => "error", "message" => "Student not found."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
}
?>
