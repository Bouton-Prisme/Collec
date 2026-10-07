<?php
if (!isset($isAdmin)) { http_response_code(404); exit; }
function icon($name, $class = '') {
    $paths = [
        'box' => '<path d="m12 3 9 5v8l-9 5-9-5V8zM3 8l9 5 9-5M12 13v8M7.5 5.5l9 5"/>',
        'card' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h3"/>',
        'wheel' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2"/><path d="M12 3v7m0 4v7M3 12h7m4 0h7M6 6l4.5 4.5m3 3L18 18M6 18l4.5-4.5m3-3L18 6"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="16" cy="8" r="1"/><path d="m3 17 6-6 5 5 3-3 4 4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'reset' => '<path d="M3 10a9 9 0 1 1 2 8M3 4v6h6"/>',
        'search' => '<circle cx="10" cy="10" r="6.5"/><path d="m15 15 5 5"/>',
        'edit' => '<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14z"/>',
        'trash' => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        'grip' => '<circle cx="9" cy="5" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="19" r="1"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
    ];
    return '<svg class="icon ' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
$editingId = $error ? (string) ($_POST['update_product'] ?? '') : '';
$draft = $error ? ($_POST['new_product'] ?? []) : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BitShop Admin — <?= h(['products' => 'Products', 'payment' => 'Payments', 'roulette' => 'Roulette', 'orders' => 'Commandes'][$activeTab]) ?></title>
  <?php if ($activeTab === 'payment'): ?><script src="https://cdn.tailwindcss.com"></script><?php endif; ?>
  <link rel="stylesheet" href="admin.css">
  <link rel="stylesheet" href="order.css">
  <link rel="stylesheet" href="site-ui.css">
  <script src="admin.js" defer></script>
</head>
<body>
  <header class="admin-header">
    <a class="brand" href="admin.php"><?= icon('box') ?><span><strong>BitShop Admin</strong><small>Gestion de vos produits et ressources</small></span></a>
    <?php if ($isAdmin): ?><div class="account"><a class="button" href="home.html">Voir la boutique <?= icon('arrow') ?></a><a class="button logout" href="admin.php?logout=1">Déconnexion</a></div><?php endif; ?>
  </header>
  <?php if ($isAdmin): ?>
  <aside class="sidebar">
    <nav aria-label="Administration">
      <?php foreach (['products' => ['Products', 'box'], 'payment' => ['Payments', 'card'], 'orders' => ['Commandes', 'box'], 'roulette' => ['Roulette', 'wheel']] as $key => [$label, $symbol]): ?>
      <a href="admin.php?tab=<?= h($key) ?>" <?= $activeTab === $key ? 'aria-current="page"' : '' ?>><?= icon($symbol) ?><span><?= h($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"><span class="status-dot"></span> Espace administrateur</div>
  </aside>
  <?php endif; ?>
  <main class="<?= $isAdmin ? 'admin-main' : 'login-main' ?>">
    <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($saved || $added || $deleted || $paymentSaved): ?><div class="notice success" role="status"><?= $paymentSaved ? 'Configuration de paiement enregistrée.' : ($added ? 'Produit ajouté.' : ($deleted ? 'Produit supprimé.' : ($activeTab === 'roulette' ? 'Roulette enregistrée.' : ($activeTab === 'orders' ? 'Commande enregistrée.' : 'Produit enregistré.')))) ?></div><?php endif; ?>
    <?php if (!$isAdmin): ?>
      <form method="post" class="panel login-form">
        <?= icon('box', 'section-icon') ?><h1>Connexion administrateur</h1><p class="muted">Bienvenue dans votre espace BitShop.</p>
        <label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        <button class="button primary">Se connecter <?= icon('arrow') ?></button>
      </form>
    <?php elseif ($activeTab === 'products'): ?>
      <section class="panel create-panel" aria-labelledby="form-title">
        <h1 id="form-title"><?= icon('box', 'section-icon') ?><span data-form-title><?= $editingId ? 'Modifier le produit' : 'Ajouter un produit' ?></span></h1>
        <form method="post" id="product-form">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="<?= $editingId ? 'update_product' : 'add_product' ?>" value="<?= h($editingId ?: '1') ?>" data-product-action>
          <input type="hidden" name="new_product[active]" value="<?= h($draft['active'] ?? '1') ?>">
          <div class="creation-grid">
            <div class="form-fields">
              <div class="field-pair"><label>Nom du produit<input name="new_product[name]" placeholder="Ex : Starter Pack" value="<?= h($draft['name'] ?? '') ?>" required></label><label>Prix (USD)<span class="price-input"><input name="new_product[usd]" type="number" min="0" step="0.01" value="<?= h($draft['usd'] ?? '0.00') ?>" required><span>$</span></span></label></div>
              <div class="field-pair"><label>Ordre d’affichage <span class="help" title="Les produits sont affichés du plus petit au plus grand numéro.">?</span><input name="new_product[sort_order]" type="number" min="1" max="1000000" step="1" value="<?= h($draft['sort_order'] ?? $nextOrder) ?>" data-next-order="<?= h($nextOrder) ?>" required></label><label>Badge<input name="new_product[badge]" list="badge-options" placeholder="Aucun" value="<?= h($draft['badge'] ?? '') ?>"><datalist id="badge-options"><option value="Populaire"><option value="Nouveau"><option value="Best-seller"><option value="Édition limitée"></datalist></label></div>
              <label>Paiement / Lien <span class="optional">facultatif</span><input type="url" name="new_product[payment_url]" placeholder="Ex : https://votre-lien-de-paiement.com" value="<?= h($draft['payment_url'] ?? '') ?>"><small>Laissez vide pour utiliser le paiement de la boutique.</small></label>
              <label>Description<textarea name="new_product[description]" rows="4" placeholder="Décrivez le produit, ses contenus, ses avantages…"><?= h($draft['description'] ?? '') ?></textarea><small class="character-count" data-description-count></small></label>
              <details class="extra-fields"><summary>Options supplémentaires</summary><label>Accroche affichée au paiement<input name="new_product[tagline]" value="<?= h($draft['tagline'] ?? '') ?>" placeholder="Les points forts du produit en une ligne"></label></details>
              <div class="form-actions"><button class="button primary" data-submit-product><?= icon('plus') ?><span><?= $editingId ? 'Enregistrer le produit' : 'Ajouter le produit' ?></span></button><button type="button" class="button" data-reset-product><?= icon('reset') ?>Réinitialiser</button></div>
            </div>
            <div class="image-panel">
              <label>Images du produit</label>
              <div class="upload-zone" data-drop-zone>
                <?= icon('image') ?><strong>Déposez une image ici</strong><small>PNG, JPG ou WEBP (max. 5 Mo)</small>
                <label class="button upload-button" for="image-upload">Choisir une image</label><input id="image-upload" type="file" accept="image/png,image/jpeg,image/webp" class="visually-hidden">
              </div>
              <div data-media-container><textarea id="new-product-media" name="new_product[media]" hidden data-media-field><?= h($draft['media'] ?? '') ?></textarea><div class="media-preview" data-media-preview></div></div>
              <button type="button" class="library-link" data-open-library data-target="new-product-media"><?= icon('image') ?>Parcourir la médiathèque</button>
            </div>
          </div>
        </form>
      </section>
      <section class="panel listing-panel" aria-labelledby="products-title">
        <div class="listing-heading"><h2 id="products-title"><?= icon('box', 'section-icon') ?>Produits existants <span class="muted">(<?= count($data['products']) ?>)</span></h2><div class="list-controls"><label class="search-field"><?= icon('search') ?><input type="search" aria-label="Rechercher un produit" placeholder="Rechercher un produit…" data-product-search></label><label class="sort-label">Trier par<select data-product-sort aria-label="Trier les produits"><option value="order">Ordre d’affichage</option><option value="name">Nom : A → Z</option><option value="price-asc">Prix croissant</option><option value="price-desc">Prix décroissant</option><option value="active">Actifs en premier</option></select></label></div></div>
        <p class="list-hint" data-order-hint>Glissez la poignée pour réorganiser. L’ordre est enregistré automatiquement.</p>
        <div class="product-list" data-product-list>
        <?php foreach ($data['products'] as $id => $product): $media = media_src_list($product['media'] ?? []); $active = ($product['active'] ?? true) !== false; ?>
          <article class="product-row <?= !$active ? 'is-inactive' : '' ?>" data-product-id="<?= h($id) ?>" data-order="<?= h($product['sort_order'] ?? $id) ?>" data-name="<?= h($product['name']) ?>" data-price="<?= h($product['usd']) ?>" data-active="<?= $active ? '1' : '0' ?>">
            <div class="reorder-control"><span class="order-number" data-order-number><?= h($product['sort_order'] ?? $id) ?></span><button type="button" class="drag-handle" draggable="true" aria-label="Déplacer <?= h($product['name']) ?>" title="Glisser pour déplacer, ou utiliser les flèches du clavier"><?= icon('grip') ?></button></div>
            <div class="product-thumbnail"><?php if ($media): ?><img src="<?= h($media[0]) ?>" alt="<?= h($product['name']) ?>" loading="lazy"><?php else: ?><?= icon('image') ?><?php endif; ?></div>
            <div class="product-info"><div class="product-meta"><h3><?= h($product['name']) ?></h3><?php if (!empty($product['badge'])): ?><span class="product-badge" data-tone="<?= ((int) $id) % 4 ?>"><?= h($product['badge']) ?></span><?php endif; ?><span class="price-chip"><?= h(number_format((float) $product['usd'], 2, ',', ' ')) ?> $</span><span class="order-label">Ordre <span data-order-number><?= h($product['sort_order'] ?? $id) ?></span></span></div><p><?= h($product['description'] ?? '') ?></p></div>
            <div class="row-actions"><button type="button" class="toggle-control" role="switch" aria-checked="<?= $active ? 'true' : 'false' ?>" aria-label="Activer <?= h($product['name']) ?>" data-toggle><span class="switch-track"></span><span data-active-label><?= $active ? 'Actif' : 'Inactif' ?></span></button><button type="button" class="button edit-button" data-edit><?= icon('edit') ?>Éditer</button><form method="post" data-delete-form><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><button class="button danger" name="delete_product" value="<?= h($id) ?>"><?= icon('trash') ?>Supprimer</button></form></div>
          </article>
        <?php endforeach; ?>
        </div>
        <div class="empty-state" data-empty-products <?= count($data['products']) ? 'hidden' : '' ?>><?= icon('box') ?><h3>Aucun produit trouvé</h3><p>Ajoutez un produit ou modifiez votre recherche.</p></div>
      </section>
    <?php elseif ($activeTab === 'orders'): ?>
      <?php require __DIR__ . '/admin-orders.php'; ?>
    <?php elseif ($activeTab === 'payment'): ?>
      <div class="payment-content"><?php require __DIR__ . '/admin-payment.php'; ?></div>
    <?php else: ?>
      <section class="panel roulette-panel"><h1><?= icon('wheel', 'section-icon') ?>Roulette</h1><p class="muted">Choisissez les images utilisées par la roulette.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="save_roulette" value="1"><div data-media-container><textarea id="roulette-media" name="roulette[media]" hidden data-media-field><?= h(implode("\n", media_src_list($data['roulette']['media']))) ?></textarea><div class="media-preview" data-media-preview></div></div><div class="form-actions"><button type="button" class="button" data-open-library data-target="roulette-media"><?= icon('image') ?>Médiathèque</button><button class="button primary">Enregistrer la roulette</button><a class="button" href="roulette.html">Voir la roulette <?= icon('arrow') ?></a></div></form></section>
    <?php endif; ?>
  </main>
  <?php if ($isAdmin): ?>
  <dialog class="library-modal" data-library-modal aria-labelledby="library-title"><div class="dialog-header"><div><h2 id="library-title">Médiathèque</h2><p class="muted">Sélectionnez une ou plusieurs images.</p></div><button type="button" class="button" data-close-library>Fermer</button></div><div class="library-toolbar"><input type="search" placeholder="Rechercher une image…" aria-label="Rechercher une image" data-library-search><span data-library-count></span></div><div class="library-grid"><?php foreach ($mediaLibrary as $item): ?><label class="library-item" data-library-item data-src="<?= h($item['src']) ?>"><input type="checkbox" value="<?= h($item['src']) ?>" data-library-checkbox><img src="<?= h($item['src']) ?>" alt="<?= h($item['name']) ?>" loading="lazy"><span><?= h($item['name']) ?></span></label><?php endforeach; ?></div><p data-library-empty <?= $mediaLibrary ? 'hidden' : '' ?>>Aucune image disponible.</p><div class="dialog-footer"><button type="button" class="button primary" data-apply-library>Utiliser la sélection</button></div></dialog>
  <div class="toast" role="status" aria-live="polite" data-toast hidden></div>
  <script type="application/json" id="admin-data"><?= json_encode(['products' => $data['products'], 'csrf' => $_SESSION['csrf_token']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</body>
</html>
