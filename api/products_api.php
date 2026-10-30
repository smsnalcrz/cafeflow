<?php
/**
 * products_api.php - Product management API for CafeFlow POS.
 *
 * GET  -> List all products and distinct categories with cup sizes & raw-inventory stock states
 * POST -> Add, update, delete products or quick update stock (supports multipart/form-data with image upload)
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/recipe_engine.php';

$pdo = db();

const UPLOADS_DIR = __DIR__ . '/../uploads/products/';
const ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_IMAGE_BYTES = 5 * 1024 * 1024; // 5MB

// -----------------------------------------------------------------------------
// GET: Fetch products list
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $rows = $pdo->query(
        'SELECT p.product_id, p.name, p.category, p.price, p.image_url,
                p.stock_quantity, p.track_stock, p.has_sizes, p.price_12oz, p.price_16oz, p.price_22oz,
                p.linked_inventory_id, p.deduction_amount,
                i.name AS linked_inventory_name, i.current_stock, i.restock_threshold, i.unit_measure
           FROM menu_products p
           LEFT JOIN inventory i ON i.item_id = p.linked_inventory_id
          ORDER BY p.category ASC, p.name ASC'
    )->fetchAll();

    // Fetch multi-ingredient linkages if present
    $allIngs = $pdo->query(
        'SELECT pi.product_id, pi.item_id, pi.deduction_amount,
                i.name AS item_name, i.current_stock, i.unit_measure, i.restock_threshold
           FROM product_ingredients pi
           JOIN inventory i ON i.item_id = pi.item_id
          ORDER BY pi.id ASC'
    )->fetchAll();
    $ingsByProd = [];
    foreach ($allIngs as $ing) {
        $ingsByProd[$ing['product_id']][] = [
            'item_id'           => (int) $ing['item_id'],
            'name'              => $ing['item_name'],
            'current_stock'     => (float) $ing['current_stock'],
            'unit_measure'      => $ing['unit_measure'],
            'restock_threshold' => (float) $ing['restock_threshold'],
            'deduction_amount'  => (float) $ing['deduction_amount'],
        ];
    }

    // Fetch size-specific prices
    $spRows = $pdo->query('SELECT product_id, size_code, size_label, size_oz, price FROM product_size_prices ORDER BY size_oz ASC, id ASC')->fetchAll();
    $sizesByProd = [];
    foreach ($spRows as $sp) {
        $label = $sp['size_label'];
        if ($sp['size_code'] === '16oz' && ($label === 'Large' || empty($label))) {
            $label = 'Tall';
        }
        $isHot = str_contains(strtolower($label ?? ''), 'hot') || str_contains(strtolower($sp['size_code'] ?? ''), 'hot') || (int)$sp['size_oz'] === 12;
        $temp = $isHot ? 'Hot' : 'Cold';
        $sizesByProd[(int) $sp['product_id']][] = [
            'code'  => $sp['size_code'],
            'label' => $label,
            'oz'    => (int) $sp['size_oz'],
            'temp'  => $temp,
            'price' => (float) $sp['price'],
        ];
    }

    $products = [];
    $categorySet = [];

    foreach ($rows as $r) {
        $category = trim($r['category'] ?? '') !== '' ? trim($r['category']) : 'General';
        $categorySet[$category] = true;

        $pId = (int) $r['product_id'];
        $linkedIngredients = $ingsByProd[$pId] ?? [];
        $linkedIds = array_map(static fn($item) => $item['item_id'], $linkedIngredients);

        $sizes = $sizesByProd[$pId] ?? [];
        $isFood     = (bool) preg_match('/croffle|pastry|sandwich|snack|meal|dessert|bakery/i', $category);
        $isBeverage = !$isFood;
        $hasSizes   = !empty($sizes) ? true : (bool) ((int) ($r['has_sizes'] ?? ($isBeverage ? 1 : 0)));

        $isCoffee = (strtolower($category) === 'coffee');

        if (empty($sizes) && $hasSizes) {
            if ($isCoffee) {
                $p12 = $r['price_12oz'] !== null ? (float) $r['price_12oz'] : (float) $r['price'];
                $p16 = $r['price_16oz'] !== null ? (float) $r['price_16oz'] : (float) $r['price'];
                $sizes = [
                    ['code' => '12oz', 'label' => '12oz Hot', 'oz' => 12, 'temp' => 'Hot', 'price' => $p12],
                    ['code' => '16oz', 'label' => '16oz Cold', 'oz' => 16, 'temp' => 'Cold', 'price' => $p16],
                ];
            } else {
                $p16 = $r['price_16oz'] !== null ? (float) $r['price_16oz'] : (float) $r['price'];
                $p22 = $r['price_22oz'] !== null ? (float) $r['price_22oz'] : ($p16 + 20.0);
                $sizes = [
                    ['code' => '16oz', 'label' => '16oz Cold', 'oz' => 16, 'temp' => 'Cold', 'price' => $p16],
                    ['code' => '22oz', 'label' => '22oz Cold', 'oz' => 22, 'temp' => 'Cold', 'price' => $p22],
                ];
            }
        }

        // Always evaluate real-time stock state dynamically from raw inventory
        $state = evaluate_product_stock_state($r, $pdo);

        $firstIng = !empty($linkedIngredients) ? $linkedIngredients[0] : null;

        $products[] = [
            'product_id'            => (int) $r['product_id'],
            'name'                  => $r['name'],
            'category'              => $category,
            'price'                 => (float) $r['price'],
            'image_url'             => $r['image_url'],
            'stock_quantity'        => (float) ($r['stock_quantity'] ?? 0),
            'track_stock'           => 1, // Consistently tracked via raw inventory
            'has_sizes'             => $hasSizes,
            'price_12oz'            => $r['price_12oz'] !== null ? (float) $r['price_12oz'] : null,
            'price_16oz'            => $r['price_16oz'] !== null ? (float) $r['price_16oz'] : ($sizes[0]['price'] ?? (float) $r['price']),
            'price_22oz'            => $isCoffee ? null : ($r['price_22oz'] !== null ? (float) $r['price_22oz'] : ($sizes[1]['price'] ?? null)),
            'sizes'                 => $sizes,
            'linked_inventory_id'   => $r['linked_inventory_id'] !== null ? (int) $r['linked_inventory_id'] : ($firstIng ? $firstIng['item_id'] : null),
            'linked_inventory_name' => $r['linked_inventory_name'] ?? ($firstIng ? $firstIng['name'] : null),
            'deduction_amount'      => (float) ($r['deduction_amount'] ?? ($firstIng ? $firstIng['deduction_amount'] : 0)),
            'unit_measure'          => $r['unit_measure'] ?? ($firstIng ? $firstIng['unit_measure'] : 'pcs'),
            'stock_state'           => $state,
            'ingredients'           => $linkedIngredients,
            'linked_inventory_ids'  => $linkedIds,
        ];
    }

    $defaultCategories = ['Coffee', 'Milk Series', 'Milk Tea', 'Fruit Tea / Lemonade', 'Croffle Classic', 'Croffle Premium', 'Sandwich', 'Pastry', 'Snacks'];
    $allCategories = array_values(array_unique(array_merge($defaultCategories, array_keys($categorySet))));

    send_json([
        'success'    => true,
        'products'   => $products,
        'categories' => $allCategories,
    ]);
}

// -----------------------------------------------------------------------------
// POST: Add / Update / Delete / Quick stock
// -----------------------------------------------------------------------------
require_method('POST');

// Handle either multipart/form-data, urlencoded, or application/json
$isFormPost = !empty($_POST) || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded');
if ($isFormPost) {
    $data = $_POST;
} else {
    $data = read_json();
}

$action = $data['action'] ?? '';

function handle_image_upload(?array $file): ?string
{
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        fail(400, 'Image upload failed. Error code: ' . $file['error']);
    }
    if ($file['size'] > MAX_IMAGE_BYTES) {
        fail(400, 'Image file size must be 5MB or less.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_MIMES, true)) {
        fail(400, 'Invalid image format. Allowed formats: JPG, JPEG, PNG, WebP.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, ALLOWED_EXTS, true)) {
        fail(400, 'Invalid image file extension. Allowed: .jpg, .png, .webp.');
    }

    if (!is_dir(UPLOADS_DIR) && !mkdir(UPLOADS_DIR, 0777, true) && !is_dir(UPLOADS_DIR)) {
        fail(500, 'Cannot create upload directory.');
    }

    $safeName = 'prod_' . bin2hex(random_bytes(6)) . '_' . time() . '.' . $ext;
    $targetPath = UPLOADS_DIR . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        fail(500, 'Failed to save uploaded image.');
    }

    return 'uploads/products/' . $safeName;
}

function safe_delete_image(?string $url): void
{
    if (!$url) return;
    $relative = str_replace(['..', '\\'], ['', '/'], $url);
    if (str_starts_with($relative, 'uploads/products/')) {
        $fullPath = dirname(__DIR__) . '/' . $relative;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

function parse_incoming_ingredients(array $data): array
{
    $res = []; // item_id => deduction_amount (decimal string)
    if (!empty($data['ingredients_json'])) {
        $raw = $data['ingredients_json'];
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (is_array($decoded)) {
            foreach ($decoded as $it) {
                $itemId = as_int($it['item_id'] ?? null, 1, 4294967295);
                if ($itemId !== null) {
                    $deduct = as_decimal($it['deduction_amount'] ?? 1, 0.00, 999999.99);
                    $res[$itemId] = $deduct !== null ? $deduct : '1.00';
                }
            }
        }
    } elseif (isset($data['linked_inventory_ids']) && is_array($data['linked_inventory_ids'])) {
        foreach ($data['linked_inventory_ids'] as $id) {
            $itemId = as_int($id, 1, 4294967295);
            if ($itemId !== null) {
                $res[$itemId] = '1.00';
            }
        }
    } elseif (!empty($data['linked_inventory_id'])) {
        $itemId = as_int($data['linked_inventory_id'], 1, 4294967295);
        if ($itemId !== null) {
            $deduct = as_decimal($data['deduction_amount'] ?? 1, 0.00, 999999.99);
            $res[$itemId] = $deduct !== null ? $deduct : '1.00';
        }
    }
    return $res;
}

switch ($action) {
    case 'add':
        $name     = trim((string) ($data['name'] ?? ''));
        $category = trim((string) ($data['category'] ?? 'Coffee'));
        $price    = as_decimal($data['price'] ?? null, 0.00, 999999.99);
        $stock    = as_decimal($data['stock_quantity'] ?? 0, 0.00, 999999.99);
        $track    = 1; // Always tracked via raw inventory

        $isFood     = (bool) preg_match('/croffle|pastry|sandwich|snack|meal|dessert|bakery/i', $category);
        $isBeverage = !$isFood;
        $hasSizes   = isset($data['has_sizes']) ? (int) (bool) $data['has_sizes'] : ($isBeverage ? 1 : 0);
        $isCoffee   = (strtolower($category) === 'coffee');
        $price12oz  = as_decimal($data['price_12oz'] ?? null, 0.00, 999999.99);
        $price16oz  = as_decimal($data['price_16oz'] ?? null, 0.00, 999999.99);
        $price22oz  = as_decimal($data['price_22oz'] ?? null, 0.00, 999999.99);

        // Check if dynamic sizes_json is provided
        $sizesJson = $data['sizes_json'] ?? null;
        $incomingSizes = [];
        if ($sizesJson) {
            $decoded = json_decode($sizesJson, true);
            if (is_array($decoded)) {
                $incomingSizes = $decoded;
            }
        }

        if ($hasSizes && !empty($incomingSizes)) {
            $minPrice = null;
            $price12oz = null;
            $price16oz = null;
            $price22oz = null;
            foreach ($incomingSizes as $s) {
                $pVal = as_decimal($s['price'] ?? 0, 0.00, 999999.99);
                if ($pVal === null) {
                    fail(422, 'All cup size prices must be valid amounts of 0.00 or more.');
                }
                $ozVal = (int) ($s['oz'] ?? 0);
                if ($minPrice === null || (float)$pVal < (float)$minPrice) {
                    $minPrice = $pVal;
                }
                if ($ozVal === 12 && $price12oz === null) $price12oz = $pVal;
                if ($ozVal === 16 && $price16oz === null) $price16oz = $pVal;
                if ($ozVal === 22 && $price22oz === null) $price22oz = $pVal;
            }
            $price = $minPrice ?? '0.00';
        } elseif ($hasSizes) {
            if ($isCoffee) {
                $price22oz = null;
                if ($price12oz === null && $price !== null) $price12oz = $price;
                if ($price16oz === null && $price12oz !== null) $price16oz = $price12oz;
                if ($price === null && $price12oz !== null) $price = $price12oz;
            } else {
                $price12oz = null;
                if ($price16oz === null && $price !== null) $price16oz = $price;
                if ($price22oz === null && $price16oz !== null) $price22oz = number_format((float) $price16oz + 20.0, 2, '.', '');
                if ($price === null && $price16oz !== null) $price = $price16oz;
            }
        }

        if ($name === '' || strlen($name) > 100) {
            fail(422, 'Product name is required (max 100 characters).');
        }
        if ($category === '' || strlen($category) > 50) {
            $category = 'General';
        }
        if ($price === null && !$hasSizes) {
            fail(422, 'Price must be a valid amount of 0.00 or more.');
        }
        if ($hasSizes && empty($incomingSizes)) {
            if ($isCoffee && ($price12oz === null || $price16oz === null)) {
                fail(422, 'Both 12oz (Hot) and 16oz (Cold) prices must be valid amounts of 0.00 or more.');
            }
            if (!$isCoffee && ($price16oz === null || $price22oz === null)) {
                fail(422, 'Both 16oz and 22oz prices must be valid amounts of 0.00 or more.');
            }
        }

        // Handle ingredients and fallback link
        $incomingIngs  = parse_incoming_ingredients($data);
        $primaryInvId  = !empty($incomingIngs) ? array_key_first($incomingIngs) : as_int($data['linked_inventory_id'] ?? null, 1, 4294967295);
        $primaryDeduct = $primaryInvId !== null && isset($incomingIngs[$primaryInvId]) ? $incomingIngs[$primaryInvId] : as_decimal($data['deduction_amount'] ?? 0, 0.00, 999999.99);

        // Handle image upload
        $imageUrl = handle_image_upload($_FILES['image'] ?? null);

        $stmt = $pdo->prepare(
            'INSERT INTO menu_products (name, category, price, image_url, stock_quantity, track_stock, has_sizes, price_12oz, price_16oz, price_22oz, linked_inventory_id, deduction_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $category,
            $price ?? ($isCoffee ? $price12oz : $price16oz),
            $imageUrl,
            $stock ?? '0.00',
            $track,
            $hasSizes,
            $price12oz,
            $price16oz,
            $price22oz,
            $primaryInvId,
            $primaryDeduct ?? '0.00'
        ]);

        $newId = (int) $pdo->lastInsertId();

        // Save cup size records in product_size_prices
        if ($hasSizes) {
            $insSp = $pdo->prepare('INSERT INTO product_size_prices (product_id, size_code, size_label, size_oz, price) VALUES (?, ?, ?, ?, ?)');
            if (!empty($incomingSizes)) {
                foreach ($incomingSizes as $s) {
                    $sOz = (int) ($s['oz'] ?? 16);
                    $sTemp = trim((string)($s['temp'] ?? 'Cold'));
                    $isHot = strtolower($sTemp) === 'hot' || str_contains(strtolower($s['label'] ?? ''), 'hot');
                    $sCode = !empty($s['code']) ? $s['code'] : ($sOz . 'oz' . ($isHot ? '_hot' : ''));
                    $sLabel = !empty($s['label']) ? trim($s['label']) : ($sOz . 'oz ' . $sTemp);
                    $sPrice = as_decimal($s['price'] ?? 0, 0.00, 999999.99) ?? '0.00';
                    $insSp->execute([$newId, $sCode, $sLabel, $sOz, $sPrice]);
                }
            } elseif ($isCoffee && $price12oz !== null && $price16oz !== null) {
                $insSp->execute([$newId, '12oz', '12oz Hot', 12, $price12oz]);
                $insSp->execute([$newId, '16oz', '16oz Cold', 16, $price16oz]);
            } elseif (!$isCoffee && $price16oz !== null && $price22oz !== null) {
                $insSp->execute([$newId, '16oz', 'Tall', 16, $price16oz]);
                $insSp->execute([$newId, '22oz', 'Grande', 22, $price22oz]);
            }
        }

        // Save linked ingredients
        if (!empty($incomingIngs)) {
            $insIng = $pdo->prepare('INSERT INTO product_ingredients (product_id, item_id, deduction_amount) VALUES (?, ?, ?)');
            foreach ($incomingIngs as $itemId => $amt) {
                $insIng->execute([$newId, $itemId, $amt]);
            }
        }

        send_json([
            'success'    => true,
            'message'    => 'Product created successfully.',
            'product_id' => $newId,
        ], 201);
        break;

    case 'update':
        $productId = as_int($data['product_id'] ?? null, 1, 4294967295);
        if ($productId === null) {
            fail(422, 'Invalid product ID.');
        }

        // Find existing product
        $st = $pdo->prepare('SELECT * FROM menu_products WHERE product_id = ?');
        $st->execute([$productId]);
        $existing = $st->fetch();
        if (!$existing) {
            fail(404, 'Product not found.');
        }

        $name     = trim((string) ($data['name'] ?? $existing['name']));
        $category = trim((string) ($data['category'] ?? $existing['category']));
        $price    = as_decimal($data['price'] ?? $existing['price'], 0.00, 999999.99);
        $stock    = as_decimal($data['stock_quantity'] ?? $existing['stock_quantity'], 0.00, 999999.99);
        $track    = 1;

        $isFood     = (bool) preg_match('/croffle|pastry|sandwich|snack|meal|dessert|bakery/i', $category);
        $isBeverage = !$isFood;
        $hasSizes   = isset($data['has_sizes']) ? (int) (bool) $data['has_sizes'] : (int) ($existing['has_sizes'] ?? ($isBeverage ? 1 : 0));
        $isCoffee   = (strtolower($category) === 'coffee');
        $price12oz  = as_decimal($data['price_12oz'] ?? $existing['price_12oz'], 0.00, 999999.99);
        $price16oz  = as_decimal($data['price_16oz'] ?? $existing['price_16oz'], 0.00, 999999.99);
        $price22oz  = as_decimal($data['price_22oz'] ?? $existing['price_22oz'], 0.00, 999999.99);

        // Check dynamic sizes_json
        $sizesJson = $data['sizes_json'] ?? null;
        $incomingSizes = [];
        if ($sizesJson) {
            $decoded = json_decode($sizesJson, true);
            if (is_array($decoded)) {
                $incomingSizes = $decoded;
            }
        }

        if ($hasSizes && !empty($incomingSizes)) {
            $minPrice = null;
            $price12oz = null;
            $price16oz = null;
            $price22oz = null;
            foreach ($incomingSizes as $s) {
                $pVal = as_decimal($s['price'] ?? 0, 0.00, 999999.99);
                if ($pVal === null) {
                    fail(422, 'All cup size prices must be valid amounts of 0.00 or more.');
                }
                $ozVal = (int) ($s['oz'] ?? 0);
                if ($minPrice === null || (float)$pVal < (float)$minPrice) {
                    $minPrice = $pVal;
                }
                if ($ozVal === 12 && $price12oz === null) $price12oz = $pVal;
                if ($ozVal === 16 && $price16oz === null) $price16oz = $pVal;
                if ($ozVal === 22 && $price22oz === null) $price22oz = $pVal;
            }
            $price = $minPrice ?? '0.00';
        } elseif ($hasSizes) {
            if ($isCoffee) {
                $price22oz = null;
                if ($price12oz === null && $price !== null) $price12oz = $price;
                if ($price16oz === null && $price12oz !== null) $price16oz = $price12oz;
                if ($price === null && $price12oz !== null) $price = $price12oz;
            } else {
                $price12oz = null;
                if ($price16oz === null && $price !== null) $price16oz = $price;
                if ($price22oz === null && $price16oz !== null) $price22oz = number_format((float) $price16oz + 20.0, 2, '.', '');
                if ($price === null && $price16oz !== null) $price = $price16oz;
            }
        }

        if ($name === '' || strlen($name) > 100) {
            fail(422, 'Product name is required (max 100 characters).');
        }
        if ($price === null && !$hasSizes) {
            fail(422, 'Price must be a valid amount of 0.00 or more.');
        }
        if ($hasSizes && empty($incomingSizes)) {
            if ($isCoffee && ($price12oz === null || $price16oz === null)) {
                fail(422, 'Both 12oz (Hot) and 16oz (Cold) prices must be valid amounts of 0.00 or more.');
            }
            if (!$isCoffee && ($price16oz === null || $price22oz === null)) {
                fail(422, 'Both 16oz and 22oz prices must be valid amounts of 0.00 or more.');
            }
        }

        // Handle ingredients update
        $hasIngsParam = array_key_exists('ingredients_json', $data) || array_key_exists('linked_inventory_id', $data);
        if ($hasIngsParam) {
            $incomingIngs  = parse_incoming_ingredients($data);
            $primaryInvId  = !empty($incomingIngs) ? array_key_first($incomingIngs) : null;
            $primaryDeduct = $primaryInvId !== null && isset($incomingIngs[$primaryInvId]) ? $incomingIngs[$primaryInvId] : '0.00';
        } else {
            $primaryInvId  = $existing['linked_inventory_id'];
            $primaryDeduct = $existing['deduction_amount'];
            $incomingIngs  = null;
        }

        $imageUrl = $existing['image_url'];
        $newUploaded = handle_image_upload($_FILES['image'] ?? null);
        if ($newUploaded !== null) {
            safe_delete_image($existing['image_url']);
            $imageUrl = $newUploaded;
        } elseif (!empty($data['remove_image'])) {
            safe_delete_image($existing['image_url']);
            $imageUrl = null;
        }

        $stmt = $pdo->prepare(
            'UPDATE menu_products
                SET name = ?, category = ?, price = ?, image_url = ?,
                    stock_quantity = ?, track_stock = ?, has_sizes = ?, price_12oz = ?, price_16oz = ?, price_22oz = ?,
                    linked_inventory_id = ?, deduction_amount = ?
              WHERE product_id = ?'
        );
        $stmt->execute([
            $name,
            $category,
            $price ?? ($isCoffee ? $price12oz : $price16oz),
            $imageUrl,
            $stock ?? '0.00',
            $track,
            $hasSizes,
            $price12oz,
            $price16oz,
            $price22oz,
            $primaryInvId,
            $primaryDeduct ?? '0.00',
            $productId
        ]);

        // Sync product_size_prices table
        $pdo->prepare('DELETE FROM product_size_prices WHERE product_id = ?')->execute([$productId]);
        if ($hasSizes) {
            $insSp = $pdo->prepare('INSERT INTO product_size_prices (product_id, size_code, size_label, size_oz, price) VALUES (?, ?, ?, ?, ?)');
            if (!empty($incomingSizes)) {
                foreach ($incomingSizes as $s) {
                    $sOz = (int) ($s['oz'] ?? 16);
                    $sTemp = trim((string)($s['temp'] ?? 'Cold'));
                    $isHot = strtolower($sTemp) === 'hot' || str_contains(strtolower($s['label'] ?? ''), 'hot');
                    $sCode = !empty($s['code']) ? $s['code'] : ($sOz . 'oz' . ($isHot ? '_hot' : ''));
                    $sLabel = !empty($s['label']) ? trim($s['label']) : ($sOz . 'oz ' . $sTemp);
                    $sPrice = as_decimal($s['price'] ?? 0, 0.00, 999999.99) ?? '0.00';
                    $insSp->execute([$productId, $sCode, $sLabel, $sOz, $sPrice]);
                }
            } elseif ($isCoffee && $price12oz !== null && $price16oz !== null) {
                $insSp->execute([$productId, '12oz', '12oz Hot', 12, $price12oz]);
                $insSp->execute([$productId, '16oz', '16oz Cold', 16, $price16oz]);
            } elseif (!$isCoffee && $price16oz !== null && $price22oz !== null) {
                $insSp->execute([$productId, '16oz', 'Tall', 16, $price16oz]);
                $insSp->execute([$productId, '22oz', 'Grande', 22, $price22oz]);
            }
        }

        if ($incomingIngs !== null) {
            $pdo->prepare('DELETE FROM product_ingredients WHERE product_id = ?')->execute([$productId]);
            if (!empty($incomingIngs)) {
                $insIng = $pdo->prepare('INSERT INTO product_ingredients (product_id, item_id, deduction_amount) VALUES (?, ?, ?)');
                foreach ($incomingIngs as $itemId => $amt) {
                    $insIng->execute([$productId, $itemId, $amt]);
                }
            }
        }

        send_json([
            'success' => true,
            'message' => 'Product updated successfully.',
        ]);
        break;

    case 'delete':
        $productId = as_int($data['product_id'] ?? null, 1, 4294967295);
        if ($productId === null) {
            fail(422, 'Invalid product ID.');
        }

        $st = $pdo->prepare('SELECT name, price, image_url FROM menu_products WHERE product_id = ?');
        $st->execute([$productId]);
        $row = $st->fetch();
        if (!$row) {
            fail(404, 'Product not found.');
        }

        // Preserve snapshot name and unit_price in past transactions before unlinking
        $pdo->prepare(
            'UPDATE transaction_items
                SET product_name = COALESCE(product_name, ?),
                    unit_price   = CASE WHEN unit_price = 0 THEN ? ELSE unit_price END
              WHERE product_id = ?'
        )->execute([$row['name'], $row['price'], $productId]);

        // Unlink from past transactions so deletion never violates foreign keys or destroys sales history
        $pdo->prepare('UPDATE transaction_items SET product_id = NULL WHERE product_id = ?')->execute([$productId]);

        safe_delete_image($row['image_url']);

        $pdo->prepare('DELETE FROM menu_products WHERE product_id = ?')->execute([$productId]);

        send_json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
        break;

    case 'update_stock':
        $productId = as_int($data['product_id'] ?? null, 1, 4294967295);
        $stock     = as_decimal($data['stock_quantity'] ?? null, 0.00, 999999.99);
        if ($productId === null || $stock === null) {
            fail(422, 'Invalid product ID or stock quantity.');
        }

        $pdo->prepare('UPDATE menu_products SET stock_quantity = ? WHERE product_id = ?')
            ->execute([$stock, $productId]);

        send_json([
            'success' => true,
            'message' => 'Stock updated.',
        ]);
        break;

    default:
        fail(422, 'Unknown action.');
}
