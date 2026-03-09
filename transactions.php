<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('movement.view');

$flash = null;
$flashType = 'info';

$hasApprovalStatus = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'approval_status'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasApprovalStatus = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
}

$hasTransferFields = false;
$hasLocationStocks = false;
$locations = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $ok1 = false;
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'source_type'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $ok1 = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    $ok2 = false;
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'dest_type'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $ok2 = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    $hasTransferFields = $ok1 && $ok2;

    if ($res = $conn->query("SHOW TABLES LIKE 'location_stocks'")) {
        $hasLocationStocks = (bool)$res->fetch_assoc();
        $res->free();
    }

    if ($res = $conn->query("SHOW TABLES LIKE 'locations'")) {
        $hasLocationsTable = (bool)$res->fetch_assoc();
        $res->free();
        if ($hasLocationsTable) {
            if ($res2 = $conn->query("SELECT id, name FROM locations WHERE status = 'active' ORDER BY name ASC")) {
                while ($r = $res2->fetch_assoc()) { $locations[] = $r; }
                $res2->free();
            }
        }
    }
}

$products = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($res = $conn->query("SELECT id, sku, name, stock_qty, reorder_level FROM products WHERE status = 'active' ORDER BY name ASC")) {
        while ($r = $res->fetch_assoc()) { $products[] = $r; }
        $res->free();
    }
}

$productId = (int)($_POST['product_id'] ?? 0);
if ($productId <= 0) {
    $productId = (int)($_GET['product_id'] ?? 0);
}

$movementType = (string)($_POST['movement_type'] ?? '');
if ($movementType === '') {
    $movementType = (string)($_GET['movement_type'] ?? 'in');
}

$qty = (int)($_POST['qty'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));

$sourceType = (string)($_POST['source_type'] ?? 'location');
$sourceLocationId = (int)($_POST['source_location_id'] ?? 0);
$sourceName = trim((string)($_POST['source_name'] ?? ''));
$destType = (string)($_POST['dest_type'] ?? 'location');
$destLocationId = (int)($_POST['dest_location_id'] ?? 0);
$destName = trim((string)($_POST['dest_name'] ?? ''));

$movementLocationId = (int)($_POST['movement_location_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }
    $postAction = (string)($_POST['action'] ?? 'create');

    if ($postAction === 'approve' || $postAction === 'reject') {
        require_perm('movement.approve');
        if (!$hasApprovalStatus) {
            $flash = 'Approval workflow is not enabled yet. Please import stock_schema_v2.sql.';
            $flashType = 'error';
        } else {
            $moveId = (int)($_POST['move_id'] ?? 0);
            if ($moveId <= 0) {
                $flash = 'Invalid movement.';
                $flashType = 'error';
            } else {
                $conn->begin_transaction();
                try {
                    $selectCols = "sm.id, sm.product_id, sm.movement_type, sm.qty, sm.note, sm.approval_status";
                    if ($hasTransferFields) {
                        $selectCols .= ", sm.source_type, sm.source_location_id, sm.source_name, sm.dest_type, sm.dest_location_id, sm.dest_name";
                    } else {
                        $selectCols .= ", NULL AS source_type, NULL AS source_location_id, NULL AS source_name, NULL AS dest_type, NULL AS dest_location_id, NULL AS dest_name";
                    }
                    $stmt = $conn->prepare("SELECT $selectCols FROM stock_movements sm WHERE sm.id = ? FOR UPDATE");
                    if (!$stmt) {
                        throw new Exception('Failed to load movement.');
                    }
                    $stmt->bind_param('i', $moveId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $mv = $res ? $res->fetch_assoc() : null;
                    $stmt->close();

                    if (!$mv) {
                        throw new Exception('Movement not found.');
                    }
                    if ((string)($mv['approval_status'] ?? '') !== 'pending') {
                        throw new Exception('Movement is not pending.');
                    }

                    if ($postAction === 'reject') {
                        $reason = trim((string)($_POST['rejected_reason'] ?? ''));
                        if ($reason === '') {
                            $reason = 'Rejected';
                        }
                        $approvedBy = (int)($_SESSION['user_id'] ?? 0);
                        $stmt2 = $conn->prepare("UPDATE stock_movements SET approval_status = 'rejected', approved_by = ?, approved_at = NOW(), rejected_reason = ? WHERE id = ?");
                        if (!$stmt2) {
                            throw new Exception('Failed to update movement.');
                        }
                        $stmt2->bind_param('isi', $approvedBy, $reason, $moveId);
                        if (!$stmt2->execute()) {
                            $stmt2->close();
                            throw new Exception('Failed to reject movement.');
                        }
                        $stmt2->close();

                        $conn->commit();
                        audit_log($conn, 'movement.reject', 'movement_id=' . (string)$moveId, null);
                        header('Location: transactions.php?msg=rejected');
                        exit();
                    }

                    $productId2 = (int)($mv['product_id'] ?? 0);
                    $movementType2 = (string)($mv['movement_type'] ?? '');
                    $qty2 = (int)($mv['qty'] ?? 0);

                    if ($movementType2 === 'transfer') {
                        if (!$hasTransferFields || !$hasLocationStocks) {
                            throw new Exception('Transfer workflow is not enabled yet. Please import locations_schema.sql and stock_schema_v3.sql.');
                        }

                        $srcType = (string)($mv['source_type'] ?? '');
                        $srcLocId = (int)($mv['source_location_id'] ?? 0);
                        $dstType = (string)($mv['dest_type'] ?? '');
                        $dstLocId = (int)($mv['dest_location_id'] ?? 0);

                        if (!in_array($srcType, ['location', 'supplier'], true)) {
                            throw new Exception('Invalid transfer source.');
                        }
                        if (!in_array($dstType, ['location', 'customer'], true)) {
                            throw new Exception('Invalid transfer destination.');
                        }
                        if ($srcType === 'location' && $srcLocId <= 0) {
                            throw new Exception('Source location is required.');
                        }
                        if ($dstType === 'location' && $dstLocId <= 0) {
                            throw new Exception('Destination location is required.');
                        }
                        if ($srcType === 'location' && $dstType === 'location' && $srcLocId === $dstLocId) {
                            throw new Exception('Source and destination locations must be different.');
                        }

                        if ($srcType === 'location') {
                            $stmt = $conn->prepare("SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE");
                            if (!$stmt) {
                                throw new Exception('Failed to load location stock.');
                            }
                            $stmt->bind_param('ii', $srcLocId, $productId2);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $row = $res ? $res->fetch_assoc() : null;
                            $stmt->close();
                            $cur = (int)($row['qty'] ?? 0);
                            if ($cur < $qty2) {
                                throw new Exception('Not enough stock in source location.');
                            }
                            $newQty = $cur - $qty2;
                            $stmt2 = $conn->prepare("INSERT INTO location_stocks (location_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
                            if (!$stmt2) {
                                throw new Exception('Failed to update source location stock.');
                            }
                            $stmt2->bind_param('iii', $srcLocId, $productId2, $newQty);
                            if (!$stmt2->execute()) {
                                $stmt2->close();
                                throw new Exception('Failed to update source location stock.');
                            }
                            $stmt2->close();
                        }

                        if ($dstType === 'location') {
                            $stmt = $conn->prepare("SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE");
                            if (!$stmt) {
                                throw new Exception('Failed to load location stock.');
                            }
                            $stmt->bind_param('ii', $dstLocId, $productId2);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $row = $res ? $res->fetch_assoc() : null;
                            $stmt->close();
                            $cur = (int)($row['qty'] ?? 0);
                            $newQty = $cur + $qty2;
                            $stmt2 = $conn->prepare("INSERT INTO location_stocks (location_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
                            if (!$stmt2) {
                                throw new Exception('Failed to update destination location stock.');
                            }
                            $stmt2->bind_param('iii', $dstLocId, $productId2, $newQty);
                            if (!$stmt2->execute()) {
                                $stmt2->close();
                                throw new Exception('Failed to update destination location stock.');
                            }
                            $stmt2->close();
                        }

                        $stmt = $conn->prepare("SELECT stock_qty FROM products WHERE id = ? FOR UPDATE");
                        if (!$stmt) {
                            throw new Exception('Failed to load product.');
                        }
                        $stmt->bind_param('i', $productId2);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        $stmt->close();
                        if (!$row) {
                            throw new Exception('Product not found.');
                        }
                        $curTotal = (int)($row['stock_qty'] ?? 0);
                        $newTotal = $curTotal;
                        if ($srcType === 'supplier' && $dstType === 'location') {
                            $newTotal = $curTotal + $qty2;
                        } elseif ($srcType === 'location' && $dstType === 'customer') {
                            if ($curTotal < $qty2) {
                                throw new Exception('Not enough stock for transfer out.');
                            }
                            $newTotal = $curTotal - $qty2;
                        }
                        if ($newTotal !== $curTotal) {
                            $stmt2 = $conn->prepare("UPDATE products SET stock_qty = ? WHERE id = ?");
                            if (!$stmt2) {
                                throw new Exception('Failed to update stock.');
                            }
                            $stmt2->bind_param('ii', $newTotal, $productId2);
                            if (!$stmt2->execute()) {
                                $stmt2->close();
                                throw new Exception('Failed to update stock.');
                            }
                            $stmt2->close();
                        }

                        $approvedBy = (int)($_SESSION['user_id'] ?? 0);
                        $stmt3 = $conn->prepare("UPDATE stock_movements SET approval_status = 'approved', approved_by = ?, approved_at = NOW(), rejected_reason = NULL WHERE id = ?");
                        if (!$stmt3) {
                            throw new Exception('Failed to update movement.');
                        }
                        $stmt3->bind_param('ii', $approvedBy, $moveId);
                        if (!$stmt3->execute()) {
                            $stmt3->close();
                            throw new Exception('Failed to approve movement.');
                        }
                        $stmt3->close();

                        $conn->commit();
                        audit_log($conn, 'movement.approve', 'movement_id=' . (string)$moveId, null);
                        header('Location: transactions.php?msg=approved');
                        exit();
                    }

                    if (($movementType2 === 'in' || $movementType2 === 'out') && $hasTransferFields && $hasLocationStocks) {
                        $locId = 0;
                        if ($movementType2 === 'in') {
                            $locId = (int)($mv['dest_location_id'] ?? 0);
                        } else {
                            $locId = (int)($mv['source_location_id'] ?? 0);
                        }

                        if ($locId > 0) {
                            $stmt = $conn->prepare("SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE");
                            if (!$stmt) {
                                throw new Exception('Failed to load location stock.');
                            }
                            $stmt->bind_param('ii', $locId, $productId2);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $row = $res ? $res->fetch_assoc() : null;
                            $stmt->close();

                            $cur = (int)($row['qty'] ?? 0);
                            if ($movementType2 === 'out' && $cur < $qty2) {
                                throw new Exception('Not enough stock in source location.');
                            }
                            $newQty = $cur + ($movementType2 === 'in' ? $qty2 : -$qty2);
                            $stmt2 = $conn->prepare("INSERT INTO location_stocks (location_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
                            if (!$stmt2) {
                                throw new Exception('Failed to update location stock.');
                            }
                            $stmt2->bind_param('iii', $locId, $productId2, $newQty);
                            if (!$stmt2->execute()) {
                                $stmt2->close();
                                throw new Exception('Failed to update location stock.');
                            }
                            $stmt2->close();
                        }
                    }

                    $stmt = $conn->prepare("SELECT stock_qty FROM products WHERE id = ? FOR UPDATE");
                    if (!$stmt) {
                        throw new Exception('Failed to load product.');
                    }
                    $stmt->bind_param('i', $productId2);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $stmt->close();
                    if (!$row) {
                        throw new Exception('Product not found.');
                    }

                    $currentStock = (int)($row['stock_qty'] ?? 0);
                    $newStock = $currentStock;
                    if ($movementType2 === 'in') {
                        $newStock = $currentStock + $qty2;
                    } elseif ($movementType2 === 'out') {
                        if ($currentStock < $qty2) {
                            throw new Exception('Not enough stock for stock out.');
                        }
                        $newStock = $currentStock - $qty2;
                    } elseif ($movementType2 === 'adjust') {
                        $newStock = $qty2;
                    } else {
                        throw new Exception('Invalid movement type.');
                    }

                    $stmt2 = $conn->prepare("UPDATE products SET stock_qty = ? WHERE id = ?");
                    if (!$stmt2) {
                        throw new Exception('Failed to update stock.');
                    }
                    $stmt2->bind_param('ii', $newStock, $productId2);
                    if (!$stmt2->execute()) {
                        $stmt2->close();
                        throw new Exception('Failed to update stock.');
                    }
                    $stmt2->close();

                    $approvedBy = (int)($_SESSION['user_id'] ?? 0);
                    $stmt3 = $conn->prepare("UPDATE stock_movements SET approval_status = 'approved', approved_by = ?, approved_at = NOW(), rejected_reason = NULL WHERE id = ?");
                    if (!$stmt3) {
                        throw new Exception('Failed to update movement.');
                    }
                    $stmt3->bind_param('ii', $approvedBy, $moveId);
                    if (!$stmt3->execute()) {
                        $stmt3->close();
                        throw new Exception('Failed to approve movement.');
                    }
                    $stmt3->close();

                    $conn->commit();
                    audit_log($conn, 'movement.approve', 'movement_id=' . (string)$moveId, null);
                    header('Location: transactions.php?msg=approved');
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $flash = $e->getMessage();
                    $flashType = 'error';
                }
            }
        }
    } else {
        require_perm('movement.create');
        if (!in_array($movementType, ['in', 'out', 'adjust', 'transfer'], true)) {
            $flash = 'Invalid movement type.';
            $flashType = 'error';
        } elseif ($productId <= 0) {
            $flash = 'Please select a product.';
            $flashType = 'error';
        } elseif ($qty <= 0) {
            $flash = 'Quantity must be greater than 0.';
            $flashType = 'error';
        } elseif ($movementType === 'transfer' && (!$hasTransferFields || !$hasLocationStocks)) {
            $flash = 'Transfer workflow is not enabled yet. Please import locations_schema.sql and stock_schema_v3.sql.';
            $flashType = 'error';
        } else {
            $canApprove = !$hasApprovalStatus || has_perm('movement.approve');

            $conn->begin_transaction();
            try {
                $createdBy = (int)($_SESSION['user_id'] ?? 0);

                if ($movementType === 'transfer') {
                    if (!in_array($sourceType, ['location', 'supplier'], true)) {
                        throw new Exception('Invalid source type.');
                    }
                    if (!in_array($destType, ['location', 'customer'], true)) {
                        throw new Exception('Invalid destination type.');
                    }
                    if ($sourceType === 'location' && $sourceLocationId <= 0) {
                        throw new Exception('Source location is required.');
                    }
                    if ($destType === 'location' && $destLocationId <= 0) {
                        throw new Exception('Destination location is required.');
                    }
                    if ($sourceType === 'supplier' && $sourceName === '') {
                        throw new Exception('Supplier name is required.');
                    }
                    if ($destType === 'customer' && $destName === '') {
                        throw new Exception('Customer name is required.');
                    }
                    if ($sourceType === 'location' && $destType === 'location' && $sourceLocationId === $destLocationId) {
                        throw new Exception('Source and destination locations must be different.');
                    }

                    $cols = "product_id, movement_type, qty, note, created_by, source_type, dest_type";
                    $vals = "?, 'transfer', ?, ?, ?, ?, ?";
                    $bindTypes = 'iisis' . 's';
                    $bindParams = [$productId, $qty, $note, $createdBy, $sourceType, $destType];

                    if ($sourceType === 'location') {
                        $cols .= ", source_location_id";
                        $vals .= ", ?";
                        $bindTypes .= 'i';
                        $bindParams[] = $sourceLocationId;
                    } else {
                        $cols .= ", source_location_id";
                        $vals .= ", NULL";
                        $cols .= ", source_name";
                        $vals .= ", ?";
                        $bindTypes .= 's';
                        $bindParams[] = $sourceName;
                    }

                    if ($destType === 'location') {
                        $cols .= ", dest_location_id";
                        $vals .= ", ?";
                        $bindTypes .= 'i';
                        $bindParams[] = $destLocationId;
                        $cols .= ", dest_name";
                        $vals .= ", NULL";
                    } else {
                        $cols .= ", dest_location_id";
                        $vals .= ", NULL";
                        $cols .= ", dest_name";
                        $vals .= ", ?";
                        $bindTypes .= 's';
                        $bindParams[] = $destName;
                    }

                    if ($hasApprovalStatus && !$canApprove) {
                        $stmt3 = $conn->prepare("INSERT INTO stock_movements ($cols, approval_status) VALUES ($vals, 'pending')");
                        if (!$stmt3) {
                            throw new Exception('Failed to record transfer.');
                        }
                        $stmt3->bind_param($bindTypes, ...$bindParams);
                        if (!$stmt3->execute()) {
                            $stmt3->close();
                            throw new Exception('Failed to record transfer.');
                        }
                        $stmt3->close();

                        $conn->commit();
                        audit_log($conn, 'movement.create', 'product_id=' . (string)$productId . ';type=transfer;qty=' . (string)$qty . ';status=pending', null);
                        header('Location: transactions.php?msg=pending');
                        exit();
                    }

                    if ($sourceType === 'location') {
                        $stmt = $conn->prepare("SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE");
                        if (!$stmt) {
                            throw new Exception('Failed to load location stock.');
                        }
                        $stmt->bind_param('ii', $sourceLocationId, $productId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        $stmt->close();
                        $cur = (int)($row['qty'] ?? 0);
                        if ($cur < $qty) {
                            throw new Exception('Not enough stock in source location.');
                        }
                    }

                    if ($sourceType === 'supplier' && $destType === 'location') {
                        $stmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception('Failed to update stock.');
                        }
                        $stmt->bind_param('ii', $qty, $productId);
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to update stock.');
                        }
                        $stmt->close();
                    } elseif ($sourceType === 'location' && $destType === 'customer') {
                        $stmt = $conn->prepare("SELECT stock_qty FROM products WHERE id = ? FOR UPDATE");
                        if (!$stmt) {
                            throw new Exception('Failed to load product.');
                        }
                        $stmt->bind_param('i', $productId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        $stmt->close();
                        $curTotal = (int)($row['stock_qty'] ?? 0);
                        if ($curTotal < $qty) {
                            throw new Exception('Not enough stock for transfer out.');
                        }
                        $stmt = $conn->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception('Failed to update stock.');
                        }
                        $stmt->bind_param('ii', $qty, $productId);
                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new Exception('Failed to update stock.');
                        }
                        $stmt->close();
                    }

                    if ($sourceType === 'location') {
                        $stmt = $conn->prepare("SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE");
                        if (!$stmt) {
                            throw new Exception('Failed to load location stock.');
                        }
                        $stmt->bind_param('ii', $sourceLocationId, $productId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        $stmt->close();
                        $cur = (int)($row['qty'] ?? 0);
                        $newQty = $cur - $qty;
                        $stmt2 = $conn->prepare("INSERT INTO location_stocks (location_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
                        if (!$stmt2) {
                            throw new Exception('Failed to update source location stock.');
                        }
                        $stmt2->bind_param('iii', $sourceLocationId, $productId, $newQty);
                        if (!$stmt2->execute()) {
                            $stmt2->close();
                            throw new Exception('Failed to update source location stock.');
                        }
                        $stmt2->close();
                    }
                    if ($destType === 'location') {
                        $stmt = $conn->prepare("SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE");
                        if (!$stmt) {
                            throw new Exception('Failed to load location stock.');
                        }
                        $stmt->bind_param('ii', $destLocationId, $productId);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $row = $res ? $res->fetch_assoc() : null;
                        $stmt->close();
                        $cur = (int)($row['qty'] ?? 0);
                        $newQty = $cur + $qty;
                        $stmt2 = $conn->prepare("INSERT INTO location_stocks (location_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
                        if (!$stmt2) {
                            throw new Exception('Failed to update destination location stock.');
                        }
                        $stmt2->bind_param('iii', $destLocationId, $productId, $newQty);
                        if (!$stmt2->execute()) {
                            $stmt2->close();
                            throw new Exception('Failed to update destination location stock.');
                        }
                        $stmt2->close();
                    }

                    if ($hasApprovalStatus) {
                        $stmt3 = $conn->prepare("INSERT INTO stock_movements ($cols, approval_status, approved_by, approved_at) VALUES ($vals, 'approved', ?, NOW())");
                        if (!$stmt3) {
                            throw new Exception('Failed to record transfer.');
                        }
                        $stmt3->bind_param($bindTypes . 'i', ...array_merge($bindParams, [$createdBy]));
                    } else {
                        $stmt3 = $conn->prepare("INSERT INTO stock_movements ($cols) VALUES ($vals)");
                        if (!$stmt3) {
                            throw new Exception('Failed to record transfer.');
                        }
                        $stmt3->bind_param($bindTypes, ...$bindParams);
                    }
                    if (!$stmt3->execute()) {
                        $stmt3->close();
                        throw new Exception('Failed to record transfer.');
                    }
                    $stmt3->close();

                    $conn->commit();
                    audit_log($conn, 'movement.create', 'product_id=' . (string)$productId . ';type=transfer;qty=' . (string)$qty, null);
                    header('Location: transactions.php?msg=ok');
                    exit();
                }

                if (($movementType === 'in' || $movementType === 'out') && $hasTransferFields && $hasLocationStocks && count($locations) > 0) {
                    if ($movementLocationId <= 0) {
                        throw new Exception('Location is required.');
                    }
                }

                if ($hasApprovalStatus && !$canApprove) {
                    if (($movementType === 'in' || $movementType === 'out') && $hasTransferFields && $hasLocationStocks && $movementLocationId > 0) {
                        if ($movementType === 'in') {
                            $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by, approval_status, source_type, source_name, dest_type, dest_location_id, dest_name) VALUES (?, ?, ?, ?, ?, 'pending', 'supplier', 'Supplier', 'location', ?, NULL)");
                            if (!$stmt3) {
                                throw new Exception('Failed to record movement.');
                            }
                            $stmt3->bind_param('isisi' . 'i', $productId, $movementType, $qty, $note, $createdBy, $movementLocationId);
                        } else {
                            $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by, approval_status, source_type, source_location_id, source_name, dest_type, dest_name) VALUES (?, ?, ?, ?, ?, 'pending', 'location', ?, NULL, 'customer', 'Customer')");
                            if (!$stmt3) {
                                throw new Exception('Failed to record movement.');
                            }
                            $stmt3->bind_param('isisi' . 'i', $productId, $movementType, $qty, $note, $createdBy, $movementLocationId);
                        }
                    } else {
                        $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by, approval_status) VALUES (?, ?, ?, ?, ?, 'pending')");
                        if (!$stmt3) {
                            throw new Exception('Failed to record movement.');
                        }
                        $stmt3->bind_param('isisi', $productId, $movementType, $qty, $note, $createdBy);
                    }

                    if (!$stmt3->execute()) {
                        $stmt3->close();
                        throw new Exception('Failed to record movement.');
                    }
                    $stmt3->close();

                    $conn->commit();
                    audit_log(
                        $conn,
                        'movement.create',
                        'product_id=' . (string)$productId . ';type=' . (string)$movementType . ';qty=' . (string)$qty . ';status=pending',
                        null
                    );
                    header('Location: transactions.php?msg=pending');
                    exit();
                }

                $stmt = $conn->prepare("SELECT stock_qty FROM products WHERE id = ? FOR UPDATE");
                if (!$stmt) {
                    throw new Exception('Failed to load product.');
                }
                $stmt->bind_param('i', $productId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if (!$row) {
                    throw new Exception('Product not found.');
                }

                $currentStock = (int)($row['stock_qty'] ?? 0);
                $newStock = $currentStock;

                if ($movementType === 'in') {
                    $newStock = $currentStock + $qty;
                } elseif ($movementType === 'out') {
                    if ($currentStock < $qty) {
                        throw new Exception('Not enough stock for stock out.');
                    }
                    $newStock = $currentStock - $qty;
                } else {
                    $newStock = $qty;
                }

                if (($movementType === 'in' || $movementType === 'out') && $hasTransferFields && $hasLocationStocks && $movementLocationId > 0) {
                    $stmt = $conn->prepare("SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE");
                    if (!$stmt) {
                        throw new Exception('Failed to load location stock.');
                    }
                    $stmt->bind_param('ii', $movementLocationId, $productId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $stmt->close();
                    $cur = (int)($row['qty'] ?? 0);
                    if ($movementType === 'out' && $cur < $qty) {
                        throw new Exception('Not enough stock in source location.');
                    }
                    $newQty = $cur + ($movementType === 'in' ? $qty : -$qty);
                    $stmt2 = $conn->prepare("INSERT INTO location_stocks (location_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)");
                    if (!$stmt2) {
                        throw new Exception('Failed to update location stock.');
                    }
                    $stmt2->bind_param('iii', $movementLocationId, $productId, $newQty);
                    if (!$stmt2->execute()) {
                        $stmt2->close();
                        throw new Exception('Failed to update location stock.');
                    }
                    $stmt2->close();
                }

                $stmt2 = $conn->prepare("UPDATE products SET stock_qty = ? WHERE id = ?");
                if (!$stmt2) {
                    throw new Exception('Failed to update stock.');
                }
                $stmt2->bind_param('ii', $newStock, $productId);
                if (!$stmt2->execute()) {
                    $stmt2->close();
                    throw new Exception('Failed to update stock.');
                }
                $stmt2->close();

                if ($hasApprovalStatus) {
                    if (($movementType === 'in' || $movementType === 'out') && $hasTransferFields && $hasLocationStocks && $movementLocationId > 0) {
                        if ($movementType === 'in') {
                            $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by, approval_status, approved_by, approved_at, source_type, source_name, dest_type, dest_location_id, dest_name) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW(), 'supplier', 'Supplier', 'location', ?, NULL)");
                            if (!$stmt3) {
                                throw new Exception('Failed to record movement.');
                            }
                            $stmt3->bind_param('isisi' . 'i' . 'i', $productId, $movementType, $qty, $note, $createdBy, $createdBy, $movementLocationId);
                        } else {
                            $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by, approval_status, approved_by, approved_at, source_type, source_location_id, source_name, dest_type, dest_name) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW(), 'location', ?, NULL, 'customer', 'Customer')");
                            if (!$stmt3) {
                                throw new Exception('Failed to record movement.');
                            }
                            $stmt3->bind_param('isisi' . 'i' . 'i', $productId, $movementType, $qty, $note, $createdBy, $createdBy, $movementLocationId);
                        }
                    } else {
                        $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by, approval_status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW())");
                        if (!$stmt3) {
                            throw new Exception('Failed to record movement.');
                        }
                        $stmt3->bind_param('isisi' . 'i', $productId, $movementType, $qty, $note, $createdBy, $createdBy);
                    }
                } else {
                    $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt3) {
                        throw new Exception('Failed to record movement.');
                    }
                    $stmt3->bind_param('isisi', $productId, $movementType, $qty, $note, $createdBy);
                }
                if (!$stmt3->execute()) {
                    $stmt3->close();
                    throw new Exception('Failed to record movement.');
                }
                $stmt3->close();

                $conn->commit();
                audit_log(
                    $conn,
                    'movement.create',
                    'product_id=' . (string)$productId . ';type=' . (string)$movementType . ';qty=' . (string)$qty,
                    null
                );
                header('Location: transactions.php?msg=ok');
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $flash = $e->getMessage();
                $flashType = 'error';
            }
        }
    }
}

if ((string)($_GET['msg'] ?? '') === 'ok') {
    $flash = 'Movement saved.';
    $flashType = 'success';
}
if ((string)($_GET['msg'] ?? '') === 'pending') {
    $flash = 'Movement saved as pending approval.';
    $flashType = 'success';
}
if ((string)($_GET['msg'] ?? '') === 'approved') {
    $flash = 'Movement approved.';
    $flashType = 'success';
}
if ((string)($_GET['msg'] ?? '') === 'rejected') {
    $flash = 'Movement rejected.';
    $flashType = 'success';
}

$pending = [];
if ($hasApprovalStatus && has_perm('movement.approve') && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $selectCols = "sm.id, sm.movement_type, sm.qty, sm.note, sm.created_at, p.sku, p.name AS product_name, COALESCE(u.username, '—') AS created_by";
    if ($hasTransferFields) {
        $selectCols .= ", sm.source_type, sm.source_location_id, sm.source_name, sm.dest_type, sm.dest_location_id, sm.dest_name";
    } else {
        $selectCols .= ", NULL AS source_type, NULL AS source_location_id, NULL AS source_name, NULL AS dest_type, NULL AS dest_location_id, NULL AS dest_name";
    }
    $sql = "SELECT $selectCols
            FROM stock_movements sm
            JOIN products p ON p.id = sm.product_id
            LEFT JOIN users u ON u.id = sm.created_by
            WHERE sm.approval_status = 'pending'
            ORDER BY sm.created_at ASC, sm.id ASC
            LIMIT 20";
    if ($res = $conn->query($sql)) {
        while ($r = $res->fetch_assoc()) { $pending[] = $r; }
        $res->free();
    }
}

$history = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $selectCols = "sm.id, sm.movement_type, sm.qty, sm.note, sm.created_at, p.sku, p.name AS product_name, u.username AS created_by";
    if ($hasApprovalStatus) {
        $selectCols .= ", sm.approval_status";
    } else {
        $selectCols .= ", 'approved' AS approval_status";
    }
    if ($hasTransferFields) {
        $selectCols .= ", sm.source_type, sm.source_location_id, sm.source_name, sm.dest_type, sm.dest_location_id, sm.dest_name";
    } else {
        $selectCols .= ", NULL AS source_type, NULL AS source_location_id, NULL AS source_name, NULL AS dest_type, NULL AS dest_location_id, NULL AS dest_name";
    }
    $sql = "SELECT $selectCols FROM stock_movements sm JOIN products p ON p.id = sm.product_id LEFT JOIN users u ON u.id = sm.created_by ORDER BY sm.created_at DESC, sm.id DESC LIMIT 25";
    if ($res = $conn->query($sql)) {
        while ($r = $res->fetch_assoc()) { $history[] = $r; }
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
    <title>Stock In/Out</title>
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
            <div class="page-title">Stock In/Out</div>
            <div class="page-subtitle">Record stock movements and keep inventory accurate</div>
        </div>
        <div class="page-meta">
            <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Add Movement</div>
                <div class="panel-icon bg-orange"><i class='bx bx-transfer-alt'></i></div>
            </div>
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <form method="post" class="form">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-row">
                        <label class="label">Product</label>
                        <select class="input" name="product_id" required>
                            <option value="">Select a product</option>
                            <?php foreach ($products as $p) { ?>
                                <option value="<?php echo (int)$p['id']; ?>" <?php echo $productId === (int)$p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$p['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$p['sku'], ENT_QUOTES, 'UTF-8'); ?>) — Stock: <?php echo (int)$p['stock_qty']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-row two">
                        <div>
                            <label class="label">Type</label>
                            <select class="input" name="movement_type" onchange="(function(sel){var isMovLoc=(sel.value==='in'||sel.value==='out'); var tf=document.getElementById('transferFields'); if(tf){tf.style.display=(sel.value==='transfer')?'grid':'none';} var ml=document.getElementById('movementLocationRow'); if(ml){ml.style.display=isMovLoc?'block':'none';} var mll=document.getElementById('movementLocationLabel'); if(mll){mll.textContent=(sel.value==='out')?'Location (source)':'Location (destination)';} var mls=document.getElementById('movementLocationSelect'); if(mls){mls.required=isMovLoc; mls.disabled=!isMovLoc; if(!isMovLoc){mls.value='';}}})(this)">
                                <option value="in" <?php echo $movementType === 'in' ? 'selected' : ''; ?>>Stock In</option>
                                <option value="out" <?php echo $movementType === 'out' ? 'selected' : ''; ?>>Stock Out</option>
                                <option value="adjust" <?php echo $movementType === 'adjust' ? 'selected' : ''; ?>>Adjust (set stock)</option>
                                <option value="transfer" <?php echo $movementType === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Qty</label>
                            <input class="input" type="number" min="1" name="qty" value="<?php echo (int)($qty > 0 ? $qty : 1); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="label">Note</label>
                        <input class="input" type="text" name="note" value="<?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Optional">
                    </div>

                    <?php if ($hasTransferFields && $hasLocationStocks && count($locations) > 0) { ?>
                        <div class="form-row" id="movementLocationRow" style="display: <?php echo ($movementType === 'in' || $movementType === 'out') ? 'block' : 'none'; ?>;">
                            <label class="label" id="movementLocationLabel"><?php echo $movementType === 'out' ? 'Location (source)' : 'Location (destination)'; ?></label>
                            <select class="input" id="movementLocationSelect" name="movement_location_id" <?php echo ($movementType === 'in' || $movementType === 'out') ? 'required' : 'disabled'; ?>>
                                <option value="">Select a location</option>
                                <?php foreach ($locations as $l) { ?>
                                    <option value="<?php echo (int)($l['id'] ?? 0); ?>" <?php echo $movementLocationId === (int)($l['id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($l['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    <?php } ?>

                    <?php if ($hasTransferFields && count($locations) > 0) { ?>
                        <div class="form-row" id="transferFields" style="display: <?php echo $movementType === 'transfer' ? 'grid' : 'none'; ?>; gap: 12px;">
                            <div class="form-row two">
                                <div>
                                    <label class="label">Source Type</label>
                                    <select class="input" name="source_type" onchange="(function(sel){var v=sel.value; var a=document.getElementById('srcLocWrap'); var b=document.getElementById('srcNameWrap'); if(!a||!b) return; if(v==='location'){a.style.display='block'; b.style.display='none';} else {a.style.display='none'; b.style.display='block';}})(this)">
                                        <option value="location" <?php echo $sourceType === 'location' ? 'selected' : ''; ?>>Location</option>
                                        <option value="supplier" <?php echo $sourceType === 'supplier' ? 'selected' : ''; ?>>Supplier</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="label">Destination Type</label>
                                    <select class="input" name="dest_type" onchange="(function(sel){var v=sel.value; var a=document.getElementById('dstLocWrap'); var b=document.getElementById('dstNameWrap'); if(!a||!b) return; if(v==='location'){a.style.display='block'; b.style.display='none';} else {a.style.display='none'; b.style.display='block';}})(this)">
                                        <option value="location" <?php echo $destType === 'location' ? 'selected' : ''; ?>>Location</option>
                                        <option value="customer" <?php echo $destType === 'customer' ? 'selected' : ''; ?>>Customer</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row two">
                                <div id="srcLocWrap" style="display: <?php echo $sourceType === 'location' ? 'block' : 'none'; ?>;">
                                    <label class="label">Source Location</label>
                                    <select class="input" name="source_location_id">
                                        <option value="">Select location</option>
                                        <?php foreach ($locations as $l) { ?>
                                            <option value="<?php echo (int)$l['id']; ?>" <?php echo $sourceLocationId === (int)$l['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$l['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div id="srcNameWrap" style="display: <?php echo $sourceType === 'supplier' ? 'block' : 'none'; ?>;">
                                    <label class="label">Supplier Name</label>
                                    <input class="input" type="text" name="source_name" value="<?php echo htmlspecialchars($sourceName, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Supplier">
                                </div>

                                <div id="dstLocWrap" style="display: <?php echo $destType === 'location' ? 'block' : 'none'; ?>;">
                                    <label class="label">Destination Location</label>
                                    <select class="input" name="dest_location_id">
                                        <option value="">Select location</option>
                                        <?php foreach ($locations as $l) { ?>
                                            <option value="<?php echo (int)$l['id']; ?>" <?php echo $destLocationId === (int)$l['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$l['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div id="dstNameWrap" style="display: <?php echo $destType === 'customer' ? 'block' : 'none'; ?>;">
                                    <label class="label">Customer Name</label>
                                    <input class="input" type="text" name="dest_name" value="<?php echo htmlspecialchars($destName, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Customer">
                                </div>
                            </div>
                        </div>
                    <?php } ?>

                    <div class="form-actions">
                        <button class="btn primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($hasApprovalStatus && has_perm('movement.approve')) { ?>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Pending Approvals</div>
                    <div class="panel-icon bg-red"><i class='bx bx-shield-quarter'></i></div>
                </div>
                <div class="panel-body">
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Qty</th>
                                <th>Note</th>
                                <th>User</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (count($pending) === 0) { ?>
                                <tr><td colspan="7" class="muted">No pending approvals.</td></tr>
                            <?php } else { ?>
                                <?php foreach ($pending as $p) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($p['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($p['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)($p['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</td>
                                        <td><?php echo htmlspecialchars((string)($p['movement_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo (int)($p['qty'] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars((string)($p['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($p['created_by'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <div class="row-actions">
                                                <form method="post" class="inline" onsubmit="return confirm('Approve this movement?');">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="move_id" value="<?php echo (int)($p['id'] ?? 0); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button class="btn" type="submit">Approve</button>
                                                </form>
                                                <form method="post" class="inline" onsubmit="return confirm('Reject this movement?');">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="move_id" value="<?php echo (int)($p['id'] ?? 0); ?>">
                                                    <input type="hidden" name="rejected_reason" value="Rejected">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button class="btn danger" type="submit">Reject</button>
                                                </form>
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
        <?php } ?>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Recent Movements</div>
                <div class="panel-icon bg-purple"><i class='bx bx-time-five'></i></div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Note</th>
                            <th>User</th>
                            <th>Status</th>
                            <?php if ($hasApprovalStatus && has_perm('movement.approve')) { ?>
                                <th>Approval</th>
                            <?php } ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($history) === 0) { ?>
                            <tr><td colspan="<?php echo ($hasApprovalStatus && has_perm('movement.approve')) ? '8' : '7'; ?>" class="muted">No movements yet.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($history as $h) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($h['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)($h['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</td>
                                    <td><?php echo htmlspecialchars((string)($h['movement_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($h['qty'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['created_by'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['approval_status'] ?? 'approved'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if ($hasApprovalStatus && has_perm('movement.approve')) { ?>
                                        <td>
                                            <?php if ((string)($h['approval_status'] ?? '') === 'pending') { ?>
                                                <div class="row-actions">
                                                    <form method="post" class="inline" onsubmit="return confirm('Approve this movement?');">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="move_id" value="<?php echo (int)($h['id'] ?? 0); ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button class="btn" type="submit">Approve</button>
                                                    </form>
                                                    <form method="post" class="inline" onsubmit="return confirm('Reject this movement?');">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="move_id" value="<?php echo (int)($h['id'] ?? 0); ?>">
                                                        <input type="hidden" name="rejected_reason" value="Rejected">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button class="btn danger" type="submit">Reject</button>
                                                    </form>
                                                </div>
                                            <?php } else { ?>
                                                <span class="muted">—</span>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
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
