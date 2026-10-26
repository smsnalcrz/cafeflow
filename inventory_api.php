<?php
/**
 * inventory_api.php - backs the Inventory modal.
 *
 *   GET                                   -> list all items (low stock first)
 *   POST {action:"add",     name, unit, stock, threshold}
 *   POST {action:"update",  item_id, name, unit, stock, threshold}   (stock count / rename)
 *   POST {action:"restock", item_id, amount}                         (adds to current stock)
 */
require_once __DIR__ . '/db.php';

const MAX_AMOUNT = 9999999;

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $rows  = $pdo->query(
        'SELECT item_id, name, current_stock, unit_measure, restock_threshold
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
        ];
    }, $rows);
    send_json(['success' => true, 'items' => $items]);
}

require_method('POST');
$d      = read_json();
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
        try {
            $pdo->prepare(
                'UPDATE inventory
                    SET name = ?, unit_measure = ?, current_stock = ?, restock_threshold = ?
                  WHERE item_id = ?'
            )->execute([$name, $unit, $stock, $alert, $id]);
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
        $pdo->prepare('UPDATE inventory SET current_stock = current_stock + ? WHERE item_id = ?')
            ->execute([$amount, $id]);
        break;

    case 'delete':
        $id = item_id_or_fail($d, $pdo);
        $pdo->prepare('DELETE FROM inventory WHERE item_id = ?')->execute([$id]);
        break;

    default:
        fail(422, 'Unknown action.');
}

send_json(['success' => true]);
