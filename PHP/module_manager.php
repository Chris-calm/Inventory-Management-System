<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('rbac.assign');

$flash = null;
$flashType = 'info';

$appModules = [
    'dashboard' => ['name' => 'Dashboard', 'path' => 'dashboard.php'],
    'analytics' => ['name' => 'Analytics', 'path' => 'analytics.php'],
    'category' => ['name' => 'Category', 'path' => 'category.php'],
    'product' => ['name' => 'Product', 'path' => 'product.php'],
    'stock_movements' => ['name' => 'Stock In/Out', 'path' => 'transactions.php'],
    'locations' => ['name' => 'Locations', 'path' => 'locations.php'],
];

$hasRoleModulesTable = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'role_modules'")) {
            $hasRoleModulesTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasRoleModulesTable = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_role_modules') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $allowedModules = $_POST['allowed_modules'] ?? [];
        $orderJson = (string)($_POST['module_order'] ?? '');

        if ($roleId <= 0) {
            $flash = 'Invalid staff division.';
            $flashType = 'error';
        } else {
            if (!$hasRoleModulesTable) {
                try {
                    $conn->query("CREATE TABLE IF NOT EXISTS role_modules (
                        role_id INT UNSIGNED NOT NULL,
                        module_key VARCHAR(50) NOT NULL,
                        allowed TINYINT(1) NOT NULL DEFAULT 1,
                        sort_order INT NOT NULL DEFAULT 0,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (role_id, module_key),
                        KEY idx_role_modules_role (role_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    $hasRoleModulesTable = true;
                } catch (Throwable $e) {
                    $hasRoleModulesTable = false;
                }
            }

            if (!$hasRoleModulesTable) {
                $flash = 'Module manager storage is not available.';
                $flashType = 'error';
            } else {
                $allowedSet = [];
                if (is_array($allowedModules)) {
                    foreach ($allowedModules as $m) {
                        $k = trim((string)$m);
                        if ($k !== '' && isset($appModules[$k])) {
                            $allowedSet[$k] = true;
                        }
                    }
                }

                $decodedOrder = [];
                if ($orderJson !== '') {
                    $tmp = json_decode($orderJson, true);
                    if (is_array($tmp)) {
                        foreach ($tmp as $k) {
                            $kk = trim((string)$k);
                            if ($kk !== '' && isset($appModules[$kk])) {
                                $decodedOrder[] = $kk;
                            }
                        }
                    }
                }
                if (count($decodedOrder) === 0) {
                    $decodedOrder = array_keys($appModules);
                }

                $sortMap = [];
                $i = 0;
                foreach ($decodedOrder as $k) {
                    $sortMap[$k] = $i;
                    $i++;
                }
                foreach (array_keys($appModules) as $k) {
                    if (!isset($sortMap[$k])) {
                        $sortMap[$k] = $i;
                        $i++;
                    }
                }

                try {
                    $stmtDel = $conn->prepare('DELETE FROM role_modules WHERE role_id = ?');
                    if ($stmtDel) {
                        $stmtDel->bind_param('i', $roleId);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }

                    $stmtIns = $conn->prepare('INSERT INTO role_modules (role_id, module_key, allowed, sort_order) VALUES (?, ?, ?, ?)');
                    if ($stmtIns) {
                        foreach ($appModules as $k => $_v) {
                            $allowed = isset($allowedSet[$k]) ? 1 : 0;
                            $sort = (int)($sortMap[$k] ?? 0);
                            $stmtIns->bind_param('isii', $roleId, $k, $allowed, $sort);
                            $stmtIns->execute();
                        }
                        $stmtIns->close();
                    }

                    audit_log($conn, 'rbac.module_manager_save', 'Updated module config for role_id=' . (string)$roleId);
                    header('Location: module_manager.php?role_id=' . (string)$roleId);
                    exit();
                } catch (Throwable $e) {
                    $flash = 'Failed to save module configuration.';
                    $flashType = 'error';
                }
            }
        }
    }
}

$roles = [];
$selectedRoleId = 0;
$roleModuleRows = [];

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($res = $conn->query("SELECT id, name FROM roles ORDER BY name ASC")) {
        while ($r = $res->fetch_assoc()) {
            $roles[] = $r;
        }
        $res->free();
    }

    $selectedRoleId = (int)($_GET['role_id'] ?? 0);
    if ($selectedRoleId <= 0 && count($roles) > 0) {
        foreach ($roles as $r) {
            if ((string)($r['name'] ?? '') === 'staff') {
                $selectedRoleId = (int)($r['id'] ?? 0);
                break;
            }
        }
    }
    if ($selectedRoleId <= 0 && count($roles) > 0) {
        $selectedRoleId = (int)($roles[0]['id'] ?? 0);
    }

    if ($hasRoleModulesTable && $selectedRoleId > 0) {
        $stmtRm = $conn->prepare('SELECT module_key, allowed, sort_order FROM role_modules WHERE role_id = ? ORDER BY sort_order ASC');
        if ($stmtRm) {
            $stmtRm->bind_param('i', $selectedRoleId);
            $stmtRm->execute();
            $resRm = $stmtRm->get_result();
            if ($resRm) {
                while ($row = $resRm->fetch_assoc()) {
                    $k = (string)($row['module_key'] ?? '');
                    if ($k !== '') {
                        $roleModuleRows[$k] = [
                            'allowed' => (int)($row['allowed'] ?? 0),
                            'sort_order' => (int)($row['sort_order'] ?? 0),
                        ];
                    }
                }
            }
            $stmtRm->close();
        }
    }
}

$allowedByKey = [];
foreach ($appModules as $k => $_v) {
    $allowedByKey[$k] = isset($roleModuleRows[$k]) ? ((int)($roleModuleRows[$k]['allowed'] ?? 0) === 1) : true;
}

$orderedKeys = array_keys($appModules);
if (count($roleModuleRows) > 0) {
    uasort($orderedKeys, function ($a, $b) use ($roleModuleRows) {
        $sa = (int)($roleModuleRows[$a]['sort_order'] ?? 0);
        $sb = (int)($roleModuleRows[$b]['sort_order'] ?? 0);
        return $sa <=> $sb;
    });
    $orderedKeys = array_values($orderedKeys);
}

$selRoleName = '';
foreach ($roles as $r) {
    if ((int)($r['id'] ?? 0) === (int)$selectedRoleId) {
        $selRoleName = (string)($r['name'] ?? '');
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Module Manager'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>
<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Module Manager</div>
            <div class="page-subtitle">Reorder and assign modules per staff division</div>
        </div>
        <div class="page-meta">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
        </div>
    </div>

    <div class="content-grid" style="grid-template-columns: 1fr;">
        <div class="panel">
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <div class="mm-grid">
                    <div class="mm-left">
                        <form method="get" class="form" style="margin-bottom: 12px;">
                            <div class="form-row">
                                <label class="label">Staff Division</label>
                                <select class="input" name="role_id" onchange="this.form.submit();">
                                    <?php foreach ($roles as $r) { ?>
                                        <option value="<?php echo (int)$r['id']; ?>" <?php echo (int)($r['id'] ?? 0) === (int)$selectedRoleId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </form>

                        <form method="post" class="form" id="mm-save-form">
                            <input type="hidden" name="action" value="save_role_modules">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="role_id" value="<?php echo (int)$selectedRoleId; ?>">
                            <input type="hidden" name="module_order" id="mm-module-order" value="">

                            <div class="form-row">
                                <label class="label">Allowed Modules</label>
                                <div class="mm-checks">
                                    <?php foreach ($orderedKeys as $k) { ?>
                                        <label class="mm-check">
                                            <input type="checkbox" name="allowed_modules[]" value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo !empty($allowedByKey[$k]) ? 'checked' : ''; ?>>
                                            <span><?php echo htmlspecialchars((string)($appModules[$k]['name'] ?? $k), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button class="btn primary" type="submit">Save Order</button>
                            </div>
                        </form>
                    </div>

                    <div class="mm-right">
                        <div class="mm-right-head">
                            <div class="panel-title" style="padding:0;">Modules for: <?php echo htmlspecialchars($selRoleName !== '' ? $selRoleName : 'Staff', ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="muted">Drag &amp; drop to reorder</div>
                        </div>
                        <div class="mm-list" id="mm-list">
                            <?php foreach ($orderedKeys as $k) { ?>
                                <div class="mm-item" draggable="true" data-module-key="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="mm-grip"><i class='bx bx-menu'></i></div>
                                    <div class="mm-item-main">
                                        <div class="mm-item-title"><?php echo htmlspecialchars((string)($appModules[$k]['name'] ?? $k), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="mm-item-sub"><?php echo htmlspecialchars((string)($appModules[$k]['path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="../JS/script.js?v=20260225"></script>
</body>
</html>
