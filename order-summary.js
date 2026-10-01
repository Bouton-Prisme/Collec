(async () => {
  const form = document.getElementById('proof-form');
  const status = document.getElementById('proof-status');
  const submit = form.querySelector('[type="submit"]');
  const box = document.getElementById('order-summary-content');
  const readStorage = (storage, key, fallback) => {
    try { return JSON.parse(storage.getItem(key)) || fallback; } catch { return fallback; }
  };
  let ready = false;
  let demoMode = true;
  let paymentSettings = null;
  let checkoutExpiresAt = 0;
  let updateMetadata = () => {};
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || submit.disabled) return;
    if (Date.now() >= checkoutExpiresAt) {
      status.textContent = 'This amount has expired. Return to payment to refresh it.';
      submit.disabled = true;
      return;
    }
    submit.disabled = true;
    try {
      const response = await fetch('payment-config.php', { cache: 'no-store' });
      if (!response.ok) throw new Error('Settings unavailable');
      const current = await response.json();
      if (!current.enabled || current.revision !== paymentSettings.revision) throw new Error('Settings changed');
    } catch {
      status.textContent = 'Payment settings have changed or are unavailable. Return to the payment page before continuing.';
      return;
    }
    updateMetadata();
    if (demoMode) {
      status.textContent = 'Demo complete. Nothing was sent; no payment or delivery took place.';
      submit.disabled = false;
      return;
    }
    submit.disabled = true;
    status.textContent = 'Sending…';
    try {
      const response = await fetch(form.action, {
        method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('Submission failed');
      status.textContent = 'Payment reference sent for manual review. Payment is not yet confirmed.';
    } catch {
      status.textContent = 'Sending failed. Your entries have been kept; please try again.';
    } finally { submit.disabled = false; }
  });
  try {
    const response = await fetch(`products.json?v=${Date.now()}`, { cache: 'no-store' });
    if (!response.ok) throw new Error('Catalogue unavailable');
    const config = await response.json();
    const settingsResponse = await fetch('payment-config.php', { cache: 'no-store' });
    if (!settingsResponse.ok) throw new Error('Payment settings unavailable');
    paymentSettings = await settingsResponse.json();
    demoMode = paymentSettings.mode === 'demo';
    document.querySelector('[data-demo-notice]').hidden = !demoMode;
    document.querySelector('[data-submit-label]').textContent = demoMode ? 'Simulate submission' : 'Send';
    document.getElementById('processingMessage').textContent = demoMode ? 'This demo does not send email, confirm payment or deliver files.' : paymentSettings.processing_message;
    form.querySelector('[name="proof"]').required = paymentSettings.proof_required;
    if (!paymentSettings.enabled) {
      box.textContent = 'Payments are temporarily unavailable. Please return to the catalogue.';
      return;
    }
    if (!demoMode) {
      if (!paymentSettings.form_endpoint) throw new Error('Submission is not configured');
      form.action = paymentSettings.form_endpoint;
    }
    const query = new URLSearchParams(location.search);
    const cart = readStorage(localStorage, 'bitshop.cart', []);
    const checkout = readStorage(sessionStorage, 'bitshop.checkout', {});
    const id = query.get('value') || (Array.isArray(cart) && cart[0]?.id);
    const product = config.products?.[id];
    if (!product) {
      box.textContent = 'No product selected. Return to the catalogue to choose a product.';
      return;
    }
    const item = Array.isArray(cart) ? cart.find(item => String(item.id) === String(id)) : null;
    const qty = Math.max(1, Math.floor(Number(item?.qty) || 1));
    const asset = query.get('asset') || checkout.asset || 'BTC';
    const hasCheckout = checkout.id === id && checkout.asset === asset && checkout.qty === qty && checkout.totalUsd === Number(product.usd) * qty
      && paymentSettings.assets[asset] && checkout.address === paymentSettings.assets[asset].address
      && checkout.settingsRevision === paymentSettings.revision && checkout.expiresAt > Date.now();
    if (!hasCheckout) {
      box.textContent = 'Your selection has changed or expired. Return to the payment page to confirm it.';
      return;
    }
    checkoutExpiresAt = checkout.expiresAt;
    const total = (Number(product.usd) * qty).toFixed(2);
    const name = document.createElement('p');
    name.className = 'font-semibold';
    name.textContent = product.name;
    const detail = document.createElement('p');
    detail.textContent = `Qty: ${qty} · ${total} USD · ${checkout.coinAmount} ${asset}`;
    box.replaceChildren(name, detail);
    const set = (id, value) => { document.getElementById(id).value = value; };
    updateMetadata = () => {
      set('product_id', id);
      set('product_name', product.name);
      set('currency', asset);
      set('amount', checkout.coinAmount);
      set('wallet_addr', checkout.address);
      const txid = document.getElementById('txid').value || '(pending)';
      const subject = `BitShop | ${product.name} | ${total} USD | TXID: ${txid}`;
      set('mail-subject', subject);
      set('mail-subject-dup', subject);
      set('summary', `Product: ${product.name} (id=${id})\nQty: ${qty}\nTotal: ${total} USD\nAmount: ${checkout.coinAmount} ${asset}\nReceiving address: ${checkout.address}\nTXID: ${txid}`);
    };
    document.getElementById('txid').addEventListener('input', updateMetadata);
    updateMetadata();
    ready = true;
    submit.disabled = false;
  } catch {
    box.textContent = 'The catalogue could not be loaded. Please refresh the page.';
  }
})();
