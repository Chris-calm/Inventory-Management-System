<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications_lib.php';

require_login();
require_perm('movement.view');

$flash = null;
$flashType = 'info';

$hasProductsTable = false;
$hasStockMovementsTable = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'products'")) {
            $hasProductsTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasProductsTable = false;
    }
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'stock_movements'")) {
            $hasStockMovementsTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasStockMovementsTable = false;
    }
}

$hasGuestRequestsTable = false;
$hasGuestRequestItemsTable = false;
$guestRequestsHistory = [];
$guestReqItemsByReq = [];
$filterGuestUserId = 0;
$guestUsers = [];
if (function_exists('has_perm') && has_perm('movement.approve')) {
    $filterGuestUserId = (int)($_GET['guest_user_id'] ?? 0);
}
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'guest_item_requests'")) {
            $hasGuestRequestsTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasGuestRequestsTable = false;
    }
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'guest_item_request_items'")) {
            $hasGuestRequestItemsTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasGuestRequestItemsTable = false;
    }

    if (function_exists('has_perm') && has_perm('movement.approve')) {
        try {
            if ($resGu = $conn->query("SELECT id, username FROM users WHERE role = 'guest' ORDER BY username ASC")) {
                while ($r = $resGu->fetch_assoc()) {
                    $guestUsers[] = $r;
                }
                $resGu->free();
            }
        } catch (Throwable $e) {
            $guestUsers = [];
        }
    }

    if ($hasGuestRequestsTable && $hasGuestRequestItemsTable) {
        try {
            if ($filterGuestUserId > 0) {
                $stmtGr = $conn->prepare("SELECT r.id, r.guest_user_id, r.location_id, r.warehouse_name, r.status, r.created_at, r.decided_at, r.decided_reason,
                               COALESCE(u.username, 'Guest') AS guest_name,
                               COALESCE(l.name, '') AS location_name
                        FROM guest_item_requests r
                        LEFT JOIN users u ON u.id = r.guest_user_id
                        LEFT JOIN locations l ON l.id = r.location_id
                        WHERE r.status IN ('approved','rejected') AND r.guest_user_id = ?
                        ORDER BY COALESCE(r.decided_at, r.created_at) DESC, r.id DESC
                        LIMIT 20");
                if ($stmtGr) {
                    $stmtGr->bind_param('i', $filterGuestUserId);
                    $stmtGr->execute();
                    $res = $stmtGr->get_result();
                    if ($res) {
                        while ($r = $res->fetch_assoc()) {
                            $guestRequestsHistory[] = $r;
                        }
                    }
                    $stmtGr->close();
                }
            } else {
                $sql = "SELECT r.id, r.guest_user_id, r.location_id, r.warehouse_name, r.status, r.created_at, r.decided_at, r.decided_reason,
                               COALESCE(u.username, 'Guest') AS guest_name,
                               COALESCE(l.name, '') AS location_name
                        FROM guest_item_requests r
                        LEFT JOIN users u ON u.id = r.guest_user_id
                        LEFT JOIN locations l ON l.id = r.location_id
                        WHERE r.status IN ('approved','rejected')
                        ORDER BY COALESCE(r.decided_at, r.created_at) DESC, r.id DESC
                        LIMIT 20";
                if ($res = $conn->query($sql)) {
                    while ($r = $res->fetch_assoc()) {
                        $guestRequestsHistory[] = $r;
                    }
                    $res->free();
                }
            }

            $needIds = [];
            foreach ($guestRequestsHistory as $gr) {
                $rid = (int)($gr['id'] ?? 0);
                if ($rid > 0) {
                    $needIds[$rid] = true;
                }
            }
            $needIds = array_keys($needIds);
            if (count($needIds) > 0) {
                $idList = implode(',', array_map('intval', $needIds));
                $sql2 = "SELECT request_id, sku, name, qty FROM guest_item_request_items WHERE request_id IN ($idList) ORDER BY id ASC";
                if ($res2 = $conn->query($sql2)) {
                    while ($it = $res2->fetch_assoc()) {
                        $rid = (int)($it['request_id'] ?? 0);
                        if ($rid <= 0) {
                            continue;
                        }
                        if (!isset($guestReqItemsByReq[$rid])) {
                            $guestReqItemsByReq[$rid] = [];
                        }
                        $guestReqItemsByReq[$rid][] = $it;
                    }
                    $res2->free();
                }
            }
        } catch (Throwable $e) {
            $guestRequestsHistory = [];
            $guestReqItemsByReq = [];
        }
    }
}

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
if (!$hasProductsTable) {
    $products = [];
    if ($flash === null) {
        $flash = 'Products module is not available yet. Please import inventory_schema.sql.';
        $flashType = 'error';
    }
} elseif (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
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

    if (!$hasProductsTable || !$hasStockMovementsTable) {
        $flash = !$hasStockMovementsTable
            ? 'Stock module is not available yet. Please import the stock schema.'
            : 'Products module is not available yet. Please import inventory_schema.sql.';
        $flashType = 'error';
    } else {
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

                    $newStatus = $postAction === 'approve' ? 'approved' : 'rejected';
                    $reason = trim((string)($_POST['rejected_reason'] ?? ''));
                    if ($reason === '') {
                        $reason = 'Rejected';
                    }
                    $approvedBy = (int)($_SESSION['user_id'] ?? 0);
                    $stmtUp = $conn->prepare("UPDATE stock_movements SET approval_status = ?, approved_by = ?, approved_at = NOW(), rejected_reason = ? WHERE id = ?");
                    if (!$stmtUp) {
                        throw new Exception('Failed to update movement.');
                    }
                    $stmtUp->bind_param('sisi', $newStatus, $approvedBy, $reason, $moveId);
                    if (!$stmtUp->execute()) {
                        $stmtUp->close();
                        throw new Exception('Failed to update movement.');
                    }
                    $stmtUp->close();

                    try {
                        $actorId = (int)($_SESSION['user_id'] ?? 0);
                        $title = $newStatus === 'approved' ? 'Stock movement approved' : 'Stock movement rejected';
                        $msgN = 'Movement #' . (string)$moveId . ' was ' . $newStatus . '.';
                        notifications_create($conn, $actorId, $title, $msgN, 'transactions.php', $newStatus === 'approved' ? 'success' : 'warning');

                        if ($resU = $conn->query("SELECT id FROM users WHERE role = 'admin'")) {
                            while ($u = $resU->fetch_assoc()) {
                                $uid = (int)($u['id'] ?? 0);
                                if ($uid > 0 && $uid !== $actorId) {
                                    notifications_create($conn, $uid, $title, $msgN, 'transactions.php', $newStatus === 'approved' ? 'success' : 'warning');
                                }
                            }
                            $resU->free();
                        }
                    } catch (Throwable $e) {
                    }

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

                try {
                    $t = 'Stock movement created';
                    $m = 'Created a ' . $movementType . ' movement for product_id=' . (string)$productId . ' qty=' . (string)$qty . '.';
                    notifications_create($conn, $createdBy, $t, $m, 'transactions.php', 'info');

                    if ($resU = $conn->query("SELECT id FROM users WHERE role = 'admin'")) {
                        while ($u = $resU->fetch_assoc()) {
                            $uid = (int)($u['id'] ?? 0);
                            if ($uid > 0 && $uid !== $createdBy) {
                                notifications_create($conn, $uid, $t, $m, 'transactions.php', 'info');
                            }
                        }
                        $resU->free();
                    }
                } catch (Throwable $e) {
                }

                header('Location: transactions.php?msg=created');
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $flash = $e->getMessage();
                $flashType = 'error';
            }
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
    <?php $pageTitle = 'Stock In/Out'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Stock In/Out</div>
            <div class="page-subtitle">Record stock movements and keep inventory accurate</div>
        </div>
        <div class="page-meta">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
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

        <?php if (has_perm('movement.approve')) { ?>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Guest Requests (Approved / Rejected)</div>
                    <div class="panel-icon bg-green"><i class='bx bx-package'></i></div>
                </div>
                <div class="panel-body">
                    <form method="get" style="margin-bottom: 10px; display:flex; gap: 10px; flex-wrap: wrap; align-items: end;">
                        <div>
                            <label class="label">Guest user</label>
                            <select class="input" name="guest_user_id" onchange="this.form.submit()" style="min-width: 220px;">
                                <option value="0" <?php echo $filterGuestUserId === 0 ? 'selected' : ''; ?>>All guests</option>
                                <?php foreach ($guestUsers as $gu) { $gid = (int)($gu['id'] ?? 0); ?>
                                    <option value="<?php echo (int)$gid; ?>" <?php echo $filterGuestUserId === $gid ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($gu['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </form>
                    <?php if (!$hasGuestRequestsTable || !$hasGuestRequestItemsTable) { ?>
                        <div class="muted">Guest requests module is not available yet.</div>
                    <?php } elseif (count($guestRequestsHistory) === 0) { ?>
                        <div class="muted">No approved/rejected guest requests yet.</div>
                    <?php } else { ?>
                        <div style="display:grid; gap: 10px;">
                            <?php foreach ($guestRequestsHistory as $gr) { $rid = (int)($gr['id'] ?? 0); $items = $guestReqItemsByReq[$rid] ?? []; ?>
                                <div class="guest-item" style="grid-template-columns: 1fr;">
                                    <div>
                                        <div class="t">#<?php echo (int)$rid; ?> · <?php echo htmlspecialchars((string)($gr['guest_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($gr['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="m">Warehouse: <?php echo htmlspecialchars((string)($gr['warehouse_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($gr['location_name'])) { ?> · Location: <?php echo htmlspecialchars((string)($gr['location_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php } ?></div>
                                        <div class="m"><?php echo htmlspecialchars((string)($gr['decided_at'] ?? $gr['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($gr['decided_reason'])) { ?> · <?php echo htmlspecialchars((string)($gr['decided_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php } ?></div>

                                        <?php if (count($items) > 0) { ?>
                                            <div style="margin-top: 8px; display:grid; gap: 6px;">
                                                <?php foreach ($items as $it) { ?>
                                                    <div class="muted"><?php echo htmlspecialchars((string)($it['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · Qty: <?php echo (int)($it['qty'] ?? 0); ?></div>
                                                <?php } ?>
                                            </div>
                                        <?php } else { ?>
                                            <div class="muted" style="margin-top: 8px;">No items found for this request.</div>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
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

<script src="../JS/script.js?v=20260225"></script>
</body>
</html>
