<?php if (!isset($isAdmin) || !$isAdmin) { http_response_code(404); exit; } ?>
<form method="post" action="admin.php?tab=payment" class="space-y-6" id="payment-settings-form">
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="save_payment" value="1">
  <section class="rounded border border-white/10 bg-white/5 p-5 space-y-4">
    <h2 class="text-xl font-semibold">Paiements</h2>
    <p class="text-sm text-gray-300">Configurez les moyens de réception et le parcours client. Le mode manuel nécessite de vérifier vous-même chaque paiement ; aucune confirmation automatique ni livraison n'est encore connectée.</p>
    <label class="flex items-center gap-3"><input type="checkbox" name="payment[enabled]" value="1" <?= $paymentSettings['enabled'] ? 'checked' : '' ?>> Autoriser le parcours de paiement</label>
    <div class="grid gap-4 md:grid-cols-2">
      <label class="block">Mode
        <select name="payment[mode]" class="mt-1 w-full rounded bg-black border border-white/20 p-2">
          <option value="demo" <?= $paymentSettings['mode'] === 'demo' ? 'selected' : '' ?>>Simulation (commandes de test, aucun paiement)</option>
          <option value="manual" <?= $paymentSettings['mode'] === 'manual' ? 'selected' : '' ?>>Paiement avec vérification manuelle</option>
        </select>
      </label>
      <label class="block">Crypto par défaut
        <select name="payment[default_asset]" class="mt-1 w-full rounded bg-black border border-white/20 p-2">
          <?php foreach (payment_assets() as $symbol => $asset): ?>
          <option value="<?= h($symbol) ?>" <?= $paymentSettings['default_asset'] === $symbol ? 'selected' : '' ?>><?= h($symbol . ' — ' . $asset['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block">Durée du montant affiché (minutes)
        <input type="number" min="1" max="1440" step="1" required name="payment[invoice_minutes]" value="<?= h($paymentSettings['invoice_minutes']) ?>" class="mt-1 w-full rounded bg-black border border-white/20 p-2">
        <span class="block text-xs text-gray-400 mt-1">À expiration, le client doit actualiser le montant. Ce délai ne constitue pas une facture serveur.</span>
      </label>
      <label class="block">Conversion des prix USD
        <select name="payment[rate_source]" class="mt-1 w-full rounded bg-black border border-white/20 p-2">
          <option value="live" <?= $paymentSettings['rate_source'] === 'live' ? 'selected' : '' ?>>Taux en ligne (CoinGecko)</option>
          <option value="manual" <?= $paymentSettings['rate_source'] === 'manual' ? 'selected' : '' ?>>Taux fixes définis ci-dessous</option>
        </select>
        <span class="block text-xs text-gray-400 mt-1">En simulation, les taux fixes sont toujours utilisés. En ligne, un taux indisponible bloque le paiement.</span>
      </label>
    </div>
  </section>
  <section class="space-y-4">
    <h2 class="text-xl font-semibold">Comptes et portefeuilles</h2>
    <p class="text-sm text-gray-400">Une adresse de réception par crypto, sur le réseau indiqué. Le libellé du compte reste privé. Ne saisissez jamais de clé privée ni de phrase de récupération. Le contrôle du format ne vérifie pas que l'adresse vous appartient.</p>
    <?php foreach (payment_assets() as $symbol => $asset): $wallet = $paymentSettings['assets'][$symbol]; ?>
    <fieldset class="min-w-0 rounded border border-white/10 bg-white/5 p-5" data-wallet="<?= h($symbol) ?>">
      <legend class="px-2 font-semibold"><?= h($asset['name'] . ' (' . $symbol . ')') ?></legend>
      <label class="flex gap-3 items-center mb-4"><input type="checkbox" name="payment[assets][<?= h($symbol) ?>][enabled]" value="1" <?= $wallet['enabled'] ? 'checked' : '' ?>> Activer <?= h($symbol) ?></label>
      <div class="grid gap-4 md:grid-cols-2">
        <label class="block">Nom du compte / portefeuille (interne)
          <input name="payment[assets][<?= h($symbol) ?>][account_label]" maxlength="120" value="<?= h($wallet['account_label']) ?>" class="mt-1 w-full rounded bg-black border border-white/20 p-2" placeholder="Ex. Trésorerie boutique">
        </label>
        <div>Réseau de réception<p class="mt-2 text-cyan-300"><?= h($asset['network']) ?></p></div>
        <label class="block md:col-span-2">Adresse publique de réception
          <input name="payment[assets][<?= h($symbol) ?>][address]" maxlength="200" spellcheck="false" autocomplete="off" value="<?= h($wallet['address']) ?>" class="mt-1 w-full rounded bg-black border border-white/20 p-2 font-mono text-sm" placeholder="Adresse réelle ; facultative en simulation">
          <span class="block mt-1 text-xs text-gray-400">En simulation, le site affiche une adresse fictive, même si vous renseignez une adresse réelle ici.</span>
        </label>
        <label class="block">Taux fixe : 1 <?= h($symbol) ?> = combien de USD ?
          <input type="number" min="0.00000001" max="1000000000" step="any" required name="payment[assets][<?= h($symbol) ?>][manual_rate]" value="<?= h($wallet['manual_rate']) ?>" class="mt-1 w-full rounded bg-black border border-white/20 p-2">
        </label>
      </div>
    </fieldset>
    <?php endforeach; ?>
  </section>
  <section class="rounded border border-white/10 bg-white/5 p-5 space-y-4">
    <h2 class="text-xl font-semibold">Contact et justificatifs</h2>
    <div class="grid gap-4 md:grid-cols-2">
      <label class="block">E-mail du support
        <input type="email" name="payment[support_email]" maxlength="254" value="<?= h($paymentSettings['support_email']) ?>" class="mt-1 w-full rounded bg-black border border-white/20 p-2">
      </label>
    </div>
    <p class="text-sm text-gray-400">Les commandes et les références sont enregistrées sur ce site, dans l'onglet Commandes. Aucun compte Formspree, pièce jointe ou service d'envoi d'e-mails n'est nécessaire. Les clients suivent leur commande avec leur lien privé.</p>
    <label class="block">Instructions affichées au paiement
      <textarea name="payment[instructions]" maxlength="2000" rows="3" class="mt-1 w-full rounded bg-black border border-white/20 p-2"><?= h($paymentSettings['instructions']) ?></textarea>
    </label>
    <label class="block">Message de délai de traitement
      <textarea name="payment[processing_message]" maxlength="500" rows="2" class="mt-1 w-full rounded bg-black border border-white/20 p-2"><?= h($paymentSettings['processing_message']) ?></textarea>
    </label>
  </section>
  <div class="sticky bottom-0 bg-[#0b0c10]/95 border-t border-white/10 py-4 flex flex-wrap gap-4 items-center">
    <button class="button primary">Enregistrer les paiements</button>
    <a href="payment.html" class="button">Voir le paiement</a>
  </div>
</form>
