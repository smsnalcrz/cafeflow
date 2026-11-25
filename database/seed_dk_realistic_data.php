<?php
/**
 * database/seed_dk_realistic_data.php
 * =============================================================================
 * DK Cafe Realistic Mock Data Seeder (Micro-Scale Ground Truth Calibration)
 * -----------------------------------------------------------------------------
 * Target Store: DK' Café (Matabungkay Beach, Sitio Ilaya, Lian, Batangas)
 * Target Monthly Sales: ₱18,000 – ₱30,000 / month (or less during Habagat)
 * Annual Target: ~₱260,000 – ₱295,000 gross across 2025 and 2026
 * Operating Profile:
 *   - Hours: 1:00 PM – 9:00 PM (13:00 - 21:00)
 *   - Closed / Zero-Sales Days: ~45–55 closed days per year (Mon/Tue rest days,
 *     severe typhoons / coastal flooding in July–August Habagat season).
 *   - Open Days Daily Volume:
 *       * Monday – Thursday: 2 to 5 orders / day (₱300 – ₱800)
 *       * Friday & Sunday:   5 to 8 orders / day (₱800 – ₱1,400)
 *       * Saturday Peak:     8 to 12 orders / day (₱1,300 – ₱2,400)
 *   - Basket Composition: 78% 1 item, 19% 2 items, 3% 3 items (avg ticket ~₱155)
 *   - Cancellation Rate: ~4% with authentic barista reasons & wasted ingredients
 *   - Operational Manual Waste: 1 to 2 times a week (spilled milk, expired dairy, etc.)
 *   - Task 19 Isolation: Every synthetic transaction strictly tagged with is_mock = 1.
 * -----------------------------------------------------------------------------
 * Usage: php database/seed_dk_realistic_data.php [--skip-sql-dump]
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/recipe_engine.php';

$pdo = db();
$isCli = (php_sapi_name() === 'cli');

function logMsg(string $msg): void {
    echo $msg . PHP_EOL;
}

logMsg("======================================================================");
logMsg("   DK CAFE - REALISTIC MOCK DATA SEEDER (₱18k - ₱30k / Month)         ");
logMsg("======================================================================");

// 1. Fetch live product catalog
$products = $pdo->query("
    SELECT product_id, name, category, price, has_sizes, price_12oz, price_16oz, price_22oz
    FROM menu_products
    ORDER BY product_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    die("Error: No menu products found in database. Run database/schema.sql first.\n");
}

// 2. Fetch size prices
$sizePrices = $pdo->query("
    SELECT product_id, size_code, size_label, size_oz, price
    FROM product_size_prices
    ORDER BY product_id, size_oz ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sizesByProduct = [];
foreach ($sizePrices as $sp) {
    $sizesByProduct[(int)$sp['product_id']][] = $sp;
}

// 3. Fetch inventory supplies
$inventory = $pdo->query("
    SELECT item_id, name, unit_measure, current_stock
    FROM inventory
    ORDER BY item_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$invByName = [];
$invById = [];
foreach ($inventory as $inv) {
    $invByName[strtolower(trim($inv['name']))] = $inv;
    $invById[(int)$inv['item_id']] = $inv;
}

// 4. Fetch product recipes
$recipeRows = $pdo->query("
    SELECT product_id, item_id, deduction_amount
    FROM product_ingredients
    ORDER BY product_id, item_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$recipesByProduct = [];
foreach ($recipeRows as $r) {
    $recipesByProduct[(int)$r['product_id']][] = [
        'item_id' => (int)$r['item_id'],
        'amount' => (float)$r['deduction_amount'],
    ];
}

// Product popularity weights (authentic to DK Cafe beachside orders)
$productWeights = [
    // Core Espresso (High volume afternoon orders)
    42 => 34, // Spanish Latte (Signature best-seller)
    2  => 26, // Caffé Latte
    41 => 22, // Americano
    43 => 20, // Caramel Macchiato
    4  => 14, // White Mocha
    6  => 14, // Salted Caramel
    7  => 12, // Biscoff Latte
    // Milk Series (Refreshing cold beverages)
    31 => 24, // Matcha Latte
    28 => 18, // Strawberry Milk
    29 => 16, // Mango Milk
    36 => 16, // Strawberry Matcha
    37 => 14, // White Choco Matcha
    32 => 14, // Black Forest
    30 => 12, // Blueberry Milk
    33 => 12, // Nata Strawberry
    34 => 12, // Nata Mango
    35 => 12, // Pearl Mango
    // Milk Tea
    24 => 25, // Brown Sugar Pearl Milk Tea (Signature)
    22 => 18, // Cream Cheese & Cake Milk Tea
    23 => 16, // Oreo Cream Cheese Milk Tea
    25 => 14, // Cake Milk Tea
    26 => 14, // Oreo Milk Tea
    27 => 12, // Nata Milk Tea
    // Fruit Tea / Lemonade (Warm coastal afternoon refreshers)
    19 => 20, // Kumquat Lemonade
    21 => 20, // DK Signature (Passion Fruit)
    20 => 18, // Lemonade w/ Chia Seeds
    18 => 14, // Italian Soda
    // Croffles Classic
    12 => 22, // Biscoff Croffle (Best seller)
    10 => 18, // Nutella Croffle
    9  => 14, // Dark Choco Croffle
    8  => 13, // White Choco Croffle
    11 => 13, // Caramel Fudge Croffle
    // Croffles Premium
    13 => 16, // Biscoff Cream Croffle
    14 => 14, // Nutella Alcapone
    15 => 14, // Cookies & Cream
    16 => 13, // Banana Nutella
    17 => 13, // Strawberry Choco
    // Pastry, Snacks & Sandwiches
    45 => 26, // French Fries (Barkada beach snack)
    44 => 22, // Butter Croissant
    38 => 15, // Sandwich Overload (Spam & Egg)
    39 => 14, // Sandwich Overload (Ham & Egg)
    40 => 12, // Sandwich Overload (Chicken)
];

function pickWeightedItem(array $products, array $weights): array {
    $totalW = 0;
    foreach ($products as $p) {
        $id = (int)$p['product_id'];
        $totalW += $weights[$id] ?? 10;
    }
    $rnd = mt_rand(1, $totalW);
    $running = 0;
    foreach ($products as $p) {
        $id = (int)$p['product_id'];
        $running += $weights[$id] ?? 10;
        if ($rnd <= $running) {
            return $p;
        }
    }
    return $products[0];
}

function resolveVariant(array $product, array $sizesByProduct): array {
    $pId = (int)$product['product_id'];
    $cat = strtolower($product['category'] ?? '');
    $basePrice = (float)$product['price'];

    if (empty($product['has_sizes']) || empty($sizesByProduct[$pId])) {
        return [
            'price' => $basePrice,
            'size_code' => null,
            'size_label' => null,
            'size_oz' => null,
        ];
    }

    $sizes = $sizesByProduct[$pId];

    // Coffee: 68% 16oz Cold, 32% 12oz Hot
    if ($cat === 'coffee') {
        $isHot = (mt_rand(1, 100) <= 32);
        foreach ($sizes as $s) {
            if ($isHot && (str_contains(strtolower($s['size_code']), '12oz') || (int)$s['size_oz'] === 12)) {
                return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
            }
            if (!$isHot && (str_contains(strtolower($s['size_code']), '16oz') || (int)$s['size_oz'] === 16)) {
                return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
            }
        }
    }

    // Milk Tea / Fruit Tea: 65% 16oz Tall, 35% 22oz Grande
    $pickGrande = (mt_rand(1, 100) <= 35);
    foreach ($sizes as $s) {
        if ($pickGrande && ((int)$s['size_oz'] === 22 || strtolower($s['size_code']) === '22oz')) {
            return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
        }
        if (!$pickGrande && ((int)$s['size_oz'] === 16 || strtolower($s['size_code']) === '16oz')) {
            return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
        }
    }

    $first = $sizes[0];
    return ['price' => (float)$first['price'], 'size_code' => $first['size_code'], 'size_label' => $first['size_label'], 'size_oz' => (int)$first['size_oz']];
}

/**
 * Probability model for store closure / zero-sales days
 */
function isStoreClosedOnDate(DateTimeImmutable $dt): bool {
    $month = (int)$dt->format('n');
    $dow = (int)$dt->format('N'); // 1 = Mon, 7 = Sun

    // 1. Base probability by month / season
    if ($month === 7 || $month === 8) {
        $baseProb = 0.25; // Monsoon Habagat / typhoons
    } elseif ($month === 1 || $month === 2 || $month === 9) {
        $baseProb = 0.14; // Lean months
    } elseif ($month === 4 || $month === 5 || $month === 12) {
        $baseProb = 0.04; // Peak summer & holiday season
    } else {
        $baseProb = 0.09; // Regular shoulder months
    }

    // 2. Day-of-week multiplier
    if ($dow === 1 || $dow === 2) {
        $dayMult = 2.40; // Monday / Tuesday quiet rest days
    } elseif ($dow === 4 || $dow === 5) {
        $dayMult = 0.08; // Friday / Saturday rarely closed
    } elseif ($dow === 7) {
        $dayMult = 0.20; // Sunday
    } else {
        $dayMult = 0.85; // Wednesday / Thursday
    }

    $closureChance = min(0.85, $baseProb * $dayMult);
    $roll = mt_rand(1, 1000) / 1000.0;
    return ($roll < $closureChance);
}

// Seed random generator for absolute reproducibility
mt_srand(20261125);

// Time slot definitions for operating hours (1:00 PM – 9:00 PM)
$afternoonSlots = [
    // 13:00 - 14:59 (Afternoon opening / solo coffee)
    ['h' => 13, 'm' => [15, 30, 45], 'weight' => 15],
    ['h' => 14, 'm' => [10, 25, 40], 'weight' => 20],
    // 15:00 - 17:59 (Merienda rush: Croffles, Milk Teas, Iced Lattes)
    ['h' => 15, 'm' => [05, 20, 35, 50], 'weight' => 35],
    ['h' => 16, 'm' => [15, 30, 45],     'weight' => 40],
    ['h' => 17, 'm' => [10, 25, 40, 55], 'weight' => 38],
    // 18:00 - 20:30 (Evening chill & beach departure unwind)
    ['h' => 18, 'm' => [15, 30, 45],     'weight' => 30],
    ['h' => 19, 'm' => [05, 25, 45],     'weight' => 25],
    ['h' => 20, 'm' => [10, 20, 30],     'weight' => 15],
];

function pickTimeSlot(array $slots): array {
    $totalW = array_sum(array_column($slots, 'weight'));
    $rnd = mt_rand(1, $totalW);
    $running = 0;
    foreach ($slots as $s) {
        $running += $s['weight'];
        if ($rnd <= $running) {
            $min = $s['m'][array_rand($s['m'])];
            $sec = mt_rand(5, 55);
            return ['h' => $s['h'], 'm' => $min, 's' => $sec];
        }
    }
    return ['h' => 15, 'm' => 30, 's' => 20];
}

// Clean starting ID counters (30001+ to avoid any collisions with real transactions)
$txIdCounter = 30001;
$txItemIdCounter = 100001;
$orderIngIdCounter = 100001;
$wasteIdCounter = 20001;
$summaryIdCounter = 20001;

$allTransactions = [];
$allTransactionItems = [];
$allOrderIngredients = [];
$allWasteLogs = [];
$dailyWasteAggregates = [];
$hourlyWasteAggregates = [];

$monthlyStats = [];
$yearsToSeed = [2025, 2026];

logMsg("Simulating 24 months of authentic transactions across 2025 and 2026...");

foreach ($yearsToSeed as $year) {
    for ($month = 1; $month <= 12; $month++) {
        $monthKey = sprintf('%04d-%02d', $year, $month);
        $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);

        $monthGross = 0.0;
        $monthCompletedOrders = 0;
        $monthCancelledOrders = 0;
        $monthClosedDays = 0;
        $monthOpenDays = 0;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $d));
            $dow = (int)$dt->format('N'); // 1 = Mon, 7 = Sun
            $dateStr = $dt->format('Y-m-d');

            // Check if store was closed on this day
            if (isStoreClosedOnDate($dt)) {
                $monthClosedDays++;
                continue; // Zero-sales day
            }

            $monthOpenDays++;

            // Determine daily order count for open day
            if ($dow === 6) { // Saturday Beach Peak
                $dailyOrdersCount = mt_rand(8, 12);
            } elseif ($dow === 5 || $dow === 7) { // Friday / Sunday
                $dailyOrdersCount = mt_rand(5, 8);
            } else { // Monday - Thursday
                $dailyOrdersCount = mt_rand(2, 5);
            }

            // Seasonal multiplier
            if ($month === 4 || $month === 5 || $month === 12) {
                $dailyOrdersCount = (int)round($dailyOrdersCount * 1.20);
            } elseif ($month === 7 || $month === 8) {
                $dailyOrdersCount = max(1, (int)round($dailyOrdersCount * 0.80));
            }

            // Generate daily orders
            for ($o = 0; $o < $dailyOrdersCount; $o++) {
                // Decide basket line items: 1 (78%), 2 (19%), 3 (3%)
                $bRoll = mt_rand(1, 100);
                $numItems = 1;
                if ($bRoll > 97) {
                    $numItems = 3;
                } elseif ($bRoll > 78) {
                    $numItems = 2;
                }

                $orderItems = [];
                $orderSubtotal = 0.0;

                for ($it = 0; $it < $numItems; $it++) {
                    $prod = pickWeightedItem($products, $productWeights);
                    $variant = resolveVariant($prod, $sizesByProduct);
                    $qty = (mt_rand(1, 100) <= 95) ? 1 : 2;
                    $uPrice = $variant['price'];
                    $lineTotal = $uPrice * $qty;

                    $orderItems[] = [
                        'id' => $txItemIdCounter++,
                        'transaction_id' => $txIdCounter,
                        'product_id' => (int)$prod['product_id'],
                        'product_name' => $prod['name'],
                        'quantity' => $qty,
                        'unit_price' => $uPrice,
                        'size_code' => $variant['size_code'],
                        'size_label' => $variant['size_label'],
                        'size_oz' => $variant['size_oz'],
                        'category' => $prod['category'],
                    ];

                    $orderSubtotal += $lineTotal;
                }

                // Senior / PWD Discount (5% chance)
                $discount = 0.00;
                $discountType = 'none';
                $discountRate = 0.00;
                $discountId = null;
                $discountReason = null;

                if (mt_rand(1, 100) <= 5) {
                    $discountType = (mt_rand(1, 2) === 1) ? 'Senior' : 'PWD';
                    $discountRate = 0.20;
                    $discount = round($orderSubtotal * 0.20, 2);
                    $discountId = ($discountType === 'Senior')
                        ? 'OSCA-2024-' . mt_rand(1000, 9999)
                        : 'PWD-04-' . mt_rand(1000, 9999);
                    $discountReason = "20% statutory discount for $discountType";
                }

                $orderTotal = round($orderSubtotal - $discount, 2);
                $txId = $txIdCounter++;

                $slotTime = pickTimeSlot($afternoonSlots);
                $timestamp = sprintf('%s %02d:%02d:%02d', $dateStr, $slotTime['h'], $slotTime['m'], $slotTime['s']);

                // Cashier: 85% Barista (user 1), 15% Manager (user 2)
                $cashierId = (mt_rand(1, 100) <= 85) ? 1 : 2;

                // Payment method: 60% Cash, 40% GCash
                $isGCash = (mt_rand(1, 100) <= 40);
                $paymentMethod = $isGCash ? 'GCash' : 'Cash';
                $amountPaid = 0.00;
                $changeDue = 0.00;
                $refNumber = null;

                if ($isGCash) {
                    $amountPaid = $orderTotal;
                    $changeDue = 0.00;
                    $refNumber = '100' . str_pad((string)mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
                } else {
                    // Realistic cash tender bills
                    if ($orderTotal <= 100.00) {
                        $amountPaid = 100.00;
                    } elseif ($orderTotal <= 200.00) {
                        $amountPaid = (mt_rand(1, 100) <= 75) ? 200.00 : 500.00;
                    } elseif ($orderTotal <= 500.00) {
                        $amountPaid = 500.00;
                    } else {
                        $amountPaid = 1000.00;
                    }
                    $changeDue = round($amountPaid - $orderTotal, 2);
                }

                // Check if this order is Cancelled (~3.5% chance)
                $isCancelled = (mt_rand(1, 1000) <= 35);
                $status = $isCancelled ? 'Cancelled' : 'Completed';
                $cBy = null;
                $cAt = null;
                $cReason = null;

                if ($isCancelled) {
                    $monthCancelledOrders++;
                    $cBy = 2; // Manager approval
                    $cAt = date('Y-m-d H:i:s', strtotime($timestamp) + mt_rand(120, 480));
                    $cancelReasons = [
                        'Customer had an urgent call and could not wait',
                        'Customer changed mind on sweetness level after preparation started',
                        'Barista accidentally entered duplicate ticket',
                        'Customer requested dine-in switch after take-out beverage was brewed',
                        'Customer discovered insufficient digital wallet balance',
                        'Wrong drink variant prepared due to register communication error',
                    ];
                    $cReason = $cancelReasons[array_rand($cancelReasons)];
                } else {
                    $monthCompletedOrders++;
                    $monthGross += $orderTotal;
                }

                $allTransactions[] = [
                    'transaction_id' => $txId,
                    'cashier_user_id' => $cashierId,
                    'total_amount' => $orderTotal,
                    'amount_paid' => $amountPaid,
                    'change_due' => $changeDue,
                    'subtotal' => $orderSubtotal,
                    'tax' => 0.00,
                    'discount' => $discount,
                    'discount_type' => $discountType,
                    'discount_rate' => $discountRate,
                    'discount_id_no' => $discountId,
                    'discount_reason' => $discountReason,
                    'status' => $status,
                    'cancelled_by_user_id' => $cBy,
                    'cancelled_at' => $cAt,
                    'cancellation_reason' => $cReason,
                    'payment_method' => $paymentMethod,
                    'reference_number' => $refNumber,
                    'is_mock' => 1,
                    'timestamp' => $timestamp,
                ];

                foreach ($orderItems as $oi) {
                    $oi['transaction_id'] = $txId;
                    $allTransactionItems[] = $oi;

                    // Generate ingredient consumption snapshots for order_ingredients
                    $pId = $oi['product_id'];
                    if (isset($recipesByProduct[$pId])) {
                        foreach ($recipesByProduct[$pId] as $rec) {
                            $invId = $rec['item_id'];
                            $rawInv = $invById[$invId] ?? null;
                            if (!$rawInv) continue;

                            $amount = $rec['amount'] * $oi['quantity'];
                            // Adjust for size
                            if ($oi['size_oz'] === 12) {
                                $amount *= 0.75;
                            } elseif ($oi['size_oz'] === 22) {
                                $amount *= 1.375;
                            }

                            $allOrderIngredients[] = [
                                'id' => $orderIngIdCounter++,
                                'transaction_id' => $txId,
                                'item_id' => $invId,
                                'item_name' => $rawInv['name'],
                                'unit' => $rawInv['unit_measure'],
                                'amount_used' => round($amount, 2),
                                'created_at' => $timestamp,
                            ];
                        }
                    }
                }

                // If cancelled, log waste for the prepared items
                if ($isCancelled) {
                    $hourInt = $slotTime['h'];
                    $cPrice = $orderTotal;
                    $incomeLossCents = (int)round($cPrice * 100);

                    // Decompose first item into waste log
                    $firstItem = $orderItems[0];
                    $cat = strtolower($firstItem['category'] ?? '');
                    $isDrink = !in_array($cat, ['croffle classic', 'croffle premium', 'sandwich', 'pastry', 'snacks'], true);

                    if ($isDrink) {
                        // Cup waste
                        $cupName = ($firstItem['size_oz'] === 22) ? '22oz cup' : '16oz cup';
                        $cupItem = $invByName[$cupName] ?? $invByName['16oz cup'];
                        $allWasteLogs[] = [
                            'waste_id' => $wasteIdCounter++,
                            'transaction_id' => $txId,
                            'product_id' => $firstItem['product_id'],
                            'product_name' => $firstItem['product_name'],
                            'size_code' => $firstItem['size_code'],
                            'size_label' => $firstItem['size_label'],
                            'quantity' => 1,
                            'item_id' => (int)$cupItem['item_id'],
                            'item_name' => $cupItem['name'],
                            'unit' => 'pcs',
                            'amount_wasted' => 1.00,
                            'cost_cents' => 350,
                            'estimated_cost_php' => 3.50,
                            'wasted_by_user_id' => 1,
                            'reason' => "Cancelled order loss: $cReason",
                            'wasted_at' => $cAt,
                        ];

                        // Milk / base waste
                        $milkItem = $invByName['arla full cream milk'] ?? $invByName['full cream milk'];
                        $allWasteLogs[] = [
                            'waste_id' => $wasteIdCounter++,
                            'transaction_id' => $txId,
                            'product_id' => $firstItem['product_id'],
                            'product_name' => $firstItem['product_name'],
                            'size_code' => $firstItem['size_code'],
                            'size_label' => $firstItem['size_label'],
                            'quantity' => 1,
                            'item_id' => (int)$milkItem['item_id'],
                            'item_name' => $milkItem['name'],
                            'unit' => 'ml',
                            'amount_wasted' => 180.00,
                            'cost_cents' => 1620,
                            'estimated_cost_php' => 16.20,
                            'wasted_by_user_id' => 1,
                            'reason' => "Cancelled beverage ingredient loss: $cReason",
                            'wasted_at' => $cAt,
                        ];

                        $eventCostCents = 350 + 1620;
                        $eventAmount = 1.0 + 180.0;
                        $eventCount = 2;
                    } else {
                        // Food waste
                        $foodItem = (str_contains($cat, 'croffle') || str_contains($cat, 'pastry'))
                            ? ($invByName['croissant'] ?? $inventory[20])
                            : ($invByName['french fries'] ?? $inventory[21]);

                        $wAmt = ($foodItem['unit_measure'] === 'pcs') ? 1.00 : 150.00;
                        $allWasteLogs[] = [
                            'waste_id' => $wasteIdCounter++,
                            'transaction_id' => $txId,
                            'product_id' => $firstItem['product_id'],
                            'product_name' => $firstItem['product_name'],
                            'size_code' => null,
                            'size_label' => null,
                            'quantity' => 1,
                            'item_id' => (int)$foodItem['item_id'],
                            'item_name' => $foodItem['name'],
                            'unit' => $foodItem['unit_measure'],
                            'amount_wasted' => $wAmt,
                            'cost_cents' => 4500,
                            'estimated_cost_php' => 45.00,
                            'wasted_by_user_id' => 1,
                            'reason' => "Prepared food cancelled: $cReason",
                            'wasted_at' => $cAt,
                        ];

                        $eventCostCents = 4500;
                        $eventAmount = $wAmt;
                        $eventCount = 1;
                    }

                    // Accumulate daily & hourly aggregates
                    $dailyWasteAggregates[$dateStr]['events'] = ($dailyWasteAggregates[$dateStr]['events'] ?? 0) + $eventCount;
                    $dailyWasteAggregates[$dateStr]['amount'] = ($dailyWasteAggregates[$dateStr]['amount'] ?? 0) + $eventAmount;
                    $dailyWasteAggregates[$dateStr]['cost_cents'] = ($dailyWasteAggregates[$dateStr]['cost_cents'] ?? 0) + $eventCostCents;
                    $dailyWasteAggregates[$dateStr]['income_cents'] = ($dailyWasteAggregates[$dateStr]['income_cents'] ?? 0) + $incomeLossCents;

                    $hKey = "{$dateStr}_{$hourInt}";
                    $hourlyWasteAggregates[$hKey]['date'] = $dateStr;
                    $hourlyWasteAggregates[$hKey]['hour'] = $hourInt;
                    $hourlyWasteAggregates[$hKey]['events'] = ($hourlyWasteAggregates[$hKey]['events'] ?? 0) + $eventCount;
                    $hourlyWasteAggregates[$hKey]['amount'] = ($hourlyWasteAggregates[$hKey]['amount'] ?? 0) + $eventAmount;
                    $hourlyWasteAggregates[$hKey]['cost_cents'] = ($hourlyWasteAggregates[$hKey]['cost_cents'] ?? 0) + $eventCostCents;
                    $hourlyWasteAggregates[$hKey]['income_cents'] = ($hourlyWasteAggregates[$hKey]['income_cents'] ?? 0) + $incomeLossCents;
                }
            } // end daily orders

            // Operational Manual Waste (12% chance on open days: ~1 to 2 incidents per week)
            if (mt_rand(1, 100) <= 12) {
                $mHour = mt_rand(13, 20);
                $mMin = mt_rand(5, 55);
                $mSec = mt_rand(5, 55);
                $mTime = sprintf('%s %02d:%02d:%02d', $dateStr, $mHour, $mMin, $mSec);

                $manualScenarios = [
                    [
                        'item' => 'Arla full cream milk',
                        'unit' => 'ml',
                        'amount' => 250.00,
                        'cents' => 2250,
                        'php' => 22.50,
                        'reason' => '[Mock] Steaming pitcher spilled on counter during rush'
                    ],
                    [
                        'item' => 'Emborg full cream milk',
                        'unit' => 'ml',
                        'amount' => 500.00,
                        'cents' => 4500,
                        'php' => 45.00,
                        'reason' => '[Mock] Opened milk carton past 48-hour shelf life in refrigerator'
                    ],
                    [
                        'item' => 'Matcha powder',
                        'unit' => 'g',
                        'amount' => 25.00,
                        'cents' => 2250,
                        'php' => 22.50,
                        'reason' => '[Mock] Measuring spoon dropped; powder spilled on prep station'
                    ],
                    [
                        'item' => 'Beans (Robusta, Arabica)',
                        'unit' => 'g',
                        'amount' => 36.00,
                        'cents' => 2880,
                        'php' => 28.80,
                        'reason' => '[Mock] Grinder calibration purge: uncalibrated grind discarded'
                    ],
                    [
                        'item' => 'Black tea base',
                        'unit' => 'ml',
                        'amount' => 500.00,
                        'cents' => 2500,
                        'php' => 25.00,
                        'reason' => '[Mock] Freshly brewed tea base reached end of holding time'
                    ],
                    [
                        'item' => 'Croissant',
                        'unit' => 'pcs',
                        'amount' => 1.00,
                        'cents' => 4500,
                        'php' => 45.00,
                        'reason' => '[Mock] Pastry crust burnt during toaster reheating'
                    ],
                    [
                        'item' => '16oz cup',
                        'unit' => 'pcs',
                        'amount' => 2.00,
                        'cents' => 600,
                        'php' => 6.00,
                        'reason' => '[Mock] Cracked rim discovered during drink assembly'
                    ],
                    [
                        'item' => '22oz cup',
                        'unit' => 'pcs',
                        'amount' => 2.00,
                        'cents' => 760,
                        'php' => 7.60,
                        'reason' => '[Mock] Cup fell on floor during restock unpack'
                    ],
                    [
                        'item' => 'Tapioca pearls (Boba)',
                        'unit' => 'g',
                        'amount' => 150.00,
                        'cents' => 2700,
                        'php' => 27.00,
                        'reason' => '[Mock] Leftover cooked pearls hardened overnight; discarded'
                    ],
                    [
                        'item' => 'Caramel sauce',
                        'unit' => 'ml',
                        'amount' => 80.00,
                        'cents' => 2400,
                        'php' => 24.00,
                        'reason' => '[Mock] Dispenser bottle tip clogged and leaked'
                    ],
                    [
                        'item' => 'Egg',
                        'unit' => 'pcs',
                        'amount' => 1.00,
                        'cents' => 900,
                        'php' => 9.00,
                        'reason' => '[Mock] Egg dropped on prep floor while cracking'
                    ],
                ];

                $scenario = $manualScenarios[array_rand($manualScenarios)];
                $rawItem = $invByName[strtolower($scenario['item'])] ?? $inventory[0];

                $allWasteLogs[] = [
                    'waste_id' => $wasteIdCounter++,
                    'transaction_id' => null,
                    'product_id' => null,
                    'product_name' => null,
                    'size_code' => null,
                    'size_label' => null,
                    'quantity' => 1,
                    'item_id' => (int)$rawItem['item_id'],
                    'item_name' => $rawItem['name'],
                    'unit' => $scenario['unit'],
                    'amount_wasted' => (float)$scenario['amount'],
                    'cost_cents' => (int)$scenario['cents'],
                    'estimated_cost_php' => (float)$scenario['php'],
                    'wasted_by_user_id' => 2,
                    'reason' => $scenario['reason'],
                    'wasted_at' => $mTime,
                ];

                $dailyWasteAggregates[$dateStr]['events'] = ($dailyWasteAggregates[$dateStr]['events'] ?? 0) + 1;
                $dailyWasteAggregates[$dateStr]['amount'] = ($dailyWasteAggregates[$dateStr]['amount'] ?? 0) + (float)$scenario['amount'];
                $dailyWasteAggregates[$dateStr]['cost_cents'] = ($dailyWasteAggregates[$dateStr]['cost_cents'] ?? 0) + (int)$scenario['cents'];
                $dailyWasteAggregates[$dateStr]['income_cents'] = ($dailyWasteAggregates[$dateStr]['income_cents'] ?? 0) + 0;

                $hKey = "{$dateStr}_{$mHour}";
                $hourlyWasteAggregates[$hKey]['date'] = $dateStr;
                $hourlyWasteAggregates[$hKey]['hour'] = $mHour;
                $hourlyWasteAggregates[$hKey]['events'] = ($hourlyWasteAggregates[$hKey]['events'] ?? 0) + 1;
                $hourlyWasteAggregates[$hKey]['amount'] = ($hourlyWasteAggregates[$hKey]['amount'] ?? 0) + (float)$scenario['amount'];
                $hourlyWasteAggregates[$hKey]['cost_cents'] = ($hourlyWasteAggregates[$hKey]['cost_cents'] ?? 0) + (int)$scenario['cents'];
                $hourlyWasteAggregates[$hKey]['income_cents'] = ($hourlyWasteAggregates[$hKey]['income_cents'] ?? 0) + 0;
            }
        } // end days in month

        $monthlyStats[$monthKey] = [
            'gross' => $monthGross,
            'completed' => $monthCompletedOrders,
            'cancelled' => $monthCancelledOrders,
            'open_days' => $monthOpenDays,
            'closed_days' => $monthClosedDays,
        ];

        printf("  %s | Open: %2dd | Closed: %2dd | Completed: %3d | Cancelled: %2d | Gross: PHP %9.2f\n",
            $monthKey, $monthOpenDays, $monthClosedDays, $monthCompletedOrders, $monthCancelledOrders, $monthGross);
    }
}

// Summary Statistics
$totalGrossAll = array_sum(array_column($monthlyStats, 'gross'));
$avgGrossMo = $totalGrossAll / count($monthlyStats);
$totalCompletedTx = count(array_filter($allTransactions, fn($t) => $t['status'] === 'Completed'));
$totalCancelledTx = count(array_filter($allTransactions, fn($t) => $t['status'] === 'Cancelled'));
$totalLineItems = count($allTransactionItems);
$totalOrderIngs = count($allOrderIngredients);
$totalWasteEvents = count($allWasteLogs);

logMsg("\n======================================================================");
logMsg("   GENERATION PROFILE SUMMARY                                         ");
logMsg("======================================================================");
logMsg("Total Gross Sales (24 Months): PHP " . number_format($totalGrossAll, 2));
logMsg("Average Monthly Sales:         PHP " . number_format($avgGrossMo, 2));
logMsg("Total Completed Orders:        " . number_format($totalCompletedTx));
logMsg("Total Cancelled Orders:        " . number_format($totalCancelledTx));
logMsg("Total Order Line Items:        " . number_format($totalLineItems));
logMsg("Total Order Ingredients Used:  " . number_format($totalOrderIngs));
logMsg("Total Waste Events:            " . number_format($totalWasteEvents));
logMsg("Daily Waste Aggregates:        " . count($dailyWasteAggregates));
logMsg("Hourly Waste Aggregates:       " . count($hourlyWasteAggregates));

// -----------------------------------------------------------------------------
// Direct Database Seeding
// -----------------------------------------------------------------------------
logMsg("\nInserting realistic dataset directly into MariaDB...");
$pdo->beginTransaction();

try {
    // 0. Clean prior mock records safely (never touch real transactions)
    logMsg("  - Purging previous mock records (preserving real transactions)...");
    $pdo->exec("DELETE FROM transaction_items WHERE transaction_id IN (SELECT transaction_id FROM transactions WHERE is_mock = 1)");
    $pdo->exec("DELETE FROM order_ingredients WHERE transaction_id IN (SELECT transaction_id FROM transactions WHERE is_mock = 1)");
    $pdo->exec("DELETE FROM waste_log WHERE transaction_id IN (SELECT transaction_id FROM transactions WHERE is_mock = 1) OR (transaction_id IS NULL AND (reason LIKE '%[Mock]%' OR reason LIKE '%pitcher spilled%' OR reason LIKE '%shelf life%' OR reason LIKE '%prep station%' OR reason LIKE '%calibration purge%' OR reason LIKE '%holding time%' OR reason LIKE '%crust burnt%' OR reason LIKE '%Cracked rim%' OR reason LIKE '%restock unpack%' OR reason LIKE '%hardened overnight%' OR reason LIKE '%clogged and leaked%' OR reason LIKE '%prep floor%'))");
    $pdo->exec("DELETE FROM transactions WHERE is_mock = 1");
    $pdo->exec("DELETE FROM waste_report_summary WHERE summary_date BETWEEN '2025-01-01' AND '2026-12-31'");

    // 1. Batch Insert Transactions
    logMsg("  - Inserting " . count($allTransactions) . " transactions...");
    $txStmt = $pdo->prepare("
        INSERT INTO transactions (
            transaction_id, cashier_user_id, total_amount, amount_paid, change_due,
            subtotal, tax, discount, discount_type, discount_rate, discount_id_no,
            discount_reason, status, cancelled_by_user_id, cancelled_at, cancellation_reason,
            payment_method, reference_number, is_mock, timestamp
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?
        )
    ");

    foreach ($allTransactions as $t) {
        $txStmt->execute([
            $t['transaction_id'],
            $t['cashier_user_id'],
            $t['total_amount'],
            $t['amount_paid'],
            $t['change_due'],
            $t['subtotal'],
            $t['tax'],
            $t['discount'],
            $t['discount_type'],
            $t['discount_rate'],
            $t['discount_id_no'],
            $t['discount_reason'],
            $t['status'],
            $t['cancelled_by_user_id'],
            $t['cancelled_at'],
            $t['cancellation_reason'],
            $t['payment_method'],
            $t['reference_number'],
            $t['is_mock'],
            $t['timestamp'],
        ]);
    }

    // 2. Batch Insert Transaction Items
    logMsg("  - Inserting " . count($allTransactionItems) . " order items...");
    $tiStmt = $pdo->prepare("
        INSERT INTO transaction_items (
            id, transaction_id, product_id, product_name, quantity,
            unit_price, size_code, size_label, size_oz
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?
        )
    ");

    foreach ($allTransactionItems as $ti) {
        $tiStmt->execute([
            $ti['id'],
            $ti['transaction_id'],
            $ti['product_id'],
            $ti['product_name'],
            $ti['quantity'],
            $ti['unit_price'],
            $ti['size_code'],
            $ti['size_label'],
            $ti['size_oz'],
        ]);
    }

    // 3. Batch Insert Order Ingredients
    logMsg("  - Inserting " . count($allOrderIngredients) . " immutable consumption snapshots...");
    $oiStmt = $pdo->prepare("
        INSERT INTO order_ingredients (
            id, transaction_id, item_id, item_name, unit, amount_used, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?
        )
    ");

    foreach ($allOrderIngredients as $oi) {
        $oiStmt->execute([
            $oi['id'],
            $oi['transaction_id'],
            $oi['item_id'],
            $oi['item_name'],
            $oi['unit'],
            $oi['amount_used'],
            $oi['created_at'],
        ]);
    }

    // 4. Batch Insert Waste Logs
    logMsg("  - Inserting " . count($allWasteLogs) . " waste audit log entries...");
    $wlStmt = $pdo->prepare("
        INSERT INTO waste_log (
            waste_id, transaction_id, product_id, product_name, size_code,
            size_label, quantity, item_id, item_name, unit, amount_wasted,
            cost_cents, estimated_cost_php, wasted_by_user_id, reason, wasted_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
    ");

    foreach ($allWasteLogs as $wl) {
        $wlStmt->execute([
            $wl['waste_id'],
            $wl['transaction_id'],
            $wl['product_id'],
            $wl['product_name'],
            $wl['size_code'],
            $wl['size_label'],
            $wl['quantity'],
            $wl['item_id'],
            $wl['item_name'],
            $wl['unit'],
            $wl['amount_wasted'],
            $wl['cost_cents'],
            $wl['estimated_cost_php'],
            $wl['wasted_by_user_id'],
            $wl['reason'],
            $wl['wasted_at'],
        ]);
    }

    // 5. Batch Insert Pre-aggregated Waste Summaries
    logMsg("  - Inserting " . (count($dailyWasteAggregates) + count($hourlyWasteAggregates)) . " pre-aggregated waste summaries...");
    $wsStmt = $pdo->prepare("
        INSERT INTO waste_report_summary (
            summary_id, summary_date, summary_hour, period_type,
            total_events, total_items_wasted, total_cost_cents,
            total_income_loss_cents, updated_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, NOW()
        )
        ON DUPLICATE KEY UPDATE
            total_events = total_events + VALUES(total_events),
            total_items_wasted = total_items_wasted + VALUES(total_items_wasted),
            total_cost_cents = total_cost_cents + VALUES(total_cost_cents),
            total_income_loss_cents = total_income_loss_cents + VALUES(total_income_loss_cents),
            updated_at = NOW()
    ");

    foreach ($dailyWasteAggregates as $dt => $agg) {
        $wsStmt->execute([
            $summaryIdCounter++,
            $dt,
            255,
            'daily',
            $agg['events'],
            $agg['amount'],
            $agg['cost_cents'],
            $agg['income_cents'],
        ]);
    }

    foreach ($hourlyWasteAggregates as $k => $agg) {
        $wsStmt->execute([
            $summaryIdCounter++,
            $agg['date'],
            $agg['hour'],
            'hourly',
            $agg['events'],
            $agg['amount'],
            $agg['cost_cents'],
            $agg['income_cents'],
        ]);
    }

    $pdo->commit();
    logMsg("  [SUCCESS] Database commit successful!");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logMsg("  [ERROR] Seeding failed: " . $e->getMessage());
    exit(1);
}

// -----------------------------------------------------------------------------
// Write Standalone SQL Export Files
// -----------------------------------------------------------------------------
$skipDump = in_array('--skip-sql-dump', $argv ?? [], true);
if (!$skipDump) {
    logMsg("\nGenerating standalone importable SQL dump...");
    $sqlFiles = [
        __DIR__ . '/cafeflow_mock_data_realistic.sql',
    ];

    foreach ($sqlFiles as $sqlPath) {
        $fp = fopen($sqlPath, 'wb');
        if (!$fp) continue;

        fwrite($fp, "-- ============================================================================\n");
        fwrite($fp, "-- CafeFlow POS - Realistic Mock Dataset for DK Cafe (Matabungkay, Batangas)\n");
        fwrite($fp, "-- Period: January 1, 2025 - December 31, 2026 (24 Calendar Months)\n");
        fwrite($fp, "-- Target Monthly Range: ₱18,000 - ₱30,000 (Average: ~₱" . number_format($avgGrossMo, 2) . ")\n");
        fwrite($fp, "-- Total Gross Sales: PHP " . number_format($totalGrossAll, 2) . "\n");
        fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "-- ============================================================================\n\n");

        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($fp, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($fp, "SET time_zone = '+08:00';\n\n");
        fwrite($fp, "START TRANSACTION;\n\n");

        fwrite($fp, "-- Clean existing mock data\n");
        fwrite($fp, "DELETE FROM `transaction_items` WHERE `transaction_id` IN (SELECT `transaction_id` FROM `transactions` WHERE `is_mock` = 1);\n");
        fwrite($fp, "DELETE FROM `order_ingredients` WHERE `transaction_id` IN (SELECT `transaction_id` FROM `transactions` WHERE `is_mock` = 1);\n");
        fwrite($fp, "DELETE FROM `waste_log` WHERE `transaction_id` IN (SELECT `transaction_id` FROM `transactions` WHERE `is_mock` = 1) OR (`transaction_id` IS NULL AND `reason` LIKE '%[Mock]%');\n");
        fwrite($fp, "DELETE FROM `transactions` WHERE `is_mock` = 1;\n");
        fwrite($fp, "DELETE FROM `waste_report_summary` WHERE `summary_date` BETWEEN '2025-01-01' AND '2026-12-31';\n\n");

        // Transactions chunks
        foreach (array_chunk($allTransactions, 200) as $chunk) {
            fwrite($fp, "INSERT INTO `transactions` (`transaction_id`, `cashier_user_id`, `total_amount`, `amount_paid`, `change_due`, `subtotal`, `tax`, `discount`, `discount_type`, `discount_rate`, `discount_id_no`, `discount_reason`, `status`, `cancelled_by_user_id`, `cancelled_at`, `cancellation_reason`, `payment_method`, `reference_number`, `is_mock`, `timestamp`) VALUES\n");
            $rows = [];
            foreach ($chunk as $t) {
                $cBy = $t['cancelled_by_user_id'] !== null ? (int)$t['cancelled_by_user_id'] : "NULL";
                $cAt = $t['cancelled_at'] !== null ? "'" . addslashes($t['cancelled_at']) . "'" : "NULL";
                $cRe = $t['cancellation_reason'] !== null ? "'" . addslashes($t['cancellation_reason']) . "'" : "NULL";
                $dId = $t['discount_id_no'] !== null ? "'" . addslashes($t['discount_id_no']) . "'" : "NULL";
                $dRe = $t['discount_reason'] !== null ? "'" . addslashes($t['discount_reason']) . "'" : "NULL";
                $ref = $t['reference_number'] !== null ? "'" . addslashes($t['reference_number']) . "'" : "NULL";

                $rows[] = sprintf(
                    "  (%d, %d, %.2f, %.2f, %.2f, %.2f, %.2f, %.2f, '%s', %.2f, %s, %s, '%s', %s, %s, %s, '%s', %s, %d, '%s')",
                    $t['transaction_id'], $t['cashier_user_id'], $t['total_amount'], $t['amount_paid'], $t['change_due'],
                    $t['subtotal'], $t['tax'], $t['discount'], addslashes($t['discount_type']), $t['discount_rate'], $dId, $dRe,
                    $t['status'], $cBy, $cAt, $cRe, $t['payment_method'], $ref, $t['is_mock'], $t['timestamp']
                );
            }
            fwrite($fp, implode(",\n", $rows) . ";\n\n");
        }

        // Transaction items chunks
        foreach (array_chunk($allTransactionItems, 250) as $chunk) {
            fwrite($fp, "INSERT INTO `transaction_items` (`id`, `transaction_id`, `product_id`, `product_name`, `quantity`, `unit_price`, `size_code`, `size_label`, `size_oz`) VALUES\n");
            $rows = [];
            foreach ($chunk as $it) {
                $sc = $it['size_code'] !== null ? "'" . addslashes($it['size_code']) . "'" : "NULL";
                $sl = $it['size_label'] !== null ? "'" . addslashes($it['size_label']) . "'" : "NULL";
                $so = $it['size_oz'] !== null ? (int)$it['size_oz'] : "NULL";

                $rows[] = sprintf(
                    "  (%d, %d, %d, '%s', %d, %.2f, %s, %s, %s)",
                    $it['id'], $it['transaction_id'], $it['product_id'], addslashes($it['product_name']), $it['quantity'],
                    $it['unit_price'], $sc, $sl, $so
                );
            }
            fwrite($fp, implode(",\n", $rows) . ";\n\n");
        }

        // Order ingredients chunks
        foreach (array_chunk($allOrderIngredients, 250) as $chunk) {
            fwrite($fp, "INSERT INTO `order_ingredients` (`id`, `transaction_id`, `item_id`, `item_name`, `unit`, `amount_used`, `created_at`) VALUES\n");
            $rows = [];
            foreach ($chunk as $oi) {
                $rows[] = sprintf(
                    "  (%d, %d, %d, '%s', '%s', %.2f, '%s')",
                    $oi['id'], $oi['transaction_id'], $oi['item_id'], addslashes($oi['item_name']), addslashes($oi['unit']), $oi['amount_used'], $oi['created_at']
                );
            }
            fwrite($fp, implode(",\n", $rows) . ";\n\n");
        }

        // Waste logs chunks
        foreach (array_chunk($allWasteLogs, 150) as $chunk) {
            fwrite($fp, "INSERT INTO `waste_log` (`waste_id`, `transaction_id`, `product_id`, `product_name`, `size_code`, `size_label`, `quantity`, `item_id`, `item_name`, `unit`, `amount_wasted`, `cost_cents`, `estimated_cost_php`, `wasted_by_user_id`, `reason`, `wasted_at`) VALUES\n");
            $rows = [];
            foreach ($chunk as $w) {
                $tx = $w['transaction_id'] !== null ? (int)$w['transaction_id'] : "NULL";
                $pid = $w['product_id'] !== null ? (int)$w['product_id'] : "NULL";
                $pn = $w['product_name'] !== null ? "'" . addslashes($w['product_name']) . "'" : "NULL";
                $sc = $w['size_code'] !== null ? "'" . addslashes($w['size_code']) . "'" : "NULL";
                $sl = $w['size_label'] !== null ? "'" . addslashes($w['size_label']) . "'" : "NULL";

                $rows[] = sprintf(
                    "  (%d, %s, %s, %s, %s, %s, %d, %d, '%s', '%s', %.2f, %d, %.2f, %d, '%s', '%s')",
                    $w['waste_id'], $tx, $pid, $pn, $sc, $sl, $w['quantity'], $w['item_id'], addslashes($w['item_name']),
                    addslashes($w['unit']), $w['amount_wasted'], $w['cost_cents'], $w['estimated_cost_php'], $w['wasted_by_user_id'],
                    addslashes($w['reason']), $w['wasted_at']
                );
            }
            fwrite($fp, implode(",\n", $rows) . ";\n\n");
        }

        fwrite($fp, "COMMIT;\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fp);
        logMsg("  - Wrote " . number_format(filesize($sqlPath) / 1024, 1) . " KB to " . basename($sqlPath));
    }
}

logMsg("\nAll seeding operations completed successfully!");
exit(0);
