<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

if (!isset($_SESSION['2fa_setup_pending']) || !is_array($_SESSION['2fa_setup_pending'])) {
    header('Location: ../index.php');
    exit();
}

$pending = $_SESSION['2fa_setup_pending'];
$userId = (int)($pending['user_id'] ?? 0);
$username = (string)($pending['username'] ?? '');
$role = (string)($pending['role'] ?? 'guest');

if ($userId <= 0 || $username === '') {
    unset($_SESSION['2fa_setup_pending']);
    header('Location: ../index.php');
    exit();
}

$error = null;
$secret = null;

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    $error = 'Database connection failed';
} else {
    try {
        $stmt = $conn->prepare('SELECT COALESCE(totp_enabled, 0) AS totp_enabled, COALESCE(totp_secret, "") AS totp_secret FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $secret = (string)($row['totp_secret'] ?? '');
                $enabled = (int)($row['totp_enabled'] ?? 0);
                if ($enabled !== 1 || $secret === '') {
                    $secret = totp_generate_secret(20);
                    $upd = $conn->prepare('UPDATE users SET totp_enabled = 1, totp_secret = ? WHERE id = ?');
                    if ($upd) {
                        $upd->bind_param('si', $secret, $userId);
                        $upd->execute();
                        $upd->close();
                        audit_log($conn, 'auth.2fa_setup_issued', '2FA setup issued for user: ' . $username, $userId);
                    }
                }
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        $error = 'Failed to initialize 2FA setup.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $code = (string)($_POST['code'] ?? '');
    if ($secret === null || $secret === '') {
        $error = 'Setup not ready.';
    } elseif (!totp_verify($secret, $code)) {
        $error = 'Invalid code';
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
            audit_log($conn, 'auth.2fa_setup_verify_failed', '2FA setup verify failed for user: ' . $username, $userId);
        }
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error && function_exists('user_effective_role')) {
            $role = user_effective_role($conn, $userId, $role);
        }
        $_SESSION['role'] = $role;

        $_SESSION['perms'] = [];
        $rbacOk = false;
        if ($res = $conn->query("SHOW TABLES LIKE 'user_roles'")) {
            $rbacOk = (bool)$res->fetch_assoc();
            $res->free();
        }
        if ($rbacOk) {
            if (function_exists('ensure_perm_movement_approve')) {
                ensure_perm_movement_approve($conn);
            }
            $_SESSION['perms'] = load_user_permissions($conn, $userId);
        }

        if (!is_array($_SESSION['perms']) || count($_SESSION['perms']) === 0) {
            if ($role === 'admin') {
                $_SESSION['perms'] = [
                    'dashboard.view' => true,
                    'analytics.view' => true,
                    'category.view' => true,
                    'category.create' => true,
                    'category.edit' => true,
                    'category.delete' => true,
                    'product.view' => true,
                    'product.create' => true,
                    'product.edit' => true,
                    'product.delete' => true,
                    'location.view' => true,
                    'location.create' => true,
                    'location.edit' => true,
                    'location.delete' => true,
                    'movement.view' => true,
                    'movement.create' => true,
                    'movement.approve' => true,
                ];
            } elseif ($role === 'guest') {
                $_SESSION['perms'] = [
                    'dashboard.view' => true,
                    'location.view' => true,
                    'product.view' => true,
                ];
            } else {
                $_SESSION['perms'] = [
                    'dashboard.view' => true,
                    'analytics.view' => true,
                    'category.view' => true,
                    'product.view' => true,
                    'movement.view' => true,
                    'movement.create' => true,
                ];
            }
        }

        unset($_SESSION['2fa_setup_pending']);
        audit_log($conn, 'auth.2fa_setup_verified', '2FA setup verified for user: ' . $username, $userId);
        if ($role === 'guest') {
            header('Location: guest_home.php');
        } else {
            header('Location: dashboard.php');
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Setup</title>
    <link rel="stylesheet" href="../CSS/loginstyle.css">
</head>
<body>
<div class="container" id="container">
    <div class="form-container sign-in-container">
        <form action="" method="POST">
            <h1>Two-Factor Authentication</h1>
            <p style="margin: 10px 0 10px;">Use an authenticator app to protect your guest account</p>
            <?php if ($error) echo "<p style='color: red;'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</p>"; ?>

            <?php if (!$error && $secret) { ?>
                <p style="margin: 10px 0 10px;">Secret key:</p>
                <p style="font-weight: 700; word-break: break-all; margin: 0 0 10px;"><?php echo htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php } ?>

            <input type="text" name="code" placeholder="6-digit code" inputmode="numeric" autocomplete="one-time-code" required />
            <button type="submit">Enable 2FA</button>
            <a href="logout.php">Cancel</a>
        </form>
    </div>
    <div class="overlay-container">
        <img src="../CUBE3.png" alt="logo">
    </div>
</div>
</body>
</html>
