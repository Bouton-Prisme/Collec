<?php
require_once __DIR__ . '/payment-settings.php';

// A PHP guard protects the private store even when config/ is under the web root.
function orders_store($change = null) {
    $dir = __DIR__ . '/config';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Order storage unavailable.');
    $lock = fopen($dir . '/orders-lock.local.php', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Order storage unavailable.');
    try {
        $file = $dir . '/orders.local.php';
        $guard = "<?php http_response_code(404); exit; ?>\n";
        $orders = [];
        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw === false || strpos($raw, $guard) !== 0) throw new RuntimeException('Order storage unavailable.');
            $orders = json_decode(substr($raw, strlen($guard)), true);
            if (!is_array($orders)) throw new RuntimeException('Order storage unavailable.');
        }
        if ($change === null) return $orders;
        $result = $change($orders);
        $tmp = $dir . '/orders-' . bin2hex(random_bytes(8)) . '.local.php';
        $payload = $guard . json_encode($orders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $payload, LOCK_EX) !== strlen($payload)) throw new RuntimeException('Could not save order. Please retry.');
        if (!rename($tmp, $file)) { unlink($tmp); throw new RuntimeException('Could not save order. Please retry.'); }
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function order_status($order) {
    return $order['status'] === 'awaiting' && time() >= $order['expires_at'] ? 'expired' : $order['status'];
}

function order_find($token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
    foreach (orders_store() as $order) if (hash_equals($order['token_hash'], hash('sha256', $token))) return $order;
    return null;
}

function order_rate($settings, $symbol) {
    if ($settings['mode'] === 'demo') return payment_assets()[$symbol]['demo_rate'];
    if ($settings['rate_source'] === 'manual') return $settings['assets'][$symbol]['manual_rate'];
    $id = payment_assets()[$symbol]['rate_id'];
    $context = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => false], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $raw = @file_get_contents('https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode($id) . '&vs_currencies=usd', false, $context);
    $rate = $raw === false ? null : (json_decode($raw, true)[$id]['usd'] ?? null);
    if (!is_numeric($rate) || !is_finite((float) $rate) || $rate <= 0 || $rate > 1000000000) throw new RuntimeException('Exchange rate unavailable. No order was created. Please try again later.');
    return (float) $rate;
}

// Integer long division avoids floating-point tails (notably ETH's 18 decimals).
function order_amount($total, $rate, $precision) {
    $numerator = (int) str_replace('.', '', number_format($total, 2, '.', '')) * 1000000;
    $denominator = (int) str_replace('.', '', number_format($rate, 8, '.', ''));
    if ($denominator < 1) throw new InvalidArgumentException('Exchange rate is below the supported minimum.');
    $whole = intdiv($numerator, $denominator);
    $remainder = $numerator % $denominator;
    $fraction = '';
    for ($i = 0; $i < $precision; $i++) {
        $remainder *= 10;
        $fraction .= (string) intdiv($remainder, $denominator);
        $remainder %= $denominator;
    }
    // Round up to the smallest unit, so the requested payment covers the price.
    if ($remainder) {
        for ($i = $precision - 1; $i >= 0; $i--) {
            if ($fraction[$i] !== '9') { $fraction[$i] = (string) ((int) $fraction[$i] + 1); break; }
            $fraction[$i] = '0';
        }
        if ($i < 0) $whole++;
    }
    return rtrim(rtrim($whole . '.' . $fraction, '0'), '.');
}

function order_create($input, $token) {
    $settings = load_payment_settings();
    if (!$settings['enabled']) throw new InvalidArgumentException('Payments are currently paused.');
    $symbol = payment_text($input['asset'] ?? '', 3, 'Currency');
    if (empty($settings['assets'][$symbol]['enabled'])) throw new InvalidArgumentException('Choose an available currency.');
    $email = payment_text($input['email'] ?? '', 254, 'Email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    $qty = filter_var($input['qty'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $id = payment_text($input['product_id'] ?? '', 20, 'Product');
    $catalogue = json_decode(file_get_contents(__DIR__ . '/products.json'), true);
    $product = $catalogue['products'][$id] ?? null;
    if (!$qty || !$product || (isset($product['active']) && !$product['active']) || !empty($product['payment_url'])) throw new InvalidArgumentException('This selection is unavailable. Return to the catalogue.');
    $total = round((float) $product['usd'] * $qty, 2);
    if (!is_finite($total) || $total <= 0 || $total > 1000000000) throw new InvalidArgumentException('This product cannot be purchased here.');
    $rate = order_rate($settings, $symbol);
    $precision = ['BTC' => 8, 'ETH' => 18, 'XMR' => 12][$symbol];
    $amount = order_amount($total, $rate, $precision);
    if ((float) $amount <= 0) throw new InvalidArgumentException('The amount is below the supported minimum.');
    $asset = public_payment_settings($settings)['assets'][$symbol];
    $order = [
        'id' => 'BS-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5))),
        'token_hash' => hash('sha256', $token), 'mode' => $settings['mode'],
        'product_id' => $id, 'product_name' => $product['name'], 'qty' => $qty,
        'total_usd' => number_format($total, 2, '.', ''), 'email' => $email,
        'asset' => $symbol, 'network' => $asset['network'], 'address' => $asset['address'],
        'amount' => $amount, 'rate' => $rate, 'rate_source' => $settings['mode'] === 'demo' ? 'demo' : $settings['rate_source'],
        'created_at' => time(), 'expires_at' => time() + $settings['invoice_minutes'] * 60,
        'status' => 'awaiting', 'txid' => '', 'customer_note' => '', 'public_note' => '',
        'processing_message' => $settings['processing_message'], 'instructions' => $settings['instructions'],
        'support_email' => $settings['support_email'],
        'history' => [['at' => time(), 'status' => 'awaiting', 'actor' => 'customer']]
    ];
    return orders_store(function (&$orders) use ($order) {
        foreach ($orders as $existing) if ($existing['token_hash'] === $order['token_hash']) return $existing;
        $orders[$order['id']] = $order;
        return $order;
    });
}

function order_submit_reference($token, $input) {
    $txid = payment_text($input['txid'] ?? '', 66, 'Transaction ID');
    $note = payment_text($input['note'] ?? '', 1000, 'Message');
    return orders_store(function (&$orders) use ($token, $txid, $note) {
        foreach ($orders as &$order) {
            if (!hash_equals($order['token_hash'], hash('sha256', $token))) continue;
            if (!in_array($order['status'], ['awaiting', 'review'], true)) throw new InvalidArgumentException('This order can no longer receive a payment reference. Contact support.');
            if ($order['mode'] !== 'demo' && !preg_match($order['asset'] === 'ETH' ? '/^0x[a-fA-F0-9]{64}$/D' : '/^[a-fA-F0-9]{64}$/D', $txid)) throw new InvalidArgumentException('Enter the complete transaction ID from your wallet.');
            if ($txid === '') throw new InvalidArgumentException('Enter your transaction ID.');
            foreach ($orders as $other) {
                if ($other['id'] !== $order['id'] && $other['mode'] === $order['mode'] && $other['asset'] === $order['asset'] && strtolower($other['txid']) === strtolower($txid)) throw new InvalidArgumentException('This reference is already attached to another order. Contact support.');
            }
            if ($order['status'] === 'review' && $order['txid'] === $txid && $order['customer_note'] === $note) return $order;
            $order['txid'] = $txid;
            $order['customer_note'] = $note;
            $order['status'] = 'review';
            $order['history'][] = ['at' => time(), 'status' => 'review', 'actor' => 'customer', 'txid' => $txid];
            return $order;
        }
        throw new InvalidArgumentException('Order not found.');
    });
}

function order_admin_update($input) {
    return orders_store(function (&$orders) use ($input) {
        $id = payment_text($input['order_id'] ?? '', 40, 'Order');
        if (!isset($orders[$id])) throw new InvalidArgumentException('Commande introuvable.');
        $order = &$orders[$id];
        $status = payment_text($input['order_status'] ?? '', 20, 'Statut');
        $allowed = ['awaiting' => ['awaiting', 'review', 'paid', 'cancelled'], 'review' => ['review', 'paid', 'cancelled'], 'paid' => ['paid', 'delivered'], 'delivered' => ['delivered'], 'cancelled' => ['cancelled', 'review']];
        if (!in_array($status, $allowed[$order['status']], true)) throw new InvalidArgumentException('Transition de statut non autorisée.');
        if (in_array($status, ['paid', 'delivered'], true) && $status !== $order['status'] && empty($input['verified'])) throw new InvalidArgumentException('Confirmez le contrôle du paiement ou la livraison effective.');
        $order['public_note'] = payment_text($input['public_note'] ?? '', 1000, 'Message client');
        $order['status'] = $status;
        $order['history'][] = ['at' => time(), 'status' => $status, 'actor' => 'admin'];
        return $order;
    });
}
