<?php
$apiKey = getenv('COINMARKETCAP_API_KEY');
header('Content-Type: application/json; charset=utf-8');
if (!$apiKey) {
    http_response_code(503);
    echo json_encode(['error' => 'CoinMarketCap is not configured.']);
    exit;
}
$url = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency/quotes/latest?symbol=XMR&convert=EUR';

$headers = [
    'Accepts: application/json',
    'X-CMC_PRO_API_KEY: ' . $apiKey
];

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($curl);
curl_close($curl);

echo $response;
?>
