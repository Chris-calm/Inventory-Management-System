<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('location.view');

$flash = null;
$flashType = 'info';

$action = (string)($_GET['action'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$name = '';
$code = '';
$notes = '';
$status = 'active';

$editRow = null;
if ($action === 'edit' && $id > 0 && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    require_perm('location.edit');
    $stmt = $conn->prepare("SELECT id, name, code, notes, status FROM locations WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $editRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if ($editRow) {
        $name = (string)($editRow['name'] ?? '');
        $code = (string)($editRow['code'] ?? '');
        $notes = (string)($editRow['notes'] ?? '');
        $status = (string)($editRow['status'] ?? 'active');
    } else {
        $flash = 'Location not found.';
        $flashType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }

    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'save') {
        $editId = (int)($_POST['id'] ?? 0);
        if ($editId > 0) {
            require_perm('location.edit');
        } else {
            require_perm('location.create');
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $status = (string)($_POST['status'] ?? 'active');

        if ($name === '') {
            $flash = 'Name is required.';
            $flashType = 'error';
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $flash = 'Invalid status.';
            $flashType = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM locations WHERE (name = ? OR (code IS NOT NULL AND code = ?)) AND id <> ? LIMIT 1");
            if ($stmt) {
                $codeCheck = $code === '' ? null : $code;
                $stmt->bind_param('ssi', $name, $codeCheck, $editId);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $flash = 'Name or code already exists.';
                    $flashType = 'error';
                } else {
                    if ($editId > 0) {
                        $stmt2 = $conn->prepare("UPDATE locations SET name = ?, code = ?, notes = ?, status = ? WHERE id = ?");
                        if ($stmt2) {
                            $codeVal = $code === '' ? null : $code;
                            $notesVal = $notes === '' ? null : $notes;
                            $stmt2->bind_param('ssssi', $name, $codeVal, $notesVal, $status, $editId);
                            $ok = $stmt2->execute();
                            $stmt2->close();
                            if ($ok) {
                                audit_log($conn, 'location.update', 'location_id=' . (string)$editId, $editId);
                                header('Location: locations.php?msg=updated');
                                exit();
                            }
                        }
                        $flash = 'Failed to update location.';
                        $flashType = 'error';
                    } else {
                        $stmt2 = $conn->prepare("INSERT INTO locations (name, code, notes, status) VALUES (?, ?, ?, ?)");
                        if ($stmt2) {
                            $codeVal = $code === '' ? null : $code;
                            $notesVal = $notes === '' ? null : $notes;
                            $stmt2->bind_param('ssss', $name, $codeVal, $notesVal, $status);
                            $ok = $stmt2->execute();
                            $newId = (int)$conn->insert_id;
                            $stmt2->close();
                            if ($ok) {
                                audit_log($conn, 'location.create', 'location_id=' . (string)$newId, $newId);
                                header('Location: locations.php?msg=created');
                                exit();
                            }
                        }
                        $flash = 'Failed to create location.';
                        $flashType = 'error';
                    }
                }
            } else {
                $flash = 'Failed to validate uniqueness.';
                $flashType = 'error';
            }
        }
    }

    if ($postAction === 'delete') {
        require_perm('location.delete');
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $conn->prepare("DELETE FROM locations WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $deleteId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    audit_log($conn, 'location.delete', 'location_id=' . (string)$deleteId, $deleteId);
                    header('Location: locations.php?msg=deleted');
                    exit();
                }
            }
            $flash = 'Failed to delete location.';
            $flashType = 'error';
        }
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') { $flash = 'Location created.'; $flashType = 'success'; }
if ($msg === 'updated') { $flash = 'Location updated.'; $flashType = 'success'; }
if ($msg === 'deleted') { $flash = 'Location deleted.'; $flashType = 'success'; }

$rows = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($res = $conn->query("SELECT id, name, code, notes, status, created_at FROM locations ORDER BY name ASC")) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $res->free();
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
    <title>Locations</title>
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
            </ul>
        </div>
        <div class="bottom-content">
            <li class="nav-link"><a href="logout.php"><i class='bx bx-log-out icon'></i><span class="text nav-text">Logout</span></a></li>
            <li class="mode">
                <div class="moon-sun"><i class='bx bx-moon icon moon'></i><i class='bx bx-sun icon sun'></i></div>
                <span class="mode-text text">Dark Mode</span>
                <div class="toggle-switch"><span class="switch"></span></div>
            </li>
            <?php if (has_perm('rbac.assign')) { ?>
                <li class="nav-link"><a href="admin.php"><i class='bx bxl-product-hunt icon'></i><span class="text nav-text">Admin</span></a></li>
            <?php } ?>
        </div>
    </div>
</nav>

<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Locations</div>
            <div class="page-subtitle">Manage storage locations for transfers and stock tracking</div>
        </div>
        <div class="page-meta">
            <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><?php echo $action === 'edit' ? 'Edit Location' : 'Add Location'; ?></div>
                <div class="panel-icon bg-blue"><i class='bx bx-map-pin'></i></div>
            </div>
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <form method="post" class="form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)($action === 'edit' ? $id : 0); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <label class="label">Name</label>
                        <input class="input" type="text" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="label">Code</label>
                        <input class="input" type="text" name="code" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Optional">
                    </div>

                    <div class="form-row">
                        <label class="label">Notes</label>
                        <input class="input" type="text" name="notes" value="<?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Optional">
                    </div>

                    <div class="form-row">
                        <label class="label">Status</label>
                        <select class="input" name="status">
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <?php if ($action === 'edit') { ?>
                            <?php if (has_perm('location.edit')) { ?>
                                <button class="btn primary" type="submit">Update</button>
                            <?php } ?>
                            <a class="btn" href="locations.php">Cancel</a>
                        <?php } else { ?>
                            <?php if (has_perm('location.create')) { ?>
                                <button class="btn primary" type="submit">Create</button>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Location List</div>
                <div class="panel-icon bg-green"><i class='bx bx-list-ul'></i></div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($rows) === 0) { ?>
                            <tr><td colspan="6" class="muted">No locations yet.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($rows as $r) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['code'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <?php if (has_perm('location.edit')) { ?>
                                                <a class="btn" href="locations.php?action=edit&id=<?php echo (int)$r['id']; ?>">Edit</a>
                                            <?php } ?>
                                            <?php if (has_perm('location.delete')) { ?>
                                                <form method="post" class="inline" onsubmit="return confirm('Delete this location?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button class="btn danger" type="submit">Delete</button>
                                                </form>
                                            <?php } ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="script.js?v=20260225"></script>
</body>
</html>
