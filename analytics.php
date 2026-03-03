<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();
require_perm('analytics.view');

$trendLabels = [];
$trendValues = [];
$topCategoryLabels = [];
$topCategoryValues = [];

$lowStockRows = [];
$outStockRows = [];

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    if ($res = $conn->query("SELECT DATE_FORMAT(created_at, '%b') AS m, DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM stock_movements WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) GROUP BY ym ORDER BY ym ASC")) {
        while ($r = $res->fetch_assoc()) {
            $trendLabels[] = (string)($r['m'] ?? '');
            $trendValues[] = (int)($r['c'] ?? 0);
        }
        $res->free();
    }

    if ($res = $conn->query("SELECT c.name, COUNT(p.id) AS c FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id ORDER BY c DESC, c.name ASC LIMIT 8")) {
        while ($r = $res->fetch_assoc()) {
            $topCategoryLabels[] = (string)($r['name'] ?? '');
            $topCategoryValues[] = (int)($r['c'] ?? 0);
        }
        $res->free();
    }

    if ($res = $conn->query("SELECT p.sku, p.name, COALESCE(c.name,'—') AS category_name, p.stock_qty, p.reorder_level FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status='active' AND p.stock_qty > 0 AND p.stock_qty <= p.reorder_level ORDER BY p.stock_qty ASC, p.name ASC LIMIT 50")) {
        while ($r = $res->fetch_assoc()) { $lowStockRows[] = $r; }
        $res->free();
    }

    if ($res = $conn->query("SELECT p.sku, p.name, COALESCE(c.name,'—') AS category_name, p.stock_qty, p.reorder_level FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status='active' AND p.stock_qty <= 0 ORDER BY p.name ASC LIMIT 50")) {
        while ($r = $res->fetch_assoc()) { $outStockRows[] = $r; }
        $res->free();
    }
}

if (count($trendLabels) === 0) {
    $trendLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
    $trendValues = [0, 0, 0, 0, 0, 0];
}
if (count($topCategoryLabels) === 0) {
    $topCategoryLabels = ['No Data'];
    $topCategoryValues = [0];
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
    <title>Analytics</title>
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
            <div class="page-title">Analytics</div>
            <div class="page-subtitle">Reports and charts based on your inventory data</div>
        </div>
        <div class="page-meta">
            <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="charts-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Movements per Month</div>
                <div class="panel-icon bg-blue"><i class='bx bx-trending-up'></i></div>
            </div>
            <div class="panel-body">
                <canvas id="movementsChart" height="120"></canvas>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Products per Category</div>
                <div class="panel-icon bg-green"><i class='bx bx-bar-chart'></i></div>
            </div>
            <div class="panel-body">
                <canvas id="categoryChart" height="120"></canvas>
            </div>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Low Stock Items</div>
                <div class="panel-icon bg-orange"><i class='bx bx-error-circle'></i></div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Reorder</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($lowStockRows) === 0) { ?>
                            <tr><td colspan="5" class="muted">No low stock items.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($lowStockRows as $r) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['sku'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($r['stock_qty'] ?? 0); ?></td>
                                    <td><?php echo (int)($r['reorder_level'] ?? 0); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Out of Stock</div>
                <div class="panel-icon bg-purple"><i class='bx bx-block'></i></div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Reorder</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($outStockRows) === 0) { ?>
                            <tr><td colspan="5" class="muted">No out of stock items.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($outStockRows as $r) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$r['sku'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (int)($r['stock_qty'] ?? 0); ?></td>
                                    <td><?php echo (int)($r['reorder_level'] ?? 0); ?></td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="script.js?v=20260225"></script>
<script>
    const mLabels = <?php echo json_encode(array_values($trendLabels)); ?>;
    const mValues = <?php echo json_encode(array_values($trendValues)); ?>;
    const cLabels = <?php echo json_encode(array_values($topCategoryLabels)); ?>;
    const cValues = <?php echo json_encode(array_values($topCategoryValues)); ?>;

    const movementsCtx = document.getElementById('movementsChart');
    if (movementsCtx) {
        new Chart(movementsCtx, {
            type: 'line',
            data: {
                labels: mLabels,
                datasets: [{
                    label: 'Movements',
                    data: mValues,
                    borderColor: '#695CFE',
                    backgroundColor: 'rgba(105, 92, 254, 0.15)',
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });
    }

    const categoryCtx = document.getElementById('categoryChart');
    if (categoryCtx) {
        new Chart(categoryCtx, {
            type: 'bar',
            data: {
                labels: cLabels,
                datasets: [{
                    label: 'Products',
                    data: cValues,
                    backgroundColor: '#4b7bec',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });
    }
</script>
</body>
</html>
