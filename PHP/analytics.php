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
    <?php $pageTitle = 'Analytics'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

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
<script src="../JS/script.js?v=20260225"></script>
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
