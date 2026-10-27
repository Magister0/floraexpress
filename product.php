<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/favorites_lib.php';
require_once __DIR__ . '/cart_store.php';
require_once __DIR__ . '/product_admin_lib.php';
require_once __DIR__ . '/pricing_lib.php';

$pdo = db();
pa_ensure_products_discount_columns($pdo);
favorites_init($pdo);
cart_store_sync();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];
$me = $_SESSION['user'] ?? null;
$userId = (int)($me['id'] ?? 0);
$canPreviewInactive = $me && in_array((string)($me['role'] ?? 'user'), ['admin', 'manager'], true);

function placeholder_image2(): string { return favorites_placeholder_image(); }
function image_src2($path, $ph): string { return favorites_image_src((string)$path, (string)$ph); }
function category_label2($raw): string {
    $x = trim((string)$raw);
    $m = ['roses'=>'Розы','tulips'=>'Тюльпаны','bouquets'=>'Букеты','mini'=>'Мини-букеты','cards'=>'Открытки','balloons'=>'Шарики','other'=>'Другое'];
    return $m[$x] ?? ($x !== '' ? $x : 'Товар');
}
function make_blurb2($name, $desc): string {
    $desc = trim((string)$desc);
    if ($desc !== '') return $desc;
    return trim((string)$name) . ' — аккуратно собранный товар с быстрым оформлением заказа.';
}

$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) { http_response_code(404); echo 'Товар не найден'; exit; }

$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Не удалось проверить форму. Обновите страницу.';
    } else {
        $action = (string)($_POST['action'] ?? 'add');
        $postedId = (int)($_POST['product_id'] ?? 0);
        if ($postedId === $productId) {
            if ($action === 'toggle_favorite') {
                if (!$userId) {
                    header('Location: auth.php?mode=login&return=' . urlencode('product.php?id=' . $productId));
                    exit;
                }
                $added = favorites_toggle($pdo, $userId, $productId);
                $notice = $added ? 'Товар добавлен в избранное.' : 'Товар убран из избранного.';
            } else {
                $qty = max(1, (int)($_POST['qty'] ?? 1));
                cart_store_add($productId, $qty);
                if ($action === 'buy_now') {
                    header('Location: checkout_services.php');
                    exit;
                }
                $notice = 'Товар добавлен в корзину.';
            }
        }
    }
}

// Колонка products.stem_product создаётся через migrate_schema.php.
$st = $pdo->prepare('SELECT id, name, price, old_price, image, description, category, is_active, stem_product FROM products WHERE id = ? LIMIT 1');
$st->execute([$productId]);
$product = $st->fetch(PDO::FETCH_ASSOC);
if (!$product || (((int)($product['is_active'] ?? 0) !== 1) && !$canPreviewInactive)) { http_response_code(404); echo 'Товар не найден'; exit; }

$placeholder = placeholder_image2();
$productImage = image_src2($product['image'] ?? '', $placeholder);
$categoryName = category_label2($product['category'] ?? '');
$description = make_blurb2((string)$product['name'], (string)($product['description'] ?? ''));
$isFavorite = $userId ? favorites_has($pdo, $userId, $productId) : false;
$favMap = $userId ? favorites_map($pdo, $userId) : [];
$rawCat = mb_strtolower(trim((string)($product['category'] ?? '')));
$isBalloon = str_contains($rawCat, 'шар') || str_contains($rawCat, 'balloon');

$related = [];
try {
    $stRel = $pdo->prepare("SELECT id, name, price, old_price, image, description FROM products WHERE id <> ? AND is_active = 1 AND COALESCE(category, '') = COALESCE(?, '') ORDER BY id DESC LIMIT 4");
    $stRel->execute([$productId, $product['category'] ?? '']);
    $related = $stRel->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($product['name']) ?> — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .product-page{display:grid;gap:22px}.topline{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap}.crumbs{display:flex;gap:8px;flex-wrap:wrap;font-size:14px;color:#6b7280;padding-top:8px}.crumbs a{color:#6b7280;text-decoration:none}
    .hero{display:grid;grid-template-columns:minmax(320px,.95fr) minmax(360px,1.05fr);gap:22px}.box{background:#fff;border:1px solid #eef2f7;border-radius:22px;box-shadow:var(--shadow-1)}
    .gallery{padding:16px}
    .gallery__img-wrap{position:relative}
    .gallery__img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:18px;border:1px solid #eef2f7;background:#f8fafc;display:block;cursor:zoom-in;transition:opacity .15s}
    .gallery__img:hover{opacity:.92}
    .gallery__hint{position:absolute;bottom:26px;right:26px;background:rgba(0,0,0,.42);color:#fff;font-size:12px;font-weight:600;padding:5px 11px;border-radius:999px;pointer-events:none;letter-spacing:.03em;backdrop-filter:blur(4px)}
    /* Лайтбокс */
    .lb{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0);pointer-events:none;transition:background .22s}
    .lb.is-open{background:rgba(0,0,0,.88);pointer-events:auto}
    .lb__img{max-width:95vw;max-height:92vh;object-fit:contain;border-radius:12px;box-shadow:0 20px 80px rgba(0,0,0,.6);transform:scale(.88);opacity:0;transition:transform .24s cubic-bezier(.34,1.38,.64,1),opacity .2s;cursor:zoom-out;user-select:none;touch-action:pinch-zoom}
    .lb.is-open .lb__img{transform:scale(1);opacity:1}
    .lb__close{position:fixed;top:18px;right:22px;width:44px;height:44px;border:none;background:rgba(255,255,255,.12);color:#fff;font-size:24px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);transition:background .15s;line-height:1}
    .lb__close:hover{background:rgba(255,255,255,.22)}
    .lb__close:focus-visible{outline:2px solid #fff;outline-offset:2px}
    .info{padding:22px}.badge-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}.badge{padding:7px 12px;border-radius:999px;border:1px solid #dbe7df;background:#f4fbf7;color:#175a37;font-size:12px;font-weight:700}
    .title{margin:0;font-size:32px;color:#111827}.desc{margin:14px 0 0;color:#5b6472;font-size:16px;line-height:1.7}
    .price-wrap{margin:18px 0 14px;display:flex;flex-direction:column;gap:6px}.price{font-size:28px;font-weight:800;color:#0f6b45;line-height:1}.price-old{font-size:17px;font-weight:600;color:#9ca3af;text-decoration:line-through;text-decoration-thickness:2px;text-decoration-color:#ef4444}.note{color:#6b7280;font-size:14px;margin-bottom:16px}.discount-badge{display:inline-flex;align-items:center;padding:4px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:999px;color:#dc2626;font-size:13px;font-weight:800}
    .features{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:18px}.feature{padding:12px 14px;border:1px solid #eef2f7;border-radius:16px;background:#fafbfc}.feature strong{display:block;margin-bottom:4px}.feature span{font-size:13px;color:#6b7280;line-height:1.5}
    .qty-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.qty-box{display:inline-flex;align-items:center;border:1px solid #dbe3ea;border-radius:16px;overflow:hidden;background:#fff}.qty-btn{width:42px;height:42px;border:none;background:#fff;font-size:22px;cursor:pointer}.qty-input{width:58px;height:42px;border:none;text-align:center;font-size:16px;font-weight:800;outline:none}
    .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:14px}.msg{padding:12px 14px;border-radius:14px;font-weight:600}.ok{background:#ecfdf3;border:1px solid #c9f1d8;color:#166534}.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .fav-btn{width:52px;min-width:52px;padding:0;justify-content:center}.fav-btn svg{width:22px;height:22px;display:block}.fav-btn svg path{stroke:currentColor;fill:transparent;transition:fill .18s ease,stroke .18s ease}.fav-btn{color:#94a3b8}.fav-btn.is-active{background:#fff1f2;border-color:#fecdd3;color:#be123c}.fav-btn.is-active svg path{fill:currentColor}
    .warn-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.warn-box{padding:16px;border-radius:18px;border:1px solid #eef2f7;background:#fffaf8}.warn-box strong{display:block;margin-bottom:8px}.warn-box p{margin:0;color:#556070;line-height:1.7;font-size:15px}.warn-box.warn-box--care{background:linear-gradient(135deg,#f3fbf7 0%,#eef8ff 100%);border-color:#dceee5}.warn-box.warn-box--care strong{color:#175a37;font-size:18px}.warn-box.warn-box--care p{color:#405468;font-size:16px}
    .related{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
    .rel{background:#fff;border:1px solid #e8eeea;border-radius:22px;box-shadow:var(--shadow-1);overflow:hidden;display:flex;flex-direction:column}
    .rel__media-wrap{position:relative;padding:7px 7px 0}
    .rel__media{display:block;position:relative;border-radius:17px;overflow:hidden}
    .rel img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:17px;border:none;background:#f4f6f8}
    .rel__body{display:flex;flex-direction:column;gap:8px;padding:11px 10px 11px;flex:1 1 auto}
    .rel h3{margin:0;font-size:15px;line-height:1.3;font-weight:800;color:#111827}
    .rel p{margin:0;color:#667085;font-size:13px;line-height:1.5}
    .rel-bottom{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:auto}
    .rel-price{font-size:15px;font-weight:800;color:#0f6b45;background:#f0faf5;border:1.5px solid #a7dfbf;border-radius:999px;padding:5px 14px}
    .rel__overlay-btn{position:absolute;top:16px;right:12px;z-index:3;width:38px;height:38px;display:flex;align-items:center;justify-content:center;padding:0;line-height:0;border-radius:999px;border:1px solid rgba(255,255,255,.42);background:rgba(255,255,255,.18);color:#fff;box-shadow:0 8px 20px rgba(0,0,0,.16);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);cursor:pointer;transition:.18s ease;text-decoration:none;appearance:none;-webkit-appearance:none}
    .rel__overlay-btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.24)}
    .rel__overlay-btn.is-active{background:rgba(225,29,72,.82);border-color:rgba(255,255,255,.56);color:#fff}
    .rel__overlay-btn svg{width:22px;height:22px;display:block}
    .rel__overlay-btn svg path{stroke:currentColor;fill:transparent;transition:fill .18s ease,stroke .18s ease}
    .rel__overlay-btn.is-active svg path{fill:currentColor;stroke:currentColor}
    .gallery__perks{margin-top:14px;display:grid;gap:1px;border:1px solid #eef2f7;border-radius:16px;overflow:hidden;background:#eef2f7}
    .gallery__perk{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fff}
    .gallery__perk-icon{width:36px;height:36px;border-radius:10px;background:#f0faf5;border:1px solid #d7eadf;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#0f6b45}
    .gallery__perk-icon svg{width:18px;height:18px}
    .gallery__perk-text strong{display:block;font-size:13px;font-weight:700;color:#0f172a}
    .gallery__perk-text span{font-size:12px;color:#6b7280;line-height:1.4}
    .stem-grid{display:flex;flex-wrap:wrap;gap:8px}
    .stem-btn{padding:10px 18px;border:2px solid #dbe3ea;border-radius:14px;background:#fff;font-size:15px;font-weight:700;color:#374151;cursor:pointer;transition:.16s ease}
    .stem-btn:hover{border-color:#0f6b45;color:#0f6b45}
    .stem-btn.is-active{border-color:#0f6b45;background:#0f6b45;color:#fff}
    @media (max-width:980px){.hero{grid-template-columns:1fr}.features,.warn-grid{grid-template-columns:1fr}.topline{flex-direction:column;align-items:flex-start}.crumbs{padding-top:0}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container product-page">
    <div class="topline">
      <a class="btn btn--ghost btn--sm" href="catalog.php">← Назад в каталог</a>
      <div class="crumbs"><a href="index.php">Главная</a><span>•</span><a href="catalog.php">Каталог</a><span>•</span><span><?= h($categoryName) ?></span><span>•</span><span><?= h($product['name']) ?></span></div>
    </div>

    <?php if ($notice): ?><div class="msg ok"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

    <div class="hero">
      <div class="box gallery">
        <div class="gallery__img-wrap">
          <img
            class="gallery__img"
            src="<?= h($productImage) ?>"
            alt="<?= h($product['name']) ?>"
            onerror="this.src='<?= h($placeholder) ?>'"
            onclick="lbOpen(this.src)"
            id="productMainImg"
          >
          <span class="gallery__hint">🔍 Нажмите для увеличения</span>
        </div>

        <div class="gallery__perks">
          <div class="gallery__perk">
            <div class="gallery__perk-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0112 2a8 8 0 018 8.2c0 7.3-8 11.8-8 11.8z"/><circle cx="12" cy="10" r="3"/></svg>
            </div>
            <div class="gallery__perk-text">
              <strong>Доставка и самовывоз</strong>
              <span>Курьером по адресу или из магазина FloraExpress</span>
            </div>
          </div>
          <div class="gallery__perk">
            <div class="gallery__perk-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 00-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 00-7-7z"/><path d="M9 9l2 2 4-4"/></svg>
            </div>
            <div class="gallery__perk-text">
              <strong>Свежие цветы</strong>
              <span>Закупаем ежедневно, гарантия свежести</span>
            </div>
          </div>
          <div class="gallery__perk">
            <div class="gallery__perk-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            </div>
            <div class="gallery__perk-text">
              <strong>Фото перед отправкой</strong>
              <span>По запросу пришлём фото готового букета</span>
            </div>
          </div>
          <div class="gallery__perk">
            <div class="gallery__perk-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
            </div>
            <div class="gallery__perk-text">
              <strong>Замена при несоответствии</strong>
              <span>Если букет не понравится — заменим или вернём деньги</span>
            </div>
          </div>
        </div>
      </div>
      <div class="box info">
        <div class="badge-row"><span class="badge"><?= h($categoryName) ?></span><?php if ((int)($product['is_active'] ?? 0) === 1): ?><span class="badge">Доступен к заказу</span><?php endif; ?></div>
        <h1 class="title"><?= h($product['name']) ?></h1>
        <p class="desc"><?= h($description) ?></p>
        <?php
          $priceView  = pricing_view($product, $pdo);
          $isStem     = !empty($product['stem_product']);
          $unitPrice  = (float)$priceView['price'];
        ?>
        <div class="price-wrap">
          <?php if ($priceView['has_discount'] && $priceView['old_price']): ?>
            <div class="price-old"><?= number_format((float)$priceView['old_price'], 0, ',', ' ') ?> ₽</div>
          <?php endif; ?>
          <div class="price" id="js-total-price"><?= number_format($unitPrice, 0, ',', ' ') ?> ₽</div>
          <?php if ($priceView['badge_html']): ?>
            <div style="margin-top:6px"><?= $priceView['badge_html'] ?></div>
          <?php endif; ?>
        </div>
        <?php if ($isStem): ?>
          <div class="note">Цена за <strong id="js-stem-count">1</strong> шт. Выберите количество ниже.</div>
        <?php else: ?>
          <div class="note">Цена за одну позицию. Доставка или самовывоз выбираются при оформлении заказа.</div>
        <?php endif; ?>
        <div class="features">
          <div class="feature"><strong>Получение</strong><span>Обычная доставка по адресу или самовывоз из магазина FloraExpress.</span></div>
          <div class="feature"><strong>Оплата</strong><span>Картой, СБП или другим доступным способом при оформлении.</span></div>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <?php if ($isStem): ?>
            <div style="margin-bottom:16px">
              <strong style="display:block;margin-bottom:10px">Количество штук</strong>
              <div class="stem-grid" id="js-stem-grid">
                <?php foreach ([1,3,5,7,11,15,25,51] as $n): ?>
                  <button type="button" class="stem-btn<?= $n === 1 ? ' is-active' : '' ?>" data-count="<?= $n ?>"><?= $n ?> шт</button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="qty" id="js-qty-input" value="1">
            </div>
          <?php else: ?>
            <div class="qty-row"><strong>Количество</strong>
              <div class="qty-box">
                <button class="qty-btn" type="button" onclick="var i=document.getElementById('qty');i.value=Math.max(1,(parseInt(i.value||1,10)-1));">−</button>
                <input id="qty" class="qty-input" type="number" min="1" name="qty" value="1">
                <button class="qty-btn" type="button" onclick="var i=document.getElementById('qty');i.value=Math.max(1,(parseInt(i.value||1,10)+1));">+</button>
              </div>
            </div>
          <?php endif; ?>
          <div class="actions">
            <button class="btn" type="submit" name="action" value="buy_now">Купить сейчас</button>
            <button class="btn btn--ghost" type="submit" name="action" value="add">Добавить в корзину</button>
            <?php if ($userId): ?>
              <button class="btn btn--ghost fav-btn<?= $isFavorite ? ' is-active' : '' ?>" type="submit" name="action" value="toggle_favorite" title="<?= $isFavorite ? 'Убрать из избранного' : 'Добавить в избранное' ?>" aria-label="<?= $isFavorite ? 'Убрать из избранного' : 'Добавить в избранное' ?>"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            <?php else: ?>
              <a class="btn btn--ghost fav-btn" href="auth.php?mode=login&return=<?= urlencode('product.php?id=' . $productId) ?>" title="Войти и добавить в избранное" aria-label="Войти и добавить в избранное"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
            <?php endif; ?>
          </div>
        </form>

        <div class="warn-grid">
          <div class="warn-box">
            <?php if ($isBalloon): ?>
              <strong>Важно по заказу</strong>
              <p>Состав и оформление могут немного отличаться от фото в зависимости от наличия. Продавец свяжется с вами и предложит похожий вариант или возврат.</p>
            <?php else: ?>
              <strong>Важно по составу букета</strong>
              <p>Иногда точный букет может немного отличаться от фото. Если нужного состава не окажется в наличии, продавец свяжется с вами и предложит похожий вариант или возврат средств.</p>
            <?php endif; ?>
          </div>
          <?php if ($isBalloon): ?>
            <div class="warn-box" style="background:linear-gradient(135deg,#eef6ff 0%,#e0f0ff 100%);border-color:#bfdbfe">
              <strong style="color:#1e3a8a;font-size:18px">Уход за шарами</strong>
              <p style="color:#405468;font-size:16px">Как хранить, сколько держится гелий и как продлить жизнь шариков — всё в краткой памятке.</p>
              <div style="margin-top:10px"><a class="btn btn--ghost btn--sm" href="care_balloons.php">Открыть памятку</a></div>
            </div>
          <?php else: ?>
            <div class="warn-box warn-box--care">
              <strong>Уход за цветами</strong>
              <p>Откройте короткую памятку с советами по воде, срезу и месту для букета. Это поможет цветам дольше сохранить свежесть, а уход под конкретный сорт лучше уточнить у продавцов лично.</p>
              <div style="margin-top:10px"><a class="btn btn--ghost btn--sm" href="care.php">Открыть памятку по уходу</a></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($related): ?>
      <div class="box" style="padding:20px">
        <h2 style="margin:0 0 14px">Похожие товары</h2>
        <div class="related">
          <?php foreach ($related as $row): ?>
            <?php
              $img = image_src2($row['image'] ?? '', $placeholder);
              $desc = trim((string)($row['description'] ?? ''));
              if ($desc === '') $desc = 'Подробнее в карточке товара.';
              $relPriceView = pricing_view($row, $pdo);
              $relIsFav = !empty($favMap[(int)$row['id']]);
              $relId = (int)$row['id'];
            ?>
            <article class="rel">
              <div class="rel__media-wrap">
                <a class="rel__media" href="product.php?id=<?= $relId ?>">
                  <img src="<?= h($img) ?>" alt="<?= h($row['name']) ?>">
                </a>
                <?php if ($userId): ?>
                  <button type="button"
                          class="rel__overlay-btn<?= $relIsFav ? ' is-active' : '' ?>"
                          data-fav-toggle
                          data-product-id="<?= $relId ?>"
                          aria-label="<?= $relIsFav ? 'Убрать из избранного' : 'Добавить в избранное' ?>"
                          title="<?= $relIsFav ? 'Убрать из избранного' : 'Добавить в избранное' ?>">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </button>
                <?php else: ?>
                  <a class="rel__overlay-btn"
                     href="auth.php?mode=login&return=<?= urlencode('product.php?id=' . $relId) ?>"
                     aria-label="Войти и добавить в избранное"
                     title="Войти и добавить в избранное">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </a>
                <?php endif; ?>
              </div>
              <div class="rel__body">
                <a class="catalog-card__title" href="product.php?id=<?= $relId ?>"><?= h($row['name']) ?></a>
                <p><?= h($desc) ?></p>
                <div class="rel-bottom">
                  <span class="rel-price">
                    <?php if ($relPriceView['has_discount'] && $relPriceView['old_price']): ?>
                      <span style="text-decoration:line-through;color:#9ca3af;font-weight:600;font-size:12px;margin-right:4px"><?= number_format((float)$relPriceView['old_price'], 0, ',', ' ') ?> ₽</span>
                    <?php endif; ?>
                    <?= number_format((float)$relPriceView['price'], 0, ',', ' ') ?> ₽
                  </span>
                  <a class="btn btn--ghost btn--sm" href="product.php?id=<?= $relId ?>">Открыть</a>
                </div>
                <?php if ($relPriceView['badge_html']): ?>
                  <div style="margin-top:6px"><?= $relPriceView['badge_html'] ?></div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>

<!-- Лайтбокс -->
<div class="lb" id="lb" role="dialog" aria-modal="true" aria-label="Просмотр фото">
  <button class="lb__close" id="lbClose" aria-label="Закрыть">✕</button>
  <img class="lb__img" id="lbImg" src="" alt="Фото товара" onclick="lbClose()">
</div>

<script>
(function () {
  const lb    = document.getElementById('lb');
  const lbImg = document.getElementById('lbImg');
  const lbBtn = document.getElementById('lbClose');

  window.lbOpen = function (src) {
    lbImg.src = src;
    lb.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    lbBtn.focus();
  };

  window.lbClose = function () {
    lb.classList.remove('is-open');
    document.body.style.overflow = '';
    document.getElementById('productMainImg')?.focus();
  };

  lbBtn.addEventListener('click', lbClose);

  lb.addEventListener('click', function (e) {
    if (e.target === lb) lbClose();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && lb.classList.contains('is-open')) lbClose();
  });
})();
</script>

<script>
(function(){
  const toast = document.createElement('div');
  toast.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:rgba(15,23,42,.92);color:#fff;padding:12px 16px;border-radius:12px;box-shadow:0 18px 36px rgba(0,0,0,.18);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:9999;font-size:14px;font-weight:600';
  document.body.appendChild(toast);
  let timer;
  function showToast(t){ toast.textContent=t; toast.style.opacity='1'; clearTimeout(timer); timer=setTimeout(()=>toast.style.opacity='0',1500); }

  document.querySelectorAll('[data-fav-toggle]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const productId = btn.dataset.productId;
      btn.disabled = true;
      try {
        const res = await fetch('favorite_api.php', {
          method:'POST',
          headers:{'X-Requested-With':'XMLHttpRequest'},
          body: new URLSearchParams({action:'toggle', product_id:productId})
        });
        const data = await res.json();
        if (data.ok) {
          btn.classList.toggle('is-active', !!data.active);
          showToast(data.active ? 'Добавлено в избранное' : 'Убрано из избранного');
        } else {
          if (data.auth) { window.location.href = 'auth.php?mode=login'; return; }
          showToast('Ошибка');
        }
      } catch(_) { showToast('Сеть недоступна'); }
      finally { btn.disabled = false; }
    });
  });
})();
</script>

<?php if ($isStem): ?>
<script>
(function(){
  const unitPrice = <?= (int)$unitPrice ?>;
  const grid      = document.getElementById('js-stem-grid');
  const qtyInput  = document.getElementById('js-qty-input');
  const totalEl   = document.getElementById('js-total-price');
  const countEl   = document.getElementById('js-stem-count');

  function fmt(n){ return n.toLocaleString('ru-RU') + ' ₽'; }

  grid.addEventListener('click', function(e){
    const btn = e.target.closest('.stem-btn');
    if (!btn) return;
    grid.querySelectorAll('.stem-btn').forEach(b => b.classList.remove('is-active'));
    btn.classList.add('is-active');
    const n = parseInt(btn.dataset.count, 10);
    qtyInput.value  = n;
    totalEl.textContent = fmt(unitPrice * n);
    countEl.textContent = n;
  });
})();
</script>
<?php endif; ?>
</body>
</html>
