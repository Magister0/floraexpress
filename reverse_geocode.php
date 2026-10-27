<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/map_config.php';

header('Content-Type: application/json; charset=utf-8');

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0.0;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : 0.0;

if ($lat == 0.0 && $lon == 0.0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_coords'], JSON_UNESCAPED_UNICODE);
    exit;
}

$key = defined('FLORA_YANDEX_MAPS_API_KEY') ? trim((string)FLORA_YANDEX_MAPS_API_KEY) : '';
if ($key === '') {
    echo json_encode(['ok' => false, 'error' => 'empty_key'], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = 'https://geocode-maps.yandex.ru/v1/?apikey=' . rawurlencode($key)
    . '&geocode=' . rawurlencode($lon . ',' . $lat)
    . '&lang=ru_RU&format=json&results=1';

$body = null;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'FloraExpress/1.0',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
} else {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "User-Agent: FloraExpress/1.0\r\n",
        ]
    ]);
    $body = @file_get_contents($url, false, $ctx);
}

if (!$body) {
    echo json_encode(['ok' => false, 'error' => 'request_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($body, true);
$feature = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'] ?? null;
if (!$feature) {
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$meta = $feature['metaDataProperty']['GeocoderMetaData'] ?? [];
$address = trim((string)($meta['text'] ?? ''));
if ($address === '') {
    $name = trim((string)($feature['name'] ?? ''));
    $desc = trim((string)($feature['description'] ?? ''));
    $address = trim($desc . ($desc !== '' && $name !== '' ? ', ' : '') . $name);
}

echo json_encode(['ok' => $address !== '', 'address' => $address], JSON_UNESCAPED_UNICODE);
