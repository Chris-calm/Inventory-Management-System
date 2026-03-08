<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('product.view');

$flash = null;
$flashType = 'info';

$action = (string)($_GET['action'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$sku = '';
$name = '';
$categoryId = '';
$unitCost = '0.00';
$unitPrice = '0.00';
$reorderLevel = '5';
$status = 'active';

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

    $catSql = $hasCatStatus
        ? "SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC"
        : "SELECT id, name FROM categories ORDER BY name ASC";

    if ($res = $conn->query($catSql)) {
        while ($r = $res->fetch_assoc()) {
            $categories[] = $r;
        }
        $res->free();
    }
}

if ($action === 'edit') {
    require_perm('product.edit');
}

if ($action === 'edit' && $id > 0 && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $stmt = $conn->prepare("SELECT sku, name, category_id, unit_cost, unit_price, reorder_level, status FROM products WHERE id = ? LIMIT 1");
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

        $catVal = $categoryId === '' ? null : (int)$categoryId;
        $costVal = is_numeric($unitCost) ? (float)$unitCost : 0.0;
        $priceVal = is_numeric($unitPrice) ? (float)$unitPrice : 0.0;
        $reorderVal = is_numeric($reorderLevel) ? (int)$reorderLevel : 5;

        if ($sku === '' || $name === '') {
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
                            $stmt2 = $conn->prepare("UPDATE products SET sku = ?, name = ?, category_id = NULL, unit_cost = ?, unit_price = ?, reorder_level = ?, status = ? WHERE id = ?");
                            if ($stmt2) {
                                $stmt2->bind_param('ssddisi', $sku, $name, $costVal, $priceVal, $reorderVal, $status, $editId);
                                $ok = $stmt2->execute();
                                $stmt2->close();
                                if ($ok) { header('Location: product.php?msg=updated'); exit(); }
                            }
                        } else {
                            $stmt2 = $conn->prepare("UPDATE products SET sku = ?, name = ?, category_id = ?, unit_cost = ?, unit_price = ?, reorder_level = ?, status = ? WHERE id = ?");
                            if ($stmt2) {
                                $stmt2->bind_param('ssiddisi', $sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $status, $editId);
                                $ok = $stmt2->execute();
                                $stmt2->close();
                                if ($ok) { header('Location: product.php?msg=updated'); exit(); }
                            }
                        }
                        $flash = 'Failed to update product.';
                        $flashType = 'error';
                    } else {
                        if ($catVal === null) {
                            $stmt2 = $conn->prepare("INSERT INTO products (sku, name, category_id, unit_cost, unit_price, stock_qty, reorder_level, status) VALUES (?, ?, NULL, ?, ?, 0, ?, ?)");
                            if ($stmt2) {
                                $stmt2->bind_param('ssddis', $sku, $name, $costVal, $priceVal, $reorderVal, $status);
                                $ok = $stmt2->execute();
                                $stmt2->close();
                                if ($ok) { header('Location: product.php?msg=created'); exit(); }
                            }
                        } else {
                            $stmt2 = $conn->prepare("INSERT INTO products (sku, name, category_id, unit_cost, unit_price, stock_qty, reorder_level, status) VALUES (?, ?, ?, ?, ?, 0, ?, ?)");
                            if ($stmt2) {
                                $stmt2->bind_param('ssiddis', $sku, $name, $catVal, $costVal, $priceVal, $reorderVal, $status);
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
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') { $flash = 'Product created.'; $flashType = 'success'; }
if ($msg === 'updated') { $flash = 'Product updated.'; $flashType = 'success'; }
if ($msg === 'deleted') { $flash = 'Product deleted.'; $flashType = 'success'; }

$q = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['filter'] ?? '');

$rows = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
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

    $sql = "SELECT p.id, p.sku, p.name, COALESCE(c.name, '—') AS category_name, p.stock_qty, p.reorder_level, p.status, p.created_at FROM products p LEFT JOIN categories c ON c.id = p.category_id";
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

                <form method="post" class="form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)($action === 'edit' ? $id : 0); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <label class="label">SKU</label>
                        <input class="input" type="text" name="sku" value="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
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
                            <?php if (has_perm('product.create')) { ?>
                                <button class="btn primary" type="submit">Create</button>
                            <?php } ?>
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
                    <button class="btn" type="submit">Apply</button>
                    <a class="btn" href="product.php">Reset</a>
                </form>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
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
                            <tr><td colspan="8" class="muted">No products found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($rows as $r) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['sku'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($r['stock_qty'] ?? 0); ?></td>
                                    <td><?php echo (int)($r['reorder_level'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <?php if (has_perm('product.edit')) { ?>
                                                <a class="btn" href="product.php?action=edit&id=<?php echo (int)$r['id']; ?>">Edit</a>
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
</section>

<script src="script.js?v=20260225"></script>
</body>
</html>
