(() => {
  'use strict';
  const dataNode = document.getElementById('admin-data');
  if (!dataNode) return;
  const { products, csrf } = JSON.parse(dataNode.textContent);
  const $ = (selector, parent = document) => parent.querySelector(selector);
  const $$ = (selector, parent = document) => [...parent.querySelectorAll(selector)];
  let toastTimeout;
  function notify(message, error = false) {
    const toast = $('[data-toast]');
    toast.textContent = message;
    toast.classList.toggle('is-error', error);
    toast.hidden = false;
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => { toast.hidden = true; }, error ? 9000 : 3500);
  }
  async function request(action, values = {}) {
    const body = new FormData();
    body.set('csrf_token', csrf);
    body.set('action', action);
    Object.entries(values).forEach(([key, value]) => body.set(key, value));
    const response = await fetch('admin.php', { method: 'POST', body });
    let result;
    try { result = await response.json(); } catch { throw new Error('Réponse inattendue. Rechargez la page puis réessayez.'); }
    if (!response.ok || !result.ok) throw new Error(result.error || 'Impossible d’enregistrer. Réessayez.');
    return result;
  }
  const imageIcon = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m3 17 6-6 5 5 3-3 4 4"/><circle cx="16" cy="8" r="1"/></svg>';
  const mediaValues = field => field.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean);
  function preview(field) {
    const container = $('[data-media-preview]', field.parentElement);
    container.replaceChildren();
    const values = mediaValues(field);
    if (!values.length) {
      const empty = document.createElement('div');
      empty.className = 'media-empty';
      empty.innerHTML = imageIcon + '<span>Aperçu de l’image</span>';
      container.append(empty);
    }
    values.forEach((src, index) => {
      const figure = document.createElement('figure');
      const img = document.createElement('img');
      img.src = src; img.alt = `Image sélectionnée ${index + 1}`;
      const remove = document.createElement('button');
      remove.type = 'button'; remove.textContent = '×';
      remove.setAttribute('aria-label', `Retirer l’image ${index + 1}`);
      remove.addEventListener('click', () => {
        field.value = values.filter((_, i) => i !== index).join('\n'); preview(field);
      });
      figure.append(img, remove); container.append(figure);
    });
  }
  $$('[data-media-field]').forEach(preview);
  const modal = $('[data-library-modal]');
  let activeField;
  function filterLibrary() {
    const term = $('[data-library-search]').value.toLocaleLowerCase();
    const items = $$('[data-library-item]');
    items.forEach(item => { item.hidden = !item.dataset.src.toLocaleLowerCase().includes(term); });
    $('[data-library-empty]').hidden = items.some(item => !item.hidden);
  }
  function countLibrary() {
    $('[data-library-count]').textContent = `${$$('[data-library-checkbox]:checked').length} sélectionnée(s)`;
  }
  $$('[data-open-library]').forEach(button => button.addEventListener('click', () => {
    activeField = document.getElementById(button.dataset.target);
    const selected = mediaValues(activeField);
    $$('[data-library-checkbox]').forEach(input => { input.checked = selected.includes(input.value); });
    $('[data-library-search]').value = '';
    filterLibrary(); countLibrary(); modal.showModal();
  }));
  $('[data-close-library]').addEventListener('click', () => modal.close());
  $('[data-library-search]').addEventListener('input', filterLibrary);
  modal.addEventListener('change', countLibrary);
  $('[data-apply-library]').addEventListener('click', () => {
    const known = new Set($$('[data-library-checkbox]').map(input => input.value));
    const retained = mediaValues(activeField).filter(src => !known.has(src));
    activeField.value = [...retained, ...$$('[data-library-checkbox]:checked').map(input => input.value)].join('\n');
    preview(activeField); modal.close();
  });
  modal.addEventListener('click', event => { if (event.target === modal) { const rect = modal.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) modal.close(); } });

  const form = $('#product-form');
  if (!form) return;
  const field = key => form.elements.namedItem(`new_product[${key}]`);
  function countDescription() { $('[data-description-count]').textContent = `${field('description').value.length} caractères`; }
  field('description').addEventListener('input', countDescription); countDescription();
  function resetForm() {
    form.reset();
    ['name', 'badge', 'description', 'tagline', 'payment_url', 'media'].forEach(key => { field(key).value = ''; });
    field('usd').value = '0.00'; field('active').value = '1';
    field('sort_order').value = field('sort_order').dataset.nextOrder;
    const action = $('[data-product-action]'); action.name = 'add_product'; action.value = '1';
    $('[data-form-title]').textContent = 'Ajouter un produit';
    $('[data-submit-product] span').textContent = 'Ajouter le produit';
    $('[data-reset-product]').lastChild.textContent = 'Réinitialiser';
    $('.extra-fields').open = false;
    preview(field('media')); countDescription();
  }
  $('[data-reset-product]').addEventListener('click', resetForm);
  $$('[data-edit]').forEach(button => button.addEventListener('click', () => {
    const row = button.closest('[data-product-id]');
    const product = products[row.dataset.productId];
    ['name', 'usd', 'badge', 'description', 'tagline', 'payment_url'].forEach(key => { field(key).value = product[key] ?? ''; });
    field('sort_order').value = row.dataset.order;
    field('active').value = row.dataset.active;
    field('media').value = (product.media || []).map(image => typeof image === 'string' ? image : image.src).join('\n');
    const action = $('[data-product-action]'); action.name = 'update_product'; action.value = row.dataset.productId;
    $('[data-form-title]').textContent = 'Modifier le produit';
    $('[data-submit-product] span').textContent = 'Enregistrer le produit';
    $('[data-reset-product]').lastChild.textContent = 'Annuler';
    preview(field('media')); countDescription();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' }); field('name').focus({ preventScroll: true });
  }));
  $$('[data-delete-form]').forEach(deleteForm => deleteForm.addEventListener('submit', event => {
    if (!confirm(`Supprimer « ${deleteForm.closest('[data-product-id]').dataset.name} » ? Cette action est définitive.`)) event.preventDefault();
  }));
  let uploading = false;
  async function upload(file) {
    if (!file || uploading) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { notify('Choisissez une image PNG, JPG ou WEBP de moins de 5 Mo.', true); return; }
    uploading = true; $('[data-submit-product]').disabled = true; $('#image-upload').disabled = true;
    $('[data-reset-product]').disabled = true; $$('[data-edit]').forEach(button => { button.disabled = true; });
    notify('Import de l’image…');
    try {
      const result = await request('upload', { image: file });
      field('media').value = [...mediaValues(field('media')), result.src].join('\n');
      preview(field('media')); notify('Image importée.');
    } catch (error) { notify(error.message, true); }
    finally { uploading = false; $('[data-submit-product]').disabled = false; $('#image-upload').disabled = false; $('#image-upload').value = ''; $('[data-reset-product]').disabled = false; $$('[data-edit]').forEach(button => { button.disabled = false; }); }
  }
  $('#image-upload').addEventListener('change', event => upload(event.target.files[0]));
  const dropZone = $('[data-drop-zone]');
  ['dragenter', 'dragover'].forEach(type => dropZone.addEventListener(type, event => { event.preventDefault(); dropZone.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach(type => dropZone.addEventListener(type, event => { event.preventDefault(); dropZone.classList.remove('is-over'); }));
  dropZone.addEventListener('drop', event => upload(event.dataTransfer.files[0]));
  form.addEventListener('submit', event => { if (uploading) event.preventDefault(); });

  const list = $('[data-product-list]');
  const search = $('[data-product-search]');
  const sort = $('[data-product-sort]');
  const rows = () => $$('[data-product-id]', list);
  let savingOrder = false;
  const canReorder = () => !savingOrder && !search.value.trim() && sort.value === 'order';
  const normalize = value => value.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  function filterAndSort() {
    const term = normalize(search.value.trim());
    const items = rows();
    items.sort((a, b) => {
      let result = 0;
      if (sort.value === 'name') result = a.dataset.name.localeCompare(b.dataset.name, 'fr');
      if (sort.value === 'price-asc') result = +a.dataset.price - +b.dataset.price;
      if (sort.value === 'price-desc') result = +b.dataset.price - +a.dataset.price;
      if (sort.value === 'active') result = +b.dataset.active - +a.dataset.active;
      return result || +a.dataset.order - +b.dataset.order || +a.dataset.productId - +b.dataset.productId;
    });
    items.forEach(row => { row.hidden = !normalize(row.dataset.name + ' ' + $('.product-info', row).textContent).includes(term); list.append(row); });
    $('[data-empty-products]').hidden = items.some(row => !row.hidden);
    $$('[data-order-hint]').forEach(hint => { hint.textContent = canReorder() ? 'Glissez la poignée pour réorganiser. L’ordre est enregistré automatiquement.' : 'Pour réorganiser, effacez la recherche et choisissez « Ordre d’affichage ».'; });
    $$('.drag-handle').forEach(handle => { handle.disabled = !canReorder(); handle.draggable = canReorder(); });
  }
  search.addEventListener('input', filterAndSort); sort.addEventListener('change', filterAndSort);
  $$('[data-toggle]').forEach(button => button.addEventListener('click', async () => {
    const row = button.closest('[data-product-id]');
    const active = row.dataset.active !== '1'; button.disabled = true;
    try {
      await request('toggle', { id: row.dataset.productId, active: active ? '1' : '0' });
      row.dataset.active = active ? '1' : '0'; products[row.dataset.productId].active = active;
      button.setAttribute('aria-checked', String(active)); $('[data-active-label]', button).textContent = active ? 'Actif' : 'Inactif';
      row.classList.toggle('is-inactive', !active);
      if ($('[data-product-action]').name === 'update_product' && $('[data-product-action]').value === row.dataset.productId) field('active').value = row.dataset.active;
      filterAndSort(); notify(active ? 'Produit activé.' : 'Produit désactivé.');
    } catch (error) { notify(error.message, true); }
    finally { button.disabled = false; }
  }));
  async function saveOrder(previous) {
    const ordered = rows();
    if (ordered.every((row, index) => row === previous[index])) return;
    savingOrder = true;
    $$('.drag-handle').forEach(handle => { handle.disabled = true; handle.draggable = false; });
    search.disabled = true; sort.disabled = true;
    try {
      await request('reorder', { ids: JSON.stringify(ordered.map(row => row.dataset.productId)) });
      ordered.forEach((row, index) => { row.dataset.order = String(index + 1); products[row.dataset.productId].sort_order = index + 1; $$('[data-order-number]', row).forEach(label => { label.textContent = index + 1; }); });
      field('sort_order').dataset.nextOrder = ordered.length + 1;
      const action = $('[data-product-action]');
      field('sort_order').value = action.name === 'update_product' ? products[action.value].sort_order : ordered.length + 1;
      notify('Ordre d’affichage enregistré.');
    } catch (error) { previous.forEach(row => list.append(row)); notify(error.message, true); }
    finally { savingOrder = false; search.disabled = false; sort.disabled = false; filterAndSort(); }
  }
  let dragged = null;
  let previous = [];
  let dropped = false;
  list.addEventListener('dragstart', event => {
    const handle = event.target.closest('.drag-handle');
    if (!handle || !canReorder()) { event.preventDefault(); return; }
    dragged = handle.closest('[data-product-id]'); previous = rows(); dropped = false;
    event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', dragged.dataset.productId);
    dragged.classList.add('is-dragging');
  });
  function moveAt(y, target) {
    if (!dragged || !target || target === dragged) return;
    const rect = target.getBoundingClientRect();
    list.insertBefore(dragged, y > rect.top + rect.height / 2 ? target.nextSibling : target);
  }
  list.addEventListener('dragover', event => {
    if (!dragged) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move';
    moveAt(event.clientY, event.target.closest('[data-product-id]'));
  });
  list.addEventListener('drop', event => {
    if (!dragged) return; event.preventDefault(); dropped = true;
    dragged.classList.remove('is-dragging'); dragged = null; saveOrder(previous);
  });
  list.addEventListener('dragend', () => {
    if (dragged) dragged.classList.remove('is-dragging');
    if (!dropped && dragged) previous.forEach(row => list.append(row));
    dragged = null;
  });
  // The same handles support keyboard and touch, alongside native desktop drag-and-drop.
  $$('.drag-handle').forEach(handle => {
    handle.addEventListener('keydown', async event => {
      if (!['ArrowUp', 'ArrowDown'].includes(event.key) || !canReorder()) return;
      event.preventDefault(); const before = rows(); const row = handle.closest('[data-product-id]');
      if (event.key === 'ArrowUp' && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
      if (event.key === 'ArrowDown' && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
      await saveOrder(before); handle.focus();
    });
    handle.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse' || !canReorder()) return;
      event.preventDefault(); previous = rows(); dragged = handle.closest('[data-product-id]');
      dragged.classList.add('is-dragging'); handle.setPointerCapture(event.pointerId);
    });
    handle.addEventListener('pointermove', event => {
      if (event.pointerType === 'mouse' || !dragged) return;
      moveAt(event.clientY, document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-product-id]'));
      if (event.clientY < 65) window.scrollBy(0, -15);
      if (event.clientY > window.innerHeight - 65) window.scrollBy(0, 15);
    });
    handle.addEventListener('pointerup', event => {
      if (event.pointerType === 'mouse' || !dragged) return;
      dragged.classList.remove('is-dragging'); dragged = null; saveOrder(previous);
    });
    handle.addEventListener('pointercancel', event => { if (event.pointerType !== 'mouse' && dragged) { dragged.classList.remove('is-dragging'); previous.forEach(row => list.append(row)); dragged = null; } });
  });
})();
