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

$hasStatusColumn = false;
$hasNotesColumn = false;
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
                        $stmt2 = $conn->prepare("UPDATE categories SET $setParts WHERE id = ?");
                        if ($stmt2) {
                            if ($hasStatusColumn && $hasNotesColumn) {
                                $stmt2->bind_param('ssssi', $name, $description, $status, $notes, $editId);
                            } elseif ($hasStatusColumn) {
                                $stmt2->bind_param('sssi', $name, $description, $status, $editId);
                            } elseif ($hasNotesColumn) {
                                $stmt2->bind_param('sssi', $name, $description, $notes, $editId);
                            } else {
                                $stmt2->bind_param('ssi', $name, $description, $editId);
                            }
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
                        $stmt2 = $conn->prepare("INSERT INTO categories ($cols) VALUES ($vals)");
                        if ($stmt2) {
                            if ($hasStatusColumn && $hasNotesColumn) {
                                $stmt2->bind_param('ssss', $name, $description, $status, $notes);
                            } elseif ($hasStatusColumn) {
                                $stmt2->bind_param('sss', $name, $description, $status);
                            } elseif ($hasNotesColumn) {
                                $stmt2->bind_param('sss', $name, $description, $notes);
                            } else {
                                $stmt2->bind_param('ss', $name, $description);
                            }
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
}

$msg = (string)($_GET['msg'] ?? '');
if ($msg === 'created') { $flash = 'Category created.'; $flashType = 'success'; }
if ($msg === 'updated') { $flash = 'Category updated.'; $flashType = 'success'; }
if ($msg === 'deleted') { $flash = 'Category deleted.'; $flashType = 'success'; }
if ($msg === 'status') { $flash = 'Category status updated.'; $flashType = 'success'; }

$rows = [];

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');
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

    $sql = "SELECT $selectColsList, COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id
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
    <title>Category</title>
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
            <div class="page-title">Category</div>
            <div class="page-subtitle">Manage your product categories</div>
        </div>
        <div class="page-meta">
            <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
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

                <form method="post" class="form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)($action === 'edit' ? $id : 0); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <label class="label">Name</label>
                        <input class="input" type="text" name="name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-row">
                        <label class="label">Description</label>
                        <input class="input" type="text" name="description" value="<?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

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
                    <button class="btn" type="submit">Filter</button>
                </form>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th>Products</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($rows) === 0) { ?>
                            <tr><td colspan="7" class="muted">No categories found.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($rows as $r) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($r['product_count'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <?php if (has_perm('category.edit')) { ?>
                                                <a class="btn" href="category.php?action=edit&id=<?php echo (int)$r['id']; ?>">Edit</a>
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

<script src="script.js?v=20260225"></script>
</body>
</html>
