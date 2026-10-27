<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/favorites_lib.php';
$pdo = db();
favorites_init($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me) { header('Location: auth.php?mode=login'); exit; }
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

$userId = (int)$me['id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message = 'Не удалось проверить форму. Обновите страницу.';
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        if ($productId > 0 && $action === 'remove') {
            favorites_remove($pdo, $userId, $productId);
            $message = 'Товар убран из избранного.';
        } elseif ($productId > 0 && $action === 'cart') {
            $_SESSION['cart'][$productId] = ($_SESSION['cart'][$productId] ?? 0) + 1;
            $message = 'Товар добавлен в корзину.';
        }
    }
}

$items = favorites_items($pdo, $userId);
$placeholder = favorites_placeholder_image();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Избранное — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .lk-wrap{max-width:860px;margin:0 auto;display:grid;gap:16px}
    .lk-back{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:14px;text-decoration:none}
    .lk-back:hover{color:#0f172a}
    .lk-tabs{display:flex;gap:4px;background:#fff;border:1px solid #e8edf2;border-radius:14px;padding:6px;box-shadow:0 2px 8px rgba(15,23,42,.05);flex-wrap:wrap}
    .lk-tab{padding:8px 16px;border-radius:10px;color:#6b7280;text-decoration:none;font-size:14px;font-weight:600;transition:.15s ease}
    .lk-tab:hover{background:#f3f6f4;color:#0f172a}
    .lk-tab.active{background:var(--pistachio-soft);color:#0f172a;border:1px solid #cfe9d9}
    .acc-box{background:#fff;border:1px solid var(--surface-2);border-radius:16px;padding:16px;box-shadow:var(--shadow-1)}
    .fav-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}
    .fav-card{background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:12px;display:flex;flex-direction:column;box-shadow:var(--shadow-1)}
    .fav-img-wrap{position:relative;display:block}
    .fav-card img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:14px;border:1px solid #eef2f7;background:#f3f4f6}
    .fav-heart-btn{position:absolute;top:10px;right:10px;width:38px;height:38px;border-radius:50%;border:none;background:rgba(225,29,72,.85);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(225,29,72,.35);transition:.18s ease;padding:0}
    .fav-heart-btn:hover{transform:scale(1.1);background:rgba(225,29,72,1)}
    .fav-heart-btn svg{width:18px;height:18px;display:block;color:#fff}
    .fav-banner{position:relative;border-radius:16px;overflow:hidden;margin-bottom:16px;height:180px}
    .fav-banner img{width:100%;height:100%;object-fit:cover;display:block}
    .fav-banner__overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(12,74,52,.75) 0%,rgba(6,40,28,.4) 100%);display:flex;flex-direction:column;justify-content:flex-end;padding:24px 28px}
    .fav-banner__title{color:#fff;font-size:26px;font-weight:800;margin:0 0 4px}
    .fav-banner__sub{color:rgba(255,255,255,.8);margin:0;font-size:14px}
    .fav-title{margin:10px 0 6px;font-size:24px;font-weight:800;color:#111827;text-decoration:none}
    .fav-desc{margin:0 0 10px;color:#6b7280;line-height:1.6;font-size:14px}
    .fav-price{font-size:18px;font-weight:800;color:#0f6b45;margin:0 0 12px}
    .fav-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto}
    .msg{padding:12px 14px;border-radius:14px;background:#ecfdf3;border:1px solid #c9f1d8;color:#166534;margin-bottom:14px;font-weight:600}
    @media (max-width:900px){.account{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php include __DIR__.'/header.php'; ?>
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
        <a class="lk-tab" href="account_orders.php">Заказы</a>
        <a class="lk-tab" href="account_profile.php">Профиль</a>
        <a class="lk-tab active" href="account_favorites.php">Избранное</a>
        <a class="lk-tab" href="account_addresses.php">Адреса</a>
      </nav>
      <main>
      <div class="fav-banner">
        <img src="img/photo_1_2026-04-19_13-48-56.jpg" alt="Избранное">
        <div class="fav-banner__overlay">
          <p class="fav-banner__title">Избранное</p>
          <p class="fav-banner__sub">Сохранённые товары, к которым хочется вернуться</p>
        </div>
      </div>
      <div class="acc-box">
        <?php if ($message !== ''): ?><div class="msg"><?= h($message) ?></div><?php endif; ?>
        <?php if (!$items): ?>
          <p class="mute" style="margin:0 0 12px">У вас пока нет избранных товаров.</p>
          <a class="btn" href="catalog.php">Перейти в каталог</a>
        <?php else: ?>
          <div class="fav-grid">
            <?php foreach ($items as $p): 
              $img = favorites_image_src($p['image'] ?? '', $placeholder);
              $desc = trim((string)($p['description'] ?? ''));
              if ($desc === '') $desc = 'Перейдите в карточку товара, чтобы посмотреть детали и быстро оформить заказ.';
              if (function_exists('mb_strlen') && mb_strlen($desc,'UTF-8') > 110) $desc = mb_substr($desc,0,110,'UTF-8').'…';
              elseif (strlen($desc) > 110) $desc = substr($desc,0,110).'…';
            ?>
            <article class="fav-card" data-fav-card="<?= (int)$p['id'] ?>">
              <div class="fav-img-wrap">
                <a href="product.php?id=<?= (int)$p['id'] ?>"><img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>"></a>
                <button type="button"
                        class="fav-heart-btn"
                        data-fav-toggle
                        data-product-id="<?= (int)$p['id'] ?>"
                        aria-label="Убрать из избранного"
                        title="Убрать из избранного">
                  <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </button>
              </div>
              <a class="fav-title" href="product.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a>
              <p class="fav-desc"><?= h($desc) ?></p>
              <div class="fav-price"><?= number_format((float)($p['price'] ?? 0), 0, ',', ' ') ?> ₽</div>
              <div class="fav-actions">
                <a class="btn btn--ghost btn--sm" href="product.php?id=<?= (int)$p['id'] ?>">Подробнее</a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn--sm" type="submit" name="action" value="cart">В корзину</button>
                </form>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      </main>
    </div>
  </div>
</section>
<?php include __DIR__.'/footer.php'; ?>
<script>
document.querySelectorAll('[data-fav-toggle]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const productId = btn.dataset.productId;
    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('action', 'toggle');
      fd.append('product_id', productId);
      const res = await fetch('favorite_api.php', {method:'POST', body:fd});
      const data = await res.json();
      if (data.ok && !data.active) {
        const card = document.querySelector('[data-fav-card="' + productId + '"]');
        if (card) card.remove();
        const favCount = document.getElementById('favCount');
        if (favCount && typeof data.count !== 'undefined') favCount.textContent = data.count;
        const topFav = document.querySelector('.fe-fav-link');
        if (topFav) topFav.classList.toggle('is-active', Number(data.count || 0) > 0);
        const grid = document.querySelector('.fav-grid');
        if (grid && !grid.children.length) {
          grid.closest('.acc-box').innerHTML = '<p class="mute" style="margin:0 0 12px">У вас пока нет избранных товаров.</p><a class="btn" href="catalog.php">Перейти в каталог</a>';
        }
      }
    } catch(_) {}
    finally { btn.disabled = false; }
  });
});
</script>
</body>
</html>
