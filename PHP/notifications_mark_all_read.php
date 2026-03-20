<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications_lib.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit();
}

if (!csrf_validate()) {
    http_response_code(400);
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$ok = notifications_mark_all_read($conn, $userId);

echo json_encode(['ok' => (bool)$ok], JSON_UNESCAPED_SLASHES);
