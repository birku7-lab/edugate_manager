<?php
// Set content type to text for setup response
header('Content-Type: text/plain');
require_once 'config.php';

try {
    // Check if the database has already been initialized
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'");
        if ($stmt->fetchColumn() > 0) {
            http_response_code(403);
            die("Setup already completed. Access denied.");
        }
    }
    
    // Create Students Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        admission_number VARCHAR(100) NOT NULL UNIQUE,
        class VARCHAR(100) NOT NULL,
        gender VARCHAR(20) NOT NULL,
        dob DATE DEFAULT NULL,
        blood_group VARCHAR(10) DEFAULT NULL,
        emergency_contact VARCHAR(50) DEFAULT NULL,
        admitted_date DATE DEFAULT NULL,
        valid_until DATE DEFAULT NULL,
        photo VARCHAR(255) DEFAULT NULL,
        qr_code VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create Attendance Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        arrival_time TIME NOT NULL,
        attendance_status ENUM('Present', 'Absent', 'Late') DEFAULT 'Present',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        UNIQUE KEY unique_daily_attendance (student_id, attendance_date)
    )");

    // Create Settings Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    )");

    // Create Holidays Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        description TEXT
    )");

    // Insert Default Settings
    $default_settings = [
        'school_name' => 'EduGate High School',
        'school_motto' => 'Excellence and Integrity',
        'school_address' => '123 Education Lane',
        'school_phone' => '+1 234 567 890',
        'school_email' => 'admin@edugate.com',
        'academic_year' => '2026/2027',
        'current_term' => 'Term 1',
        'school_opening_time' => '07:00',
        'late_arrival_time' => '07:30',
        'attendance_cutoff_time' => '09:00',
        'theme' => 'auto',
        'accent_color' => 'primary',
        'school_days' => '{"Mon":true,"Tue":true,"Wed":true,"Thu":true,"Fri":true,"Sat":false,"Sun":false}',
        'dashboard_layout' => '{"cards":true,"live_scan":true,"charts":true,"quick_actions":true}',
        'notifications_success' => 'true',
        'notifications_error' => 'true',
        'qr_size' => '250',
        'qr_margin' => '4',
        'qr_correction' => 'M'
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($default_settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    // Create Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        username VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        token VARCHAR(255) DEFAULT NULL,
        role ENUM('Admin', 'Staff') DEFAULT 'Staff',
        permissions TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create User Sessions Table for multiple device login
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Create Audit Log Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(255) NOT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(45) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    // Seed default admin with a secure password
    $admin_raw_password = 'Admin@Setup2026!'; // Meets 12+ chars, upper, lower, number, special
    $admin_password_hash = password_hash($admin_raw_password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (name, username, password_hash, role, permissions) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['Admin User', 'admin', $admin_password_hash, 'Admin', '["all"]']);

    $pdo->exec("ALTER TABLE attendance MODIFY COLUMN attendance_status ENUM('Present', 'Absent', 'Late') DEFAULT 'Present'");

    echo "Database and tables initialized successfully with settings.\n";
    echo "Default Admin Username: admin\n";
    echo "Default Admin Password: " . $admin_raw_password . "\n";
    echo "Please log in and change this password immediately.";
} catch (PDOException $e) {
    error_log("Setup failed: " . $e->getMessage());
    echo "Setup failed. Please check the error logs.";
}
?>
