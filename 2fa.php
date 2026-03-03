<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

if (!isset($_SESSION['2fa_pending']) || !is_array($_SESSION['2fa_pending'])) {
    header('Location: index.php');
    exit();
}

$pending = $_SESSION['2fa_pending'];
$userId = (int)($pending['user_id'] ?? 0);
$username = (string)($pending['username'] ?? '');
$role = (string)($pending['role'] ?? 'staff');
$secret = (string)($pending['totp_secret'] ?? '');

if ($userId <= 0 || $username === '' || $secret === '') {
    unset($_SESSION['2fa_pending']);
    header('Location: index.php');
    exit();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = (string)($_POST['code'] ?? '');

    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        $error = 'Database connection failed';
    } elseif (!totp_verify($secret, $code)) {
        $error = 'Invalid code';
        audit_log($conn, 'auth.2fa_failed', '2FA failed for user: ' . $username, $userId);
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;

        $_SESSION['perms'] = [];
        $rbacOk = false;
        if ($res = $conn->query("SHOW TABLES LIKE 'user_roles'")) {
            $rbacOk = (bool)$res->fetch_assoc();
            $res->free();
        }
        if ($rbacOk) {
            $_SESSION['perms'] = load_user_permissions($conn, $userId);
        }
        if (!is_array($_SESSION['perms']) || count($_SESSION['perms']) === 0) {
            $fallbackRole = (string)($_SESSION['role'] ?? 'staff');
            if ($fallbackRole === 'admin') {
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
                    'movement.view' => true,
                    'movement.create' => true,
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

        unset($_SESSION['2fa_pending']);
        audit_log($conn, 'auth.2fa_success', '2FA success for user: ' . $username, $userId);
        header('Location: dashboard.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Verification</title>
    <link rel="stylesheet" href="loginstyle.css">
</head>
<body>
<div class="container" id="container">
    <div class="form-container sign-in-container">
        <form action="" method="POST">
            <h1>2FA Code</h1>
            <?php if ($error) echo "<p style='color: red;'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
            <input type="text" name="code" placeholder="6-digit code" inputmode="numeric" autocomplete="one-time-code" required />
            <button type="submit">Verify</button>
            <a href="logout.php">Cancel</a>
        </form>
    </div>
    <div class="overlay-container">
        <img src="CUBE3.png" alt="logo">
    </div>
</div>
</body>
</html>
