<?php
require_once 'config.php';
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die('Connection failed');

$result = $conn->query("UPDATE pending_products SET status = 'pending' WHERE status = 'active'");
echo "Updated " . $conn->affected_rows . " rows\n";

// Show current status
$result = $conn->query("SELECT id, sku, name, status FROM pending_products ORDER BY id DESC");
while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']}, SKU: {$row['sku']}, Status: {$row['status']}\n";
}

$conn->close();
?>
