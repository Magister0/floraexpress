<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('admin_panel.php'));
    exit;
}
$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['admin','manager'], true)) {
    http_response_code(403);
    echo '403';
    exit;
}

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rub = fn($n) => number_format((float)$n, 0, ',', ' ') . ' ₽';

/* Быстрые метрики */
$quick = [];
try {
    try {
        $hasAdminSeen = (bool)$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='orders' AND column_name='admin_seen_at' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) { $hasAdminSeen = false; }
    $quick['orders_new']        = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'new'" . ($hasAdminSeen ? " AND admin_seen_at IS NULL" : ""))->fetchColumn();
    $quick['orders_today']      = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE created_at >= DATE_TRUNC('day', NOW()) AND status <> 'canceled'")->fetchColumn();
    $quick['revenue_today']     = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE created_at >= DATE_TRUNC('day', NOW()) AND status <> 'canceled'")->fetchColumn();
    $quick['deliveries_active'] = (int)$pdo->query("SELECT COUNT(*) FROM deliveries WHERE status IN ('assigned','picked','en_route')")->fetchColumn();
    $quick['products_total']    = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE COALESCE(is_active,1)=1")->fetchColumn();
} catch (Throwable $e) {}

$aiCoverage = null;
try {
    $totalActive = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE COALESCE(is_active,1)=1")->fetchColumn();
    $tagged = 0;
    if ((bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='product_ai_metadata' LIMIT 1")->fetchColumn()) {
        $tagged = (int)$pdo->query("SELECT COUNT(*) FROM product_ai_metadata")->fetchColumn();
    }
    $aiCoverage = ['total' => $totalActive, 'tagged' => $tagged, 'pct' => $totalActive > 0 ? round($tagged / $totalActive * 100) : 0];
} catch (Throwable $e) {}

$aiChats = 0;
try {
    if ((bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='ai_chats' LIMIT 1")->fetchColumn()) {
        $aiChats = (int)$pdo->query("SELECT COUNT(*) FROM ai_chats")->fetchColumn();
    }
} catch (Throwable $e) {}

$hasUsers = file_exists(__DIR__ . '/admin.php');

$sections = [
    [
        'title' => 'Каталог',
        'color' => '#175a37',
        'items' => [
            ['icon' => '📦', 'href' => 'products.php',         'title' => 'Товары',      'sub' => ($quick['products_total'] ?? 0) . ' активных'],
            ['icon' => '🏷️', 'href' => 'categories.php',       'title' => 'Категории',   'sub' => 'разделы каталога'],
            ['icon' => '✨', 'href' => 'extra_services.php',   'title' => 'Доп. услуги', 'sub' => 'открытки, шары'],
        ],
    ],
    [
        'title' => 'Заказы и доставка',
        'color' => '#1d4ed8',
        'items' => [
            ['icon' => '🛒', 'href' => 'admin_orders.php',          'title' => 'Заказы',     'sub' => ($quick['orders_new'] ?? 0) > 0 ? '🔔 ' . $quick['orders_new'] . ' новых' : 'все заказы', 'highlight' => !empty($quick['orders_new'])],
            ['icon' => '🚴', 'href' => 'admin_delivery_zones.php', 'title' => 'Доставка',    'sub' => ($quick['deliveries_active'] ?? 0) > 0 ? $quick['deliveries_active'] . ' в пути' : 'зоны и тарифы'],
            ['icon' => '📊', 'href' => 'admin_reports.php',        'title' => 'Отчётность', 'sub' => 'KPI, графики, выгрузка'],
        ],
    ],
    [
        'title' => 'Цены и маркетинг',
        'color' => '#c2410c',
        'items' => [
            ['icon' => '💰', 'href' => 'admin_pricing_rules.php', 'title' => 'Дин. цены', 'sub' => 'праздничные коэф.'],
            ['icon' => '🎟️', 'href' => 'promocodes.php',          'title' => 'Промокоды', 'sub' => 'скидки и купоны'],
        ],
    ],
    [
        'title' => 'AI-консультант',
        'color' => '#a855f7',
        'items' => [
            ['icon' => '🤖', 'href' => 'admin_ai_catalog.php',  'title' => 'AI-теги',  'sub' => $aiCoverage ? ('разметка ' . $aiCoverage['pct'] . '%') : 'разметка товаров'],
            ['icon' => '💬', 'href' => 'admin_ai_chats.php',    'title' => 'Диалоги',  'sub' => $aiChats > 0 ? ($aiChats . ' с клиентами') : 'история чатов'],
            ['icon' => '🔬', 'href' => 'ai_analyze_catalog.php','title' => 'Анализ',   'sub' => 'прогон каталога'],
        ],
    ],
    [
        'title' => 'Прочее',
        'color' => '#6b7280',
        'items' => array_values(array_filter([
            $hasUsers ? ['icon' => '👥', 'href' => 'admin.php', 'title' => 'Пользователи', 'sub' => 'роли и доступ'] : null,
        ])),
    ],
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Админ-панель — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Те же поля, что и у шапки/футера */
    .ap-wrap{
      width:min(1200px, calc(100% - 32px));
      margin:14px auto 24px;
      padding:0;
      display:grid;
      gap:12px;
      box-sizing:border-box;
    }

    /* Верхняя строка: заголовок + 4 KPI в одну линию (по ширине) */
    .ap-top{
      display:grid;gap:10px;
      grid-template-columns: minmax(240px, 1.4fr) repeat(4, minmax(0,1fr));
    }
    @media (max-width:1200px){.ap-top{grid-template-columns:repeat(2,1fr)}}
    @media (max-width:560px){.ap-top{grid-template-columns:1fr}}

    .ap-hero{
      background:linear-gradient(135deg,#175a37,#0f6b45);color:#fff;
      border-radius:14px;padding:14px 18px;
      display:flex;flex-direction:column;justify-content:center;gap:4px;
      min-height:78px;
    }
    .ap-hero h1{margin:0;font-size:19px;font-weight:800;letter-spacing:.2px}
    .ap-hero p{margin:0;font-size:12.5px;opacity:.88}

    .ap-kpi{
      background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:10px 14px;
      display:flex;flex-direction:column;justify-content:center;gap:3px;
      min-height:78px;
    }
    .ap-kpi__label{font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;font-weight:700}
    .ap-kpi__value{font-size:22px;font-weight:900;color:#0f172a;line-height:1.05}
    .ap-kpi__sub{font-size:11px;color:#94a3b8;line-height:1.1}
    .ap-kpi--red  {background:linear-gradient(135deg,#fff1f2,#fef2f2);border-color:#fecaca}
    .ap-kpi--red  .ap-kpi__value{color:#b91c1c}
    .ap-kpi--blue {background:linear-gradient(135deg,#eff6ff,#f0f7ff);border-color:#bfdbfe}
    .ap-kpi--blue .ap-kpi__value{color:#1d4ed8}
    .ap-kpi--green .ap-kpi__value{color:#175a37}

    /* Секции в 3 колонки: 5 разделов раскладываются 3 + 2 */
    .ap-sections{
      display:grid;gap:12px;
      grid-template-columns:repeat(3, minmax(0,1fr));
      align-items:start;
    }
    @media (max-width:900px){.ap-sections{grid-template-columns:repeat(2,1fr)}}
    @media (max-width:560px){.ap-sections{grid-template-columns:1fr}}

    .ap-section{
      background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:12px 14px;
      display:flex;flex-direction:column;gap:8px;
    }
    .ap-section__head{display:flex;align-items:center;gap:8px}
    .ap-section__dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
    .ap-section__title{font-size:11.5px;font-weight:800;color:#374151;letter-spacing:.4px;text-transform:uppercase}

    .ap-tiles{display:flex;flex-direction:column;gap:6px}

    .ap-tile{
      display:flex;align-items:center;gap:12px;
      background:#fafbfa;border:1px solid #eef2f7;border-radius:10px;
      padding:9px 12px;
      text-decoration:none;color:inherit;
      transition:.15s ease;
      min-height:58px;
      position:relative;
    }
    .ap-tile:hover{background:#fff;border-color:#175a37;transform:translateX(2px);box-shadow:0 4px 12px rgba(0,0,0,.05)}
    .ap-tile__icon{
      flex-shrink:0;width:34px;height:34px;border-radius:10px;
      display:grid;place-items:center;font-size:17px;
      background:#fff;border:1px solid #e7ece8;
    }
    .ap-tile__txt{flex:1;min-width:0}
    .ap-tile__name{font-weight:700;font-size:13.5px;color:#0f172a;line-height:1.15}
    .ap-tile__sub{font-size:11.5px;color:#6b7280;line-height:1.25;margin-top:2px}
    .ap-tile__arrow{color:#cbd5e1;font-size:18px;flex-shrink:0;transition:.15s}
    .ap-tile:hover .ap-tile__arrow{color:#175a37;transform:translateX(2px)}
    .ap-tile--highlight{background:#fffbeb;border-color:#fde68a}
    .ap-tile--highlight .ap-tile__icon{background:#fef3c7;border-color:#fbbf24}
    .ap-tile--highlight .ap-tile__sub{color:#92400e;font-weight:600}
  </style>
</head>
<body>
<?php include __DIR__.'/header.php'; ?>

<div class="ap-wrap">

  <!-- Верхняя полоса -->
  <div class="ap-top">
    <div class="ap-hero">
      <h1>⚙ Админ-панель</h1>
      <p>Все разделы магазина в одном месте</p>
    </div>
    <div class="ap-kpi <?= !empty($quick['orders_new']) ? 'ap-kpi--red' : '' ?>">
      <div class="ap-kpi__label">Новых заказов</div>
      <div class="ap-kpi__value"><?= (int)($quick['orders_new'] ?? 0) ?></div>
      <div class="ap-kpi__sub">ждут обработки</div>
    </div>
    <div class="ap-kpi">
      <div class="ap-kpi__label">Сегодня</div>
      <div class="ap-kpi__value"><?= (int)($quick['orders_today'] ?? 0) ?></div>
      <div class="ap-kpi__sub">заказов оплачено</div>
    </div>
    <div class="ap-kpi ap-kpi--blue">
      <div class="ap-kpi__label">Выручка</div>
      <div class="ap-kpi__value"><?= $h($rub($quick['revenue_today'] ?? 0)) ?></div>
      <div class="ap-kpi__sub">за день</div>
    </div>
    <div class="ap-kpi ap-kpi--green">
      <div class="ap-kpi__label">В пути</div>
      <div class="ap-kpi__value"><?= (int)($quick['deliveries_active'] ?? 0) ?></div>
      <div class="ap-kpi__sub">у курьеров сейчас</div>
    </div>
  </div>

  <!-- Все секции в одну строку -->
  <div class="ap-sections">
    <?php foreach ($sections as $section): if (empty($section['items'])) continue; ?>
      <div class="ap-section">
        <div class="ap-section__head">
          <span class="ap-section__dot" style="background:<?= $h($section['color']) ?>"></span>
          <span class="ap-section__title"><?= $h($section['title']) ?></span>
        </div>
        <div class="ap-tiles">
          <?php foreach ($section['items'] as $item): if (!$item) continue; ?>
            <a class="ap-tile<?= !empty($item['highlight']) ? ' ap-tile--highlight' : '' ?>" href="<?= $h($item['href']) ?>">
              <span class="ap-tile__icon"><?= $item['icon'] ?></span>
              <span class="ap-tile__txt">
                <div class="ap-tile__name"><?= $h($item['title']) ?></div>
                <div class="ap-tile__sub"><?= $h($item['sub']) ?></div>
              </span>
              <span class="ap-tile__arrow">›</span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

</div>

<?php include __DIR__.'/footer.php'; ?>
</body>
</html>
