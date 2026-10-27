<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/favorites_lib.php';
require_once __DIR__ . '/product_admin_lib.php';
require_once __DIR__ . '/cart_store.php';

$pdo = db();
pa_ensure_products_discount_columns($pdo);
favorites_init($pdo);
cart_store_sync();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$me     = $_SESSION['user'] ?? null;
$userId = (int)($me['id'] ?? 0);

// Товары со скидкой: old_price заполнен и больше текущей цены
try {
    $rows = $pdo->query("
        SELECT id, name, price, old_price, image, description, category
        FROM products
        WHERE COALESCE(is_active, 1) = 1
          AND old_price IS NOT NULL
          AND old_price > price
          AND old_price > 0
        ORDER BY (old_price - price) DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}

// Добавляем процент скидки
foreach ($rows as &$p) {
    $p['discount_pct'] = $p['old_price'] > 0
        ? (int)round((1 - $p['price'] / $p['old_price']) * 100)
        : 0;
}
unset($p);

$favMap     = $userId ? favorites_map($pdo, $userId) : [];
$placeholder = favorites_placeholder_image();

// Активные промокоды (публичные — только те, что не single_use_per_user и не закончились)
try {
    $promos = $pdo->query("
        SELECT code, title, discount_type, discount_value, min_order_sum, ends_at, delivery_method
        FROM promocodes
        WHERE active = TRUE
          AND (ends_at IS NULL OR ends_at > NOW())
          AND (usage_limit IS NULL OR usage_limit <= 0 OR used_count < usage_limit)
          AND COALESCE(single_use_per_user, FALSE) = FALSE
        ORDER BY discount_value DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $promos = [];
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Акции и скидки — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Герой */
    .sales-hero {
      background: linear-gradient(135deg, #fff0f4 0%, #fff8e1 100%);
      border: 1px solid #fce7f3;
      border-radius: 24px;
      padding: 36px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
      overflow: hidden;
      position: relative;
    }
    .sales-hero__text { flex: 1; min-width: 0; }
    .sales-hero__badge {
      display: inline-block;
      background: #e11d48;
      color: #fff;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      padding: 4px 12px;
      border-radius: 999px;
      margin-bottom: 12px;
    }
    .sales-hero__title { margin: 0 0 8px; font-size: 36px; font-weight: 900; color: #111827; line-height: 1.15; }
    .sales-hero__sub   { margin: 0; color: #6b7280; font-size: 16px; line-height: 1.6; }
    .sales-hero__deco  {
      font-size: 80px;
      line-height: 1;
      user-select: none;
      opacity: .85;
      flex-shrink: 0;
    }

    /* Промокоды */
    .promo-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 14px;
    }
    .promo-card {
      background: #fff;
      border: 1.5px dashed #d1d5db;
      border-radius: 18px;
      padding: 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      transition: border-color .18s, box-shadow .18s;
    }
    .promo-card:hover { border-color: #6b21a8; box-shadow: 0 4px 18px rgba(107,33,168,.10); }
    .promo-card__code {
      font-size: 22px;
      font-weight: 900;
      letter-spacing: .08em;
      color: #6b21a8;
      font-family: monospace;
      background: #f3e8ff;
      border-radius: 10px;
      padding: 6px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      cursor: pointer;
      user-select: all;
    }
    .promo-card__copy {
      font-size: 11px;
      font-weight: 700;
      color: #9333ea;
      background: transparent;
      border: none;
      cursor: pointer;
      padding: 0;
      opacity: .7;
      white-space: nowrap;
    }
    .promo-card__copy:hover { opacity: 1; }
    .promo-card__discount { font-weight: 800; font-size: 18px; color: #e11d48; }
    .promo-card__desc     { color: #6b7280; font-size: 13px; line-height: 1.5; }
    .promo-card__expire   { font-size: 12px; color: #9ca3af; }
    .promo-card__tag {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      color: #6b7280;
      background: #f3f4f6;
      border-radius: 999px;
      padding: 2px 10px;
    }
    .copy-toast {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%) translateY(20px);
      background: #1f2937;
      color: #fff;
      padding: 10px 22px;
      border-radius: 999px;
      font-size: 14px;
      font-weight: 600;
      opacity: 0;
      pointer-events: none;
      transition: opacity .22s, transform .22s;
      z-index: 9999;
    }
    .copy-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

    /* Товары со скидкой */
    .sale-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 18px;
    }
    .sale-card {
      background: #fff;
      border: 1px solid #eef2f7;
      border-radius: 20px;
      box-shadow: var(--shadow-1);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      position: relative;
      transition: box-shadow .2s, transform .15s;
    }
    .sale-card:hover { box-shadow: 0 8px 32px rgba(0,0,0,.10); transform: translateY(-2px); }
    .sale-card__badge {
      position: absolute;
      top: 12px;
      left: 12px;
      background: #e11d48;
      color: #fff;
      font-size: 13px;
      font-weight: 800;
      padding: 3px 10px;
      border-radius: 999px;
      z-index: 2;
    }
    .sale-card__fav {
      position: absolute;
      top: 10px;
      right: 10px;
      width: 34px;
      height: 34px;
      background: rgba(255,255,255,.88);
      border: none;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #9ca3af;
      transition: color .15s, background .15s;
      z-index: 2;
    }
    .sale-card__fav:hover, .sale-card__fav.is-fav { color: #e11d48; }
    .sale-card__fav svg { width: 18px; height: 18px; }
    .sale-card__img {
      width: 100%;
      aspect-ratio: 4/3;
      object-fit: cover;
      background: #f8fafc;
      display: block;
    }
    .sale-card__body { padding: 14px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
    .sale-card__name { margin: 0; font-size: 16px; font-weight: 700; color: #111827; line-height: 1.3; }
    .sale-card__desc { margin: 0; font-size: 13px; color: #6b7280; line-height: 1.5;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .sale-card__prices { display: flex; align-items: baseline; gap: 8px; margin-top: auto; padding-top: 8px; }
    .sale-card__price-new  { font-size: 20px; font-weight: 900; color: #e11d48; }
    .sale-card__price-old  { font-size: 14px; color: #9ca3af; text-decoration: line-through; }
    .sale-card__actions { padding: 0 14px 14px; display: flex; gap: 8px; }

    .section-label {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: #9ca3af;
      margin-bottom: 10px;
    }
    .empty-sales {
      background: #fff;
      border: 1px solid #eef2f7;
      border-radius: 18px;
      padding: 40px;
      text-align: center;
      color: #6b7280;
    }

    @media (max-width: 600px) {
      .sales-hero { flex-direction: column; align-items: flex-start; padding: 22px 18px; }
      .sales-hero__deco { display: none; }
      .sales-hero__title { font-size: 26px; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section">
  <div class="container" style="display:grid;gap:32px;">

    <!-- Герой -->
    <div class="sales-hero">
      <div class="sales-hero__text">
        <span class="sales-hero__badge">Горячие предложения</span>
        <h1 class="sales-hero__title">Акции и скидки</h1>
        <p class="sales-hero__sub">Выгодные цены на букеты и промокоды для заказа с доставкой.</p>
      </div>
      <div class="sales-hero__deco">🌷</div>
    </div>

    <?php if ($promos): ?>
    <!-- Промокоды -->
    <div>
      <div class="section-label">Промокоды</div>
      <div class="promo-grid">
        <?php foreach ($promos as $pr):
          $discountLabel = $pr['discount_type'] === 'fixed'
            ? '-' . number_format((float)$pr['discount_value'], 0, ',', ' ') . ' ₽'
            : '-' . rtrim(rtrim(number_format((float)$pr['discount_value'], 1, ',', '.'), '0'), ',') . '%';
          $delivLabel = match($pr['delivery_method'] ?? 'any') {
            'delivery' => 'Только доставка',
            'pickup'   => 'Только самовывоз',
            default    => 'Любой заказ',
          };
          $hasExpire = !empty($pr['ends_at']);
          $expireStr = $hasExpire ? date('d.m.Y', strtotime((string)$pr['ends_at'])) : null;
        ?>
          <div class="promo-card">
            <div class="promo-card__code" onclick="copyPromo('<?= h($pr['code']) ?>', this)">
              <span><?= h($pr['code']) ?></span>
              <button class="promo-card__copy" type="button">Скопировать</button>
            </div>
            <div class="promo-card__discount"><?= h($discountLabel) ?></div>
            <?php if (!empty($pr['title'])): ?>
              <div class="promo-card__desc"><?= h($pr['title']) ?></div>
            <?php endif; ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
              <span class="promo-card__tag"><?= h($delivLabel) ?></span>
              <?php if ((float)($pr['min_order_sum'] ?? 0) > 0): ?>
                <span class="promo-card__tag">от <?= number_format((float)$pr['min_order_sum'], 0, ',', ' ') ?> ₽</span>
              <?php endif; ?>
            </div>
            <?php if ($expireStr): ?>
              <div class="promo-card__expire">До <?= h($expireStr) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Товары со скидкой -->
    <div>
      <div class="section-label">Товары со скидкой</div>
      <?php if (!$rows): ?>
        <div class="empty-sales">
          <p style="margin:0 0 12px;font-size:18px;font-weight:700;">Акций пока нет</p>
          <p style="margin:0 0 16px;">Следите за обновлениями — скоро появятся выгодные предложения.</p>
          <a class="btn" href="catalog.php">Посмотреть каталог</a>
        </div>
      <?php else: ?>
        <div class="sale-grid">
          <?php foreach ($rows as $p):
            $imgSrc = trim((string)($p['image'] ?? ''));
            $imgSrc = $imgSrc !== '' ? $imgSrc : $placeholder;
            $isFav  = !empty($favMap[(int)$p['id']]);
          ?>
            <div class="sale-card">
              <span class="sale-card__badge">-<?= (int)$p['discount_pct'] ?>%</span>

              <button
                class="sale-card__fav<?= $isFav ? ' is-fav' : '' ?>"
                type="button"
                data-id="<?= (int)$p['id'] ?>"
                onclick="toggleFav(this, <?= (int)$p['id'] ?>)"
                title="<?= $isFav ? 'Убрать из избранного' : 'В избранное' ?>"
              >
                <svg viewBox="0 0 24 24" fill="<?= $isFav ? 'currentColor' : 'none' ?>" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>

              <a href="product.php?id=<?= (int)$p['id'] ?>">
                <img
                  class="sale-card__img"
                  src="<?= h($imgSrc) ?>"
                  alt="<?= h($p['name']) ?>"
                  onerror="this.src='<?= h($placeholder) ?>'"
                  loading="lazy"
                >
              </a>

              <div class="sale-card__body">
                <h2 class="sale-card__name">
                  <a href="product.php?id=<?= (int)$p['id'] ?>" style="color:inherit;text-decoration:none;">
                    <?= h($p['name']) ?>
                  </a>
                </h2>
                <?php if (trim((string)($p['description'] ?? '')) !== ''): ?>
                  <p class="sale-card__desc"><?= h($p['description']) ?></p>
                <?php endif; ?>
                <div class="sale-card__prices">
                  <span class="sale-card__price-new"><?= number_format((float)$p['price'], 0, ',', ' ') ?> ₽</span>
                  <span class="sale-card__price-old"><?= number_format((float)$p['old_price'], 0, ',', ' ') ?> ₽</span>
                </div>
              </div>

              <div class="sale-card__actions">
                <button
                  class="btn btn--sm"
                  style="flex:1"
                  type="button"
                  onclick="addToCart(<?= (int)$p['id'] ?>, this)"
                >В корзину</button>
                <a class="btn btn--ghost btn--sm" href="product.php?id=<?= (int)$p['id'] ?>">Подробнее</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</section>

<div class="copy-toast" id="copyToast">Промокод скопирован!</div>

<script>
async function addToCart(id, btn) {
  const orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = '...';
  try {
    const res = await fetch('cart_api.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'add', id, qty: 1 }),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Ошибка');
    btn.textContent = 'Добавлено ✓';
    // Обновляем счётчик корзины в хедере
    const badge = document.getElementById('cartCount');
    if (badge && data.count != null) badge.textContent = data.count;
    const badgeMob = document.getElementById('cartCountMob');
    if (badgeMob && data.count != null) badgeMob.textContent = data.count;
    setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 1800);
  } catch (e) {
    btn.textContent = orig;
    btn.disabled = false;
    alert(e.message);
  }
}

function toggleFav(btn, id) {
  const isFav = btn.classList.contains('is-fav');
  const action = isFav ? 'remove' : 'add';
  fetch('favorite_api.php', {
    method: 'POST',
    body: new URLSearchParams({ action, product_id: id }),
  })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      btn.classList.toggle('is-fav', !isFav);
      const svg = btn.querySelector('path');
      if (svg) svg.setAttribute('fill', !isFav ? 'currentColor' : 'none');
      btn.title = !isFav ? 'Убрать из избранного' : 'В избранное';
      const cnt = document.getElementById('favCount');
      if (cnt && data.count != null) cnt.textContent = data.count;
    })
    .catch(() => {});
}

function copyPromo(code, el) {
  navigator.clipboard.writeText(code).then(() => {
    const toast = document.getElementById('copyToast');
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2200);
  }).catch(() => {
    // fallback для старых браузеров
    const inp = document.createElement('input');
    inp.value = code;
    document.body.appendChild(inp);
    inp.select();
    document.execCommand('copy');
    document.body.removeChild(inp);
    const toast = document.getElementById('copyToast');
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2200);
  });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
