<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

$action = $_GET['action'] ?? 'get';

// Ensure academic_terms table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `academic_terms` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `term_name` VARCHAR(100) NOT NULL,
            `academic_year` VARCHAR(50) NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `status` ENUM('Completed', 'Active', 'Upcoming') NOT NULL DEFAULT 'Upcoming',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sms_logs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `student_id` INT(11) DEFAULT NULL,
            `phone_number` VARCHAR(50) NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('Sent', 'Failed') NOT NULL DEFAULT 'Sent',
            `api_response` TEXT,
            `error_message` TEXT,
            `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `scheduled_sms` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `target_filter` TEXT NOT NULL,
            `message_content` TEXT NOT NULL,
            `scheduled_time` DATETIME NOT NULL,
            `status` ENUM('Pending', 'Processing', 'Completed', 'Failed') NOT NULL DEFAULT 'Pending',
            `created_by` INT(11) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
} catch (PDOException $e) {}

try {
    if ($action === 'get') {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        echo json_encode(['status' => 'success', 'data' => $settings]);
    } 
    elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        
        $updated_count = 0;
        foreach ($_POST as $key => $value) {
            // Handle file uploads separately if any (e.g. school logo)
            $stmt->execute([$key, $value, $value]);
            $updated_count++;
        }

        // Handle logo upload
        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['school_logo'];
            if ($file['size'] > 2 * 1024 * 1024) {
                echo json_encode(['status' => 'error', 'message' => 'Logo exceeds 2MB limit.']);
                exit;
            }
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
            if (!in_array($mime, $allowed_mimes)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid logo type.']);
                exit;
            }
            
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg'];
            
            if (in_array($ext, $allowed)) {
                $content = file_get_contents($file['tmp_name']);
                if (preg_match('/<\?php|<script/i', $content)) {
                    echo json_encode(['status' => 'error', 'message' => 'Malicious content detected in logo.']);
                    exit;
                }
                
                $upload_dir = '../uploads/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
                
                $file_name = 'logo_' . hash('sha256', uniqid(rand(), true)) . '.' . $ext;
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $logo_url = 'uploads/' . $file_name;
                    $stmt->execute(['school_logo', $logo_url, $logo_url]);
                }
            }
        }

        log_audit($pdo, $current_user['id'] ?? null, 'UPDATE_SETTINGS', 'System settings updated');
        echo json_encode(['status' => 'success', 'message' => 'Settings updated successfully']);
    } 
    elseif ($action === 'get_terms') {
        $stmt = $pdo->query("SELECT * FROM academic_terms ORDER BY start_date DESC");
        $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $terms]);
    }
    elseif ($action === 'add_term' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($current_user['role'] ?? '') !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $termName = trim($_POST['term_name'] ?? '');
        $academicYear = trim($_POST['academic_year'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';

        if (!$termName || !$academicYear || !$startDate || !$endDate) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
            exit;
        }

        // Determine status. If there's no active term, maybe this becomes active?
        // Let's just default to Upcoming for now, the rollover script will promote it.
        // Wait, if it's the very first term, make it Active.
        $stmt = $pdo->query("SELECT COUNT(*) FROM academic_terms WHERE status='Active'");
        $activeCount = $stmt->fetchColumn();
        $status = ($activeCount == 0 && $startDate <= date('Y-m-d')) ? 'Active' : 'Upcoming';

        $stmt = $pdo->prepare("INSERT INTO academic_terms (term_name, academic_year, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$termName, $academicYear, $startDate, $endDate, $status]);
        
        // If it was made Active, update settings
        if ($status === 'Active') {
            $stmtSet = $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key=?");
            $stmtSet->execute([$academicYear, 'academic_year']);
            $stmtSet->execute([$termName, 'current_term']);
        }

        echo json_encode(['status' => 'success', 'message' => 'Academic Term added successfully.']);
    }
    elseif ($action === 'delete_term' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($current_user['role'] ?? '') !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM academic_terms WHERE id = ? AND status = 'Upcoming'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Term deleted.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete active or completed term.']);
        }
    }
    elseif ($action === 'get_classes') {
        // 1. Get classes that actually exist in the students section (students table)
        $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != ''");
        $studentClasses = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Get classes configured in settings
        $sStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'class_expiries'");
        $classExpiries = $sStmt->fetchColumn();
        
        $settingsClasses = [];
        if ($classExpiries) {
            $parsed = json_decode($classExpiries, true);
            if (is_array($parsed)) {
                $settingsClasses = array_keys($parsed);
            }
        }

        // Merge and deduplicate
        $allClasses = array_unique(array_merge($studentClasses, $settingsClasses));
        sort($allClasses);

        // Format as expected by JS
        $classes = [];
        foreach ($allClasses as $c) {
            if (trim($c) !== '') {
                $classes[] = ['class_name' => $c];
            }
        }

        echo json_encode(['status' => 'success', 'data' => $classes]);
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in settings.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong.']);
}
?>
