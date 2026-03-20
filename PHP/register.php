<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

if (isset($_SESSION['username']) && (string)$_SESSION['username'] !== '') {
    $role = (string)($_SESSION['role'] ?? 'staff');
    if ($role === 'guest') {
        header('Location: locations.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start();
}

$flash = null;
$flashType = 'info';

$values = [
    'username' => '',
    'password' => '',
    'confirm_password' => '',
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'company_name' => '',
    'company_website' => '',
    'address_line1' => '',
    'city' => '',
    'country' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
        $flash = 'Database connection failed.';
        $flashType = 'error';
    } else {
        foreach ($values as $k => $v) {
            $values[$k] = trim((string)($_POST[$k] ?? ''));
        }

        $username = $values['username'];
        $password = (string)($values['password'] ?? '');
        $confirm = (string)($values['confirm_password'] ?? '');

        if ($username === '' || $password === '' || $confirm === '') {
            $flash = 'Username and password are required.';
            $flashType = 'error';
        } elseif (strlen($username) < 4) {
            $flash = 'Username must be at least 4 characters.';
            $flashType = 'error';
        } elseif (strlen($password) < 8) {
            $flash = 'Password must be at least 8 characters.';
            $flashType = 'error';
        } elseif ($password !== $confirm) {
            $flash = 'Passwords do not match.';
            $flashType = 'error';
        } elseif ($values['full_name'] === '' || $values['email'] === '' || $values['phone'] === '') {
            $flash = 'Full name, email, and phone are required.';
            $flashType = 'error';
        } else {
            $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            if (!$stmt) {
                $flash = 'Registration failed.';
                $flashType = 'error';
            } else {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $flash = 'Username already exists.';
                    $flashType = 'error';
                } else {
                    try {
                        $conn->query("CREATE TABLE IF NOT EXISTS client_profiles (
                            user_id INT UNSIGNED NOT NULL,
                            full_name VARCHAR(190) NOT NULL,
                            email VARCHAR(190) NOT NULL,
                            phone VARCHAR(50) NOT NULL,
                            company_name VARCHAR(190) NULL,
                            company_website VARCHAR(190) NULL,
                            address_line1 VARCHAR(190) NULL,
                            city VARCHAR(120) NULL,
                            country VARCHAR(120) NULL,
                            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            PRIMARY KEY (user_id),
                            CONSTRAINT fk_client_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    } catch (Throwable $e) {
                    }

                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtIns = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'guest')");
                    if (!$stmtIns) {
                        $flash = 'Registration failed.';
                        $flashType = 'error';
                    } else {
                        $stmtIns->bind_param('ss', $username, $hash);
                        $ok = $stmtIns->execute();
                        $newUserId = (int)$conn->insert_id;
                        $stmtIns->close();

                        if (!$ok || $newUserId <= 0) {
                            $flash = 'Registration failed.';
                            $flashType = 'error';
                        } else {
                            try {
                                $rbacOk = false;
                                if ($res = $conn->query("SHOW TABLES LIKE 'user_roles'")) {
                                    $rbacOk = (bool)$res->fetch_assoc();
                                    $res->free();
                                }

                                if ($rbacOk) {
                                    $roleId = 0;
                                    $stmtRole = $conn->prepare("SELECT id FROM roles WHERE name = 'guest' LIMIT 1");
                                    if ($stmtRole) {
                                        $stmtRole->execute();
                                        $resRole = $stmtRole->get_result();
                                        if ($resRole && ($rowRole = $resRole->fetch_assoc())) {
                                            $roleId = (int)($rowRole['id'] ?? 0);
                                        }
                                        $stmtRole->close();
                                    }

                                    if ($roleId > 0) {
                                        $stmtUr = $conn->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
                                        if ($stmtUr) {
                                            $stmtUr->bind_param('ii', $newUserId, $roleId);
                                            $stmtUr->execute();
                                            $stmtUr->close();
                                        }
                                    }
                                }
                            } catch (Throwable $e) {
                            }

                            $stmtP = $conn->prepare('INSERT INTO client_profiles (user_id, full_name, email, phone, company_name, company_website, address_line1, city, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            if ($stmtP) {
                                $company = $values['company_name'] !== '' ? $values['company_name'] : null;
                                $website = $values['company_website'] !== '' ? $values['company_website'] : null;
                                $addr = $values['address_line1'] !== '' ? $values['address_line1'] : null;
                                $city = $values['city'] !== '' ? $values['city'] : null;
                                $country = $values['country'] !== '' ? $values['country'] : null;
                                $stmtP->bind_param(
                                    'issssssss',
                                    $newUserId,
                                    $values['full_name'],
                                    $values['email'],
                                    $values['phone'],
                                    $company,
                                    $website,
                                    $addr,
                                    $city,
                                    $country
                                );
                                $stmtP->execute();
                                $stmtP->close();
                            }

                            audit_log($conn, 'auth.guest_registered', 'Guest registration: ' . $username, $newUserId);
                            header('Location: ../index.php?msg=registered');
                            exit();
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account</title>
    <link rel="stylesheet" href="../CSS/loginstyle.css">
</head>
<body>
<div class="container" id="container" style="min-height: 620px;">
    <div class="form-container sign-in-container" style="width: 50%;">
        <form action="" method="POST" autocomplete="off">
            <h1>Create account</h1>
            <?php if ($flash) { echo "<p style='color:" . ($flashType === 'error' ? "red" : "green") . ";'>" . htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') . "</p>"; } ?>

            <input type="text" name="full_name" placeholder="Full name" value="<?php echo htmlspecialchars($values['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required />
            <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8'); ?>" required />
            <input type="text" name="phone" placeholder="Phone" value="<?php echo htmlspecialchars($values['phone'], ENT_QUOTES, 'UTF-8'); ?>" required />

            <input type="text" name="company_name" placeholder="Company name (optional)" value="<?php echo htmlspecialchars($values['company_name'], ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="text" name="company_website" placeholder="Company website (optional)" value="<?php echo htmlspecialchars($values['company_website'], ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="text" name="address_line1" placeholder="Address (optional)" value="<?php echo htmlspecialchars($values['address_line1'], ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="text" name="city" placeholder="City (optional)" value="<?php echo htmlspecialchars($values['city'], ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="text" name="country" placeholder="Country (optional)" value="<?php echo htmlspecialchars($values['country'], ENT_QUOTES, 'UTF-8'); ?>" />

            <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($values['username'], ENT_QUOTES, 'UTF-8'); ?>" required />
            <input type="password" name="password" placeholder="Password (min 8 characters)" required />
            <input type="password" name="confirm_password" placeholder="Confirm password" required />

            <button type="submit">Create Account</button>
            <a href="../index.php">Back to login</a>
        </form>
    </div>
    <div class="overlay-container">
        <img src="../CUBE3.png" alt="logo">
    </div>
</div>
</body>
</html>
