<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cart_store.php';
require_once __DIR__ . '/extra_services_lib.php';

$pdo = db();
cart_store_sync();
extra_services_bootstrap($pdo);

function normalize_service_ids($raw): array {
    $ids = is_array($raw) ? $raw : [];
    $ids = array_map('intval', $ids);
    $ids = array_values(array_unique(array_filter($ids, static fn($x) => $x > 0)));
    sort($ids);
    return $ids;
}
function normalize_service_messages($raw): array {
    $result = [];
    if (!is_array($raw)) return $result;
    foreach ($raw as $id => $text) {
        $sid = (int)$id;
        if ($sid <= 0) continue;
        $result[$sid] = trim((string)$text);
    }
    return $result;
}
function placeholder_image(): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="100%" height="100%" rx="18" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="12" fill="#9ca3af">Нет фото</text></svg>');
}

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('checkout_services.php'));
    exit;
}

$cart = cart_store_get();
$ids = array_map('intval', array_keys($cart));
$items = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, price, image, is_active FROM products WHERE id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $pid = (int)$r['id'];
        $qty = max(0, (int)($cart[$pid] ?? 0));
        if ($qty <= 0 || (int)$r['is_active'] !== 1) continue;
        $price = (float)$r['price'];
        $items[] = [
            'id' => $pid,
            'name' => (string)$r['name'],
            'price' => $price,
            'image' => (string)($r['image'] ?? ''),
            'qty' => $qty,
            'sum' => $price * $qty,
        ];
    }
}
if (!$items) {
    header('Location: cart.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

$error = null;
$allServices = extra_services_fetch_all($pdo, true);
$savedIds = normalize_service_ids($_SESSION['checkout_extra_service_ids'] ?? []);
$savedMessages = normalize_service_messages($_SESSION['checkout_extra_service_messages'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            throw new RuntimeException('Сессия устарела. Обновите страницу.');
        }
        $action = (string)($_POST['action'] ?? 'next');
        if ($action === 'skip') {
            unset($_SESSION['checkout_extra_service_ids'], $_SESSION['checkout_extra_service_messages']);
            header('Location: checkout.php');
            exit;
        }

        $selectedIds = normalize_service_ids($_POST['services'] ?? []);
        $selectedRows = extra_services_fetch_selected($pdo, $selectedIds);
        $allowed = [];
        foreach ($selectedRows as $row) {
            $allowed[(int)$row['id']] = $row;
        }
        $selectedIds = array_keys($allowed);
        sort($selectedIds);

        $messagesRaw = normalize_service_messages($_POST['service_message'] ?? []);
        $messages = [];
        foreach ($selectedIds as $sid) {
            $service = $allowed[$sid];
            if (!empty($service['allow_message'])) {
                $messages[$sid] = trim((string)($messagesRaw[$sid] ?? ''));
            }
        }

        $_SESSION['checkout_extra_service_ids'] = $selectedIds;
        $_SESSION['checkout_extra_service_messages'] = $messages;
        header('Location: checkout.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $savedIds = normalize_service_ids($_POST['services'] ?? []);
        $savedMessages = normalize_service_messages($_POST['service_message'] ?? []);
    }
}

$selectedRows = extra_services_fetch_selected($pdo, $savedIds);
$selectedMap = [];
foreach ($selectedRows as $row) {
    $selectedMap[(int)$row['id']] = $row;
}
$extraSubtotal = 0.0;
foreach ($selectedRows as $row) {
    $extraSubtotal += (float)$row['price'];
}
$productSubtotal = (float)array_sum(array_column($items, 'sum'));
$total = $productSubtotal + $extraSubtotal;
$placeholder = placeholder_image();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Дополнительные услуги — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .services-step{padding-top:10px}
    .services-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:18px;align-items:start}
    @media (max-width:1020px){.services-layout{grid-template-columns:1fr}}
    .services-box{background:#fff;border:1px solid #e6ece8;border-radius:20px;box-shadow:var(--shadow-1);padding:18px}
    .services-note{margin:0 0 18px;color:#667085;line-height:1.7}
    .services-error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;padding:12px 14px;border-radius:14px;margin-bottom:14px}
    .services-list{display:grid;gap:14px}
    .service-row{display:grid;grid-template-columns:150px minmax(0,1fr) 26px;gap:16px;align-items:stretch;padding:12px;border:1px solid #e6ece8;border-radius:22px;background:#fff;transition:.18s ease;box-shadow:var(--shadow-1)}
    .service-row:hover{transform:translateY(-1px)}
    .service-row.is-selected{border-color:#8bc49a;box-shadow:0 0 0 3px rgba(139,196,154,.16), var(--shadow-1)}
    .service-row__img{width:100%;height:100%;min-height:136px;object-fit:cover;border-radius:16px;border:1px solid #eef3f0;background:#f8fafc}
    .service-row__content{display:grid;gap:10px;min-width:0}
    .service-row__head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
    .service-row__title{font-size:22px;font-weight:800;line-height:1.2;color:#0f172a;margin:0}
    .service-row__desc{margin:0;color:#6b7280;line-height:1.65;font-size:15px}
    .service-row__price{font-size:24px;font-weight:900;color:#0f6b45;white-space:nowrap}
    .service-row__check{display:flex;align-items:flex-start;justify-content:flex-end;padding-top:6px}
    .service-row__check input{width:22px;height:22px;accent-color:#1f7a4f}
    .service-row__message{display:none;gap:8px}
    .service-row__message.is-visible{display:grid}
    .service-row__message-label{font-size:14px;font-weight:700;color:#667085}
    .service-row__meta{display:flex;gap:10px;flex-wrap:wrap}
    .service-pill{display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;background:#f5fbf7;border:1px solid #d7eadf;color:#185a37;font-size:12px;font-weight:800}
    .services-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}
    .order-item{display:grid;grid-template-columns:64px 1fr auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #eef3f0}
    .order-item:last-child{border-bottom:none}
    .order-item__img{width:64px;height:64px;border-radius:14px;object-fit:cover;border:1px solid #edf1ef;background:#f8fafc}
    .order-item__name{font-weight:800;font-size:15px;color:#111827}
    .order-item__meta{margin-top:4px;color:#6b7280;font-size:13px}
    .order-item__sum{font-weight:800;font-size:15px;white-space:nowrap}
    .order-line,.order-total{display:flex;justify-content:space-between;gap:12px}
    .order-line{padding:6px 0;color:#374151}
    .order-total{padding-top:14px;margin-top:8px;border-top:1px solid #eef3f0;font-weight:900;font-size:22px}
    .selected-extra-list{display:grid;gap:10px;margin-top:10px}
    .selected-extra{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px dashed #e5ebe7}
    .selected-extra:first-child{border-top:none}
    .selected-extra__name{font-weight:800;color:#111827}
    .selected-extra__meta{margin-top:4px;color:#6b7280;font-size:13px;line-height:1.5}
    .selected-extra__sum{font-weight:800;white-space:nowrap}
    .services-tip{margin-top:14px;padding:14px;border-radius:16px;background:#fff8f2;border:1px solid #fed7aa;color:#9a3412;line-height:1.6}
    @media (max-width:760px){
      .service-row{grid-template-columns:1fr}
      .service-row__img{min-height:180px}
      .service-row__head{flex-direction:column;align-items:flex-start}
      .service-row__price{font-size:20px}
      .service-row__check{padding-top:0;justify-content:flex-start}
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section services-step">
  <div class="container">
    <h1 class="section__title">Дополнительные услуги</h1>
    <p class="section__sub" style="margin:10px 0 24px;">Сначала можно выбрать открытку, шоколадку, шарики и другие дополнения. Этот шаг можно пропустить.</p>

    <?php if ($error): ?><div class="services-error"><?= h($error) ?></div><?php endif; ?>

    <div class="services-layout">
      <div class="services-box">
        <?php if ($allServices): ?>
          <form method="post" id="servicesForm">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <p class="services-note">Выберите всё, что хотите добавить к заказу. Для открытки можно сразу вписать текст, чтобы не возвращаться к этому позже.</p>

            <div class="services-list">
              <?php foreach ($allServices as $service): ?>
                <?php
                  $sid = (int)$service['id'];
                  $checked = isset($selectedMap[$sid]);
                  $allowMessage = !empty($service['allow_message']);
                  $imageSrc = extra_services_image_src($service['image'] ?? '', (string)$service['name']);
                  $messageValue = $savedMessages[$sid] ?? '';
                ?>
                <label class="service-row<?= $checked ? ' is-selected' : '' ?>"
                       data-service-row
                       data-service-id="<?= $sid ?>"
                       data-service-name="<?= h($service['name']) ?>"
                       data-service-price="<?= (float)($service['price'] ?? 0) ?>"
                       data-service-allow-message="<?= $allowMessage ? '1' : '0' ?>">
                  <img class="service-row__img" src="<?= h($imageSrc) ?>" alt="<?= h($service['name']) ?>">

                  <div class="service-row__content">
                    <div class="service-row__head">
                      <div>
                        <h3 class="service-row__title"><?= h($service['name']) ?></h3>
                        <p class="service-row__desc"><?= h((string)($service['description'] ?? '')) ?></p>
                      </div>
                      <div class="service-row__price">+<?= number_format((float)($service['price'] ?? 0), 0, ',', ' ') ?> ₽</div>
                    </div>

                    <div class="service-row__meta">
                      <span class="service-pill">Дополнение к заказу</span>
                      <?php if ($allowMessage): ?><span class="service-pill">Можно добавить текст</span><?php endif; ?>
                    </div>

                    <?php if ($allowMessage): ?>
                      <div class="service-row__message<?= $checked ? ' is-visible' : '' ?>" data-service-message>
                        <label class="service-row__message-label" for="service_message_<?= $sid ?>">Что написать на открытке</label>
                        <textarea class="input" id="service_message_<?= $sid ?>" name="service_message[<?= $sid ?>]" rows="3" style="padding-top:12px;padding-bottom:12px;border-radius:16px;resize:vertical" placeholder="Текст для получателя"><?= h($messageValue) ?></textarea>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="service-row__check">
                    <input type="checkbox" name="services[]" value="<?= $sid ?>" <?= $checked ? 'checked' : '' ?> data-service-checkbox>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

            <div class="services-tip">После выбора нажмите «Дальше», и откроется страница доставки или самовывоза. На следующем шаге дополнительные услуги повторно предлагаться не будут.</div>

            <div class="services-actions">
              <button class="btn" type="submit" name="action" value="next">Дальше</button>
              <button class="btn btn--ghost" type="submit" name="action" value="skip">Пропустить</button>
              <a class="btn btn--ghost" href="cart.php">Назад в корзину</a>
            </div>
          </form>
        <?php else: ?>
          <p class="services-note" style="margin-bottom:16px">Дополнительные услуги пока не добавлены. Можно сразу перейти к оформлению заказа.</p>
          <div class="services-actions">
            <a class="btn" href="checkout.php">Дальше</a>
            <a class="btn btn--ghost" href="cart.php">Назад в корзину</a>
          </div>
        <?php endif; ?>
      </div>

      <aside class="services-box">
        <h2 style="margin:0 0 10px;font-size:20px">Ваш заказ</h2>
        <?php foreach ($items as $it): ?>
          <div class="order-item">
            <img class="order-item__img" src="<?= h($it['image'] ?: $placeholder) ?>" alt="<?= h($it['name']) ?>" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';">
            <div>
              <div class="order-item__name"><?= h($it['name']) ?></div>
              <div class="order-item__meta"><?= (int)$it['qty'] ?> × <?= number_format((float)$it['price'], 0, ',', ' ') ?> ₽</div>
            </div>
            <div class="order-item__sum"><?= number_format((float)$it['sum'], 0, ',', ' ') ?> ₽</div>
          </div>
        <?php endforeach; ?>

        <div id="selectedExtrasBox" class="selected-extra-list">
          <?php foreach ($selectedRows as $service): ?>
            <?php $sid = (int)$service['id']; ?>
            <div class="selected-extra" data-selected-service="<?= $sid ?>">
              <div>
                <div class="selected-extra__name"><?= h($service['name']) ?></div>
                <div class="selected-extra__meta"><?= !empty($service['allow_message']) && !empty($savedMessages[$sid]) ? 'Текст добавлен' : 'Дополнение к заказу' ?></div>
              </div>
              <div class="selected-extra__sum">+<?= number_format((float)$service['price'], 0, ',', ' ') ?> ₽</div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="order-line"><span>Товары</span><strong id="productSubtotalValue"><?= number_format($productSubtotal, 0, ',', ' ') ?> ₽</strong></div>
        <div class="order-line"><span>Доп. услуги</span><strong id="extraSubtotalValue"><?= number_format($extraSubtotal, 0, ',', ' ') ?> ₽</strong></div>
        <div class="order-total"><span>Предварительно</span><span id="grandTotalValue"><?= number_format($total, 0, ',', ' ') ?> ₽</span></div>
      </aside>
    </div>
  </div>
</section>
<script>
(function(){
  const productSubtotal = <?= json_encode((float)$productSubtotal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const rows = Array.from(document.querySelectorAll('[data-service-row]'));
  const selectedExtrasBox = document.getElementById('selectedExtrasBox');
  const extraSubtotalValue = document.getElementById('extraSubtotalValue');
  const grandTotalValue = document.getElementById('grandTotalValue');
  const formatter = new Intl.NumberFormat('ru-RU');

  function formatMoney(value){
    return formatter.format(Math.max(0, Math.round(Number(value) || 0))) + ' ₽';
  }

  function syncRow(row){
    const checkbox = row.querySelector('[data-service-checkbox]');
    const messageWrap = row.querySelector('[data-service-message]');
    const active = !!(checkbox && checkbox.checked);
    row.classList.toggle('is-selected', active);
    if (messageWrap) {
      messageWrap.classList.toggle('is-visible', active);
      messageWrap.querySelectorAll('textarea').forEach(function(el){ el.disabled = !active; });
    }
  }

  function buildSelectedExtra(row){
    const checkbox = row.querySelector('[data-service-checkbox]');
    if (!checkbox || !checkbox.checked) return '';
    const name = row.dataset.serviceName || '';
    const price = Number(row.dataset.servicePrice || 0);
    const allowMessage = row.dataset.serviceAllowMessage === '1';
    let meta = 'Дополнение к заказу';
    if (allowMessage) {
      const textarea = row.querySelector('textarea');
      if (textarea && textarea.value.trim() !== '') meta = 'Текст добавлен';
      else meta = 'Текст можно добавить';
    }
    return '<div class="selected-extra">'
      + '<div><div class="selected-extra__name">' + escapeHtml(name) + '</div><div class="selected-extra__meta">' + escapeHtml(meta) + '</div></div>'
      + '<div class="selected-extra__sum">+' + formatMoney(price).replace(' ₽', '') + ' ₽</div>'
      + '</div>';
  }

  function escapeHtml(text){
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderSummary(){
    let extraSubtotal = 0;
    let html = '';
    rows.forEach(function(row){
      const checkbox = row.querySelector('[data-service-checkbox]');
      if (checkbox && checkbox.checked) {
        extraSubtotal += Number(row.dataset.servicePrice || 0);
        html += buildSelectedExtra(row);
      }
    });
    selectedExtrasBox.innerHTML = html;
    extraSubtotalValue.textContent = formatMoney(extraSubtotal);
    grandTotalValue.textContent = formatMoney(productSubtotal + extraSubtotal);
  }

  rows.forEach(function(row){
    const checkbox = row.querySelector('[data-service-checkbox]');
    const textarea = row.querySelector('textarea');
    if (checkbox) {
      checkbox.addEventListener('change', function(){
        syncRow(row);
        renderSummary();
      });
      syncRow(row);
    }
    if (textarea) {
      textarea.addEventListener('input', renderSummary);
    }
  });

  renderSummary();
})();
</script>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
