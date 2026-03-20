<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications_lib.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_SLASHES);
    exit();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$unread = notifications_unread_count($conn, $userId);
$list = notifications_list_recent($conn, $userId, 6);

echo json_encode([
    'ok' => true,
    'unread' => $unread,
    'items' => $list,
], JSON_UNESCAPED_SLASHES);
