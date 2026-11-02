<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (empty($_COOKIE['csrf_token'])) {
    setcookie('csrf_token', bin2hex(random_bytes(32)), time() + 86400 * 30, '/', '', isset($_SERVER["HTTPS"]), false);
}

$file = $_GET['file'] ?? '';
$public_pages = ['login.html'];
if (in_array(basename($file), $public_pages)) {
    readfile($file);
    exit;
}

$token = $_COOKIE['auth_token'] ?? '';
$authenticated = false;

if ($token) {
    require_once 'api/config.php';
    try {
        $stmt = $pdo->prepare("SELECT u.id FROM users u JOIN user_sessions s ON u.id = s.user_id WHERE s.token = ?");
        $stmt->execute([$token]);
        if ($stmt->fetch()) {
            $authenticated = true;
        }
    } catch (PDOException $e) {

    }
}

if (!$authenticated) {
    header("Location: login.html");
    exit;
}

$real_base = realpath(__DIR__);
$real_file = realpath(__DIR__ . '/' . $file);

if ($real_file && strpos($real_file, $real_base) === 0 && pathinfo($real_file, PATHINFO_EXTENSION) === 'html' && file_exists($real_file)) {
    header('Content-Type: text/html');
    readfile($real_file);
} else {
    http_response_code(404);
    echo "File not found.";
}
?>
