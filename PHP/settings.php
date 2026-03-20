<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications_lib.php';

require_login();

$flash = null;
$flashType = 'info';

$userId = (int)($_SESSION['user_id'] ?? 0);

$hasUserProfilesTable = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'user_profiles'")) {
            $hasUserProfilesTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasUserProfilesTable = false;
    }
}

$profile = [
    'email' => '',
    'phone' => '',
    'department' => '',
    'position' => '',
    'timezone' => 'Asia/Manila',
    'language' => 'en',
];

$avatarUrl = function_exists('user_avatar_url') ? user_avatar_url(isset($conn) && $conn instanceof mysqli ? $conn : null, $userId, '../CUBE3.png') : '../CUBE3.png';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload_avatar') {
        if (!isset($_FILES['avatar_file']) || !is_array($_FILES['avatar_file'])) {
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
                            if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
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
                            }
                            $flash = 'Profile photo updated.';
                            $flashType = 'success';
                            header('Location: settings.php');
                            exit();
                        }

                        $flash = 'Failed to save image.';
                        $flashType = 'error';
                    }
                }
            }
        }
    }

    if ($action === 'save_profile') {
        if (!$hasUserProfilesTable) {
            try {
                $conn->query("CREATE TABLE IF NOT EXISTS user_profiles (
                    user_id INT UNSIGNED NOT NULL,
                    email VARCHAR(190) NULL,
                    phone VARCHAR(50) NULL,
                    department VARCHAR(120) NULL,
                    position VARCHAR(120) NULL,
                    timezone VARCHAR(80) NULL,
                    language VARCHAR(20) NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id),
                    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $hasUserProfilesTable = true;
            } catch (Throwable $e) {
                $hasUserProfilesTable = false;
            }
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $position = trim((string)($_POST['position'] ?? ''));
        $timezone = trim((string)($_POST['timezone'] ?? ''));
        $language = trim((string)($_POST['language'] ?? ''));

        $okProfile = true;
        if ($hasUserProfilesTable && $userId > 0) {
            try {
                $stmt = $conn->prepare('INSERT INTO user_profiles (user_id, email, phone, department, position, timezone, language) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE email=VALUES(email), phone=VALUES(phone), department=VALUES(department), position=VALUES(position), timezone=VALUES(timezone), language=VALUES(language)');
                if ($stmt) {
                    $emailDb = $email === '' ? null : $email;
                    $phoneDb = $phone === '' ? null : $phone;
                    $departmentDb = $department === '' ? null : $department;
                    $positionDb = $position === '' ? null : $position;
                    $timezoneDb = $timezone === '' ? null : $timezone;
                    $languageDb = $language === '' ? null : $language;
                    $stmt->bind_param('issssss', $userId, $emailDb, $phoneDb, $departmentDb, $positionDb, $timezoneDb, $languageDb);
                    $okProfile = $stmt->execute();
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $okProfile = false;
            }
        }

        $flash = $okProfile ? 'Settings updated.' : 'Failed to update settings.';
        $flashType = $okProfile ? 'success' : 'error';
    }

    if ($action === 'save_notifications') {
        $notifyMovements = (string)($_POST['notify_movements'] ?? '') === '1';
        $notifyLowStock = (string)($_POST['notify_low_stock'] ?? '') === '1';
        $notifySystem = (string)($_POST['notify_system'] ?? '') === '1';

        $ok = notifications_update_settings($conn, $userId, [
            'notify_movements' => $notifyMovements,
            'notify_low_stock' => $notifyLowStock,
            'notify_system' => $notifySystem,
        ]);

        $flash = $ok ? 'Settings updated.' : 'Failed to update settings.';
        $flashType = $ok ? 'success' : 'error';
    }

    if ($action === '') {
        $flash = 'Invalid request.';
        $flashType = 'error';
    }
}

$settings = [
    'notify_movements' => 1,
    'notify_low_stock' => 1,
    'notify_system' => 1,
];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $settings = notifications_get_settings($conn, $userId);

    if (!$hasUserProfilesTable) {
        try {
            if ($res = $conn->query("SHOW TABLES LIKE 'user_profiles'")) {
                $hasUserProfilesTable = (bool)$res->fetch_assoc();
                $res->free();
            }
        } catch (Throwable $e) {
            $hasUserProfilesTable = false;
        }
    }

    if ($hasUserProfilesTable && $userId > 0) {
        try {
            $stmt = $conn->prepare('SELECT email, phone, department, position, timezone, language FROM user_profiles WHERE user_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $profile['email'] = (string)($row['email'] ?? '');
                    $profile['phone'] = (string)($row['phone'] ?? '');
                    $profile['department'] = (string)($row['department'] ?? '');
                    $profile['position'] = (string)($row['position'] ?? '');
                    $profile['timezone'] = (string)($row['timezone'] ?? $profile['timezone']);
                    $profile['language'] = (string)($row['language'] ?? $profile['language']);
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Settings'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<section class="home">
    <div class="page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="page-title">Settings</div>
            <div class="page-subtitle">Manage your preferences</div>
        </div>
        <div class="page-meta self-start sm:self-auto">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
        </div>
    </div>

    <div class="content-grid grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Profile & Preferences</div>
                <div class="panel-icon bg-blue"><i class='bx bx-user'></i></div>
            </div>
            <div class="panel-body">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom: 14px;">
                    <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:56px;height:56px;border-radius:999px;object-fit:cover;border:1px solid var(--border);">
                    <form method="post" enctype="multipart/form-data" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="upload_avatar">
                        <label class="btn" style="padding:8px 12px;">
                            Change photo
                            <input type="file" name="avatar_file" accept="image/png,image/jpeg,image/webp" style="display:none;" onchange="this.form.submit();">
                        </label>
                    </form>
                </div>

                <form method="post" class="form" style="max-width: 520px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save_profile">

                    <div class="form-row">
                        <label class="label">Email</label>
                        <input class="input" type="email" name="email" value="<?php echo htmlspecialchars((string)$profile['email'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="name@company.com">
                    </div>

                    <div class="form-row">
                        <label class="label">Phone</label>
                        <input class="input" type="text" name="phone" value="<?php echo htmlspecialchars((string)$profile['phone'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="+63 9xx xxx xxxx">
                    </div>

                    <div class="form-row two">
                        <div class="form-row">
                            <label class="label">Department</label>
                            <input class="input" type="text" name="department" value="<?php echo htmlspecialchars((string)$profile['department'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Warehouse / Purchasing">
                        </div>
                        <div class="form-row">
                            <label class="label">Position</label>
                            <input class="input" type="text" name="position" value="<?php echo htmlspecialchars((string)$profile['position'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Staff / Admin">
                        </div>
                    </div>

                    <div class="form-row two">
                        <div class="form-row">
                            <label class="label">Timezone</label>
                            <select class="input" name="timezone">
                                <?php
                                    $tzs = ['Asia/Manila','Asia/Singapore','Asia/Tokyo','UTC'];
                                    foreach ($tzs as $tz) {
                                        $sel = ((string)$profile['timezone'] === (string)$tz) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '" ' . $sel . '>' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label class="label">Language</label>
                            <select class="input" name="language">
                                <option value="en" <?php echo ((string)$profile['language'] === 'en') ? 'selected' : ''; ?>>English</option>
                                <option value="fil" <?php echo ((string)$profile['language'] === 'fil') ? 'selected' : ''; ?>>Filipino</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Notifications</div>
                <div class="panel-icon bg-purple"><i class='bx bx-bell'></i></div>
            </div>
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <form method="post" class="form" style="max-width: 520px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <label class="label" style="display:flex; align-items:center; gap:10px; font-weight:600;">
                            <input type="checkbox" name="notify_movements" value="1" <?php echo (int)($settings['notify_movements'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            <span>Stock movements (created/approved/rejected)</span>
                        </label>
                    </div>

                    <div class="form-row">
                        <label class="label" style="display:flex; align-items:center; gap:10px; font-weight:600;">
                            <input type="checkbox" name="notify_low_stock" value="1" <?php echo (int)($settings['notify_low_stock'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            <span>Low stock alerts</span>
                        </label>
                    </div>

                    <div class="form-row">
                        <label class="label" style="display:flex; align-items:center; gap:10px; font-weight:600;">
                            <input type="checkbox" name="notify_system" value="1" <?php echo (int)($settings['notify_system'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            <span>System notifications</span>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button class="btn primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<script src="../JS/script.js?v=20260225"></script>
</body>
</html>
