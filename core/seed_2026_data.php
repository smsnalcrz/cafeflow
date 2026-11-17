<?php
/**
 * CafeFlow POS - 2026 Calibrated Empirical Dataset Seeder
 *
 * Specifically calibrated for DK Cafe (Matabungkay, Lian, Batangas):
 * - Target Monthly Revenue: ~PHP 20,000 to PHP 30,000 / month (~PHP 650 to PHP 1,050 / day)
 * - Quaint coastal cafe volume:
 *     * Weekdays (Mon - Thu): 2 to 5 orders/day
 *     * Weekends (Fri - Sun): 5 to 9 orders/day
 *     * Matabungkay beach summer peak (March - May): ~PHP 28,000 - PHP 33,000/mo
 *     * Habagat rainy season dip (July - August): ~PHP 19,000 - PHP 22,000/mo
 *     * Ber-months holiday surge (October - December): ~PHP 25,000 - PHP 32,000/mo
 * - Realistic basket sizes: 72% single item, 23% two items (drink + snack combo), 5% three items
 * - Realistic AOV: ~PHP 170 - PHP 210
 * - Payment mix: ~60% GCash, ~40% Cash
 * - Discounts: Senior / PWD / Promo (~10% prevalence)
 * - Cashiers: Active staff rotation (Barista, tan_smsn, Manager)
 * - Audit Tag: `is_mock = 1`
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function seed_2026_dataset(bool $force = false): array
{
    $pdo = db();

    // 1. Check existing 2026 data
    $existing = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE YEAR(timestamp) = 2026")->fetchColumn();
    if ($existing > 0 && !$force) {
        return [
            'success' => true,
            'message' => "2026 dataset already seeded ($existing records present). Use --force to re-seed.",
            'count'   => $existing
        ];
    }

    if ($existing > 0 && $force) {
        echo "Clearing previous 2026 mock records...\n";
        $txIds = $pdo->query("SELECT transaction_id FROM transactions WHERE YEAR(timestamp) = 2026 AND is_mock = 1")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($txIds)) {
            $chunks = array_chunk($txIds, 1000);
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id IN ($placeholders)")->execute($chunk);
                $pdo->prepare("DELETE FROM transactions WHERE transaction_id IN ($placeholders)")->execute($chunk);
            }
        }
    }

    // 2. Fetch menu products
    $prods = $pdo->query("
        SELECT product_id, name, category, price, has_sizes, price_12oz, price_16oz, price_22oz
          FROM menu_products
         ORDER BY product_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($prods)) {
        return ['success' => false, 'error' => 'No products found in menu_products to seed transactions.'];
    }

    // 3. Fetch product sizes
    $sizeRows = $pdo->query("
        SELECT product_id, size_code, size_label, size_oz, price
          FROM product_size_prices
         ORDER BY product_id, size_oz ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $sizeMap = [];
    foreach ($sizeRows as $s) {
        $pId = (int) $s['product_id'];
        $sizeMap[$pId][] = $s;
    }

    // 4. Categorize products
    $hotCoffeeProds  = [];
    $coldCoffeeProds = [];
    $refreshers      = []; // Fruit Tea, Lemonade, Soda
    $milkSeries      = []; // Milk Series, Milk Tea
    $croffles        = []; // Croffle Classic & Premium
    $savoryFood      = []; // Sandwiches, Fries, Pastries

    foreach ($prods as $p) {
        $cat = strtolower($p['category']);

        if ($cat === 'coffee') {
            $hotCoffeeProds[]  = $p;
            $coldCoffeeProds[] = $p;
        } elseif (str_contains($cat, 'croffle')) {
            $croffles[] = $p;
        } elseif (str_contains($cat, 'fruit tea') || str_contains($cat, 'lemonade')) {
            $refreshers[] = $p;
        } elseif (str_contains($cat, 'milk tea') || str_contains($cat, 'milk series')) {
            $milkSeries[] = $p;
        } elseif (in_array($cat, ['pastry', 'sandwich', 'snacks'], true)) {
            $savoryFood[] = $p;
        } else {
            $refreshers[] = $p;
        }
    }

    // 5. Cashier staff
    $cashierUserIds = $pdo->query("SELECT user_id FROM users WHERE status = 'active' ORDER BY user_id ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($cashierUserIds)) {
        $cashierUserIds = [1, 2];
    }

    // 6. Prepared statements
    $txInsertSql = "
        INSERT INTO transactions (
            cashier_user_id, total_amount, amount_paid, change_due,
            subtotal, tax, discount, discount_type, discount_rate, discount_id_no, discount_reason,
            status, cancelled_by_user_id, cancelled_at, cancellation_reason,
            payment_method, reference_number, is_mock, timestamp
        ) VALUES (
            :cashier_id, :total, :paid, :change,
            :subtotal, 0.00, :discount, :disc_type, :disc_rate, :disc_id, :disc_reason,
            :status, :cancel_user, :cancel_at, :cancel_reason,
            :method, :ref, 1, :ts
        )
    ";
    $txStmt = $pdo->prepare($txInsertSql);

    $itemInsertSql = "
        INSERT INTO transaction_items (
            transaction_id, product_id, product_name, quantity, unit_price,
            size_code, size_label, size_oz
        ) VALUES (
            :tx_id, :prod_id, :name, :qty, :price,
            :scode, :slabel, :soz
        )
    ";
    $itemStmt = $pdo->prepare($itemInsertSql);

    // 7. Calibrated Simulation for 2026 (Jan 1 to Dec 31)
    $startDate = new DateTimeImmutable('2026-01-01');
    $endDate   = new DateTimeImmutable('2026-12-31');
    $current   = $startDate;

    $totalOrdersCount = 0;
    $completedOrdersCount = 0;
    $totalGrossRevenue = 0.0;
    $totalItemsCount = 0;

    echo "Generating calibrated 2026 DK Cafe dataset (Target: PHP 20k-30k/mo)...\n";
    $startTime = microtime(true);

    $pdo->beginTransaction();

    while ($current <= $endDate) {
        $month = (int) $current->format('n'); // 1 - 12
        $day   = (int) $current->format('j'); // 1 - 31
        $dow   = (int) $current->format('w'); // 0 (Sun) - 6 (Sat)
        $dateStr = $current->format('Y-m-d');

        $isFriday   = ($dow === 5);
        $isSaturday = ($dow === 6);
        $isSunday   = ($dow === 0);
        $isWeekend  = ($isFriday || $isSaturday || $isSunday);

        // DK Cafe Baseline Daily Orders:
        // Weekdays: 2 to 4 orders
        // Weekends: 5 to 7 orders
        $orderCount = $isWeekend ? mt_rand(5, 7) : mt_rand(2, 4);

        // Seasonal modifier:
        // Summer beach months in Matabungkay (March - May): +30%
        if ($month >= 3 && $month <= 5) {
            $orderCount = (int) round($orderCount * 1.30);
        }
        // Habagat rainy lull (July - August): -20%
        elseif ($month >= 7 && $month <= 8) {
            $orderCount = max(1, (int) round($orderCount * 0.80));
        }
        // Ber-months holiday ramp-up (October - November): +15%
        elseif ($month === 10 || $month === 11) {
            $orderCount = (int) round($orderCount * 1.15);
        }
        // Christmas holiday season (December): +35%
        elseif ($month === 12) {
            $orderCount = (int) round($orderCount * 1.35);
        }

        // Payday modest lift (15th & 30th/31st)
        if ($day === 15 || $day === 30 || ($month === 2 && $day === 28)) {
            $orderCount = max($orderCount, $orderCount + mt_rand(0, 1));
        }

        // Holiday bumps (Valentine's, Easter, Christmas)
        if (($month === 2 && $day === 14) || ($month === 4 && $day >= 3 && $day <= 5) || ($month === 12 && ($day === 24 || $day === 25))) {
            $orderCount += mt_rand(2, 3);
        }

        for ($i = 0; $i < $orderCount; $i++) {
            // Realistic hour distribution for DK Cafe:
            // Morning coffee (8:30 - 10:30): 25%
            // Merienda / Afternoon (13:30 - 16:30): 50% (main peak)
            // Evening (17:30 - 20:30): 25%
            $timeRoll = mt_rand(1, 100);
            if ($timeRoll <= 25) {
                $hour = mt_rand(8, 10);
                $minute = mt_rand(0, 59);
            } elseif ($timeRoll <= 75) {
                $hour = mt_rand(13, 16);
                $minute = mt_rand(0, 59);
            } else {
                $hour = mt_rand(17, 20);
                $minute = mt_rand(0, 59);
            }
            $second = mt_rand(0, 59);
            $orderTimestamp = sprintf('%s %02d:%02d:%02d', $dateStr, $hour, $minute, $second);

            // Cashier assignment:
            // Barista 1 (55%), tan_smsn 6 (35%), Manager 2 (10%)
            $cashierRoll = mt_rand(1, 100);
            if ($cashierRoll <= 55) {
                $cashierId = $cashierUserIds[0];
            } elseif ($cashierRoll <= 90 && count($cashierUserIds) > 2) {
                $cashierId = $cashierUserIds[2];
            } else {
                $cashierId = $cashierUserIds[1] ?? $cashierUserIds[0];
            }

            // Order Status: ~98.8% Completed, 0.9% Cancelled, 0.3% Voided
            $statusRoll = mt_rand(1, 1000);
            if ($statusRoll <= 988) {
                $orderStatus = 'Completed';
                $cancelUserId = null;
                $cancelAt = null;
                $cancelReason = null;
            } elseif ($statusRoll <= 997) {
                $orderStatus = 'Cancelled';
                $cancelUserId = $cashierId;
                $cancelAt = date('Y-m-d H:i:s', strtotime($orderTimestamp) + mt_rand(60, 240));
                $reasons = ['Customer changed mind', 'Waiting time too long', 'Customer forgot wallet/phone'];
                $cancelReason = $reasons[array_rand($reasons)];
            } else {
                $orderStatus = 'Voided';
                $cancelUserId = $cashierUserIds[1] ?? $cashierId;
                $cancelAt = date('Y-m-d H:i:s', strtotime($orderTimestamp) + mt_rand(60, 300));
                $cancelReason = 'Cashier register punching error';
            }

            // Basket composition:
            // 72% Single Item (1 drink or 1 croffle)
            // 23% Two Items (drink + croffle or 2 drinks)
            // 5% Three Items (couple/small merienda)
            $basketRoll = mt_rand(1, 100);
            if ($basketRoll <= 72) {
                $itemLinesCount = 1;
            } elseif ($basketRoll <= 95) {
                $itemLinesCount = 2;
            } else {
                $itemLinesCount = 3;
            }

            $orderLines = [];
            $orderSubtotal = 0.0;

            for ($k = 0; $k < $itemLinesCount; $k++) {
                // Time-of-day product preference
                if ($hour <= 10) {
                    // Morning: Coffee (50%), Pastry/Croissant (35%), Sandwich (15%)
                    $catRoll = mt_rand(1, 100);
                    if ($catRoll <= 50) {
                        $product = $hotCoffeeProds[array_rand($hotCoffeeProds)];
                        $forceHot = (mt_rand(1, 100) <= 60);
                    } elseif ($catRoll <= 85) {
                        $product = $savoryFood[array_rand($savoryFood)];
                        $forceHot = false;
                    } else {
                        $product = $coldCoffeeProds[array_rand($coldCoffeeProds)];
                        $forceHot = false;
                    }
                } elseif ($hour >= 13 && $hour <= 16) {
                    // Afternoon Merienda: Croffles (35%), Fruit Tea / Lemonade (25%), Milk Tea/Series (25%), Iced Coffee (15%)
                    $catRoll = mt_rand(1, 100);
                    if ($catRoll <= 35) {
                        $product = $croffles[array_rand($croffles)];
                    } elseif ($catRoll <= 60) {
                        $product = $refreshers[array_rand($refreshers)];
                    } elseif ($catRoll <= 85) {
                        $product = $milkSeries[array_rand($milkSeries)];
                    } else {
                        $product = $coldCoffeeProds[array_rand($coldCoffeeProds)];
                    }
                    $forceHot = false;
                } else {
                    // Evening: Croffle / Snacks (40%), Milk Series (35%), Coffee (25%)
                    $catRoll = mt_rand(1, 100);
                    if ($catRoll <= 40) {
                        $product = (mt_rand(1, 100) <= 60) ? $croffles[array_rand($croffles)] : $savoryFood[array_rand($savoryFood)];
                    } elseif ($catRoll <= 75) {
                        $product = $milkSeries[array_rand($milkSeries)];
                    } else {
                        $product = $coldCoffeeProds[array_rand($coldCoffeeProds)];
                    }
                    $forceHot = false;
                }

                $pId = (int) $product['product_id'];
                $qty = (mt_rand(1, 100) <= 95) ? 1 : 2;
                $sizeCode  = null;
                $sizeLabel = null;
                $sizeOz    = null;
                $unitPrice = (float) $product['price'];

                if (!empty($product['has_sizes']) && isset($sizeMap[$pId]) && !empty($sizeMap[$pId])) {
                    $availableSizes = $sizeMap[$pId];
                    if (isset($forceHot) && $forceHot) {
                        $hotSizes = array_filter($availableSizes, fn($s) => str_contains(strtolower($s['size_label']), 'hot') || $s['size_code'] === '12oz');
                        $chosen = !empty($hotSizes) ? reset($hotSizes) : $availableSizes[0];
                    } else {
                        // Summer favors larger size (22oz)
                        if (($month >= 3 && $month <= 5) && count($availableSizes) > 1) {
                            $chosen = $availableSizes[mt_rand(1, 100) <= 65 ? (count($availableSizes) - 1) : 0];
                        } else {
                            $chosen = $availableSizes[array_rand($availableSizes)];
                        }
                    }

                    $sizeCode  = $chosen['size_code'];
                    $sizeLabel = $chosen['size_label'];
                    $sizeOz    = (int) $chosen['size_oz'];
                    $unitPrice = (float) $chosen['price'];
                } elseif (!empty($product['has_sizes'])) {
                    $sizeCode  = '16oz';
                    $sizeLabel = '16oz Cold';
                    $sizeOz    = 16;
                    $unitPrice = (float) ($product['price_16oz'] ?: $product['price']);
                }

                $lineTotal = round($unitPrice * $qty, 2);
                $orderSubtotal += $lineTotal;

                $orderLines[] = [
                    'prod_id' => $pId,
                    'name'    => $product['name'],
                    'qty'     => $qty,
                    'price'   => $unitPrice,
                    'scode'   => $sizeCode,
                    'slabel'  => $sizeLabel,
                    'soz'     => $sizeOz
                ];
            }

            // Customer Discounts:
            // 90% None
            // 6% Senior Citizen (20%)
            // 2% PWD (20%)
            // 2% Promo (10%)
            $discRoll = mt_rand(1, 100);
            $discType   = 'none';
            $discRate   = 0.00;
            $discIdNo   = null;
            $discReason = null;
            $discountAmount = 0.00;

            if ($discRoll <= 6) {
                $discType   = 'Senior';
                $discRate   = 20.00;
                $discIdNo   = 'OSCA-2026-' . sprintf('%05d', mt_rand(1000, 99999));
                $discReason = 'Senior Citizen 20% Privilege (RA 9994)';
                $discountAmount = round($orderSubtotal * 0.20, 2);
            } elseif ($discRoll <= 8) {
                $discType   = 'PWD';
                $discRate   = 20.00;
                $discIdNo   = 'PWD-2026-' . sprintf('%05d', mt_rand(1000, 99999));
                $discReason = 'Person with Disability 20% Privilege (RA 10754)';
                $discountAmount = round($orderSubtotal * 0.20, 2);
            } elseif ($discRoll <= 10) {
                $discType   = 'Promo';
                $discRate   = 10.00;
                $discIdNo   = 'PROMO-' . strtoupper(substr(md5((string) mt_rand()), 0, 6));
                $discReason = 'DK Cafe Merienda Special';
                $discountAmount = round($orderSubtotal * 0.10, 2);
            }

            $orderFinalTotal = max(0.0, round($orderSubtotal - $discountAmount, 2));

            // Payment: 60% GCash, 40% Cash
            $isGCash = (mt_rand(1, 100) <= 60);
            $payMethod = $isGCash ? 'GCash' : 'Cash';

            if ($isGCash) {
                $payRef = 'GC26' . $current->format('md') . strtoupper(bin2hex(random_bytes(3)));
                $amountPaid = $orderFinalTotal;
                $changeDue  = 0.00;
            } else {
                $payRef = null;
                // Realistic tender bill denominations: e.g. PHP 100, 200, 500, 1000
                if ($orderFinalTotal <= 100) {
                    $amountPaid = (mt_rand(1, 100) <= 45) ? $orderFinalTotal : 100.00;
                } elseif ($orderFinalTotal <= 200) {
                    $amountPaid = (mt_rand(1, 100) <= 35) ? $orderFinalTotal : (mt_rand(1, 100) <= 65 ? 200.00 : 500.00);
                } elseif ($orderFinalTotal <= 500) {
                    $amountPaid = (mt_rand(1, 100) <= 40) ? $orderFinalTotal : 500.00;
                } else {
                    $amountPaid = (mt_rand(1, 100) <= 35) ? $orderFinalTotal : 1000.00;
                }
                if ($amountPaid < $orderFinalTotal) {
                    $amountPaid = ceil($orderFinalTotal / 50) * 50.00;
                }
                $changeDue = round($amountPaid - $orderFinalTotal, 2);
            }

            $txStmt->execute([
                ':cashier_id'   => $cashierId,
                ':total'        => $orderFinalTotal,
                ':paid'         => $amountPaid,
                ':change'       => $changeDue,
                ':subtotal'     => $orderSubtotal,
                ':discount'     => $discountAmount,
                ':disc_type'    => $discType,
                ':disc_rate'    => $discRate,
                ':disc_id'      => $discIdNo,
                ':disc_reason'  => $discReason,
                ':status'       => $orderStatus,
                ':cancel_user'  => $cancelUserId,
                ':cancel_at'    => $cancelAt,
                ':cancel_reason'=> $cancelReason,
                ':method'       => $payMethod,
                ':ref'          => $payRef,
                ':ts'           => $orderTimestamp
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
                $totalItemsCount += $ol['qty'];
            }

            $totalOrdersCount++;
            if ($orderStatus === 'Completed') {
                $completedOrdersCount++;
                $totalGrossRevenue += $orderFinalTotal;
            }
        }

        $current = $current->modify('+1 day');
    }

    $pdo->commit();
    $elapsed = round(microtime(true) - $startTime, 2);

    return [
        'success'               => true,
        'year'                  => 2026,
        'days_seeded'           => 365,
        'total_transactions'    => $totalOrdersCount,
        'completed_orders'      => $completedOrdersCount,
        'total_gross_sales'     => round($totalGrossRevenue, 2),
        'total_items_sold'      => $totalItemsCount,
        'average_daily_orders'  => round($totalOrdersCount / 365, 1),
        'average_daily_sales'   => round($totalGrossRevenue / 365, 2),
        'average_monthly_sales' => round($totalGrossRevenue / 12, 2),
        'average_order_value'   => round($totalGrossRevenue / max(1, $completedOrdersCount), 2),
        'execution_seconds'     => $elapsed,
        'dataset_type'          => 'dk_cafe_empirical_calibrated'
    ];
}

// Allow CLI execution
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $force = in_array('--force', $argv, true);
    echo "====================================================\n";
    echo " CafeFlow POS - 2026 Historical Seeder (DK Cafe)\n";
    echo " Target: PHP 20,000 - 30,000 / month\n";
    echo " Period: 2026-01-01 to 2026-12-31 (365 days)\n";
    echo "====================================================\n";
    $result = seed_2026_dataset($force);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
