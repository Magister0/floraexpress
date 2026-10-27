<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/map_config.php';
require_once __DIR__ . '/delivery_lib.php';

$pdo = db();
delivery_ensure_schema($pdo);

// Центр Екатеринбурга — координаты площади 1905 года
$EKB_LAT = 56.8385;
$EKB_LON = 60.6053;

$zones = [
    [
        'name'             => 'Центр Екатеринбурга',
        'description'      => 'В пределах 4 км от площади 1905 года',
        'center_lat'       => $EKB_LAT,
        'center_lon'       => $EKB_LON,
        'radius_km'        => 4.0,
        'base_fee'         => 250.0,
        'free_distance_km' => 2.0,
        'per_km_fee'       => 50.0,
        'min_fee'          => 250.0,
        'free_from'        => 5000.0,
        'eta_speed_kmh'    => 20.0,
        'eta_prep_min'     => 25,
        'priority'         => 100,
    ],
    [
        'name'             => 'Город Екатеринбург',
        'description'      => 'Спальные районы, в пределах 15 км от центра',
        'center_lat'       => $EKB_LAT,
        'center_lon'       => $EKB_LON,
        'radius_km'        => 15.0,
        'base_fee'         => 350.0,
        'free_distance_km' => 4.0,
        'per_km_fee'       => 40.0,
        'min_fee'          => 350.0,
        'free_from'        => 7000.0,
        'eta_speed_kmh'    => 25.0,
        'eta_prep_min'     => 30,
        'priority'         => 50,
    ],
    [
        'name'             => 'Пригород',
        'description'      => 'Среднеуральск, Берёзовский, Верхняя Пышма и т.п. (до 35 км)',
        'center_lat'       => $EKB_LAT,
        'center_lon'       => $EKB_LON,
        'radius_km'        => 35.0,
        'base_fee'         => 700.0,
        'free_distance_km' => 15.0,
        'per_km_fee'       => 35.0,
        'min_fee'          => 700.0,
        'free_from'        => null,
        'eta_speed_kmh'    => 35.0,
        'eta_prep_min'     => 45,
        'priority'         => 10,
    ],
];

$modifiers = [
    [
        'name'       => 'Ночной тариф (22:00–06:00)',
        'kind'       => 'night',
        'multiplier' => 1.50,
        'surcharge_fixed' => 0,
        'time_from'  => '22:00',
        'time_to'    => '06:00',
        'priority'   => 80,
    ],
    [
        'name'       => 'Выходные (сб, вс)',
        'kind'       => 'weekend',
        'multiplier' => 1.00,
        'surcharge_fixed' => 100.0,
        'weekdays'   => [6, 7],
        'priority'   => 50,
    ],
    [
        'name'       => 'Срочная доставка (под 2 часа)',
        'kind'       => 'urgent',
        'multiplier' => 1.80,
        'surcharge_fixed' => 0,
        'priority'   => 90,
    ],
    [
        'name'       => 'Хрупкий заказ (стекло/баллоны/композиции)',
        'kind'       => 'fragile',
        'multiplier' => 1.00,
        'surcharge_fixed' => 200.0,
        'priority'   => 40,
    ],
    [
        'name'       => '8 марта · повышенный тариф',
        'kind'       => 'holiday',
        'multiplier' => 1.30,
        'surcharge_fixed' => 0,
        'date_from'  => date('Y') . '-03-01',
        'date_to'    => date('Y') . '-03-08',
        'priority'   => 70,
    ],
    [
        'name'       => '14 февраля · повышенный тариф',
        'kind'       => 'holiday',
        'multiplier' => 1.25,
        'surcharge_fixed' => 0,
        'date_from'  => date('Y') . '-02-12',
        'date_to'    => date('Y') . '-02-14',
        'priority'   => 65,
    ],
];

$findZone = $pdo->prepare('SELECT id FROM delivery_zones WHERE name = ? LIMIT 1');
$findMod  = $pdo->prepare('SELECT id FROM delivery_tariff_modifiers WHERE name = ? LIMIT 1');

$createdZ = $updatedZ = 0;
foreach ($zones as $z) {
    $findZone->execute([$z['name']]);
    $id = (int)($findZone->fetchColumn() ?: 0);
    $z['active'] = true;
    if ($id > 0) {
        delivery_zone_save($pdo, $z, $id);
        $updatedZ++;
    } else {
        delivery_zone_save($pdo, $z, null);
        $createdZ++;
    }
}

$createdM = $updatedM = 0;
foreach ($modifiers as $m) {
    $findMod->execute([$m['name']]);
    $id = (int)($findMod->fetchColumn() ?: 0);
    $m['active'] = true;
    if ($id > 0) {
        delivery_modifier_save($pdo, $m, $id);
        $updatedM++;
    } else {
        delivery_modifier_save($pdo, $m, null);
        $createdM++;
    }
}

$totalZ = (int)$pdo->query('SELECT COUNT(*) FROM delivery_zones')->fetchColumn();
$totalM = (int)$pdo->query('SELECT COUNT(*) FROM delivery_tariff_modifiers')->fetchColumn();
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Зоны доставки добавлены</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f7f7f8;color:#111827;padding:40px;line-height:1.6}
    .card{max-width:780px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
    h1{margin:0 0 12px;font-size:30px}
    .ok{display:inline-block;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:10px 14px;border-radius:999px;font-weight:700;margin-bottom:16px}
    .actions a{display:inline-block;margin-right:12px;padding:12px 16px;border-radius:12px;text-decoration:none;border:1px solid #d1d5db;color:#111827}
    .actions a.primary{background:#175a37;color:#fff;border-color:#175a37}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <div class="card">
    <div class="ok">Готово</div>
    <h1>Зоны и тарифы доставки загружены</h1>
    <p>Зоны: создано <strong><?= $createdZ ?></strong>, обновлено <strong><?= $updatedZ ?></strong>. Всего: <strong><?= $totalZ ?></strong>.</p>
    <p>Модификаторы тарифа: создано <strong><?= $createdM ?></strong>, обновлено <strong><?= $updatedM ?></strong>. Всего: <strong><?= $totalM ?></strong>.</p>
    <p>Управление — <code>admin_delivery_zones.php</code>. Центр зон — площадь 1905 года, Екатеринбург.</p>
    <div class="actions">
      <a class="primary" href="admin_delivery_zones.php">Открыть админку доставки</a>
      <a href="checkout.php">Проверить расчёт в чекауте</a>
    </div>
  </div>
</body>
</html>
