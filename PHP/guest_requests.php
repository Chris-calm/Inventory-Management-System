<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications_lib.php';

require_login();
require_perm('movement.approve');

$flash = null;
$flashType = 'info';

try {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $hasOccupiedColumn = false;
        if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND COLUMN_NAME = 'is_occupied'")) {
            $stmtCol->execute();
            $c = 0;
            $stmtCol->bind_result($c);
            if ($stmtCol->fetch()) {
                $hasOccupiedColumn = ((int)$c) > 0;
            }
            $stmtCol->close();
        }
        if (!$hasOccupiedColumn) {
            try {
                $conn->query("ALTER TABLE locations ADD COLUMN is_occupied TINYINT(1) NOT NULL DEFAULT 0");
                $conn->query("ALTER TABLE locations ADD COLUMN occupied_request_id INT NULL");
                $conn->query("ALTER TABLE locations ADD COLUMN occupied_at TIMESTAMP NULL");
            } catch (Throwable $e) {
            }
        }

        $conn->query("CREATE TABLE IF NOT EXISTS guest_item_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            guest_user_id INT NOT NULL,
            location_id INT NOT NULL,
            warehouse_name VARCHAR(190) NOT NULL,
            warehouse_image_src VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at TIMESTAMP NULL,
            decided_by INT NULL,
            decided_reason VARCHAR(255) NULL,
            INDEX idx_guest_item_requests_status (status, created_at),
            INDEX idx_guest_item_requests_guest (guest_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $hasLocationIdCol = false;
        if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_item_requests' AND COLUMN_NAME = 'location_id'")) {
            $stmtCol->execute();
            $c = 0;
            $stmtCol->bind_result($c);
            if ($stmtCol->fetch()) {
                $hasLocationIdCol = ((int)$c) > 0;
            }
            $stmtCol->close();
        }
        if (!$hasLocationIdCol) {
            $conn->query("ALTER TABLE guest_item_requests ADD COLUMN location_id INT NOT NULL DEFAULT 0 AFTER guest_user_id");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS guest_item_request_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            sku VARCHAR(80) NOT NULL,
            name VARCHAR(160) NOT NULL,
            qty INT NOT NULL,
            INDEX idx_guest_item_request_items_req (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }

    $action = (string)($_POST['action'] ?? '');
    $rid = (int)($_POST['request_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = null;
    } else {
        $reason = mb_substr($reason, 0, 255);
    }

    if (($action === 'approve' || $action === 'reject') && $rid > 0) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, guest_user_id, location_id, warehouse_name, status FROM guest_item_requests WHERE id = ? LIMIT 1");
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

            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $decidedBy = (int)($_SESSION['user_id'] ?? 0);

            if ($newStatus === 'approved') {
                $locId = (int)($row['location_id'] ?? 0);
                if ($locId <= 0) {
                    throw new Exception('invalid_location');
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

                $title2 = $newStatus === 'approved' ? 'Guest request approved' : 'Guest request rejected';
                $msg2 = 'Request #' . (string)$rid . ' was ' . $newStatus . '.';
                notifications_create($conn, $decidedBy, $title2, $msg2, 'product.php#guest-requests-approvals', $newStatus === 'approved' ? 'success' : 'warning');
            } catch (Throwable $e) {
            }

            $conn->commit();
            header('Location: guest_requests.php?msg=' . ($newStatus === 'approved' ? 'approved' : 'rejected'));
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $m = $e->getMessage();
            $flash = $m === 'occupied' ? 'Cannot approve: warehouse is already occupied.' : 'Failed to update request.';
            $flashType = 'error';
        }
    }
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'approved') { $flash = 'Request approved.'; $flashType = 'success'; }
if ($msg === 'rejected') { $flash = 'Request rejected.'; $flashType = 'success'; }

$pending = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $sql = "SELECT r.id, r.warehouse_name, r.note, r.created_at, COALESCE(u.username, 'Guest') AS guest_name
            FROM guest_item_requests r
            LEFT JOIN users u ON u.id = r.guest_user_id
            WHERE r.status = 'pending'
            ORDER BY r.created_at DESC, r.id DESC";
    if ($res = $conn->query($sql)) {
        while ($r = $res->fetch_assoc()) {
            $pending[] = $r;
        }
        $res->free();
    }
}

$itemsByReq = [];
if (count($pending) > 0 && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $ids = array_map(fn($r) => (int)($r['id'] ?? 0), $pending);
    $ids = array_values(array_filter($ids, fn($x) => $x > 0));
    if (count($ids) > 0) {
        $idList = implode(',', $ids);
        $sql = "SELECT request_id, sku, name, qty FROM guest_item_request_items WHERE request_id IN ($idList) ORDER BY id ASC";
        if ($res = $conn->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                $rid = (int)($r['request_id'] ?? 0);
                if (!isset($itemsByReq[$rid])) {
                    $itemsByReq[$rid] = [];
                }
                $itemsByReq[$rid][] = $r;
            }
            $res->free();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Guest Requests'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Guest Requests</div>
            <div class="page-subtitle">Review guest item requests and approve/reject them.</div>
        </div>
        <div class="page-meta">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
        </div>
    </div>

    <div class="content-grid" style="grid-template-columns: 1fr;">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Pending Requests</div>
                <div class="panel-icon bg-green"><i class='bx bx-check-shield'></i></div>
            </div>
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if (count($pending) === 0) { ?>
                    <div class="muted">No pending requests.</div>
                <?php } else { ?>
                    <div style="display:grid; gap: 12px;">
                        <?php foreach ($pending as $r) { $rid = (int)($r['id'] ?? 0); ?>
                            <div class="guest-item" style="grid-template-columns: 1fr;">
                                <div>
                                    <div class="t">Request #<?php echo (int)$rid; ?> · <?php echo htmlspecialchars((string)($r['guest_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="m">Warehouse: <?php echo htmlspecialchars((string)($r['warehouse_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($r['note'])) { ?>
                                        <div class="m">Note: <?php echo htmlspecialchars((string)$r['note'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php } ?>

                                    <?php $items = $itemsByReq[$rid] ?? []; ?>
                                    <?php if (count($items) > 0) { ?>
                                        <div style="margin-top: 10px; display:grid; gap: 6px;">
                                            <?php foreach ($items as $it) { ?>
                                                <div class="muted"><?php echo htmlspecialchars((string)($it['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · Qty: <?php echo (int)($it['qty'] ?? 0); ?></div>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>

                                    <form method="post" style="margin-top: 12px; display:flex; gap: 10px; flex-wrap: wrap;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$rid; ?>">
                                        <input class="input" type="text" name="reason" placeholder="Reason (optional)" style="min-width: 240px;">
                                        <button class="btn primary" type="submit" name="action" value="approve">Approve</button>
                                        <button class="btn danger" type="submit" name="action" value="reject">Reject</button>
                                    </form>
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
