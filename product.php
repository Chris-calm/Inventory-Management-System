<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('product.view');

$flash = null;
$flashType = 'info';

$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$action = (string)($_GET['action'] ?? 'add');
$id = (int)($_GET['id'] ?? 0);

if ($action === 'edit') {
    require_perm('product.edit');
}

if ($action === 'edit' && $id > 0 && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $selectCols = 'sku, name, category_id, unit_cost, unit_price, reorder_level, status';
    if ($hasProdImagePath) {
        $selectCols .= ', image_path';
    }
    $stmt = $conn->prepare("SELECT $selectCols FROM products WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $sku = (string)($row['sku'] ?? '');
            $name = (string)($row['name'] ?? '');
            $categoryId = $row['category_id'] === null ? '' : (string)$row['category_id'];
            $unitCost = (string)($row['unit_cost'] ?? '0.00');
            $unitPrice = (string)($row['unit_price'] ?? '0.00');
            $reorderLevel = (string)($row['reorder_level'] ?? '5');
            $status = (string)($row['status'] ?? 'active');
            if ($hasProdImagePath) {
                $imagePath = (string)($row['image_path'] ?? '');
            }
        }
        $stmt->close();
    }
}

$sku = '';
$name = '';
$categoryId = '';
$unitCost = '0.00';
$unitPrice = '0.00';
$reorderLevel = '5';
$status = 'active';
$imagePath = '';

$categories = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $hasCatStatus = false;
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'status'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasCatStatus = ((int)$c) > 0;
        }
        $stmtCol->close();
    }

    $hasCatArchived = false;
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'archived_at'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasCatArchived = ((int)$c) > 0;
        }
        $stmtCol->close();
    }

    $whereParts = [];
    if ($hasCatStatus) {
        $whereParts[] = "status = 'active'";
    }
    if ($hasCatArchived) {
        $whereParts[] = "archived_at IS NULL";
    }

    $catSql = "SELECT id, name FROM categories";
    if (count($whereParts) > 0) {
        $catSql .= ' WHERE ' . implode(' AND ', $whereParts);
    }
    $catSql .= ' ORDER BY name ASC';

    if ($res = $conn->query($catSql)) {
        while ($r = $res->fetch_assoc()) {
            $categories[] = $r;
        }
        $res->free();
    }
}

$hasProdImagePath = false;
$hasProdArchivedAt = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'image_path'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasProdImagePath = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'archived_at'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasProdArchivedAt = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
}

if ($action === 'edit') {
    require_perm('product.edit');
}

if ($action === 'edit' && $id > 0 && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $selectCols = 'sku, name, category_id, unit_cost, unit_price, reorder_level, status';
    if ($hasProdImagePath) {
        $selectCols .= ', image_path';
    }
    $stmt = $conn->prepare("SELECT $selectCols FROM products WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $sku = (string)($row['sku'] ?? '');
            $name = (string)($row['name'] ?? '');
            $categoryId = $row['category_id'] === null ? '' : (string)$row['category_id'];
            $unitCost = (string)($row['unit_cost'] ?? '0.00');
            $unitPrice = (string)($row['unit_price'] ?? '0.00');
            $reorderLevel = (string)($row['reorder_level'] ?? '5');
            $status = (string)($row['status'] ?? 'active');
            if ($hasProdImagePath) {
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
            require_perm('product.edit');
        }
        
        $sku = trim((string)($_POST['sku'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $categoryId = trim((string)($_POST['category_id'] ?? ''));
        $unitCost = trim((string)($_POST['unit_cost'] ?? '0.00'));
        $unitPrice = trim((string)($_POST['unit_price'] ?? '0.00'));
        $reorderLevel = trim((string)($_POST['reorder_level'] ?? '5'));
        $status = (string)($_POST['status'] ?? 'active');

        $uploadedImagePath = null;
        if ($hasProdImagePath && isset($_FILES['image']) && is_array($_FILES['image'])) {
            $err = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_NO_FILE) {
                if ($err !== UPLOAD_ERR_OK) {
                    $flash = 'Image upload failed.';
                    $flashType = 'error';
                } else {
                    $tmp = (string)($_FILES['image']['tmp_name'] ?? '');
                    $size = (int)($_FILES['image']['size'] ?? 0);
                    $orig = (string)($_FILES['image']['name'] ?? '');
                    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $flash = 'Invalid image type. Allowed: JPG, PNG, WEBP.';
                        $flashType = 'error';
                    } elseif ($size <= 0 || $size > 5 * 1024 * 1024) {
                        $flash = 'Image must be under 5MB.';
                        $flashType = 'error';
                    } else {
                        $dir = __DIR__ . '/uploads/products';
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0755, true);
                        }
                        $filename = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $dest = $dir . '/' . $filename;
                        if (!@move_uploaded_file($tmp, $dest)) {
                            $flash = 'Failed to save uploaded image.';
                            $flashType = 'error';
                        } else {
                            $uploadedImagePath = 'uploads/products/' . $filename;
                        }
                    }
                }
            }
        }

        $catVal = $categoryId === '' ? null : (int)$categoryId;
        $costVal = is_numeric($unitCost) ? (float)$unitCost : 0.0;
        $priceVal = is_numeric($unitPrice) ? (float)$unitPrice : 0.0;
        $reorderVal = is_numeric($reorderLevel) ? (int)$reorderLevel : 5;

        if ($flashType === 'error') {
            // keep existing flash
        } elseif ($sku === '' || $name === '') {
            $flash = 'SKU and product name are required.';
            $flashType = 'error';
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $flash = 'Invalid status.';
            $flashType = 'error';
        } elseif ($reorderVal < 0) {
            $flash = 'Reorder level must be 0 or greater.';
            $flashType = 'error';
        } else {
            $isStaff = ($_SESSION['role'] ?? '') !== 'admin';
            
            if ($editId > 0) {
                // Editing existing product from products table
                require_perm('product.edit');
                $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $sku, $editId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $exists = $res && $res->fetch_assoc();
                    $stmt->close();

                    if ($exists) {
                        $flash = 'SKU already exists.';
                        $flashType = 'error';
                    } else {
                        if ($catVal === null) {
                            $setParts = "sku = ?, name = ?, category_id = NULL, unit_cost = ?, unit_price = ?, reorder_level = ?, status = ?";
                            if ($hasProdImagePath && $uploadedImagePath !== null) {
                                $setParts .= ", image_path = ?";
                            }
                            $stmt2 = $conn->prepare("UPDATE products SET $setParts WHERE id = ?");
                            if ($stmt2) {
                                if ($hasProdImagePath && $uploadedImagePath !== null) {
                                    $stmt2->bind_param('ssddissi', $sku, $name, $costVal, $priceVal, $reorderVal, $status, $uploadedImagePath, $editId);
                                } else {
                                    $stmt2->bind_param('ssddisi', $sku, $name, $costVal, $priceVal, $reorderVal, $status, $editId);
                                }
                                $ok = $stmt2->execute();
                                $stmt2->close();
                                if ($ok) { header('Location: product.php?msg=updated'); exit(); }
                            }
                        } else {
                            $setParts = "sku = ?, name = ?, category_id = ?, unit_cost = ?, unit_price = ?, reorder_level = ?, status = ?";
                            if ($hasProdImagePath && $uploadedImagePath !== null) {
                                $setParts .= ", image_path = ?";
                            }
                            $stmt2 = $conn->prepare("UPDATE products SET $setParts WHERE id = ?");
                            if ($stmt2) {
                                if ($hasProdImagePath && $uploadedImagePath !== null) {
                                    $stmt2->bind_param('ssiddissi', $sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $status, $uploadedImagePath, $editId);
                                } else {
                                    $stmt2->bind_param('ssiddisi', $sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $status, $editId);
                                }
                                $ok = $stmt2->execute();
                                $stmt2->close();
                                if ($ok) { header('Location: product.php?msg=updated'); exit(); }
                            }
                        }
                        $flash = 'Failed to update product.';
                        $flashType = 'error';
                    }
                } else {
                    $flash = 'Failed to validate SKU.';
                    $flashType = 'error';
                }
            } else {
                // Creating new product
                $stmt = $conn->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $sku);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $exists = $res && $res->fetch_assoc();
                    $stmt->close();

                    if ($exists) {
                        $flash = 'SKU already exists.';
                        $flashType = 'error';
                    } else {
                        if ($isStaff) {
                            // Staff: insert into pending_products for approval
                            $hasPendingTable = false;
                            if ($checkRes = $conn->query("SHOW TABLES LIKE 'pending_products'")) {
                                $hasPendingTable = (bool)$checkRes->fetch_assoc();
                                $checkRes->free();
                            }
                            
                            if (!$hasPendingTable) {
                                $flash = 'Approval system not ready. Please create pending_products table.';
                                $flashType = 'error';
                            } else {
                                $pendingCols = "sku, name, category_id, unit_cost, unit_price, reorder_level, status, requested_by";
                                $pendingVals = "?, ?, ?, ?, ?, ?, ?, ?";
                                $pendingTypes = 'ssiddisi';
                                $pendingParams = [$sku, $name, $catVal, $costVal, $priceVal, $reorderVal, 'pending', (int)($_SESSION['user_id'] ?? 0)];
                                if ($hasProdImagePath && $uploadedImagePath !== null) {
                                    $pendingCols .= ", image_path";
                                    $pendingVals .= ", ?";
                                    $pendingTypes .= 's';
                                    $pendingParams[] = $uploadedImagePath;
                                }
                                $stmt2 = $conn->prepare("INSERT INTO pending_products ($pendingCols) VALUES ($pendingVals)");
                                if ($stmt2) {
                                    $stmt2->bind_param($pendingTypes, ...$pendingParams);
                                    $ok = $stmt2->execute();
                                    $stmt2->close();
                                    if ($ok) { 
                                        $flash = 'Product submitted for approval.';
                                        $flashType = 'success';
                                    } else {
                                        $flash = 'Failed to submit product for approval.';
                                        $flashType = 'error';
                                    }
                                } else {
                                    $flash = 'Failed to submit product for approval.';
                                    $flashType = 'error';
                                }
                            }
                        } else {
                            // Admin: insert directly into products
                            if ($catVal === null) {
                                $cols = "sku, name, category_id, unit_cost, unit_price, stock_qty, reorder_level, status";
                                $vals = "?, ?, NULL, ?, ?, 0, ?, ?";
                                $bindTypes = 'ssddis';
                                $bindParams = [$sku, $name, $costVal, $priceVal, $reorderVal, $status];
                                if ($hasProdImagePath && $uploadedImagePath !== null) {
                                    $cols .= ", image_path";
                                    $vals .= ", ?";
                                    $bindTypes .= 's';
                                    $bindParams[] = $uploadedImagePath;
                                }
                                $stmt2 = $conn->prepare("INSERT INTO products ($cols) VALUES ($vals)");
                                if ($stmt2) {
                                    $stmt2->bind_param($bindTypes, ...$bindParams);
                                    $ok = $stmt2->execute();
                                    $stmt2->close();
                                    if ($ok) { header('Location: product.php?msg=created'); exit(); }
                                }
                            } else {
                                $cols = "sku, name, category_id, unit_cost, unit_price, stock_qty, reorder_level, status";
                                $vals = "?, ?, ?, ?, ?, 0, ?, ?";
                                $bindTypes = 'ssiddis';
                                $bindParams = [$sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $status];
                                if ($hasProdImagePath && $uploadedImagePath !== null) {
                                    $cols .= ", image_path";
                                    $vals .= ", ?";
                                    $bindTypes .= 's';
                                    $bindParams[] = $uploadedImagePath;
                                }
                                $stmt2 = $conn->prepare("INSERT INTO products ($cols) VALUES ($vals)");
                                if ($stmt2) {
                                    $stmt2->bind_param($bindTypes, ...$bindParams);
                                    $ok = $stmt2->execute();
                                    $stmt2->close();
                                    if ($ok) { header('Location: product.php?msg=created'); exit(); }
                                }
                            }
                            $flash = 'Failed to create product.';
                            $flashType = 'error';
                        }
                    }
                } else {
                    $flash = 'Failed to validate SKU.';
                    $flashType = 'error';
                }
            }
        }
    }

    if ($postAction === 'delete') {
        require_perm('product.delete');
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $deleteId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) { header('Location: product.php?msg=deleted'); exit(); }
            }
            $flash = 'Failed to delete product.';
            $flashType = 'error';
        }
    }

    if ($postAction === 'archive') {
        require_perm('product.edit');
        if (!$hasProdArchivedAt) {
            $flash = 'Archive feature is not enabled yet. Please import product_schema_v2.sql.';
            $flashType = 'error';
        } else {
            $archiveId = (int)($_POST['id'] ?? 0);
            if ($archiveId > 0) {
                $stmt = $conn->prepare("UPDATE products SET archived_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $archiveId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) { header('Location: product.php?msg=archived'); exit(); }
                }
                $flash = 'Failed to archive product.';
                $flashType = 'error';
            }
        }
    }

    if ($postAction === 'restore') {
        require_perm('product.edit');
        if (!$hasProdArchivedAt) {
            $flash = 'Archive feature is not enabled yet. Please import product_schema_v2.sql.';
            $flashType = 'error';
        } else {
            $restoreId = (int)($_POST['id'] ?? 0);
            if ($restoreId > 0) {
                $stmt = $conn->prepare("UPDATE products SET archived_at = NULL WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $restoreId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) { header('Location: product.php?msg=restored'); exit(); }
                }
                $flash = 'Failed to restore product.';
                $flashType = 'error';
            }
        }
    }

    if ($postAction === 'approve_product') {
        require_perm('product.edit');
        $pendingId = (int)($_POST['pending_id'] ?? 0);
        if ($pendingId > 0) {
            $stmt = $conn->prepare("SELECT * FROM pending_products WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $pendingId);
            $stmt->execute();
            $res = $stmt->get_result();
            $pending = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            
            if ($pending) {
                $cols = "sku, name, category_id, unit_cost, unit_price, stock_qty, reorder_level, status";
                $vals = "?, ?, ?, ?, ?, 0, ?, ?";
                $types = 'ssiddis';
                $params = [
                    $pending['sku'],
                    $pending['name'],
                    $pending['category_id'],
                    $pending['unit_cost'],
                    $pending['unit_price'],
                    $pending['reorder_level'],
                    'active'
                ];
                if ($hasProdImagePath && !empty($pending['image_path'])) {
                    $cols .= ", image_path";
                    $vals .= ", ?";
                    $types .= 's';
                    $params[] = $pending['image_path'];
                }
                $stmt2 = $conn->prepare("INSERT INTO products ($cols) VALUES ($vals)");
                if ($stmt2) {
                    $stmt2->bind_param($types, ...$params);
                    $ok = $stmt2->execute();
                    $newId = $conn->insert_id;
                    $stmt2->close();
                    if ($ok && $newId > 0) {
                        $stmt3 = $conn->prepare("DELETE FROM pending_products WHERE id = ?");
                        $stmt3->bind_param('i', $pendingId);
                        $stmt3->execute();
                        $stmt3->close();
                        header('Location: product.php?msg=approved'); exit();
                    }
                }
            }
            $flash = 'Failed to approve product.';
            $flashType = 'error';
        }
    }

    if ($postAction === 'reject_product') {
        require_perm('product.edit');
        $pendingId = (int)($_POST['pending_id'] ?? 0);
        $rejectionNotes = trim((string)($_POST['rejection_notes'] ?? ''));
        if ($pendingId > 0) {
            $stmt = $conn->prepare("UPDATE pending_products SET status = 'rejected', rejection_notes = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $rejectionNotes, $pendingId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) { header('Location: product.php?msg=rejected'); exit(); }
            }
            $flash = 'Failed to reject product.';
            $flashType = 'error';
        }
    }

    if ($postAction === 'remove_pending') {
        // Staff can remove their own pending products
        $pendingId = (int)($_POST['pending_id'] ?? 0);
        if ($pendingId > 0) {
            $stmt = $conn->prepare("DELETE FROM pending_products WHERE id = ? AND requested_by = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $pendingId, $userId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($ok && $affected > 0) { 
                    header('Location: product.php?msg=removed'); 
                    exit(); 
                }
            }
            $flash = 'Failed to remove pending product.';
            $flashType = 'error';
        }
    }

    if ($postAction === 'remove_rejected') {
        // Staff can remove their own rejected products
        $pendingId = (int)($_POST['pending_id'] ?? 0);
        if ($pendingId > 0) {
            $stmt = $conn->prepare("DELETE FROM pending_products WHERE id = ? AND requested_by = ? AND status = 'rejected'");
            if ($stmt) {
                $stmt->bind_param('ii', $pendingId, $userId);
                $ok = $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($ok && $affected > 0) { 
                    header('Location: product.php?msg=removed'); 
                    exit(); 
                }
            }
            $flash = 'Failed to remove rejected product.';
            $flashType = 'error';
        }
    }

    if ($postAction === 'resubmit_pending') {
        // Staff can edit and resubmit rejected products via modal
        $pendingId = (int)($_POST['pending_id'] ?? 0);
        if ($pendingId > 0) {
            $sku = trim((string)($_POST['sku'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $categoryId = trim((string)($_POST['category_id'] ?? ''));
            $unitCost = trim((string)($_POST['unit_cost'] ?? '0.00'));
            $unitPrice = trim((string)($_POST['unit_price'] ?? '0.00'));
            $reorderLevel = trim((string)($_POST['reorder_level'] ?? '5'));

            $catVal = $categoryId === '' ? null : (int)$categoryId;
            $costVal = is_numeric($unitCost) ? (float)$unitCost : 0.0;
            $priceVal = is_numeric($unitPrice) ? (float)$unitPrice : 0.0;
            $reorderVal = is_numeric($reorderLevel) ? (int)$reorderLevel : 5;

            if ($sku === '' || $name === '') {
                $flash = 'SKU and product name are required.';
                $flashType = 'error';
            } else {
                // Handle image upload for resubmit
                $uploadedImagePath = null;
                if ($hasProdImagePath && isset($_FILES['resubmit_image']) && is_array($_FILES['resubmit_image'])) {
                    $err = (int)($_FILES['resubmit_image']['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($err === UPLOAD_ERR_OK) {
                        $tmp = (string)($_FILES['resubmit_image']['tmp_name'] ?? '');
                        $size = (int)($_FILES['resubmit_image']['size'] ?? 0);
                        $orig = (string)($_FILES['resubmit_image']['name'] ?? '');
                        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                        if (in_array($ext, $allowed, true) && $size > 0 && $size <= 5 * 1024 * 1024) {
                            $dir = __DIR__ . '/uploads/products';
                            if (!is_dir($dir)) {
                                @mkdir($dir, 0755, true);
                            }
                            $filename = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
                            $dest = $dir . '/' . $filename;
                            if (@move_uploaded_file($tmp, $dest)) {
                                $uploadedImagePath = 'uploads/products/' . $filename;
                            }
                        }
                    }
                }

                if ($catVal === null) {
                    $setParts = "sku = ?, name = ?, category_id = NULL, unit_cost = ?, unit_price = ?, reorder_level = ?, status = 'pending', rejection_notes = NULL";
                    if ($uploadedImagePath !== null) {
                        $setParts .= ", image_path = ?";
                        $stmt = $conn->prepare("UPDATE pending_products SET $setParts WHERE id = ? AND requested_by = ?");
                        if ($stmt) {
                            $stmt->bind_param('ssddisii', $sku, $name, $costVal, $priceVal, $reorderVal, $uploadedImagePath, $pendingId, $userId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if ($ok) { header('Location: product.php?msg=resubmitted'); exit(); }
                        }
                    } else {
                        $stmt = $conn->prepare("UPDATE pending_products SET $setParts WHERE id = ? AND requested_by = ?");
                        if ($stmt) {
                            $stmt->bind_param('ssddiii', $sku, $name, $costVal, $priceVal, $reorderVal, $pendingId, $userId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if ($ok) { header('Location: product.php?msg=resubmitted'); exit(); }
                        }
                    }
                } else {
                    $setParts = "sku = ?, name = ?, category_id = ?, unit_cost = ?, unit_price = ?, reorder_level = ?, status = 'pending', rejection_notes = NULL";
                    if ($uploadedImagePath !== null) {
                        $setParts .= ", image_path = ?";
                        $stmt = $conn->prepare("UPDATE pending_products SET $setParts WHERE id = ? AND requested_by = ?");
                        if ($stmt) {
                            $stmt->bind_param('ssiddisii', $sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $uploadedImagePath, $pendingId, $userId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if ($ok) { header('Location: product.php?msg=resubmitted'); exit(); }
                        }
                    } else {
                        $stmt = $conn->prepare("UPDATE pending_products SET $setParts WHERE id = ? AND requested_by = ?");
                        if ($stmt) {
                            $stmt->bind_param('ssiddiii', $sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $pendingId, $userId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if ($ok) { header('Location: product.php?msg=resubmitted'); exit(); }
                        }
                    }
                }
                $flash = 'Failed to resubmit product.';
                $flashType = 'error';
            }
        }
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') { $flash = 'Product created.'; $flashType = 'success'; }
if ($msg === 'updated') { $flash = 'Product updated.'; $flashType = 'success'; }
if ($msg === 'deleted') { $flash = 'Product deleted.'; $flashType = 'success'; }
if ($msg === 'archived') { $flash = 'Product archived.'; $flashType = 'success'; }
if ($msg === 'restored') { $flash = 'Product restored.'; $flashType = 'success'; }
if ($msg === 'approved') { $flash = 'Product approved and added to inventory.'; $flashType = 'success'; }
if ($msg === 'rejected') { $flash = 'Product request rejected.'; $flashType = 'success'; }
if ($msg === 'resubmitted') { $flash = 'Product resubmitted for approval.'; $flashType = 'success'; }
if ($msg === 'removed') { $flash = 'Pending product removed.'; $flashType = 'success'; }

$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? '');
$showArchived = (string)($_GET['show_archived'] ?? '0') === '1';

$rows = [];
$pendingProducts = [];
$rejectedProducts = [];
$myPendingProducts = [];

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    // Check if pending_products table exists
    $hasPendingProducts = false;
    if ($res = $conn->query("SHOW TABLES LIKE 'pending_products'")) {
        $hasPendingProducts = (bool)$res->fetch_assoc();
        $res->free();
    }
    
    // Fetch pending products for admin (only status = 'pending')
    if ($isAdmin && $hasPendingProducts) {
        $pendingSql = "SELECT pp.*, COALESCE(c.name, '—') AS category_name, u.username AS requested_by_name 
                       FROM pending_products pp 
                       LEFT JOIN categories c ON c.id = pp.category_id 
                       LEFT JOIN users u ON u.id = pp.requested_by 
                       WHERE pp.status = 'pending'
                       ORDER BY pp.created_at DESC";
        if ($res = $conn->query($pendingSql)) {
            while ($r = $res->fetch_assoc()) { $pendingProducts[] = $r; }
            $res->free();
        }
    }
    
    // Fetch staff's own pending submissions (status = 'pending')
    if (!$isAdmin && $hasPendingProducts && $userId > 0) {
        $myPendingSql = "SELECT pp.*, COALESCE(c.name, '—') AS category_name 
                        FROM pending_products pp 
                        LEFT JOIN categories c ON c.id = pp.category_id 
                        WHERE pp.status = 'pending' AND pp.requested_by = ?
                        ORDER BY pp.created_at DESC";
        $stmt = $conn->prepare($myPendingSql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $myPendingProducts[] = $r; }
            $stmt->close();
        }
    }
    
    // Fetch rejected products for staff
    if (!$isAdmin && $hasPendingProducts && $userId > 0) {
        $rejectedSql = "SELECT pp.*, COALESCE(c.name, '—') AS category_name 
                        FROM pending_products pp 
                        LEFT JOIN categories c ON c.id = pp.category_id 
                        WHERE pp.status = 'rejected' AND pp.requested_by = ?
                        ORDER BY pp.updated_at DESC";
        $stmt = $conn->prepare($rejectedSql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $rejectedProducts[] = $r; }
            $stmt->close();
        }
    }
    
    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $where[] = "(p.sku LIKE CONCAT('%', ?, '%') OR p.name LIKE CONCAT('%', ?, '%'))";
        $params[] = $q;
        $params[] = $q;
        $types .= 'ss';
    }

    if ($filter === 'low') {
        $where[] = "p.status = 'active' AND p.stock_qty <= p.reorder_level";
    } elseif ($filter === 'out') {
        $where[] = "p.status = 'active' AND p.stock_qty <= 0";
    } elseif ($filter === 'active') {
        $where[] = "p.status = 'active'";
    } elseif ($filter === 'inactive') {
        $where[] = "p.status = 'inactive'";
    }

    if ($hasProdArchivedAt && !$showArchived) {
        $where[] = 'p.archived_at IS NULL';
    }

    $selectCols = "p.id, p.sku, p.name, COALESCE(c.name, '—') AS category_name, p.stock_qty, p.reorder_level, p.status, p.created_at";
    if ($hasProdArchivedAt) {
        $selectCols .= ', p.archived_at';
    } else {
        $selectCols .= ', NULL AS archived_at';
    }
    if ($hasProdImagePath) {
        $selectCols .= ', p.image_path';
    } else {
        $selectCols .= ', NULL AS image_path';
    }
    $sql = "SELECT $selectCols FROM products p LEFT JOIN categories c ON c.id = p.category_id";
    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.created_at DESC, p.id DESC';

    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            }
            $stmt->close();
        }
    } else {
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            $res->free();
        }
    }
}

// Prepare categories JSON for JavaScript
$categoriesJson = json_encode($categories);
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
    <title>Product</title>
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.55);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        body:not(.dark) .modal-overlay,
        html:not(.dark) .modal-overlay {
            background: rgba(0, 0, 0, 0.25);
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: var(--surface);
            color: var(--text-color);
            padding: 24px;
            border-radius: 16px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: var(--text-color);
        }
        .modal-close {
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
            transition: color 0.2s;
        }
        .modal-close:hover {
            color: var(--text-color);
        }
        .rejection-notice {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .rejection-notice strong {
            color: #ef4444;
            display: block;
            margin-bottom: 4px;
        }
        .rejection-notice p {
            margin: 0;
            color: var(--text-color);
            font-size: 0.9rem;
        }

        .modal-content textarea.input {
            resize: none;
            height: 110px;
            max-height: 110px;
            overflow: auto;
        }
    </style>
</head>
<body>
<nav class="sidebar close">
    <header>
        <div class="image-text">
            <span class="image"><img src="CUBE3.png" alt="logo"></span>
            <div class="text header"><span class="name">CUBE</span> <span class="proffesion">Company</span></div>
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
                <?php if (has_perm('movement.view') || has_perm('location.view')) { ?>
                    <li class="nav-dropdown">
                        <a href="#" class="dropdown-toggle">
                            <i class='bx bx-transfer-alt icon'></i>
                            <span class="text nav-text">Stock</span>
                            <i class='bx bx-chevron-down dd-icon'></i>
                        </a>
                        <ul class="submenu">
                            <?php if (has_perm('movement.view')) { ?>
                                <li class="nav-link"><a href="transactions.php"><span class="text nav-text">Stock In/Out</span></a></li>
                            <?php } ?>
                            <?php if (has_perm('location.view')) { ?>
                                <li class="nav-link"><a href="locations.php"><span class="text nav-text">Locations</span></a></li>
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
            <div class="page-title">Product</div>
            <div class="page-subtitle">Manage your inventory products</div>
        </div>
        <div class="page-meta">
            <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><?php echo $action === 'edit' ? 'Edit Product' : 'Add Product'; ?></div>
                <div class="panel-icon bg-blue"><i class='bx bxl-product-hunt'></i></div>
            </div>
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <form method="post" class="form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)($action === 'edit' ? $id : 0); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <label class="label">SKU</label>
                        <input class="input" type="text" name="sku" value="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>

                    <?php if ($hasProdImagePath) { ?>
                        <div class="form-row">
                            <label class="label">Image</label>
                            <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                            <?php if ($action === 'edit' && $imagePath !== '') { ?>
                                <div class="muted" style="margin-top: 6px;">Current: <?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <div class="form-row">
                        <label class="label">Name</label>
                        <input class="input" type="text" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-row">
                        <label class="label">Category</label>
                        <select class="input" name="category_id">
                            <option value="">— None —</option>
                            <?php foreach ($categories as $c) { $cid = (string)$c['id']; ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo $categoryId === $cid ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-row two">
                        <div>
                            <label class="label">Unit Cost</label>
                            <input class="input" type="number" step="0.01" name="unit_cost" value="<?php echo htmlspecialchars($unitCost, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div>
                            <label class="label">Unit Price</label>
                            <input class="input" type="number" step="0.01" name="unit_price" value="<?php echo htmlspecialchars($unitPrice, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <div class="form-row two">
                        <div>
                            <label class="label">Reorder Level</label>
                            <input class="input" type="number" min="0" name="reorder_level" value="<?php echo htmlspecialchars($reorderLevel, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div>
                            <label class="label">Status</label>
                            <select class="input" name="status">
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <?php if ($action === 'edit') { ?>
                            <?php if (has_perm('product.edit')) { ?>
                                <button class="btn primary" type="submit">Update</button>
                            <?php } ?>
                            <a class="btn" href="product.php">Cancel</a>
                        <?php } else { ?>
                            <button class="btn primary" type="submit">Create</button>
                        <?php } ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Product List</div>
                <div class="panel-icon bg-green"><i class='bx bx-list-ul'></i></div>
            </div>
            <div class="panel-body">
                <form method="get" class="toolbar">
                    <input class="input" type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search SKU or name">
                    <select class="input" name="filter">
                        <option value="" <?php echo $filter === '' ? 'selected' : ''; ?>>All</option>
                        <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="low" <?php echo $filter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="out" <?php echo $filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                    <?php if ($hasProdArchivedAt) { ?>
                        <input type="hidden" name="show_archived" value="<?php echo $showArchived ? '1' : '0'; ?>">
                    <?php } ?>
                    <button class="btn" type="submit">Apply</button>
                    <a class="btn" href="product.php">Reset</a>
                </form>

                <?php if ($hasProdArchivedAt) { ?>
                    <div class="toolbar" style="grid-template-columns: 1fr auto; align-items: center; margin-top: 10px;">
                        <div class="muted">Archived products are hidden by default.</div>
                        <?php
                            $toggleParams = ['q' => $q, 'filter' => $filter, 'show_archived' => $showArchived ? '0' : '1'];
                        ?>
                        <a class="btn" href="product.php?<?php echo htmlspecialchars(http_build_query($toggleParams), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $showArchived ? 'Hide Archived' : 'Show Archived'; ?></a>
                    </div>
                <?php } ?>

                <div class="table-wrap" style="height: 450px; overflow-y: auto;">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Image</th>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Reorder</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($rows) === 0) { ?>
                            <tr><td colspan="9" class="muted">No products found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($rows as $r) { ?>
                                <tr>
                                    <td>
                                        <?php if ($hasProdImagePath && !empty($r['image_path'])) { ?>
                                            <img src="<?php echo htmlspecialchars((string)$r['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                                        <?php } else { ?>
                                            <span class="muted">—</span>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$r['sku'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($hasProdArchivedAt && !empty($r['archived_at'])) { ?>
                                            <span class="muted">(archived)</span>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$r['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php
                                            $stockQty = (int)($r['stock_qty'] ?? 0);
                                            $reorderLvl = (int)($r['reorder_level'] ?? 0);
                                            echo (int)$stockQty;
                                            if ($stockQty <= 0) {
                                                echo ' <span class="muted">(out)</span>';
                                            } elseif ($stockQty <= $reorderLvl) {
                                                echo ' <span class="muted">(low)</span>';
                                            }
                                        ?>
                                    </td>
                                    <td><?php echo (int)($r['reorder_level'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <a class="btn" href="product_view.php?id=<?php echo (int)$r['id']; ?>">View</a>
                                            <?php
                                                $restockParams = ['product_id' => (int)$r['id'], 'movement_type' => 'in'];
                                            ?>
                                            <a class="btn" href="transactions.php?<?php echo htmlspecialchars(http_build_query($restockParams), ENT_QUOTES, 'UTF-8'); ?>">Restock</a>
                                            <?php if (has_perm('product.edit')) { ?>
                                                <a class="btn" href="product.php?action=edit&id=<?php echo (int)$r['id']; ?>">Edit</a>
                                            <?php } ?>
                                            <?php if ($hasProdArchivedAt && has_perm('product.edit')) { ?>
                                                <?php if (!empty($r['archived_at'])) { ?>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="action" value="restore">
                                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button class="btn" type="submit">Restore</button>
                                                    </form>
                                                <?php } else { ?>
                                                    <form method="post" class="inline" onsubmit="return confirm('Archive this product?');">
                                                        <input type="hidden" name="action" value="archive">
                                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button class="btn" type="submit">Archive</button>
                                                    </form>
                                                <?php } ?>
                                            <?php } ?>
                                            <?php if (has_perm('product.delete')) { ?>
                                                <button class="btn danger" onclick='showDeleteModal(<?php echo (int)$r["id"]; ?>, "<?php echo htmlspecialchars((string)$r["sku"], ENT_QUOTES, 'UTF-8'); ?>", "<?php echo htmlspecialchars((string)$r["name"], ENT_QUOTES, 'UTF-8'); ?>")'>Delete</button>
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

        <?php if ($isAdmin) { ?>
        <div class="panel" style="grid-column: 1 / -1;">
            <div class="panel-header">
                <div class="panel-title">Pending Approvals (<?php echo count($pendingProducts); ?>)</div>
                <div class="panel-icon bg-orange"><i class='bx bx-time-five'></i></div>
            </div>
            <div class="panel-body">
                <?php if (count($pendingProducts) === 0) { ?>
                    <div class="muted" style="padding: 20px;">No products waiting for approval.</div>
                <?php } else { ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Image</th>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Cost</th>
                            <th>Price</th>
                            <th>Reorder</th>
                            <th>Requested By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pendingProducts as $pp) { ?>
                            <tr>
                                <td>
                                    <?php if ($hasProdImagePath && !empty($pp['image_path'])) { ?>
                                        <img src="<?php echo htmlspecialchars((string)$pp['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                                    <?php } else { ?>
                                        <span class="muted">—</span>
                                    <?php } ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)$pp['sku'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$pp['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$pp['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float)$pp['unit_cost'], 2); ?></td>
                                <td><?php echo number_format((float)$pp['unit_price'], 2); ?></td>
                                <td><?php echo (int)$pp['reorder_level']; ?></td>
                                <td><?php echo htmlspecialchars((string)$pp['requested_by_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$pp['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <div class="row-actions">
                                        <form method="post" class="inline" onsubmit="return confirm('Approve this product?');">
                                            <input type="hidden" name="action" value="approve_product">
                                            <input type="hidden" name="pending_id" value="<?php echo (int)$pp['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <button class="btn primary" type="submit">Approve</button>
                                        </form>
                                        <button class="btn danger" onclick="showRejectModal(<?php echo (int)$pp['id']; ?>, '<?php echo htmlspecialchars((string)$pp['name'], ENT_QUOTES, 'UTF-8'); ?>')">Reject</button>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <?php if (!$isAdmin) { ?>
        <div class="panel" style="grid-column: 1 / -1;">
            <div class="panel-header">
                <div class="panel-title">My Pending Submissions (<?php echo count($myPendingProducts); ?>)</div>
                <div class="panel-icon bg-orange"><i class='bx bx-time-five'></i></div>
            </div>
            <div class="panel-body">
                <?php if (count($myPendingProducts) === 0) { ?>
                    <div class="muted" style="padding: 20px;">No products submitted for approval.</div>
                <?php } else { ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Image</th>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Cost</th>
                            <th>Price</th>
                            <th>Submitted Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($myPendingProducts as $mp) { ?>
                            <tr>
                                <td>
                                    <?php if ($hasProdImagePath && !empty($mp['image_path'])) { ?>
                                        <img src="<?php echo htmlspecialchars((string)$mp['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                                    <?php } else { ?>
                                        <span class="muted">—</span>
                                    <?php } ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)$mp['sku'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$mp['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$mp['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float)$mp['unit_cost'], 2); ?></td>
                                <td><?php echo number_format((float)$mp['unit_price'], 2); ?></td>
                                <td><?php echo htmlspecialchars((string)$mp['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="muted">Awaiting Approval</span></td>
                                <td>
                                    <div class="row-actions">
                                        <form method="post" class="inline" onsubmit="return confirm('Remove this pending product?');">
                                            <input type="hidden" name="action" value="remove_pending">
                                            <input type="hidden" name="pending_id" value="<?php echo (int)$mp['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <button class="btn danger" type="submit">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <?php if (!$isAdmin) { ?>
        <div class="panel" style="grid-column: 1 / -1;">
            <div class="panel-header">
                <div class="panel-title">Rejected Products (<?php echo count($rejectedProducts); ?>)</div>
                <div class="panel-icon bg-red"><i class='bx bx-x-circle'></i></div>
            </div>
            <div class="panel-body">
                <?php if (count($rejectedProducts) === 0) { ?>
                    <div class="muted" style="padding: 20px;">No rejected products.</div>
                <?php } else { ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Image</th>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Cost</th>
                            <th>Price</th>
                            <th>Rejection Reason</th>
                            <th>Rejected Date</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rejectedProducts as $rp) { ?>
                            <tr>
                                <td>
                                    <?php if ($hasProdImagePath && !empty($rp['image_path'])) { ?>
                                        <img src="<?php echo htmlspecialchars((string)$rp['image_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                                    <?php } else { ?>
                                        <span class="muted">—</span>
                                    <?php } ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)$rp['sku'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$rp['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)$rp['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float)$rp['unit_cost'], 2); ?></td>
                                <td><?php echo number_format((float)$rp['unit_price'], 2); ?></td>
                                <td><?php echo nl2br(htmlspecialchars((string)($rp['rejection_notes'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></td>
                                <td><?php echo htmlspecialchars((string)($rp['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <div class="row-actions">
                                        <button class="btn primary" onclick='showEditResubmitModal(<?php echo json_encode([
                                            "id" => (int)$rp["id"],
                                            "sku" => (string)$rp["sku"],
                                            "name" => (string)$rp["name"],
                                            "category_id" => $rp["category_id"],
                                            "unit_cost" => (string)$rp["unit_cost"],
                                            "unit_price" => (string)$rp["unit_price"],
                                            "reorder_level" => (string)$rp["reorder_level"],
                                            "image_path" => (string)($rp["image_path"] ?? ""),
                                            "rejection_notes" => (string)($rp["rejection_notes"] ?? "")
                                        ]); ?>)'>Edit & Resubmit</button>
                                        <form method="post" class="inline" onsubmit="return confirm('Permanently remove this rejected product?');">
                                            <input type="hidden" name="action" value="remove_rejected">
                                            <input type="hidden" name="pending_id" value="<?php echo (int)$rp['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                            <button class="btn danger" type="submit">Remove</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
    </div>
</section>

<!-- Reject Modal (Admin) -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reject Product</h3>
            <button class="modal-close" onclick="hideRejectModal()">&times;</button>
        </div>
        <p style="margin: 0 0 16px 0; color: var(--text-muted, #666);">Product: <span id="rejectProductName"></span></p>
        <form method="post" id="rejectForm">
            <input type="hidden" name="action" value="reject_product">
            <input type="hidden" name="pending_id" id="rejectPendingId" value="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-row">
                <label class="label">Rejection Reason / Notes</label>
                <textarea class="input" name="rejection_notes" rows="4" placeholder="Enter reason for rejection..." required></textarea>
            </div>
            <div class="form-actions" style="margin-top: 16px;">
                <button class="btn danger" type="submit">Reject Product</button>
                <button class="btn" type="button" onclick="hideRejectModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit & Resubmit Modal (Staff) -->
<div id="editResubmitModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit & Resubmit Product</h3>
            <button class="modal-close" onclick="hideEditResubmitModal()">&times;</button>
        </div>
        
        <div id="rejectionNotice" class="rejection-notice">
            <strong>Rejection Reason:</strong>
            <p id="rejectionNoticeText"></p>
        </div>
        
        <form method="post" id="editResubmitForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="resubmit_pending">
            <input type="hidden" name="pending_id" id="resubmitPendingId">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            
            <div class="form-row">
                <label class="label">SKU</label>
                <input class="input" type="text" name="sku" id="resubmitSku" required>
            </div>
            
            <div class="form-row">
                <label class="label">Name</label>
                <input class="input" type="text" name="name" id="resubmitName" required>
            </div>
            
            <div class="form-row">
                <label class="label">Category</label>
                <select class="input" name="category_id" id="resubmitCategoryId">
                    <option value="">— None —</option>
                    <?php foreach ($categories as $c) { ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php } ?>
                </select>
            </div>
            
            <div class="form-row two">
                <div>
                    <label class="label">Unit Cost</label>
                    <input class="input" type="number" step="0.01" name="unit_cost" id="resubmitUnitCost">
                </div>
                <div>
                    <label class="label">Unit Price</label>
                    <input class="input" type="number" step="0.01" name="unit_price" id="resubmitUnitPrice">
                </div>
            </div>
            
            <div class="form-row">
                <label class="label">Reorder Level</label>
                <input class="input" type="number" min="0" name="reorder_level" id="resubmitReorderLevel">
            </div>
            
            <?php if ($hasProdImagePath) { ?>
            <div class="form-row">
                <label class="label">Image (optional)</label>
                <input class="input" type="file" name="resubmit_image" accept="image/jpeg,image/png,image/webp">
                <div id="currentImageInfo" class="muted" style="margin-top: 6px;"></div>
            </div>
            <?php } ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <button class="btn primary" type="submit">Resubmit for Approval</button>
                <button class="btn" type="button" onclick="hideEditResubmitModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Reject Modal Functions (Admin)
function showRejectModal(pendingId, productName) {
    document.getElementById('rejectPendingId').value = pendingId;
    document.getElementById('rejectProductName').textContent = productName;
    document.getElementById('rejectModal').classList.add('active');
}

function hideRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
    document.querySelector('#rejectForm textarea[name="rejection_notes"]').value = '';
}

// Edit & Resubmit Modal Functions (Staff)
function showEditResubmitModal(data) {
    document.getElementById('resubmitPendingId').value = data.id;
    document.getElementById('resubmitSku').value = data.sku;
    document.getElementById('resubmitName').value = data.name;
    document.getElementById('resubmitCategoryId').value = data.category_id || '';
    document.getElementById('resubmitUnitCost').value = data.unit_cost;
    document.getElementById('resubmitUnitPrice').value = data.unit_price;
    document.getElementById('resubmitReorderLevel').value = data.reorder_level;
    
    // Show rejection notes
    var rejectionNotes = data.rejection_notes || 'No reason provided';
    document.getElementById('rejectionNoticeText').textContent = rejectionNotes;
    
    // Show current image info if available
    var imageInfo = document.getElementById('currentImageInfo');
    if (imageInfo) {
        if (data.image_path) {
            imageInfo.textContent = 'Current: ' + data.image_path;
        } else {
            imageInfo.textContent = '';
        }
    }
    
    document.getElementById('editResubmitModal').classList.add('active');
}

function hideEditResubmitModal() {
    document.getElementById('editResubmitModal').classList.remove('active');
}

// Close modals when clicking outside
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) hideRejectModal();
});

document.getElementById('editResubmitModal').addEventListener('click', function(e) {
    if (e.target === this) hideEditResubmitModal();
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) hideDeleteModal();
});

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideRejectModal();
        hideEditResubmitModal();
        hideDeleteModal();
    }
});

// Delete Modal Functions
function showDeleteModal(id, sku, name) {
    document.getElementById('deleteProductId').value = id;
    document.getElementById('deleteProductInfo').textContent = sku + ' - ' + name;
    document.getElementById('deleteModal').classList.add('active');
}

function hideDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

function confirmDelete() {
    document.getElementById('deleteForm').submit();
}
</script>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delete Product</h3>
            <button class="modal-close" onclick="hideDeleteModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="alert" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; margin-bottom: 20px;">
                <strong>Warning:</strong> This action cannot be undone. Deleting this product will also delete all its stock movements.
            </div>
            
            <p>Are you sure you want to delete this product?</p>
            <p><strong id="deleteProductInfo" style="color: var(--text-color);"></strong></p>
        </div>
        
        <div class="modal-footer" style="margin-top: 20px; text-align: right;">
            <form id="deleteForm" method="post" style="display: inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" id="deleteProductId" name="id" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            </form>
            
            <button class="btn" onclick="hideDeleteModal()" style="margin-right: 10px;">Cancel</button>
            <button class="btn danger" onclick="confirmDelete()">Delete</button>
        </div>
    </div>
</div>

<script src="script.js?v=20260225"></script>
</body>
</html>
