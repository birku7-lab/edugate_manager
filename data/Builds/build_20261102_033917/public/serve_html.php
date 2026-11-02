<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (empty($_COOKIE['csrf_token'])) {
    setcookie('csrf_token', bin2hex(random_bytes(32)), time() + 86400 * 30, '/', '', isset($_SERVER["HTTPS"]), false);
}

$file = $_GET['file'] ?? '';
$x_0001 = ['login.html'];
if (in_array(basename($file), $x_0001)) {
    readfile($file);
    exit;
}

$token = $_COOKIE['auth_token'] ?? '';
$x_0002 = false;

if ($token) {
    require_once 'api/config.php';
    try {
        $stmt = $pdo->prepare("SELECT u.id FROM users u JOIN user_sessions s ON u.id = s.user_id WHERE s.token = ?");
        $stmt->execute([$token]);
        if ($stmt->fetch()) {
            $x_0002 = true;
        }
    } catch (PDOException $e) {

    }
}

if (!$x_0002) {
    header("Location: login.html");
    exit;
}

$x_0003 = realpath(__DIR__);
$x_0004 = realpath(__DIR__ . '/' . $file);

if ($x_0004 && strpos($x_0004, $x_0003) === 0 && pathinfo($x_0004, PATHINFO_EXTENSION) === 'html' && file_exists($x_0004)) {
    header('Content-Type: text/html');
    readfile($x_0004);
} else {
    http_response_code(404);
    echo "File not found.";
}
?>
