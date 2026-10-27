<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/order_presenter.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) { header('Location: auth.php?mode=login&return=' . urlencode('admin_orders.php')); exit; }
$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['admin','manager'], true)) { http_response_code(403); echo '403'; exit; }

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Сессия истекла, обновите страницу.');
        if (($_POST['action'] ?? '') === 'release_to_couriers') {
            require_once __DIR__ . '/telegram_notify.php';
            require_once __DIR__ . '/delivery_lib.php';
            $oid = (int)($_POST['order_id'] ?? 0);
            if ($oid <= 0) throw new RuntimeException('Заказ не найден.');
            $pdo->beginTransaction();
            $stD = $pdo->prepare('SELECT id FROM deliveries WHERE order_id = ? LIMIT 1 FOR UPDATE');
            $stD->execute([$oid]);
            $did = (int)($stD->fetchColumn() ?: 0);
            if ($did <= 0) {
                $stIns = $pdo->prepare("INSERT INTO deliveries (order_id, status, created_at) VALUES (?, 'pending', CURRENT_TIMESTAMP) RETURNING id");
                $stIns->execute([$oid]); $did = (int)$stIns->fetchColumn();
            }
            $relSql = column_exists($pdo, 'orders', 'released_to_courier') ? 'released_to_courier = TRUE, ' : '';
            $pdo->prepare("UPDATE orders SET {$relSql}status = CASE WHEN status = 'new' THEN 'assembling' ELSE status END WHERE id = ?")->execute([$oid]);
            if (function_exists('add_delivery_event')) add_delivery_event($pdo, $did, 'released', ['by' => 'admin']);
            $pdo->commit();
            $notify = notifyActiveCouriersAboutDelivery($pdo, $did);
            $cc = (int)($notify['couriers_count'] ?? 0);
            // Курьеры во ВКонтакте — уведомляем тем же действием, что и в Telegram.
            if (file_exists(__DIR__ . '/vk_notify.php')) {
                require_once __DIR__ . '/vk_notify.php';
                if (function_exists('notifyActiveCouriersAboutDeliveryVK')) {
                    try { notifyActiveCouriersAboutDeliveryVK($pdo, $did); } catch (Throwable $vkErr) { /* VK необязателен */ }
                }
            }
            $flash = "Заказ #{$oid} передан курьерам." . ($cc > 0 ? " Уведомлено курьеров на смене: {$cc}." : ' Нет курьеров на смене для уведомления.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = 'Ошибка: ' . $e->getMessage();
    }
}

$hasDeliveryMethod = column_exists($pdo, 'orders', 'delivery_method');
$hasCancelReason = column_exists($pdo, 'orders', 'cancel_reason');

// Колонка orders.admin_seen_at создаётся через migrate_schema.php.
// Безопасно: если её нет, апдейт просто упадёт в catch.
try {
    $pdo->exec("UPDATE orders SET admin_seen_at = CURRENT_TIMESTAMP WHERE status = 'new' AND admin_seen_at IS NULL");
} catch (Throwable $e) { /* колонки нет — игнорируем, прогоните migrate_schema.php */ }
$status = trim((string)($_GET['status'] ?? ''));
$method = trim((string)($_GET['method'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$args = [];
if ($status !== '') { $where[] = 'o.status = ?'; $args[] = $status; }
if ($q !== '') {
    if (ctype_digit($q)) { $where[] = 'o.id = ?'; $args[] = (int)$q; }
    else {
        $where[] = '(o.phone ILIKE ? OR o.address ILIKE ? OR COALESCE(o.comment,\'\') ILIKE ? OR COALESCE(o.recipient_name,\'\') ILIKE ? OR COALESCE(c.name,\'\') ILIKE ?)';
        for ($i=0;$i<5;$i++) $args[] = "%$q%";
    }
}
if ($method !== '') {
    if ($hasDeliveryMethod) { $where[] = 'COALESCE(o.delivery_method,\'delivery\') = ?'; $args[] = $method; }
    elseif ($method === 'pickup') { $where[] = "(o.address ILIKE 'Самовывоз%' OR COALESCE(o.comment,'') ILIKE '%Способ: Самовывоз%')"; }
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$extraSql = [];
$extraSql[] = $hasDeliveryMethod ? "COALESCE(o.delivery_method, 'delivery') AS delivery_method" : "NULL::text AS delivery_method";
$extraSql[] = $hasCancelReason ? "COALESCE(o.cancel_reason, '') AS cancel_reason" : "NULL::text AS cancel_reason";
$orders = [];
$queryError = null;
try {
    $st = $pdo->prepare("SELECT o.*, " . implode(', ', $extraSql) . ", d.status AS delivery_status, d.courier_id, c.name AS courier_name FROM orders o LEFT JOIN deliveries d ON d.order_id = o.id LEFT JOIN users c ON c.id = d.courier_id $whereSql ORDER BY o.id DESC LIMIT 200");
    $st->execute($args);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $queryError = $e->getMessage();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Админ — Заказы</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .box{background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:16px}
    .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    .orders{display:grid;gap:12px}
    .order-card{position:relative;display:grid;grid-template-columns:minmax(110px,.7fr) minmax(110px,.7fr) minmax(130px,.7fr) minmax(120px,.65fr) minmax(140px,.8fr) minmax(120px,.75fr) minmax(0,1.1fr) minmax(150px,auto);gap:12px;align-items:start;padding:16px;border:1px solid #eef2f7;border-radius:16px;background:#fcfdfd;transition:box-shadow .2s,border-color .2s}
    .order-card--new{background:#fffdf3;border-color:#fde68a;box-shadow:0 0 0 1px #fde68a,0 8px 22px rgba(245,158,11,.14)}
    .order-card--new::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:16px 0 0 16px;background:linear-gradient(180deg,#f59e0b,#f97316)}
    .badge-new{display:inline-flex;align-items:center;gap:6px;margin-top:2px;padding:3px 10px;border-radius:999px;background:#f59e0b;color:#fff;font-size:11px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;animation:newPulse 1.6s ease-in-out infinite}
    .badge-new::before{content:"";width:7px;height:7px;border-radius:50%;background:#fff}
    @keyframes newPulse{0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.5)}50%{box-shadow:0 0 0 7px rgba(245,158,11,0)}}
    .stack{display:grid;gap:6px}
    .label{font-size:12px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
    .value{font-size:15px;color:#0f172a;font-weight:600;line-height:1.5}
    .tag{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;border:1px solid #d7eadf;background:#f2faf6;color:#185a37;font-weight:700;font-size:12px}
    .tag--gray{border-color:#e5e7eb;background:#f8fafc;color:#475569}
    .tag--accent{border-color:#dbeafe;background:#eff6ff;color:#1d4ed8}
    .tag--danger{border-color:#fecdd3;background:#fff1f2;color:#be123c}
    .tag--ok{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}
    .actions{display:flex;flex-direction:column;gap:8px;align-items:stretch;justify-content:center;height:100%}
    .actions form{margin:0;display:block}
    .actions .btn{display:flex;width:100%;justify-content:center;text-align:center;white-space:nowrap}
    .reason{font-size:13px;color:#991b1b;background:#fff1f2;border:1px solid #fecdd3;border-radius:10px;padding:8px 10px}
    @media (max-width:1200px){.order-card{grid-template-columns:repeat(2,1fr)}.actions{justify-content:flex-start}}
    @media (max-width:700px){.order-card{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <h1 class="section__title">Заказы</h1>
    <?php if ($flash !== ''): ?>
      <div class="box" style="margin-bottom:16px;padding:12px 14px;border-radius:12px;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="box" style="margin-bottom:16px">
      <form class="filters" method="get" action="admin_orders.php">
        <div>
          <div class="mute">Статус заказа</div>
          <select class="input" name="status">
            <?php foreach ([''=>'Все','new'=>'Новый','paid'=>'Оплачен','assembling'=>'Собирается','on_delivery'=>'Передан в доставку','delivered'=>'Доставлен','canceled'=>'Отменён'] as $k=>$v): ?>
              <option value="<?= h($k) ?>" <?= $status===$k?'selected':'' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="mute">Способ</div>
          <select class="input" name="method">
            <option value="" <?= $method===''?'selected':'' ?>>Все</option>
            <option value="delivery" <?= $method==='delivery'?'selected':'' ?>>Доставка</option>
            <option value="pickup" <?= $method==='pickup'?'selected':'' ?>>Самовывоз</option>
          </select>
        </div>
        <div>
          <div class="mute">Поиск</div>
          <input class="input" name="q" value="<?= h($q) ?>" placeholder="ID / телефон / адрес / получатель / курьер">
        </div>
        <button class="btn" type="submit">Найти</button>
        <a class="btn btn--ghost" href="admin_orders.php">Сброс</a>
      </form>
    </div>

    <div class="orders">
      <?php if ($queryError): ?>
        <div class="box" style="background:#fff1f2;border-color:#fecdd3"><p style="margin:0;color:#9f1239;font-weight:700">Ошибка запроса: <?= htmlspecialchars($queryError) ?></p></div>
      <?php elseif (!$orders): ?>
        <div class="box"><p class="mute" style="margin:0">Заказы не найдены.</p></div>
      <?php else: ?>
        <?php
          foreach ($orders as $o):
            $methodValue = infer_delivery_method($o, $hasDeliveryMethod);
            $isNew = (string)($o['status'] ?? 'new') === 'new';
            // Заказ уже передан курьеру (назначен курьер или нажата «Передать курьерам»).
            $handedToCourier = $methodValue === 'delivery' && (!empty($o['courier_id']) || !empty($o['released_to_courier']));
            // Подсвечиваем только то, что ещё требует действия: новый и не отданный дальше.
            $needsAttention = $isNew && !$handedToCourier;
        ?>
          <article class="order-card<?= $needsAttention ? ' order-card--new' : '' ?>">
            <div class="stack">
              <div class="label">Заказ</div>
              <div class="value"><a href="admin_order_view.php?id=<?= (int)$o['id'] ?>" style="font-weight:900">#<?= (int)$o['id'] ?></a></div>
              <?php if ($needsAttention): ?><div><span class="badge-new">Новый</span></div><?php endif; ?>
              <div class="mute"><?= h($o['created_at'] ?? '') ?></div>
            </div>
            <div class="stack">
              <div class="label">Сумма</div>
              <div class="value" style="font-size:20px;font-weight:900"><?= number_format((float)($o['total'] ?? 0),0,',',' ') ?> ₽</div>
            </div>
            <div class="stack">
              <div class="label">Статус заказа</div>
              <div><span class="<?= h(order_status_class((string)($o['status'] ?? 'new'))) ?>"><?= h(order_status_label((string)($o['status'] ?? 'new'))) ?></span></div>
              <?php if (!empty($o['cancel_reason'])): ?><div class="reason">Причина: <?= h($o['cancel_reason']) ?></div><?php endif; ?>
            </div>
            <div class="stack">
              <div class="label">Способ</div>
              <div><span class="<?= h(delivery_method_badge_class($methodValue)) ?>"><?= h(delivery_method_label($methodValue)) ?></span></div>
            </div>
            <div class="stack">
              <div class="label"><?= $methodValue === 'pickup' ? 'Получение' : 'Доставка' ?></div>
              <?php if ($methodValue === 'pickup'): ?>
                <div><span class="tag tag--gray">Выдача в магазине</span></div>
                <div class="mute"><?= h(trim((string)($o['delivery_slot'] ?? '')) !== '' ? $o['delivery_slot'] : 'Время ещё не выбрано') ?></div>
              <?php else: ?>
                <div><span class="<?= h(delivery_status_class((string)($o['delivery_status'] ?? ''))) ?>"><?= h(delivery_status_label((string)($o['delivery_status'] ?? ''))) ?></span></div>
                <div class="mute"><?= !empty($o['courier_name']) ? ('Курьер: ' . h($o['courier_name'])) : 'Курьер не назначен' ?></div>
              <?php endif; ?>
            </div>
            <div class="stack">
              <div class="label">Телефон</div>
              <div class="value"><?= h($o['phone'] ?? '—') ?></div>
            </div>
            <div class="stack">
              <div class="label"><?= $methodValue === 'pickup' ? 'Адрес магазина' : 'Адрес' ?></div>
              <div class="value"><?= h($methodValue === 'pickup' ? ($o['address'] ?? 'Самовывоз из магазина FloraExpress') : short_address($o['address'] ?? '', $o['comment'] ?? '')) ?></div>
            </div>
            <div class="actions">
              <?php if ($methodValue === 'delivery' && empty($o['courier_id'])): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="release_to_couriers">
                  <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <button class="btn" type="submit"><?= !empty($o['released_to_courier']) ? 'Уведомить ещё раз' : 'Передать курьерам' ?></button>
                </form>
              <?php endif; ?>
              <a class="btn btn--ghost" href="admin_order_view.php?id=<?= (int)$o['id'] ?>">Открыть</a>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
