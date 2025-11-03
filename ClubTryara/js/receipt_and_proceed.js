// receipt_and_proceed.js
// Full client-side helper for creating orders (idempotent), opening receipt windows,
// coordinating finalize between receipt and POS opener, and calling the finalize API.
//
// Usage notes:
// - Your main app should provide gatherCurrentOrder() that returns:
//   { items: [{product_id, qty, price}], discount, note, currency, exchange_rate, table_id }
// - Optional UI hooks your app can implement:
//   - window.onOrderFinalized(orderId)  -> called when order finalized
//   - window.showProceedForOrder(orderId) -> called when receipt didn't finalize and user should proceed in POS
// - The script expects API endpoints at /ClubTryara/api/create_order.php and /ClubTryara/api/complete_order.php

(function (global) {
  const API_BASE = '/ClubTryara/api/';

  function log() {
    if (window.console && console.log) console.log.apply(console, arguments);
  }

  async function apiPost(path, body) {
    const url = API_BASE + path;
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const j = await res.json();
      return j;
    } catch (err) {
      log('API error', path, err);
      return { success: false, error: err.message || 'Network error' };
    }
  }

  function generateIdempotencyKey() {
    // ISO-like timestamp + random suffix ensures practical uniqueness
    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }

  // Create order (idempotent) and open receipt window
  async function createAndShowReceipt(orderPayload, openInNewWindow = true) {
    if (!orderPayload || !Array.isArray(orderPayload.items) || orderPayload.items.length === 0) {
      throw new Error('Invalid order payload');
    }

    // attach an idempotency key so retries won't create duplicates
    const idempotencyKey = generateIdempotencyKey();
    orderPayload.idempotency_key = idempotencyKey;

    const created = await apiPost('create_order.php', orderPayload);
    if (!created || !created.success) {
      throw new Error(created && created.error ? created.error : 'Failed creating order');
    }

    const orderId = created.order_id;
    const receiptUrl = `/ClubTryara/php/print_receipt.php?order_id=${encodeURIComponent(orderId)}`;

    // Open receipt window/tab
    let receiptWin = null;
    try {
      receiptWin = window.open(receiptUrl, 'receipt_' + orderId, 'width=420,height=720');
    } catch (e) {
      // fallback: navigate current window (not ideal)
      receiptWin = window.open(receiptUrl, '_blank');
    }

    // Setup listening for finalize message from receipt window
    let finalized = false;
    function onMessage(e) {
      if (!e || !e.data) return;
      const data = e.data;
      if (data && data.type === 'order_finalized' && parseInt(data.order_id, 10) === parseInt(orderId, 10)) {
        finalized = true;
        window.removeEventListener('message', onMessage);
        if (receiptWin && !receiptWin.closed) {
          try { receiptWin.close(); } catch (err) { /* ignore */ }
        }
        // notify host app
        if (typeof window.onOrderFinalized === 'function') {
          try { window.onOrderFinalized(orderId); } catch (err) { log('onOrderFinalized error', err); }
        }
      }
    }
    window.addEventListener('message', onMessage);

    // Setup fallback: if receipt doesn't finalize within timeout, call host UI to show proceed control
    const fallbackMs = 30000; // 30 seconds
    setTimeout(() => {
      if (!finalized) {
        if (typeof window.showProceedForOrder === 'function') {
          try { window.showProceedForOrder(orderId); } catch (err) { log('showProceedForOrder error', err); }
        } else {
          // default: alert
          alert('Receipt not finalized. Please use Proceed to complete payment.');
        }
      }
    }, fallbackMs);

    return { success: true, order_id: orderId, already_created: !!created.already_created };
  }

  // Finalize order by calling API complete_order.php
  // payments: [{method: 'Cash', amount: 100, reference: ''}, ...]
  async function finalizeOrder(orderId, payments) {
    if (!orderId) throw new Error('orderId required');
    if (!Array.isArray(payments) || payments.length === 0) {
      throw new Error('payments required (array of {method, amount, reference})');
    }

    const payload = { order_id: orderId, payments: payments };
    const res = await apiPost('complete_order.php', payload);
    if (!res || !res.success) {
      throw new Error(res && res.error ? res.error : 'Failed to finalize order');
    }

    // Notify other windows (receipt/opener)
    try {
      window.postMessage({ type: 'order_finalized', order_id: orderId }, '*');
    } catch (err) {
      log('postMessage error', err);
    }

    // Notify host app
    if (typeof window.onOrderFinalized === 'function') {
      try { window.onOrderFinalized(orderId); } catch (err) { log('onOrderFinalized error', err); }
    }

    return { success: true, order_id: orderId };
  }

  // Expose API to global
  global.ClubTryara = global.ClubTryara || {};
  global.ClubTryara.createAndShowReceipt = createAndShowReceipt;
  global.ClubTryara.finalizeOrder = finalizeOrder;
  global.ClubTryara.apiPost = apiPost;
  global.ClubTryara.generateIdempotencyKey = generateIdempotencyKey;

})(window);