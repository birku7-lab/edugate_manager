<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['login', 'verify'])) {
    $csrf_cookie = $_COOKIE['csrf_token'] ?? '';
    $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrf_cookie || !hash_equals($csrf_cookie, $csrf_header)) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'CSRF token validation failed']));
    }
}

try {
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!$username || !$password) {
            echo json_encode(['status' => 'error', 'message' => 'Username and password are required']);
            exit;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitFile = 'rate_limits.json';
        $rateLimits = file_exists($rateLimitFile) ? json_decode(file_get_contents($rateLimitFile), true) : [];
        $now = time();

        foreach ($rateLimits as $ipAddr => $data) {
            if ($now - $data['last_attempt'] > 300) {
                unset($rateLimits[$ipAddr]);
            }
        }
        
        if (isset($rateLimits[$ip]) && $rateLimits[$ip]['attempts'] >= 5) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many failed login attempts. Please try again later.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, username, password_hash, role, permissions FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {

            $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )");

            $token = bin2hex(random_bytes(32));

            $insert = $pdo->prepare("INSERT INTO user_sessions (user_id, token) VALUES (?, ?)");
            $insert->execute([$user['id'], $token]);

            unset($user['password_hash']);

            setcookie('auth_token', $token, time() + 86400 * 30, '/', '', isset($_SERVER["HTTPS"]), true);

            $csrf_token = bin2hex(random_bytes(32));
            setcookie('csrf_token', $csrf_token, time() + 86400 * 30, '/', '', isset($_SERVER["HTTPS"]), false);

            if (isset($rateLimits[$ip])) {
                unset($rateLimits[$ip]);
                file_put_contents($rateLimitFile, json_encode($rateLimits));
            }

            log_audit($pdo, $user['id'], 'LOGIN_SUCCESS', 'User logged in successfully');

            echo json_encode([
                'status' => 'success', 
                'message' => 'Login successful', 
                'token' => $token,
                'user' => $user
            ]);
        } else {
            log_audit($pdo, null, 'LOGIN_FAILED', 'Failed login attempt for username: ' . $username);
            sleep(1); // Anti-bruteforce delay

            if (!isset($rateLimits[$ip])) {
                $rateLimits[$ip] = ['attempts' => 1, 'last_attempt' => $now];
            } else {
                $rateLimits[$ip]['attempts']++;
                $rateLimits[$ip]['last_attempt'] = $now;
            }
            file_put_contents($rateLimitFile, json_encode($rateLimits));
            
            echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
        }
    } 
    elseif ($action === 'verify') {
        $token = $_COOKIE['auth_token'] ?? '';
        if (!$token) {
            $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if(strpos($token, 'Bearer ') === 0) {
                $token = substr($token, 7);
            }
        }
        if (!$token) {
            echo json_encode(['status' => 'error', 'message' => 'No token provided']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT u.id, u.name, u.username, u.role, u.permissions FROM users u JOIN user_sessions s ON u.id = s.user_id WHERE s.token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            echo json_encode(['status' => 'success', 'user' => $user]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        }
    }
    elseif ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_COOKIE['auth_token'] ?? '';
        if ($token) {
            $stmt = $pdo->prepare("SELECT user_id as id FROM user_sessions WHERE token = ?");
            $stmt->execute([$token]);
            $u = $stmt->fetch();
            if ($u) log_audit($pdo, $u['id'], 'LOGOUT', 'User logged out manually');

            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token = ?");
            $stmt->execute([$token]);
        }
        setcookie('auth_token', '', time() - 3600, '/', '', isset($_SERVER["HTTPS"]), true);
        setcookie('csrf_token', '', time() - 3600, '/', '', isset($_SERVER["HTTPS"]), false);
        echo json_encode(['status' => 'success', 'message' => 'Logged out']);
    }
    else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("Database error in auth.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
}
?>
