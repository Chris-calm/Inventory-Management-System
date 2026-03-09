<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('rbac.assign');

$flash = null;
$flashType = 'info';

$hasFullNameColumn = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'full_name'");
    if ($stmtCol) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasFullNameColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
}

$setupSecret = null;
$setupUser = null;
if (isset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_setup_user'])) {
    $setupSecret = (string)$_SESSION['2fa_setup_secret'];
    $setupUser = (string)$_SESSION['2fa_setup_user'];
    unset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_setup_user']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_user') {
        require_perm('user.create');

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);

        if ($username === '' || $password === '' || $roleId <= 0) {
            $flash = 'Username, password, and role are required.';
            $flashType = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $flash = 'Username already exists.';
                    $flashType = 'error';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt2 = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'staff')");
                    if ($stmt2) {
                        $stmt2->bind_param('ss', $username, $hash);
                        $ok = $stmt2->execute();
                        $newUserId = (int)$conn->insert_id;
                        $stmt2->close();

                        if ($ok && $newUserId > 0) {
                            $stmt3 = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                            if ($stmt3) {
                                $stmt3->bind_param('ii', $newUserId, $roleId);
                                $stmt3->execute();
                                $stmt3->close();
                            }

                            audit_log($conn, 'user.create', 'Created user: ' . $username, $newUserId);

                            header('Location: admin.php?msg=created');
                            exit();
                        }
                    }
                    $flash = 'Failed to create user.';
                    $flashType = 'error';
                }
            } else {
                $flash = 'Failed to validate username.';
                $flashType = 'error';
            }
        }
    }

    if ($action === 'assign_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);

        if ($userId <= 0 || $roleId <= 0) {
            $flash = 'Invalid user or role.';
            $flashType = 'error';
        } else {
            $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
            }

            $stmt2 = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            if ($stmt2) {
                $stmt2->bind_param('ii', $userId, $roleId);
                $ok = $stmt2->execute();
                $stmt2->close();
                if ($ok) {
                    audit_log($conn, 'rbac.assign', 'Assigned role_id=' . (string)$roleId . ' to user_id=' . (string)$userId, $userId);
                    header('Location: admin.php?msg=updated');
                    exit();
                }
            }

            $flash = 'Failed to assign role.';
            $flashType = 'error';
        }
    }

    if ($action === 'update_name') {
        require_perm('user.edit');

        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim((string)($_POST['full_name'] ?? ''));

        if ($userId <= 0) {
            $flash = 'Invalid user.';
            $flashType = 'error';
        } elseif (!$hasFullNameColumn) {
            $flash = 'Names feature is not enabled yet. Please import security_schema.sql.';
            $flashType = 'error';
        } else {
            $fullNameDb = $fullName === '' ? null : $fullName;
            $stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $fullNameDb, $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    audit_log($conn, 'user.edit_name', 'Updated name for user_id=' . (string)$userId, $userId);
                    header('Location: admin.php?msg=name');
                    exit();
                }
            }
            $flash = 'Failed to update name.';
            $flashType = 'error';
        }
    }

    if ($action === 'reset_password') {
        require_perm('user.edit');

        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($userId <= 0 || $newPassword === '') {
            $flash = 'User and new password are required.';
            $flashType = 'error';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $hash, $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    audit_log($conn, 'user.password_reset', 'Password reset for user_id=' . (string)$userId, $userId);
                    header('Location: admin.php?msg=pw');
                    exit();
                }
            }
            $flash = 'Failed to reset password.';
            $flashType = 'error';
        }
    }

    if ($action === 'delete_user') {
        require_perm('user.delete');

        $userId = (int)($_POST['user_id'] ?? 0);
        $currentUsername = (string)($_SESSION['username'] ?? '');
        $currentUserId = 0;

        if ($currentUsername !== '') {
            $stmtMe = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($stmtMe) {
                $stmtMe->bind_param('s', $currentUsername);
                $stmtMe->execute();
                $resMe = $stmtMe->get_result();
                if ($resMe && ($rowMe = $resMe->fetch_assoc())) {
                    $currentUserId = (int)($rowMe['id'] ?? 0);
                }
                $stmtMe->close();
            }
        }

        if ($userId <= 0) {
            $flash = 'Invalid user.';
            $flashType = 'error';
        } elseif ($currentUserId > 0 && $userId === $currentUserId) {
            $flash = 'You cannot delete your own account.';
            $flashType = 'error';
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    audit_log($conn, 'user.delete', 'Deleted user_id=' . (string)$userId, $userId);
                    header('Location: admin.php?msg=deleted');
                    exit();
                }
            }
            $flash = 'Failed to delete user.';
            $flashType = 'error';
        }
    }

    if ($action === 'enable_2fa') {
        require_perm('user.edit');

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $flash = 'Invalid user.';
            $flashType = 'error';
        } else {
            $secret = totp_generate_secret(20);
            $stmt = $conn->prepare("UPDATE users SET totp_enabled = 1, totp_secret = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $secret, $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    $uStmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                    $uname = '';
                    if ($uStmt) {
                        $uStmt->bind_param('i', $userId);
                        $uStmt->execute();
                        $resU = $uStmt->get_result();
                        if ($resU && ($ru = $resU->fetch_assoc())) {
                            $uname = (string)($ru['username'] ?? '');
                        }
                        $uStmt->close();
                    }
                    $_SESSION['2fa_setup_secret'] = $secret;
                    $_SESSION['2fa_setup_user'] = $uname !== '' ? $uname : ('user_id=' . (string)$userId);
                    audit_log($conn, 'user.2fa_enabled', 'Enabled 2FA', $userId);
                    header('Location: admin.php?msg=2fa_on');
                    exit();
                }
            }
            $flash = 'Failed to enable 2FA.';
            $flashType = 'error';
        }
    }

    if ($action === 'disable_2fa') {
        require_perm('user.edit');

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $flash = 'Invalid user.';
            $flashType = 'error';
        } else {
            $stmt = $conn->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    audit_log($conn, 'user.2fa_disabled', 'Disabled 2FA', $userId);
                    header('Location: admin.php?msg=2fa_off');
                    exit();
                }
            }
            $flash = 'Failed to disable 2FA.';
            $flashType = 'error';
        }
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') { $flash = 'User created.'; $flashType = 'success'; }
if ($msg === 'updated') { $flash = 'Role updated.'; $flashType = 'success'; }
if ($msg === 'pw') { $flash = 'Password updated.'; $flashType = 'success'; }
if ($msg === 'deleted') { $flash = 'User deleted.'; $flashType = 'success'; }
if ($msg === '2fa_on') { $flash = '2FA enabled.'; $flashType = 'success'; }
if ($msg === '2fa_off') { $flash = '2FA disabled.'; $flashType = 'success'; }
if ($msg === 'name') { $flash = 'Name updated.'; $flashType = 'success'; }

$roles = [];
$users = [];
$auditLogs = [];
$hasAuditLogsTable = false;

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($res = $conn->query("SELECT id, name FROM roles ORDER BY name ASC")) {
        while ($r = $res->fetch_assoc()) { $roles[] = $r; }
        $res->free();
    }

    if ($res = $conn->query("SHOW TABLES LIKE 'audit_logs'")) {
        $hasAuditLogsTable = (bool)$res->fetch_assoc();
        $res->free();
    }

    $sqlWithName = "SELECT u.id, u.username, u.full_name, u.created_at, COALESCE(u.totp_enabled, 0) AS totp_enabled, COALESCE(MAX(ur.role_id), 0) AS role_id, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS roles
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            GROUP BY u.id
            ORDER BY u.created_at DESC, u.id DESC";

    $sqlNoName = "SELECT u.id, u.username, NULL AS full_name, u.created_at, COALESCE(u.totp_enabled, 0) AS totp_enabled, COALESCE(MAX(ur.role_id), 0) AS role_id, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS roles
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            GROUP BY u.id
            ORDER BY u.created_at DESC, u.id DESC";

    try {
        $sqlUsers = $hasFullNameColumn ? $sqlWithName : $sqlNoName;
        $res = $conn->query($sqlUsers);
    } catch (mysqli_sql_exception $e) {
        $hasFullNameColumn = false;
        $res = $conn->query($sqlNoName);
    }

    if ($res) {
        while ($r = $res->fetch_assoc()) { $users[] = $r; }
        $res->free();
    }

    if ($hasAuditLogsTable) {
        $sqlAudit = "SELECT al.action, al.details, al.ip_address, al.created_at, u.username AS actor
                    FROM audit_logs al
                    LEFT JOIN users u ON u.id = al.actor_user_id
                    ORDER BY al.created_at DESC, al.id DESC
                    LIMIT 25";
        if ($res = $conn->query($sqlAudit)) {
            while ($r = $res->fetch_assoc()) { $auditLogs[] = $r; }
            $res->free();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var t = localStorage.getItem('ims_theme');
                if (t === 'dark') {
                    document.documentElement.classList.add('dark');
                    document.body && document.body.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="style2.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <title>Admin</title>
</head>
<body>
<nav class="sidebar close">
    <header>
        <div class="image-text">
            <span class="image"><img src="CUBE3.png" alt="logo"></span>
            <div class="text header"><span class="name">CUBE</span><span class="proffesion">Company</span></div>
        </div>
        <i class='bx bx-chevron-right toggle'></i>
    </header>
    <div class="menu-bar">
        <div class="menu">
            <li class="search-box"><i class='bx bx-search icon'></i><input type="text" placeholder="Search..."></li>
            <ul class="menu-link">
                <li class="nav-link"><a href="dashboard.php"><i class='bx bx-home-alt icon'></i><span class="text nav-text">Dashboard</span></a></li>
                <li class="nav-link"><a href="analytics.php"><i class='bx bx-pie-chart-alt icon'></i><span class="text nav-text">Analytics</span></a></li>
                <li class="nav-link"><a href="category.php"><i class='bx bxs-category-alt icon'></i><span class="text nav-text">Category</span></a></li>
                <li class="nav-link"><a href="product.php"><i class='bx bxl-product-hunt icon'></i><span class="text nav-text">Product</span></a></li>
                <?php $canMovement = has_perm('movement.view'); ?>
                <?php $canLocations = has_perm('location.view'); ?>
                <?php if ($canMovement || $canLocations) { ?>
                    <li class="nav-dropdown">
                        <a href="#" class="dropdown-toggle">
                            <i class='bx bx-transfer-alt icon'></i>
                            <span class="text nav-text">Stock</span>
                            <i class='bx bx-chevron-down dd-icon'></i>
                        </a>
                        <ul class="submenu">
                            <?php if ($canMovement) { ?>
                                <li><a href="transactions.php"><span class="text nav-text">Stock In/Out</span></a></li>
                            <?php } ?>
                            <?php if ($canLocations) { ?>
                                <li><a href="locations.php"><span class="text nav-text">Locations</span></a></li>
                            <?php } ?>
                        </ul>
                    </li>
                <?php } ?>
                <li class="nav-link"><a href="admin.php"><i class='bx bx-shield-quarter icon'></i><span class="text nav-text">Admin</span></a></li>
            </ul>
        </div>
        <div class="bottom-content">
            <li class="nav-link"><a href="logout.php"><i class='bx bx-log-out icon'></i><span class="text nav-text">Logout</span></a></li>
            <li class="mode">
                <div class="moon-sun"><i class='bx bx-moon icon moon'></i><i class='bx bx-sun icon sun'></i></div>
                <span class="mode-text text">Dark Mode</span>
                <div class="toggle-switch"><span class="switch"></span></div>
            </li>
        </div>
    </div>
</nav>

<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Admin</div>
            <div class="page-subtitle">User management and role assignments</div>
        </div>
        <div class="page-meta">
            <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Create User</div>
                <div class="panel-icon bg-green"><i class='bx bx-user-plus'></i></div>
            </div>
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($setupSecret && $setupUser) { ?>
                    <div class="alert success">
                        2FA secret for <?php echo htmlspecialchars($setupUser, ENT_QUOTES, 'UTF-8'); ?>:
                        <strong><?php echo htmlspecialchars($setupSecret, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                <?php } ?>

                <form method="post" class="form">
                    <input type="hidden" name="action" value="create_user">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <label class="label">Username</label>
                        <input class="input" type="text" name="username" required>
                    </div>
                    <div class="form-row">
                        <label class="label">Password</label>
                        <input class="input" type="password" name="password" required>
                    </div>
                    <div class="form-row">
                        <label class="label">Role</label>
                        <select class="input" name="role_id" required>
                            <option value="">Select role</option>
                            <?php foreach ($roles as $r) { ?>
                                <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button class="btn primary" type="submit">Create</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Users</div>
                <div class="panel-icon bg-blue"><i class='bx bx-group'></i></div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Roles</th>
                            <th>Created</th>
                            <th>Assign Role</th>
                            <th>Reset Password</th>
                            <th>2FA</th>
                            <th>Delete</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($users) === 0) { ?>
                            <tr><td colspan="8" class="muted">No users found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($users as $u) { ?>
                                <tr>
                                    <td>
                                        <form method="post" class="toolbar" style="grid-template-columns: 1fr auto; margin-bottom: 0;">
                                            <input type="hidden" name="action" value="update_name">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input class="input" type="text" name="full_name" value="<?php echo htmlspecialchars((string)($u['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Full name">
                                            <button class="btn" type="submit" style="padding: 6px 10px;">Save</button>
                                        </form>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($u['roles'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($u['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <form method="post" class="toolbar" style="grid-template-columns: 1fr 180px auto; margin-bottom: 0;">
                                            <input type="hidden" name="action" value="assign_role">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <select class="input" name="role_id" required>
                                                <option value="">Select role</option>
                                                <?php foreach ($roles as $r) { ?>
                                                    <option value="<?php echo (int)$r['id']; ?>" <?php echo (int)($u['role_id'] ?? 0) === (int)$r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                <?php } ?>
                                            </select>
                                            <button class="btn" type="submit" style="padding: 6px 10px;">Assign</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" class="toolbar" style="grid-template-columns: 1fr auto; margin-bottom: 0;">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input class="input" type="password" name="new_password" placeholder="New password" required>
                                            <button class="btn" type="submit">Update</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ((int)($u['totp_enabled'] ?? 0) === 1) { ?>
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Disable 2FA for this user?');">
                                                <input type="hidden" name="action" value="disable_2fa">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <button class="btn" type="submit">Disable</button>
                                            </form>
                                        <?php } else { ?>
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Enable 2FA for this user? You will need to copy the secret.');">
                                                <input type="hidden" name="action" value="enable_2fa">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <button class="btn" type="submit">Enable</button>
                                            </form>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <form method="post" style="margin: 0;" onsubmit="return confirm('Delete this user?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <button class="btn" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="panel" style="grid-column: 1 / -1;">
            <div class="panel-header">
                <div class="panel-title">Recent Audit Logs</div>
                <div class="panel-icon bg-orange"><i class='bx bx-receipt'></i></div>
            </div>
            <div class="panel-body">
                <?php if (!$hasAuditLogsTable) { ?>
                    <div class="muted">Audit logs are not enabled yet. Please import <strong>security_schema.sql</strong>.</div>
                <?php } else { ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Time</th>
                                <th>Actor</th>
                                <th>Action</th>
                                <th>IP</th>
                                <th>Details</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (count($auditLogs) === 0) { ?>
                                <tr><td colspan="5" class="muted">No audit records yet.</td></tr>
                            <?php } else { ?>
                                <?php foreach ($auditLogs as $l) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($l['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($l['actor'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($l['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($l['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($l['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</section>

<script src="script.js?v=20260225"></script>
</body>
</html>
