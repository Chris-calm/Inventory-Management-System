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

    if ($action === 'upload_avatar') {
        require_perm('user.edit');

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $flash = 'Invalid user.';
            $flashType = 'error';
        } elseif (!isset($_FILES['avatar_file']) || !is_array($_FILES['avatar_file'])) {
            $flash = 'No file uploaded.';
            $flashType = 'error';
        } else {
            $f = $_FILES['avatar_file'];
            $tmp = (string)($f['tmp_name'] ?? '');
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                $flash = 'Upload failed.';
                $flashType = 'error';
            } else {
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                $mime = '';
                try {
                    if (class_exists('finfo')) {
                        $fi = new finfo(FILEINFO_MIME_TYPE);
                        $mime = (string)$fi->file($tmp);
                    }
                } catch (Throwable $e) {
                    $mime = '';
                }

                $ext = $allowed[$mime] ?? '';
                if ($ext === '') {
                    $flash = 'Invalid image type. Please upload JPG/PNG/WebP.';
                    $flashType = 'error';
                } else {
                    $dirFs = realpath(__DIR__ . '/..');
                    $uploadDir = $dirFs ? ($dirFs . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'users') : '';
                    if ($uploadDir === '') {
                        $flash = 'Upload path error.';
                        $flashType = 'error';
                    } else {
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        foreach (glob($uploadDir . DIRECTORY_SEPARATOR . $userId . '.*') ?: [] as $old) {
                            @unlink($old);
                        }

                        $dest = $uploadDir . DIRECTORY_SEPARATOR . $userId . '.' . $ext;
                        if (move_uploaded_file($tmp, $dest)) {
                            $avatarRel = 'uploads/users/' . $userId . '.' . $ext;
                            if (function_exists('ensure_users_avatar_path_column') && ensure_users_avatar_path_column($conn)) {
                                try {
                                    $stmtA = $conn->prepare('UPDATE users SET avatar_path = ? WHERE id = ?');
                                    if ($stmtA) {
                                        $stmtA->bind_param('si', $avatarRel, $userId);
                                        $stmtA->execute();
                                        $stmtA->close();
                                    }
                                } catch (Throwable $e) {
                                }
                            }
                            audit_log($conn, 'user.avatar_upload', 'Uploaded avatar for user_id=' . (string)$userId, $userId);
                            header('Location: admin.php?msg=avatar');
                            exit();
                        }

                        $flash = 'Failed to save image.';
                        $flashType = 'error';
                    }
                }
            }
        }
    }

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
if ($msg === 'avatar') { $flash = 'Photo updated.'; $flashType = 'success'; }

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
            WHERE u.role <> 'guest'
            GROUP BY u.id
            ORDER BY u.created_at DESC, u.id DESC";

    $sqlNoName = "SELECT u.id, u.username, NULL AS full_name, u.created_at, COALESCE(u.totp_enabled, 0) AS totp_enabled, COALESCE(MAX(ur.role_id), 0) AS role_id, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS roles
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE u.role <> 'guest'
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
    <?php $pageTitle = 'Admin'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>
<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Admin</div>
            <div class="page-subtitle">User management and role assignments</div>
        </div>
        <div class="page-meta">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
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

        <div class="panel" id="employees">
            <div class="panel-header">
                <div class="panel-title">Employees</div>
                <div class="panel-icon bg-blue"><i class='bx bx-group'></i></div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Photo</th>
                            <th style="min-width: 320px;">Name</th>
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
                            <tr><td colspan="9" class="muted">No users found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($users as $u) { ?>
                                <?php
                                    $uid = (int)($u['id'] ?? 0);
                                    $fullNameVal = trim((string)($u['full_name'] ?? ''));
                                    $displayName = $fullNameVal !== '' ? $fullNameVal : (string)($u['username'] ?? '');
                                    $avatarUrl = function_exists('user_avatar_url') ? user_avatar_url(isset($conn) && $conn instanceof mysqli ? $conn : null, (int)$uid, '') : '';
                                ?>
                                <tr>
                                    <td style="min-width: 130px;">
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <?php if ($avatarUrl !== '') { ?>
                                                <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:36px;height:36px;border-radius:999px;object-fit:cover;border:1px solid var(--border);">
                                            <?php } else { ?>
                                                <div style="width:36px;height:36px;border-radius:999px;display:grid;place-items:center;background:var(--surface-2);border:1px solid var(--border);font-weight:800;color:var(--text-muted);">
                                                    <?php echo htmlspecialchars(strtoupper(substr((string)($u['username'] ?? 'U'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                            <?php } ?>

                                            <form method="post" enctype="multipart/form-data" style="margin:0; display:flex; align-items:center;">
                                                <input type="hidden" name="action" value="upload_avatar">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="btn" style="padding:6px 10px;">
                                                    Upload
                                                    <input type="file" name="avatar_file" accept="image/png,image/jpeg,image/webp" style="display:none;" onchange="this.form.submit();">
                                                </label>
                                            </form>
                                        </div>
                                    </td>
                                    <td style="min-width: 320px;">
                                        <form method="post" class="toolbar" style="grid-template-columns: 1fr auto; margin-bottom: 0;">
                                            <input type="hidden" name="action" value="update_name">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input class="input" type="text" name="full_name" value="<?php echo htmlspecialchars((string)($u['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($displayName !== '' ? $displayName : 'Full name', ENT_QUOTES, 'UTF-8'); ?>" style="min-width: 240px;" <?php echo !$hasFullNameColumn ? 'disabled' : ''; ?>>
                                            <button class="btn" type="submit" style="padding: 6px 10px;" <?php echo !$hasFullNameColumn ? 'disabled' : ''; ?>>Save</button>
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
                                        <form method="post" class="toolbar" style="grid-template-columns: 240px auto; margin-bottom: 0;">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input class="input" type="password" name="new_password" placeholder="New password" required style="min-width: 220px;">
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

<script src="../JS/script.js?v=20260225"></script>
</body>
</html>
