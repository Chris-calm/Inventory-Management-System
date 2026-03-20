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

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_SLASHES);
    exit();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
if ($userId <= 0 || $role !== 'guest') {
    http_response_code(403);
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json'], JSON_UNESCAPED_SLASHES);
    exit();
}

$token = (string)($payload['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit();
}

$warehouse = $payload['warehouse'] ?? null;
$items = $payload['items'] ?? null;

$warehouseName = '';
$warehouseImage = '';
$locationId = 0;
if (is_array($warehouse)) {
    $warehouseName = trim((string)($warehouse['name'] ?? ''));
    $warehouseImage = trim((string)($warehouse['imageSrc'] ?? ''));
    $locationId = (int)($warehouse['id'] ?? 0);
}

if (!is_array($items)) {
    $items = [];
}

$cleanItems = [];
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $sku = trim((string)($it['sku'] ?? ''));
    $name = trim((string)($it['name'] ?? ''));
    $qty = (int)($it['qty'] ?? 0);
    $categoryId = (int)($it['category_id'] ?? 0);
    $unitCost = $it['unit_cost'] ?? null;
    $unitPrice = $it['unit_price'] ?? null;
    $reorderLevel = (int)($it['reorder_level'] ?? 0);
    if ($qty <= 0) $qty = 1;
    if ($sku === '' && $name === '') continue;

    // Allow free-text items: if SKU is not provided, fall back to the name.
    if ($sku === '') {
        $sku = $name;
    }
    if ($name === '') {
        $name = $sku;
    }

    $costVal = is_numeric($unitCost) ? (float)$unitCost : 0.0;
    $priceVal = is_numeric($unitPrice) ? (float)$unitPrice : 0.0;
    if ($categoryId < 0) $categoryId = 0;
    if ($reorderLevel < 0) $reorderLevel = 0;

    $cleanItems[] = [
        'sku' => mb_substr($sku, 0, 80),
        'name' => mb_substr($name, 0, 160),
        'qty' => $qty,
        'category_id' => $categoryId > 0 ? $categoryId : null,
        'unit_cost' => $costVal,
        'unit_price' => $priceVal,
        'reorder_level' => $reorderLevel,
    ];
}

if ($warehouseName === '' || count($cleanItems) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing'], JSON_UNESCAPED_SLASHES);
    exit();
}

if ($locationId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_location'], JSON_UNESCAPED_SLASHES);
    exit();
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS guest_item_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            guest_user_id INT NOT NULL,
            location_id INT NOT NULL,
            warehouse_name VARCHAR(190) NOT NULL,
            warehouse_image_src VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at TIMESTAMP NULL,
            decided_by INT NULL,
            decided_reason VARCHAR(255) NULL,
            INDEX idx_guest_item_requests_status (status, created_at),
            INDEX idx_guest_item_requests_guest (guest_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $hasLocationIdCol = false;
    if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_item_requests' AND COLUMN_NAME = 'location_id'")) {
        $stmtCol->execute();
        $c = 0;
        $stmtCol->bind_result($c);
        if ($stmtCol->fetch()) {
            $hasLocationIdCol = ((int)$c) > 0;
        }
        $stmtCol->close();
    }
    if (!$hasLocationIdCol) {
        $conn->query("ALTER TABLE guest_item_requests ADD COLUMN location_id INT NOT NULL DEFAULT 0 AFTER guest_user_id");
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS guest_item_request_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            sku VARCHAR(80) NOT NULL,
            name VARCHAR(160) NOT NULL,
            qty INT NOT NULL,
            INDEX idx_guest_item_request_items_req (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $addCols = [
        ['category_id', "ALTER TABLE guest_item_request_items ADD COLUMN category_id INT UNSIGNED NULL AFTER name"],
        ['unit_cost', "ALTER TABLE guest_item_request_items ADD COLUMN unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER category_id"],
        ['unit_price', "ALTER TABLE guest_item_request_items ADD COLUMN unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_cost"],
        ['reorder_level', "ALTER TABLE guest_item_request_items ADD COLUMN reorder_level INT NOT NULL DEFAULT 0 AFTER unit_price"],
    ];
    foreach ($addCols as $cinfo) {
        $col = (string)$cinfo[0];
        $sql = (string)$cinfo[1];
        $hasCol = false;
        if ($stmtCol = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_item_request_items' AND COLUMN_NAME = ?")) {
            $stmtCol->bind_param('s', $col);
            $stmtCol->execute();
            $c = 0;
            $stmtCol->bind_result($c);
            if ($stmtCol->fetch()) {
                $hasCol = ((int)$c) > 0;
            }
            $stmtCol->close();
        }
        if (!$hasCol) {
            try {
                $conn->query($sql);
            } catch (Throwable $e) {
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'schema'], JSON_UNESCAPED_SLASHES);
    exit();
}

$conn->begin_transaction();
try {
    $note = trim((string)($payload['note'] ?? ''));
    if ($note !== '') {
        $note = mb_substr($note, 0, 255);
    } else {
        $note = null;
    }

    $imgDb = $warehouseImage !== '' ? mb_substr($warehouseImage, 0, 255) : null;

    $dupRequestId = 0;
    if ($stmtDup = $conn->prepare("SELECT id FROM guest_item_requests WHERE guest_user_id = ? AND location_id = ? AND status = 'pending' AND created_at >= (NOW() - INTERVAL 10 SECOND) ORDER BY id DESC LIMIT 1")) {
        $stmtDup->bind_param('ii', $userId, $locationId);
        $stmtDup->execute();
        $resDup = $stmtDup->get_result();
        if ($resDup && ($rDup = $resDup->fetch_assoc())) {
            $dupRequestId = (int)($rDup['id'] ?? 0);
        }
        $stmtDup->close();
    }

    if ($dupRequestId > 0) {
        $conn->commit();
        echo json_encode(['ok' => true, 'request_id' => $dupRequestId, 'duplicate' => true], JSON_UNESCAPED_SLASHES);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO guest_item_requests (guest_user_id, location_id, warehouse_name, warehouse_image_src, status, note) VALUES (?, ?, ?, ?, 'pending', ?)");
    if (!$stmt) {
        throw new Exception('prepare');
    }
    $stmt->bind_param('iisss', $userId, $locationId, $warehouseName, $imgDb, $note);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('insert');
    }
    $requestId = (int)$conn->insert_id;
    $stmt->close();

    $stmtIt = $conn->prepare("INSERT INTO guest_item_request_items (request_id, sku, name, qty, category_id, unit_cost, unit_price, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmtIt) {
        throw new Exception('prepare_items');
    }
    foreach ($cleanItems as $ci) {
        $sku = (string)$ci['sku'];
        $name = (string)$ci['name'];
        $qty = (int)$ci['qty'];
        $catId = $ci['category_id'] === null ? null : (int)$ci['category_id'];
        $costVal = (float)$ci['unit_cost'];
        $priceVal = (float)$ci['unit_price'];
        $reorderVal = (int)$ci['reorder_level'];
        $stmtIt->bind_param('issiidii', $requestId, $sku, $name, $qty, $catId, $costVal, $priceVal, $reorderVal);
        if (!$stmtIt->execute()) {
            $stmtIt->close();
            throw new Exception('insert_item');
        }
    }
    $stmtIt->close();

    try {
        $guestName = (string)($_SESSION['username'] ?? 'Guest');
        $title = 'Guest request pending approval';
        $msg = $guestName . ' submitted an item request for ' . $warehouseName . ' (' . (string)count($cleanItems) . ' items).';

        if ($resU = $conn->query("SELECT id FROM users WHERE role <> 'guest'")) {
            while ($u = $resU->fetch_assoc()) {
                $uid = (int)($u['id'] ?? 0);
                if ($uid > 0 && $uid !== $userId) {
                    notifications_create($conn, $uid, $title, $msg, 'product.php#guest-requests-approvals', 'info');
                }
            }
            $resU->free();
        }

        notifications_create($conn, $userId, 'Request submitted', 'Your request is pending approval.', 'product.php#guest-requests', 'success');
    } catch (Throwable $e) {
    }

    $conn->commit();

    echo json_encode(['ok' => true, 'request_id' => $requestId], JSON_UNESCAPED_SLASHES);
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
    exit();
}
