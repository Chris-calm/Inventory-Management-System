<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$__username = (string)($_SESSION['username'] ?? '');
$__role = (string)($_SESSION['role'] ?? ($_SESSION['role_name'] ?? ''));
$__userId = (int)($_SESSION['user_id'] ?? 0);
$__csrf = function_exists('csrf_token') ? csrf_token() : '';

$__avatar = function_exists('user_avatar_url') ? user_avatar_url(isset($conn) && $conn instanceof mysqli ? $conn : null, $__userId, '../CUBE3.png') : '../CUBE3.png';
?>
<div class="flex items-center gap-3 relative">
    <button class="meta-pill notif-bell" type="button" data-csrf="<?php echo htmlspecialchars($__csrf, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Notifications">
        <i class='bx bx-bell'></i>
        <span class="notif-badge" style="display:none;">0</span>
    </button>
    <div class="notif-dropdown" style="display:none;"></div>

    <button type="button" class="top-avatar" aria-label="Profile menu">
        <img src="<?php echo htmlspecialchars($__avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="w-10 h-10 rounded-full object-cover border border-[var(--border)]">
    </button>

    <div class="top-profile-dropdown" style="display:none;">
        <div class="profile-menu-header">
            <div class="user-avatar"><img src="<?php echo htmlspecialchars($__avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="user"></div>
            <div class="user-name"><?php echo htmlspecialchars($__username, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($__role !== '') { ?><div class="user-role"><?php echo htmlspecialchars($__role, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        </div>
        <div class="profile-menu-links">
            <?php if (function_exists('has_perm') && has_perm('rbac.assign')) { ?>
                <a class="profile-link" href="module_manager.php"><i class='bx bx-grid-alt'></i><span>Module Manager</span></a>
                <a class="profile-link" href="admin.php#employees"><i class='bx bx-user'></i><span>Employees</span></a>
            <?php } ?>
            <a class="profile-link" href="settings.php"><i class='bx bx-cog'></i><span>Settings</span></a>
            <a class="profile-link danger" href="logout.php"><i class='bx bx-log-out'></i><span>Logout</span></a>
        </div>
    </div>
</div>
