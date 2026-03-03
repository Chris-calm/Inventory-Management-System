<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('movement.view');

$flash = null;
$flashType = 'info';

$products = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($res = $conn->query("SELECT id, sku, name, stock_qty, reorder_level FROM products WHERE status = 'active' ORDER BY name ASC")) {
        while ($r = $res->fetch_assoc()) { $products[] = $r; }
        $res->free();
    }
}

$productId = (int)($_POST['product_id'] ?? 0);
$movementType = (string)($_POST['movement_type'] ?? 'in');
$qty = (int)($_POST['qty'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    require_perm('movement.create');
    if (!csrf_validate()) {
        http_response_code(400);
        echo 'Bad Request';
        exit();
    }
    if (!in_array($movementType, ['in', 'out', 'adjust'], true)) {
        $flash = 'Invalid movement type.';
        $flashType = 'error';
    } elseif ($productId <= 0) {
        $flash = 'Please select a product.';
        $flashType = 'error';
    } elseif ($qty <= 0) {
        $flash = 'Quantity must be greater than 0.';
        $flashType = 'error';
    } else {
        $conn->begin_transaction();

        try {
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

            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            $stmt3 = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, qty, note, created_by) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt3) {
                throw new Exception('Failed to record movement.');
            }
            $stmt3->bind_param('isisi', $productId, $movementType, $qty, $note, $createdBy);
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

if ((string)($_GET['msg'] ?? '') === 'ok') {
    $flash = 'Movement saved.';
    $flashType = 'success';
}

$history = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $sql = "SELECT sm.id, sm.movement_type, sm.qty, sm.note, sm.created_at, p.sku, p.name AS product_name, u.username AS created_by FROM stock_movements sm JOIN products p ON p.id = sm.product_id LEFT JOIN users u ON u.id = sm.created_by ORDER BY sm.created_at DESC, sm.id DESC LIMIT 25";
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
                <li class="nav-link"><a href="transactions.php"><i class='bx bx-transfer-alt icon'></i><span class="text nav-text">Stock In/Out</span></a></li>
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
                            <select class="input" name="movement_type">
                                <option value="in" <?php echo $movementType === 'in' ? 'selected' : ''; ?>>Stock In</option>
                                <option value="out" <?php echo $movementType === 'out' ? 'selected' : ''; ?>>Stock Out</option>
                                <option value="adjust" <?php echo $movementType === 'adjust' ? 'selected' : ''; ?>>Adjust (set stock)</option>
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

                    <div class="form-actions">
                        <button class="btn primary" type="submit">Save</button>
                    </div>
                </form>
            </div>
        </div>

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
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($history) === 0) { ?>
                            <tr><td colspan="6" class="muted">No movements yet.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($history as $h) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($h['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)($h['sku'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</td>
                                    <td><?php echo htmlspecialchars((string)($h['movement_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($h['qty'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($h['created_by'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
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
