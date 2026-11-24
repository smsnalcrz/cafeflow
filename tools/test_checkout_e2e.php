<?php
/**
 * End-to-end integration test for POS Checkout
 * Simulates the exact payload sent from the browser when ordering Caramel Macchiato.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();

// Ensure test barista session exists
$barista = $pdo->query("SELECT user_id, username, role FROM users WHERE role = 'barista' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$barista) {
    die("No active barista user found\n");
}

init_session();
$_SESSION['user_id'] = $barista['user_id'];
$_SESSION['username'] = $barista['username'];
$_SESSION['role'] = 'barista';
$_SESSION['status'] = 'active';
$_SESSION['last_activity'] = time();

$csrf = get_csrf_token();

// Find Caramel Macchiato product (has_sizes = 1)
$prod = $pdo->query("SELECT product_id, name, price, has_sizes FROM menu_products WHERE name = 'Caramel Macchiato' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$prod) {
    die("Caramel Macchiato not found\n");
}

$sp = $pdo->query("SELECT * FROM product_size_prices WHERE product_id = {$prod['product_id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$testIdempotencyKey = 'cf_ord_test_' . bin2hex(random_bytes(6));

$payload = [
    'csrf_token'      => $csrf,
    'payment_method'  => 'Cash',
    'amount_paid'     => 150.00,
    'discount_type'   => 'none',
    'idempotency_key' => $testIdempotencyKey,
    'items'           => [
        [
            'product_id' => (int) $prod['product_id'],
            'quantity'   => 1,
            'size_oz'    => (int) ($sp['size_oz'] ?? 16),
            'size_label' => $sp['size_label'] ?? '16oz Cold',
            'size_code'  => $sp['size_code'] ?? '16oz'
        ]
    ]
];

session_write_close();

// Perform cURL request to local Apache server (http://localhost/cafeflow/api/checkout.php)
$ch = curl_init('http://localhost/cafeflow/api/checkout.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Cookie: ' . session_name() . '=' . session_id()
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

if ($httpCode === 200) {
    $res = json_decode($response, true);
    if (!empty($res['success']) && !empty($res['transaction_id'])) {
        $txId = (int) $res['transaction_id'];
        echo "SUCCESS: Created Transaction #{$txId}\n";

        // Test Idempotent Replay
        $ch2 = curl_init('http://localhost/cafeflow/api/checkout.php');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Cookie: ' . session_name() . '=' . session_id()
        ]);
        $response2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        echo "Replay HTTP Code: $httpCode2\n";
        echo "Replay Response: $response2\n";
        $res2 = json_decode($response2, true);
        if ($httpCode2 === 200 && ($res2['idempotent_replay'] ?? false) === true && (int)$res2['transaction_id'] === $txId) {
            echo "SUCCESS: Idempotency replay returned same transaction #{$txId} without duplicates!\n";
        } else {
            echo "FAILED: Idempotency replay did not match!\n";
        }
        
        // Clean up test transaction and ingredients
        $pdo->prepare("DELETE FROM order_ingredients WHERE transaction_id = ?")->execute([$txId]);
        $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id = ?")->execute([$txId]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$txId]);
        echo "Cleaned up test transaction #{$txId}\n";
    }
} else {
    echo "FAILED with HTTP {$httpCode}\n";
}
