<?php
session_start();
require_once __DIR__ . '/orders.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
function oh($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$error = '';
$order = null;
try {
    $order = order_find($token);
    if ($order && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['order_csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['order_csrf'], $_POST['csrf'])) throw new InvalidArgumentException('Session expired. Reload this page before submitting again.');
        $order = order_submit_reference($token, $_POST);
        header('Location: order.php?token=' . $token, true, 303);
        exit;
    }
} catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
catch (Throwable $exception) { $error = 'Order information is temporarily unavailable. Keep your private link and try again shortly.'; }
if (empty($_SESSION['order_csrf'])) $_SESSION['order_csrf'] = bin2hex(random_bytes(32));
if (!$order) http_response_code($error ? 503 : 404);
$status = $order ? order_status($order) : '';
$labels = ['awaiting' => 'Awaiting your payment', 'expired' => 'Payment window expired', 'review' => 'Reference received — awaiting review', 'paid' => 'Payment verified', 'delivered' => 'Delivery completed', 'cancelled' => 'Order cancelled'];
$messages = ['awaiting' => 'Your order is saved. Send the exact amount on the network below, then submit your transaction ID.', 'expired' => 'Do not send a new payment for this order. Already sent it? Submit your reference below so we can review it. Otherwise, return to checkout for a new order.', 'review' => 'Your reference is saved. We will check the transaction in our wallet. No additional payment is needed while you wait.', 'paid' => 'Your payment has been checked manually. Your order is awaiting delivery.', 'delivered' => 'The shop has marked your order as delivered. Check the delivery instructions below or contact support if you have not received it.', 'cancelled' => 'Do not send payment for this order. If you already paid, contact support with your order number.'];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title><?= $order ? oh($order['id']) : 'Order not found' ?> — BitShop</title><link rel="stylesheet" href="site-ui.css"><link rel="stylesheet" href="order.css"><script src="vendor/qrcode.min.js" defer></script><script src="order.js" defer></script></head>
<body class="storefront tracking-page">
<header class="site-header"><nav class="site-nav" aria-label="Main navigation"><a class="site-brand" href="home.html">BitShop</a><a class="ui-button" href="home.html">Products</a></nav></header>
<main class="tracking-wrap">
<?php if ($error): ?><p class="order-alert" role="alert"><?= oh($error) ?></p><?php endif; ?>
<?php if (!$order): ?>
<section class="tracking-card"><h1><?= $error ? 'Please try again shortly' : 'Order not found' ?></h1><p>Use the complete private tracking link saved when you placed your order.</p><a class="ui-button" href="payment.html">Return to checkout</a></section>
<?php else: ?>
<?php if ($order['mode'] === 'demo'): ?><p class="demo-banner"><strong>Demo order</strong> — Test data only. Do not send any crypto. No payment or delivery takes place.</p><?php endif; ?>
<div class="tracking-heading"><div><p class="eyebrow">YOUR ORDER · <?= oh(gmdate('d M Y, H:i', $order['created_at'])) ?> UTC</p><h1><?= oh($order['id']) ?></h1><p><?= oh($order['product_name']) ?> · <?= oh($order['qty']) ?> × <?= oh(number_format((float) $order['total_usd'] / $order['qty'], 2)) ?> USD · Total <?= oh($order['total_usd']) ?> USD</p></div><span class="status-badge" data-status="<?= oh($status) ?>"><?= oh($labels[$status]) ?></span></div>
<ol class="order-steps" aria-label="Order progress"><li class="complete">1. Order saved</li><li class="<?= in_array($status, ['review', 'paid', 'delivered']) ? 'complete' : '' ?>">2. Manual review</li><li class="<?= in_array($status, ['paid', 'delivered']) ? 'complete' : '' ?>">3. Payment verified</li><li class="<?= $status === 'delivered' ? 'complete' : '' ?>">4. Delivery</li></ol>
<div class="tracking-grid">
<section class="tracking-card"><h2><?= oh($labels[$status]) ?></h2><p><?= oh($messages[$status]) ?></p>
<?php if ($order['mode'] !== 'demo'): ?><p class="review-delay"><?= oh($order['processing_message']) ?></p><?php endif; ?>
<?php if ($order['public_note']): ?><div class="merchant-note"><strong>Message from the shop</strong><p><?= nl2br(oh($order['public_note'])) ?></p></div><?php endif; ?>
<?php if ($status === 'awaiting'): ?>
<div id="payment-instructions" data-expires="<?= $order['expires_at'] ?>">
<p class="network-label">Use only <?= oh($order['network']) ?></p>
<?php if ($order['mode'] !== 'demo'): ?><div id="order-qr" data-asset="<?= oh($order['asset']) ?>"></div><a id="open-wallet" class="ui-button" hidden>Open compatible wallet</a><?php endif; ?>
<label for="order-amount">Exact amount (network fees are additional)</label><div class="copy-row"><input readonly id="order-amount" value="<?= oh($order['amount']) ?>"><button class="ui-button" data-copy="order-amount">Copy <?= oh($order['asset']) ?></button></div>
<label for="order-address">Receiving address</label><div class="copy-row"><input readonly id="order-address" value="<?= oh($order['address']) ?>"><button class="ui-button" data-copy="order-address">Copy address</button></div>
<p class="muted">Amount valid until <?= oh(gmdate('H:i', $order['expires_at'])) ?> UTC · <span id="order-countdown"></span></p>
<?php if ($order['instructions']): ?><p><?= nl2br(oh($order['instructions'])) ?></p><?php endif; ?>
<p>Check the address, network and amount in your wallet before confirming. Keep this page open to submit your reference.</p>
</div><p id="expired-message" class="order-alert" hidden>The payment window has expired. Do not send a new payment. If you already paid, submit your reference below.</p>
<?php endif; ?>
<?php if ($order['txid']): ?><div class="reference-box"><strong>Saved transaction ID</strong><p><?= oh($order['txid']) ?></p></div><?php endif; ?>
<?php if (in_array($status, ['awaiting', 'expired', 'review'])): ?>
<form method="post" class="reference-form"><input type="hidden" name="csrf" value="<?= oh($_SESSION['order_csrf']) ?>"><h2><?= $status === 'review' ? 'Correct your reference' : 'Already sent your payment?' ?></h2><p>Submitting a reference requests a manual check; it does not confirm payment.</p><label for="txid">Transaction ID</label><input id="txid" name="txid" required maxlength="66" autocomplete="off" value="<?= oh($_POST['txid'] ?? $order['txid']) ?>" placeholder="<?= $order['mode'] === 'demo' ? 'A fictitious reference for this demo' : 'Copy the full transaction ID from your wallet' ?>"><label for="reference-note">Message for the shop (optional)</label><textarea id="reference-note" name="note" maxlength="1000" rows="3"><?= oh($_POST['note'] ?? $order['customer_note']) ?></textarea><button class="ui-button ui-button--primary" type="submit"><?= $order['mode'] === 'demo' ? 'Save demo reference' : 'Submit for manual review' ?></button></form>
<?php endif; ?>
</section>
<aside class="tracking-card order-recap"><h2>Order summary</h2><h3><?= oh($order['product_name']) ?></h3><dl><dt>Quantity</dt><dd><?= oh($order['qty']) ?></dd><dt>Total</dt><dd><?= oh($order['total_usd']) ?> USD</dd><dt>Currency</dt><dd><?= oh($order['asset']) ?></dd><dt>Network</dt><dd><?= oh($order['network']) ?></dd><dt>Contact email</dt><dd><?= oh($order['email']) ?></dd></dl><p class="muted">Delivery follows manual payment verification. No automatic email is sent by this checkout.</p><hr><h2>Keep your tracking link</h2><p>This private link gives access to your order details. Save it and keep it to yourself.</p><button class="ui-button" id="copy-tracking">Copy private tracking link</button><a class="ui-button" href="order.php?token=<?= oh($token) ?>">Refresh status</a><p id="copy-feedback" role="status" aria-live="polite"></p>
<?php if ($order['support_email']): ?><a class="support-link" href="mailto:<?= oh($order['support_email']) ?>?subject=<?= rawurlencode('Order ' . $order['id']) ?>">Need help? Contact support</a><p class="muted">Include <?= oh($order['id']) ?> in your message.</p><?php endif; ?>
<a href="payment.html">Back to checkout</a></aside>
</div>
<?php endif; ?>
</main><footer class="site-footer"><span>BitShop</span><div><a href="support.html">Support</a><a href="privacy.html">Privacy</a><a href="terms.html">Terms</a></div></footer>
</body></html>
