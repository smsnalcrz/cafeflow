<?php
/**
 * tools/generate_1_year_mock_sql.php
 * Generates `database/cafeflow_mock_data_1_year.sql` containing 1 full year (12 completed months)
 * of realistic, relationally valid sales, payments, and waste data for CafeFlow POS.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';

$pdo = db();

// Load actual products
$products = $pdo->query("
    SELECT product_id, name, category, price, has_sizes, price_12oz, price_16oz, price_22oz
    FROM menu_products
    ORDER BY product_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sizePrices = $pdo->query("
    SELECT product_id, size_code, size_label, size_oz, price
    FROM product_size_prices
    ORDER BY product_id, size_oz ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sizesByProduct = [];
foreach ($sizePrices as $sp) {
    $sizesByProduct[(int)$sp['product_id']][] = $sp;
}

// Load actual inventory supplies
$inventory = $pdo->query("
    SELECT item_id, name, unit_measure, current_stock
    FROM inventory
    ORDER BY item_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$invByName = [];
foreach ($inventory as $inv) {
    $invByName[strtolower(trim($inv['name']))] = $inv;
}

// Calibrated monthly targets (Strictly ₱24,000 - ₱32,000, averaging ~₱27,200)
// 2 Full Calendar Years: 2025 (Jan - Dec) and 2026 (Jan - Dec)
$monthlyTargets = [
    // 2025 (Full Year: Jan - Dec)
    '2025-01' => 24800.00, // Post-holiday recovery
    '2025-02' => 25400.00, // Valentine's month boost
    '2025-03' => 26800.00, // Graduations / start of dry season
    '2025-04' => 26500.00, // Summer vacation & Holy Week
    '2025-05' => 27600.00, // Peak summer heat / cold beverage demand
    '2025-06' => 25900.00, // School opening transition
    '2025-07' => 25100.00, // Rainy season onset (Habagat)
    '2025-08' => 26200.00, // Mid-rainy season
    '2025-09' => 26900.00, // Ber-months commence
    '2025-10' => 27500.00, // Semestral break & Halloween
    '2025-11' => 27800.00, // Pre-holiday gathering build-up
    '2025-12' => 29600.00, // Christmas / holiday peak

    // 2026 (Full Year: Jan - Dec)
    '2026-01' => 25600.00, // Post-holiday dip
    '2026-02' => 26100.00, // Short month, Valentine's boost
    '2026-03' => 27400.00, // Summer start / graduations
    '2026-04' => 26800.00, // Holy week dip & summer cold drinks
    '2026-05' => 28200.00, // Summer vacation peak
    '2026-06' => 26600.00, // Back to school season
    '2026-07' => 25800.00, // Rainy season onset (Habagat)
    '2026-08' => 27100.00, // Mid-rainy season
    '2026-09' => 27700.00, // Ber months begin
    '2026-10' => 28400.00, // Semestral break & Halloween
    '2026-11' => 28900.00, // Pre-holiday build-up
    '2026-12' => 31200.00, // Christmas / year-end celebration peak
];

// Product popularity weights
$productWeights = [
    // Core Espresso (High volume)
    2  => 28, // Caffé Latte
    42 => 32, // Spanish Latte (Signature best-seller)
    43 => 22, // Caramel Macchiato
    41 => 24, // Americano
    4  => 16, // White Mocha
    6  => 15, // Salted Caramel
    7  => 12, // Biscoff Latte
    // Milk Series (High volume afternoon)
    28 => 20, // Strawberry Milk
    29 => 18, // Mango Milk
    30 => 14, // Blueberry Milk
    31 => 25, // Matcha Latte
    32 => 15, // Black Forest
    33 => 14, // Nata Strawberry
    34 => 13, // Nata Mango
    35 => 14, // Pearl Mango
    36 => 16, // Strawberry Matcha
    37 => 15, // White Choco Matcha
    // Milk Tea (Steady afternoon/evening)
    22 => 18, // Cream Cheese & Cake Milk Tea
    23 => 17, // Oreo Cream Cheese Milk Tea
    24 => 26, // Brown Sugar Pearl Milk Tea (Signature)
    25 => 15, // Cake Milk Tea
    26 => 16, // Oreo Milk Tea
    27 => 14, // Nata Milk Tea
    // Fruit Tea / Lemonade (Refresher)
    18 => 15, // Italian Soda
    19 => 18, // Kumquat Lemonade
    20 => 19, // Lemonade w/ Chia Seeds
    21 => 20, // DK Signature Passion Fruit
    // Classic Croffles
    8  => 14, // White Choco Croffle
    9  => 15, // Dark Choco Croffle
    10 => 18, // Nutella Croffle
    11 => 14, // Caramel Fudge Croffle
    12 => 22, // Biscoff Croffle (Best seller)
    // Premium Croffles
    13 => 16, // Biscoff Cream Croffle
    14 => 14, // Nutella Alcapone
    15 => 15, // Cookies & Cream
    16 => 13, // Banana Nutella
    17 => 14, // Strawberry Choco
    // Food & Pastry
    38 => 16, // Spam & Egg Sandwich Overload
    39 => 18, // Ham & Egg Sandwich Overload
    40 => 12, // Chicken Sandwich Overload
    44 => 25, // Butter Croissant
    45 => 28, // French Fries
];

// Helper to pick a weighted product
function pickWeightedProduct(array $products, array $weights): array {
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

// Helper to determine size and unit price
function resolveItemVariant(array $product, array $sizesByProduct): array {
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
    // For coffee: 65% Cold 16oz, 35% Hot 12oz
    if ($cat === 'coffee') {
        $isHot = (mt_rand(1, 100) <= 35);
        foreach ($sizes as $s) {
            if ($isHot && (str_contains(strtolower($s['size_code']), '12oz') || (int)$s['size_oz'] === 12)) {
                return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
            }
            if (!$isHot && (str_contains(strtolower($s['size_code']), '16oz') || (int)$s['size_oz'] === 16)) {
                return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
            }
        }
    }

    // For Milk Tea / Fruit Tea: 60% 16oz Tall, 40% 22oz Grande
    $pickGrande = (mt_rand(1, 100) <= 40);
    foreach ($sizes as $s) {
        if ($pickGrande && ((int)$s['size_oz'] === 22 || strtolower($s['size_code']) === '22oz')) {
            return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
        }
        if (!$pickGrande && ((int)$s['size_oz'] === 16 || strtolower($s['size_code']) === '16oz')) {
            return ['price' => (float)$s['price'], 'size_code' => $s['size_code'], 'size_label' => $s['size_label'], 'size_oz' => (int)$s['size_oz']];
        }
    }

    // Default to first available size
    $first = $sizes[0];
    return ['price' => (float)$first['price'], 'size_code' => $first['size_code'], 'size_label' => $first['size_label'], 'size_oz' => (int)$first['size_oz']];
}

echo "Starting Calibrated 1-Year Mock Data Generation...\n";

$txIdCounter = 20001;
$txItemIdCounter = 50001;
$wasteIdCounter = 10001;
$summaryIdCounter = 10001;

$allTransactions = [];
$allTransactionItems = [];
$allWasteLogs = [];
$dailyWasteAggregates = []; // key: YYYY-MM-DD
$hourlyWasteAggregates = []; // key: YYYY-MM-DD_HH

$monthlyGrossActual = [];

// Seed random generator for repeatable consistency
mt_srand(20261122);

// Time slot definitions across operating day (08:00 - 20:30)
$rushSlots = [
    // Morning rush
    ['h' => 8,  'm' => [15, 30, 45], 'weight' => 20],
    ['h' => 9,  'm' => [10, 25, 40], 'weight' => 25],
    ['h' => 10, 'm' => [05, 30, 50], 'weight' => 15],
    // Lunch rush
    ['h' => 11, 'm' => [45, 55],     'weight' => 18],
    ['h' => 12, 'm' => [15, 30, 45], 'weight' => 22],
    ['h' => 13, 'm' => [10, 25, 40], 'weight' => 16],
    // Afternoon snack / study
    ['h' => 14, 'm' => [20, 45],     'weight' => 20],
    ['h' => 15, 'm' => [15, 35, 55], 'weight' => 24],
    ['h' => 16, 'm' => [10, 30, 50], 'weight' => 22],
    // Evening unwind
    ['h' => 17, 'm' => [20, 40],     'weight' => 24],
    ['h' => 18, 'm' => [10, 25, 45], 'weight' => 28],
    ['h' => 19, 'm' => [15, 35, 50], 'weight' => 22],
    ['h' => 20, 'm' => [05, 20],     'weight' => 12],
];

// Iterate through each month
foreach ($monthlyTargets as $monthKey => $monthTarget) {
    [$year, $month] = explode('-', $monthKey);
    $year = (int)$year;
    $month = (int)$month;
    $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);

    $monthGrossAccumulated = 0.0;

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $remainingDays = ($daysInMonth - $d + 1);
        $remainingBudget = max(100.0, $monthTarget - $monthGrossAccumulated);
        $baseDailyTarget = $remainingBudget / $remainingDays;

        $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $d));
        $dow = (int)$dt->format('N'); // 1 = Mon, 7 = Sun
        $dayNum = (int)$dt->format('j');

        // Day of week factor
        $dowFactor = match($dow) {
            1 => 0.94,
            2 => 0.86, // slow Tuesday
            3 => 0.93,
            4 => 0.97,
            5 => 1.08, // Friday
            6 => 1.16, // Saturday busy
            7 => 1.10, // Sunday
        };

        // Quincena payday bump
        $paydayFactor = 1.0;
        if ($dayNum === 15 || $dayNum === $daysInMonth || $dayNum === 30) {
            $paydayFactor = 1.15;
        }

        // Random jitter (0.92 - 1.08)
        $jitter = 0.92 + (mt_rand(0, 160) / 1000.0);

        $dayTargetGross = $baseDailyTarget * $dowFactor * $paydayFactor * $jitter;
        if ($d === $daysInMonth) {
            // On the final day of month, close the exact gap to reach the target cleanly
            $dayTargetGross = max(400.0, $monthTarget - $monthGrossAccumulated);
        }

        $dayGrossAccumulated = 0.0;
        $orderCountToday = 0;

        // Keep adding orders until we are close to day target (within ~₱75 tolerance)
        while (true) {
            // Decide number of line items: 1 (65%), 2 (26%), 3 (8%), 4 (1%)
            $nRoll = mt_rand(1, 100);
            $numItems = 1;
            if ($nRoll > 99) {
                $numItems = 4;
            } elseif ($nRoll > 91) {
                $numItems = 3;
            } elseif ($nRoll > 65) {
                $numItems = 2;
            }

            $orderItems = [];
            $orderSubtotal = 0.0;

            for ($itemIdx = 0; $itemIdx < $numItems; $itemIdx++) {
                $prod = pickWeightedProduct($products, $productWeights);
                $variant = resolveItemVariant($prod, $sizesByProduct);
                $qty = (mt_rand(1, 100) <= 92) ? 1 : 2; // 92% qty 1, 8% qty 2
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
                ];

                $orderSubtotal += $lineTotal;
            }

            // Discount: 8% Senior/PWD
            $discount = 0.00;
            $discountType = 'none';
            $discountRate = 0.00;
            $discountId = null;
            $discountReason = null;

            if (mt_rand(1, 100) <= 8) {
                $discountType = (mt_rand(1, 2) === 1) ? 'Senior' : 'PWD';
                $discountRate = 0.20;
                $discount = round($orderSubtotal * 0.20, 2);
                $discountId = ($discountType === 'Senior')
                    ? 'OSCA-2024-' . mt_rand(1000, 9999)
                    : 'PWD-04-' . mt_rand(1000, 9999);
                $discountReason = "20% statutory discount for $discountType";
            }

            $orderTotal = round($orderSubtotal - $discount, 2);

            // Check if adding this order would overshoot target by too much
            if ($orderCountToday >= 3 && ($dayGrossAccumulated + $orderTotal) > ($dayTargetGross + 40.0) && $dayGrossAccumulated >= ($dayTargetGross * 0.88)) {
                // We have enough for this day
                // Roll back item IDs
                $txItemIdCounter -= count($orderItems);
                break;
            }

            $txId = $txIdCounter++;
            $orderCountToday++;

            // Pick time slot
            $slot = $rushSlots[array_rand($rushSlots)];
            $sec = mt_rand(5, 55);
            $min = $slot['m'][array_rand($slot['m'])];
            $hour = $slot['h'];
            $timestamp = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $d, $hour, $min, $sec);

            // Cashier rotation
            $cashierId = ($hour < 14) ? 1 : 6;
            if ($dow >= 6 && mt_rand(1, 100) <= 20) {
                $cashierId = 2; // Manager shift
            }

            // Payment method: 55% Cash, 45% GCash
            $isGCash = (mt_rand(1, 100) <= 45);
            $paymentMethod = $isGCash ? 'GCash' : 'Cash';
            $amountPaid = 0.00;
            $changeDue = 0.00;
            $refNumber = null;

            if ($isGCash) {
                $amountPaid = $orderTotal;
                $changeDue = 0.00;
                $refNumber = '100' . str_pad((string)mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
            } else {
                // Cash tender rounded to realistic bills
                if ($orderTotal <= 100.00) {
                    $amountPaid = 100.00;
                } elseif ($orderTotal <= 200.00) {
                    $amountPaid = (mt_rand(1, 100) <= 70) ? 200.00 : 500.00;
                } elseif ($orderTotal <= 300.00) {
                    $amountPaid = 500.00;
                } elseif ($orderTotal <= 500.00) {
                    $amountPaid = 500.00;
                } else {
                    $amountPaid = 1000.00;
                }
                $changeDue = round($amountPaid - $orderTotal, 2);
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
                'status' => 'Completed',
                'cancelled_by_user_id' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'payment_method' => $paymentMethod,
                'reference_number' => $refNumber,
                'is_mock' => 1,
                'timestamp' => $timestamp,
            ];

            foreach ($orderItems as $oi) {
                // Update transaction_id
                $oi['transaction_id'] = $txId;
                $allTransactionItems[] = $oi;
            }

            $dayGrossAccumulated += $orderTotal;
            $monthGrossAccumulated += $orderTotal;

            // Cap maximum orders per day (prevent infinite loop)
            if ($orderCountToday >= 10 || $dayGrossAccumulated >= $dayTargetGross) {
                break;
            }
        }

        // Occasional Cancelled Order (~1 to 2 times a week across the month)
        // 20% chance on any given day to have 1 cancelled order
        if (mt_rand(1, 100) <= 20) {
            $cTxId = $txIdCounter++;
            $cHour = mt_rand(9, 19);
            $cMin = mt_rand(10, 50);
            $cSec = mt_rand(10, 50);
            $cTimestamp = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $d, $cHour, $cMin, $cSec);
            $cCancelledAt = date('Y-m-d H:i:s', strtotime($cTimestamp) + mt_rand(180, 600));

            $cancelReasons = [
                'Customer had an urgent call and could not wait',
                'Customer changed mind on sweetness level after preparation started',
                'Barista accidentally entered duplicate ticket',
                'Customer requested dine-in switch after take-out beverage was brewed',
                'Customer discovered insufficient digital wallet balance',
                'Wrong drink variant prepared due to register communication error',
            ];
            $cReason = $cancelReasons[array_rand($cancelReasons)];

            // Cancelled order item (usually 1 beverage)
            $cProd = pickWeightedProduct($products, $productWeights);
            $cVariant = resolveItemVariant($cProd, $sizesByProduct);
            $cPrice = $cVariant['price'];

            $allTransactions[] = [
                'transaction_id' => $cTxId,
                'cashier_user_id' => 1,
                'total_amount' => $cPrice,
                'amount_paid' => $cPrice,
                'change_due' => 0.00,
                'subtotal' => $cPrice,
                'tax' => 0.00,
                'discount' => 0.00,
                'discount_type' => 'none',
                'discount_rate' => 0.00,
                'discount_id_no' => null,
                'discount_reason' => null,
                'status' => 'Cancelled',
                'cancelled_by_user_id' => 2, // Manager approval
                'cancelled_at' => $cCancelledAt,
                'cancellation_reason' => $cReason,
                'payment_method' => 'Cash',
                'reference_number' => null,
                'is_mock' => 1,
                'timestamp' => $cTimestamp,
            ];

            $allTransactionItems[] = [
                'id' => $txItemIdCounter++,
                'transaction_id' => $cTxId,
                'product_id' => (int)$cProd['product_id'],
                'product_name' => $cProd['name'],
                'quantity' => 1,
                'unit_price' => $cPrice,
                'size_code' => $cVariant['size_code'],
                'size_label' => $cVariant['size_label'],
                'size_oz' => $cVariant['size_oz'],
            ];

            // Record Waste for this cancelled order (decomposed ingredients)
            $cCat = strtolower($cProd['category'] ?? '');
            $isDrink = !in_array($cCat, ['croffle classic', 'croffle premium', 'sandwich', 'pastry', 'snacks'], true);

            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $hourInt = $cHour;

            if ($isDrink) {
                // Cup waste
                $cupName = ($cVariant['size_oz'] === 22) ? '22oz cup' : '16oz cup';
                $cupItem = $invByName[$cupName] ?? $invByName['16oz cup'];
                $allWasteLogs[] = [
                    'waste_id' => $wasteIdCounter++,
                    'transaction_id' => $cTxId,
                    'product_id' => (int)$cProd['product_id'],
                    'product_name' => $cProd['name'],
                    'size_code' => $cVariant['size_code'],
                    'size_label' => $cVariant['size_label'],
                    'quantity' => 1,
                    'item_id' => (int)$cupItem['item_id'],
                    'item_name' => $cupItem['name'],
                    'unit' => 'pcs',
                    'amount_wasted' => 1.00,
                    'cost_cents' => 350,
                    'estimated_cost_php' => 3.50,
                    'wasted_by_user_id' => 1,
                    'reason' => "Cancelled order loss: $cReason",
                    'wasted_at' => $cCancelledAt,
                ];

                // Milk / base waste
                $milkItem = $invByName['arla full cream milk'] ?? $invByName['full cream milk'];
                $allWasteLogs[] = [
                    'waste_id' => $wasteIdCounter++,
                    'transaction_id' => $cTxId,
                    'product_id' => (int)$cProd['product_id'],
                    'product_name' => $cProd['name'],
                    'size_code' => $cVariant['size_code'],
                    'size_label' => $cVariant['size_label'],
                    'quantity' => 1,
                    'item_id' => (int)$milkItem['item_id'],
                    'item_name' => $milkItem['name'],
                    'unit' => 'ml',
                    'amount_wasted' => 180.00,
                    'cost_cents' => 1620,
                    'estimated_cost_php' => 16.20,
                    'wasted_by_user_id' => 1,
                    'reason' => "Cancelled beverage ingredient loss: $cReason",
                    'wasted_at' => $cCancelledAt,
                ];

                // Track aggregates
                $eventLossCents = 350 + 1620;
                $eventItemsWasted = 1.0 + 180.0;
                $incomeLossCents = (int)round($cPrice * 100);

                $dailyWasteAggregates[$dateStr]['events'] = ($dailyWasteAggregates[$dateStr]['events'] ?? 0) + 2;
                $dailyWasteAggregates[$dateStr]['amount'] = ($dailyWasteAggregates[$dateStr]['amount'] ?? 0) + $eventItemsWasted;
                $dailyWasteAggregates[$dateStr]['cost_cents'] = ($dailyWasteAggregates[$dateStr]['cost_cents'] ?? 0) + $eventLossCents;
                $dailyWasteAggregates[$dateStr]['income_cents'] = ($dailyWasteAggregates[$dateStr]['income_cents'] ?? 0) + $incomeLossCents;

                $hourlyKey = "{$dateStr}_{$hourInt}";
                $hourlyWasteAggregates[$hourlyKey]['date'] = $dateStr;
                $hourlyWasteAggregates[$hourlyKey]['hour'] = $hourInt;
                $hourlyWasteAggregates[$hourlyKey]['events'] = ($hourlyWasteAggregates[$hourlyKey]['events'] ?? 0) + 2;
                $hourlyWasteAggregates[$hourlyKey]['amount'] = ($hourlyWasteAggregates[$hourlyKey]['amount'] ?? 0) + $eventItemsWasted;
                $hourlyWasteAggregates[$hourlyKey]['cost_cents'] = ($hourlyWasteAggregates[$hourlyKey]['cost_cents'] ?? 0) + $eventLossCents;
                $hourlyWasteAggregates[$hourlyKey]['income_cents'] = ($hourlyWasteAggregates[$hourlyKey]['income_cents'] ?? 0) + $incomeLossCents;
            } else {
                // Food / Pastry waste
                $foodItem = (str_contains($cCat, 'croffle') || str_contains($cCat, 'pastry'))
                    ? ($invByName['croissant'] ?? $inventory[20])
                    : ($invByName['french fries'] ?? $inventory[21]);

                $allWasteLogs[] = [
                    'waste_id' => $wasteIdCounter++,
                    'transaction_id' => $cTxId,
                    'product_id' => (int)$cProd['product_id'],
                    'product_name' => $cProd['name'],
                    'size_code' => null,
                    'size_label' => null,
                    'quantity' => 1,
                    'item_id' => (int)$foodItem['item_id'],
                    'item_name' => $foodItem['name'],
                    'unit' => $foodItem['unit_measure'],
                    'amount_wasted' => ($foodItem['unit_measure'] === 'pcs') ? 1.00 : 150.00,
                    'cost_cents' => 4500,
                    'estimated_cost_php' => 45.00,
                    'wasted_by_user_id' => 1,
                    'reason' => "Prepared food cancelled: $cReason",
                    'wasted_at' => $cCancelledAt,
                ];

                $eventLossCents = 4500;
                $eventItemsWasted = ($foodItem['unit_measure'] === 'pcs') ? 1.00 : 150.00;
                $incomeLossCents = (int)round($cPrice * 100);

                $dailyWasteAggregates[$dateStr]['events'] = ($dailyWasteAggregates[$dateStr]['events'] ?? 0) + 1;
                $dailyWasteAggregates[$dateStr]['amount'] = ($dailyWasteAggregates[$dateStr]['amount'] ?? 0) + $eventItemsWasted;
                $dailyWasteAggregates[$dateStr]['cost_cents'] = ($dailyWasteAggregates[$dateStr]['cost_cents'] ?? 0) + $eventLossCents;
                $dailyWasteAggregates[$dateStr]['income_cents'] = ($dailyWasteAggregates[$dateStr]['income_cents'] ?? 0) + $incomeLossCents;

                $hourlyKey = "{$dateStr}_{$hourInt}";
                $hourlyWasteAggregates[$hourlyKey]['date'] = $dateStr;
                $hourlyWasteAggregates[$hourlyKey]['hour'] = $hourInt;
                $hourlyWasteAggregates[$hourlyKey]['events'] = ($hourlyWasteAggregates[$hourlyKey]['events'] ?? 0) + 1;
                $hourlyWasteAggregates[$hourlyKey]['amount'] = ($hourlyWasteAggregates[$hourlyKey]['amount'] ?? 0) + $eventItemsWasted;
                $hourlyWasteAggregates[$hourlyKey]['cost_cents'] = ($hourlyWasteAggregates[$hourlyKey]['cost_cents'] ?? 0) + $eventLossCents;
                $hourlyWasteAggregates[$hourlyKey]['income_cents'] = ($hourlyWasteAggregates[$hourlyKey]['income_cents'] ?? 0) + $incomeLossCents;
            }
        }

        // Operational Manual Waste Incidents (e.g. 1-2 times per week)
        // 18% chance on any given day
        if (mt_rand(1, 100) <= 18) {
            $mHour = mt_rand(8, 20);
            $mMin = mt_rand(5, 55);
            $mSec = mt_rand(5, 55);
            $mTime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $d, $mHour, $mMin, $mSec);
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);

            $manualScenarios = [
                [
                    'item' => 'Arla full cream milk',
                    'unit' => 'ml',
                    'amount' => 250.00,
                    'cents' => 2250,
                    'php' => 22.50,
                    'reason' => 'Steaming pitcher spilled on counter during rush'
                ],
                [
                    'item' => 'Emborg full cream milk',
                    'unit' => 'ml',
                    'amount' => 500.00,
                    'cents' => 4500,
                    'php' => 45.00,
                    'reason' => 'Opened milk carton past 48-hour shelf life in refrigerator'
                ],
                [
                    'item' => 'Matcha powder',
                    'unit' => 'g',
                    'amount' => 30.00,
                    'cents' => 2700,
                    'php' => 27.00,
                    'reason' => 'Measuring spoon dropped; powder spilled on prep station'
                ],
                [
                    'item' => 'Beans (Robusta, Arabica)',
                    'unit' => 'g',
                    'amount' => 36.00,
                    'cents' => 2880,
                    'php' => 28.80,
                    'reason' => 'Grinder calibration purge: uncalibrated grind discarded'
                ],
                [
                    'item' => 'Black tea base',
                    'unit' => 'ml',
                    'amount' => 600.00,
                    'cents' => 3000,
                    'php' => 30.00,
                    'reason' => 'Freshly brewed tea base reached end of holding time'
                ],
                [
                    'item' => 'Croissant',
                    'unit' => 'pcs',
                    'amount' => 1.00,
                    'cents' => 4500,
                    'php' => 45.00,
                    'reason' => 'Pastry crust burnt during toaster reheating'
                ],
                [
                    'item' => '16oz cup',
                    'unit' => 'pcs',
                    'amount' => 2.00,
                    'cents' => 600,
                    'php' => 6.00,
                    'reason' => 'Cracked rim discovered during drink assembly'
                ],
                [
                    'item' => '22oz cup',
                    'unit' => 'pcs',
                    'amount' => 2.00,
                    'cents' => 760,
                    'php' => 7.60,
                    'reason' => 'Cup fell on floor during restock unpack'
                ],
                [
                    'item' => 'Tapioca pearls (Boba)',
                    'unit' => 'g',
                    'amount' => 200.00,
                    'cents' => 3600,
                    'php' => 36.00,
                    'reason' => 'Leftover cooked pearls hardened overnight; discarded'
                ],
                [
                    'item' => 'Caramel sauce',
                    'unit' => 'ml',
                    'amount' => 100.00,
                    'cents' => 3000,
                    'php' => 30.00,
                    'reason' => 'Dispenser bottle tip clogged and leaked'
                ],
                [
                    'item' => 'Egg',
                    'unit' => 'pcs',
                    'amount' => 1.00,
                    'cents' => 900,
                    'php' => 9.00,
                    'reason' => 'Egg dropped on prep floor while cracking'
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
                'wasted_by_user_id' => 2, // Logged by manager
                'reason' => $scenario['reason'],
                'wasted_at' => $mTime,
            ];

            $dailyWasteAggregates[$dateStr]['events'] = ($dailyWasteAggregates[$dateStr]['events'] ?? 0) + 1;
            $dailyWasteAggregates[$dateStr]['amount'] = ($dailyWasteAggregates[$dateStr]['amount'] ?? 0) + (float)$scenario['amount'];
            $dailyWasteAggregates[$dateStr]['cost_cents'] = ($dailyWasteAggregates[$dateStr]['cost_cents'] ?? 0) + (int)$scenario['cents'];
            $dailyWasteAggregates[$dateStr]['income_cents'] = ($dailyWasteAggregates[$dateStr]['income_cents'] ?? 0) + 0;

            $hourlyKey = "{$dateStr}_{$mHour}";
            $hourlyWasteAggregates[$hourlyKey]['date'] = $dateStr;
            $hourlyWasteAggregates[$hourlyKey]['hour'] = $mHour;
            $hourlyWasteAggregates[$hourlyKey]['events'] = ($hourlyWasteAggregates[$hourlyKey]['events'] ?? 0) + 1;
            $hourlyWasteAggregates[$hourlyKey]['amount'] = ($hourlyWasteAggregates[$hourlyKey]['amount'] ?? 0) + (float)$scenario['amount'];
            $hourlyWasteAggregates[$hourlyKey]['cost_cents'] = ($hourlyWasteAggregates[$hourlyKey]['cost_cents'] ?? 0) + (int)$scenario['cents'];
            $hourlyWasteAggregates[$hourlyKey]['income_cents'] = ($hourlyWasteAggregates[$hourlyKey]['income_cents'] ?? 0) + 0;
        }
    }

    $monthlyGrossActual[$monthKey] = $monthGrossAccumulated;
    echo sprintf("  Month %s: Target ₱%8.2f | Actual ₱%8.2f (Diff: %+.2f)\n",
        $monthKey, $monthTarget, $monthGrossAccumulated, $monthGrossAccumulated - $monthTarget);
}

// Summary Statistics
$totalYearGross = array_sum($monthlyGrossActual);
$avgMonthlyGross = $totalYearGross / count($monthlyGrossActual);
$totalCompletedTx = count(array_filter($allTransactions, fn($t) => $t['status'] === 'Completed'));
$totalCancelledTx = count(array_filter($allTransactions, fn($t) => $t['status'] === 'Cancelled'));
$totalLineItems = count($allTransactionItems);
$totalWasteEvents = count($allWasteLogs);

echo "\n=== GENERATION STATISTICS ===\n";
echo "Total Year Gross Sales:    ₱" . number_format($totalYearGross, 2) . "\n";
echo "Average Monthly Sales:     ₱" . number_format($avgMonthlyGross, 2) . "\n";
echo "Total Completed Orders:    " . number_format($totalCompletedTx) . "\n";
echo "Total Cancelled Orders:    " . number_format($totalCancelledTx) . "\n";
echo "Total Order Line Items:    " . number_format($totalLineItems) . "\n";
echo "Total Waste Log Records:   " . number_format($totalWasteEvents) . "\n";
echo "Total Daily Waste Rows:    " . count($dailyWasteAggregates) . "\n";
echo "Total Hourly Waste Rows:   " . count($hourlyWasteAggregates) . "\n";

// Serialize into standalone, importable SQL file
$sqlFile = __DIR__ . '/../database/cafeflow_mock_data_1_year.sql';
$fp = fopen($sqlFile, 'wb');
if (!$fp) {
    die("Failed to open $sqlFile for writing!\n");
}

fwrite($fp, "-- ============================================================================\n");
fwrite($fp, "-- CafeFlow POS - 2 Full Years of Realistic Mock Data (Small Café Scale)\n");
fwrite($fp, "-- Period: January 1, 2025 - December 31, 2026 (24 Full Calendar Months)\n");
fwrite($fp, "-- Average Monthly Gross Sales: ₱25,000 - ₱31,000 (Target Average: ~₱27,200)\n");
fwrite($fp, "-- Total Gross Sales: ₱" . number_format($totalYearGross, 2) . "\n");
fwrite($fp, "-- Transactions: " . number_format($totalCompletedTx) . " Completed, " . number_format($totalCancelledTx) . " Cancelled\n");
fwrite($fp, "-- Line Items: " . number_format($totalLineItems) . "\n");
fwrite($fp, "-- Waste Records: " . number_format($totalWasteEvents) . "\n");
fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($fp, "-- ============================================================================\n\n");

fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n");
fwrite($fp, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
fwrite($fp, "SET time_zone = '+08:00';\n\n");
fwrite($fp, "START TRANSACTION;\n\n");

// 0. Clean previous mock data for idempotent re-runs
fwrite($fp, "-- ----------------------------------------------------------------------------\n");
fwrite($fp, "-- 0. Clean Previous Mock Data (Preserves Audit Integrity & Non-Mock Transactions)\n");
fwrite($fp, "-- ----------------------------------------------------------------------------\n");
fwrite($fp, "DELETE FROM `transaction_items` WHERE `transaction_id` IN (SELECT `transaction_id` FROM `transactions` WHERE `is_mock` = 1);\n");
fwrite($fp, "DELETE FROM `waste_log` WHERE `transaction_id` IN (SELECT `transaction_id` FROM `transactions` WHERE `is_mock` = 1) OR `wasted_at` BETWEEN '2025-01-01 00:00:00' AND '2026-12-31 23:59:59';\n");
fwrite($fp, "DELETE FROM `transactions` WHERE `is_mock` = 1;\n");
fwrite($fp, "DELETE FROM `waste_report_summary` WHERE `summary_date` BETWEEN '2025-01-01' AND '2026-12-31';\n\n");

// 1. Transactions batch insert
fwrite($fp, "-- ----------------------------------------------------------------------------\n");
fwrite($fp, "-- 1. Sales & Order Transactions (24 Months: Jan 2025 - Dec 2026)\n");
fwrite($fp, "-- ----------------------------------------------------------------------------\n");

$batchSize = 250;
$txChunks = array_chunk($allTransactions, $batchSize);
foreach ($txChunks as $chunk) {
    fwrite($fp, "INSERT INTO `transactions` (\n");
    fwrite($fp, "  `transaction_id`, `cashier_user_id`, `total_amount`, `amount_paid`, `change_due`,\n");
    fwrite($fp, "  `subtotal`, `tax`, `discount`, `discount_type`, `discount_rate`, `discount_id_no`,\n");
    fwrite($fp, "  `discount_reason`, `status`, `cancelled_by_user_id`, `cancelled_at`, `cancellation_reason`,\n");
    fwrite($fp, "  `payment_method`, `reference_number`, `is_mock`, `timestamp`\n");
    fwrite($fp, ") VALUES\n");

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
            $t['transaction_id'],
            $t['cashier_user_id'],
            $t['total_amount'],
            $t['amount_paid'],
            $t['change_due'],
            $t['subtotal'],
            $t['tax'],
            $t['discount'],
            addslashes($t['discount_type']),
            $t['discount_rate'],
            $dId,
            $dRe,
            $t['status'],
            $cBy,
            $cAt,
            $cRe,
            $t['payment_method'],
            $ref,
            $t['is_mock'],
            $t['timestamp']
        );
    }
    fwrite($fp, implode(",\n", $rows) . ";\n\n");
}

// 2. Transaction items batch insert
fwrite($fp, "-- ----------------------------------------------------------------------------\n");
fwrite($fp, "-- 2. Transaction Line Items\n");
fwrite($fp, "-- ----------------------------------------------------------------------------\n");

$itemChunks = array_chunk($allTransactionItems, 350);
foreach ($itemChunks as $chunk) {
    fwrite($fp, "INSERT INTO `transaction_items` (\n");
    fwrite($fp, "  `id`, `transaction_id`, `product_id`, `product_name`, `quantity`,\n");
    fwrite($fp, "  `unit_price`, `size_code`, `size_label`, `size_oz`\n");
    fwrite($fp, ") VALUES\n");

    $rows = [];
    foreach ($chunk as $it) {
        $scode = $it['size_code'] !== null ? "'" . addslashes($it['size_code']) . "'" : "NULL";
        $slabel = $it['size_label'] !== null ? "'" . addslashes($it['size_label']) . "'" : "NULL";
        $soz = $it['size_oz'] !== null ? (int)$it['size_oz'] : "NULL";

        $rows[] = sprintf(
            "  (%d, %d, %d, '%s', %d, %.2f, %s, %s, %s)",
            $it['id'],
            $it['transaction_id'],
            $it['product_id'],
            addslashes($it['product_name']),
            $it['quantity'],
            $it['unit_price'],
            $scode,
            $slabel,
            $soz
        );
    }
    fwrite($fp, implode(",\n", $rows) . ";\n\n");
}

// 3. Waste Log insert
fwrite($fp, "-- ----------------------------------------------------------------------------\n");
fwrite($fp, "-- 3. Waste & Loss Audit Log (Spills, Expired, Damaged, Cancelled Orders)\n");
fwrite($fp, "-- ----------------------------------------------------------------------------\n");

$wasteChunks = array_chunk($allWasteLogs, 150);
foreach ($wasteChunks as $chunk) {
    fwrite($fp, "INSERT INTO `waste_log` (\n");
    fwrite($fp, "  `waste_id`, `transaction_id`, `product_id`, `product_name`, `size_code`,\n");
    fwrite($fp, "  `size_label`, `quantity`, `item_id`, `item_name`, `unit`, `amount_wasted`,\n");
    fwrite($fp, "  `cost_cents`, `estimated_cost_php`, `wasted_by_user_id`, `reason`, `wasted_at`\n");
    fwrite($fp, ") VALUES\n");

    $rows = [];
    foreach ($chunk as $w) {
        $tx = $w['transaction_id'] !== null ? (int)$w['transaction_id'] : "NULL";
        $pid = $w['product_id'] !== null ? (int)$w['product_id'] : "NULL";
        $pname = $w['product_name'] !== null ? "'" . addslashes($w['product_name']) . "'" : "NULL";
        $scode = $w['size_code'] !== null ? "'" . addslashes($w['size_code']) . "'" : "NULL";
        $slabel = $w['size_label'] !== null ? "'" . addslashes($w['size_label']) . "'" : "NULL";

        $rows[] = sprintf(
            "  (%d, %s, %s, %s, %s, %s, %d, %d, '%s', '%s', %.2f, %d, %.2f, %d, '%s', '%s')",
            $w['waste_id'],
            $tx,
            $pid,
            $pname,
            $scode,
            $slabel,
            $w['quantity'],
            $w['item_id'],
            addslashes($w['item_name']),
            addslashes($w['unit']),
            $w['amount_wasted'],
            $w['cost_cents'],
            $w['estimated_cost_php'],
            $w['wasted_by_user_id'],
            addslashes($w['reason']),
            $w['wasted_at']
        );
    }
    fwrite($fp, implode(",\n", $rows) . ";\n\n");
}

// 4. Pre-aggregated Waste Report Summary insert
fwrite($fp, "-- ----------------------------------------------------------------------------\n");
fwrite($fp, "-- 4. Pre-aggregated Waste Report Summaries (Daily & Hourly)\n");
fwrite($fp, "-- ----------------------------------------------------------------------------\n");

$summaryRows = [];
// Daily rows (summary_hour = 255)
foreach ($dailyWasteAggregates as $dt => $agg) {
    $summaryRows[] = sprintf(
        "  (%d, '%s', 255, 'daily', %d, %.2f, %d, %d, NOW())",
        $summaryIdCounter++,
        $dt,
        $agg['events'],
        $agg['amount'],
        $agg['cost_cents'],
        $agg['income_cents']
    );
}

// Hourly rows
foreach ($hourlyWasteAggregates as $key => $agg) {
    $summaryRows[] = sprintf(
        "  (%d, '%s', %d, 'hourly', %d, %.2f, %d, %d, NOW())",
        $summaryIdCounter++,
        $agg['date'],
        $agg['hour'],
        $agg['events'],
        $agg['amount'],
        $agg['cost_cents'],
        $agg['income_cents']
    );
}

$summaryChunks = array_chunk($summaryRows, 150);
foreach ($summaryChunks as $chunk) {
    fwrite($fp, "INSERT INTO `waste_report_summary` (\n");
    fwrite($fp, "  `summary_id`, `summary_date`, `summary_hour`, `period_type`,\n");
    fwrite($fp, "  `total_events`, `total_items_wasted`, `total_cost_cents`,\n");
    fwrite($fp, "  `total_income_loss_cents`, `updated_at`\n");
    fwrite($fp, ") VALUES\n");
    fwrite($fp, implode(",\n", $chunk) . "\n");
    fwrite($fp, "ON DUPLICATE KEY UPDATE\n");
    fwrite($fp, "  `total_events` = `total_events` + VALUES(`total_events`),\n");
    fwrite($fp, "  `total_items_wasted` = `total_items_wasted` + VALUES(`total_items_wasted`),\n");
    fwrite($fp, "  `total_cost_cents` = `total_cost_cents` + VALUES(`total_cost_cents`),\n");
    fwrite($fp, "  `total_income_loss_cents` = `total_income_loss_cents` + VALUES(`total_income_loss_cents`),\n");
    fwrite($fp, "  `updated_at` = NOW();\n\n");
}

fwrite($fp, "COMMIT;\n");
fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fp);

echo "Successfully written standalone SQL file to:\n";
echo "$sqlFile\n";
echo "File size: " . number_format(filesize($sqlFile) / 1024, 1) . " KB\n";
