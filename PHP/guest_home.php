<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

require_login();

$role = (string)($_SESSION['role'] ?? '');
if ($role !== 'guest') {
    header('Location: dashboard.php');
    exit();
}

if (!has_perm('location.view') && !has_perm('product.view')) {
    http_response_code(403);
    echo 'Forbidden';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Guest'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<section class="home">
    <div class="page-header" style="max-width: 1120px; margin: 0 auto; padding-left: 0; padding-right: 0;">
        <div>
            <div class="page-title">Guest Portal</div>
            <div class="page-subtitle">Browse available warehouses and product pricing</div>
        </div>
        <div class="page-meta">
            <?php require __DIR__ . '/partials/topbar.php'; ?>
        </div>
    </div>

    <div class="content-grid" style="max-width: 1120px; margin: 0 auto; padding-left: 0; padding-right: 0;">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Warehouses</div>
                <div class="panel-icon bg-blue"><i class='bx bx-map-pin'></i></div>
            </div>
            <div class="panel-body">
                <div class="muted" style="margin-bottom: 12px;">View available storage locations</div>
                <?php if (has_perm('location.view')) { ?>
                    <a class="btn primary" href="locations.php">Browse Warehouses</a>
                <?php } else { ?>
                    <div class="muted">Not available</div>
                <?php } ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Products & Pricing</div>
                <div class="panel-icon bg-green"><i class='bx bxl-product-hunt'></i></div>
            </div>
            <div class="panel-body">
                <div class="muted" style="margin-bottom: 12px;">Browse products and unit prices</div>
                <?php if (has_perm('product.view')) { ?>
                    <a class="btn primary" href="product.php">Browse Products</a>
                <?php } else { ?>
                    <div class="muted">Not available</div>
                <?php } ?>
            </div>
        </div>
    </div>
</section>
</body>
</html>
