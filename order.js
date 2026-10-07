(() => {
  const feedback = document.getElementById('copy-feedback');
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); feedback.textContent = 'Copied.'; }
    catch { feedback.textContent = 'Copy unavailable. Select the text or copy this page address manually.'; }
  }
  document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', () => copy(document.getElementById(button.dataset.copy).value)));
  document.getElementById('copy-tracking')?.addEventListener('click', () => copy(location.href));
  const block = document.getElementById('payment-instructions');
  if (block) {
    const qr = document.getElementById('order-qr');
    if (qr) {
      const address = document.getElementById('order-address').value;
      const amount = document.getElementById('order-amount').value;
      let uri;
      if (qr.dataset.asset === 'BTC') uri = `bitcoin:${address}?amount=${amount}`;
      if (qr.dataset.asset === 'XMR') uri = `monero:${address}?tx_amount=${amount}`;
      if (qr.dataset.asset === 'ETH') {
        const [whole, fraction = ''] = amount.split('.');
        uri = `ethereum:${address}@1?value=${BigInt(whole + fraction.padEnd(18, '0'))}`;
      }
      if (uri) {
        const wallet = document.getElementById('open-wallet');
        wallet.href = uri; wallet.hidden = false;
        if (window.QRCode) new QRCode(qr, {text: uri, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M});
      }
    }
    const tick = () => {
      const remaining = Math.max(0, Number(block.dataset.expires) * 1000 - Date.now());
      document.getElementById('order-countdown').textContent = `${Math.floor(remaining / 60000)}:${String(Math.floor(remaining / 1000) % 60).padStart(2, '0')} remaining`;
      if (!remaining) { block.hidden = true; document.getElementById('expired-message').hidden = false; }
    };
    tick(); setInterval(tick, 1000);
  }
  document.querySelector('.reference-form')?.addEventListener('submit', event => {
    const button = event.target.querySelector('[type="submit"]');
    button.disabled = true; button.textContent = 'Saving your reference…';
  });
})();
