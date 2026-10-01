(async () => {
  try {
    const response = await fetch('payment-config.php', { cache: 'no-store' });
    if (!response.ok) return;
    const settings = await response.json();
    document.querySelectorAll('[data-support-email]').forEach(element => {
      element.replaceChildren();
      if (!settings.support_email) {
        element.textContent = 'Support contact is not configured.';
        return;
      }
      const link = document.createElement('a');
      link.textContent = settings.support_email;
      link.href = 'mailto:' + settings.support_email;
      link.style.overflowWrap = 'anywhere';
      element.appendChild(link);
    });
    document.querySelectorAll('[data-site-demo]').forEach(element => { element.hidden = settings.mode !== 'demo'; });
  } catch { /* Keep a readable page if settings cannot be loaded. */ }
})();
