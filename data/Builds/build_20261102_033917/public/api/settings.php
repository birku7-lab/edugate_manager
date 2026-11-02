<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

$action = $_GET['action'] ?? 'get';

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
        $x_0001 = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $x_0001[$row['setting_key']] = $row['setting_value'];
        }
        echo json_encode(['status' => 'success', 'data' => $x_0001]);
    } 
    elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        
        $x_0002 = 0;
        foreach ($_POST as $key => $value) {

            $stmt->execute([$key, $value, $value]);
            $x_0002++;
        }

        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['school_logo'];
            if ($file['size'] > 2 * 1024 * 1024) {
                echo json_encode(['status' => 'error', 'message' => 'Logo exceeds 2MB limit.']);
                exit;
            }
            
            $x_0003 = finfo_open(FILEINFO_MIME_TYPE);
            $x_0004 = finfo_file($x_0003, $file['tmp_name']);
            finfo_close($x_0003);
            
            $x_0005 = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
            if (!in_array($x_0004, $x_0005)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid logo type.']);
                exit;
            }
            
            $x_0006 = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $x_0007 = ['jpg', 'jpeg', 'png', 'gif', 'svg'];
            
            if (in_array($x_0006, $x_0007)) {
                $x_0008 = file_get_contents($file['tmp_name']);
                if (preg_match('/<\?php|<script/i', $x_0008)) {
                    echo json_encode(['status' => 'error', 'message' => 'Malicious content detected in logo.']);
                    exit;
                }
                
                $x_0009 = '../uploads/';
                if (!is_dir($x_0009)) @mkdir($x_0009, 0777, true);
                
                $x_000a = 'logo_' . hash('sha256', uniqid(rand(), true)) . '.' . $x_0006;
                $x_000b = $x_0009 . $x_000a;
                
                if (move_uploaded_file($file['tmp_name'], $x_000b)) {
                    $x_000c = 'uploads/' . $x_000a;
                    $stmt->execute(['school_logo', $x_000c, $x_000c]);
                }
            }
        }

        log_audit($pdo, $x_000d['id'] ?? null, 'UPDATE_SETTINGS', 'System settings updated');
        echo json_encode(['status' => 'success', 'message' => 'Settings updated successfully']);
    } 
    elseif ($action === 'get_terms') {
        $stmt = $pdo->query("SELECT * FROM academic_terms ORDER BY start_date DESC");
        $x_000e = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $x_000e]);
    }
    elseif ($action === 'add_term' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($x_000d['role'] ?? '') !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $x_000f = trim($_POST['term_name'] ?? '');
        $x_0010 = trim($_POST['academic_year'] ?? '');
        $x_0011 = $_POST['start_date'] ?? '';
        $x_0012 = $_POST['end_date'] ?? '';

        if (!$x_000f || !$x_0010 || !$x_0011 || !$x_0012) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
            exit;
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM academic_terms WHERE status='Active'");
        $x_0013 = $stmt->fetchColumn();
        $status = ($x_0013 == 0 && $x_0011 <= date('Y-m-d')) ? 'Active' : 'Upcoming';

        $stmt = $pdo->prepare("INSERT INTO academic_terms (term_name, academic_year, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$x_000f, $x_0010, $x_0011, $x_0012, $status]);

        if ($status === 'Active') {
            $x_0014 = $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key=?");
            $x_0014->execute([$x_0010, 'academic_year']);
            $x_0014->execute([$x_000f, 'current_term']);
        }

        echo json_encode(['status' => 'success', 'message' => 'Academic Term added successfully.']);
    }
    elseif ($action === 'delete_term' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($x_000d['role'] ?? '') !== 'Admin') {
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

        $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != ''");
        $x_0015 = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $x_0016 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'class_expiries'");
        $x_0017 = $x_0016->fetchColumn();
        
        $x_0018 = [];
        if ($x_0017) {
            $x_0019 = json_decode($x_0017, true);
            if (is_array($x_0019)) {
                $x_0018 = array_keys($x_0019);
            }
        }

        $x_001a = array_unique(array_merge($x_0015, $x_0018));
        sort($x_001a);

        $x_001b = [];
        foreach ($x_001a as $c) {
            if (trim($c) !== '') {
                $x_001b[] = ['class_name' => $c];
            }
        }

        echo json_encode(['status' => 'success', 'data' => $x_001b]);
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in settings.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong.']);
}
?>
