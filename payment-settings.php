<?php
// Configuration contains receiving addresses only, never private keys or seed phrases.
function payment_settings_file() {
    return __DIR__ . '/config/payment.local.php';
}

function payment_assets() {
    return [
        'BTC' => ['name' => 'Bitcoin', 'network' => 'Bitcoin mainnet', 'rate_id' => 'bitcoin', 'demo_address' => 'bc1qEXEMPLExxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'demo_rate' => 60000],
        'ETH' => ['name' => 'Ethereum', 'network' => 'Ethereum mainnet', 'rate_id' => 'ethereum', 'demo_address' => '0xEXEMPLE0123456789abcdef0123456789ABCDEF01', 'demo_rate' => 3000],
        'XMR' => ['name' => 'Monero', 'network' => 'Monero mainnet', 'rate_id' => 'monero', 'demo_address' => '4AEXEMPLExxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'demo_rate' => 150],
    ];
}

function payment_defaults() {
    $settings = [
        'enabled' => true, 'mode' => 'demo', 'default_asset' => 'BTC',
        'invoice_minutes' => 15, 'rate_source' => 'live',
        'support_email' => 'collection.archive@proton.me',
        'formspree_id' => '', 'proof_required' => false,
        'instructions' => 'Send the exact amount using the network shown. Then submit your transaction ID for manual review.',
        'processing_message' => 'Payment references are reviewed manually within 24 to 48 hours.',
        'assets' => []
    ];
    foreach (payment_assets() as $symbol => $asset) {
        $settings['assets'][$symbol] = ['enabled' => true, 'account_label' => '', 'address' => '', 'manual_rate' => $asset['demo_rate']];
    }
    return $settings;
}

function load_payment_settings() {
    $file = payment_settings_file();
    if (!is_file($file)) return payment_defaults();
    $contents = file_get_contents($file);
    $prefix = "<?php http_response_code(404); exit; ?>\n";
    if ($contents === false || strpos($contents, $prefix) !== 0) {
        throw new RuntimeException('La configuration de paiement est illisible.');
    }
    $data = json_decode(substr($contents, strlen($prefix)), true);
    if (!is_array($data)) throw new RuntimeException('La configuration de paiement est invalide.');
    return validate_payment_settings(array_replace_recursive(payment_defaults(), $data));
}

function payment_text($value, $max, $label) {
    if (!is_string($value) || strlen($value) > $max) throw new InvalidArgumentException($label . ' : valeur trop longue ou invalide.');
    return trim($value);
}

function payment_address_format($symbol, $address) {
    if ($symbol === 'BTC') return preg_match('/^(?:[13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[ac-hj-np-z02-9]{11,87}|BC1[AC-HJ-NP-Z02-9]{11,87})$/D', $address) === 1;
    if ($symbol === 'ETH') return preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) === 1 && $address !== '0x' . str_repeat('0', 40);
    if ($symbol === 'XMR') return preg_match('/^[48][1-9A-HJ-NP-Za-km-z]{94}$/D', $address) === 1 || preg_match('/^4[1-9A-HJ-NP-Za-km-z]{105}$/D', $address) === 1;
    return false;
}

function validate_payment_settings($input) {
    if (!is_array($input)) throw new InvalidArgumentException('Configuration invalide.');
    $next = payment_defaults();
    $next['enabled'] = !empty($input['enabled']);
    $next['mode'] = $input['mode'] ?? '';
    $next['rate_source'] = $input['rate_source'] ?? '';
    if (!in_array($next['mode'], ['demo', 'manual'], true) || !in_array($next['rate_source'], ['live', 'manual'], true)) {
        throw new InvalidArgumentException('Mode ou source de taux invalide.');
    }
    $next['invoice_minutes'] = filter_var($input['invoice_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
    if ($next['invoice_minutes'] === false) throw new InvalidArgumentException('La durée doit être comprise entre 1 et 1440 minutes.');
    foreach (['support_email' => 254, 'formspree_id' => 80, 'instructions' => 2000, 'processing_message' => 500] as $field => $limit) {
        $next[$field] = payment_text($input[$field] ?? '', $limit, $field);
    }
    if ($next['support_email'] !== '' && !filter_var($next['support_email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Adresse e-mail de support invalide.');
    if ($next['formspree_id'] !== '' && !preg_match('/^[a-zA-Z0-9]{6,80}$/D', $next['formspree_id'])) throw new InvalidArgumentException('Identifiant Formspree invalide (uniquement les lettres et chiffres après /f/).');
    $next['proof_required'] = !empty($input['proof_required']);
    $active = [];
    foreach (payment_assets() as $symbol => $asset) {
        $value = $input['assets'][$symbol] ?? [];
        if (!is_array($value)) throw new InvalidArgumentException('Portefeuille invalide.');
        $rate = filter_var($value['manual_rate'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($rate === false || !is_finite((float) $rate) || $rate <= 0 || $rate > 1000000000) throw new InvalidArgumentException($symbol . ' : le taux doit être positif et inférieur à un milliard.');
        $address = payment_text($value['address'] ?? '', 200, $symbol . ' adresse');
        if ($address !== '' && !payment_address_format($symbol, $address)) throw new InvalidArgumentException($symbol . ' : format d’adresse incompatible avec le réseau principal.');
        $next['assets'][$symbol] = [
            'enabled' => !empty($value['enabled']),
            'account_label' => payment_text($value['account_label'] ?? '', 120, $symbol . ' compte'),
            'address' => $address, 'manual_rate' => (float) $rate
        ];
        if ($next['assets'][$symbol]['enabled']) {
            $active[] = $symbol;
            if ($next['enabled'] && $next['mode'] === 'manual' && $address === '') throw new InvalidArgumentException($symbol . ' : renseignez une adresse de réception ou désactivez cette crypto.');
        }
    }
    $next['default_asset'] = $input['default_asset'] ?? '';
    if (!is_string($next['default_asset']) || !array_key_exists($next['default_asset'], payment_assets())) throw new InvalidArgumentException('Crypto par défaut invalide.');
    if ($next['enabled'] && (!count($active) || !in_array($next['default_asset'], $active, true))) throw new InvalidArgumentException('Activez au moins une crypto et choisissez une crypto active par défaut.');
    return $next;
}

function save_payment_settings($settings) {
    $file = payment_settings_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Impossible de créer le dossier de configuration.');
    $payload = "<?php http_response_code(404); exit; ?>\n" . json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = $dir . '/payment-' . bin2hex(random_bytes(8)) . '.local.php';
    if (file_put_contents($temporary, $payload, LOCK_EX) === false) throw new RuntimeException('Impossible d’écrire la configuration.');
    if (!rename($temporary, $file)) {
        unlink($temporary);
        throw new RuntimeException('Impossible d’enregistrer la configuration.');
    }
}

function public_payment_settings($settings) {
    $public = array_intersect_key($settings, array_flip(['enabled', 'mode', 'default_asset', 'invoice_minutes', 'rate_source', 'support_email', 'proof_required', 'instructions', 'processing_message']));
    $public['form_endpoint'] = ''; // References now stay in the local order store.
    $public['assets'] = [];
    foreach (payment_assets() as $symbol => $asset) {
        $value = $settings['assets'][$symbol];
        if (!$value['enabled']) continue;
        $public['assets'][$symbol] = [
            'name' => $asset['name'], 'network' => $asset['network'], 'rate_id' => $asset['rate_id'],
            'address' => $settings['mode'] === 'demo' ? $asset['demo_address'] : $value['address'],
            'manual_rate' => $value['manual_rate']
        ];
    }
    $public['revision'] = hash('sha256', json_encode($public));
    return $public;
}
