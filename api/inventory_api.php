<?php
/**
 * inventory_api.php - backs the Inventory modal.
 *
 *   GET                                   -> list all items (low stock first)
 *   POST {action:"add",     name, unit, stock, threshold}
 *   POST {action:"update",  item_id, name, unit, stock, threshold}   (stock count / rename)
 *   POST {action:"restock", item_id, amount}                         (adds to current stock)
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// RBAC: Requires authenticated user (both Barista and Manager have full inventory management)
require_login();

const MAX_AMOUNT = 9999999;

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    release_session_lock();
    $rows  = $pdo->query(
        'SELECT item_id, name, current_stock, unit_measure, restock_threshold, is_active
           FROM inventory
          ORDER BY (current_stock <= restock_threshold) DESC, name ASC'
    )->fetchAll();
    $items = array_map(static function (array $r): array {
        return [
            'item_id'           => (int) $r['item_id'],
            'name'              => $r['name'],
            'current_stock'     => (float) $r['current_stock'],
            'unit_measure'      => $r['unit_measure'],
            'restock_threshold' => (float) $r['restock_threshold'],
            'low'               => (float) $r['current_stock'] <= (float) $r['restock_threshold'],
            'is_active'         => ((int)($r['is_active'] ?? 1)) === 1,
        ];
    }, $rows);
    send_json(['success' => true, 'items' => $items]);
}

require_method('POST');
$d      = read_json();

$csrf = $d['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!validate_csrf_token($csrf)) {
    fail(403, 'CSRF security token invalid or missing.');
}
$action = $d['action'] ?? '';

/** Trimmed text of 1..$max bytes, or a 422. */
function required_text(array $d, string $key, int $max, string $label): string
{
    $v = $d[$key] ?? null;
    $v = is_string($v) ? trim($v) : '';
    if ($v === '' || strlen($v) > $max) {
        fail(422, "$label is required (max $max characters).");
    }
    return $v;
}

function item_id_or_fail(array $d, PDO $pdo): int
{
    $id = as_int($d['item_id'] ?? null, 1, 4294967295);
    if ($id === null) {
        fail(422, 'Invalid item.');
    }
    $st = $pdo->prepare('SELECT 1 FROM inventory WHERE item_id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) {
        fail(404, 'Item not found.');
    }
    return $id;
}

function duplicate_name_guard(PDOException $e): void
{
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        fail(409, 'An inventory item with that name already exists.');
    }
}

switch ($action) {
    case 'add':
        $name  = required_text($d, 'name', 100, 'Name');
        $unit  = required_text($d, 'unit', 20, 'Unit');
        $stock = as_decimal($d['stock'] ?? null, 0, MAX_AMOUNT);
        $alert = as_decimal($d['threshold'] ?? null, 0, MAX_AMOUNT);
        if ($stock === null || $alert === null) {
            fail(422, 'Stock and restock alert must be numbers of 0 or more.');
        }
        // Prevent duplicate inventory items (case-insensitive check)
        $stCheck = $pdo->prepare('SELECT item_id FROM inventory WHERE LOWER(name) = LOWER(?)');
        $stCheck->execute([$name]);
        if ($stCheck->fetchColumn()) {
            fail(409, "An inventory item named '$name' already exists in the inventory.");
        }

        try {
            $pdo->prepare(
                'INSERT INTO inventory (name, current_stock, unit_measure, restock_threshold) VALUES (?, ?, ?, ?)'
            )->execute([$name, $stock, $unit, $alert]);
            $newItemId = (int) $pdo->lastInsertId();
            audit_log(
                'inventory',
                'stock_create',
                "Added new inventory supply: {$name} ({$stock} {$unit})",
                'inventory',
                (string)$newItemId,
                ['name' => $name, 'initial_stock' => $stock, 'unit' => $unit, 'threshold' => $alert]
            );
            send_json(['success' => true, 'item_id' => $newItemId]);
        } catch (PDOException $e) {
            duplicate_name_guard($e);
            throw $e;
        }
        break;

    case 'update':
        $id    = item_id_or_fail($d, $pdo);
        $name  = required_text($d, 'name', 100, 'Name');
        $unit  = required_text($d, 'unit', 20, 'Unit');
        $stock = as_decimal($d['stock'] ?? null, 0, MAX_AMOUNT);
        $alert = as_decimal($d['threshold'] ?? null, 0, MAX_AMOUNT);
        if ($stock === null || $alert === null) {
            fail(422, 'Stock and restock alert must be numbers of 0 or more.');
        }

        // Prevent duplicate inventory items on rename (case-insensitive check against other items)
        $stCheck = $pdo->prepare('SELECT item_id FROM inventory WHERE LOWER(name) = LOWER(?) AND item_id != ?');
        $stCheck->execute([$name, $id]);
        if ($stCheck->fetchColumn()) {
            fail(409, "An inventory item named '$name' already exists in the inventory.");
        }

        // Fetch previous state for structured before/after audit attribution (Task 11)
        $prevStmt = $pdo->prepare('SELECT name, unit_measure, current_stock, restock_threshold FROM inventory WHERE item_id = ?');
        $prevStmt->execute([$id]);
        $prevItem = $prevStmt->fetch() ?: [];

        try {
            $pdo->prepare(
                'UPDATE inventory
                    SET name = ?, unit_measure = ?, current_stock = ?, restock_threshold = ?
                  WHERE item_id = ?'
            )->execute([$name, $unit, $stock, $alert, $id]);
            audit_log(
                'inventory',
                'stock_adjustment',
                "Adjusted inventory supply: {$name} (Stock: {$stock} {$unit}, Alert: {$alert})",
                'inventory',
                (string)$id,
                [
                    'name'           => $name,
                    'previous_stock' => isset($prevItem['current_stock']) ? (float)$prevItem['current_stock'] : null,
                    'new_stock'      => $stock,
                    'unit'           => $unit,
                    'previous_alert' => isset($prevItem['restock_threshold']) ? (float)$prevItem['restock_threshold'] : null,
                    'new_alert'      => $alert
                ]
            );
        } catch (PDOException $e) {
            duplicate_name_guard($e);
            throw $e;
        }
        break;

    case 'restock':
        $id     = item_id_or_fail($d, $pdo);
        $amount = as_decimal($d['amount'] ?? null, 0.01, MAX_AMOUNT);
        if ($amount === null) {
            fail(422, 'Enter the quantity received (more than 0).');
        }

        // Fetch supply details for before/after attribution (Task 11)
        $itemStmt = $pdo->prepare('SELECT name, unit_measure, current_stock FROM inventory WHERE item_id = ?');
        $itemStmt->execute([$id]);
        $itemRow = $itemStmt->fetch();
        $itemName = $itemRow['name'] ?? "Item #{$id}";
        $unitName = $itemRow['unit_measure'] ?? 'units';
        $prevStock = (float)($itemRow['current_stock'] ?? 0);
        $newStock = $prevStock + $amount;

        $pdo->prepare('UPDATE inventory SET current_stock = current_stock + ? WHERE item_id = ?')
            ->execute([$amount, $id]);
        audit_log(
            'inventory',
            'stock_restock',
            "Restocked +{$amount} {$unitName} of {$itemName} (Previous: {$prevStock}, New: {$newStock})",
            'inventory',
            (string)$id,
            [
                'item_id'        => $id,
                'item_name'      => $itemName,
                'amount_added'   => $amount,
                'previous_stock' => $prevStock,
                'new_stock'      => $newStock,
                'unit'           => $unitName
            ]
        );
        break;

    case 'delete':
        $id = item_id_or_fail($d, $pdo);

        // Fetch supply name
        $nameStmt = $pdo->prepare('SELECT name FROM inventory WHERE item_id = ?');
        $nameStmt->execute([$id]);
        $itemName = $nameStmt->fetchColumn() ?: 'Item';

        // Check if any active menu products depend on this inventory supply
        $depStmt = $pdo->prepare('
            SELECT DISTINCT p.product_id, p.name
              FROM product_ingredients pi
              JOIN menu_products p ON p.product_id = pi.product_id
             WHERE pi.item_id = ?
             UNION
            SELECT DISTINCT product_id, name
              FROM menu_products
             WHERE linked_inventory_id = ?
             ORDER BY name ASC
        ');
        $depStmt->execute([$id, $id]);
        $depProducts = $depStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($depProducts)) {
            $productNames = array_column($depProducts, 'name');
            $count = count($productNames);
            fail(
                409,
                sprintf(
                    'Cannot delete supply "%s": It is currently required by recipes for %d menu product(s): %s. Please reassign or remove this ingredient in Product Management first, or deactivate the supply.',
                    $itemName,
                    $count,
                    implode(', ', array_slice($productNames, 0, 5)) . ($count > 5 ? ' and ' . ($count - 5) . ' more' : '')
                )
            );
        }

        try {
            $pdo->prepare('DELETE FROM inventory WHERE item_id = ?')->execute([$id]);
            audit_log(
                'inventory',
                'stock_delete',
                "Deleted inventory supply: {$itemName} (#{$id})",
                'inventory',
                (string)$id,
                ['name' => $itemName]
            );
        } catch (PDOException $e) {
            fail(409, 'Cannot delete supply: Database constraint prevented removal.');
        }
        break;

    case 'toggle_active':
        $id = item_id_or_fail($d, $pdo);
        $pdo->prepare('UPDATE inventory SET is_active = NOT is_active WHERE item_id = ?')->execute([$id]);
        $stAct = $pdo->prepare('SELECT is_active FROM inventory WHERE item_id = ?');
        $stAct->execute([$id]);
        $newActive = (int) $stAct->fetchColumn();
        audit_log(
            'inventory',
            'stock_toggle_active',
            ($newActive === 1 ? 'Activated' : 'Deactivated') . " inventory supply #{$id}",
            'inventory',
            (string)$id,
            ['is_active' => $newActive]
        );
        send_json([
            'success'   => true,
            'is_active' => $newActive === 1,
            'message'   => $newActive === 1 ? 'Supply activated.' : 'Supply deactivated.',
        ]);
        break;

    default:
        fail(422, 'Unknown action.');
}

send_json(['success' => true]);
