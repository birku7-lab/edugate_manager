<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Direct access forbidden']));
}

$x_0001 = $_COOKIE['auth_token'] ?? '';
if (!$x_0001) {
    $x_0001 = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if(strpos($x_0001, 'Bearer ') === 0) {
        $x_0001 = substr($x_0001, 7);
    }
}

if (!$x_0001) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized access. No token provided.']));
}

try {
    $x_0002 = $x_0003->prepare("SELECT u.id, u.name, u.username, u.role, u.permissions FROM users u JOIN user_sessions s ON u.id = s.user_id WHERE s.token = ?");
    $x_0002->execute([$x_0001]);
    $x_0004 = $x_0002->fetch(PDO::FETCH_ASSOC);

    if (!$x_0004) {
        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'Invalid or expired token.']));
    }
} catch (PDOException $e) {
    error_log("Auth middleware DB error: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['status' => 'error', 'message' => 'Database error during authentication.']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $x_0005 = $_COOKIE['csrf_token'] ?? '';
    $x_0006 = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$x_0005 || !hash_equals($x_0005, $x_0006)) {
        http_response_code(403);
        log_audit($x_0003, $x_0004['id'], 'CSRF_FAILED', 'CSRF token validation failed');
        exit(json_encode(['status' => 'error', 'message' => 'CSRF token validation failed']));
    }
}

require_once __DIR__ . '/rollover_check.php';

?>
