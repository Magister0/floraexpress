<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pricing_lib.php';

$pdo = db();
pricing_ensure_schema($pdo);

/**
 * Раскатывает набор стандартных праздничных правил.
 * Каждое правило — для текущего года.
 * Идемпотентно по name: если такое имя уже есть — обновляет период/коэффициент.
 */

$year = (int)date('Y');

$rules = [
    [
        'name'        => 'День святого Валентина · 14 февраля',
        'description' => 'Праздничная наценка за неделю до 14 февраля и в сам день',
        'starts_at'   => $year . '-02-08 00:00:00',
        'ends_at'     => $year . '-02-14 23:59:59',
        'multiplier'  => 1.40,
        'applies_to'  => 'all',
        'priority'    => 80,
        'badge_label' => 'Цена на 14 февраля',
    ],
    [
        'name'        => 'Международный женский день · 8 марта',
        'description' => 'Самый горячий период — наценка с 1 по 8 марта',
        'starts_at'   => $year . '-03-01 00:00:00',
        'ends_at'     => $year . '-03-08 23:59:59',
        'multiplier'  => 1.50,
        'applies_to'  => 'all',
        'priority'    => 100,
        'badge_label' => 'Цена на 8 марта',
    ],
    [
        'name'              => 'Розы · 8 марта (доп. наценка)',
        'description'       => 'Розы — отдельный спрос, накладывается поверх общей наценки',
        'starts_at'         => $year . '-03-01 00:00:00',
        'ends_at'           => $year . '-03-08 23:59:59',
        'multiplier'        => 1.15,
        'applies_to'        => 'category',
        'target_categories' => ['roses', 'розы', 'Розы'],
        'priority'          => 110,
        'badge_label'       => 'Праздничная цена на розы',
    ],
    [
        'name'        => 'День матери (последнее воскресенье ноября)',
        'description' => 'Праздничная наценка за 5 дней до Дня матери',
        'starts_at'   => $year . '-11-20 00:00:00',
        'ends_at'     => $year . '-11-30 23:59:59',
        'multiplier'  => 1.25,
        'applies_to'  => 'all',
        'priority'    => 70,
        'badge_label' => 'Цена ко Дню матери',
    ],
    [
        'name'        => '1 сентября · День знаний',
        'description' => 'Букеты для учителей — пик 28 августа – 1 сентября',
        'starts_at'   => $year . '-08-28 00:00:00',
        'ends_at'     => $year . '-09-01 23:59:59',
        'multiplier'  => 1.30,
        'applies_to'  => 'all',
        'priority'    => 75,
        'badge_label' => 'Цена на 1 сентября',
    ],
    [
        'name'        => 'Распродажа августа',
        'description' => 'Скидка −15% на весь каталог в межсезонье',
        'starts_at'   => $year . '-08-05 00:00:00',
        'ends_at'     => $year . '-08-20 23:59:59',
        'multiplier'  => 0.85,
        'applies_to'  => 'all',
        'priority'    => 20,
        'badge_label' => 'Скидка −15%',
    ],
    [
        'name'        => 'Демо: круглогодичный праздничный коэффициент',
        'description' => 'Всегда активно (для демонстрации работы системы). Можно выключить.',
        'starts_at'   => null,
        'ends_at'     => null,
        'multiplier'  => 1.10,
        'applies_to'  => 'all',
        'priority'    => 5,
        'badge_label' => 'Сезонный спрос',
        'active'      => false, // выключено — включить вручную для демо
    ],
];

$findByName = $pdo->prepare('SELECT id FROM pricing_rules WHERE name = ? LIMIT 1');

$created = 0;
$updated = 0;

foreach ($rules as $r) {
    $findByName->execute([$r['name']]);
    $existingId = (int)($findByName->fetchColumn() ?: 0);

    $payload = [
        'name'              => $r['name'],
        'description'       => $r['description'] ?? '',
        'starts_at'         => $r['starts_at'] ?? '',
        'ends_at'           => $r['ends_at']   ?? '',
        'multiplier'        => $r['multiplier'] ?? 1.0,
        'surcharge_fixed'   => $r['surcharge_fixed'] ?? 0,
        'applies_to'        => $r['applies_to'] ?? 'all',
        'target_categories' => $r['target_categories'] ?? [],
        'target_product_ids'=> $r['target_product_ids'] ?? [],
        'priority'          => $r['priority'] ?? 0,
        'active'            => array_key_exists('active', $r) ? (bool)$r['active'] : true,
        'badge_label'       => $r['badge_label'] ?? '',
    ];

    if ($existingId > 0) {
        pricing_rule_save($pdo, $payload, $existingId);
        $updated++;
    } else {
        pricing_rule_save($pdo, $payload, null);
        $created++;
    }
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM pricing_rules')->fetchColumn();
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Демо-правила цен добавлены</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f7f7f8;color:#111827;padding:40px;line-height:1.6}
    .card{max-width:780px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
    h1{margin:0 0 12px;font-size:32px}
    .ok{display:inline-block;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:10px 14px;border-radius:999px;font-weight:700;margin-bottom:16px}
    .actions a{display:inline-block;margin-right:12px;padding:12px 16px;border-radius:12px;text-decoration:none;border:1px solid #d1d5db;color:#111827}
    .actions a.primary{background:#175a37;color:#fff;border-color:#175a37}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <div class="card">
    <div class="ok">Готово</div>
    <h1>Правила динамического ценообразования загружены</h1>
    <p>Создано: <strong><?= $created ?></strong>. Обновлено: <strong><?= $updated ?></strong>.</p>
    <p>Всего правил в базе: <strong><?= $total ?></strong>.</p>
    <p>Управление — на странице <code>admin_pricing_rules.php</code>. Правила привязаны к текущему году <strong><?= $year ?></strong>.</p>
    <div class="actions">
      <a class="primary" href="admin_pricing_rules.php">Открыть админку правил</a>
      <a href="catalog.php">Посмотреть каталог</a>
    </div>
  </div>
</body>
</html>
