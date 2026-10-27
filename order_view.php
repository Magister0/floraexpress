<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/order_presenter.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('order_view.php' . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : '')));
    exit;
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: account_orders.php');
    exit;
}

$hasDeliveryMethod = column_exists($pdo, 'orders', 'delivery_method');
$hasCancelReason = column_exists($pdo, 'orders', 'cancel_reason');
$extraSql = [];
$extraSql[] = $hasDeliveryMethod ? "COALESCE(o.delivery_method, 'delivery') AS delivery_method" : "NULL::text AS delivery_method";
$extraSql[] = $hasCancelReason ? "COALESCE(o.cancel_reason, '') AS cancel_reason" : "NULL::text AS cancel_reason";

$st = $pdo->prepare("\n    SELECT\n        o.*,\n        " . implode(', ', $extraSql) . ",\n        d.id AS delivery_id,\n        d.status AS delivery_status,\n        d.courier_id,\n        d.eta,\n        d.assigned_at,\n        d.delivered_at,\n        u.name AS courier_name\n    FROM orders o\n    LEFT JOIN deliveries d ON d.order_id = o.id\n    LEFT JOIN users u ON u.id = d.courier_id\n    WHERE o.id = ? AND o.user_id = ?\n    LIMIT 1\n");
$st->execute([$orderId, (int)$me['id']]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo 'Заказ не найден';
    exit;
}

$stI = $pdo->prepare("\n    SELECT oi.qty, oi.price, p.name, p.image\n    FROM order_items oi\n    LEFT JOIN products p ON p.id = oi.product_id\n    WHERE oi.order_id = ?\n    ORDER BY oi.id ASC\n");
$stI->execute([$orderId]);
$items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

$events = [];
if (!empty($order['delivery_id'])) {
    $stE = $pdo->prepare("\n        SELECT event, meta_json, created_at\n        FROM delivery_events\n        WHERE delivery_id = ?\n        ORDER BY id DESC\n        LIMIT 20\n    ");
    $stE->execute([(int)$order['delivery_id']]);
    $events = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$method = infer_delivery_method($order, $hasDeliveryMethod);
$commentData = split_comment_parts($order['comment'] ?? '');
$placeholder = placeholder_image();
$recipient = trim((string)($order['recipient_name'] ?? ''));
$slot = trim((string)($order['delivery_slot'] ?? ''));
$entrance = detail_value($commentData['details'], 'Подъезд');
$floor = detail_value($commentData['details'], 'Этаж');
$domofon = detail_value($commentData['details'], 'Домофон');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Заказ #<?= (int)$orderId ?> — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .lk-wrap{max-width:900px;margin:0 auto;display:grid;gap:16px}
    .lk-back{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:14px;text-decoration:none}
    .lk-back:hover{color:#0f172a}
    .lk-tabs{display:flex;gap:4px;background:#fff;border:1px solid #e8edf2;border-radius:14px;padding:6px;box-shadow:0 2px 8px rgba(15,23,42,.05);flex-wrap:wrap}
    .lk-tab{padding:8px 16px;border-radius:10px;color:#6b7280;text-decoration:none;font-size:14px;font-weight:600;transition:.15s ease}
    .lk-tab:hover{background:#f3f6f4;color:#0f172a}
    .lk-tab.active{background:var(--pistachio-soft);color:#0f172a;border:1px solid #cfe9d9}
    .box{background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:20px}
    .grid{display:grid;grid-template-columns:minmax(300px,.95fr) minmax(0,1.05fr);gap:16px}
    .hero{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .stack{display:grid;gap:14px}
    .mini{display:grid;grid-template-columns:140px 1fr;gap:14px;padding:11px 0;border-bottom:1px solid #eef2f7}
    .mini:last-child{border-bottom:none;padding-bottom:0}
    .label{font-size:13px;color:#6b7280;font-weight:700}
    .value{font-size:15px;color:#0f172a;font-weight:600;line-height:1.6;word-break:break-word}
    .tag{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;border:1px solid #d7eadf;background:#f2faf6;color:#185a37;font-weight:700;font-size:12px}
    .tag--gray{border-color:#e5e7eb;background:#f8fafc;color:#475569}
    .tag--accent{border-color:#dbeafe;background:#eff6ff;color:#1d4ed8}
    .tag--danger{border-color:#fecdd3;background:#fff1f2;color:#be123c}
    .tag--ok{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}
    .item{display:grid;grid-template-columns:72px 1fr auto;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid #eef2f7}
    .item:last-child{border-bottom:none}
    .item-photo{width:72px;height:72px;border-radius:16px;overflow:hidden;background:#f3f4f6;border:1px solid #edf1ef;flex-shrink:0}
    .item-photo img{width:100%;height:100%;object-fit:cover;display:block}
    .item-title{font-size:16px;font-weight:800;margin:0 0 4px}
    .item-meta{font-size:14px;color:#6b7280}
    .item-price{font-size:18px;font-weight:900;white-space:nowrap}
    .chips{display:flex;gap:8px;flex-wrap:wrap}
    .chip{padding:8px 12px;border-radius:999px;background:#f8faf9;border:1px solid #e3ebe6;font-size:13px;color:#294252;font-weight:700}
    .reason{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:14px;padding:12px 14px}
    .total{display:flex;justify-content:space-between;align-items:center;padding-top:16px;margin-top:4px;border-top:1px solid #e7ece8;font-size:18px;font-weight:900}

    /* ── Timeline ── */
    .tl{display:grid;gap:0;padding:4px 0}
    .tl-item{display:grid;grid-template-columns:32px 1fr;gap:0 12px;position:relative}
    .tl-item:not(:last-child) .tl-line{display:block}
    .tl-dot-col{display:flex;flex-direction:column;align-items:center;gap:0}
    .tl-dot{width:12px;height:12px;border-radius:50%;background:#0f6b45;border:2px solid #fff;box-shadow:0 0 0 2px #0f6b45;flex-shrink:0;margin-top:4px}
    .tl-line{flex:1;width:2px;background:#e2ebe6;margin:4px 0 0}
    .tl-body{padding:0 0 20px}
    .tl-item:last-child .tl-body{padding-bottom:0}
    .tl-name{font-size:14px;font-weight:700;color:#0f172a;line-height:1.4}
    .tl-time{font-size:12px;color:#94a3b8;margin-top:3px}

    @media(max-width:860px){.grid{grid-template-columns:1fr}}
    @media(max-width:640px){
      .mini{grid-template-columns:1fr}
      .item{grid-template-columns:60px 1fr}
      .item-price{grid-column:2;justify-self:start}
      .lk-tab{font-size:13px;padding:7px 12px}
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section">
  <div class="container">
    <div class="lk-wrap">

      <div>
        <a class="lk-back" href="account_orders.php">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
          Мои заказы
        </a>
      </div>

      <nav class="lk-tabs">
        <a class="lk-tab active" href="account_orders.php">Заказы</a>
        <a class="lk-tab" href="account_profile.php">Профиль</a>
        <a class="lk-tab" href="account_favorites.php">Избранное</a>
        <a class="lk-tab" href="account_addresses.php">Адреса</a>
      </nav>

      <div class="stack">
        <!-- Header -->
        <section class="box">
          <div class="hero">
            <div>
              <h1 style="margin:0 0 6px;font-size:26px">Заказ #<?= (int)$orderId ?></h1>
              <div class="mute">Оформлен: <?= h($order['created_at'] ?? '') ?></div>
              <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
                <span class="<?= h(delivery_method_badge_class($method)) ?>"><?= h(delivery_method_label($method)) ?></span>
                <span class="<?= h(order_status_class((string)($order['status'] ?? 'new'))) ?>">Заказ: <?= h(order_status_label((string)($order['status'] ?? 'new'))) ?></span>
                <?php if ($method === 'delivery'): ?>
                  <span class="<?= h(delivery_status_class((string)($order['delivery_status'] ?? ''))) ?>">Доставка: <?= h(delivery_status_label((string)($order['delivery_status'] ?? ''))) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <a class="btn" href="repeat_order.php?id=<?= (int)$orderId ?>">Повторить заказ</a>
              <a class="btn btn--ghost" href="account_orders.php">К заказам</a>
            </div>
          </div>
        </section>

        <?php if (!empty($order['cancel_reason'])): ?>
          <div class="reason">Заказ был отменён. Причина: <?= h($order['cancel_reason']) ?></div>
        <?php endif; ?>

        <!-- Main grid -->
        <div class="grid">
          <section class="box">
            <h2 style="margin:0 0 14px;font-size:20px"><?= $method === 'pickup' ? 'Самовывоз' : 'Доставка' ?></h2>
            <?php
              $fullAddr = trim((string)($order['address'] ?? ''));
              $fullAddr = preg_replace('/^Адрес:\s*/ui', '', $fullAddr);
              if ($fullAddr === '') $fullAddr = $method === 'pickup' ? 'Самовывоз из магазина FloraExpress' : '—';
            ?>
            <div class="mini"><div class="label"><?= $method === 'pickup' ? 'Место выдачи' : 'Адрес' ?></div><div class="value"><?= h($fullAddr) ?></div></div>
            <div class="mini"><div class="label">Телефон</div><div class="value"><?= h($order['phone'] ?? '—') ?></div></div>
            <div class="mini"><div class="label">Получатель</div><div class="value"><?= h($recipient !== '' ? $recipient : '—') ?></div></div>
            <?php if ($slot !== ''): ?><div class="mini"><div class="label"><?= $method === 'pickup' ? 'Время выдачи' : 'Слот' ?></div><div class="value"><?= h($slot) ?></div></div><?php endif; ?>
            <?php if ($method === 'delivery' && ($entrance !== '' || $floor !== '' || $domofon !== '')): ?>
              <div class="mini"><div class="label">Детали</div><div class="value"><div class="chips"><?php if ($entrance !== ''): ?><span class="chip">Подъезд: <?= h($entrance) ?></span><?php endif; ?><?php if ($floor !== ''): ?><span class="chip">Этаж: <?= h($floor) ?></span><?php endif; ?><?php if ($domofon !== ''): ?><span class="chip">Домофон: <?= h($domofon) ?></span><?php endif; ?></div></div></div>
            <?php endif; ?>
            <?php if (($commentData['note'] ?? '') !== ''): ?><div class="mini"><div class="label">Комментарий</div><div class="value"><?= h($commentData['note']) ?></div></div><?php endif; ?>
            <?php if ($method === 'delivery'): ?>
              <div class="mini"><div class="label">Курьер</div><div class="value"><?= !empty($order['courier_name']) ? h($order['courier_name']) : 'Ещё не назначен' ?></div></div>
            <?php endif; ?>
          </section>

          <section class="box">
            <h2 style="margin:0 0 14px;font-size:20px">Состав заказа</h2>
            <?php if ($items): ?>
              <?php foreach ($items as $item): ?>
                <div class="item">
                  <div class="item-photo"><img src="<?= h(image_src($item['image'] ?? '', $placeholder)) ?>" alt="<?= h($item['name'] ?? 'Товар') ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';"></div>
                  <div>
                    <div class="item-title"><?= h($item['name'] ?? 'Товар') ?></div>
                    <div class="item-meta"><?= (int)($item['qty'] ?? 0) ?> × <?= number_format((float)($item['price'] ?? 0), 0, ',', ' ') ?> ₽</div>
                  </div>
                  <div class="item-price"><?= number_format((float)($item['qty'] ?? 0) * (float)($item['price'] ?? 0), 0, ',', ' ') ?> ₽</div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="mute">Не удалось загрузить состав заказа.</div>
            <?php endif; ?>
            <div class="total"><span>Итого</span><span><?= number_format((float)($order['total'] ?? 0), 0, ',', ' ') ?> ₽</span></div>
          </section>
        </div>

        <!-- Delivery timeline -->
        <?php if ($method === 'delivery' && $events): ?>
          <section class="box">
            <h2 style="margin:0 0 18px;font-size:20px">История доставки</h2>
            <div class="tl">
              <?php
                $eventLabels = [
                  'assigned'      => 'Курьер назначен',
                  'picked_up'     => 'Курьер забрал заказ',
                  'on_the_way'    => 'Курьер в пути',
                  'delivered'     => 'Заказ доставлен',
                  'failed'        => 'Не удалось доставить',
                  'created'       => 'Доставка создана',
                  'status_change' => 'Изменение статуса',
                ];
                $reversedEvents = array_reverse($events);
              ?>
              <?php foreach ($reversedEvents as $idx => $ev): ?>
                <?php
                  $evKey  = strtolower(trim((string)($ev['event'] ?? '')));
                  $evName = $eventLabels[$evKey] ?? ucfirst(str_replace('_', ' ', $evKey)) ?: 'Событие';
                  $isLast = $idx === array_key_last($reversedEvents);
                ?>
                <div class="tl-item">
                  <div class="tl-dot-col">
                    <div class="tl-dot" style="<?= $isLast ? 'background:#0f6b45;box-shadow:0 0 0 3px rgba(15,107,69,.2)' : 'background:#94a3b8;box-shadow:0 0 0 2px #94a3b8' ?>"></div>
                    <?php if (!$isLast): ?><div class="tl-line"></div><?php endif; ?>
                  </div>
                  <div class="tl-body">
                    <div class="tl-name"><?= h($evName) ?></div>
                    <div class="tl-time"><?= h($ev['created_at'] ?? '') ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

      </div><!-- /.stack -->
    </div><!-- /.lk-wrap -->
  </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
