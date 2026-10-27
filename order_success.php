<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/extra_services_lib.php';

if (file_exists(__DIR__ . '/cart_store.php')) {
    require_once __DIR__ . '/cart_store.php';
    if (function_exists('cart_store_sync')) {
        cart_store_sync();
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('column_exists')) {
    function column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ? LIMIT 1");
            $st->execute([$table, $column]);
            $cache[$key] = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('placeholder_image')) {
    function placeholder_image(): string
    {
        return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="100%" height="100%" rx="18" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="12" fill="#9ca3af">Нет фото</text></svg>');
    }
}

if (!function_exists('image_src')) {
    function image_src(string $image, string $fallback): string
    {
        $image = trim($image);
        if ($image === '') return $fallback;
        if (preg_match('~^https?://~i', $image) || str_starts_with($image, 'data:image/')) return $image;
        if ($image[0] === '/') return $image;
        return $image;
    }
}

if (!function_exists('money_ru')) {
    function money_ru($value): string
    {
        return number_format((float)$value, 0, ',', ' ') . ' ₽';
    }
}

if (!function_exists('order_status_label')) {
    function order_status_label(?string $status): string
    {
        $map = [
            'new' => 'Новый',
            'paid' => 'Оплачен',
            'assembling' => 'Собирается',
            'on_delivery' => 'В доставке',
            'delivered' => 'Доставлен',
            'canceled' => 'Отменён',
        ];
        $key = (string)$status;
        return $map[$key] ?? ($key !== '' ? $key : '—');
    }
}

if (!function_exists('delivery_status_label')) {
    function delivery_status_label(?string $status): string
    {
        $map = [
            'pending' => 'Ожидает курьера',
            'assigned' => 'Назначен курьер',
            'pickup' => 'Самовывоз',
            'on_way' => 'Курьер в пути',
            'delivered' => 'Доставлено',
            'canceled' => 'Отменено',
        ];
        $key = (string)$status;
        return $map[$key] ?? ($key !== '' ? $key : '—');
    }
}

if (!function_exists('delivery_method_label')) {
    function delivery_method_label(string $method): string
    {
        return $method === 'pickup' ? 'Самовывоз' : 'Доставка';
    }
}

if (!function_exists('delivery_method_badge_class')) {
    function delivery_method_badge_class(string $method): string
    {
        return $method === 'pickup' ? 'tag tag--gray' : 'tag';
    }
}

if (!function_exists('split_comment_parts')) {
    function split_comment_parts(?string $comment): array
    {
        $comment = trim((string)$comment);
        if ($comment === '') return ['details' => [], 'note' => ''];

        $details = [];
        $note = '';
        $parts = preg_split('/\s*;\s*/u', $comment) ?: [];

        foreach ($parts as $part) {
            if ($part === '') continue;
            if (str_contains($part, ':')) {
                [$k, $v] = array_map('trim', explode(':', $part, 2));
                if ($k !== '') $details[$k] = $v;
            } else {
                $note .= ($note !== '' ? '; ' : '') . $part;
            }
        }

        if (isset($details['Комментарий']) && trim((string)$details['Комментарий']) !== '') {
            $note = trim((string)$details['Комментарий']);
            unset($details['Комментарий']);
        }

        return ['details' => $details, 'note' => $note];
    }
}

if (!function_exists('detail_value')) {
    function detail_value(array $details, string $key): string
    {
        return trim((string)($details[$key] ?? ''));
    }
}

if (!function_exists('infer_delivery_method')) {
    function infer_delivery_method(array $order, bool $hasDeliveryMethod): string
    {
        if ($hasDeliveryMethod && !empty($order['delivery_method'])) {
            return (string)$order['delivery_method'] === 'pickup' ? 'pickup' : 'delivery';
        }
        $comment = (string)($order['comment'] ?? '');
        $hasPickup = function_exists('mb_stripos') ? mb_stripos($comment, 'Самовывоз') !== false : stripos($comment, 'Самовывоз') !== false;
        return $hasPickup ? 'pickup' : 'delivery';
    }
}

if (!function_exists('short_address')) {
    function short_address(string $address, string $comment = ''): string
    {
        $address = trim($address);
        if ($address !== '') return $address;
        $parts = split_comment_parts($comment);
        return $parts['note'] !== '' ? $parts['note'] : '—';
    }
}

$pdo = db();
extra_services_bootstrap($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login');
    exit;
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: index.php');
    exit;
}

$hasDeliveryMethod = column_exists($pdo, 'orders', 'delivery_method');
$hasCancelReason = column_exists($pdo, 'orders', 'cancel_reason');
$extraSql = [];
$extraSql[] = $hasDeliveryMethod ? "COALESCE(o.delivery_method, 'delivery') AS delivery_method" : "NULL::text AS delivery_method";
$extraSql[] = $hasCancelReason ? "COALESCE(o.cancel_reason, '') AS cancel_reason" : "NULL::text AS cancel_reason";

$st = $pdo->prepare('SELECT o.*, ' . implode(', ', $extraSql) . ' FROM orders o WHERE o.id = ? AND o.user_id = ? LIMIT 1');
$st->execute([$orderId, (int)$me['id']]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    header('Location: index.php');
    exit;
}

$stI = $pdo->prepare("SELECT oi.qty, oi.price, COALESCE(p.name, 'Товар') AS name, p.image FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id ASC");
$stI->execute([$orderId]);
$items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

$extraServices = [];
try {
    $stS = $pdo->prepare('SELECT oes.name_snapshot, oes.qty, oes.price, oes.message_text, es.image
                          FROM order_extra_services oes
                          LEFT JOIN extra_services es ON es.id = oes.service_id
                          WHERE oes.order_id = ? ORDER BY oes.id ASC');
    $stS->execute([$orderId]);
    $extraServices = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $extraServices = [];
}

$delivery = null;
try {
    $stD = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = ? LIMIT 1');
    $stD->execute([$orderId]);
    $delivery = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $delivery = null;
}

$placeholder = placeholder_image();
$commentData = split_comment_parts((string)($order['comment'] ?? ''));
$method = infer_delivery_method($order, $hasDeliveryMethod);
$shortAddress = $method === 'pickup'
    ? ((string)($order['address'] ?? 'Самовывоз из магазина FloraExpress'))
    : short_address((string)($order['address'] ?? ''), (string)($order['comment'] ?? ''));
$recipient = trim((string)($order['recipient_name'] ?? ''));
$slot = trim((string)($order['delivery_slot'] ?? ''));
$entrance = detail_value($commentData['details'], 'Подъезд');
$floor = detail_value($commentData['details'], 'Этаж');
$domofon = detail_value($commentData['details'], 'Домофон');
$deliveryStatus = $method === 'pickup' ? 'pickup' : (string)($delivery['status'] ?? 'pending');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Заказ оформлен — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .wrap{max-width:1120px;margin:32px auto;padding:0 16px;display:grid;gap:18px}
    .box{background:#fff;border:1px solid #e7ece8;border-radius:22px;box-shadow:var(--shadow-1);padding:22px}
    .hero{display:grid;grid-template-columns:auto 1fr;gap:16px;align-items:start}
    .check{width:56px;height:56px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#ecfdf3,#d1fae5);color:#047857;font-size:28px;font-weight:700}
    .grid{display:grid;grid-template-columns:minmax(320px,.95fr) minmax(0,1.05fr);gap:18px}
    .mini{display:grid;grid-template-columns:130px 1fr;gap:14px;padding-bottom:14px;border-bottom:1px solid #eef2f1;margin-bottom:14px}
    .mini:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
    .label{font-size:14px;color:#6b7a90}
    .value{font-size:15px;line-height:1.6;color:#0f172a;font-weight:600}
    .tag{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;border:1px solid #d7eadf;background:#f2faf6;color:#185a37;font-weight:700;font-size:12px}
    .tag--gray{border-color:#e5e7eb;background:#f8fafc;color:#475569}
    .tag--accent{border-color:#dbeafe;background:#eff6ff;color:#1d4ed8}
    .item{display:grid;grid-template-columns:72px 1fr auto;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid #eef2f1}
    .item:last-child{border-bottom:none}
    .item-photo{width:72px;height:72px;border-radius:16px;overflow:hidden;background:#f3f4f6;border:1px solid #edf1ef}
    .item-photo img{width:100%;height:100%;object-fit:cover;display:block}
    .total{display:flex;justify-content:space-between;align-items:center;padding-top:16px;margin-top:6px;border-top:1px solid #e7ece8;font-size:18px;font-weight:900}
    .chips{display:flex;gap:8px;flex-wrap:wrap}
    .chip{padding:8px 12px;border-radius:999px;background:#f8faf9;border:1px solid #e3ebe6;font-size:13px;color:#294252;font-weight:700}
    .care-note{padding:16px;border-radius:18px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.6}
    @media (max-width:950px){.grid{grid-template-columns:1fr}}
    @media (max-width:640px){.hero{grid-template-columns:1fr}.mini{grid-template-columns:1fr}.item{grid-template-columns:60px 1fr}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="wrap">
  <section class="box">
    <div class="hero">
      <div class="check">✓</div>
      <div>
        <h1 style="margin:0;font-size:40px;line-height:1.05">Заказ №<?= (int)$order['id'] ?> оформлен</h1>
        <p style="margin:8px 0 0;color:#607080;font-size:17px;line-height:1.5"><?= $method === 'pickup' ? 'Мы приняли заказ. Его можно будет забрать в магазине в выбранный интервал.' : 'Мы приняли заказ в обработку. Статусы доставки появятся, когда заказ возьмёт курьер.' ?></p>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
          <span class="<?= h(delivery_method_badge_class($method)) ?>"><?= h(delivery_method_label($method)) ?></span>
          <span class="tag">Статус заказа: <?= h(order_status_label((string)($order['status'] ?? 'new'))) ?></span>
          <?php if ($method === 'delivery'): ?><span class="tag tag--accent">Доставка: <?= h(delivery_status_label($deliveryStatus)) ?></span><?php else: ?><span class="tag tag--gray">Выдача: ожидается</span><?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <div class="grid">
    <section class="box">
      <h2 style="margin:0 0 18px;font-size:18px"><?= $method === 'pickup' ? 'Самовывоз' : 'Доставка' ?></h2>
      <div class="mini"><div class="label"><?= $method === 'pickup' ? 'Место выдачи' : 'Адрес' ?></div><div class="value"><?= h($shortAddress) ?></div></div>
      <div class="mini"><div class="label">Телефон</div><div class="value"><?= h($order['phone'] ?? '—') ?></div></div>
      <div class="mini"><div class="label">Получатель</div><div class="value"><?= h($recipient !== '' ? $recipient : '—') ?></div></div>
      <?php if ($slot !== ''): ?><div class="mini"><div class="label"><?= $method === 'pickup' ? 'Время выдачи' : 'Время' ?></div><div class="value"><?= h($slot) ?></div></div><?php endif; ?>
      <?php if ($method === 'delivery' && ($entrance !== '' || $floor !== '' || $domofon !== '')): ?><div class="mini"><div class="label">Детали</div><div class="value"><div class="chips"><?php if ($entrance !== ''): ?><span class="chip">Подъезд: <?= h($entrance) ?></span><?php endif; ?><?php if ($floor !== ''): ?><span class="chip">Этаж: <?= h($floor) ?></span><?php endif; ?><?php if ($domofon !== ''): ?><span class="chip">Домофон: <?= h($domofon) ?></span><?php endif; ?></div></div></div><?php endif; ?>
      <?php if (($commentData['note'] ?? '') !== ''): ?><div class="mini"><div class="label">Комментарий</div><div class="value"><?= h($commentData['note']) ?></div></div><?php endif; ?>
    </section>

    <section class="box">
      <h2 style="margin:0 0 18px;font-size:18px">Состав заказа</h2>
      <?php foreach ($items as $item): ?>
        <div class="item">
          <div class="item-photo"><img src="<?= h(image_src((string)($item['image'] ?? ''), $placeholder)) ?>" alt="<?= h($item['name'] ?? 'Товар') ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';"></div>
          <div>
            <div style="font-size:16px;font-weight:800;margin:0 0 4px"><?= h($item['name'] ?? 'Товар') ?></div>
            <div style="font-size:14px;color:#6b7280"><?= (int)($item['qty'] ?? 0) ?> × <?= money_ru((float)($item['price'] ?? 0)) ?></div>
          </div>
          <div style="font-size:18px;font-weight:800;white-space:nowrap"><?= money_ru((float)($item['qty'] ?? 0) * (float)($item['price'] ?? 0)) ?></div>
        </div>
      <?php endforeach; ?>

      <?php foreach ($extraServices as $item): ?>
        <div class="item">
          <div class="item-photo"><img src="<?= h(image_src((string)($item['image'] ?? ''), $placeholder)) ?>" alt="<?= h($item['name_snapshot'] ?? 'Доп. услуга') ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';"></div>
          <div>
            <div style="font-size:16px;font-weight:800;margin:0 0 4px"><?= h($item['name_snapshot'] ?? 'Доп. услуга') ?></div>
            <div style="font-size:14px;color:#6b7280"><?= !empty($item['message_text']) ? 'Текст добавлен' : 'Дополнение к заказу' ?></div>
          </div>
          <div style="font-size:18px;font-weight:800;white-space:nowrap">+<?= money_ru((float)($item['price'] ?? 0)) ?></div>
        </div>
        <?php if (!empty($item['message_text'])): ?><div style="font-size:14px;color:#6b7280;margin-top:-6px;margin-bottom:10px;padding-left:86px">Текст на открытке: <?= h($item['message_text']) ?></div><?php endif; ?>
      <?php endforeach; ?>

      <?php $deliveryFeeShown = (float)($order['delivery_fee'] ?? 0); ?>
      <?php if ($deliveryFeeShown > 0): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:15px;color:#374151">
          <span>Доставка</span>
          <span><?= money_ru($deliveryFeeShown) ?></span>
        </div>
      <?php endif; ?>
      <div class="total"><span>Итого</span><span><?= money_ru((float)($order['total'] ?? 0)) ?></span></div>
    </section>
  </div>

  <?php
  $trackToken = trim((string)($_GET['token'] ?? ''));
  if ($trackToken === '') {
      // Попробуем взять из БД
      try {
          $stTok = $pdo->prepare("SELECT tracking_token FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
          $stTok->execute([$orderId, (int)$me['id']]);
          $trackToken = (string)($stTok->fetchColumn() ?: '');
      } catch (Throwable $e) {}
  }
  ?>
  <?php if ($trackToken !== ''): ?>
  <div class="box" style="background:#eff6ff;border:1px solid #bfdbfe">
    <h2 style="margin:0 0 8px;font-size:17px;color:#1d4ed8">Отслеживание заказа</h2>
    <p style="margin:0 0 12px;color:#374151">По этой ссылке можно в любой момент проверить статус заказа — без входа в аккаунт. Сохраните её или поделитесь с получателем.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <a class="btn" href="track.php?token=<?= h($trackToken) ?>" target="_blank">Открыть трекинг</a>
      <code style="font-size:13px;color:#374151;word-break:break-all"><?= h('https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/track.php?token=' . $trackToken) ?></code>
    </div>
  </div>
  <?php endif; ?>

  <div class="care-note">Чтобы букет дольше оставался свежим и красивым, можно открыть отдельную памятку по уходу. Для разных цветов рекомендации немного отличаются, поэтому точные детали лучше уточнять у продавцов лично. <a href="care.php">Открыть памятку по уходу</a></div>
  <div style="display:flex;gap:12px;flex-wrap:wrap"><a class="btn" href="catalog.php">Продолжить покупки</a><a class="btn btn--ghost" href="account_orders.php">Перейти в мои заказы</a></div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
