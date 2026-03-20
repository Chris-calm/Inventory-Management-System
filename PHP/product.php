<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications_lib.php';

require_login();
require_perm('product.view');

$__role = (string)($_SESSION['role'] ?? '');
$__userId = (int)($_SESSION['user_id'] ?? 0);

$flash = null;
$flashType = 'info';

$filterGuestUserId = 0;
if (function_exists('has_perm') && has_perm('movement.approve')) {
    $filterGuestUserId = (int)($_GET['guest_user_id'] ?? 0);
}

$guestUsers = [];
if ($filterGuestUserId >= 0 && function_exists('has_perm') && has_perm('movement.approve') && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SELECT id, username FROM users WHERE role = 'guest' ORDER BY username ASC")) {
            while ($r = $res->fetch_assoc()) {
                $guestUsers[] = $r;
            }
            $res->free();
        }
    } catch (Throwable $e) {
        $guestUsers = [];
    }
}

$hasStockMovementsTable = false;
$hasLocationStocksTable = false;
$hasSmApprovalStatus = false;
$hasSmApprovedBy = false;
$hasSmApprovedAt = false;
$hasSmRejectedReason = false;
$hasSmTransferFields = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'stock_movements'")) {
            $hasStockMovementsTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasStockMovementsTable = false;
    }
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'location_stocks'")) {
            $hasLocationStocksTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasLocationStocksTable = false;
    }

    if ($hasStockMovementsTable) {
        $colCk = [
            'approval_status' => 'hasSmApprovalStatus',
            'approved_by' => 'hasSmApprovedBy',
            'approved_at' => 'hasSmApprovedAt',
            'rejected_reason' => 'hasSmRejectedReason',
        ];
        foreach ($colCk as $col => $varName) {
            $ok = false;
            if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = ?")) {
                $stmtCol->bind_param('s', $col);
                $stmtCol->execute();
                $c = 0;
                $stmtCol->bind_result($c);
                if ($stmtCol->fetch()) {
                    $ok = ((int)$c) > 0;
                }
                $stmtCol->close();
            }
            ${$varName} = $ok;
        }

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
        $hasSmTransferFields = $ok1 && $ok2;
    }
}

$action = (string)($_GET['action'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$sku = '';
$name = '';
$categoryId = '';
$unitCost = '0.00';
$unitPrice = '0.00';
$reorderLevel = '5';
$status = 'active';
$imagePath = '';

$hasProductsTable = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'products'")) {
            $hasProductsTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasProductsTable = false;
    }
}

$hasGuestRequestsTable = false;
$hasGuestRequestItemsTable = false;
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
}

$hasLocationsTable = false;
$hasOccupiedColumn = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'locations'")) {
            $hasLocationsTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasLocationsTable = false;
    }
    if ($hasLocationsTable) {
        if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND COLUMN_NAME = 'is_occupied'")) {
            $stmtCol->execute();
            $c = 0;
            $stmtCol->bind_result($c);
            if ($stmtCol->fetch()) {
                $hasOccupiedColumn = ((int)$c) > 0;
            }
            $stmtCol->close();
        }
    }
}

$myRequests = [];
$pendingGuestRequests = [];
$reqItemsByReq = [];

$viewRequestId = (int)($_GET['view_request_id'] ?? 0);
$viewRequest = null;
$viewRequestItems = [];

$clientProductOptions = [];
if ($action !== 'edit' && $hasGuestRequestsTable && $hasGuestRequestItemsTable && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        $hasGriCategoryId = false;
        $hasGriUnitCost = false;
        $hasGriUnitPrice = false;
        $hasGriReorderLevel = false;
        $griCols = [
            'category_id' => 'hasGriCategoryId',
            'unit_cost' => 'hasGriUnitCost',
            'unit_price' => 'hasGriUnitPrice',
            'reorder_level' => 'hasGriReorderLevel',
        ];
        foreach ($griCols as $col => $varName) {
            $ok = false;
            if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_item_request_items' AND COLUMN_NAME = ?")) {
                $stmtCol->bind_param('s', $col);
                $stmtCol->execute();
                $c = 0;
                $stmtCol->bind_result($c);
                if ($stmtCol->fetch()) {
                    $ok = ((int)$c) > 0;
                }
                $stmtCol->close();
            }
            ${$varName} = $ok;
        }

        $selectExtra = [];
        if ($hasGriCategoryId) $selectExtra[] = 'MAX(i.category_id) AS category_id';
        if ($hasGriUnitCost) $selectExtra[] = 'MAX(i.unit_cost) AS unit_cost';
        if ($hasGriUnitPrice) $selectExtra[] = 'MAX(i.unit_price) AS unit_price';
        if ($hasGriReorderLevel) $selectExtra[] = 'MAX(i.reorder_level) AS reorder_level';
        $extraSql = count($selectExtra) > 0 ? (",\n                           " . implode(",\n                           ", $selectExtra)) : '';

        if ($filterGuestUserId > 0) {
            $sql = "SELECT i.sku,
                           MAX(i.name) AS name" . $extraSql . "
                    FROM guest_item_request_items i
                    JOIN guest_item_requests r ON r.id = i.request_id
                    WHERE r.guest_user_id = ?
                    GROUP BY i.sku
                    ORDER BY name ASC, i.sku ASC
                    LIMIT 300";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $filterGuestUserId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $clientProductOptions[] = $r;
                    }
                }
                $stmt->close();
            }
        } else {
            $selectExtra2 = [];
            if ($hasGriCategoryId) $selectExtra2[] = 'MAX(category_id) AS category_id';
            if ($hasGriUnitCost) $selectExtra2[] = 'MAX(unit_cost) AS unit_cost';
            if ($hasGriUnitPrice) $selectExtra2[] = 'MAX(unit_price) AS unit_price';
            if ($hasGriReorderLevel) $selectExtra2[] = 'MAX(reorder_level) AS reorder_level';
            $extraSql2 = count($selectExtra2) > 0 ? (",\n                           " . implode(",\n                           ", $selectExtra2)) : '';

            $sql = "SELECT sku,
                           MAX(name) AS name" . $extraSql2 . "
                    FROM guest_item_request_items
                    GROUP BY sku
                    ORDER BY name ASC, sku ASC
                    LIMIT 300";
            if ($res = $conn->query($sql)) {
                while ($r = $res->fetch_assoc()) {
                    $clientProductOptions[] = $r;
                }
                $res->free();
            }
        }
    } catch (Throwable $e) {
        $clientProductOptions = [];
    }
}

if ($hasGuestRequestsTable && $hasGuestRequestItemsTable && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($__role === 'guest' && $__userId > 0) {
        $stmt = $conn->prepare("SELECT id, warehouse_name, status, note, created_at, decided_at, decided_reason FROM guest_item_requests WHERE guest_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 10");
        if ($stmt) {
            $stmt->bind_param('i', $__userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $myRequests[] = $r;
                }
            }
            $stmt->close();
        }
    }

    if (function_exists('has_perm') && has_perm('movement.approve')) {
        if ($filterGuestUserId > 0) {
            $stmtPg = $conn->prepare("SELECT r.id, r.warehouse_name, r.note, r.created_at, COALESCE(u.username, 'Guest') AS guest_name
                    FROM guest_item_requests r
                    LEFT JOIN users u ON u.id = r.guest_user_id
                    WHERE r.status = 'pending' AND r.guest_user_id = ?
                    ORDER BY r.created_at ASC, r.id ASC
                    LIMIT 20");
            if ($stmtPg) {
                $stmtPg->bind_param('i', $filterGuestUserId);
                $stmtPg->execute();
                $res = $stmtPg->get_result();
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $pendingGuestRequests[] = $r;
                    }
                }
                $stmtPg->close();
            }
        } else {
            $sql = "SELECT r.id, r.warehouse_name, r.note, r.created_at, COALESCE(u.username, 'Guest') AS guest_name
                    FROM guest_item_requests r
                    LEFT JOIN users u ON u.id = r.guest_user_id
                    WHERE r.status = 'pending'
                    ORDER BY r.created_at ASC, r.id ASC
                    LIMIT 20";
            if ($res = $conn->query($sql)) {
                while ($r = $res->fetch_assoc()) {
                    $pendingGuestRequests[] = $r;
                }
                $res->free();
            }
        }

        if ($viewRequestId > 0) {
            $sqlView = "SELECT r.id, r.guest_user_id, r.location_id, r.warehouse_name, r.status, r.note, r.created_at, r.decided_at, r.decided_reason,
                               COALESCE(u.username, 'Guest') AS guest_name
                        FROM guest_item_requests r
                        LEFT JOIN users u ON u.id = r.guest_user_id
                        WHERE r.id = ?
                        LIMIT 1";
            $stmtView = $conn->prepare($sqlView);
            if ($stmtView) {
                $stmtView->bind_param('i', $viewRequestId);
                $stmtView->execute();
                $resV = $stmtView->get_result();
                if ($resV) {
                    $viewRequest = $resV->fetch_assoc();
                }
                $stmtView->close();
            }

            if ($viewRequest) {
                $rid2 = (int)($viewRequest['id'] ?? 0);
                $viewRequestItems = $reqItemsByReq[$rid2] ?? [];
                if (count($viewRequestItems) === 0) {
                    $stmtVI = $conn->prepare("SELECT request_id, sku, name, qty FROM guest_item_request_items WHERE request_id = ? ORDER BY id ASC");
                    if ($stmtVI) {
                        $stmtVI->bind_param('i', $rid2);
                        $stmtVI->execute();
                        $resVI = $stmtVI->get_result();
                        if ($resVI) {
                            while ($rr = $resVI->fetch_assoc()) {
                                $viewRequestItems[] = $rr;
                            }
                        }
                        $stmtVI->close();
                    }
                }
            }
        }
    }

    $needIds = [];
    foreach ($myRequests as $r) {
        $needIds[] = (int)($r['id'] ?? 0);
    }
    foreach ($pendingGuestRequests as $r) {
        $needIds[] = (int)($r['id'] ?? 0);
    }
    if (function_exists('has_perm') && has_perm('movement.approve') && $viewRequestId > 0) {
        $needIds[] = $viewRequestId;
    }
    $needIds = array_values(array_unique(array_filter($needIds, fn($x) => $x > 0)));
    if (count($needIds) > 0) {
        $idList = implode(',', $needIds);
        $sql = "SELECT request_id, sku, name, qty FROM guest_item_request_items WHERE request_id IN ($idList) ORDER BY id ASC";
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                $rid = (int)($r['request_id'] ?? 0);
                if (!isset($reqItemsByReq[$rid])) {
                    $reqItemsByReq[$rid] = [];
                }
                $reqItemsByReq[$rid][] = $r;
            }
            $res->free();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }

    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'guest_req_approve' || $postAction === 'guest_req_reject') {
        require_perm('movement.approve');

        if (!$hasGuestRequestsTable || !$hasGuestRequestItemsTable) {
            $flash = 'Guest requests module is not available yet.';
            $flashType = 'error';
        } elseif (!$hasLocationsTable || !$hasOccupiedColumn) {
            $flash = 'Warehouses occupancy is not enabled yet.';
            $flashType = 'error';
        } elseif ($postAction === 'guest_req_approve' && (!$hasStockMovementsTable || !$hasLocationStocksTable || !$hasProductsTable)) {
            $flash = 'Stock transaction workflow is not enabled yet. Please import inventory_schema.sql, stock_schema_v2.sql, stock_schema_v3.sql, and locations_schema.sql.';
            $flashType = 'error';
        } else {
            $rid = (int)($_POST['request_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                $reason = null;
            } else {
                $reason = mb_substr($reason, 0, 255);
            }

            if ($rid <= 0) {
                $flash = 'Invalid request.';
                $flashType = 'error';
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("SELECT id, guest_user_id, location_id, warehouse_name, status FROM guest_item_requests WHERE id = ? LIMIT 1 FOR UPDATE");
                    if (!$stmt) {
                        throw new Exception('select');
                    }
                    $stmt->bind_param('i', $rid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res ? $res->fetch_assoc() : null;
                    $stmt->close();
                    if (!$row) {
                        throw new Exception('not_found');
                    }
                    if ((string)($row['status'] ?? '') !== 'pending') {
                        throw new Exception('not_pending');
                    }

                    $newStatus = $postAction === 'guest_req_approve' ? 'approved' : 'rejected';
                    $decidedBy = (int)($_SESSION['user_id'] ?? 0);

                    if ($newStatus === 'approved') {
                        $locId = (int)($row['location_id'] ?? 0);
                        if ($locId <= 0) {
                            throw new Exception('invalid_location');
                        }

                        $reqItems = [];
                        $stmtIt = $conn->prepare('SELECT sku, name, qty FROM guest_item_request_items WHERE request_id = ? ORDER BY id ASC');
                        if (!$stmtIt) {
                            throw new Exception('items');
                        }
                        $stmtIt->bind_param('i', $rid);
                        $stmtIt->execute();
                        $resIt = $stmtIt->get_result();
                        if ($resIt) {
                            while ($it = $resIt->fetch_assoc()) {
                                $reqItems[] = $it;
                            }
                        }
                        $stmtIt->close();
                        if (count($reqItems) === 0) {
                            throw new Exception('no_items');
                        }

                        $guestId = (int)($row['guest_user_id'] ?? 0);
                        $guestName = 'Guest';
                        if ($guestId > 0) {
                            $stmtGn = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
                            if ($stmtGn) {
                                $stmtGn->bind_param('i', $guestId);
                                $stmtGn->execute();
                                $resGn = $stmtGn->get_result();
                                if ($resGn && ($rGn = $resGn->fetch_assoc())) {
                                    $guestName = (string)($rGn['username'] ?? 'Guest');
                                }
                                $stmtGn->close();
                            }
                        }

                        foreach ($reqItems as $it) {
                            $skuIt = trim((string)($it['sku'] ?? ''));
                            $qtyIt = (int)($it['qty'] ?? 0);
                            if ($qtyIt <= 0 || $skuIt === '') {
                                continue;
                            }

                            $pid = 0;
                            $stmtP = $conn->prepare('SELECT id, stock_qty FROM products WHERE sku = ? LIMIT 1 FOR UPDATE');
                            if (!$stmtP) {
                                throw new Exception('product_lookup');
                            }
                            $stmtP->bind_param('s', $skuIt);
                            $stmtP->execute();
                            $resP = $stmtP->get_result();
                            $pRow = $resP ? $resP->fetch_assoc() : null;
                            $stmtP->close();
                            if (!$pRow) {
                                throw new Exception('product_not_found');
                            }
                            $pid = (int)($pRow['id'] ?? 0);
                            if ($pid <= 0) {
                                throw new Exception('product_not_found');
                            }

                            $curLocQty = 0;
                            $stmtLs = $conn->prepare('SELECT qty FROM location_stocks WHERE location_id = ? AND product_id = ? FOR UPDATE');
                            if (!$stmtLs) {
                                throw new Exception('location_stock');
                            }
                            $stmtLs->bind_param('ii', $locId, $pid);
                            $stmtLs->execute();
                            $resLs = $stmtLs->get_result();
                            if ($resLs && ($lsRow = $resLs->fetch_assoc())) {
                                $curLocQty = (int)($lsRow['qty'] ?? 0);
                            }
                            $stmtLs->close();

                            if ($curLocQty < $qtyIt) {
                                throw new Exception('insufficient_stock');
                            }
                            $newLocQty = $curLocQty - $qtyIt;
                            $stmtLsUp = $conn->prepare('INSERT INTO location_stocks (location_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)');
                            if (!$stmtLsUp) {
                                throw new Exception('location_stock_update');
                            }
                            $stmtLsUp->bind_param('iii', $locId, $pid, $newLocQty);
                            if (!$stmtLsUp->execute()) {
                                $stmtLsUp->close();
                                throw new Exception('location_stock_update');
                            }
                            $stmtLsUp->close();

                            $stmtPs = $conn->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?');
                            if (!$stmtPs) {
                                throw new Exception('product_stock_update');
                            }
                            $stmtPs->bind_param('iii', $qtyIt, $pid, $qtyIt);
                            if (!$stmtPs->execute() || $stmtPs->affected_rows <= 0) {
                                $stmtPs->close();
                                throw new Exception('insufficient_stock');
                            }
                            $stmtPs->close();

                            $noteMv = 'Guest request #' . (string)$rid . ' · ' . $guestName;
                            $cols = 'product_id, movement_type, qty, note, created_by';
                            $vals = '?, ?, ?, ?, ?';
                            $types = 'isisi';
                            $params = [$pid, 'out', $qtyIt, $noteMv, $guestId];

                            if ($hasSmApprovalStatus) {
                                $cols .= ', approval_status';
                                $vals .= ", 'approved'";
                            }
                            if ($hasSmApprovedBy) {
                                $cols .= ', approved_by';
                                $vals .= ', ?';
                                $types .= 'i';
                                $params[] = $decidedBy;
                            }
                            if ($hasSmApprovedAt) {
                                $cols .= ', approved_at';
                                $vals .= ', NOW()';
                            }
                            if ($hasSmTransferFields) {
                                $cols .= ', source_type, source_location_id, source_name, dest_type, dest_location_id, dest_name';
                                $vals .= ", 'location', ?, NULL, 'customer', NULL, ?";
                                $types .= 'is';
                                $params[] = $locId;
                                $params[] = mb_substr($guestName, 0, 120);
                            }

                            $sqlMv = "INSERT INTO stock_movements ($cols) VALUES ($vals)";
                            $stmtMv = $conn->prepare($sqlMv);
                            if (!$stmtMv) {
                                throw new Exception('movement_insert');
                            }
                            $stmtMv->bind_param($types, ...$params);
                            if (!$stmtMv->execute()) {
                                $stmtMv->close();
                                throw new Exception('movement_insert');
                            }
                            $stmtMv->close();
                        }

                        $stmtL = $conn->prepare("SELECT id, COALESCE(is_occupied, 0) AS is_occupied FROM locations WHERE id = ? LIMIT 1 FOR UPDATE");
                        if (!$stmtL) {
                            throw new Exception('lock_location');
                        }
                        $stmtL->bind_param('i', $locId);
                        $stmtL->execute();
                        $resL = $stmtL->get_result();
                        $loc = $resL ? $resL->fetch_assoc() : null;
                        $stmtL->close();
                        if (!$loc) {
                            throw new Exception('location_not_found');
                        }
                        if ((int)($loc['is_occupied'] ?? 0) === 1) {
                            throw new Exception('occupied');
                        }

                        $stmtOcc = $conn->prepare("UPDATE locations SET is_occupied = 1, occupied_request_id = ?, occupied_at = NOW() WHERE id = ?");
                        if (!$stmtOcc) {
                            throw new Exception('occupy');
                        }
                        $stmtOcc->bind_param('ii', $rid, $locId);
                        if (!$stmtOcc->execute()) {
                            $stmtOcc->close();
                            throw new Exception('occupy_exec');
                        }
                        $stmtOcc->close();
                    }

                    $stmtUp = $conn->prepare("UPDATE guest_item_requests SET status = ?, decided_at = NOW(), decided_by = ?, decided_reason = ? WHERE id = ?");
                    if (!$stmtUp) {
                        throw new Exception('update');
                    }
                    $stmtUp->bind_param('sisi', $newStatus, $decidedBy, $reason, $rid);
                    if (!$stmtUp->execute()) {
                        $stmtUp->close();
                        throw new Exception('update_exec');
                    }
                    $stmtUp->close();

                    try {
                        $guestId = (int)($row['guest_user_id'] ?? 0);
                        $wh = (string)($row['warehouse_name'] ?? '');
                        $title = $newStatus === 'approved' ? 'Request approved' : 'Request rejected';
                        $msg = 'Your request for ' . $wh . ' was ' . $newStatus . '.';
                        if ($reason) {
                            $msg .= ' Reason: ' . $reason;
                        }
                        notifications_create($conn, $guestId, $title, $msg, 'product.php#guest-requests', $newStatus === 'approved' ? 'success' : 'warning');
                    } catch (Throwable $e) {
                    }

                    $conn->commit();
                    header('Location: product.php#guest-requests-approvals');
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $m = $e->getMessage();
                    if ($m === 'occupied') {
                        $flash = 'Cannot approve: warehouse is already occupied.';
                    } elseif ($m === 'insufficient_stock') {
                        $flash = 'Cannot approve: not enough stock in the selected warehouse for one or more requested items.';
                    } elseif ($m === 'product_not_found') {
                        $flash = 'Cannot approve: one or more requested items do not exist in Products yet. Create the product first (Add Product), then restock it to the selected warehouse, then approve the request.';
                    } else {
                        $flash = 'Failed to update request.';
                    }
                    $flashType = 'error';
                }
            }
        }
    }
}

$categories = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $hasCategoriesTable = false;
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'categories'")) {
            $hasCategoriesTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasCategoriesTable = false;
    }

    if (!$hasCategoriesTable) {
        $categories = [];
    } else {
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
}

$hasProdImagePath = false;
$hasProdArchivedAt = false;
if ($hasProductsTable && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
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

if ($action === 'edit' && $id > 0 && $hasProductsTable && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasProductsTable && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
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
        } else {
            require_perm('product.create');
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
                    if ($editId > 0) {
                        if ($catVal === null) {
                            $setParts = "sku = ?, name = ?, category_id = NULL, unit_cost = ?, unit_price = ?, reorder_level = ?, status = ?";
                            if ($hasProdImagePath && $uploadedImagePath !== null) {
                                $setParts .= ", image_path = ?";
                            }
                            $stmt2 = $conn->prepare("UPDATE products SET $setParts WHERE id = ?");
                            if ($stmt2) {
                                if ($hasProdImagePath && $uploadedImagePath !== null) {
                                    $stmt2->bind_param('ssddiss' . 'i', $sku, $name, $costVal, $priceVal, $reorderVal, $status, $uploadedImagePath, $editId);
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
                                    $stmt2->bind_param('ssiddiss' . 'i', $sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $status, $uploadedImagePath, $editId);
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
                    } else {
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
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') { $flash = 'Product created.'; $flashType = 'success'; }
if ($msg === 'updated') { $flash = 'Product updated.'; $flashType = 'success'; }
if ($msg === 'deleted') { $flash = 'Product deleted.'; $flashType = 'success'; }
if ($msg === 'archived') { $flash = 'Product archived.'; $flashType = 'success'; }
if ($msg === 'restored') { $flash = 'Product restored.'; $flashType = 'success'; }

$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? '');
$showArchived = (string)($_GET['show_archived'] ?? '0') === '1';

$rows = [];
if (!$hasProductsTable) {
    if ($flash === null) {
        $flash = 'Products module is not available yet. Please import inventory_schema.sql.';
        $flashType = 'error';
    }
} elseif (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $hasCategoriesTable = false;
    try {
        if ($res = $conn->query("SHOW TABLES LIKE 'categories'")) {
            $hasCategoriesTable = (bool)$res->fetch_assoc();
            $res->free();
        }
    } catch (Throwable $e) {
        $hasCategoriesTable = false;
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

    if ($__role === 'guest') {
        $where[] = "p.status = 'active' AND p.stock_qty > 0";
    }

    if ($hasProdArchivedAt && !$showArchived) {
        $where[] = 'p.archived_at IS NULL';
    }

    $selectCols = "p.id, p.sku, p.name, COALESCE(c.name, '—') AS category_name, p.unit_cost, p.unit_price, p.stock_qty, p.reorder_level, p.status, p.created_at";
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
    $sql = $hasCategoriesTable
        ? "SELECT $selectCols FROM products p LEFT JOIN categories c ON c.id = p.category_id"
        : "SELECT $selectCols FROM products p LEFT JOIN (SELECT NULL AS id, NULL AS name) c ON 1=0";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Product'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<section class="home">
    <div class="page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="page-title">Product</div>
            <div class="page-subtitle"><?php echo $__role === 'guest' ? 'Browse products and pricing' : 'Manage your inventory products'; ?></div>
        </div>
        <div class="page-meta self-start sm:self-auto">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
        </div>
    </div>

    <?php if ($flash) { ?>
        <div class="content-grid" style="grid-template-columns: 1fr; padding-bottom: 0;">
            <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    <?php } ?>

    <div id="guest-requests-approvals"></div>
    <?php if (function_exists('has_perm') && has_perm('movement.approve')) { ?>
        <div class="content-grid grid grid-cols-1 xl:grid-cols-2 gap-4" style="grid-template-columns: unset; padding-top: 0;">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Pending / Approval Queue</div>
                    <div class="panel-icon bg-purple"><i class='bx bx-check-shield'></i></div>
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
                        <?php if ($viewRequestId > 0) { ?>
                            <input type="hidden" name="view_request_id" value="<?php echo (int)$viewRequestId; ?>">
                        <?php } ?>
                    </form>
                    <?php if (!$hasGuestRequestsTable || !$hasGuestRequestItemsTable) { ?>
                        <div class="muted">Requests are not available yet.</div>
                    <?php } elseif (count($pendingGuestRequests) === 0) { ?>
                        <div class="muted">No pending requests.</div>
                    <?php } else { ?>
                        <div style="display:grid; gap: 10px;">
                            <?php foreach ($pendingGuestRequests as $r) { $rid = (int)($r['id'] ?? 0); ?>
                                <div class="guest-item" style="grid-template-columns: 1fr auto; align-items: center;">
                                    <div>
                                        <div class="t">#<?php echo (int)$rid; ?> · <?php echo htmlspecialchars((string)($r['guest_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="m"><?php echo htmlspecialchars((string)($r['warehouse_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <?php $viewParams = ['view_request_id' => $rid]; if ($filterGuestUserId > 0) { $viewParams['guest_user_id'] = $filterGuestUserId; } ?>
                                    <a class="btn" href="product.php?<?php echo htmlspecialchars(http_build_query($viewParams), ENT_QUOTES, 'UTF-8'); ?>#guest-requests-approvals">View</a>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Guest Request Details</div>
                    <div class="panel-icon bg-green"><i class='bx bx-detail'></i></div>
                </div>
                <div class="panel-body">
                    <?php if (!$hasGuestRequestsTable || !$hasGuestRequestItemsTable) { ?>
                        <div class="muted">Requests are not available yet.</div>
                    <?php } elseif (!$viewRequest) { ?>
                        <div class="muted">Select a request from the queue to see items.</div>
                    <?php } else { ?>
                        <?php $rid = (int)($viewRequest['id'] ?? 0); ?>
                        <div class="guest-item" style="grid-template-columns: 1fr;">
                            <div>
                                <div class="t">Request #<?php echo (int)$rid; ?> · <?php echo htmlspecialchars((string)($viewRequest['guest_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="m">Warehouse: <?php echo htmlspecialchars((string)($viewRequest['warehouse_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="m">Status: <?php echo htmlspecialchars((string)($viewRequest['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($viewRequest['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if (!empty($viewRequest['note'])) { ?>
                                    <div class="m">Note: <?php echo htmlspecialchars((string)($viewRequest['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php } ?>
                                <?php if (!empty($viewRequest['decided_reason'])) { ?>
                                    <div class="m">Decision: <?php echo htmlspecialchars((string)($viewRequest['decided_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php } ?>

                                <?php if (count($viewRequestItems) > 0) { ?>
                                    <div style="margin-top: 10px; display:grid; gap: 6px;">
                                        <?php foreach ($viewRequestItems as $it) { ?>
                                            <div class="muted"><?php echo htmlspecialchars((string)($it['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · Qty: <?php echo (int)($it['qty'] ?? 0); ?></div>
                                        <?php } ?>
                                    </div>
                                <?php } else { ?>
                                    <div class="muted" style="margin-top: 10px;">No items found for this request.</div>
                                <?php } ?>

                                <?php if ((string)($viewRequest['status'] ?? '') === 'pending') { ?>
                                    <form method="post" style="margin-top: 12px; display:flex; gap: 10px; flex-wrap: wrap;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$rid; ?>">
                                        <input class="input" type="text" name="reason" placeholder="Reason (optional)" style="min-width: 240px;">
                                        <button class="btn primary" type="submit" name="action" value="guest_req_approve">Approve</button>
                                        <button class="btn danger" type="submit" name="action" value="guest_req_reject">Reject</button>
                                    </form>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>

    <div class="content-grid grid grid-cols-1 xl:grid-cols-2 gap-4">
        <?php if ($__role === 'guest') { ?>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Selected Warehouse & Items</div>
                    <div class="panel-icon bg-purple"><i class='bx bx-package'></i></div>
                </div>
                <div class="panel-body">
                    <div id="guest-warehouse-pill" class="muted">No warehouse selected. Go to <a href="locations.php">Warehouses</a> and select one.</div>
                    <div id="guest-items" style="margin-top: 12px;"></div>
                    <div style="margin-top: 12px; display:grid; gap: 10px;">
                        <input id="guest-approval-note" class="input" type="text" placeholder="Note to staff/admin (optional)">
                        <button id="guest-submit-approval" class="btn primary" type="button" data-csrf="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">Submit for approval</button>
                        <div id="guest-submit-status" class="muted" style="display:none;"></div>
                    </div>
                </div>
            </div>
        <?php } ?>

        <?php if (has_perm('product.create') || has_perm('product.edit')) { ?>
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

                        <?php if ($action !== 'edit' && count($clientProductOptions) > 0) { ?>
                        <div class="form-row">
                            <label class="label">Client product</label>
                            <select id="client-product-select" class="input" name="client_product_pick">
                                <option value="">— Select from guest requests —</option>
                                <?php foreach ($clientProductOptions as $cp) { $cpSku = trim((string)($cp['sku'] ?? '')); $cpName = trim((string)($cp['name'] ?? '')); $cpCat = (int)($cp['category_id'] ?? 0); $cpCost = (string)($cp['unit_cost'] ?? '0.00'); $cpPrice = (string)($cp['unit_price'] ?? '0.00'); $cpReorder = (int)($cp['reorder_level'] ?? 0); if ($cpSku === '') { continue; } ?>
                                    <option value="<?php echo htmlspecialchars($cpSku, ENT_QUOTES, 'UTF-8'); ?>" data-sku="<?php echo htmlspecialchars($cpSku, ENT_QUOTES, 'UTF-8'); ?>" data-name="<?php echo htmlspecialchars($cpName, ENT_QUOTES, 'UTF-8'); ?>" data-category-id="<?php echo (int)$cpCat; ?>" data-unit-cost="<?php echo htmlspecialchars($cpCost, ENT_QUOTES, 'UTF-8'); ?>" data-unit-price="<?php echo htmlspecialchars($cpPrice, ENT_QUOTES, 'UTF-8'); ?>" data-reorder-level="<?php echo (int)$cpReorder; ?>"><?php echo htmlspecialchars($cpSku . ($cpName !== '' ? (' · ' . $cpName) : ''), ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <?php } ?>

                    <div class="form-row">
                        <label class="label">SKU</label>
                        <input class="input w-full" type="text" name="sku" value="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>" required>
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
                        <input class="input w-full" type="text" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" required>
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
                                <?php if (has_perm('product.create')) { ?>
                                    <button class="btn primary" type="submit">Create</button>
                                <?php } ?>
                            <?php } ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php } ?>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Product List</div>
                <div class="panel-icon bg-green"><i class='bx bx-list-ul'></i></div>
            </div>
            <div class="panel-body">
                <form method="get" class="toolbar" style="grid-template-columns: 1fr 180px auto auto;">
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

                <div class="table-wrap rounded-xl border" style="border-color: var(--border);">
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
                                <?php
                                    $__img = ($hasProdImagePath && !empty($r['image_path'])) ? (string)$r['image_path'] : '';
                                    $__src = '';
                                    if ($__img !== '') {
                                        $__v = @filemtime(__DIR__ . '/' . $__img);
                                        $__src = $__img . ($__v ? ('?v=' . (string)$__v) : '');
                                    }
                                    $__sku = (string)($r['sku'] ?? '');
                                    $__name2 = (string)($r['name'] ?? '');
                                    $__cat = (string)($r['category_name'] ?? '—');
                                    $__price = (string)($r['unit_price'] ?? '0.00');
                                    $__stock = (string)($r['stock_qty'] ?? '0');
                                    $__catId = (int)($r['category_id'] ?? 0);
                                    $__cost = (string)($r['unit_cost'] ?? '0.00');
                                    $__reorder = (string)($r['reorder_level'] ?? '0');
                                ?>
                                <tr class="js-open-modal" tabindex="0" role="button"
                                    data-sku="<?php echo htmlspecialchars($__sku, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-modal-type="product"
                                    data-title="<?php echo htmlspecialchars($__name2, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-subtitle="SKU: <?php echo htmlspecialchars($__sku, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-sku="<?php echo htmlspecialchars($__sku, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-category-id="<?php echo (int)$__catId; ?>"
                                    data-unit-cost="<?php echo htmlspecialchars($__cost, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-unit-price="<?php echo htmlspecialchars($__price, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-reorder-level="<?php echo htmlspecialchars($__reorder, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-meta1-label="Unit Price"
                                    data-meta1-value="<?php echo htmlspecialchars($__price, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-meta2-label="Category / Stock"
                                    data-meta2-value="<?php echo htmlspecialchars($__cat . ' · Stock: ' . $__stock, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-image-src="<?php echo htmlspecialchars($__src, ENT_QUOTES, 'UTF-8'); ?>">
                                    <td>
                                        <?php if ($__src !== '') { ?>
                                            <img src="<?php echo htmlspecialchars($__src, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
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
                                                <form method="post" class="inline" onsubmit="return confirm('Delete this product? This also deletes its movements.');">
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

    <div id="guest-requests"></div>
    <?php if ($__role === 'guest') { ?>
        <div class="content-grid" style="grid-template-columns: 1fr;">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">My Requests</div>
                    <div class="panel-icon bg-green"><i class='bx bx-time-five'></i></div>
                </div>
                <div class="panel-body">
                    <?php if (!$hasGuestRequestsTable || !$hasGuestRequestItemsTable) { ?>
                        <div class="muted">Requests are not available yet.</div>
                    <?php } elseif (count($myRequests) === 0) { ?>
                        <div class="muted">No requests yet.</div>
                    <?php } else { ?>
                        <div style="display:grid; gap: 12px;">
                            <?php foreach ($myRequests as $r) { $rid = (int)($r['id'] ?? 0); ?>
                                <div class="guest-item" style="grid-template-columns: 1fr;">
                                    <div>
                                        <div class="t">Request #<?php echo (int)$rid; ?> · <?php echo htmlspecialchars((string)($r['warehouse_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="m">Status: <?php echo htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php if (!empty($r['note'])) { ?>
                                            <div class="m">Note: <?php echo htmlspecialchars((string)$r['note'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php } ?>
                                        <?php if (!empty($r['decided_reason'])) { ?>
                                            <div class="m">Decision: <?php echo htmlspecialchars((string)$r['decided_reason'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php } ?>

                                        <?php $items = $reqItemsByReq[$rid] ?? []; ?>
                                        <?php if (count($items) > 0) { ?>
                                            <div style="margin-top: 10px; display:grid; gap: 6px;">
                                                <?php foreach ($items as $it) { ?>
                                                    <div class="muted"><?php echo htmlspecialchars((string)($it['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · Qty: <?php echo (int)($it['qty'] ?? 0); ?></div>
                                                <?php } ?>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>

</section>

<div id="ims-detail-modal" class="ims-modal" style="display:none;">
    <div class="ims-modal-backdrop" data-modal-close="1"></div>
    <div class="ims-modal-card" role="dialog" aria-modal="true">
        <div class="ims-modal-head">
            <div class="ims-modal-head-text">
                <div class="ims-modal-title" id="ims-modal-title"></div>
                <div class="ims-modal-subtitle muted" id="ims-modal-subtitle"></div>
            </div>
            <button type="button" class="btn" data-modal-close="1" style="padding:6px 10px;">Close</button>
        </div>
        <div class="ims-modal-body">
            <div class="ims-modal-grid">
                <div class="ims-modal-image">
                    <img id="ims-modal-image" src="" alt="" style="display:none;">
                    <div id="ims-modal-image-empty" class="muted" style="padding:14px;">No image</div>
                </div>
                <div class="ims-modal-details">
                    <div class="ims-modal-row">
                        <div class="ims-modal-label" id="ims-modal-meta1-label"></div>
                        <div class="ims-modal-value" id="ims-modal-meta1-value"></div>
                    </div>
                    <div class="ims-modal-row">
                        <div class="ims-modal-label" id="ims-modal-meta2-label"></div>
                        <div class="ims-modal-value" id="ims-modal-meta2-value"></div>
                    </div>
                </div>
            </div>
            <div id="ims-modal-actions" class="ims-modal-actions"></div>
        </div>
    </div>
</div>

<script>
(() => {
  const sel = document.getElementById('client-product-select');
  if (!sel) return;
  const form = sel.closest('form') || document;
  const skuInput = form.querySelector('input[name="sku"]');
  const nameInput = form.querySelector('input[name="name"]');
  const catSelect = form.querySelector('select[name="category_id"]');
  const costInput = form.querySelector('input[name="unit_cost"]');
  const priceInput = form.querySelector('input[name="unit_price"]');
  const reorderInput = form.querySelector('input[name="reorder_level"]');

  const applySelection = () => {
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    const sku = opt.getAttribute('data-sku') || '';
    const name = opt.getAttribute('data-name') || '';
    const catId = opt.getAttribute('data-category-id') || '';
    const unitCost = opt.getAttribute('data-unit-cost') || '';
    const unitPrice = opt.getAttribute('data-unit-price') || '';
    const reorderLevel = opt.getAttribute('data-reorder-level') || '';

    if (skuInput) skuInput.value = sku;
    if (nameInput) nameInput.value = name;

    if (catSelect) {
      const v = String(catId || '');
      catSelect.value = v === '0' ? '' : v;
    }
    if (costInput) costInput.value = String(unitCost || '0');
    if (priceInput) priceInput.value = String(unitPrice || '0');
    if (reorderInput) reorderInput.value = String(reorderLevel || '0');
  };

  sel.addEventListener('change', applySelection);

  // If an option is already selected (e.g., browser restores form state), apply it.
  if (sel.value) {
    applySelection();
  }
})();
</script>

<script src="../JS/script.js?v=20260225"></script>
</body>
</html>
