<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) { header('Location: auth.php?mode=login&return=' . urlencode('admin_reports.php')); exit; }
$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['admin','manager'], true)) { http_response_code(403); echo '403'; exit; }

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rub = fn($n) => number_format((float)$n, 0, ',', ' ') . ' ₽';

/* ============================================================ */
/* Фильтр периода                                               */
/* ============================================================ */
$period = $_GET['period'] ?? 'all';
$validPeriods = ['today', 'week', 'month', 'year', 'custom', 'all'];
if (!in_array($period, $validPeriods, true)) $period = 'all';

$filterFrom = null;
$filterTo   = null;

if ($period === 'today') {
    $filterFrom = $filterTo = date('Y-m-d');
} elseif ($period === 'week') {
    $filterFrom = date('Y-m-d', strtotime('monday this week'));
    $filterTo   = date('Y-m-d');
} elseif ($period === 'month') {
    $filterFrom = date('Y-m-01');
    $filterTo   = date('Y-m-d');
} elseif ($period === 'year') {
    $filterFrom = date('Y-01-01');
    $filterTo   = date('Y-m-d');
} elseif ($period === 'custom') {
    $rawFrom = (string)($_GET['date_from'] ?? '');
    $rawTo   = (string)($_GET['date_to']   ?? '');
    $filterFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : date('Y-m-01');
    $filterTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)   ? $rawTo   : date('Y-m-d');
}

$periodLabels = [
    'all'    => 'За всё время',
    'today'  => 'Сегодня',
    'week'   => 'Эта неделя',
    'month'  => 'Этот месяц',
    'year'   => 'Этот год',
    'custom' => 'Произвольный период',
];
$periodLabel = $periodLabels[$period] ?? 'За всё время';
if ($period === 'custom' && $filterFrom && $filterTo) {
    $periodLabel = $h($filterFrom) . ' — ' . $h($filterTo);
}

// WHERE-условия для дат
$dateWhere      = $filterFrom ? "AND DATE(created_at) BETWEEN '$filterFrom' AND '$filterTo'" : '';
$dateWhereAlias = $filterFrom ? "AND DATE(o.created_at) BETWEEN '$filterFrom' AND '$filterTo'" : '';
$dateWhereD     = $filterFrom ? "AND DATE(d.assigned_at) BETWEEN '$filterFrom' AND '$filterTo'" : '';

$hasDeliveryMethod   = column_exists($pdo, 'orders', 'delivery_method');
$hasDeliveryCalcMeta = column_exists($pdo, 'orders', 'delivery_calc_meta');
$methodExpr = $hasDeliveryMethod
    ? "COALESCE(o.delivery_method,'delivery')"
    : "CASE WHEN (o.address ILIKE 'Самовывоз%' OR COALESCE(o.comment,'') ILIKE '%Способ: Самовывоз%') THEN 'pickup' ELSE 'delivery' END";

/* ============================================================ */
/* Сводные метрики                                              */
/* ============================================================ */
$summary = $pdo->query("
    SELECT
        COUNT(*)::int AS orders_total,
        COUNT(*) FILTER (WHERE status = 'new')::int AS orders_new,
        COUNT(*) FILTER (WHERE status = 'canceled')::int AS orders_cancelled,
        COUNT(*) FILTER (WHERE status <> 'canceled')::int AS orders_paid,
        COALESCE(SUM(total) FILTER (WHERE status <> 'canceled'), 0)::float AS revenue_total,
        COALESCE(AVG(total) FILTER (WHERE status <> 'canceled'), 0)::float AS avg_order
    FROM orders
    WHERE 1=1 $dateWhere
")->fetch(PDO::FETCH_ASSOC) ?: [];

/* ============================================================ */
/* График: продажи по дням                                     */
/* ============================================================ */
if ($filterFrom) {
    $daysQuery = "
        SELECT DATE(created_at) AS day,
               COUNT(*) FILTER (WHERE status <> 'canceled')::int AS orders_cnt,
               COALESCE(SUM(total) FILTER (WHERE status <> 'canceled'), 0)::float AS revenue
        FROM orders
        WHERE DATE(created_at) BETWEEN '$filterFrom' AND '$filterTo'
        GROUP BY DATE(created_at) ORDER BY day ASC
    ";
} else {
    $daysQuery = "
        SELECT DATE(created_at) AS day,
               COUNT(*) FILTER (WHERE status <> 'canceled')::int AS orders_cnt,
               COALESCE(SUM(total) FILTER (WHERE status <> 'canceled'), 0)::float AS revenue
        FROM orders
        WHERE created_at >= NOW() - INTERVAL '30 days'
        GROUP BY DATE(created_at) ORDER BY day ASC
    ";
}
$days = $pdo->query($daysQuery)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================================================ */
/* Распределение по статусам                                   */
/* ============================================================ */
$statusBreakdown = $pdo->query("
    SELECT status, COUNT(*)::int AS cnt
    FROM orders
    WHERE 1=1 $dateWhere
    GROUP BY status
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================================================ */
/* Способы доставки                                            */
/* ============================================================ */
$methods = $pdo->query("
    SELECT $methodExpr AS method,
           COUNT(*)::int AS cnt,
           COALESCE(SUM(o.total) FILTER (WHERE o.status <> 'canceled'), 0)::float AS revenue
    FROM orders o
    WHERE 1=1 $dateWhereAlias
    GROUP BY 1 ORDER BY 1
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================================================ */
/* Зоны доставки (из delivery_calc_meta)                       */
/* ============================================================ */
$zoneStats = [];
if ($hasDeliveryCalcMeta) {
    $zoneRows = $pdo->query("
        SELECT delivery_calc_meta
        FROM orders o
        WHERE delivery_calc_meta IS NOT NULL AND status <> 'canceled' $dateWhereAlias
        ORDER BY id DESC LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($zoneRows as $row) {
        $meta = json_decode((string)($row['delivery_calc_meta'] ?? ''), true);
        if (!is_array($meta) || empty($meta['zone']['name'])) continue;
        $zone = (string)$meta['zone']['name'];
        $zoneStats[$zone] = ($zoneStats[$zone] ?? 0) + 1;
    }
    arsort($zoneStats);
}

/* ============================================================ */
/* Топ-10 товаров                                              */
/* ============================================================ */
$topProducts = $pdo->query("
    SELECT p.id, p.name, p.image,
           SUM(oi.qty)::int AS qty,
           COALESCE(SUM(oi.qty * oi.price), 0)::float AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE o.status <> 'canceled' $dateWhereAlias
    GROUP BY p.id, p.name, p.image
    ORDER BY revenue DESC, qty DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================================================ */
/* Топ курьеров                                                */
/* ============================================================ */
$topCouriers = $pdo->query("
    SELECT u.id, u.name,
           COUNT(d.id)::int AS deliveries,
           COUNT(d.id) FILTER (WHERE d.status = 'delivered')::int AS delivered,
           COALESCE(SUM(d.courier_fee) FILTER (WHERE d.status = 'delivered'), 0)::float AS earned
    FROM users u
    JOIN deliveries d ON d.courier_id = u.id
    WHERE u.role = 'courier' $dateWhereD
    GROUP BY u.id, u.name
    HAVING COUNT(d.id) > 0
    ORDER BY delivered DESC, deliveries DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ============================================================ */
/* AI-консультант                                              */
/* ============================================================ */
$aiStats = null;
$hasAiChats = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='ai_chats' LIMIT 1")->fetchColumn();
if ($hasAiChats) {
    $dateWhereAi = $filterFrom ? "WHERE DATE(c.created_at) BETWEEN '$filterFrom' AND '$filterTo'" : '';
    $aiStats = $pdo->query("
        SELECT
            COUNT(DISTINCT c.id)::int AS chats,
            COUNT(m.id)::int AS messages,
            COUNT(m.id) FILTER (WHERE m.role = 'user')::int AS user_msgs,
            COUNT(m.id) FILTER (WHERE m.role = 'assistant')::int AS bot_msgs,
            COUNT(m.id) FILTER (WHERE m.fallback = TRUE)::int AS fallback_msgs,
            COALESCE(SUM(m.tokens_used), 0)::int AS tokens
        FROM ai_chats c
        LEFT JOIN ai_messages m ON m.chat_id = c.id
        $dateWhereAi
    ")->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ============================================================ */
/* Для графика                                                 */
/* ============================================================ */
$statusLabelsMap = [
    'new'         => 'Новые',
    'paid'        => 'Оплачены',
    'assembling'  => 'Собираются',
    'on_delivery' => 'В доставке',
    'delivered'   => 'Доставлены',
    'canceled'    => 'Отменены',
];
$statusLabelsArr = [];
$statusCountsArr = [];
$statusColors = ['#94a3b8', '#3b82f6', '#f59e0b', '#a855f7', '#10b981', '#ef4444'];
foreach ($statusBreakdown as $r) {
    $statusLabelsArr[] = $statusLabelsMap[(string)$r['status']] ?? (string)$r['status'];
    $statusCountsArr[] = (int)$r['cnt'];
}

$exportParams = ['period' => $period];
if ($filterFrom) $exportParams['date_from'] = $filterFrom;
if ($filterTo)   $exportParams['date_to']   = $filterTo;
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Отчётность и аналитика — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    .rep-wrap{width:min(1200px, calc(100% - 32px));margin:24px auto;display:grid;gap:18px}
    .rep-card{background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:20px;box-shadow:var(--shadow-1)}
    .rep-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .rep-actions{display:flex;gap:10px;flex-wrap:wrap}
    .period-filter__label{color:#6b7280;font-size:13px;font-weight:700;margin-bottom:10px}
    .period-btns{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .period-btns .btn--ghost.is-active{background:#eef6f1;border-color:#175a37;color:#175a37}
    .period-custom{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid #f0f4f8}
    .period-custom label{font-size:13px;color:#6b7280;font-weight:600}
    .period-custom .input{padding:7px 12px;border-radius:10px;border:1px solid #e5e7eb;font-size:13px;min-width:140px}
    .period-label{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#6b7280;margin-left:8px}
    .period-label strong{color:#111827}

    .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
    .stat{padding:16px;background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);position:relative;overflow:hidden}
    .stat::after{content:'';position:absolute;top:-30px;right:-30px;width:100px;height:100px;border-radius:50%;background:linear-gradient(135deg,rgba(23,90,55,.08),rgba(15,107,69,.02))}
    .stat__label{font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;font-weight:700;position:relative;z-index:1}
    .stat__value{font-size:28px;font-weight:900;color:#0f172a;margin-top:6px;line-height:1.1;position:relative;z-index:1}
    .stat__sub{font-size:12px;color:#94a3b8;margin-top:3px;position:relative;z-index:1}
    .stat--green .stat__value{color:#175a37}
    .stat--blue  .stat__value{color:#1d4ed8}
    .stat--red   .stat__value{color:#b91c1c}
    .stat--orange .stat__value{color:#c2410c}

    .chart-wrap{position:relative;height:320px}
    .chart-wrap--small{height:240px}
    .rep-half{display:grid;grid-template-columns:1.4fr 1fr;gap:18px}
    @media (max-width:900px){.rep-half{grid-template-columns:1fr}}

    table.t{width:100%;border-collapse:collapse;font-size:13.5px}
    table.t th,table.t td{padding:9px 10px;border-bottom:1px solid #f0f4f1;text-align:left;vertical-align:middle}
    table.t th{font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;background:#f9fbfa;font-weight:700}
    .pic{width:42px;height:42px;border-radius:10px;background:#f3f4f6;overflow:hidden;flex-shrink:0}
    .pic img{width:100%;height:100%;object-fit:cover}
    .cell-prod{display:flex;align-items:center;gap:10px}
    .tag{display:inline-flex;padding:5px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;color:#475569;font-weight:700;font-size:12px}
  </style>
</head>
<body>
<?php include __DIR__.'/header.php'; ?>

<div class="rep-wrap">

  <div class="rep-card">
    <div class="rep-top">
      <div>
        <h1 class="section__title" style="margin:0">Отчётность и аналитика</h1>
        <p class="section__sub" style="margin:6px 0 0">Полная картина продаж, динамика, топ-товары, курьеры и AI.</p>
      </div>
      <div class="rep-actions">
        <a class="btn btn--ghost" href="admin_reports_export.php?<?= http_build_query($exportParams) ?>">📊 Выгрузить в Excel</a>
      </div>
    </div>
  </div>

  <!-- Period filter -->
  <div class="rep-card">
    <div class="period-filter__label">Период</div>
    <div class="period-btns">
      <?php foreach (['all' => 'Всё время', 'today' => 'Сегодня', 'week' => 'Неделя', 'month' => 'Месяц', 'year' => 'Год', 'custom' => 'Произвольный'] as $p => $label): ?>
        <a class="btn btn--ghost btn--sm<?= $period === $p ? ' is-active' : '' ?>"
           href="admin_reports.php?period=<?= $p ?><?= ($p === 'custom' && $filterFrom) ? '&date_from='.urlencode($filterFrom).'&date_to='.urlencode($filterTo) : '' ?>">
          <?= $h($label) ?>
        </a>
      <?php endforeach; ?>

      <?php if ($period !== 'all'): ?>
        <span class="period-label">Показано: <strong><?= $periodLabel ?></strong></span>
      <?php endif; ?>
    </div>

    <?php if ($period === 'custom'): ?>
      <form class="period-custom" method="get">
        <input type="hidden" name="period" value="custom">
        <label for="date_from">С</label>
        <input class="input" type="date" id="date_from" name="date_from"
               value="<?= $h($filterFrom ?? date('Y-m-01')) ?>" max="<?= date('Y-m-d') ?>">
        <label for="date_to">По</label>
        <input class="input" type="date" id="date_to" name="date_to"
               value="<?= $h($filterTo ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
        <button class="btn btn--sm" type="submit">Применить</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- KPI -->
  <div class="stats">
    <div class="stat stat--green">
      <div class="stat__label">Выручка</div>
      <div class="stat__value"><?= $h($rub($summary['revenue_total'] ?? 0)) ?></div>
      <div class="stat__sub"><?= (int)($summary['orders_paid'] ?? 0) ?> оплаченных заказов</div>
    </div>
    <div class="stat stat--blue">
      <div class="stat__label">Заказов всего</div>
      <div class="stat__value"><?= number_format((int)($summary['orders_total'] ?? 0), 0, '.', ' ') ?></div>
      <div class="stat__sub"><?= (int)($summary['orders_cancelled'] ?? 0) ?> отменены</div>
    </div>
    <div class="stat">
      <div class="stat__label">Средний чек</div>
      <div class="stat__value"><?= $h($rub($summary['avg_order'] ?? 0)) ?></div>
      <div class="stat__sub">по оплаченным</div>
    </div>
    <div class="stat stat--red">
      <div class="stat__label">Новых</div>
      <div class="stat__value"><?= (int)($summary['orders_new'] ?? 0) ?></div>
      <div class="stat__sub">ждут обработки</div>
    </div>
    <?php if (($summary['orders_total'] ?? 0) > 0): ?>
    <div class="stat stat--orange">
      <div class="stat__label">% отмен</div>
      <div class="stat__value"><?= round((int)$summary['orders_cancelled'] / max(1,(int)$summary['orders_total']) * 100) ?>%</div>
      <div class="stat__sub">от всех заказов</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sales chart -->
  <?php if ($days): ?>
  <div class="rep-card">
    <h2 style="margin:0 0 16px;font-size:17px">
      <?= $filterFrom ? 'Продажи за выбранный период' : 'Продажи за последние 30 дней' ?>
    </h2>
    <div class="chart-wrap"><canvas id="salesChart"></canvas></div>
  </div>
  <?php endif; ?>

  <!-- Status breakdown + Delivery methods -->
  <div class="rep-half">
    <div class="rep-card">
      <h2 style="margin:0 0 16px;font-size:17px">Статусы заказов</h2>
      <?php if (!$statusBreakdown): ?>
        <p class="mute">Нет данных за период.</p>
      <?php else: ?>
        <div class="chart-wrap chart-wrap--small"><canvas id="statusChart"></canvas></div>
      <?php endif; ?>
    </div>
    <div class="rep-card">
      <h2 style="margin:0 0 16px;font-size:17px">Способ доставки</h2>
      <?php if (!$methods): ?>
        <p class="mute">Нет данных.</p>
      <?php else: ?>
        <table class="t">
          <thead><tr><th>Способ</th><th>Заказов</th><th>Выручка</th></tr></thead>
          <tbody>
          <?php foreach ($methods as $m): ?>
            <tr>
              <td><span class="tag"><?= $h(($m['method'] ?? 'delivery') === 'pickup' ? '🛍️ Самовывоз' : '🚴 Доставка') ?></span></td>
              <td><?= (int)$m['cnt'] ?></td>
              <td style="font-weight:700"><?= $h($rub($m['revenue'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($zoneStats): ?>
          <h3 style="margin:18px 0 8px;font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px">Топ зон доставки</h3>
          <table class="t">
            <thead><tr><th>Зона</th><th>Заказов</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($zoneStats, 0, 5, true) as $name => $cnt): ?>
              <tr>
                <td><?= $h($name) ?></td>
                <td><?= (int)$cnt ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Top products -->
  <div class="rep-card">
    <h2 style="margin:0 0 16px;font-size:17px">🏆 Топ-10 товаров по выручке</h2>
    <?php if (!$topProducts): ?>
      <p class="mute">Продаж за период пока нет.</p>
    <?php else: ?>
      <table class="t">
        <thead><tr><th></th><th>Товар</th><th>Продано шт.</th><th>Выручка</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $i => $p): ?>
          <tr>
            <td style="font-weight:900;color:#175a37;width:36px"><?= $i + 1 ?></td>
            <td>
              <div class="cell-prod">
                <div class="pic"><?php if (!empty($p['image'])): ?><img src="<?= $h((string)$p['image']) ?>" alt="" onerror="this.style.display='none'"><?php endif; ?></div>
                <div>
                  <div style="font-weight:700"><?= $h((string)$p['name']) ?></div>
                  <div class="mute" style="font-size:11.5px">ID #<?= (int)$p['id'] ?></div>
                </div>
              </div>
            </td>
            <td style="font-weight:700"><?= (int)$p['qty'] ?></td>
            <td style="font-weight:800;color:#175a37"><?= $h($rub($p['revenue'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Couriers -->
  <?php if ($topCouriers): ?>
  <div class="rep-card">
    <h2 style="margin:0 0 16px;font-size:17px">🚴 Курьеры по эффективности</h2>
    <table class="t">
      <thead><tr><th>Имя</th><th>Доставок</th><th>Завершено</th><th>% успеха</th><th>Заработано</th></tr></thead>
      <tbody>
      <?php foreach ($topCouriers as $c):
        $rate = (int)$c['deliveries'] > 0 ? round((int)$c['delivered'] / (int)$c['deliveries'] * 100) : 0;
      ?>
        <tr>
          <td style="font-weight:700"><?= $h((string)($c['name'] ?? '—')) ?></td>
          <td><?= (int)$c['deliveries'] ?></td>
          <td><?= (int)$c['delivered'] ?></td>
          <td><?= $rate ?>%</td>
          <td style="font-weight:700;color:#175a37"><?= $h($rub($c['earned'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- AI consultant -->
  <?php if ($aiStats && (int)$aiStats['chats'] > 0): ?>
  <div class="rep-card">
    <h2 style="margin:0 0 16px;font-size:17px">🤖 AI-консультант</h2>
    <div class="stats">
      <div class="stat stat--blue">
        <div class="stat__label">Диалогов</div>
        <div class="stat__value"><?= (int)$aiStats['chats'] ?></div>
      </div>
      <div class="stat">
        <div class="stat__label">Сообщений</div>
        <div class="stat__value"><?= (int)$aiStats['messages'] ?></div>
        <div class="stat__sub"><?= (int)$aiStats['user_msgs'] ?> от клиентов · <?= (int)$aiStats['bot_msgs'] ?> от AI</div>
      </div>
      <div class="stat stat--orange">
        <div class="stat__label">Через правила (fallback)</div>
        <div class="stat__value"><?= (int)$aiStats['fallback_msgs'] ?></div>
        <div class="stat__sub">
          <?php
            $bot = (int)$aiStats['bot_msgs'];
            $fb = (int)$aiStats['fallback_msgs'];
            echo $bot > 0 ? round($fb / $bot * 100) . '%' : '0%';
            echo ' от ответов AI';
          ?>
        </div>
      </div>
      <div class="stat">
        <div class="stat__label">Токенов</div>
        <div class="stat__value"><?= number_format((int)$aiStats['tokens'], 0, '.', ' ') ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__.'/footer.php'; ?>

<script>
(function(){
  const days = <?= json_encode($days, JSON_UNESCAPED_UNICODE) ?>;
  const statusLabels = <?= json_encode($statusLabelsArr, JSON_UNESCAPED_UNICODE) ?>;
  const statusCounts = <?= json_encode($statusCountsArr) ?>;
  const statusColors = <?= json_encode($statusColors) ?>;

  // Sales chart
  const salesEl = document.getElementById('salesChart');
  if (salesEl && days.length) {
    new Chart(salesEl, {
      type: 'bar',
      data: {
        labels: days.map(d => (d.day || '').slice(5)),
        datasets: [
          {
            type: 'line',
            label: 'Выручка, ₽',
            data: days.map(d => parseFloat(d.revenue) || 0),
            borderColor: '#175a37',
            backgroundColor: 'rgba(23,90,55,.12)',
            borderWidth: 2,
            tension: 0.35,
            yAxisID: 'y',
            fill: true,
            pointRadius: 3,
            pointBackgroundColor: '#175a37',
          },
          {
            type: 'bar',
            label: 'Заказы',
            data: days.map(d => parseInt(d.orders_cnt) || 0),
            backgroundColor: 'rgba(59,130,246,.55)',
            borderRadius: 6,
            yAxisID: 'y1',
            barPercentage: 0.7,
          },
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y:  { beginAtZero: true, position: 'left',  ticks: { callback: v => v.toLocaleString('ru-RU') + ' ₽' } },
          y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } }
        },
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 14 } },
          tooltip: {
            callbacks: {
              label: ctx => ctx.dataset.yAxisID === 'y'
                ? 'Выручка: ' + Math.round(ctx.parsed.y).toLocaleString('ru-RU') + ' ₽'
                : 'Заказов: ' + ctx.parsed.y
            }
          }
        }
      }
    });
  }

  // Status doughnut
  const stEl = document.getElementById('statusChart');
  if (stEl && statusLabels.length) {
    new Chart(stEl, {
      type: 'doughnut',
      data: {
        labels: statusLabels,
        datasets: [{
          data: statusCounts,
          backgroundColor: statusColors.slice(0, statusLabels.length),
          borderWidth: 2,
          borderColor: '#fff',
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 10 } } }
      }
    });
  }
})();
</script>
</body>
</html>
