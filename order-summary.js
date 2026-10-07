// Compatibility for old checkout bookmarks.
(() => {
  const params = new URLSearchParams(location.search);
  const target = new URL('payment.html', location.href);
  for (const key of ['value', 'asset']) if (params.has(key)) target.searchParams.set(key, params.get(key));
  target.hash = 'pay';
  const link = document.getElementById('resume-checkout');
  if (link) link.href = target.pathname + target.search + target.hash;
})();
