<?php
session_start();
require_once __DIR__ . '/orders.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (empty($_SESSION['checkout_csrf'])) $_SESSION['checkout_csrf'] = bin2hex(random_bytes(32));
        echo json_encode(['csrf' => $_SESSION['checkout_csrf']]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    if (empty($_SESSION['checkout_csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['checkout_csrf'], $_POST['csrf'])) { http_response_code(403); echo json_encode(['error' => 'Session expired. Refresh and try again.']); exit; }
    $key = payment_text($_POST['request_id'] ?? '', 64, 'Request');
    if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Invalid request.');
    $known = $_SESSION['order_requests'][$key] ?? null;
    if ($known && order_find($known)) { echo json_encode(['url' => 'order.php?token=' . $known]); exit; }
    $recent = array_filter($_SESSION['order_times'] ?? [], function ($at) { return $at > time() - 3600; });
    if (count($recent) >= 20) { http_response_code(429); echo json_encode(['error' => 'Too many orders. Please try again later.']); exit; }
    $token = $known ?? bin2hex(random_bytes(32));
    $_SESSION['order_requests'][$key] = $token;
    order_create($_POST, $token);
    $recent[] = time();
    $_SESSION['order_times'] = array_values($recent);
    echo json_encode(['url' => 'order.php?token=' . $token]);
} catch (InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(503); echo json_encode(['error' => 'Could not prepare your order. Please retry later; do not send payment yet.']);
}
