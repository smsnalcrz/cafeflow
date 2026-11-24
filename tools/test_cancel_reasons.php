<?php
/**
 * Test Cancel Reasons: Unprepared vs Spillage/Waste during Pending
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();

$barista = $pdo->query("SELECT user_id, username, role FROM users WHERE role = 'barista' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
init_session();
$_SESSION['user_id'] = $barista['user_id'];
$_SESSION['username'] = $barista['username'];
$_SESSION['role'] = 'barista';
$_SESSION['status'] = 'active';
$_SESSION['last_activity'] = time();

$csrf = get_csrf_token();
session_write_close();

// Find sample inventory item
$inv = $pdo->query("SELECT item_id, current_stock FROM inventory WHERE current_stock > 10 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$itemId = (int) $inv['item_id'];
$initialStock = (float) $inv['current_stock'];

// TEST 1: Cancel with No-Waste Reason (e.g. Customer change of mind)
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO transactions (cashier_user_id, total_amount, amount_paid, change_due, subtotal, status, payment_method, stock_deducted) VALUES (?, 100, 100, 0, 100, 'Pending', 'Cash', 1)")
    ->execute([(int)$barista['user_id']]);
$tx1 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_ingredients (transaction_id, item_id, item_name, unit, amount_used) VALUES (?, ?, 'Beans', 'g', 15.0)")->execute([$tx1, $itemId]);
$pdo->prepare("UPDATE inventory SET current_stock = current_stock - 15.0 WHERE item_id = ?")->execute([$itemId]);
$pdo->commit();

// Cancel via cURL with is_waste = false
$ch = curl_init('http://localhost/cafeflow/api/cancel_order.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'csrf_token'     => $csrf,
    'transaction_id' => $tx1,
    'reason'         => 'Customer changed mind (Not prepared)',
    'is_waste'       => false
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Cookie: ' . session_name() . '=' . session_id()
]);
$res1 = json_decode(curl_exec($ch), true);
curl_close($ch);

$stock1 = (float) $pdo->query("SELECT current_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();
$wasteCount1 = (int) $pdo->query("SELECT COUNT(*) FROM waste_log WHERE transaction_id = {$tx1}")->fetchColumn();

echo "TEST 1 (No-waste cancel): " . ($res1['success'] ? 'SUCCESS' : 'FAIL') . "\n";
echo "  Inventory restored: " . ($res1['inventory_reversed'] ? 'YES' : 'NO') . "\n";
echo "  Stock matches initial: " . (round($stock1, 2) === round($initialStock, 2) ? 'YES' : 'NO') . "\n";
echo "  Waste entries logged: {$wasteCount1} (Expected: 0)\n";

// TEST 2: Cancel with Waste/Spillage Reason (e.g. Accidental spillage / Dropped drink)
$realProd = $pdo->query("SELECT product_id, name, price FROM menu_products LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pId = (int) $realProd['product_id'];

$pdo->beginTransaction();
$pdo->prepare("INSERT INTO transactions (cashier_user_id, total_amount, amount_paid, change_due, subtotal, status, payment_method, stock_deducted) VALUES (?, 100, 100, 0, 100, 'Pending', 'Cash', 1)")
    ->execute([(int)$barista['user_id']]);
$tx2 = (int) $pdo->lastInsertId();
// Insert a transaction item so waste logger knows what product was wasted
$pdo->prepare("INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, unit_price) VALUES (?, ?, ?, 1, 100.00)")->execute([$tx2, $pId, $realProd['name']]);
$pdo->prepare("INSERT INTO order_ingredients (transaction_id, item_id, item_name, unit, amount_used) VALUES (?, ?, 'Beans', 'g', 15.0)")->execute([$tx2, $itemId]);
$pdo->prepare("UPDATE inventory SET current_stock = current_stock - 15.0 WHERE item_id = ?")->execute([$itemId]);
$pdo->commit();

$stockBeforeCancel2 = (float) $pdo->query("SELECT current_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();

// Cancel via cURL with is_waste = true
$ch = curl_init('http://localhost/cafeflow/api/cancel_order.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'csrf_token'     => $csrf,
    'transaction_id' => $tx2,
    'reason'         => 'Accidental spillage / Dropped drink (Waste)',
    'is_waste'       => true
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Cookie: ' . session_name() . '=' . session_id()
]);
$res2 = json_decode(curl_exec($ch), true);
curl_close($ch);

$stock2 = (float) $pdo->query("SELECT current_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();
$wasteCount2 = (int) $pdo->query("SELECT COUNT(*) FROM waste_log WHERE transaction_id = {$tx2}")->fetchColumn();

echo "TEST 2 (Spillage/Waste cancel): " . ($res2['success'] ? 'SUCCESS' : 'FAIL') . "\n";
echo "  Inventory reversed: " . ($res2['inventory_reversed'] ? 'YES' : 'NO (Expected NO)') . "\n";
echo "  Stock remained deducted: " . (round($stock2, 2) === round($stockBeforeCancel2, 2) ? 'YES' : 'NO') . "\n";
echo "  Waste entries logged: {$wasteCount2} (Expected: > 0)\n";

// Cleanup test rows
$pdo->prepare("DELETE FROM waste_log WHERE transaction_id IN (?, ?)")->execute([$tx1, $tx2]);
$pdo->prepare("DELETE FROM order_ingredients WHERE transaction_id IN (?, ?)")->execute([$tx1, $tx2]);
$pdo->prepare("DELETE FROM transaction_items WHERE transaction_id IN (?, ?)")->execute([$tx1, $tx2]);
$pdo->prepare("DELETE FROM transactions WHERE transaction_id IN (?, ?)")->execute([$tx1, $tx2]);
// Restore original stock
$pdo->prepare("UPDATE inventory SET current_stock = ? WHERE item_id = ?")->execute([$initialStock, $itemId]);
echo "Cleaned up all test data.\n";
