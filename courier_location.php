<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/courier_location_lib.php';
require_once __DIR__ . '/map_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();
courier_location_ensure_schema($pdo);

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    echo json_encode(['ok' => false, 'error' => 'no_token'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Находим заказ по tracking_token
$st = $pdo->prepare("
    SELECT o.id, o.status, o.address_lat, o.address_lon,
           d.id AS delivery_id, d.status AS delivery_status, d.courier_id,
           u.name AS courier_name
    FROM orders o
    LEFT JOIN deliveries d ON d.order_id = o.id
    LEFT JOIN users u ON u.id = d.courier_id
    WHERE o.tracking_token = ?
    LIMIT 1
");
$st->execute([$token]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deliveryStatus = (string)($order['delivery_status'] ?? '');
$courierId = (int)($order['courier_id'] ?? 0);

$response = [
    'ok'             => true,
    'order_id'       => (int)$order['id'],
    'order_status'   => (string)$order['status'],
    'delivery_status'=> $deliveryStatus,
    'show_map'       => false,
    'courier'        => null,
    'path'           => [],
    'destination'    => [
        'lat' => $order['address_lat'] !== null ? (float)$order['address_lat'] : null,
        'lon' => $order['address_lon'] !== null ? (float)$order['address_lon'] : null,
    ],
    'shop'           => [
        'lat' => defined('FLORA_SHOP_LAT') ? (float)FLORA_SHOP_LAT : 56.8389,
        'lon' => defined('FLORA_SHOP_LON') ? (float)FLORA_SHOP_LON : 60.6051,
        'address' => defined('FLORA_SHOP_ADDRESS') ? (string)FLORA_SHOP_ADDRESS : 'Магазин',
    ],
];

// Карта доступна только когда курьер уже взял заказ или едет
if (!in_array($deliveryStatus, ['picked','en_route'], true) || $courierId <= 0) {
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$response['show_map'] = true;

$loc = courier_location_get_current($pdo, $courierId);
if ($loc && !empty($loc['is_fresh'])) {
    $response['courier'] = [
        'name'        => (string)($order['courier_name'] ?? 'Курьер'),
        'lat'         => (float)$loc['lat'],
        'lon'         => (float)$loc['lon'],
        'heading'     => $loc['heading'] !== null ? (float)$loc['heading'] : null,
        'speed_kmh'   => $loc['speed_kmh'] !== null ? (float)$loc['speed_kmh'] : null,
        'age_seconds' => (int)$loc['age_seconds'],
        'source'      => (string)$loc['source'],
    ];

    // Подгружаем путь (для рисования линии на карте)
    $path = courier_location_track_recent($pdo, (int)$order['delivery_id'], 100);
    $response['path'] = array_map(fn($p) => ['lat' => (float)$p['lat'], 'lon' => (float)$p['lon']], $path);
} else {
    $response['courier'] = [
        'name'    => (string)($order['courier_name'] ?? 'Курьер'),
        'offline' => true,
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
