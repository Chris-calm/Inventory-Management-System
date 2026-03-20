<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('product.view');

$flash = null;
$flashType = 'info';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Bad Request';
    exit();
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

$hasLocationsTable = false;
$hasLocationStocksTable = false;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($stmtTbl = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations'")) {
        $stmtTbl->execute();
        $c = 0;
        $stmtTbl->bind_result($c);
        if ($stmtTbl->fetch()) {
            $hasLocationsTable = ((int)$c) > 0;
        }
        $stmtTbl->close();
    }
    if ($stmtTbl = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'location_stocks'")) {
        $stmtTbl->execute();
        $c = 0;
        $stmtTbl->bind_result($c);
        if ($stmtTbl->fetch()) {
            $hasLocationStocksTable = ((int)$c) > 0;
        }
        $stmtTbl->close();
    }
}

$product = null;
$movements = [];
$stockByLocation = [];

$moveType = (string)($_GET['move_type'] ?? '');
$dateFrom = (string)($_GET['date_from'] ?? '');
$dateTo = (string)($_GET['date_to'] ?? '');
$export = (string)($_GET['export'] ?? '0') === '1';

$allowedMoveTypes = ['in', 'out', 'adjust', 'transfer'];
if ($moveType !== '' && !in_array($moveType, $allowedMoveTypes, true)) {
    $moveType = '';
}

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $selectCols = "p.id, p.sku, p.name, p.category_id, COALESCE(c.name, '—') AS category_name, p.unit_cost, p.unit_price, p.stock_qty, p.reorder_level, p.status, p.created_at, p.updated_at";
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

    $stmt = $conn->prepare("SELECT $selectCols FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $product = $res->fetch_assoc();
        }
        $stmt->close();
    }

    if ($product) {
        if ($hasLocationsTable && $hasLocationStocksTable) {
            $sqlLoc = "SELECT l.id, l.name, COALESCE(ls.qty, 0) AS qty
                        FROM locations l
                        LEFT JOIN location_stocks ls ON ls.location_id = l.id AND ls.product_id = ?
                        WHERE l.status = 'active'
                        ORDER BY l.name ASC, l.id ASC";
            $stmtLoc = $conn->prepare($sqlLoc);
            if ($stmtLoc) {
                $stmtLoc->bind_param('i', $id);
                $stmtLoc->execute();
                $resLoc = $stmtLoc->get_result();
                if ($resLoc) {
                    while ($r = $resLoc->fetch_assoc()) {
                        $stockByLocation[] = $r;
                    }
                }
                $stmtLoc->close();
            }
        }

        $where = ['sm.product_id = ?'];
        $params = [$id];
        $types = 'i';

        if ($moveType !== '') {
            $where[] = 'sm.movement_type = ?';
            $params[] = $moveType;
            $types .= 's';
        }
        if ($dateFrom !== '') {
            $where[] = 'sm.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
            $types .= 's';
        }
        if ($dateTo !== '') {
            $where[] = 'sm.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
            $types .= 's';
        }

        $limit = $export ? 5000 : 100;
        $sql = "SELECT sm.id, sm.movement_type, sm.qty, sm.note, sm.created_at, COALESCE(u.username, '—') AS created_by_username
                FROM stock_movements sm
                LEFT JOIN users u ON u.id = sm.created_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY sm.created_at DESC, sm.id DESC
                LIMIT $limit";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $movements[] = $r;
                }
            }
            $stmt->close();
        }
    }
}

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product_' . (int)$id . '_movements.csv"');
    $out = fopen('php://output', 'w');
    if ($out) {
        fputcsv($out, ['Date', 'Type', 'Qty', 'Note', 'By']);
        foreach ($movements as $m) {
            $t = (string)($m['movement_type'] ?? '');
            $qty = (int)($m['qty'] ?? 0);
            $displayQty = $qty;
            if ($t === 'out') {
                $displayQty = -abs($qty);
            } elseif ($t === 'in') {
                $displayQty = abs($qty);
            }
            fputcsv($out, [
                (string)($m['created_at'] ?? ''),
                $t,
                (string)$displayQty,
                (string)($m['note'] ?? ''),
                (string)($m['created_by_username'] ?? ''),
            ]);
        }
        fclose($out);
    }
    exit();
}

if (!$product) {
    http_response_code(404);
    echo 'Not Found';
    exit();
}

$stockQty = (int)($product['stock_qty'] ?? 0);
$reorderLevel = (int)($product['reorder_level'] ?? 0);
$stockState = 'ok';
if ($stockQty <= 0) {
    $stockState = 'out';
} elseif ($stockQty <= $reorderLevel) {
    $stockState = 'low';
}

$unitCost = is_numeric((string)($product['unit_cost'] ?? '0')) ? (float)$product['unit_cost'] : 0.0;
$stockValue = $stockQty * $unitCost;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Product Details'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<section class="home">
    <div class="page-header">
        <div>
            <div class="page-title">Product Details</div>
            <div class="page-subtitle"><?php echo htmlspecialchars((string)($product['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars((string)($product['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="page-meta">
            <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Overview</div>
                <div class="panel-icon bg-blue"><i class='bx bx-box'></i></div>
            </div>
            <div class="panel-body">
                <?php if ($flash) { ?>
                    <div class="alert <?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <div style="display: grid; grid-template-columns: 120px 1fr; gap: 14px; align-items: start;">
                    <div>
                        <?php if ($hasProdImagePath && !empty($product['image_path'])) { ?>
                            <?php
                                $__img = (string)$product['image_path'];
                                $__v = @filemtime(__DIR__ . '/' . $__img);
                                $__src = $__img . ($__v ? ('?v=' . (string)$__v) : '');
                            ?>
                            <img src="<?php echo htmlspecialchars($__src, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width: 120px; height: 120px; object-fit: cover; border-radius: 14px; border: 1px solid rgba(255,255,255,0.08);">
                        <?php } else { ?>
                            <div class="muted" style="width: 120px; height: 120px; display: grid; place-items: center; border-radius: 14px; border: 1px dashed rgba(255,255,255,0.15);">No image</div>
                        <?php } ?>
                    </div>

                    <div style="display: grid; gap: 10px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <div class="muted">Category</div>
                                <div><?php echo htmlspecialchars((string)($product['category_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div>
                                <div class="muted">Status</div>
                                <div><?php echo htmlspecialchars((string)($product['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if ($hasProdArchivedAt && !empty($product['archived_at'])) { ?> <span class="muted">(archived)</span><?php } ?></div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                            <div>
                                <div class="muted">Stock</div>
                                <div>
                                    <?php echo (int)$stockQty; ?>
                                    <?php if ($stockState === 'out') { ?>
                                        <span class="muted">(out)</span>
                                    <?php } elseif ($stockState === 'low') { ?>
                                        <span class="muted">(low)</span>
                                    <?php } ?>
                                </div>
                            </div>
                            <div>
                                <div class="muted">Reorder Level</div>
                                <div><?php echo (int)$reorderLevel; ?></div>
                            </div>
                            <div>
                                <div class="muted">Stock Value</div>
                                <div><?php echo htmlspecialchars(number_format($stockValue, 2), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <div class="muted">Unit Cost</div>
                                <div><?php echo htmlspecialchars(number_format((float)($product['unit_cost'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div>
                                <div class="muted">Unit Price</div>
                                <div><?php echo htmlspecialchars(number_format((float)($product['unit_price'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 4px;">
                            <a class="btn" href="product.php">Back</a>
                            <?php if (has_perm('product.edit')) { ?>
                                <a class="btn primary" href="product.php?action=edit&id=<?php echo (int)$product['id']; ?>">Edit</a>
                            <?php } ?>
                            <a class="btn" href="transactions.php">Stock In/Out</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Stock by Location</div>
                <div class="panel-icon bg-purple"><i class='bx bx-map-pin'></i></div>
            </div>
            <div class="panel-body">
                <?php if (!$hasLocationsTable || !$hasLocationStocksTable) { ?>
                    <div class="muted">Location stock tracking is not enabled yet.</div>
                <?php } elseif (count($stockByLocation) === 0) { ?>
                    <div class="muted">No locations found.</div>
                <?php } else { ?>
                    <?php foreach ($stockByLocation as $ls) { ?>
                        <div class="panel-row">
                            <div><?php echo htmlspecialchars((string)($ls['name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="num"><?php echo (int)($ls['qty'] ?? 0); ?></div>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Movement History</div>
                <div class="panel-icon bg-green"><i class='bx bx-transfer-alt'></i></div>
            </div>
            <div class="panel-body">
                <form method="get" class="toolbar" style="grid-template-columns: 1fr 1fr 1fr auto; align-items: end;">
                    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                    <div>
                        <label class="label">From</label>
                        <input class="input" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="label">To</label>
                        <input class="input" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="label">Type</label>
                        <select class="input" name="move_type">
                            <option value="" <?php echo $moveType === '' ? 'selected' : ''; ?>>All</option>
                            <option value="in" <?php echo $moveType === 'in' ? 'selected' : ''; ?>>In</option>
                            <option value="out" <?php echo $moveType === 'out' ? 'selected' : ''; ?>>Out</option>
                            <option value="adjust" <?php echo $moveType === 'adjust' ? 'selected' : ''; ?>>Adjust</option>
                            <option value="transfer" <?php echo $moveType === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                        </select>
                    </div>
                    <div class="form-actions" style="margin: 0;">
                        <button class="btn" type="submit">Apply</button>
                        <?php
                            $exportParams = ['id' => (int)$id, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'move_type' => $moveType, 'export' => '1'];
                        ?>
                        <a class="btn" href="product_view.php?<?php echo htmlspecialchars(http_build_query($exportParams), ENT_QUOTES, 'UTF-8'); ?>">CSV</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Note</th>
                            <th>By</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($movements) === 0) { ?>
                            <tr><td colspan="5" class="muted">No movements found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($movements as $m) { ?>
                                <?php
                                    $t = (string)($m['movement_type'] ?? '');
                                    $qty = (int)($m['qty'] ?? 0);
                                    $displayQty = $qty;
                                    if ($t === 'out') {
                                        $displayQty = -abs($qty);
                                    } elseif ($t === 'in') {
                                        $displayQty = abs($qty);
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($m['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)$displayQty; ?></td>
                                    <td><?php echo htmlspecialchars((string)($m['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($m['created_by_username'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
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
