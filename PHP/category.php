<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('category.view');

$flash = null;
$flashType = 'info';

$action = (string)($_GET['action'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$name = '';
$description = '';
$status = 'active';
$notes = '';
$parentId = '';
$tag = '';
$tagColor = '#4f46e5';
$imagePath = '';

$hasStatusColumn = false;
$hasNotesColumn = false;
$hasParentIdColumn = false;
$hasTagColumn = false;
$hasTagColorColumn = false;
$hasArchivedAtColumn = false;
$hasImagePathColumn = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'status'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasStatusColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'notes'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasNotesColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'parent_id'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasParentIdColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'tag'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasTagColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'tag_color'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasTagColorColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'archived_at'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasArchivedAtColumn = ((int)$c) > 0;
        }
        $stmtCol->close();
    }

    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'image_path'")) {
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
            $conn->query("ALTER TABLE categories ADD COLUMN image_path VARCHAR(255) NULL");
            $hasImagePathColumn = true;
        } catch (Throwable $e) {
            $hasImagePathColumn = false;
        }
    }
}

if ($action === 'edit') {
    require_perm('category.edit');
}

if ($action === 'edit' && $id > 0 && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $selectCols = "name, description";
    if ($hasStatusColumn) {
        $selectCols .= ", status";
    }
    if ($hasNotesColumn) {
        $selectCols .= ", notes";
    }
    if ($hasParentIdColumn) {
        $selectCols .= ", parent_id";
    }
    if ($hasTagColumn) {
        $selectCols .= ", tag";
    }
    if ($hasTagColorColumn) {
        $selectCols .= ", tag_color";
    }
    if ($hasImagePathColumn) {
        $selectCols .= ", image_path";
    }
    $stmt = $conn->prepare("SELECT $selectCols FROM categories WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $name = (string)($row['name'] ?? '');
            $description = (string)($row['description'] ?? '');
            if ($hasStatusColumn) {
                $status = (string)($row['status'] ?? 'active');
            }
            if ($hasNotesColumn) {
                $notes = (string)($row['notes'] ?? '');
            }
            if ($hasParentIdColumn) {
                $parentId = $row['parent_id'] === null ? '' : (string)$row['parent_id'];
            }
            if ($hasTagColumn) {
                $tag = (string)($row['tag'] ?? '');
            }
            if ($hasTagColorColumn) {
                $tagColor = (string)($row['tag_color'] ?? '#4f46e5');
            }
            if ($hasImagePathColumn) {
                $imagePath = (string)($row['image_path'] ?? '');
            }
        }
        $stmt->close();
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
            require_perm('category.edit');
        } else {
            require_perm('category.create');
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($hasStatusColumn) {
            $status = (string)($_POST['status'] ?? 'active');
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }
        }
        if ($hasNotesColumn) {
            $notes = trim((string)($_POST['notes'] ?? ''));
        }
        $parentVal = null;
        if ($hasParentIdColumn) {
            $parentId = trim((string)($_POST['parent_id'] ?? ''));
            $parentVal = $parentId === '' ? null : (int)$parentId;
            if ($editId > 0 && $parentVal !== null && $parentVal === $editId) {
                $parentVal = null;
                $parentId = '';
            }
        }
        if ($hasTagColumn) {
            $tag = trim((string)($_POST['tag'] ?? ''));
            if ($tag === '') { $tag = ''; }
        }
        if ($hasTagColorColumn) {
            $tagColor = trim((string)($_POST['tag_color'] ?? '#4f46e5'));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $tagColor)) {
                $tagColor = '#4f46e5';
            }
        }

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
                } elseif ($size > 2 * 1024 * 1024) {
                    $flash = 'Image must be 2MB or less.';
                    $flashType = 'error';
                } else {
                    $uploadDir = dirname(__DIR__) . '/assets/uploads/categories';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }
                    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($origName, PATHINFO_FILENAME));
                    $fileName = 'cat_' . ($safeName !== '' ? $safeName . '_' : '') . uniqid('', true) . '.' . $ext;
                    $dest = $uploadDir . '/' . $fileName;
                    if (@move_uploaded_file($tmpName, $dest)) {
                        $imagePath = 'assets/uploads/categories/' . $fileName;
                    } else {
                        $flash = 'Failed to save uploaded image.';
                        $flashType = 'error';
                    }
                }
            }
        }

        if ($hasParentIdColumn && $hasStatusColumn && $parentVal !== null) {
            $stmtParent = $conn->prepare("SELECT status FROM categories WHERE id = ? LIMIT 1");
            if ($stmtParent) {
                $stmtParent->bind_param('i', $parentVal);
                $stmtParent->execute();
                $resParent = $stmtParent->get_result();
                if ($resParent && ($p = $resParent->fetch_assoc())) {
                    $ps = (string)($p['status'] ?? 'active');
                    if (in_array($ps, ['active', 'inactive'], true)) {
                        $status = $ps;
                    }
                }
                $stmtParent->close();
            }
        }

        if ($name === '') {
            $flash = 'Category name is required.';
            $flashType = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id <> ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $name, $editId);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $flash = 'Category name already exists.';
                    $flashType = 'error';
                } else {
                    if ($editId > 0) {
                        $setParts = "name = ?, description = ?";
                        if ($hasStatusColumn) {
                            $setParts .= ", status = ?";
                        }
                        if ($hasNotesColumn) {
                            $setParts .= ", notes = ?";
                        }
                        if ($hasParentIdColumn) {
                            $setParts .= ", parent_id = ?";
                        }
                        if ($hasTagColumn) {
                            $setParts .= ", tag = ?";
                        }
                        if ($hasTagColorColumn) {
                            $setParts .= ", tag_color = ?";
                        }
                        if ($hasImagePathColumn) {
                            $setParts .= ", image_path = ?";
                        }
                        $stmt2 = $conn->prepare("UPDATE categories SET $setParts WHERE id = ?");
                        if ($stmt2) {
                            $bindTypes = '';
                            $bindParams = [];
                            $bindTypes .= 'ss';
                            $bindParams[] = $name;
                            $bindParams[] = $description;
                            if ($hasStatusColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $status;
                            }
                            if ($hasNotesColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $notes;
                            }
                            if ($hasParentIdColumn) {
                                $bindTypes .= 'i';
                                $bindParams[] = $parentVal === null ? 0 : (int)$parentVal;
                            }
                            if ($hasTagColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $tag;
                            }
                            if ($hasTagColorColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $tagColor;
                            }
                            if ($hasImagePathColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $imagePath === '' ? null : $imagePath;
                            }
                            $bindTypes .= 'i';
                            $bindParams[] = $editId;

                            if ($hasParentIdColumn && $parentVal === null) {
                                $sqlNull = str_replace('parent_id = ?', 'parent_id = NULL', "UPDATE categories SET $setParts WHERE id = ?");
                                $sqlNull = str_replace(', parent_id = NULL', ', parent_id = NULL', $sqlNull);
                                $stmt2->close();
                                $stmt2 = $conn->prepare($sqlNull);
                                if ($stmt2) {
                                    $bindTypes2 = $bindTypes;
                                    $bindParams2 = $bindParams;
                                    $idx = 0;
                                    $newTypes = '';
                                    $newParams = [];
                                    for ($i = 0; $i < strlen($bindTypes2); $i++) {
                                        $t = $bindTypes2[$i];
                                        $val = $bindParams2[$idx];
                                        $idx++;
                                        if ($t === 'i' && $hasParentIdColumn && $i > 1) {
                                            continue;
                                        }
                                        $newTypes .= $t;
                                        $newParams[] = $val;
                                    }
                                    $stmt2->bind_param($newTypes, ...$newParams);
                                    $ok = $stmt2->execute();
                                    $stmt2->close();
                                    if ($ok) {
                                        audit_log($conn, 'category.edit', 'Edited category_id=' . (string)$editId, $editId);
                                        header('Location: category.php?msg=updated');
                                        exit();
                                    }
                                }
                                $flash = 'Failed to update category.';
                                $flashType = 'error';
                            } else {
                                $stmt2->bind_param($bindTypes, ...$bindParams);
                                $ok = $stmt2->execute();
                                $stmt2->close();
                                if ($ok) {
                                    audit_log($conn, 'category.edit', 'Edited category_id=' . (string)$editId, $editId);
                                    header('Location: category.php?msg=updated');
                                    exit();
                                }
                            }
                        }
                        $flash = 'Failed to update category.';
                        $flashType = 'error';
                    } else {
                        $cols = "name, description";
                        $vals = "?, ?";
                        if ($hasStatusColumn) {
                            $cols .= ", status";
                            $vals .= ", ?";
                        }
                        if ($hasNotesColumn) {
                            $cols .= ", notes";
                            $vals .= ", ?";
                        }
                        if ($hasParentIdColumn) {
                            $cols .= ", parent_id";
                            $vals .= ", ?";
                        }
                        if ($hasTagColumn) {
                            $cols .= ", tag";
                            $vals .= ", ?";
                        }
                        if ($hasTagColorColumn) {
                            $cols .= ", tag_color";
                            $vals .= ", ?";
                        }
                        if ($hasImagePathColumn) {
                            $cols .= ", image_path";
                            $vals .= ", ?";
                        }
                        $stmt2 = $conn->prepare("INSERT INTO categories ($cols) VALUES ($vals)");
                        if ($stmt2) {
                            $bindTypes = 'ss';
                            $bindParams = [$name, $description];
                            if ($hasStatusColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $status;
                            }
                            if ($hasNotesColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $notes;
                            }
                            if ($hasParentIdColumn) {
                                $bindTypes .= 'i';
                                $bindParams[] = $parentVal === null ? 0 : (int)$parentVal;
                            }
                            if ($hasTagColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $tag;
                            }
                            if ($hasTagColorColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $tagColor;
                            }

                            if ($hasImagePathColumn) {
                                $bindTypes .= 's';
                                $bindParams[] = $imagePath === '' ? null : $imagePath;
                            }

                            if ($hasParentIdColumn && $parentVal === null) {
                                $stmt2->close();
                                $sqlNull = str_replace('parent_id', 'parent_id', "INSERT INTO categories ($cols) VALUES ($vals)");
                                $sqlNull = str_replace(', parent_id', ', parent_id', $sqlNull);
                                $sqlNull = str_replace(', ?', ', NULL', $sqlNull);
                                $stmt2 = $conn->prepare($sqlNull);
                                if ($stmt2) {
                                    $newTypes = '';
                                    $newParams = [];
                                    for ($i = 0, $j = 0; $i < strlen($bindTypes); $i++, $j++) {
                                        if ($hasParentIdColumn && $bindTypes[$i] === 'i') {
                                            continue;
                                        }
                                        $newTypes .= $bindTypes[$i];
                                        $newParams[] = $bindParams[$j];
                                    }
                                    $stmt2->bind_param($newTypes, ...$newParams);
                                    $ok = $stmt2->execute();
                                    $newId = (int)$conn->insert_id;
                                    $stmt2->close();
                                    if ($ok) {
                                        audit_log($conn, 'category.create', 'Created category_id=' . (string)$newId . ';name=' . $name, $newId);
                                        header('Location: category.php?msg=created');
                                        exit();
                                    }
                                }
                                $flash = 'Failed to create category.';
                                $flashType = 'error';
                            } else {
                                $stmt2->bind_param($bindTypes, ...$bindParams);
                                $ok = $stmt2->execute();
                                $newId = (int)$conn->insert_id;
                                $stmt2->close();
                                if ($ok) {
                                    audit_log($conn, 'category.create', 'Created category_id=' . (string)$newId . ';name=' . $name, $newId);
                                    header('Location: category.php?msg=created');
                                    exit();
                                }
                            }
                        }
                        $flash = 'Failed to create category.';
                        $flashType = 'error';
                    }
                }
            } else {
                $flash = 'Failed to validate category.';
                $flashType = 'error';
            }
        }
    }

    if ($postAction === 'delete') {
        require_perm('category.delete');
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM products WHERE category_id = ?");
            $cnt = 0;
            if ($stmt) {
                $stmt->bind_param('i', $deleteId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $cnt = (int)($row['c'] ?? 0);
                }
                $stmt->close();
            }

            if ($cnt > 0) {
                $flash = 'Cannot delete: category is used by products.';
                $flashType = 'error';
            } else {
                $stmt2 = $conn->prepare("DELETE FROM categories WHERE id = ?");
                if ($stmt2) {
                    $stmt2->bind_param('i', $deleteId);
                    $ok = $stmt2->execute();
                    $stmt2->close();
                    if ($ok) {
                        audit_log($conn, 'category.delete', 'Deleted category_id=' . (string)$deleteId, $deleteId);
                        header('Location: category.php?msg=deleted');
                        exit();
                    }
                }
                $flash = 'Failed to delete category.';
                $flashType = 'error';
            }
        }
    }

    if ($postAction === 'toggle_status') {
        require_perm('category.edit');
        if (!$hasStatusColumn) {
            $flash = 'Status feature is not enabled yet. Please import category_schema.sql.';
            $flashType = 'error';
        } else {
            $toggleId = (int)($_POST['id'] ?? 0);
            $newStatus = (string)($_POST['status'] ?? 'active');
            if (!in_array($newStatus, ['active', 'inactive'], true)) {
                $newStatus = 'active';
            }

            if ($toggleId > 0) {
                $stmt = $conn->prepare("UPDATE categories SET status = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $newStatus, $toggleId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        if ($hasParentIdColumn) {
                            $stmtChild = $conn->prepare("UPDATE categories SET status = ? WHERE parent_id = ?");
                            if ($stmtChild) {
                                $stmtChild->bind_param('si', $newStatus, $toggleId);
                                $stmtChild->execute();
                                $stmtChild->close();
                            }
                        }
                        audit_log($conn, $newStatus === 'active' ? 'category.activate' : 'category.deactivate', 'category_id=' . (string)$toggleId, $toggleId);
                        header('Location: category.php?msg=status');
                        exit();
                    }
                }
                $flash = 'Failed to update status.';
                $flashType = 'error';
            }
        }
    }

    if ($postAction === 'archive') {
        require_perm('category.edit');
        if (!$hasArchivedAtColumn) {
            $flash = 'Archive feature is not enabled yet. Please import category_schema_v2.sql.';
            $flashType = 'error';
        } else {
            $archiveId = (int)($_POST['id'] ?? 0);
            if ($archiveId > 0) {
                $stmt = $conn->prepare("UPDATE categories SET archived_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $archiveId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        audit_log($conn, 'category.archive', 'category_id=' . (string)$archiveId, $archiveId);
                        header('Location: category.php?msg=archived');
                        exit();
                    }
                }
                $flash = 'Failed to archive category.';
                $flashType = 'error';
            }
        }
    }

    if ($postAction === 'restore') {
        require_perm('category.edit');
        if (!$hasArchivedAtColumn) {
            $flash = 'Archive feature is not enabled yet. Please import category_schema_v2.sql.';
            $flashType = 'error';
        } else {
            $restoreId = (int)($_POST['id'] ?? 0);
            if ($restoreId > 0) {
                $stmt = $conn->prepare("UPDATE categories SET archived_at = NULL WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $restoreId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        audit_log($conn, 'category.restore', 'category_id=' . (string)$restoreId, $restoreId);
                        header('Location: category.php?msg=restored');
                        exit();
                    }
                }
                $flash = 'Failed to restore category.';
                $flashType = 'error';
            }
        }
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') { $flash = 'Category created.'; $flashType = 'success'; }
if ($msg === 'updated') { $flash = 'Category updated.'; $flashType = 'success'; }
if ($msg === 'deleted') { $flash = 'Category deleted.'; $flashType = 'success'; }
if ($msg === 'status') { $flash = 'Category status updated.'; $flashType = 'success'; }
if ($msg === 'archived') { $flash = 'Category archived.'; $flashType = 'success'; }
if ($msg === 'restored') { $flash = 'Category restored.'; $flashType = 'success'; }

$rows = [];

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');
$showArchived = (string)($_GET['show_archived'] ?? '0') === '1';
$sort = (string)($_GET['sort'] ?? 'name_asc');
$page = (int)($_GET['page'] ?? 1);
$perPage = 10;
if ($page < 1) { $page = 1; }

$sortSql = 'c.name ASC';
if ($sort === 'name_desc') { $sortSql = 'c.name DESC'; }
if ($sort === 'newest') { $sortSql = 'c.created_at DESC'; }
if ($sort === 'products_desc') { $sortSql = 'product_count DESC, c.name ASC'; }

$totalRows = 0;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $where[] = '(c.name LIKE ? OR c.description LIKE ?' . ($hasNotesColumn ? ' OR c.notes LIKE ?' : '') . ')';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
        if ($hasNotesColumn) {
            $params[] = $like;
            $types .= 's';
        }
    }

    if ($hasStatusColumn && in_array($statusFilter, ['active', 'inactive'], true)) {
        $where[] = 'c.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($hasArchivedAtColumn && !$showArchived) {
        $where[] = 'c.archived_at IS NULL';
    }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $selectColsList = 'c.id, c.name, c.description, c.created_at';
    if ($hasStatusColumn) {
        $selectColsList .= ', c.status';
    } else {
        $selectColsList .= ", 'active' AS status";
    }
    if ($hasNotesColumn) {
        $selectColsList .= ', c.notes';
    } else {
        $selectColsList .= ', NULL AS notes';
    }
    if ($hasParentIdColumn) {
        $selectColsList .= ', c.parent_id';
    } else {
        $selectColsList .= ', NULL AS parent_id';
    }
    if ($hasParentIdColumn) {
        $selectColsList .= ', pc.name AS parent_name';
    } else {
        $selectColsList .= ', NULL AS parent_name';
    }
    if ($hasTagColumn) {
        $selectColsList .= ', c.tag';
    } else {
        $selectColsList .= ', NULL AS tag';
    }
    if ($hasTagColorColumn) {
        $selectColsList .= ', c.tag_color';
    } else {
        $selectColsList .= ', NULL AS tag_color';
    }
    if ($hasImagePathColumn) {
        $selectColsList .= ', c.image_path';
    } else {
        $selectColsList .= ', NULL AS image_path';
    }
    if ($hasArchivedAtColumn) {
        $selectColsList .= ', c.archived_at';
    } else {
        $selectColsList .= ', NULL AS archived_at';
    }

    $sqlCount = "SELECT COUNT(*) AS c FROM categories c $whereSql";
    $stmt = $conn->prepare($sqlCount);
    if ($stmt) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $totalRows = (int)($row['c'] ?? 0);
        }
        $stmt->close();
    }

    $totalPages = (int)max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;

    $fromSql = 'FROM categories c';
    if ($hasParentIdColumn) {
        $fromSql .= ' LEFT JOIN categories pc ON pc.id = c.parent_id';
    }
    $fromSql .= ' LEFT JOIN products p ON p.category_id = c.id';

    $sql = "SELECT $selectColsList, COUNT(p.id) AS product_count
            $fromSql
            $whereSql
            GROUP BY c.id
            ORDER BY $sortSql
            LIMIT ? OFFSET ?";
    $stmt2 = $conn->prepare($sql);
    if ($stmt2) {
        $params2 = $params;
        $types2 = $types . 'ii';
        $params2[] = $perPage;
        $params2[] = $offset;
        $stmt2->bind_param($types2, ...$params2);
        $stmt2->execute();
        $res = $stmt2->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        $stmt2->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Category'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Category</div>
            <div class="page-subtitle">Manage your product categories</div>
        </div>
        <div class="page-meta">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><?php echo $action === 'edit' ? 'Edit Category' : 'Add Category'; ?></div>
                <div class="panel-icon bg-blue"><i class='bx bxs-category-alt'></i></div>
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
                        <label class="label">Description</label>
                        <input class="input" type="text" name="description" value="<?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <?php if ($hasParentIdColumn) { ?>
                        <?php
                            $parentOptions = [];
                            if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
                                $sqlParents = $hasArchivedAtColumn ? "SELECT id, name FROM categories WHERE (archived_at IS NULL) ORDER BY name ASC" : "SELECT id, name FROM categories ORDER BY name ASC";
                                if ($resP = $conn->query($sqlParents)) {
                                    while ($pr = $resP->fetch_assoc()) {
                                        if ($action === 'edit' && (int)$id === (int)($pr['id'] ?? 0)) { continue; }
                                        $parentOptions[] = $pr;
                                    }
                                    $resP->free();
                                }
                            }
                        ?>
                        <div class="form-row">
                            <label class="label">Parent Category</label>
                            <select class="input" name="parent_id">
                                <option value="" <?php echo $parentId === '' ? 'selected' : ''; ?>>None</option>
                                <?php foreach ($parentOptions as $po) { ?>
                                    <option value="<?php echo (int)($po['id'] ?? 0); ?>" <?php echo (string)($po['id'] ?? '') === (string)$parentId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($po['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    <?php } ?>

                    <?php if ($hasTagColumn) { ?>
                        <div class="form-row">
                            <label class="label">Tag</label>
                            <input class="input" type="text" name="tag" value="<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Optional">
                        </div>
                    <?php } ?>

                    <?php if ($hasTagColorColumn) { ?>
                        <div class="form-row">
                            <label class="label">Tag Color</label>
                            <input class="input" type="color" name="tag_color" value="<?php echo htmlspecialchars($tagColor, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    <?php } ?>

                    <?php if ($hasStatusColumn) { ?>
                        <div class="form-row">
                            <label class="label">Status</label>
                            <select class="input" name="status">
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    <?php } ?>

                    <?php if ($hasNotesColumn) { ?>
                        <div class="form-row">
                            <label class="label">Notes</label>
                            <input class="input" type="text" name="notes" value="<?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    <?php } ?>

                    <?php if ($hasImagePathColumn) { ?>
                        <div class="form-row">
                            <label class="label">Image</label>
                            <input class="input" type="file" name="image" accept="image/png,image/jpeg,image/webp">
                            <?php if ($imagePath !== '') { ?>
                                <div class="muted" style="margin-top: 8px; display: flex; align-items: center; gap: 10px;">
                                    <?php
                                        $__img = (string)$imagePath;
                                        $__v = @filemtime(dirname(__DIR__) . '/' . $__img);
                                        $__src = '../' . $__img . ($__v ? ('?v=' . (string)$__v) : '');
                                    ?>
                                    <img class="thumb" src="<?php echo htmlspecialchars($__src, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                    <span>Current image</span>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>

                    <div class="form-actions">
                        <?php if ($action === 'edit') { ?>
                            <?php if (has_perm('category.edit')) { ?>
                                <button class="btn primary" type="submit">Update</button>
                            <?php } ?>
                            <a class="btn" href="category.php">Cancel</a>
                        <?php } else { ?>
                            <?php if (has_perm('category.create')) { ?>
                                <button class="btn primary" type="submit">Create</button>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Category List</div>
                <div class="panel-icon bg-green"><i class='bx bx-list-ul'></i></div>
            </div>
            <div class="panel-body">
                <form method="get" class="toolbar" style="grid-template-columns: 1fr 180px 200px auto;">
                    <input class="input" type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search categories...">
                    <select class="input" name="status" <?php echo $hasStatusColumn ? '' : 'disabled'; ?>>
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <select class="input" name="sort">
                        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name (A–Z)</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z–A)</option>
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                        <option value="products_desc" <?php echo $sort === 'products_desc' ? 'selected' : ''; ?>>Most products</option>
                    </select>
                    <?php if ($hasArchivedAtColumn) { ?>
                        <input type="hidden" name="show_archived" value="<?php echo $showArchived ? '1' : '0'; ?>">
                    <?php } ?>
                    <button class="btn" type="submit">Filter</button>
                </form>

                <?php if ($hasArchivedAtColumn) { ?>
                    <div class="toolbar" style="grid-template-columns: 1fr auto; align-items: center; margin-top: 10px;">
                        <div class="muted">Archived categories are hidden by default.</div>
                        <?php
                            $toggleParams = ['q' => $q, 'status' => $statusFilter, 'sort' => $sort, 'page' => $page, 'show_archived' => $showArchived ? '0' : '1'];
                        ?>
                        <a class="btn" href="category.php?<?php echo htmlspecialchars(http_build_query($toggleParams), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $showArchived ? 'Hide Archived' : 'Show Archived'; ?></a>
                    </div>
                <?php } ?>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <?php if ($hasImagePathColumn) { ?><th>Image</th><?php } ?>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Parent</th>
                            <th>Tag</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th>Products</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($rows) === 0) { ?>
                            <tr><td colspan="<?php echo $hasImagePathColumn ? '10' : '9'; ?>" class="muted">No categories found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($rows as $r) { ?>
                                <tr>
                                    <?php if ($hasImagePathColumn) { ?>
                                        <td>
                                            <?php if (!empty($r['image_path'])) { ?>
                                                <?php
                                                    $__img = (string)($r['image_path'] ?? '');
                                                    $__v = @filemtime(dirname(__DIR__) . '/' . $__img);
                                                    $__src = '../' . $__img . ($__v ? ('?v=' . (string)$__v) : '');
                                                ?>
                                                <img class="thumb" src="<?php echo htmlspecialchars($__src, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                            <?php } else { ?>
                                                <span class="muted">—</span>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                    <td>
                                        <?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($hasArchivedAtColumn && !empty($r['archived_at'])) { ?>
                                            <span class="muted">(archived)</span>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($r['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                            $pn = (string)($r['parent_name'] ?? '');
                                            echo $pn !== '' ? htmlspecialchars($pn, ENT_QUOTES, 'UTF-8') : '—';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($r['tag'])) { ?>
                                            <span class="meta-pill" style="background: <?php echo htmlspecialchars((string)($r['tag_color'] ?? '#4f46e5'), ENT_QUOTES, 'UTF-8'); ?>; border: 0; color: #fff;"><?php echo htmlspecialchars((string)$r['tag'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php } else { ?>
                                            <span class="muted">—</span>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($r['product_count'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <?php if (has_perm('category.edit')) { ?>
                                                <a class="btn" href="category.php?action=edit&id=<?php echo (int)$r['id']; ?>">Edit</a>
                                            <?php } ?>
                                            <?php if ($hasArchivedAtColumn && has_perm('category.edit')) { ?>
                                                <?php if (!empty($r['archived_at'])) { ?>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="action" value="restore">
                                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button class="btn" type="submit">Restore</button>
                                                    </form>
                                                <?php } else { ?>
                                                    <form method="post" class="inline" onsubmit="return confirm('Archive this category?');">
                                                        <input type="hidden" name="action" value="archive">
                                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button class="btn" type="submit">Archive</button>
                                                    </form>
                                                <?php } ?>
                                            <?php } ?>
                                            <?php if ($hasStatusColumn && has_perm('category.edit')) { ?>
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <input type="hidden" name="status" value="<?php echo (string)($r['status'] ?? 'active') === 'active' ? 'inactive' : 'active'; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button class="btn" type="submit"><?php echo (string)($r['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                                </form>
                                            <?php } ?>
                                            <?php if (has_perm('category.delete')) { ?>
                                                <form method="post" class="inline" onsubmit="return confirm('Delete this category?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button class="btn danger" type="submit" <?php echo (int)($r['product_count'] ?? 0) > 0 ? 'disabled' : ''; ?>>Delete</button>
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

                <?php if ($totalRows > 0) { ?>
                    <?php
                        $totalPages = (int)max(1, (int)ceil($totalRows / $perPage));
                        $baseParams = ['q' => $q, 'status' => $statusFilter, 'sort' => $sort];
                        if ($hasArchivedAtColumn) {
                            $baseParams['show_archived'] = $showArchived ? '1' : '0';
                        }
                    ?>
                    <div class="toolbar" style="grid-template-columns: 1fr auto auto; align-items: center;">
                        <div class="muted">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?> — <?php echo (int)$totalRows; ?> total</div>
                        <div>
                            <?php if ($page > 1) { $baseParams['page'] = $page - 1; ?>
                                <a class="btn" href="category.php?<?php echo htmlspecialchars(http_build_query($baseParams), ENT_QUOTES, 'UTF-8'); ?>">Prev</a>
                            <?php } else { ?>
                                <span class="btn" style="opacity: .5; pointer-events: none;">Prev</span>
                            <?php } ?>
                            <?php if ($page < $totalPages) { $baseParams['page'] = $page + 1; ?>
                                <a class="btn" href="category.php?<?php echo htmlspecialchars(http_build_query($baseParams), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                            <?php } else { ?>
                                <span class="btn" style="opacity: .5; pointer-events: none;">Next</span>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</section>

<script src="../JS/script.js?v=20260225"></script>
</body>
</html>
