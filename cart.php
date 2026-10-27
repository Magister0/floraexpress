<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/promo_lib.php';
require_once __DIR__ . '/cart_store.php';
require_once __DIR__ . '/pricing_lib.php';

$pdo = db();
promo_ensure_schema($pdo);
cart_store_sync();

$cart = cart_store_get();
$ids = array_map('intval', array_keys($cart));
$items = [];

if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, price, old_price, image, description, category FROM products WHERE id IN ($in) ORDER BY id DESC");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $pid = (int)$r['id'];
        $qty = max(0, (int)($cart[$pid] ?? 0));
        if ($qty <= 0) continue;
        $priceView = pricing_view($r, $pdo);
        $price = (float)$priceView['price'];
        $items[] = [
            'id' => $pid,
            'name' => (string)$r['name'],
            'description' => trim((string)($r['description'] ?? '')),
            'price' => $price,
            'base_price' => (float)$priceView['base'],
            'badge_html' => (string)$priceView['badge_html'],
            'image' => trim((string)($r['image'] ?? '')),
            'qty' => $qty,
            'sum' => $price * $qty,
        ];
    }
}

if (!$items) {
    unset($_SESSION['checkout_extra_service_ids'], $_SESSION['checkout_extra_service_messages']);
}

$total = (float)array_sum(array_column($items, 'sum'));
$placeholder = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="12" fill="#9ca3af">Нет изображения</text></svg>');
$userId = (int)(($_SESSION['user']['id'] ?? 0));

$promoMessage = null;
$promoError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['promo_action'] ?? '');
    if ($action === 'apply') {
        $code = promo_normalize_code((string)($_POST['promo_code'] ?? ''));
        if ($code === '') {
            $promoError = 'Введите промокод';
        } else {
            $promo = promo_fetch_by_code($pdo, $code);
            if (!$promo) {
                $promoError = 'Промокод не найден';
            } else {
                $calc = promo_validate($pdo, $promo, $total, (string)($_SESSION['checkout_delivery_method'] ?? 'delivery'), $userId);
                if (!$calc['ok']) {
                    $promoError = implode(' ', $calc['errors']);
                } else {
                    $_SESSION['promo_code'] = $code;
                    $promoMessage = 'Промокод применён';
                }
            }
        }
    } elseif ($action === 'remove') {
        unset($_SESSION['promo_code']);
        $promoMessage = 'Промокод удалён';
    }
}

$promoCalc = promo_calculate_from_session($pdo, $total, (string)($_SESSION['checkout_delivery_method'] ?? 'delivery'), $userId);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Корзина — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .cart-layout{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:22px;align-items:start}
    .cart-list{display:grid;gap:14px}
    .cart-item{display:grid;grid-template-columns:92px minmax(0,1fr) auto auto;gap:14px;align-items:center;background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:14px}
    .cart-item__img{width:92px;height:92px;border-radius:14px;object-fit:cover;border:1px solid #eef2f7;background:#f8fafc}
    .cart-item__title{margin:0 0 6px;font-size:20px}
    .cart-item__desc{margin:0;color:#6b7280;font-size:14px;line-height:1.5;max-width:420px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .cart-item__meta{display:grid;gap:6px;justify-items:end;min-width:120px}
    .cart-item__price{font-weight:700;color:#111827}
    .cart-item__sum{font-size:20px;font-weight:800;color:#111827}
    .qty{display:inline-flex;align-items:center;gap:8px}
    .qty .input{width:60px;padding:10px 8px;text-align:center}
    .summaryBox{background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:16px;position:sticky;top:96px}
    .summaryTitle{margin:0 0 10px;font-size:24px}
    .promoForm{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;margin-top:10px}
    .summaryLine{display:flex;justify-content:space-between;gap:12px;padding:8px 0;color:#374151}
    .summaryLine strong{color:#111827}
    .summaryLine.is-discount{color:#15803d;font-weight:700}
    .summaryLine.is-total{font-size:24px;font-weight:900;padding-top:14px;margin-top:8px;border-top:1px solid #eef2f7;color:#111827}
    .promoBadge{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-weight:700;font-size:13px}
    .notice-ok,.notice-err{padding:10px 12px;border-radius:12px;font-size:14px;margin-top:10px}
    .notice-ok{background:#ecfdf3;border:1px solid #bbf7d0;color:#166534}
    .notice-err{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
    .cart-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
    .summaryHint{margin-top:12px;padding:12px 14px;border-radius:14px;background:#f8fafc;border:1px dashed #d8e3dc;color:#667085;font-size:14px;line-height:1.55}
    .empty-box{background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:18px}
    @media (max-width:1080px){.cart-layout{grid-template-columns:1fr}.summaryBox{position:static}}
    @media (max-width:760px){.cart-item{grid-template-columns:72px 1fr;align-items:start}.cart-item__img{width:72px;height:72px}.cart-item__meta,.cart-item__controls{grid-column:2}.cart-item__meta{justify-items:start}.cart-item__controls{margin-top:8px}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <h1 class="section__title">Корзина</h1>

    <?php if (!$items): ?>
      <div class="empty-box">
        <p class="mute" style="margin:0 0 12px;">В вашей корзине пока пусто.</p>
        <a class="btn" href="catalog.php">Перейти в каталог</a>
      </div>
    <?php else: ?>
      <div class="cart-layout">
        <div class="cart-list">
          <?php foreach ($items as $it): ?>
            <article class="cart-item" data-id="<?= (int)$it['id'] ?>">
              <img class="cart-item__img" src="<?= h($it['image'] !== '' ? $it['image'] : $placeholder) ?>" alt="<?= h($it['name']) ?>" onerror="this.src='<?= h($placeholder) ?>'">
              <div>
                <h2 class="cart-item__title"><?= h($it['name']) ?></h2>
                <p class="cart-item__desc"><?= h($it['description'] !== '' ? $it['description'] : 'Подробности доступны в карточке товара.') ?></p>
              </div>
              <div class="cart-item__controls">
                <div class="qty">
                  <button class="btn btn--ghost btn--sm" type="button" onclick="chgQty(<?= (int)$it['id'] ?>,-1)">–</button>
                  <input class="input" id="qty-<?= (int)$it['id'] ?>" value="<?= (int)$it['qty'] ?>" inputmode="numeric" onblur="setQty(<?= (int)$it['id'] ?>, this.value)">
                  <button class="btn btn--ghost btn--sm" type="button" onclick="chgQty(<?= (int)$it['id'] ?>,1)">+</button>
                </div>
              </div>
              <div class="cart-item__meta">
                <div class="cart-item__price">
                  <?php if (!empty($it['badge_html']) && abs($it['price'] - $it['base_price']) > 0.01): ?>
                    <span style="text-decoration:line-through;color:#9ca3af;font-weight:600;font-size:12px;margin-right:4px"><?= number_format($it['base_price'], 0, ',', ' ') ?> ₽</span>
                  <?php endif; ?>
                  <?= number_format($it['price'], 0, ',', ' ') ?> ₽ / шт.
                </div>
                <?php if (!empty($it['badge_html'])): ?>
                  <div style="margin-top:2px"><?= $it['badge_html'] ?></div>
                <?php endif; ?>
                <div class="cart-item__sum" id="sum-<?= (int)$it['id'] ?>"><?= number_format($it['sum'], 0, ',', ' ') ?> ₽</div>
                <button class="btn btn--ghost btn--sm" type="button" onclick="removeItem(<?= (int)$it['id'] ?>)">Удалить</button>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <aside class="summaryBox">
          <h2 class="summaryTitle">Итого по корзине</h2>

          <?php if ($promoMessage): ?><div class="notice-ok"><?= h($promoMessage) ?></div><?php endif; ?>
          <?php if ($promoError): ?><div class="notice-err"><?= h($promoError) ?></div><?php endif; ?>

          <?php if ($promoCalc['hasPromo'] ?? false): ?>
            <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
              <span class="promoBadge">Промокод: <?= h((string)$promoCalc['code']) ?></span>
              <form method="post">
                <input type="hidden" name="promo_action" value="remove">
                <button class="btn btn--ghost btn--sm" type="submit">Удалить</button>
              </form>
            </div>
          <?php else: ?>
            <form method="post" class="promoForm">
              <input class="input" type="text" name="promo_code" placeholder="Промокод" autocomplete="off">
              <button class="btn btn--ghost" type="submit" name="promo_action" value="apply">Применить</button>
            </form>
          <?php endif; ?>

          <div class="summaryLine"><span>Товары</span><strong><?= number_format($total, 0, ',', ' ') ?> ₽</strong></div>
          <div class="summaryLine is-discount"><span>Скидка</span><strong>-<?= number_format((float)($promoCalc['discount'] ?? 0), 0, ',', ' ') ?> ₽</strong></div>
          <div class="summaryLine is-total"><span>К оплате</span><strong><?= number_format((float)($promoCalc['total'] ?? $total), 0, ',', ' ') ?> ₽</strong></div>

          <div class="summaryHint">После нажатия на кнопку «Оформить заказ» откроется отдельный шаг с дополнительными услугами. Там можно выбрать открытку, шоколадку, шарики и другие дополнения или сразу перейти дальше.</div>

          <div class="cart-actions">
            <a class="btn" href="checkout_services.php">Оформить заказ</a>
            <a class="btn btn--ghost" href="catalog.php">Добавить ещё</a>
            <button class="btn btn--ghost" type="button" onclick="clearCart()">Очистить</button>
          </div>
        </aside>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
async function api(body){
  const res = await fetch('cart_api.php',{method:'POST', body:new URLSearchParams(body)});
  return res.json();
}
async function chgQty(id,delta){
  const el=document.getElementById('qty-'+id);
  let val=parseInt(el.value||'0',10)+delta;
  val=Math.max(0,val);
  await setQty(id,val);
}
async function setQty(id,val){
  val=Math.max(0,parseInt(val||'0',10));
  const data=await api({action:'set',id,qty:val});
  if(!data.ok) return alert('Ошибка: '+(data.error||''));
  location.reload();
}
async function removeItem(id){
  const data=await api({action:'remove',id});
  if(!data.ok) return alert('Ошибка: '+(data.error||''));
  location.reload();
}
async function clearCart(){
  const data=await api({action:'clear'});
  if(!data.ok) return alert('Ошибка: '+(data.error||''));
  location.reload();
}
</script>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
