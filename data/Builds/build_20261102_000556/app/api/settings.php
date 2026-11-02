<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';
require_once 'auth_middleware.php';

$x_0001 = $_GET['action'] ?? 'get';

try {
    $x_0002->exec("
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

    $x_0002->exec("
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
    $x_0002->exec("
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
    if ($x_0001 === 'get') {
        $x_0003 = $x_0002->query("SELECT setting_key, setting_value FROM settings");
        $x_0004 = [];
        while ($x_0005 = $x_0003->fetch(PDO::FETCH_ASSOC)) {
            $x_0004[$x_0005['setting_key']] = $x_0005['setting_value'];
        }
        echo json_encode(['status' => 'success', 'data' => $x_0004]);
    } 
    elseif ($x_0001 === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_0003 = $x_0002->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        
        $x_0006 = 0;
        foreach ($_POST as $x_0007 => $x_0008) {

            $x_0003->execute([$x_0007, $x_0008, $x_0008]);
            $x_0006++;
        }

        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $x_0009 = $_FILES['school_logo'];
            if ($x_0009['size'] > 2 * 1024 * 1024) {
                echo json_encode(['status' => 'error', 'message' => 'Logo exceeds 2MB limit.']);
                exit;
            }
            
            $x_000a = finfo_open(FILEINFO_MIME_TYPE);
            $x_000b = finfo_file($x_000a, $x_0009['tmp_name']);
            finfo_close($x_000a);
            
            $x_000c = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
            if (!in_array($x_000b, $x_000c)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid logo type.']);
                exit;
            }
            
            $x_000d = strtolower(pathinfo($x_0009['name'], PATHINFO_EXTENSION));
            $x_000e = ['jpg', 'jpeg', 'png', 'gif', 'svg'];
            
            if (in_array($x_000d, $x_000e)) {
                $x_000f = file_get_contents($x_0009['tmp_name']);
                if (preg_match('/<\?php|<script/i', $x_000f)) {
                    echo json_encode(['status' => 'error', 'message' => 'Malicious content detected in logo.']);
                    exit;
                }
                
                $x_0010 = '../uploads/';
                if (!is_dir($x_0010)) @mkdir($x_0010, 0777, true);
                
                $x_0011 = 'logo_' . hash('sha256', uniqid(rand(), true)) . '.' . $x_000d;
                $x_0012 = $x_0010 . $x_0011;
                
                if (move_uploaded_file($x_0009['tmp_name'], $x_0012)) {
                    $x_0013 = 'uploads/' . $x_0011;
                    $x_0003->execute(['school_logo', $x_0013, $x_0013]);
                }
            }
        }

        log_audit($x_0002, $x_0014['id'] ?? null, 'UPDATE_SETTINGS', 'System settings updated');
        echo json_encode(['status' => 'success', 'message' => 'Settings updated successfully']);
    } 
    elseif ($x_0001 === 'get_terms') {
        $x_0003 = $x_0002->query("SELECT * FROM academic_terms ORDER BY start_date DESC");
        $x_0015 = $x_0003->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $x_0015]);
    }
    elseif ($x_0001 === 'add_term' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($x_0014['role'] ?? '') !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $x_0016 = trim($_POST['term_name'] ?? '');
        $x_0017 = trim($_POST['academic_year'] ?? '');
        $x_0018 = $_POST['start_date'] ?? '';
        $x_0019 = $_POST['end_date'] ?? '';

        if (!$x_0016 || !$x_0017 || !$x_0018 || !$x_0019) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
            exit;
        }

        $x_0003 = $x_0002->query("SELECT COUNT(*) FROM academic_terms WHERE status='Active'");
        $x_001a = $x_0003->fetchColumn();
        $x_001b = ($x_001a == 0 && $x_0018 <= date('Y-m-d')) ? 'Active' : 'Upcoming';

        $x_0003 = $x_0002->prepare("INSERT INTO academic_terms (term_name, academic_year, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)");
        $x_0003->execute([$x_0016, $x_0017, $x_0018, $x_0019, $x_001b]);

        if ($x_001b === 'Active') {
            $x_001c = $x_0002->prepare("UPDATE settings SET setting_value=? WHERE setting_key=?");
            $x_001c->execute([$x_0017, 'academic_year']);
            $x_001c->execute([$x_0016, 'current_term']);
        }

        echo json_encode(['status' => 'success', 'message' => 'Academic Term added successfully.']);
    }
    elseif ($x_0001 === 'delete_term' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($x_0014['role'] ?? '') !== 'Admin') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $x_0003 = $x_0002->prepare("DELETE FROM academic_terms WHERE id = ? AND status = 'Upcoming'");
        $x_0003->execute([$id]);
        if ($x_0003->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Term deleted.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete active or completed term.']);
        }
    }
    elseif ($x_0001 === 'get_classes') {

        $x_0003 = $x_0002->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != ''");
        $x_001d = $x_0003->fetchAll(PDO::FETCH_COLUMN);

        $x_001e = $x_0002->query("SELECT setting_value FROM settings WHERE setting_key = 'class_expiries'");
        $x_001f = $x_001e->fetchColumn();
        
        $x_0020 = [];
        if ($x_001f) {
            $x_0021 = json_decode($x_001f, true);
            if (is_array($x_0021)) {
                $x_0020 = array_keys($x_0021);
            }
        }

        $x_0022 = array_unique(array_merge($x_001d, $x_0020));
        sort($x_0022);

        $x_0023 = [];
        foreach ($x_0022 as $c) {
            if (trim($c) !== '') {
                $x_0023[] = ['class_name' => $c];
            }
        }

        echo json_encode(['status' => 'success', 'data' => $x_0023]);
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in settings.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong.']);
}
?>
