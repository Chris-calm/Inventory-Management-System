<?php
session_start();

require_once __DIR__ . '/security.php';

if (!isset($_SESSION["username"])) {
    header("Location: index.php");
    exit();
}

require_perm('dashboard.view');

require_once __DIR__ . '/db.php';

$stats = [
    'products' => 0,
    'categories' => 0,
    'low_stock' => 0,
    'movements' => 0,
    'in_stock' => 0,
    'out_of_stock' => 0,
    'products_without_category' => 0,
    'most_used_category' => '—',
    'inactive_categories' => 0,
];

$recentActivity = [];
$recentProducts = [];
$trendLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
$trendValues = [12, 18, 15, 22, 20, 9];
$topCategoryLabels = ['Category A', 'Category B', 'Category C', 'Category D'];
$topCategoryValues = [7, 4, 3, 2];

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $queries = [
        'products' => "SELECT COUNT(*) AS c FROM products",
        'categories' => "SELECT COUNT(*) AS c FROM categories",
        'low_stock' => "SELECT COUNT(*) AS c FROM products WHERE status = 'active' AND stock_qty <= reorder_level",
        'movements' => "SELECT COUNT(*) AS c FROM stock_movements",
        'in_stock' => "SELECT COUNT(*) AS c FROM products WHERE status = 'active' AND stock_qty > 0",
        'out_of_stock' => "SELECT COUNT(*) AS c FROM products WHERE status = 'active' AND stock_qty <= 0",
        'products_without_category' => "SELECT COUNT(*) AS c FROM products WHERE category_id IS NULL",
        'inactive_categories' => "SELECT COUNT(*) AS c FROM categories c LEFT JOIN products p ON p.category_id = c.id WHERE p.id IS NULL",
    ];

    foreach ($queries as $key => $sql) {
        if ($res = $conn->query($sql)) {
            if ($row = $res->fetch_assoc()) {
                $stats[$key] = (int)($row['c'] ?? 0);
            }
            $res->free();
        }
    }

    if ($res = $conn->query("SELECT c.name, COUNT(p.id) AS c FROM categories c JOIN products p ON p.category_id = c.id GROUP BY c.id ORDER BY c DESC, c.name ASC LIMIT 1")) {
        if ($row = $res->fetch_assoc()) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $stats['most_used_category'] = $name;
            }
        }
        $res->free();
    }

    if ($res = $conn->query("SELECT sm.movement_type, sm.qty, sm.created_at, p.name AS product_name FROM stock_movements sm JOIN products p ON p.id = sm.product_id ORDER BY sm.created_at DESC, sm.id DESC LIMIT 5")) {
        while ($row = $res->fetch_assoc()) {
            $recentActivity[] = [
                'type' => (string)($row['movement_type'] ?? ''),
                'qty' => (int)($row['qty'] ?? 0),
                'product' => (string)($row['product_name'] ?? ''),
                'time' => (string)($row['created_at'] ?? ''),
            ];
        }
        $res->free();
    }

    if ($res = $conn->query("SELECT p.name AS product_name, COALESCE(c.name, '—') AS category_name, p.stock_qty, p.reorder_level, p.status FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.created_at DESC, p.id DESC LIMIT 7")) {
        while ($row = $res->fetch_assoc()) {
            $recentProducts[] = [
                'product' => (string)($row['product_name'] ?? ''),
                'category' => (string)($row['category_name'] ?? '—'),
                'stock' => (int)($row['stock_qty'] ?? 0),
                'reorder' => (int)($row['reorder_level'] ?? 0),
                'status' => (string)($row['status'] ?? 'active'),
            ];
        }
        $res->free();
    }

    $trendLabels = [];
    $trendValues = [];
    if ($res = $conn->query("SELECT DATE_FORMAT(created_at, '%b') AS m, DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM stock_movements WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) GROUP BY ym ORDER BY ym ASC")) {
        while ($row = $res->fetch_assoc()) {
            $trendLabels[] = (string)($row['m'] ?? '');
            $trendValues[] = (int)($row['c'] ?? 0);
        }
        $res->free();
    }
    if (count($trendLabels) === 0) {
        $trendLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
        $trendValues = [12, 18, 15, 22, 20, 9];
    }

    $topCategoryLabels = [];
    $topCategoryValues = [];
    if ($res = $conn->query("SELECT c.name, COUNT(p.id) AS c FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id ORDER BY c DESC, c.name ASC LIMIT 6")) {
        while ($row = $res->fetch_assoc()) {
            $topCategoryLabels[] = (string)($row['name'] ?? '');
            $topCategoryValues[] = (int)($row['c'] ?? 0);
        }
        $res->free();
    }
    if (count($topCategoryLabels) === 0) {
        $topCategoryLabels = ['Category A', 'Category B', 'Category C', 'Category D'];
        $topCategoryValues = [7, 4, 3, 2];
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
        <title>Dashboard</title>
    </head>
    <body>
        <nav class="sidebar close">
            <header>
                <div class="image-text">
                    <span class="image">
                        <img src="CUBE3.png" alt="logo">
                    </span>
                    <div class="text header">
                        <span class="name">CUBE</span>
                        <span class="proffesion">Company</span>
                    </div>
                </div>
                <i class='bx bx-chevron-right toggle'></i>
            </header>
            <div class="menu-bar">
                <div class="menu">
                    <li class="search-box">
                            <i class='bx bx-search icon'></i>
                            <input type="text" placeholder="Search...">
                    </li>
                    <ul class="menu-link">
                        <li class="nav-link">
                            <a href="dashboard.php">
                                <i class='bx bx-home-alt icon'></i>
                                <span class="text nav-text">Dashboard</span>
                            </a>
                        </li>
                        <li class="nav-link">
                            <a href="analytics.php">
                                <i class='bx bx-pie-chart-alt icon'></i>
                                <span class="text nav-text">Analytics</span>
                            </a>
                        </li>
                        <li class="nav-link">
                            <a href="category.php">
                                <i class='bx bxs-category-alt icon'></i>
                                <span class="text nav-text">Category</span>
                            </a>
                        </li>
                        <li class="nav-link">
                           <a href="product.php">
                                <i class='bx bxl-product-hunt icon'></i>
                                 <span class="text nav-text">Product</span>
                            </a>
                        </li>
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
                    <li class="nav-link">
                        <a href="logout.php">
                             <i class='bx bx-log-out icon'></i>
                              <span class="text nav-text">Logout</span>
                         </a>
                     </li>
                     <li class="mode">
                        <div class="moon-sun">
                            <i class='bx bx-moon icon moon'></i>
                            <i class='bx bx-sun icon sun'></i>
                        </div>
                        <span class="mode-text text">Dark Mode</span>

                        <div class="toggle-switch">
                            <span class="switch"></span>
                        </div>
                     </li>
                     <?php if (has_perm('rbac.assign')) { ?>
                         <li class="nav-link">
                            <a href="admin.php">
                                 <i class='bx bxl-product-hunt icon'></i>
                                  <span class="text nav-text">Admin</span>
                             </a>
                         </li>
                     <?php } ?>
                </div>
            </div>
        </nav>

        <section class="home">
            <div class="page-header">
                <div>
                    <div class="page-title">Dashboard</div>
                    <div class="page-subtitle">Overview of your inventory system</div>
                </div>
                <div class="page-meta">
                    <div class="meta-pill">Signed in as: <?php echo htmlspecialchars((string)($_SESSION["username"] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Products</div>
                    <div class="kpi-value"><?php echo (int)$stats['products']; ?></div>
                    <div class="kpi-icon bg-blue"><i class='bx bxl-product-hunt'></i></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Categories</div>
                    <div class="kpi-value"><?php echo (int)$stats['categories']; ?></div>
                    <div class="kpi-icon bg-green"><i class='bx bxs-category-alt'></i></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Low Stock</div>
                    <div class="kpi-value"><?php echo (int)$stats['low_stock']; ?></div>
                    <div class="kpi-icon bg-purple"><i class='bx bx-error-circle'></i></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Stock Movements</div>
                    <div class="kpi-value"><?php echo (int)$stats['movements']; ?></div>
                    <div class="kpi-icon bg-orange"><i class='bx bx-transfer-alt'></i></div>
                </div>
            </div>

            <div class="section-title">System Data Overview</div>
            <div class="summary-grid">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Inventory Summary</div>
                        <div class="panel-icon bg-green"><i class='bx bx-package'></i></div>
                    </div>
                    <div class="panel-body">
                        <div class="panel-row"><span>Total Products</span><span class="num"><?php echo (int)$stats['products']; ?></span></div>
                        <div class="panel-row"><span>In Stock</span><span class="num"><?php echo (int)$stats['in_stock']; ?></span></div>
                        <div class="panel-row"><span>Out of Stock</span><span class="num"><?php echo (int)$stats['out_of_stock']; ?></span></div>
                        <div class="panel-row"><span>Low Stock Items</span><span class="num"><?php echo (int)$stats['low_stock']; ?></span></div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Category Breakdown</div>
                        <div class="panel-icon bg-blue"><i class='bx bxs-category-alt'></i></div>
                    </div>
                    <div class="panel-body">
                        <div class="panel-row"><span>Total Categories</span><span class="num"><?php echo (int)$stats['categories']; ?></span></div>
                        <div class="panel-row"><span>Most Used Category</span><span class="muted"><?php echo htmlspecialchars((string)$stats['most_used_category'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="panel-row"><span>Products Without Category</span><span class="num"><?php echo (int)$stats['products_without_category']; ?></span></div>
                        <div class="panel-row"><span>Inactive Categories</span><span class="num"><?php echo (int)$stats['inactive_categories']; ?></span></div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Recent Activity</div>
                        <div class="panel-icon bg-purple"><i class='bx bx-time-five'></i></div>
                    </div>
                    <div class="panel-body">
                        <?php if (count($recentActivity) === 0) { ?>
                            <div class="activity-item">
                                <div class="activity-dot bg-blue"></div>
                                <div>
                                    <div class="activity-title">No recent activity</div>
                                    <div class="activity-time">Record stock in/out to populate this</div>
                                </div>
                            </div>
                        <?php } else { ?>
                            <?php foreach ($recentActivity as $a) {
                                $dot = 'bg-blue';
                                if ($a['type'] === 'in') { $dot = 'bg-green'; }
                                if ($a['type'] === 'out') { $dot = 'bg-orange'; }
                                if ($a['type'] === 'adjust') { $dot = 'bg-purple'; }
                            ?>
                                <div class="activity-item">
                                    <div class="activity-dot <?php echo $dot; ?>"></div>
                                    <div>
                                        <div class="activity-title"><?php echo htmlspecialchars($a['product'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($a['type'], ENT_QUOTES, 'UTF-8'); ?> <?php echo (int)$a['qty']; ?>)</div>
                                        <div class="activity-time"><?php echo htmlspecialchars($a['time'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Quick Actions</div>
                        <div class="panel-icon bg-orange"><i class='bx bx-bolt-circle'></i></div>
                    </div>
                    <div class="panel-body">
                        <div class="quick-actions">
                            <a class="qa" href="product.php"><i class='bx bx-plus'></i><span>Add Product</span></a>
                            <a class="qa" href="category.php"><i class='bx bx-folder-plus'></i><span>Add Category</span></a>
                            <a class="qa" href="analytics.php"><i class='bx bx-line-chart'></i><span>View Analytics</span></a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Stock Trend (Demo)</div>
                        <div class="panel-icon bg-blue"><i class='bx bx-trending-up'></i></div>
                    </div>
                    <div class="panel-body">
                        <canvas id="trendChart" height="120"></canvas>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Top Categories (Demo)</div>
                        <div class="panel-icon bg-green"><i class='bx bx-bar-chart'></i></div>
                    </div>
                    <div class="panel-body">
                        <canvas id="categoryChart" height="120"></canvas>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Recent Products (Demo)</div>
                    <div class="panel-icon bg-purple"><i class='bx bx-list-ul'></i></div>
                </div>
                <div class="panel-body">
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentProducts) === 0) { ?>
                                    <tr>
                                        <td colspan="4" class="muted">No products found.</td>
                                    </tr>
                                <?php } else { ?>
                                    <?php foreach ($recentProducts as $p) {
                                        $stock = (int)$p['stock'];
                                        $reorder = (int)$p['reorder'];
                                        $statusText = 'OK';
                                        if ($stock <= 0) { $statusText = 'Out of Stock'; }
                                        else if ($stock <= $reorder) { $statusText = 'Low Stock'; }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($p['product'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($p['category'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo $stock; ?></td>
                                            <td><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script src="script.js?v=20260225"></script>
        <script>
            const trendCtx = document.getElementById('trendChart');
            if (trendCtx) {
                const trendLabels = <?php echo json_encode(array_values($trendLabels)); ?>;
                const trendValues = <?php echo json_encode(array_values($trendValues)); ?>;
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [{
                            label: 'Stock movements',
                            data: trendValues,
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
                const catLabels = <?php echo json_encode(array_values($topCategoryLabels)); ?>;
                const catValues = <?php echo json_encode(array_values($topCategoryValues)); ?>;
                new Chart(categoryCtx, {
                    type: 'bar',
                    data: {
                        labels: catLabels,
                        datasets: [{
                            label: 'Products',
                            data: catValues,
                            backgroundColor: ['#4b7bec', '#2ecc71', '#a55eea', '#fa8231'],
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
