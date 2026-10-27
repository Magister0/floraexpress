<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/order_presenter.php';
require_once __DIR__ . '/map_config.php';


function order_status_label(string $status): string {
    return [
        'new'         => 'Принят',
        'paid'        => 'Принят',
        'assembling'  => 'Собирается',
        'on_delivery' => 'Передан курьеру',
        'delivered'   => 'Доставлен',
        'canceled'    => 'Отменён',
    ][$status] ?? $status;
}

function delivery_status_label(string $status): string {
    return [
        'pending'   => 'Ожидает курьера',
        'assigned'  => 'Курьер назначен',
        'picked'    => 'Курьер забрал заказ',
        'en_route'  => 'Курьер в пути',
        'delivered' => 'Доставлен',
        'failed'    => 'Не удалось доставить',
    ][$status] ?? $status;
}

function status_step(string $status): int {
    return [
        'new' => 1, 'paid' => 1, 'assembling' => 2,
        'on_delivery' => 3, 'delivered' => 4, 'canceled' => 0,
    ][$status] ?? 1;
}

$pdo = db();
$token = trim((string)($_GET['token'] ?? ''));

$order = null;
$items = [];
$delivery = null;

if ($token !== '') {
    try {
        $st = $pdo->prepare("SELECT * FROM orders WHERE tracking_token = ? LIMIT 1");
        $st->execute([$token]);
        $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($order) {
            $stI = $pdo->prepare("SELECT oi.qty, oi.price, oi.product_id, COALESCE(p.name,'Товар') AS name, p.image FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
            $stI->execute([(int)$order['id']]);
            $items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stD = $pdo->prepare("SELECT * FROM deliveries WHERE order_id = ? LIMIT 1");
            $stD->execute([(int)$order['id']]);
            $delivery = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {}
}

$status         = (string)($order['status'] ?? '');
$deliveryStatus = (string)($delivery['status'] ?? '');

if ($deliveryStatus === 'delivered' && $status !== 'canceled') {
    $status = 'delivered';
} elseif (in_array($deliveryStatus, ['picked','en_route'], true) && !in_array($status, ['delivered','canceled'], true)) {
    $status = 'on_delivery';
}

$step      = status_step($status);
$canceled  = $status === 'canceled';
$delivered = $status === 'delivered';
$steps = ['Принят', 'Собирается', 'Передан курьеру', 'Доставлен'];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Отслеживание заказа — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .track-wrap{max-width:680px;margin:32px auto;padding:0 16px;display:grid;gap:18px}
    .box{background:#fff;border:1px solid #e7ece8;border-radius:22px;box-shadow:var(--shadow-1);padding:22px}
    .steps{display:flex;gap:0;margin:20px 0 8px;position:relative}
    .steps::before{content:'';position:absolute;top:18px;left:18px;right:18px;height:3px;background:#e5e7eb;z-index:0}
    .step{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative;z-index:1}
    .step__dot{width:36px;height:36px;border-radius:50%;border:3px solid #e5e7eb;background:#fff;display:grid;place-items:center;font-size:13px;font-weight:800;color:#9ca3af;transition:.3s}
    .step--done .step__dot{background:#175a37;border-color:#175a37;color:#fff}
    .step--active .step__dot{background:#fff;border-color:#175a37;color:#175a37;box-shadow:0 0 0 4px rgba(23,90,55,.15)}
    .step--canceled .step__dot{background:#fef2f2;border-color:#fca5a5;color:#b91c1c}
    .step__label{font-size:11px;color:#6b7280;text-align:center;line-height:1.3;max-width:72px}
    .step--done .step__label,.step--active .step__label{color:#175a37;font-weight:700}
    .info-row{display:grid;grid-template-columns:140px 1fr;gap:6px;padding:8px 0;border-bottom:1px solid #f0f4f1}
    .info-row:last-child{border-bottom:none}
    .info-label{color:#6b7280;font-size:14px}
    .info-value{font-size:14px;font-weight:700;color:#111827;word-break:break-word}
    .badge-ok{display:inline-block;background:#ecfdf3;border:1px solid #6ee7b7;color:#065f46;border-radius:999px;padding:4px 12px;font-size:13px;font-weight:700}
    .badge-cancel{display:inline-block;background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:999px;padding:4px 12px;font-size:13px;font-weight:700}
    .track-item{display:grid;grid-template-columns:64px 1fr auto;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid #f0f4f1}
    .track-item:last-child{border-bottom:none;padding-bottom:0}
    .track-item:first-child{padding-top:0}
    .track-item__photo{width:64px;height:64px;border-radius:14px;overflow:hidden;background:#f3f4f6;border:1px solid #edf1ef;display:block}
    .track-item__photo img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s}
    .track-item__photo:hover img{transform:scale(1.05)}
    .track-item__name{font-size:15px;font-weight:700;color:#111827;text-decoration:none;line-height:1.35}
    .track-item__name:hover{color:#175a37;text-decoration:underline}
    .track-item__qty{font-size:13px;color:#6b7280;margin-top:2px}
    .track-item__sum{font-size:15px;font-weight:800;color:#111827;white-space:nowrap}
    @media (max-width:520px){.track-item{grid-template-columns:56px 1fr;gap:10px}.track-item__photo{width:56px;height:56px}.track-item__sum{grid-column:2/3;font-size:14px}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="track-wrap">

<?php if (!$order): ?>
  <div class="box">
    <h1 style="margin:0 0 10px">Отслеживание заказа</h1>
    <?php if ($token !== ''): ?>
      <p style="color:#b91c1c">Заказ не найден. Проверьте ссылку.</p>
    <?php else: ?>
      <p class="mute">Перейдите по ссылке из письма или страницы подтверждения заказа.</p>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="box">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h1 style="margin:0;font-size:26px">Заказ №<?= (int)$order['id'] ?></h1>
      <?php if ($canceled): ?>
        <span class="badge-cancel">Отменён</span>
      <?php elseif ($delivered): ?>
        <span class="badge-ok">Доставлен</span>
      <?php else: ?>
        <span class="badge-ok" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8">В обработке</span>
      <?php endif; ?>
    </div>

    <?php if (!$canceled): ?>
    <div class="steps">
      <?php foreach ($steps as $i => $label):
        $n = $i + 1;
        $isDone = ($n < $step) || ($delivered && $n === count($steps));
        $cls = $isDone ? 'step--done' : ($n === $step ? 'step--active' : '');
      ?>
        <div class="step <?= $cls ?>">
          <div class="step__dot"><?= $isDone ? '✓' : $n ?></div>
          <div class="step__label"><?= h($label) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p style="margin:12px 0 0;color:#374151">
      Статус: <strong><?= h(order_status_label($status)) ?></strong>
      <?php if ($delivery && !in_array($status, ['new','paid','assembling','canceled'], true)): ?>
        — <?= h(delivery_status_label((string)($delivery['status'] ?? ''))) ?>
      <?php endif; ?>
    </p>
  </div>

  <?php
    $photoRequested = !empty($order['photo_report_requested']) && (string)$order['photo_report_requested'] !== 'f';
  ?>
  <?php if ($photoRequested && empty($order['photo_report']) && $status !== 'canceled' && $status !== 'delivered'): ?>
  <div class="box" style="background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border:1px solid #fde68a">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="font-size:28px">📸</div>
      <div>
        <h2 style="margin:0;font-size:16px;color:#92400e">Фото-отчёт заказан</h2>
        <div style="font-size:13px;color:#78350f;margin-top:3px">Флорист пришлёт фото готового букета перед отправкой — оно появится здесь.</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($order['photo_report']) && $status !== 'canceled'): ?>
  <div class="box" style="overflow:hidden">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
      <div style="font-size:24px">🌸</div>
      <div>
        <h2 style="margin:0;font-size:17px;color:#175a37">Ваш букет готов!</h2>
        <div class="mute" style="font-size:12.5px;margin-top:2px">Фото от флориста перед отправкой</div>
      </div>
    </div>
    <a href="<?= h((string)$order['photo_report']) ?>" target="_blank" style="display:block;border-radius:14px;overflow:hidden;border:1px solid #e7ece8">
      <img src="<?= h((string)$order['photo_report']) ?>" alt="Фото готового букета" style="width:100%;height:auto;display:block;max-height:480px;object-fit:cover">
    </a>
    <?php if (!empty($order['photo_report_at'])): ?>
      <div class="mute" style="font-size:12px;margin-top:8px;text-align:center">Снято <?= h(substr((string)$order['photo_report_at'], 0, 16)) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php
    // Карта живого трекинга курьера показывается, когда курьер уже забрал заказ
    $showTracking = $delivery
        && in_array((string)($delivery['status'] ?? ''), ['picked','en_route'], true)
        && $status !== 'canceled';
    $yandexMapsKey = defined('FLORA_YANDEX_MAPS_API_KEY') ? (string)FLORA_YANDEX_MAPS_API_KEY : '';
  ?>
  <?php if ($showTracking): ?>
    <div class="box" style="overflow:hidden">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <div style="font-size:24px">🚴</div>
        <div>
          <h2 style="margin:0;font-size:17px;color:#175a37">Курьер на карте</h2>
          <div class="mute" style="font-size:12.5px;margin-top:2px" id="trackingStatusLine">Связываемся с курьером…</div>
        </div>
      </div>
      <div id="courierMap" style="width:100%;height:380px;border-radius:14px;overflow:hidden;border:1px solid #e7ece8;background:#f3f4f6"></div>
      <div class="mute" style="font-size:11.5px;margin-top:8px;text-align:center">
        Геолокация обновляется автоматически каждые ~10 секунд, пока курьер транслирует её из Telegram.
      </div>
    </div>

    <?php if ($yandexMapsKey !== ''): ?>
    <script src="https://api-maps.yandex.ru/2.1/?apikey=<?= h($yandexMapsKey) ?>&lang=ru_RU"></script>
    <?php else: ?>
    <script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU"></script>
    <?php endif; ?>
    <script>
    (function(){
      const TRACK_TOKEN = <?= json_encode((string)$token) ?>;
      const POLL_MS = 10000;
      const mapEl = document.getElementById('courierMap');
      const statusLine = document.getElementById('trackingStatusLine');
      if (!mapEl || typeof ymaps === 'undefined') return;

      let yMap = null;
      let shopPlacemark = null;
      let destPlacemark = null;
      let courierPlacemark = null;
      let pathPolyline = null;
      let lastCourierPos = null;

      function fmtAgo(sec) {
        sec = Math.max(0, parseInt(sec, 10) || 0);
        if (sec < 60) return sec + ' сек назад';
        const m = Math.floor(sec / 60);
        if (m < 60) return m + ' мин назад';
        return Math.floor(m / 60) + ' ч назад';
      }

      function buildMap(data) {
        const shop = data.shop;
        const dest = data.destination;
        const initLat = (dest && dest.lat) || shop.lat;
        const initLon = (dest && dest.lon) || shop.lon;

        yMap = new ymaps.Map(mapEl, {
          center: [initLat, initLon],
          zoom: 13,
          controls: ['zoomControl','geolocationControl'],
        });

        if (shop && shop.lat && shop.lon) {
          shopPlacemark = new ymaps.Placemark(
            [shop.lat, shop.lon],
            { iconCaption: 'Магазин', balloonContent: shop.address || 'FloraExpress' },
            { preset: 'islands#greenShopIcon' }
          );
          yMap.geoObjects.add(shopPlacemark);
        }

        if (dest && dest.lat && dest.lon) {
          destPlacemark = new ymaps.Placemark(
            [dest.lat, dest.lon],
            { iconCaption: 'Адрес доставки', balloonContent: 'Здесь вас ждут' },
            { preset: 'islands#redHomeIcon' }
          );
          yMap.geoObjects.add(destPlacemark);
        }
      }

      function updateCourier(data) {
        const c = data.courier;
        if (!c || c.offline) {
          if (statusLine) statusLine.textContent = 'Курьер пока не транслирует геолокацию из Telegram.';
          if (courierPlacemark) {
            yMap.geoObjects.remove(courierPlacemark);
            courierPlacemark = null;
          }
          return;
        }

        const pos = [c.lat, c.lon];
        const balloon = (c.name || 'Курьер') +
          (c.speed_kmh ? ' · ' + Math.round(c.speed_kmh) + ' км/ч' : '');

        if (!courierPlacemark) {
          courierPlacemark = new ymaps.Placemark(
            pos,
            { iconCaption: c.name || 'Курьер', balloonContent: balloon },
            { preset: 'islands#blueCircleDotIconWithCaption', iconColor: '#175a37' }
          );
          yMap.geoObjects.add(courierPlacemark);
        } else {
          courierPlacemark.geometry.setCoordinates(pos);
          courierPlacemark.properties.set('balloonContent', balloon);
        }

        lastCourierPos = pos;

        // Авто-фокус: умещаем магазин, курьера и адрес в видимую область
        try {
          const points = [pos];
          if (shopPlacemark) points.push(shopPlacemark.geometry.getCoordinates());
          if (destPlacemark) points.push(destPlacemark.geometry.getCoordinates());
          if (points.length >= 2) {
            yMap.setBounds(ymaps.util.bounds.fromPoints(points), {
              checkZoomRange: true, zoomMargin: 60, duration: 500,
            });
          }
        } catch (e) {}

        if (statusLine) {
          statusLine.textContent = (c.name || 'Курьер') + ' в пути · обновлено ' + fmtAgo(c.age_seconds || 0);
        }
      }

      function updatePath(path) {
        if (!Array.isArray(path) || path.length < 2) return;
        const coords = path.map(p => [p.lat, p.lon]);
        if (!pathPolyline) {
          pathPolyline = new ymaps.Polyline(coords, {}, {
            strokeColor: '#175a37',
            strokeWidth: 4,
            strokeOpacity: 0.65,
          });
          yMap.geoObjects.add(pathPolyline);
        } else {
          pathPolyline.geometry.setCoordinates(coords);
        }
      }

      async function poll() {
        try {
          const r = await fetch('courier_location.php?token=' + encodeURIComponent(TRACK_TOKEN), { cache: 'no-store' });
          const data = await r.json();
          if (!data || !data.ok) return;
          if (!data.show_map) {
            if (statusLine) statusLine.textContent = 'Курьер ещё не забрал заказ.';
            return;
          }
          if (!yMap) buildMap(data);
          updatePath(data.path || []);
          updateCourier(data);
        } catch (e) { /* ignore network glitches */ }
      }

      ymaps.ready(function(){
        poll();
        setInterval(poll, POLL_MS);
      });
    })();
    </script>
  <?php endif; ?>

  <div class="box">
    <h2 style="margin:0 0 14px;font-size:17px">Детали заказа</h2>
    <div class="info-row"><div class="info-label">Получатель</div><div class="info-value"><?= h($order['recipient_name'] ?? '—') ?></div></div>
    <div class="info-row"><div class="info-label">Телефон</div><div class="info-value"><?= h($order['phone'] ?? '—') ?></div></div>
    <div class="info-row"><div class="info-label">Адрес доставки</div><div class="info-value"><?= h($order['address'] ?? '—') ?></div></div>
    <div class="info-row"><div class="info-label">Время доставки</div><div class="info-value"><?= h($order['delivery_slot'] ?? '—') ?></div></div>
    <?php if ((float)($order['delivery_fee'] ?? 0) > 0): ?>
      <div class="info-row"><div class="info-label">Доставка</div><div class="info-value"><?= number_format((float)$order['delivery_fee'], 0, ',', ' ') ?> ₽</div></div>
    <?php endif; ?>
    <div class="info-row"><div class="info-label">Сумма</div><div class="info-value"><?= number_format((float)($order['total'] ?? 0), 0, ',', ' ') ?> ₽</div></div>
  </div>

  <?php if ($items): ?>
  <?php $placeholder = placeholder_image(); ?>
  <div class="box">
    <h2 style="margin:0 0 14px;font-size:17px">Состав заказа</h2>
    <?php foreach ($items as $item):
      $pid     = (int)($item['product_id'] ?? 0);
      $imgSrc  = image_src((string)($item['image'] ?? ''), $placeholder);
      $qty     = (int)$item['qty'];
      $price   = (float)$item['price'];
      $hasLink = $pid > 0;
    ?>
      <div class="track-item">
        <?php if ($hasLink): ?>
          <a class="track-item__photo" href="product.php?id=<?= $pid ?>">
            <img src="<?= h($imgSrc) ?>" alt="<?= h($item['name']) ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';">
          </a>
        <?php else: ?>
          <span class="track-item__photo">
            <img src="<?= h($imgSrc) ?>" alt="<?= h($item['name']) ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';">
          </span>
        <?php endif; ?>
        <div>
          <?php if ($hasLink): ?>
            <a class="track-item__name" href="product.php?id=<?= $pid ?>"><?= h($item['name']) ?></a>
          <?php else: ?>
            <span class="track-item__name"><?= h($item['name']) ?></span>
          <?php endif; ?>
          <div class="track-item__qty"><?= $qty ?> × <?= number_format($price, 0, ',', ' ') ?> ₽</div>
        </div>
        <div class="track-item__sum"><?= number_format($qty * $price, 0, ',', ' ') ?> ₽</div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="mute" style="font-size:13px;text-align:center">Обновите страницу чтобы увидеть актуальный статус. Сохраните ссылку на эту страницу.</p>

<?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
