<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$__current = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$__username = (string)($_SESSION['username'] ?? '');
$__role = (string)($_SESSION['role'] ?? ($_SESSION['role_name'] ?? ''));
$__userId = (int)($_SESSION['user_id'] ?? 0);

$__avatar = function_exists('user_avatar_url') ? user_avatar_url(isset($conn) && $conn instanceof mysqli ? $conn : null, $__userId, '../CUBE3.png') : '../CUBE3.png';
$__sidebarClass = $__role === 'guest' ? 'sidebar' : 'sidebar close';
?>
<nav class="<?php echo htmlspecialchars($__sidebarClass, ENT_QUOTES, 'UTF-8'); ?>">
    <header>
        <div class="profile-block">
            <div class="brand-row">
                <span class="brand-logo"><img src="../CUBE3.png" alt="logo"></span>
                <div class="brand-text">
                    <div class="brand-title">CUBECompany</div>
                </div>
            </div>
            <div class="user-card">
                <div class="user-avatar"><img src="<?php echo htmlspecialchars($__avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="user"></div>
                <div class="user-name"><?php echo htmlspecialchars($__username, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($__role !== '') { ?><div class="user-role"><?php echo htmlspecialchars($__role, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
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
                <?php if ($__role === 'guest') { ?>
                    <li class="nav-link <?php echo $__current === 'guest_home.php' ? 'active' : ''; ?>">
                        <a href="guest_home.php">
                            <i class='bx bx-user icon'></i>
                            <span class="text nav-text">Guest Portal</span>
                        </a>
                    </li>

                    <?php if (function_exists('has_perm') && has_perm('location.view')) { ?>
                        <li class="nav-link <?php echo $__current === 'locations.php' ? 'active' : ''; ?>">
                            <a href="locations.php">
                                <img class="nav-img" src="../assets/icons/location.svg" alt="">
                                <span class="text nav-text">Warehouses</span>
                            </a>
                        </li>
                    <?php } ?>

                    <?php if (function_exists('has_perm') && has_perm('product.view')) { ?>
                        <li class="nav-link <?php echo $__current === 'product.php' ? 'active' : ''; ?>">
                            <a href="product.php">
                                <img class="nav-img" src="../assets/icons/product.svg" alt="">
                                <span class="text nav-text">Products & Pricing</span>
                            </a>
                        </li>
                    <?php } ?>
                <?php } else { ?>
                    <?php if (!function_exists('has_perm') || has_perm('dashboard.view')) { ?>
                        <li class="nav-link <?php echo $__current === 'dashboard.php' ? 'active' : ''; ?>">
                            <a href="dashboard.php">
                                <i class='bx bx-home-alt icon'></i>
                                <span class="text nav-text">Dashboard</span>
                            </a>
                        </li>
                    <?php } ?>
                    <?php if (function_exists('has_perm') && has_perm('analytics.view')) { ?>
                        <li class="nav-link <?php echo $__current === 'analytics.php' ? 'active' : ''; ?>">
                            <a href="analytics.php">
                                <i class='bx bx-pie-chart-alt icon'></i>
                                <span class="text nav-text">Analytics</span>
                            </a>
                        </li>
                    <?php } ?>
                    <?php if (function_exists('has_perm') && has_perm('category.view')) { ?>
                        <li class="nav-link <?php echo $__current === 'category.php' ? 'active' : ''; ?>">
                            <a href="category.php">
                                <img class="nav-img" src="../assets/icons/category.svg" alt="">
                                <span class="text nav-text">Category</span>
                            </a>
                        </li>
                    <?php } ?>
                    <?php if (function_exists('has_perm') && has_perm('product.view')) { ?>
                        <li class="nav-link <?php echo $__current === 'product.php' ? 'active' : ''; ?>">
                            <a href="product.php">
                                <img class="nav-img" src="../assets/icons/product.svg" alt="">
                                <span class="text nav-text">Product</span>
                            </a>
                        </li>
                    <?php } ?>

                    <?php if (function_exists('has_perm') && (has_perm('movement.view') || has_perm('location.view'))) { ?>
                        <li class="nav-dropdown <?php echo $__current === 'transactions.php' || $__current === 'locations.php' ? 'active' : ''; ?>">
                            <a href="#" class="dropdown-toggle">
                                <img class="nav-img" src="../assets/icons/stock.svg" alt="">
                                <span class="text nav-text">Stock</span>
                                <i class='bx bx-chevron-down dd-icon'></i>
                            </a>
                            <ul class="submenu">
                                <?php if (has_perm('movement.view')) { ?>
                                    <li class="nav-link <?php echo $__current === 'transactions.php' ? 'active' : ''; ?>"><a href="transactions.php"><img class="nav-img" src="../assets/icons/stock.svg" alt=""><span class="text nav-text">Stock In/Out</span></a></li>
                                <?php } ?>
                                <?php if (has_perm('movement.approve')) { ?>
                                    <li class="nav-link <?php echo $__current === 'guest_requests.php' ? 'active' : ''; ?>"><a href="guest_requests.php"><i class='bx bx-check-shield icon'></i><span class="text nav-text">Guest Requests</span></a></li>
                                <?php } ?>
                                <?php if (has_perm('location.view')) { ?>
                                    <li class="nav-link <?php echo $__current === 'locations.php' ? 'active' : ''; ?>"><a href="locations.php"><img class="nav-img" src="../assets/icons/location.svg" alt=""><span class="text nav-text">Locations</span></a></li>
                                <?php } ?>
                            </ul>
                        </li>
                    <?php } ?>
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
                <div class="toggle-switch"><span class="switch"></span></div>
            </li>
        </div>
    </div>
</nav>
