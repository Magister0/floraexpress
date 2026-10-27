<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/delivery_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();

$lat   = isset($_GET['lat']) ? (float)$_GET['lat'] : 0.0;
$lon   = isset($_GET['lon']) ? (float)$_GET['lon'] : 0.0;
$total = isset($_GET['total']) ? (float)$_GET['total'] : 0.0;
$slot  = trim((string)($_GET['slot'] ?? ''));
$fragile = !empty($_GET['fragile']);

if ($lat == 0.0 && $lon == 0.0) {
    echo json_encode(['ok' => false, 'error' => 'no_coords'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Парсим слот: "сегодня, 22:00–23:00" / "2024-03-08 14:00" / просто "22:00"
$when = new DateTimeImmutable('now');
$urgent = false;

if ($slot !== '') {
    // Извлекаем дату/время в формате YYYY-MM-DD HH:MM
    if (preg_match('~(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})~', $slot, $m)) {
        try { $when = new DateTimeImmutable($m[1] . ' ' . $m[2]); } catch (Throwable $e) {}
    } elseif (preg_match('~(\d{1,2})[:.](\d{2})~', $slot, $m)) {
        // Только время — относим к сегодня/завтра
        $h = (int)$m[1]; $mn = (int)$m[2];
        $candidate = (new DateTimeImmutable('today'))->setTime($h, $mn);
        if ($candidate < new DateTimeImmutable('now')) {
            $candidate = $candidate->modify('+1 day');
        }
        $when = $candidate;
    }

    // Срочная доставка: слот в ближайшие 2 часа
    $diffMin = ($when->getTimestamp() - time()) / 60;
    if ($diffMin > 0 && $diffMin <= 120) {
        $urgent = true;
    }
}

$flags = [
    'urgent'  => $urgent,
    'fragile' => $fragile,
];

$result = delivery_calc_quote($pdo, $lat, $lon, $when, $total, $flags);
$result['when'] = $when->format('Y-m-d H:i');
$result['flags'] = $flags;

echo json_encode($result, JSON_UNESCAPED_UNICODE);
