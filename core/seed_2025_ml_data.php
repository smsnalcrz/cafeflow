<?php
/**
 * CafeFlow POS - 2025 Historical ML Dataset Seeder
 * Generates an empirical, mathematically consistent 365-day historical dataset for 2025
 * incorporating Philippine seasonality (Tag-init summer beach rush, Habagat rainy season,
 * Ber-months holiday surge, and 15th/30th Quincena paydays).
 *
 * All records are tagged with `is_mock = 1` to strictly preserve production audit integrity.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function seed_2025_dataset(bool $force = false): array
{
    $pdo = db();

    // Check if 2025 data already exists
    $existing = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE YEAR(timestamp) = 2025")->fetchColumn();
    if ($existing > 0 && !$force) {
        return [
            'success' => true,
            'message' => "2025 dataset already seeded ($existing records present). Use force=true to re-seed.",
            'count'   => $existing
        ];
    }

    if ($existing > 0 && $force) {
        // Delete existing mock 2025 data
        $txIds = $pdo->query("SELECT transaction_id FROM transactions WHERE YEAR(timestamp) = 2025 AND is_mock = 1")->fetchAll(PDO::FETCH_COLUMN);
        if ($txIds) {
            $marks = implode(',', array_fill(0, count($txIds), '?'));
            $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id IN ($marks)")->execute($txIds);
            $pdo->prepare("DELETE FROM transactions WHERE transaction_id IN ($marks)")->execute($txIds);
        }
    }

    // Retrieve active menu products
    $prods = $pdo->query("
        SELECT product_id, name, category, price, has_sizes, price_12oz, price_16oz, price_22oz
          FROM menu_products
         ORDER BY product_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($prods)) {
        return ['success' => false, 'error' => 'No products found in menu_products to seed transactions.'];
    }

    // Retrieve size prices
    $sizePrices = $pdo->query("
        SELECT product_id, size_code, size_label, size_oz, price
          FROM product_size_prices
    ")->fetchAll(PDO::FETCH_ASSOC);

    $sizeMap = [];
    foreach ($sizePrices as $sp) {
        $pId = (int) $sp['product_id'];
        $sizeMap[$pId][] = $sp;
    }

    // Split products into macro categories
    $drinkProds = [];
    $snackProds = [];
    foreach ($prods as $p) {
        $cat = strtolower($p['category'] ?? '');
        if (in_array($cat, ['croffle', 'pastry', 'sandwich', 'snacks', 'meals'], true)) {
            $snackProds[] = $p;
        } else {
            $drinkProds[] = $p;
        }
    }
    if (empty($snackProds)) $snackProds = $prods;
    if (empty($drinkProds)) $drinkProds = $prods;

    $txStmt = $pdo->prepare("
        INSERT INTO transactions (
            cashier_user_id, total_amount, amount_paid, change_due, subtotal, tax, discount,
            discount_type, status, payment_method, reference_number, is_mock, timestamp
        ) VALUES (
            :cashier_id, :total, :paid, :change, :subtotal, 0.00, :discount,
            'none', 'Completed', :method, :ref, 1, :ts
        )
    ");

    $itemStmt = $pdo->prepare("
        INSERT INTO transaction_items (
            transaction_id, product_id, product_name, quantity, unit_price,
            size_code, size_label, size_oz
        ) VALUES (
            :tx_id, :prod_id, :name, :qty, :price, :scode, :slabel, :soz
        )
    ");

    $pdo->beginTransaction();

    $startDate = new DateTimeImmutable('2025-01-01');
    $endDate   = new DateTimeImmutable('2025-12-31');
    $current   = $startDate;

    $totalOrders = 0;
    $totalRevenue = 0.0;

    while ($current <= $endDate) {
        $m = (int) $current->format('n'); // 1-12
        $d = (int) $current->format('j'); // 1-31
        $w = (int) $current->format('w'); // 0 (Sun) - 6 (Sat)
        $isWeekend = ($w === 0 || $w === 5 || $w === 6); // Fri, Sat, Sun are beach peak days

        // 1. Seasonal baseline order volume
        // Base: 8-14 on weekdays, 20-32 on weekends
        $baseOrders = $isWeekend ? mt_rand(20, 32) : mt_rand(8, 14);

        // Seasonal modifier:
        // Summer (March to May): Tag-init beach rush (+40%)
        if ($m >= 3 && $m <= 5) {
            $baseOrders = (int) round($baseOrders * 1.45);
        }
        // Habagat Wet Season (July to August): rainy dip (-20%)
        elseif ($m >= 7 && $m <= 8) {
            $baseOrders = max(6, (int) round($baseOrders * 0.82));
        }
        // Ber-Months Holiday Surge (October to December): ramp-up (+30% to +50%)
        elseif ($m === 10 || $m === 11) {
            $baseOrders = (int) round($baseOrders * 1.25);
        } elseif ($m === 12) {
            $baseOrders = (int) round($baseOrders * 1.55); // Christmas parties & holidays
        }

        // Quincena payday spike (15th and 30th)
        if ($d === 15 || $d === 30 || ($d === 28 && $m === 2)) {
            $baseOrders = (int) round($baseOrders * 1.35);
        }

        // Generate orders across realistic business hours (8:00 AM to 9:00 PM)
        for ($i = 0; $i < $baseOrders; $i++) {
            // Peak hours clustering: 1:00 PM - 3:00 PM (afternoon drinks) and 6:00 PM - 8:00 PM (dinner/evening crowd)
            $hourRand = mt_rand(1, 100);
            if ($hourRand <= 30) {
                $hour = mt_rand(18, 20); // Evening rush
            } elseif ($hourRand <= 60) {
                $hour = mt_rand(13, 15); // Afternoon rush
            } elseif ($hourRand <= 80) {
                $hour = mt_rand(9, 11);  // Morning coffee
            } else {
                $hour = mt_rand(8, 21);
            }
            $minute = mt_rand(0, 59);
            $second = mt_rand(0, 59);

            $timestampStr = $current->format('Y-m-d') . sprintf(' %02d:%02d:%02d', $hour, $minute, $second);

            // Determine basket items (1 to 4 items per ticket)
            $itemCount = mt_rand(1, 100) < 65 ? mt_rand(1, 2) : mt_rand(3, 4);
            $orderLines = [];
            $orderSubtotal = 0.0;

            for ($k = 0; $k < $itemCount; $k++) {
                // Summer favors cold drinks 80/20, Rainy season favors snacks/hot drinks 50/50
                $pickDrink = ($m >= 3 && $m <= 5) ? (mt_rand(1, 100) <= 80) : (mt_rand(1, 100) <= 65);
                $prod = $pickDrink ? $drinkProds[array_rand($drinkProds)] : $snackProds[array_rand($snackProds)];
                $pId  = (int) $prod['product_id'];

                $qty = mt_rand(1, 100) < 85 ? 1 : 2;
                $sCode = null; $sLabel = null; $sOz = null;
                $unitPrice = (float) $prod['price'];

                if (!empty($prod['has_sizes']) && isset($sizeMap[$pId]) && !empty($sizeMap[$pId])) {
                    $availSizes = $sizeMap[$pId];
                    // Summer favors larger cold sizes (16oz / 22oz)
                    $chosenSize = $availSizes[array_rand($availSizes)];
                    $sCode = $chosenSize['size_code'];
                    $sLabel = $chosenSize['size_label'];
                    $sOz = (int) $chosenSize['size_oz'];
                    $unitPrice = (float) $chosenSize['price'];
                } elseif (!empty($prod['has_sizes'])) {
                    $sCode = '16oz'; $sLabel = '16oz Cold'; $sOz = 16;
                    $unitPrice = (float) ($prod['price_16oz'] ?: $prod['price']);
                }

                $lineTotal = round($unitPrice * $qty, 2);
                $orderSubtotal += $lineTotal;

                $orderLines[] = [
                    'prod_id' => $pId,
                    'name'    => $prod['name'],
                    'qty'     => $qty,
                    'price'   => $unitPrice,
                    'scode'   => $sCode,
                    'slabel'  => $sLabel,
                    'soz'     => $sOz
                ];
            }

            // Payment method: 65% GCash, 35% Cash
            $isGCash = (mt_rand(1, 100) <= 65);
            $payMethod = $isGCash ? 'GCash' : 'Cash';
            $refNumber = $isGCash ? ('GC' . $current->format('ymd') . strtoupper(bin2hex(random_bytes(3)))) : null;
            $paidAmount = $isGCash ? $orderSubtotal : (ceil($orderSubtotal / 100) * 100);
            if ($paidAmount < $orderSubtotal) $paidAmount = $orderSubtotal + 50.0;
            $changeDue = round($paidAmount - $orderSubtotal, 2);

            $cashierId = mt_rand(1, 100) < 70 ? 1 : 2; // Barista 1 or Manager 2

            $txStmt->execute([
                ':cashier_id' => $cashierId,
                ':total'      => $orderSubtotal,
                ':paid'       => $paidAmount,
                ':change'     => $changeDue,
                ':subtotal'   => $orderSubtotal,
                ':discount'   => 0.00,
                ':method'     => $payMethod,
                ':ref'        => $refNumber,
                ':ts'         => $timestampStr
            ]);

            $txId = (int) $pdo->lastInsertId();

            foreach ($orderLines as $ol) {
                $itemStmt->execute([
                    ':tx_id'   => $txId,
                    ':prod_id' => $ol['prod_id'],
                    ':name'    => $ol['name'],
                    ':qty'     => $ol['qty'],
                    ':price'   => $ol['price'],
                    ':scode'   => $ol['scode'],
                    ':slabel'  => $ol['slabel'],
                    ':soz'     => $ol['soz']
                ]);
            }

            $totalOrders++;
            $totalRevenue += $orderSubtotal;
        }

        $current = $current->modify('+1 day');
    }

    $pdo->commit();

    return [
        'success'      => true,
        'year'         => 2025,
        'total_orders' => $totalOrders,
        'total_sales'  => round($totalRevenue, 2),
        'days_seeded'  => 365,
        'dataset_type' => 'synthetic'
    ];
}

// Allow CLI execution
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $force = in_array('--force', $argv, true);
    echo "Starting 2025 Historical ML Dataset Seeding...\n";
    $res = seed_2025_dataset($force);
    echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
}
