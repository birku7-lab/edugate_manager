<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
if (empty($_COOKIE['csrf_token'])) {
    setcookie('csrf_token', bin2hex(random_bytes(32)), time() + 86400 * 30, '/', '', isset($_SERVER["HTTPS"]), false);
}

$x_0001 = $_GET['file'] ?? '';
$x_0002 = ['login.html'];
if (in_array(basename($x_0001), $x_0002)) {
    readfile($x_0001);
    exit;
}

$x_0003 = $_COOKIE['auth_token'] ?? '';
$x_0004 = false;

if ($x_0003) {
    require_once 'api/config.php';
    try {
        $x_0005 = $x_0006->prepare("SELECT u.id FROM users u JOIN user_sessions s ON u.id = s.user_id WHERE s.token = ?");
        $x_0005->execute([$x_0003]);
        if ($x_0005->fetch()) {
            $x_0004 = true;
        }
    } catch (PDOException $e) {

    }
}

if (!$x_0004) {
    header("Location: login.html");
    exit;
}

$x_0007 = realpath(__DIR__);
$x_0008 = realpath(__DIR__ . '/' . $x_0001);

if ($x_0008 && strpos($x_0008, $x_0007) === 0 && pathinfo($x_0008, PATHINFO_EXTENSION) === 'html' && file_exists($x_0008)) {
    header('Content-Type: text/html');
    readfile($x_0008);
} else {
    http_response_code(404);
    echo "File not found.";
}
?>
