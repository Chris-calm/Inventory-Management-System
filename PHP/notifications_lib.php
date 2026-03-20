<?php

function notifications_ensure_tables(mysqli $conn): void
{
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(120) NOT NULL,
                message VARCHAR(255) NOT NULL,
                link_url VARCHAR(255) NULL,
                type VARCHAR(32) NOT NULL DEFAULT 'info',
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notifications_user (user_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS notification_settings (
                user_id INT PRIMARY KEY,
                notify_movements TINYINT(1) NOT NULL DEFAULT 1,
                notify_low_stock TINYINT(1) NOT NULL DEFAULT 1,
                notify_system TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        return;
    }
}

function notifications_get_settings(mysqli $conn, int $userId): array
{
    notifications_ensure_tables($conn);

    $defaults = [
        'notify_movements' => 1,
        'notify_low_stock' => 1,
        'notify_system' => 1,
    ];

    if ($userId <= 0) {
        return $defaults;
    }

    $stmt = $conn->prepare("SELECT notify_movements, notify_low_stock, notify_system FROM notification_settings WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        return $defaults;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        $ins = $conn->prepare("INSERT IGNORE INTO notification_settings (user_id) VALUES (?)");
        if ($ins) {
            $ins->bind_param('i', $userId);
            $ins->execute();
            $ins->close();
        }
        return $defaults;
    }

    return [
        'notify_movements' => (int)($row['notify_movements'] ?? 1),
        'notify_low_stock' => (int)($row['notify_low_stock'] ?? 1),
        'notify_system' => (int)($row['notify_system'] ?? 1),
    ];
}

function notifications_update_settings(mysqli $conn, int $userId, array $settings): bool
{
    notifications_ensure_tables($conn);

    $nm = !empty($settings['notify_movements']) ? 1 : 0;
    $nl = !empty($settings['notify_low_stock']) ? 1 : 0;
    $ns = !empty($settings['notify_system']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO notification_settings (user_id, notify_movements, notify_low_stock, notify_system) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE notify_movements = VALUES(notify_movements), notify_low_stock = VALUES(notify_low_stock), notify_system = VALUES(notify_system)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiii', $userId, $nm, $nl, $ns);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function notifications_create(mysqli $conn, int $userId, string $title, string $message, ?string $linkUrl = null, string $type = 'info'): void
{
    notifications_ensure_tables($conn);

    if ($userId <= 0 || $title === '' || $message === '') {
        return;
    }

    $title = mb_substr($title, 0, 120);
    $message = mb_substr($message, 0, 255);
    if ($linkUrl !== null && $linkUrl !== '') {
        $linkUrl = mb_substr($linkUrl, 0, 255);
    } else {
        $linkUrl = null;
    }

    $allowedTypes = ['info', 'success', 'warning', 'error'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'info';
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, link_url, type) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('issss', $userId, $title, $message, $linkUrl, $type);
    $stmt->execute();
    $stmt->close();
}

function notifications_list_recent(mysqli $conn, int $userId, int $limit = 6): array
{
    notifications_ensure_tables($conn);

    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 15) {
        $limit = 15;
    }

    $stmt = $conn->prepare("SELECT id, title, message, link_url, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    $stmt->close();

    return $rows;
}

function notifications_unread_count(mysqli $conn, int $userId): int
{
    notifications_ensure_tables($conn);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $c = 0;
    if ($res && ($row = $res->fetch_assoc())) {
        $c = (int)($row['c'] ?? 0);
    }
    $stmt->close();
    return $c;
}

function notifications_mark_all_read(mysqli $conn, int $userId): bool
{
    notifications_ensure_tables($conn);

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}
