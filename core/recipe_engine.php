<?php
/**
 * CafeFlow - Centralized Recipe & Inventory Consumption Engine
 * -------------------------------------------------------------
 * All cup size multipliers, raw supply mappings, and consumption estimates
 * are centralized here in one place for easy review and future calibration.
 *
 * Date: October 26, 2026
 */

require_once __DIR__ . '/db.php';

/**
 * 1. CUP SIZE PROFILES
 * -------------------------------------------------------------
 * Defines standard cup sizes, volume scale factors, and matching
 * disposable inventory item patterns.
 */
const RECIPE_CUP_SIZES = [
    '16oz' => [
        'code'        => '16oz',
        'label'       => 'Tall',
        'oz'          => 16,
        'scale'       => 1.00,             // Baseline scale (1.0)
        'cup_pattern' => '16oz cup',       // Matches inventory item name
        'lid_pattern' => 'Flat lid',
        'straw'       => 'Bendable straw',
    ],
    '22oz' => [
        'code'        => '22oz',
        'label'       => 'Grande',
        'oz'          => 22,
        'scale'       => 1.375,            // 22oz is ~37.5% larger than 16oz
        'cup_pattern' => '22oz cup',
        'lid_pattern' => 'Flat lid',
        'straw'       => 'Bendable straw',
    ],
    // Readily expandable for 12oz Hot or other sizes
    '12oz' => [
        'code'        => '12oz',
        'label'       => 'Regular',
        'oz'          => 12,
        'scale'       => 0.75,
        'cup_pattern' => '12oz cup',
        'lid_pattern' => 'Flat lid',
        'straw'       => null,
    ],
];

/**
 * 2. TEMPORARY ESTIMATED CONSUMPTION PROFILES (PER 16oz SERVING)
 * -------------------------------------------------------------
 * Structured placeholder assumptions for raw ingredients.
 * Quantities listed are for 16oz (Large). For 22oz (Grande), amounts
 * scale automatically by RECIPE_CUP_SIZES['22oz']['scale'] unless
 * explicitly overridden.
 *
 * To adjust any ingredient value in the future, edit this array directly!
 */
const ESTIMATED_CONSUMPTION_PROFILES = [
    // --- Specific Product Overrides ---
    'matcha' => [
        'items' => [
            ['name' => 'Matcha Powder',       'qty_16oz' => 15.0,  'qty_22oz' => 22.0,  'unit' => 'g'],
            ['name' => 'Full Cream Milk',     'qty_16oz' => 180.0, 'qty_22oz' => 250.0, 'unit' => 'ml'],
            ['name' => 'Ice',                 'qty_16oz' => 120.0, 'qty_22oz' => 160.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'americano' => [
        'items' => [
            ['name' => 'Espresso Beans',      'qty_16oz' => 18.0,  'qty_22oz' => 24.0,  'unit' => 'g'],
            ['name' => 'Ice',                 'qty_16oz' => 140.0, 'qty_22oz' => 180.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'spanish latte' => [
        'items' => [
            ['name' => 'Espresso Beans',      'qty_16oz' => 18.0,  'qty_22oz' => 24.0,  'unit' => 'g'],
            ['name' => 'Full Cream Milk',     'qty_16oz' => 170.0, 'qty_22oz' => 230.0, 'unit' => 'ml'],
            ['name' => 'Ice',                 'qty_16oz' => 120.0, 'qty_22oz' => 150.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'caramel macchiato' => [
        'items' => [
            ['name' => 'Espresso Beans',      'qty_16oz' => 18.0,  'qty_22oz' => 24.0,  'unit' => 'g'],
            ['name' => 'Full Cream Milk',     'qty_16oz' => 160.0, 'qty_22oz' => 220.0, 'unit' => 'ml'],
            ['name' => 'Caramel syrup',       'qty_16oz' => 20.0,  'qty_22oz' => 30.0,  'unit' => 'ml'],
            ['name' => 'Ice',                 'qty_16oz' => 120.0, 'qty_22oz' => 150.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'chocolate' => [
        'items' => [
            ['name' => 'Chocolate Powder',    'qty_16oz' => 25.0,  'qty_22oz' => 35.0,  'unit' => 'g'],
            ['name' => 'Full Cream Milk',     'qty_16oz' => 180.0, 'qty_22oz' => 250.0, 'unit' => 'ml'],
            ['name' => 'Ice',                 'qty_16oz' => 120.0, 'qty_22oz' => 150.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],

    // --- Category Fallbacks ---
    'category:coffee' => [
        'items' => [
            ['name' => 'Espresso Beans',      'qty_16oz' => 18.0,  'qty_22oz' => 24.0,  'unit' => 'g'],
            ['name' => 'Full Cream Milk',     'qty_16oz' => 160.0, 'qty_22oz' => 220.0, 'unit' => 'ml'],
            ['name' => 'Ice',                 'qty_16oz' => 120.0, 'qty_22oz' => 150.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'category:non-coffee' => [
        'items' => [
            ['name' => 'Full Cream Milk',     'qty_16oz' => 180.0, 'qty_22oz' => 250.0, 'unit' => 'ml'],
            ['name' => 'Ice',                 'qty_16oz' => 120.0, 'qty_22oz' => 150.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'category:milk tea' => [
        'items' => [
            ['name' => 'Full Cream Milk',     'qty_16oz' => 160.0, 'qty_22oz' => 220.0, 'unit' => 'ml'],
            ['name' => 'Ice',                 'qty_16oz' => 120.0, 'qty_22oz' => 150.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'category:fruit tea' => [
        'items' => [
            ['name' => 'Ice',                 'qty_16oz' => 140.0, 'qty_22oz' => 180.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'category:fruit tea / lemonade' => [
        'items' => [
            ['name' => 'Ice',                 'qty_16oz' => 140.0, 'qty_22oz' => 180.0, 'unit' => 'g'],
        ],
        'requires_cup'   => true,
        'requires_lid'   => true,
        'requires_straw' => true,
    ],
    'category:croffle' => [
        'items' => [],
        'requires_cup'   => false,
        'requires_lid'   => false,
        'requires_straw' => false,
    ],
    'category:sandwich' => [
        'items' => [],
        'requires_cup'   => false,
        'requires_lid'   => false,
        'requires_straw' => false,
    ],
    'category:pastry' => [
        'items' => [
            ['name' => 'Croissant',           'qty_16oz' => 1.0,   'qty_22oz' => 1.0,   'unit' => 'pcs'],
        ],
        'requires_cup'   => false,
        'requires_lid'   => false,
        'requires_straw' => false,
    ],
    'category:snacks' => [
        'items' => [],
        'requires_cup'   => false,
        'requires_lid'   => false,
        'requires_straw' => false,
    ],
];

/**
 * Cached map of inventory items by lowercase name and alias.
 */
function get_inventory_name_map(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $rows = $pdo->query('SELECT item_id, name, unit_measure, current_stock, restock_threshold FROM inventory')->fetchAll();
    $cache = [];
    foreach ($rows as $r) {
        $clean = strtolower(trim($r['name']));
        $cache[$clean] = $r;

        // Also add aliases for flexible matching
        if (str_contains($clean, 'beans'))           $cache['beans'] = $r;
        if (str_contains($clean, 'matcha'))          $cache['matcha'] = $r;
        if (str_contains($clean, 'chocolate powder'))$cache['choco powder'] = $r;
        if (str_contains($clean, 'full cream milk')) {
            $cache['milk'] = $r;
            $cache['full cream milk'] = $r;
        }
        if (str_contains($clean, 'croissant'))       $cache['croissant'] = $r;
        if (str_contains($clean, '16oz cup'))        $cache['16oz cup'] = $r;
        if (str_contains($clean, '22oz cup'))        $cache['22oz cup'] = $r;
        if (str_contains($clean, 'flat lid'))        $cache['flat lid'] = $r;
        if (str_contains($clean, 'straw'))           $cache['straw'] = $r;
        if (str_contains($clean, 'ice'))             $cache['ice'] = $r;
    }
    return $cache;
}

/**
 * Finds the best inventory item matching a requested name/concept.
 */
function resolve_inventory_item(string $name, array $invMap): ?array
{
    $key = strtolower(trim($name));
    if (isset($invMap[$key])) return $invMap[$key];

    foreach ($invMap as $k => $item) {
        if (str_contains($k, $key) || str_contains($key, $k)) {
            return $item;
        }
    }
    return null;
}

/**
 * Calculates raw inventory consumption for a specific product and cup size.
 *
 * @param array       $product   Row from menu_products (product_id, name, category, etc.)
 * @param string|null $sizeCode  '16oz', '22oz', etc., or null for non-beverages
 * @param int         $quantity  Number of servings
 * @param PDO         $pdo
 * @return array      List of ['item_id' => int, 'name' => string, 'unit' => string, 'amount' => float]
 */
function calculate_product_consumption(array $product, ?string $sizeCode, int $quantity, PDO $pdo): array
{
    if ($quantity <= 0) return [];

    $pId = (int) ($product['product_id'] ?? 0);
    $category = strtolower(trim($product['category'] ?? 'general'));
    $pName = strtolower(trim($product['name'] ?? ''));
    $isBeverage = !in_array($category, ['pastry', 'meals', 'sandwich', 'snacks', 'croffle', 'coffle'], true);

    // Normalize size
    $sizeProfile = null;
    if ($isBeverage) {
        $normCode = $sizeCode && isset(RECIPE_CUP_SIZES[$sizeCode]) ? $sizeCode : '16oz';
        $sizeProfile = RECIPE_CUP_SIZES[$normCode];
    }

    $scale = $sizeProfile ? $sizeProfile['scale'] : 1.0;
    $invMap = get_inventory_name_map($pdo);
    $consumption = []; // item_id => ['item_id' => ..., 'name' => ..., 'unit' => ..., 'amount' => float]

    // -------------------------------------------------------------
    // PRIORITY 1: Custom Explicit Ingredients Linked in DB
    // -------------------------------------------------------------
    $customIngs = [];
    if ($pId > 0) {
        $st = $pdo->prepare(
            'SELECT pi.item_id, pi.deduction_amount, i.name, i.unit_measure, i.current_stock
               FROM product_ingredients pi
               JOIN inventory i ON i.item_id = pi.item_id
              WHERE pi.product_id = ?'
        );
        $st->execute([$pId]);
        $customIngs = $st->fetchAll();
    }

    if (!empty($customIngs)) {
        foreach ($customIngs as $ing) {
            $itemId = (int) $ing['item_id'];
            $baseDeduct = (float) $ing['deduction_amount'];
            $ingNameLower = strtolower($ing['name']);

            // Cups and packaging shouldn't scale by ingredient multiplier
            $isPackaging = str_contains($ingNameLower, 'cup') || str_contains($ingNameLower, 'lid') || str_contains($ingNameLower, 'straw');

            // If a specific 16oz or 22oz cup was saved in custom ingredients, map it to the ordered size
            if (str_contains($ingNameLower, 'cup') && $sizeProfile) {
                $targetCup = resolve_inventory_item($sizeProfile['cup_pattern'], $invMap);
                if ($targetCup) {
                    $itemId = (int) $targetCup['item_id'];
                    $ing['name'] = $targetCup['name'];
                    $ing['unit_measure'] = $targetCup['unit_measure'];
                }
            }

            $perServing = $isPackaging ? $baseDeduct : ($baseDeduct * $scale);
            $totalAmount = round($perServing * $quantity, 2);

            if (!isset($consumption[$itemId])) {
                $consumption[$itemId] = [
                    'item_id' => $itemId,
                    'name'    => $ing['name'],
                    'unit'    => $ing['unit_measure'],
                    'amount'  => 0.0,
                ];
            }
            $consumption[$itemId]['amount'] += $totalAmount;
        }

        // Ensure matching cup & lid are included if missing for beverages
        if ($isBeverage && $sizeProfile) {
            $hasCup = false;
            $hasLid = false;
            foreach ($consumption as $c) {
                $nameLower = strtolower($c['name']);
                if (str_contains($nameLower, 'cup')) {
                    $hasCup = true;
                }
                if (str_contains($nameLower, 'lid')) {
                    $hasLid = true;
                }
            }
            if (!$hasCup) {
                $cupItem = resolve_inventory_item($sizeProfile['cup_pattern'], $invMap);
                if ($cupItem) {
                    $cId = (int) $cupItem['item_id'];
                    if (!isset($consumption[$cId])) {
                        $consumption[$cId] = [
                            'item_id' => $cId,
                            'name'    => $cupItem['name'],
                            'unit'    => $cupItem['unit_measure'],
                            'amount'  => 0.0,
                        ];
                    }
                    $consumption[$cId]['amount'] += (1.0 * $quantity);
                }
            }
            if (!$hasLid && !empty($sizeProfile['lid_pattern'])) {
                $lidItem = resolve_inventory_item($sizeProfile['lid_pattern'], $invMap);
                if ($lidItem) {
                    $lId = (int) $lidItem['item_id'];
                    if (!isset($consumption[$lId])) {
                        $consumption[$lId] = [
                            'item_id' => $lId,
                            'name'    => $lidItem['name'],
                            'unit'    => $lidItem['unit_measure'],
                            'amount'  => 0.0,
                        ];
                    }
                    $consumption[$lId]['amount'] += (1.0 * $quantity);
                }
            }
        }

        return array_values($consumption);
    }

    // -------------------------------------------------------------
    // PRIORITY 2: Centralized Default Recipe Assumptions
    // -------------------------------------------------------------
    // Match by exact product name or keyword
    $matchedProfile = null;
    foreach (ESTIMATED_CONSUMPTION_PROFILES as $key => $prof) {
        if (!str_starts_with($key, 'category:') && str_contains($pName, $key)) {
            $matchedProfile = $prof;
            break;
        }
    }

    // If no product match, match by category
    if (!$matchedProfile && isset(ESTIMATED_CONSUMPTION_PROFILES['category:' . $category])) {
        $matchedProfile = ESTIMATED_CONSUMPTION_PROFILES['category:' . $category];
    }

    if ($matchedProfile) {
        $is22oz = ($sizeProfile && $sizeProfile['code'] === '22oz');
        foreach ($matchedProfile['items'] as $itemSpec) {
            $resolved = resolve_inventory_item($itemSpec['name'], $invMap);
            if ($resolved) {
                $rId = (int) $resolved['item_id'];
                $amountPerServing = $is22oz && isset($itemSpec['qty_22oz'])
                    ? (float) $itemSpec['qty_22oz']
                    : (float) $itemSpec['qty_16oz'];

                if (!$is22oz && $scale !== 1.0) {
                    $amountPerServing *= $scale;
                }

                $totalAmount = round($amountPerServing * $quantity, 2);

                if (!isset($consumption[$rId])) {
                    $consumption[$rId] = [
                        'item_id' => $rId,
                        'name'    => $resolved['name'],
                        'unit'    => $resolved['unit_measure'],
                        'amount'  => 0.0,
                    ];
                }
                $consumption[$rId]['amount'] += $totalAmount;
            }
        }

        // Add Cup & Lid for beverages
        if ($isBeverage && $sizeProfile && !empty($matchedProfile['requires_cup'])) {
            $cup = resolve_inventory_item($sizeProfile['cup_pattern'], $invMap);
            if ($cup) {
                $cId = (int) $cup['item_id'];
                if (!isset($consumption[$cId])) {
                    $consumption[$cId] = [
                        'item_id' => $cId,
                        'name'    => $cup['name'],
                        'unit'    => $cup['unit_measure'],
                        'amount'  => 0.0,
                    ];
                }
                $consumption[$cId]['amount'] += (1.0 * $quantity);
            }
        }

        if ($isBeverage && $sizeProfile && !empty($matchedProfile['requires_lid'])) {
            $lid = resolve_inventory_item($sizeProfile['lid_pattern'], $invMap);
            if ($lid) {
                $lId = (int) $lid['item_id'];
                if (!isset($consumption[$lId])) {
                    $consumption[$lId] = [
                        'item_id' => $lId,
                        'name'    => $lid['name'],
                        'unit'    => $lid['unit_measure'],
                        'amount'  => 0.0,
                    ];
                }
                $consumption[$lId]['amount'] += (1.0 * $quantity);
            }
        }

        return array_values($consumption);
    }

    // -------------------------------------------------------------
    // PRIORITY 3: Fallback by Product Name Matching Raw Inventory
    // -------------------------------------------------------------
    $directInv = resolve_inventory_item($pName, $invMap);
    if ($directInv) {
        $dId = (int) $directInv['item_id'];
        return [[
            'item_id' => $dId,
            'name'    => $directInv['name'],
            'unit'    => $directInv['unit_measure'],
            'amount'  => (float) $quantity,
        ]];
    }

    return [];
}

/**
 * Checks if the available inventory can fulfill the order requirements.
 *
 * @param array $aggregatedConsumption item_id => ['name' => ..., 'amount' => float, 'unit' => ...]
 * @param PDO   $pdo
 * @return array ['ok' => bool, 'error' => string|null]
 */
function verify_inventory_sufficiency(array $aggregatedConsumption, PDO $pdo): array
{
    if (empty($aggregatedConsumption)) {
        return ['ok' => true, 'error' => null];
    }

    $ids = array_keys($aggregatedConsumption);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT item_id, name, current_stock, unit_measure FROM inventory WHERE item_id IN ($marks)");
    $stmt->execute($ids);
    $stockRows = $stmt->fetchAll();
    $stockMap = [];
    foreach ($stockRows as $sr) {
        $stockMap[(int) $sr['item_id']] = (float) $sr['current_stock'];
    }

    foreach ($aggregatedConsumption as $itemId => $req) {
        $available = $stockMap[$itemId] ?? 0.0;
        $needed = (float) $req['amount'];
        if ($available < $needed) {
            $shortage = round($needed - $available, 2);
            $msg = sprintf(
                'Insufficient stock for "%s". Requires %s %s, but only %s %s available (short by %s %s).',
                $req['name'],
                number_format($needed, 2),
                $req['unit'],
                number_format(max(0, $available), 2),
                $req['unit'],
                number_format($shortage, 2),
                $req['unit']
            );
            return ['ok' => false, 'error' => $msg];
        }
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Evaluates the real-time stock state of a specific cup size ('ok', 'low', 'out')
 * based on raw inventory materials required for 1 serving of that size.
 *
 * @param array       $product
 * @param string|null $sizeCode e.g. '16oz', '22oz', or null for default/non-sized
 * @param PDO         $pdo
 * @param array|null  $invItemMap optional preloaded map of inventory rows
 * @return string 'ok' | 'low' | 'out'
 */
function evaluate_single_size_stock(array $product, ?string $sizeCode, PDO $pdo, ?array $invItemMap = null): string
{
    $consumption = calculate_product_consumption($product, $sizeCode, 1, $pdo);

    if (empty($consumption)) {
        return 'ok'; // No raw inventory dependencies defined
    }

    if ($invItemMap === null) {
        $ids = array_map(static fn($c) => $c['item_id'], $consumption);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT item_id, current_stock, restock_threshold FROM inventory WHERE item_id IN ($marks)");
        $stmt->execute($ids);
        $items = $stmt->fetchAll();
        $invItemMap = [];
        foreach ($items as $it) {
            $invItemMap[(int) $it['item_id']] = $it;
        }
    }

    $hasLow = false;
    foreach ($consumption as $c) {
        $it = $invItemMap[$c['item_id']] ?? null;
        if (!$it) continue;

        $stock = (float) $it['current_stock'];
        $thresh = (float) $it['restock_threshold'];
        $needed = (float) $c['amount'];

        if ($stock <= 0 || ($needed > 0 && $stock < $needed)) {
            return 'out'; // Out of stock if any required material is depleted
        }
        if ($stock <= $thresh) {
            $hasLow = true;
        }
    }

    return $hasLow ? 'low' : 'ok';
}

/**
 * Evaluates the real-time stock state of a menu product ('ok', 'low', 'partial', 'out')
 * based purely on its required raw inventory materials across available sizes.
 *
 * - If product has sizes (beverage):
 *   * Returns 'out' ONLY if ALL sizes are out of stock (e.g. coffee beans/powders depleted,
 *     or BOTH 16oz and 22oz cups are depleted).
 *   * Returns 'partial' if at least one size is available while another is depleted.
 *     The product remains clickable in POS so customers can order available sizes!
 *   * Returns 'low' if all sizes are available but some stock is at/below alert threshold.
 *   * Returns 'ok' if stock is healthy.
 *
 * - If product does not have sizes:
 *   * Evaluates single serving stock directly.
 */
function evaluate_product_stock_state(array $product, PDO $pdo): string
{
    $category = trim($product['category'] ?? '');
    $isBeverage = !in_array(strtolower($category), ['pastry', 'meals', 'sandwich', 'snacks', 'croffle', 'coffle'], true);
    $hasSizes = !empty($product['has_sizes']) && $isBeverage;

    if ($hasSizes) {
        $stock16 = evaluate_single_size_stock($product, '16oz', $pdo);
        $stock22 = evaluate_single_size_stock($product, '22oz', $pdo);

        // If ALL sizes are out of stock, product cannot be fulfilled
        if ($stock16 === 'out' && $stock22 === 'out') {
            return 'out';
        }

        // If one size is out but another is available, it is partial (available to order!)
        if ($stock16 === 'out' || $stock22 === 'out') {
            return 'partial';
        }

        // If both sizes are available, check if either is low
        if ($stock16 === 'low' || $stock22 === 'low') {
            return 'low';
        }

        return 'ok';
    }

    return evaluate_single_size_stock($product, null, $pdo);
}
