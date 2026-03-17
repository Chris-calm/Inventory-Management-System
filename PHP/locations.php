<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('location.view');

$flash = null;
$flashType = 'info';

$hasImagePathColumn = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND COLUMN_NAME = 'image_path'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasImagePathColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if (!$hasImagePathColumn) {
        try {
            $conn->query("ALTER TABLE locations ADD COLUMN image_path VARCHAR(255) NULL");
            $hasImagePathColumn = true;
        } catch (Throwable $e) {
            $hasImagePathColumn = false;
        }
    }
}

$action = (string)($_GET['action'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$name = '';
$code = '';
$notes = '';
$status = 'active';
$imagePath = '';

$editRow = null;
if ($action === 'edit' && $id > 0 && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    require_perm('location.edit');
    $selectCols = "id, name, code, notes, status";
    if ($hasImagePathColumn) {
        $selectCols .= ", image_path";
    }
    $stmt = $conn->prepare("SELECT $selectCols FROM locations WHERE id = ? LIMIT 1");
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
        if ($hasImagePathColumn) {
            $imagePath = (string)($editRow['image_path'] ?? '');
        }
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

        if ($hasImagePathColumn) {
            $imagePath = trim((string)($_POST['existing_image_path'] ?? $imagePath));
            if (isset($_FILES['image']) && is_array($_FILES['image']) && isset($_FILES['image']['error']) && (int)$_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $tmpName = (string)($_FILES['image']['tmp_name'] ?? '');
                $origName = (string)($_FILES['image']['name'] ?? '');
                $size = (int)($_FILES['image']['size'] ?? 0);
                $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if ($tmpName === '' || $size <= 0) {
                    $flash = 'Invalid image upload.';
                    $flashType = 'error';
                } elseif (!in_array($ext, $allowed, true)) {
                    $flash = 'Image must be JPG, PNG, or WEBP.';
                    $flashType = 'error';
                } elseif ($size > 3 * 1024 * 1024) {
                    $flash = 'Image must be 3MB or less.';
                    $flashType = 'error';
                } else {
                    $uploadDir = dirname(__DIR__) . '/assets/uploads/locations';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }
                    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($origName, PATHINFO_FILENAME));
                    $fileName = 'loc_' . ($safeName !== '' ? $safeName . '_' : '') . uniqid('', true) . '.' . $ext;
                    $dest = $uploadDir . '/' . $fileName;
                    if (@move_uploaded_file($tmpName, $dest)) {
                        $imagePath = 'assets/uploads/locations/' . $fileName;
                    } else {
                        $flash = 'Failed to save uploaded image.';
                        $flashType = 'error';
                    }
                }
            }
        }

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
                        $set = "name = ?, code = ?, notes = ?, status = ?";
                        if ($hasImagePathColumn) {
                            $set .= ", image_path = ?";
                        }
                        $stmt2 = $conn->prepare("UPDATE locations SET $set WHERE id = ?");
                        if ($stmt2) {
                            $codeVal = $code === '' ? null : $code;
                            $notesVal = $notes === '' ? null : $notes;
                            if ($hasImagePathColumn) {
                                $imgVal = $imagePath === '' ? null : $imagePath;
                                $stmt2->bind_param('sssssi', $name, $codeVal, $notesVal, $status, $imgVal, $editId);
                            } else {
                                $stmt2->bind_param('ssssi', $name, $codeVal, $notesVal, $status, $editId);
                            }
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
                        $cols = 'name, code, notes, status';
                        $vals = '?, ?, ?, ?';
                        if ($hasImagePathColumn) {
                            $cols .= ', image_path';
                            $vals .= ', ?';
                        }
                        $stmt2 = $conn->prepare("INSERT INTO locations ($cols) VALUES ($vals)");
                        if ($stmt2) {
                            $codeVal = $code === '' ? null : $code;
                            $notesVal = $notes === '' ? null : $notes;
                            if ($hasImagePathColumn) {
                                $imgVal = $imagePath === '' ? null : $imagePath;
                                $stmt2->bind_param('sssss', $name, $codeVal, $notesVal, $status, $imgVal);
                            } else {
                                $stmt2->bind_param('ssss', $name, $codeVal, $notesVal, $status);
                            }
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
    $listCols = "id, name, code, notes, status, created_at";
    if ($hasImagePathColumn) {
        $listCols .= ", image_path";
    } else {
        $listCols .= ", NULL AS image_path";
    }
    if ($res = $conn->query("SELECT $listCols FROM locations ORDER BY name ASC")) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $res->free();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Locations'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

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

                <form method="post" class="form" <?php echo $hasImagePathColumn ? 'enctype="multipart/form-data"' : ''; ?>>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)($action === 'edit' ? $id : 0); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($hasImagePathColumn) { ?>
                        <input type="hidden" name="existing_image_path" value="<?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php } ?>

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

                    <?php if ($hasImagePathColumn) { ?>
                        <div class="form-row">
                            <label class="label">Image</label>
                            <input class="input" type="file" name="image" accept="image/png,image/jpeg,image/webp">
                            <?php if ($imagePath !== '') { ?>
                                <div class="muted" style="margin-top: 8px; display: flex; align-items: center; gap: 10px;">
                                    <img class="thumb" src="../<?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                    <span>Current image</span>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>

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
                <div class="panel-icon bg-green"><i class='bx bx-grid-alt'></i></div>
            </div>
            <div class="panel-body">
                <?php if (count($rows) === 0) { ?>
                    <div class="muted">No locations yet.</div>
                <?php } else { ?>
                    <div class="card-grid">
                        <?php foreach ($rows as $r) { ?>
                            <div class="card-item">
                                <div class="card-img">
                                    <?php if (!empty($r['image_path'])) { ?>
                                        <img src="../<?php echo htmlspecialchars((string)$r['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                    <?php } ?>
                                </div>
                                <div class="card-body">
                                    <div class="card-title"><?php echo htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="card-meta">Code: <?php echo htmlspecialchars((string)($r['code'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="card-meta">Status: <?php echo htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($r['notes'])) { ?>
                                        <div class="card-meta"><?php echo htmlspecialchars((string)$r['notes'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php } ?>
                                    <div class="card-actions">
                                        <?php if (has_perm('location.edit')) { ?>
                                            <a class="btn" href="locations.php?action=edit&id=<?php echo (int)$r['id']; ?>">Edit</a>
                                        <?php } ?>
                                        <?php if (has_perm('location.delete')) { ?>
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Delete this location?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <button class="btn danger" type="submit">Delete</button>
                                            </form>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</section>

<script src="../JS/script.js?v=20260225"></script>
</body>
</html>
