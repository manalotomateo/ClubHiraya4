<?php
// php/print_receipt.php
// Enhanced printable receipt that supports:
// - Printing a temporarily posted cart (POST: cart, totals) (same behavior as before)
// - Printing a saved order by order_id (?order_id=123). When printing a saved order the page
//   shows stored items, saved currency/exchange_rate and will coordinate with the opener (POS)
//   to finalize the order (complete_order). If the opener does not respond, the receipt falls
//   back to calling the server finalize endpoint (idempotent).
//
// Behavior on Close/Back:
// - If opened by POS (window.opener) and we have an order_id, this page will postMessage
//   { type: 'receipt_printed', order_id } to the opener and wait (short timeout) for a reply
//   message { type: 'order_completed', order_id } before closing. If no reply, it will call
//   ../api/complete_order.php as a fallback.
// - If no order_id (temporary print), fallback posts to ../api/update_stock.php (preserves old behavior).

$cartJson    = $_POST['cart']   ?? null;
$totalsJson  = $_POST['totals'] ?? null;
$orderId     = isset($_GET['order_id']) ? (int)$_GET['order_id'] : null;

if ($cartJson === null && $orderId === null) {
    // No POST data and no order id -> show the info page (like original)
    header('X-Robots-Tag: noindex, nofollow', true);
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8" />
      <title>Receipt - Club Hiraya</title>
      <meta name="viewport" content="width=device-width,initial-scale=1" />
      <style>
        body { font-family: Arial, sans-serif; padding: 28px; color: #222; background:#f7f7fb; }
        .card { max-width:720px; margin:40px auto; background:#fff; padding:20px; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,0.06); }
        h1 { margin:0 0 8px 0; font-size:20px; }
        p { color:#444; }
        a.button { display:inline-block; margin-top:16px; padding:10px 16px; background:#d51ecb; color:#fff; border-radius:8px; text-decoration:none; font-weight:700; }
      </style>
    </head>
    <body>
      <div class="card">
        <h1>No receipt data</h1>
        <p>This page was opened without receipt data. Open the Bill Out/Print Receipt function from the POS (index.php) to print a receipt.</p>
        <p><a class="button" href="index.php">Back to POS</a></p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Initialize variables that will be used by the template
$cart = [];
$totals = [];
$date = date('Y-m-d H:i:s');
$printed_order = null; // DB order row if loaded
$exchange_info = null; // decoded exchange_rate array if present
$currency = 'PHP';

// If order_id provided, try to load order + items from DB and use that as canonical receipt data
if ($orderId) {
    // Attempt to load DB connection. Adjust path if your db.php is elsewhere.
    $dbPath = __DIR__ . '/../api/db.php';
    if (file_exists($dbPath)) {
        require_once $dbPath; // provides $pdo (PDO)
        try {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $printed_order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($printed_order) {
                $currency = $printed_order['currency'] ?? 'PHP';
                if (!empty($printed_order['exchange_rate'])) {
                    $exchange_info = json_decode($printed_order['exchange_rate'], true);
                }
                // Load items (use recorded unit_price)
                $itst = $pdo->prepare('SELECT oi.qty, oi.unit_price, f.name FROM order_items oi LEFT JOIN foods f ON f.id = oi.food_id WHERE oi.order_id = :oid');
                $itst->execute([':oid' => $orderId]);
                $items = $itst->fetchAll(PDO::FETCH_ASSOC);
                $cart = [];
                foreach ($items as $it) {
                    $cart[] = [
                        'name' => $it['name'] ?? 'Item',
                        'qty' => (int)$it['qty'],
                        'price' => (float)$it['unit_price']
                    ];
                }
                // compute simple totals from stored unit_price and qty
                $subtotal = 0.0;
                foreach ($cart as $ci) $subtotal += ($ci['price'] * $ci['qty']);
                $discount = isset($printed_order['discount']) ? floatval($printed_order['discount']) : 0.0;
                // We don't have saved tax/service in orders table here, so Payable = subtotal - discount
                $payable = $subtotal - $discount;
                $totals = [
                    'subtotal' => $subtotal,
                    'serviceCharge' => 0.0,
                    'tax' => 0.0,
                    'discountAmount' => $discount,
                    'payable' => $payable
                ];
            } else {
                // order_id provided but not found -> fall back to showing message (behave like no data)
                header('X-Robots-Tag: noindex, nofollow', true);
                ?>
                <!doctype html>
                <html>
                <head><meta charset="utf-8"/><title>Receipt not found</title></head>
                <body>
                  <h1>Order not found</h1>
                  <p>The order you requested (ID <?= htmlspecialchars($orderId, ENT_QUOTES) ?>) could not be located.</p>
                  <p><a href="index.php">Back to POS</a></p>
                </body>
                </html>
                <?php
                exit;
            }
        } catch (Exception $e) {
            // DB error -> fall back to temporary behavior
            $printed_order = null;
            $cart = [];
            $totals = [];
        }
    } else {
        // db.php not found; cannot load order -> fall back to temporary
        $printed_order = null;
    }
}

// If no saved order loaded, but POST cart/totals exist, use those (original behavior)
if (!$printed_order && $cartJson !== null) {
    $cart = json_decode($cartJson, true);
    $totals = json_decode($totalsJson, true);
    if (!is_array($cart)) $cart = [];
    if (!is_array($totals)) $totals = [];
    $currency = 'PHP';
}

// Formatting helpers
function fmtPHP($n) {
    return '₱' . number_format((float)$n, 2);
}
function safe($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function fmtConverted($amountPhp, $exchange_info, $currency) {
    if (!$exchange_info || !isset($exchange_info['rate']) || !$currency || $currency === 'PHP') return '';
    $rate = floatval($exchange_info['rate']);
    $converted = $amountPhp * $rate;
    if ($currency === 'JPY') {
        return ($currency === 'JPY' ? '¥' : '') . number_format(round($converted), 0);
    }
    $symbol = $currency === 'USD' ? '$' : ($currency === 'EUR' ? '€' : '');
    return $symbol . number_format($converted, 2);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Receipt - Club Hiraya</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    body{font-family:Arial,sans-serif;padding:20px;color:#111;background:#fff;}
    header{text-align:center;margin-bottom:10px}
    .items{width:100%;border-collapse:collapse;margin-top:10px}
    .items th,.items td{border-bottom:1px solid #eee;padding:10px;text-align:left}
    .right{text-align:right}
    .controls{margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
    .btn{padding:10px 16px;border-radius:8px;border:none;font-weight:700;color:#fff;text-decoration:none;cursor:pointer}
    .btn-print{background:#2b6cb0}
    .btn-back{background:#d51ecb}
    .btn-close{background:#6b7280}
    .meta{color:#666;font-size:13px;margin-top:6px}
    .exchange{margin-top:8px;color:#444;font-size:13px}
    @media print{ .no-print{display:none} }
  </style>
</head>
<body>
  <header>
    <h2>Club Hiraya</h2>
    <div style="color:#666;font-size:13px;margin-top:4px">Receipt</div>
    <div class="meta"><?= safe($date) ?></div>
    <?php if ($printed_order): ?>
      <div class="meta">Receipt #: <?= safe($printed_order['id']) ?> &nbsp; &nbsp; Status: <?= safe($printed_order['status']) ?></div>
    <?php endif; ?>
  </header>

  <table class="items" aria-label="Receipt items">
    <thead>
      <tr><th style="width:56%;">Item</th><th style="width:12%;">Qty</th><th style="width:16%;" class="right">Price</th><th style="width:16%;" class="right">Total</th></tr>
    </thead>
    <tbody>
      <?php if (empty($cart)): ?>
        <tr><td colspan="4" style="padding:18px;text-align:center;color:#666;">(No items)</td></tr>
      <?php else: foreach ($cart as $it):
        $name = safe($it['name'] ?? 'Item');
        $qty = (int)($it['qty'] ?? 0);
        $price = (float)($it['price'] ?? 0.0);
        $line = $price * $qty;
      ?>
        <tr>
          <td><?= $name ?></td>
          <td><?= $qty ?></td>
          <td class="right"><?= fmtPHP($price) ?><?php if ($exchange_info && $currency !== 'PHP') echo ' <small style="color:#666">(' . safe(fmtConverted($price, $exchange_info, $currency)) . ')</small>'; ?></td>
          <td class="right"><?= fmtPHP($line) ?><?php if ($exchange_info && $currency !== 'PHP') echo ' <small style="color:#666">(' . safe(fmtConverted($line, $exchange_info, $currency)) . ')</small>'; ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <table class="totals" style="margin-top:12px;width:100%">
    <tr><td style="color:#444">Subtotal</td><td style="text-align:right;font-weight:700"><?= fmtPHP($totals['subtotal'] ?? 0) ?><?php if ($exchange_info && $currency !== 'PHP') echo ' <span style="color:#666">(' . safe(fmtConverted($totals['subtotal'] ?? 0, $exchange_info, $currency)) . ')</span>'; ?></td></tr>
    <tr><td style="color:#444">Service Charge</td><td style="text-align:right;font-weight:700"><?= fmtPHP($totals['serviceCharge'] ?? 0) ?><?php if ($exchange_info && $currency !== 'PHP') echo ' <span style="color:#666">(' . safe(fmtConverted($totals['serviceCharge'] ?? 0, $exchange_info, $currency)) . ')</span>'; ?></td></tr>
    <tr><td style="color:#444">Tax</td><td style="text-align:right;font-weight:700"><?= fmtPHP($totals['tax'] ?? 0) ?><?php if ($exchange_info && $currency !== 'PHP') echo ' <span style="color:#666">(' . safe(fmtConverted($totals['tax'] ?? 0, $exchange_info, $currency)) . ')</span>'; ?></td></tr>
    <tr><td style="color:#444">Discount</td><td style="text-align:right;font-weight:700"><?= fmtPHP($totals['discountAmount'] ?? 0) ?><?php if ($exchange_info && $currency !== 'PHP') echo ' <span style="color:#666">(' . safe(fmtConverted($totals['discountAmount'] ?? 0, $exchange_info, $currency)) . ')</span>'; ?></td></tr>
    <tr style="border-top:2px solid #eee;"><td><strong>Payable</strong></td><td style="text-align:right;font-weight:900"><strong><?= fmtPHP($totals['payable'] ?? 0) ?></strong><?php if ($exchange_info && $currency !== 'PHP') echo ' <strong style="color:#666">(' . safe(fmtConverted($totals['payable'] ?? 0, $exchange_info, $currency)) . ')</strong>'; ?></td></tr>
  </table>

  <?php if ($printed_order && $exchange_info): ?>
    <div class="exchange">
      Currency: <?= safe($printed_order['currency']) ?> — Rate: <?= safe($exchange_info['rate'] ?? '') ?> (code: <?= safe($exchange_info['code'] ?? '') ?>)
    </div>
  <?php endif; ?>

  <div class="controls no-print">
    <button id="printBtn" class="btn btn-print" type="button" onclick="window.print();">Print</button>

    <!-- Back to POS: will behave like "proceed" (request finalize) then return -->
    <a id="backToPos" class="btn btn-back" href="index.php"
       onclick="event.preventDefault(); tryProceedAndReturn();">Back to POS</a>

    <!-- Close: same behavior -->
    <a id="closeBtn" class="btn btn-close" href="index.php"
       onclick="event.preventDefault(); tryProceedAndReturn();">Close</a>
  </div>

  <script>
    // Receipt data exposed to JS
    const __receipt_cart = <?= json_encode($cart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const __receipt_totals = <?= json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const __receipt_order_id = <?= $printed_order ? (int)$printed_order['id'] : 'null' ?>;
    const __receipt_payable = <?= isset($totals['payable']) ? (float)$totals['payable'] : 0 ?>;

    // Try to signal the opener to finalize the order; if it doesn't exist, use fallback API calls.
    function tryProceedAndReturn() {
      // If we have an order_id and the opener exists, prefer to signal the opener and let it finalize the order.
      if (window.opener && __receipt_order_id) {
        try {
          // Notify opener that printing finished and ask it to finalize (or show payment options).
          window.opener.postMessage({ type: 'receipt_printed', order_id: __receipt_order_id }, '*');

          // Wait for opener to reply with an 'order_completed' message for this order.
          let handled = false;
          function listener(e) {
            if (!e.data) return;
            try {
              if (e.data.type === 'order_completed' && e.data.order_id === __receipt_order_id) {
                handled = true;
                window.removeEventListener('message', listener);
                // Close popup
                try { window.close(); } catch (e) { window.location.href = 'index.php'; }
              }
            } catch (err) { /* ignore */ }
          }
          window.addEventListener('message', listener);

          // Fallback timeout: if opener does not respond within 6 seconds, call server finalize endpoint directly.
          setTimeout(() => {
            if (!handled) {
              window.removeEventListener('message', listener);
              fallbackFinalizeOrder();
            }
          }, 6000);

          return;
        } catch (err) {
          console.error('Error posting message to opener:', err);
          // fall through to fallback
        }
      }

      // If we don't have an order_id or opener not present, fallback to update_stock behavior (legacy)
      return fallbackPostThenRedirect();
    }

    // Fallback for non-persisted receipts: post to update_stock.php and return to POS
    function fallbackPostThenRedirect() {
      const payload = { items: (Array.isArray(__receipt_cart) ? __receipt_cart.map(i => ({ id: i.id, qty: i.qty })) : []) };
      if (!payload.items.length) {
        window.location.href = 'index.php';
        return;
      }
      fetch('../api/update_stock.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      }).then(response => {
        // ignore response details; redirect back to POS
        window.location.href = 'index.php';
      }).catch(err => {
        console.error('Failed to update stock from receipt page:', err);
        window.location.href = 'index.php';
      });
    }

    // Fallback finalize when we have a saved order_id but opener didn't respond
    function fallbackFinalizeOrder() {
      if (!__receipt_order_id) { window.location.href = 'index.php'; return; }
      // Call complete_order endpoint. This endpoint is idempotent — safe if the opener already completed it.
      fetch('../api/complete_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          order_id: __receipt_order_id,
          // Payment from receipt fallback: use a special method so it is obvious in records.
          payment: { method: 'print_fallback', amount_paid: __receipt_payable || 0 }
        }),
        credentials: 'same-origin'
      }).then(res => res.json()).then(j => {
        // After fallback attempt, close or redirect
        try { window.close(); } catch (e) { window.location.href = 'index.php'; }
      }).catch(err => {
        console.error('Fallback finalize failed:', err);
        try { window.close(); } catch (e) { window.location.href = 'index.php'; }
      });
    }

    // Auto-print when opened by the POS opener
    window.addEventListener('load', function() {
      if (window.opener) {
        setTimeout(function() { window.print(); }, 400);
      }
    });
  </script>
</body>
</html>
?>