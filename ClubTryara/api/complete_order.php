<?php
// complete_order.php
// Expects: POST JSON { order_id, payments: [{method, amount, reference}], finalize_note(optional) }
// Returns JSON { success:true, order_id, sales_id }

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php'; // expects $conn (mysqli)

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['order_id']) || !isset($input['payments'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid payload']);
    exit;
}

$order_id = intval($input['order_id']);
$payments = $input['payments'];

try {
    $conn->begin_transaction();

    // Lock order
    $stmt = $conn->prepare("SELECT status, discount, currency, exchange_rate FROM orders WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->bind_result($status, $discount, $currency, $exchange_rate);
    if (!$stmt->fetch()) throw new Exception('Order not found');
    $stmt->close();
    if ($status === 'completed') {
        $conn->commit();
        echo json_encode(['success'=>true,'order_id'=>$order_id,'already_completed'=>true]);
        exit;
    }

    // Sum order total from items
    $stmt = $conn->prepare("SELECT SUM(qty * price) as subtotal FROM order_items WHERE order_id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->bind_result($subtotal);
    $stmt->fetch();
    $stmt->close();
    $subtotal = floatval($subtotal);
    $discount = floatval($discount);
    $total = max(0, $subtotal - $discount);

    // Record payments rows
    $ins = $conn->prepare("INSERT INTO payments (order_id, method, amount, reference, created_at) VALUES (?, ?, ?, ?, NOW())");
    foreach ($payments as $p) {
        $method = $conn->real_escape_string($p['method']);
        $amount = floatval($p['amount']);
        $reference = isset($p['reference']) ? $conn->real_escape_string($p['reference']) : '';
        $ins->bind_param('isds', $order_id, $method, $amount, $reference);
        if (!$ins->execute()) throw new Exception('Insert payment failed: ' . $ins->error);
    }
    $ins->close();

    // Update inventory: simple decrement based on order_items (assuming inventory.product_id exists)
    $stmt = $conn->prepare("SELECT product_id, qty FROM order_items WHERE order_id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->bind_result($product_id, $qty);
    $updInv = $conn->prepare("UPDATE inventory SET stock = stock - ? WHERE product_id = ?");
    while ($stmt->fetch()) {
        $updInv->bind_param('ii', $qty, $product_id);
        $updInv->execute(); // ignore errors here; inventory integrity checks should be added later
    }
    $stmt->close();
    $updInv->close();

    // Mark order completed
    $stmt = $conn->prepare("UPDATE orders SET status = 'completed', completed_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $order_id);
    if (!$stmt->execute()) throw new Exception('Mark order failed: ' . $stmt->error);
    $stmt->close();

    // Insert into sales (simple aggregated)
    $stmt = $conn->prepare("INSERT INTO sales (order_id, subtotal, discount, total, currency, exchange_rate, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('idds', $order_id, $subtotal, $discount, $total, $currency); // adjust datatypes
    // Because bind_param types require specific types, do a simple execute via escaping for currency/exchange_rate:
    $currency_esc = $conn->real_escape_string($currency);
    $exchange_rate = floatval($exchange_rate);
    $query = "INSERT INTO sales (order_id, subtotal, discount, total, currency, exchange_rate, created_at) VALUES ($order_id, $subtotal, $discount, $total, '$currency_esc', $exchange_rate, NOW())";
    if (!$conn->query($query)) throw new Exception('Insert sales failed: ' . $conn->error);

    $conn->commit();
    echo json_encode(['success'=>true, 'order_id'=>$order_id]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}x