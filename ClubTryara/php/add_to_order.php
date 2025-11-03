<?php
// bill_out.php
// This view keeps minimal server logic; client-side should call create_order and open receipt.

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Bill Out</title>
  <script src="/ClubTryara/js/receipt_and_proceed.js" defer></script>
  <script defer>
    document.addEventListener('DOMContentLoaded', function(){
      // Example hook: gather order details from page (your app.js should provide)
      const billBtn = document.getElementById('doBillOut');
      billBtn.addEventListener('click', async function(){
        // assume window.gatherCurrentOrder() is provided by app.js and returns the orderPayload
        if (!window.gatherCurrentOrder) { alert('gatherCurrentOrder missing'); return; }
        const payload = window.gatherCurrentOrder();
        await window.ClubTryara.createAndShowReceipt(payload, true);
      });
    });
  </script>
</head>
<body>
  <button id="doBillOut">Bill Out (Print Receipt)</button>
</body>
</html>