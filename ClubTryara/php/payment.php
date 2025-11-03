<?php
// payment.php - simple payment selection UI for a pending order_id passed as ?order_id=123
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
if (!$orderId) {
    echo "Invalid order id"; exit;
}
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Payment</title>
<style>
  body{font-family:Arial;padding:18px}
  .method{border:1px solid #ddd;padding:12px;margin-bottom:8px;border-radius:8px;cursor:pointer}
  .method.selected{outline:3px solid #4b9; background:#f7fff7}
</style>
</head>
<body>
  <h2>Complete Payment (Order #<?= $orderId ?>)</h2>
  <div id="methods">
    <div class="method" data-method="cash"><h3>Cash</h3><div>Pay with cash. No extra steps.</div></div>
    <div class="method" data-method="gcash"><h3>GCash</h3><div>Show QR or transaction reference.</div></div>
    <div class="method" data-method="bank_transfer"><h3>Bank Transfer</h3><div>Show bank details and reference input.</div></div>
  </div>

  <div id="paymentForm" style="margin-top:12px;display:none">
    <label>Amount paid: <input type="number" id="amountPaid" step="0.01" /></label>
    <div id="detailsArea"></div>
    <div style="margin-top:12px;">
      <button id="payBtn">Submit Payment</button>
      <button id="cancelBtn">Cancel</button>
    </div>
  </div>

<script>
(() => {
  const methods = document.querySelectorAll('.method');
  let selected = null;
  methods.forEach(m => {
    m.addEventListener('click', () => {
      methods.forEach(x => x.classList.remove('selected'));
      m.classList.add('selected');
      selected = m.dataset.method;
      document.getElementById('paymentForm').style.display = 'block';
      const details = document.getElementById('detailsArea');
      details.innerHTML = '';
      if (selected === 'gcash') {
        details.innerHTML = '<label>Reference: <input id=\"ref\" type=\"text\" /></label><div style=\"margin-top:6px\">Show the QR to the customer</div>';
      } else if (selected === 'bank_transfer') {
        details.innerHTML = '<label>Account Ref: <input id=\"ref\" type=\"text\" /></label><div style=\"margin-top:6px\">Bank: ACME Bank<br/>Account: 123-456-789</div>';
      } else {
        details.innerHTML = '<div style=\"margin-top:6px\">Collect cash and enter amount.</div>';
      }
    });
  });

  document.getElementById('payBtn').addEventListener('click', async () => {
    if (!selected) return alert('Select payment method');
    const amount = parseFloat(document.getElementById('amountPaid').value || '0');
    if (isNaN(amount) || amount <= 0) return alert('Enter amount paid');
    const ref = document.getElementById('ref') ? document.getElementById('ref').value : null;
    // POST to server to finalize the order
    try {
      const res = await fetch('../api/complete_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          order_id: <?= $orderId ?>,
          payment: { method: selected, amount_paid: amount, details: { ref: ref } }
        })
      });
      const j = await res.json();
      // Notify opener (POS) that order is completed
      if (window.opener) {
        window.opener.postMessage({ type: 'order_completed', order_id: <?= $orderId ?>, result: j }, '*');
      }
      alert('Payment recorded. Closing window.');
      window.close();
    } catch (e) {
      alert('Error recording payment: ' + e.message);
    }
  });

  document.getElementById('cancelBtn').addEventListener('click', () => window.close());
})();
</script>
</body>
</html>