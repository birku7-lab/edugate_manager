<?php
// Ensure this is only included, not accessed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Direct access forbidden']));
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL); // Log all errors but do not display them

date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json');
// Handle CORS securely
header("Access-Control-Allow-Origin: https://edugate.ink");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Preflight requests should exit early
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$server_name = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
if ($server_name === 'localhost' || $server_name === '127.0.0.1') {
    // Local XAMPP Credentials
    $host = 'localhost';
    $db = 'school_attendance';
    $user = 'root';
    $pass = '';
} else {
    // Namecheap Live Credentials
    $host = 'localhost';
    $db = 'edugowks_attendance';
    $user = 'edugowks_Success'; // Updated to correct username!
    $pass = 'success10152653';
}

try {
    // Connect to the database
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Database connection failed. Please try again later."]);
    exit;
}

// Global helper for audit logging
function log_audit($pdo, $user_id, $action, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $details, $ip]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}
?>

