<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/order_presenter.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('account_orders.php'));
    exit;
}

$hasDeliveryMethod = column_exists($pdo, 'orders', 'delivery_method');
$hasCancelReason = column_exists($pdo, 'orders', 'cancel_reason');

$extraSelect = [];
$extraSelect[] = $hasDeliveryMethod ? "COALESCE(o.delivery_method, 'delivery') AS delivery_method" : "NULL::text AS delivery_method";
$extraSelect[] = $hasCancelReason ? "COALESCE(o.cancel_reason, '') AS cancel_reason" : "NULL::text AS cancel_reason";
$extraSql = implode(', ', $extraSelect);

$st = $pdo->prepare("
    SELECT
        o.*,
        $extraSql,
        COALESCE(o.tracking_token, '') AS tracking_token,
        d.status AS delivery_status,
        d.courier_id,
        u.name AS courier_name
    FROM orders o
    LEFT JOIN deliveries d ON d.order_id = o.id
    LEFT JOIN users u ON u.id = d.courier_id
    WHERE o.user_id = ?
    ORDER BY o.id DESC
    LIMIT 100
");
$st->execute([(int)$me['id']]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Мои заказы — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .lk-wrap{max-width:860px;margin:0 auto;display:grid;gap:16px}
    .lk-back{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:14px;text-decoration:none}
    .lk-back:hover{color:#0f172a}
    .lk-tabs{display:flex;gap:4px;background:#fff;border:1px solid #e8edf2;border-radius:14px;padding:6px;box-shadow:0 2px 8px rgba(15,23,42,.05);flex-wrap:wrap}
    .lk-tab{padding:8px 16px;border-radius:10px;color:#6b7280;text-decoration:none;font-size:14px;font-weight:600;transition:.15s ease}
    .lk-tab:hover{background:#f3f6f4;color:#0f172a}
    .lk-tab.active{background:var(--pistachio-soft);color:#0f172a;border:1px solid #cfe9d9}
    .box{background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:18px}
    .empty{padding:24px;border:1px dashed #dfe7e2;border-radius:16px;color:#6b7280;background:#fbfcfc}
    .tag{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid #d7eadf;background:#f2faf6;color:#185a37;font-weight:700;font-size:12px;white-space:nowrap}
    .tag--gray{border-color:#e5e7eb;background:#f8fafc;color:#475569}
    .tag--accent{border-color:#dbeafe;background:#eff6ff;color:#1d4ed8}
    .tag--danger{border-color:#fecdd3;background:#fff1f2;color:#be123c}
    .tag--ok{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}

    /* ── Orders list ── */
    .orders{display:grid;gap:12px;margin-top:16px}

    /* ── Order card ── */
    .oc{
      border:1px solid #e8edf2;
      border-radius:16px;
      background:#fff;
      overflow:hidden;
      box-shadow:0 1px 4px rgba(15,23,42,.05);
      transition:box-shadow .18s ease, transform .18s ease;
    }
    .oc:hover{box-shadow:0 4px 16px rgba(15,23,42,.09);transform:translateY(-1px)}

    /* colored left accent based on status */
    .oc[data-status="new"]        { border-left:4px solid #60a5fa }
    .oc[data-status="processing"] { border-left:4px solid #a78bfa }
    .oc[data-status="delivering"] { border-left:4px solid #fb923c }
    .oc[data-status="delivered"]  { border-left:4px solid #34d399 }
    .oc[data-status="canceled"]   { border-left:4px solid #f87171 }

    /* Top row: id + date + method | price */
    .oc__top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      padding:14px 18px 12px;
      border-bottom:1px solid #f0f4f8;
      flex-wrap:wrap;
    }
    .oc__top-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-width:0}
    .oc__id{
      font-size:17px;
      font-weight:900;
      color:#0f172a;
      text-decoration:none;
    }
    .oc__id:hover{color:#0f6b45}
    .oc__date{font-size:13px;color:#94a3b8;font-weight:500}
    .oc__price{
      font-size:20px;
      font-weight:900;
      color:#0f172a;
      white-space:nowrap;
      flex-shrink:0;
    }

    /* Bottom row: status + info | actions */
    .oc__body{
      display:flex;
      align-items:stretch;
      gap:0;
    }
    .oc__info{
      flex:1;
      min-width:0;
      padding:12px 18px 14px;
      display:grid;
      gap:8px;
    }
    .oc__row{
      display:flex;
      align-items:flex-start;
      gap:8px;
      min-width:0;
    }
    .oc__row-icon{
      flex-shrink:0;
      width:16px;
      height:16px;
      margin-top:2px;
      color:#94a3b8;
    }
    .oc__row-text{
      font-size:13.5px;
      color:#374151;
      line-height:1.45;
      min-width:0;
      word-break:break-word;
    }
    .oc__row-text strong{font-weight:700;color:#0f172a}
    .oc__reason{
      font-size:12.5px;
      color:#991b1b;
      background:#fff1f2;
      border:1px solid #fecdd3;
      border-radius:8px;
      padding:6px 10px;
      line-height:1.5;
    }

    /* Actions column */
    .oc__actions{
      display:flex;
      flex-direction:column;
      align-items:stretch;
      justify-content:center;
      gap:8px;
      padding:14px 16px 14px 0;
      flex-shrink:0;
      min-width:130px;
    }
    .oc__actions .btn{justify-content:center;white-space:nowrap}

    @media(max-width:640px){
      .lk-tab{font-size:13px;padding:7px 12px}
      .oc__actions{flex-direction:row;flex-wrap:wrap;padding:0 14px 14px;min-width:0}
      .oc__top{padding:12px 14px 10px}
      .oc__info{padding:10px 14px 12px}
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section">
  <div class="container">
    <div class="lk-wrap">
      <div>
        <a class="lk-back" href="account.php">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
          Личный кабинет
        </a>
      </div>
      <nav class="lk-tabs">
        <a class="lk-tab active" href="account_orders.php">Заказы</a>
        <a class="lk-tab" href="account_profile.php">Профиль</a>
        <a class="lk-tab" href="account_favorites.php">Избранное</a>
        <a class="lk-tab" href="account_addresses.php">Адреса</a>
      </nav>
      <main class="box">
        <h1 style="margin:0;font-size:24px">Мои заказы</h1>

      <?php if (!$orders): ?>
        <div class="empty" style="margin-top:16px">У вас пока нет оформленных заказов.</div>
      <?php else: ?>
        <div class="orders">
          <?php foreach ($orders as $o):
            $method = infer_delivery_method($o, $hasDeliveryMethod);
            $status = (string)($o['status'] ?? 'new');
          ?>
            <article class="oc" data-status="<?= h($status) ?>">

              <!-- Top: id / date / method badge — price -->
              <div class="oc__top">
                <div class="oc__top-left">
                  <a class="oc__id" href="order_view.php?id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a>
                  <span class="oc__date"><?= h($o['created_at'] ?? '') ?></span>
                  <span class="<?= h(delivery_method_badge_class($method)) ?>"><?= h(delivery_method_label($method)) ?></span>
                </div>
                <div class="oc__price"><?= number_format((float)($o['total'] ?? 0), 0, ',', ' ') ?> ₽</div>
              </div>

              <!-- Body: info + actions -->
              <div class="oc__body">
                <div class="oc__info">

                  <!-- Status row -->
                  <div class="oc__row">
                    <svg class="oc__row-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5l2 1.5"/></svg>
                    <div class="oc__row-text">
                      <span class="<?= h(order_status_class($status)) ?>"><?= h(order_status_label($status)) ?></span>
                    </div>
                  </div>
                  <?php if (!empty($o['cancel_reason'])): ?>
                    <div class="oc__reason">Причина отмены: <?= h($o['cancel_reason']) ?></div>
                  <?php endif; ?>

                  <!-- Delivery / pickup row -->
                  <div class="oc__row">
                    <svg class="oc__row-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="1" y="5" width="14" height="9" rx="1.5"/><path d="M5 5V4a3 3 0 016 0v1"/></svg>
                    <div class="oc__row-text">
                      <?php if ($method === 'pickup'): ?>
                        <strong>Самовывоз</strong> —
                        <?= h(trim((string)($o['delivery_slot'] ?? '')) !== '' ? $o['delivery_slot'] : 'из магазина FloraExpress') ?>
                      <?php else: ?>
                        <strong>Доставка:</strong>
                        <span class="<?= h(delivery_status_class((string)($o['delivery_status'] ?? ''))) ?>"><?= h(delivery_status_label((string)($o['delivery_status'] ?? ''))) ?></span>
                        <?php if (!empty($o['courier_name'])): ?>
                          · Курьер: <?= h($o['courier_name']) ?>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- Address row -->
                  <div class="oc__row">
                    <svg class="oc__row-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M8 1.5A4.5 4.5 0 0112.5 6c0 3-4.5 8.5-4.5 8.5S3.5 9 3.5 6A4.5 4.5 0 018 1.5z"/><circle cx="8" cy="6" r="1.5"/></svg>
                    <div class="oc__row-text">
                      <?= h($method === 'pickup'
                        ? ($o['address'] ?? 'Магазин FloraExpress')
                        : short_address($o['address'] ?? '', $o['comment'] ?? '')) ?>
                      <?php if ($method === 'delivery' && !empty($o['phone'])): ?>
                        · <?= h($o['phone']) ?>
                      <?php endif; ?>
                    </div>
                  </div>

                </div>

                <!-- Actions -->
                <div class="oc__actions">
                  <a class="btn btn--ghost btn--sm" href="order_view.php?id=<?= (int)$o['id'] ?>">Открыть</a>
                  <?php if (!empty($o['tracking_token']) && !in_array($status, ['delivered','canceled'], true)): ?>
                    <a class="btn btn--sm" href="track.php?token=<?= h($o['tracking_token']) ?>" target="_blank">Отследить</a>
                  <?php endif; ?>
                </div>
              </div>

            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      </main>
    </div>
  </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
