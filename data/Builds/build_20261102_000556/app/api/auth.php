<?php
/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */
header('Content-Type: application/json');
require_once 'config.php';

$x_0001 = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($x_0001, ['login', 'verify'])) {
    $x_0002 = $_COOKIE['csrf_token'] ?? '';
    $x_0003 = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$x_0002 || !hash_equals($x_0002, $x_0003)) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'CSRF token validation failed']));
    }
}

try {
    if ($x_0001 === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_0004 = $_POST['username'] ?? '';
        $x_0005 = $_POST['password'] ?? '';

        if (!$x_0004 || !$x_0005) {
            echo json_encode(['status' => 'error', 'message' => 'Username and password are required']);
            exit;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $x_0006 = 'rate_limits.json';
        $x_0007 = file_exists($x_0006) ? json_decode(file_get_contents($x_0006), true) : [];
        $x_0008 = time();

        foreach ($x_0007 as $x_0009 => $x_000a) {
            if ($x_0008 - $x_000a['last_attempt'] > 300) {
                unset($x_0007[$x_0009]);
            }
        }
        
        if (isset($x_0007[$ip]) && $x_0007[$ip]['attempts'] >= 5) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many failed login attempts. Please try again later.']);
            exit;
        }

        $x_000b = $x_000c->prepare("SELECT id, name, username, password_hash, role, permissions FROM users WHERE username = ?");
        $x_000b->execute([$x_0004]);
        $x_000d = $x_000b->fetch(PDO::FETCH_ASSOC);

        if ($x_000d && password_verify($x_0005, $x_000d['password_hash'])) {

            $x_000c->exec("CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )");

            $x_000e = bin2hex(random_bytes(32));

            $x_000f = $x_000c->prepare("INSERT INTO user_sessions (user_id, token) VALUES (?, ?)");
            $x_000f->execute([$x_000d['id'], $x_000e]);

            unset($x_000d['password_hash']);

            setcookie('auth_token', $x_000e, time() + 86400 * 30, '/', '', isset($_SERVER["HTTPS"]), true);

            $x_0010 = bin2hex(random_bytes(32));
            setcookie('csrf_token', $x_0010, time() + 86400 * 30, '/', '', isset($_SERVER["HTTPS"]), false);

            if (isset($x_0007[$ip])) {
                unset($x_0007[$ip]);
                file_put_contents($x_0006, json_encode($x_0007));
            }

            log_audit($x_000c, $x_000d['id'], 'LOGIN_SUCCESS', 'User logged in successfully');

            echo json_encode([
                'status' => 'success', 
                'message' => 'Login successful', 
                'token' => $x_000e,
                'user' => $x_000d
            ]);
        } else {
            log_audit($x_000c, null, 'LOGIN_FAILED', 'Failed login attempt for username: ' . $x_0004);
            sleep(1); // Anti-bruteforce delay

            if (!isset($x_0007[$ip])) {
                $x_0007[$ip] = ['attempts' => 1, 'last_attempt' => $x_0008];
            } else {
                $x_0007[$ip]['attempts']++;
                $x_0007[$ip]['last_attempt'] = $x_0008;
            }
            file_put_contents($x_0006, json_encode($x_0007));
            
            echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
        }
    } 
    elseif ($x_0001 === 'verify') {
        $x_000e = $_COOKIE['auth_token'] ?? '';
        if (!$x_000e) {
            $x_000e = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if(strpos($x_000e, 'Bearer ') === 0) {
                $x_000e = substr($x_000e, 7);
            }
        }
        if (!$x_000e) {
            echo json_encode(['status' => 'error', 'message' => 'No token provided']);
            exit;
        }

        $x_000b = $x_000c->prepare("SELECT u.id, u.name, u.username, u.role, u.permissions FROM users u JOIN user_sessions s ON u.id = s.user_id WHERE s.token = ?");
        $x_000b->execute([$x_000e]);
        $x_000d = $x_000b->fetch(PDO::FETCH_ASSOC);

        if ($x_000d) {
            echo json_encode(['status' => 'success', 'user' => $x_000d]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        }
    }
    elseif ($x_0001 === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $x_000e = $_COOKIE['auth_token'] ?? '';
        if ($x_000e) {
            $x_000b = $x_000c->prepare("SELECT user_id as id FROM user_sessions WHERE token = ?");
            $x_000b->execute([$x_000e]);
            $u = $x_000b->fetch();
            if ($u) log_audit($x_000c, $u['id'], 'LOGOUT', 'User logged out manually');

            $x_000b = $x_000c->prepare("DELETE FROM user_sessions WHERE token = ?");
            $x_000b->execute([$x_000e]);
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
