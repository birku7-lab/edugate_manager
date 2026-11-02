<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Direct access forbidden']));
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL); // Log all errors but do not display them

date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json');

header("Access-Control-Allow-Origin: https://edugate.ink");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$x_0001 = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
if ($x_0001 === 'localhost' || $x_0001 === '127.0.0.1') {

    $x_0002 = 'localhost';
    $db = 'school_attendance';
    $x_0003 = 'root';
    $x_0004 = '';
} else {

    $x_0002 = 'localhost';
    $db = 'edugowks_attendance';
    $x_0003 = 'edugowks_Success'; // Updated to correct username!
    $x_0004 = 'success10152653';
}

try {

    $x_0005 = new PDO("mysql:host=$x_0002;dbname=$db;charset=utf8", $x_0003, $x_0004);
    $x_0005->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $x_0005->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Database connection failed. Please try again later."]);
    exit;
}

function log_audit($x_0005, $x_0006, $x_0007, $x_0008) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $x_0009 = $x_0005->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $x_0009->execute([$x_0006, $x_0007, $x_0008, $ip]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}
?>

