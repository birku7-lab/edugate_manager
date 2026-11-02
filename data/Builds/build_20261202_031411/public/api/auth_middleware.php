<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Direct access forbidden']));
}

$token = $_COOKIE['auth_token'] ?? '';
if (!$token) {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if(strpos($token, 'Bearer ') === 0) {
        $token = substr($token, 7);
    }
}

if (!$token) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized access. No token provided.']));
}

try {
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.username, u.role, u.permissions FROM users u JOIN user_sessions s ON u.id = s.user_id WHERE s.token = ?");
    $stmt->execute([$token]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_user) {
        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'Invalid or expired token.']));
    }
} catch (PDOException $e) {
    error_log("Auth middleware DB error: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['status' => 'error', 'message' => 'Database error during authentication.']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_cookie = $_COOKIE['csrf_token'] ?? '';
    $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrf_cookie || !hash_equals($csrf_cookie, $csrf_header)) {
        http_response_code(403);
        log_audit($pdo, $current_user['id'], 'CSRF_FAILED', 'CSRF token validation failed');
        exit(json_encode(['status' => 'error', 'message' => 'CSRF token validation failed']));
    }
}

require_once __DIR__ . '/rollover_check.php';

?>
