<?php
// create_order.php
// Creates an order and its items in a single transaction, with optional idempotency_key support.
// Expects JSON POST:
// {
//   "items": [{"product_id": int, "qty": number, "price": number}, ...],
//   "discount": number,             // optional
//   "note": string,                 // optional
//   "currency": string,             // optional
//   "exchange_rate": number,        // optional
//   "table_id": string|null,        // optional
//   "idempotency_key": string|null  // optional
// }
//
// Returns JSON:
// { success: true, order_id: <int>, already_created: false }
// or on idempotent repeat:
// { success: true, order_id: <int>, already_created: true }
// On error: { success: false, error: "<message>" }

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php'; // must provide $conn (mysqli)

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input) || !isset($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload: items required']);
    exit;
}

// sanitize and normalize incoming values
$discount = isset($input['discount']) ? floatval($input['discount']) : 0.0;
$note = isset($input['note']) ? $input['note'] : '';
$currency = isset($input['currency']) ? $input['currency'] : 'PHP';
$exchange_rate = isset($input['exchange_rate']) ? floatval($input['exchange_rate']) : 1.0;
$table_id = isset($input['table_id']) ? $input['table_id'] : null;
$idempotency_key = isset($input['idempotency_key']) ? trim($input['idempotency_key']) : null;

// Basic validation of items: ensure product_id, qty, price exist and are numeric
$items = [];
foreach ($input['items'] as $i => $it) {
    if (!isset($it['product_id']) || !isset($it['qty']) || !isset($it['price'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Invalid item at index $i"]);
        exit;
    }
    $product_id = intval($it['product_id']);
    $qty = floatval($it['qty']);
    $price = floatval($it['price']);
    if ($product_id <= 0 || $qty <= 0 || $price < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Invalid item values at index $i"]);
        exit;
    }
    $items[] = ['product_id' => $product_id, 'qty' => $qty, 'price' => $price];
}

try {
    // Start transaction
    if (!$conn->begin_transaction()) {
        throw new Exception('Failed to start DB transaction');
    }

    // If idempotency key provided, check for existing order
    if ($idempotency_key !== null && $idempotency_key !== '') {
        $check = $conn->prepare("SELECT id FROM orders WHERE idempotency_key = ? LIMIT 1");
        if (!$check) throw new Exception('Prepare failed: ' . $conn->error);
        $check->bind_param('s', $idempotency_key);
        if (!$check->execute()) throw new Exception('Execute failed: ' . $check->error);
        $check->bind_result($existing_id);
        if ($check->fetch()) {
            // Found prior order with this idempotency key: return it
            $check->close();
            $conn->commit();
            echo json_encode(['success' => true, 'order_id' => intval($existing_id), 'already_created' => true]);
            exit;
        }
        $check->close();
    }

    // Insert order
    $stmt = $conn->prepare("INSERT INTO orders (status, discount, note, currency, exchange_rate, table_id, idempotency_key, created_at) VALUES ('open', ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

    // Bind params: discount (d), note (s), currency (s), exchange_rate (d), table_id (s), idempotency_key (s)
    // Ensure variables exist and are in proper type
    $discount_param = $discount;
    $note_param = $note;
    $currency_param = $currency;
    $exchange_rate_param = $exchange_rate;
    $table_id_param = $table_id;
    $idempotency_param = $idempotency_key;

    // mysqli requires all bound parameters to be variables, but table_id might be NULL. If null, bind as NULL string.
    if ($table_id_param === null) $table_id_param = null;

    if (!$stmt->bind_param('dssdss', $discount_param, $note_param, $currency_param, $exchange_rate_param, $table_id_param, $idempotency_param)) {
        throw new Exception('Bind failed: ' . $stmt->error);
    }
    if (!$stmt->execute()) {
        throw new Exception('Execute failed (insert order): ' . $stmt->error);
    }
    $order_id = $stmt->insert_id;
    $stmt->close();

    // Insert order items
    $ins = $conn->prepare("INSERT INTO order_items (order_id, product_id, qty, price, created_at) VALUES (?, ?, ?, ?, NOW())");
    if (!$ins) throw new Exception('Prepare failed (order_items): ' . $conn->error);

    foreach ($items as $it) {
        $order_id_param = $order_id;
        $product_id_param = $it['product_id'];
        $qty_param = $it['qty'];
        $price_param = $it['price'];
        // bind: i i d d  -> order_id(int), product_id(int), qty(double), price(double)
        if (!$ins->bind_param('iidd', $order_id_param, $product_id_param, $qty_param, $price_param)) {
            throw new Exception('Bind failed (order_items): ' . $ins->error);
        }
        if (!$ins->execute()) {
            throw new Exception('Execute failed (insert order_item): ' . $ins->error);
        }
    }
    $ins->close();

    // Commit transaction
    if (!$conn->commit()) {
        throw new Exception('Commit failed: ' . $conn->error);
    }

    echo json_encode(['success' => true, 'order_id' => intval($order_id), 'already_created' => false]);
    exit;

} catch (Exception $e) {
    // Rollback and return error
    if ($conn->errno) { /* noop, $conn exists */ }
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}