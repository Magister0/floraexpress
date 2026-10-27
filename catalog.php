<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/favorites_lib.php';
require_once __DIR__ . '/product_admin_lib.php';
require_once __DIR__ . '/category_lib.php';
require_once __DIR__ . '/pricing_lib.php';

$pdo = db();
pa_ensure_products_discount_columns($pdo);
favorites_init($pdo);
categories_bootstrap($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$me = $_SESSION['user'] ?? null;
$userId = (int)($me['id'] ?? 0);
$canManage = $me && in_array($me['role'] ?? 'user', ['admin', 'manager'], true);

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

function lower_(mixed $s): string { return mb_strtolower((string)$s, 'UTF-8'); }
function crop_text_safe($s, $len = 96){
    $s = trim((string)$s);
    if ($s === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') > $len ? mb_substr($s, 0, $len, 'UTF-8') . '…' : $s;
    }
    if (function_exists('iconv_substr')) {
        $cut = iconv_substr($s, 0, $len, 'UTF-8');
        if ($cut !== false && $cut !== $s) return $cut . '…';
        if ($cut !== false) return $cut;
    }
    return strlen($s) > $len ? substr($s, 0, $len) . '...' : $s;
}

function catalog_heart_svg(): string {
    return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

function catalog_edit_svg(): string {
    return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.12 2.12 0 0 0-3-3L5 17v3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 6.5l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

$CAT_LABELS = [
  'roses'    => 'Розы',
  'tulips'   => 'Тюльпаны',
  'bouquets' => 'Букеты',
  'mini'     => 'Мини-букеты',
  'cards'    => 'Открытки',
  'balloons' => 'Шарики',
  'other'    => 'Другое',
];

function normCat($raw){
  $x = trim((string)$raw);
  $x = function_exists('mb_strtolower') ? mb_strtolower($x,'UTF-8') : strtolower($x);
  $x = str_replace(['ё'],'е',$x);
  $map = [
    'roses'    => ['розы','роза','roses','rose'],
    'tulips'   => ['тюльпаны','тюльпан','tulips','tulip'],
    'bouquets' => ['букеты','букет','сборные','сборный','bouquet','bouquets','mix'],
    'mini'     => ['мини','мини-букеты','mini'],
    'cards'    => ['открытки','открытка','cards','card'],
    'balloons' => ['шары','шарики','balloons','balloon'],
  ];
  foreach ($map as $k => $arr) {
    foreach ($arr as $v) {
      if ($x === $v) return $k;
    }
  }
  return isset($map[$x]) ? $x : ($x !== '' ? $x : 'other');
}

$cat = trim((string)($_GET['cat'] ?? 'all'));
$cat = $cat === '' ? 'all' : normCat($cat);
$q = trim((string)($_GET['q'] ?? ''));
$priceMin = isset($_GET['price_min']) && $_GET['price_min'] !== '' ? max(0, (int)$_GET['price_min']) : null;
$priceMax = isset($_GET['price_max']) && $_GET['price_max'] !== '' ? max(0, (int)$_GET['price_max']) : null;
$sort = in_array($_GET['sort'] ?? '', ['price_asc', 'price_desc'], true) ? $_GET['sort'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;

try {
  $items = $pdo->query("SELECT id,name,price,old_price,image,description,category,is_active,COALESCE(stem_product,false) AS stem_product FROM products WHERE COALESCE(is_active,1)=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $items = [];
}

foreach ($items as &$p) {
  $p['__cat'] = normCat($p['category'] ?? '');
}
unset($p);

// Строим фильтры из таблицы categories (только is_active = true),
// упорядоченные по sort_order. Категории без товаров не показываем.
$dynamicFilters = [];
$seenFilterKeys = [];
foreach (categories_fetch_all($pdo, true) as $row) {
  $normKey = normCat((string)$row['slug']);
  if (isset($seenFilterKeys[$normKey])) continue; // не дублируем (напр. 'Букеты' и 'bouquets' → оба normCat='bouquets')
  foreach ($items as $p) {
    if ($p['__cat'] === $normKey) {
      $dynamicFilters[$normKey] = (string)$row['name'];
      $seenFilterKeys[$normKey] = true;
      break;
    }
  }
}

$favMap = $userId ? favorites_map($pdo, $userId) : [];
$placeholder = favorites_placeholder_image();

$filtered = [];
foreach ($items as $p) {
  if ($cat !== 'all' && ($p['__cat'] ?? 'other') !== $cat) continue;
  if ($q !== '') {
    $needle = lower_($q);
    $hay = lower_((string)($p['name'] ?? '') . ' ' . (string)($p['description'] ?? '') . ' ' . (string)($p['category'] ?? ''));
    if (strpos($hay, $needle) === false) continue;
  }
  $price = (float)($p['price'] ?? 0);
  if ($priceMin !== null && $price < $priceMin) continue;
  if ($priceMax !== null && $price > $priceMax) continue;
  $filtered[] = $p;
}
if ($sort === 'price_asc') {
  usort($filtered, fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);
} elseif ($sort === 'price_desc') {
  usort($filtered, fn($a, $b) => (float)$b['price'] <=> (float)$a['price']);
} else {
  $bouquetCats = ['bouquets', 'mini'];
  usort($filtered, function($a, $b) use ($bouquetCats) {
    $pa = in_array($a['__cat'], $bouquetCats, true) ? 0 : ((bool)($a['stem_product'] ?? false) ? 1 : 2);
    $pb = in_array($b['__cat'], $bouquetCats, true) ? 0 : ((bool)($b['stem_product'] ?? false) ? 1 : 2);
    if ($pa !== $pb) return $pa - $pb;
    return (int)$b['id'] - (int)$a['id'];
  });
}

// Пагинация
$totalItems = count($filtered);
$totalPages = max(1, (int)ceil($totalItems / $perPage));
if ($page > $totalPages) $page = $totalPages;
$pageItems = array_slice($filtered, ($page - 1) * $perPage, $perPage);

function catalog_page_url(int $p, string $cat, string $q, ?int $priceMin, ?int $priceMax, string $sort): string {
  $params = [];
  if ($cat !== 'all') $params['cat'] = $cat;
  if ($q !== '') $params['q'] = $q;
  if ($priceMin !== null) $params['price_min'] = $priceMin;
  if ($priceMax !== null) $params['price_max'] = $priceMax;
  if ($sort !== '') $params['sort'] = $sort;
  if ($p > 1) $params['page'] = $p;
  return 'catalog.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>FloraExpress — Каталог</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .catalog-page .catalog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
    .catalog-page .filters{display:flex; gap:12px; flex-wrap:wrap; align-items:center;}
    .catalog-page .catalog-filter-links{display:flex; gap:8px; flex-wrap:wrap; align-items:center;}
    .catalog-page .catalog-search-wrap{margin-left:auto; display:flex; gap:10px; align-items:center; min-width:280px;}
    .catalog-page .catalog-grid{display:grid; grid-template-columns:repeat(auto-fill, minmax(258px, 1fr)); gap:18px;}
    .catalog-page .catalog-card{position:relative; display:flex; flex-direction:column; background:#fff; border:1px solid #e8eeea; border-radius:22px; box-shadow:var(--shadow-1); overflow:hidden;}
    .catalog-page .catalog-card__media-wrap{position:relative; padding:7px 7px 0;}
    .catalog-page .catalog-card__media{display:block; position:relative; border-radius:17px; overflow:hidden;}
    .catalog-page .catalog-card__img{display:block; width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:17px; border:none; background:#f4f6f8;}
    .catalog-page .catalog-card__overlay-btn{position:absolute; top:16px; z-index:3; width:38px; height:38px; display:flex; align-items:center; justify-content:center; padding:0; line-height:0; border-radius:999px; border:1px solid rgba(255,255,255,.42); background:rgba(255,255,255,.18); color:#fff; box-shadow:0 8px 20px rgba(0,0,0,.16); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); cursor:pointer; transition:.18s ease; text-decoration:none; appearance:none; -webkit-appearance:none;}
    .catalog-page .catalog-card__overlay-btn:hover{transform:translateY(-1px); background:rgba(255,255,255,.24)}
    .catalog-page .catalog-card__overlay-btn::before,.catalog-page .catalog-card__overlay-btn::after{content:none !important; display:none !important;}
    .catalog-page .catalog-card__fav{right:12px;}
    .catalog-page .catalog-card__fav.is-active{background:rgba(225,29,72,.82); border-color:rgba(255,255,255,.56); color:#fff;}
    .catalog-page .catalog-card__fav-icon{display:flex;align-items:center;justify-content:center;line-height:0;}
    .catalog-page .catalog-card__fav svg,.catalog-page .catalog-card__edit svg{width:22px;height:22px;display:block;}
    .catalog-page .catalog-card__fav svg path{stroke:currentColor;fill:transparent;transition:fill .18s ease,stroke .18s ease;}
    .catalog-page .catalog-card__fav.is-active svg path{fill:currentColor; stroke:currentColor;}
    .catalog-page .catalog-card__hide{left:12px; padding:0;}
    .catalog-page .catalog-card__hide svg{width:22px;height:22px;display:block}
    .catalog-page .catalog-card__edit{right:12px; top:auto; bottom:12px; left:auto; padding:0;}
    .catalog-page .catalog-card__body{display:flex; flex-direction:column; gap:8px; padding:11px 10px 11px; flex:1 1 auto;}
    .catalog-page .catalog-card__title{font-size:15px; line-height:1.3; font-weight:800; color:#111827; text-decoration:none;}
    .catalog-page .catalog-card__desc{margin:0; height:58px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; color:#667085; font-size:13px; line-height:1.5;}
    .catalog-page .catalog-card__price-wrap{display:flex;flex-direction:column;gap:4px;margin-top:auto;}
    .catalog-page .catalog-card__price{align-self:flex-start; color:#0f6b45; font-size:15px; font-weight:800; line-height:1.1; background:#f0faf5; border:1.5px solid #a7dfbf; border-radius:999px; padding:5px 14px;}
    .catalog-page .catalog-card__price-old{color:#9ca3af; font-size:13px; font-weight:600; text-decoration:line-through; text-decoration-thickness:2px; text-decoration-color:#ef4444;}
    .catalog-page .catalog-card__discount-badge{display:inline-flex;align-items:center;padding:2px 7px;background:#fef2f2;border:1px solid #fecaca;border-radius:999px;color:#dc2626;font-size:11px;font-weight:800;line-height:1.4;}
    .catalog-page .catalog-card__actions{display:grid; grid-template-columns:minmax(0,1fr); gap:8px;}
    .catalog-page .catalog-card__buy{width:100%; min-height:44px; border-radius:16px; justify-content:center; white-space:nowrap;}
    .catalog-page .catalog-card__manage{display:grid; grid-template-columns:1fr; gap:7px;}
    .catalog-page .catalog-empty{color:var(--muted); padding:10px 2px;}
    .catalog-page .price-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding-top:10px;border-top:1px solid #eef2f7;margin-top:10px}
    .catalog-page .price-filter label{font-size:13px;color:#6b7280;font-weight:600}
    .catalog-page .price-filter .input{width:90px;padding:6px 10px;font-size:13px}
    .catalog-page .sort-btns{display:flex;gap:6px}
    .catalog-page .catalog-pager{display:flex;flex-wrap:wrap;gap:6px;align-items:center;justify-content:center;margin:24px 0 8px}
    .catalog-page .catalog-pager__btn,
    .catalog-page .catalog-pager__num{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;color:#111827;font-weight:700;font-size:14px;text-decoration:none;transition:.15s ease}
    .catalog-page .catalog-pager__btn:hover,
    .catalog-page .catalog-pager__num:hover{border-color:#0f6b45;color:#0f6b45}
    .catalog-page .catalog-pager__num.is-active{background:#0f6b45;border-color:#0f6b45;color:#fff;cursor:default}
    .catalog-page .catalog-pager__btn.is-disabled{opacity:.4;cursor:not-allowed;color:#9ca3af}
    .catalog-page .catalog-pager__btn.is-disabled:hover{border-color:#e5e7eb;color:#9ca3af}
    .catalog-page .catalog-pager__gap{padding:0 4px;color:#9ca3af;font-weight:700}
    .catalog-page .catalog-toast{position:fixed; left:50%; bottom:24px; transform:translateX(-50%); background:rgba(15,23,42,.92); color:#fff; padding:12px 16px; border-radius:12px; box-shadow:0 18px 36px rgba(0,0,0,.18); opacity:0; pointer-events:none; transition:opacity .2s ease; z-index:9999;}
    @media (max-width: 900px){
      .catalog-page .catalog-grid{grid-template-columns:repeat(2,minmax(0,1fr)); gap:15px;}
    }
    @media (max-width: 760px){
      .catalog-page .catalog-search-wrap{width:100%; margin-left:0;}
      .catalog-page .catalog-head{align-items:stretch}
      .catalog-page .catalog-head .btn{width:100%;justify-content:center}
      .catalog-page .catalog-grid{grid-template-columns:1fr; gap:13px;}
      .catalog-page .catalog-card__media-wrap{padding:5px 5px 0;}
      .catalog-page .catalog-card__body{padding:10px 8px 10px;}
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section catalog-page" style="padding-bottom:18px">
  <div class="container">
    <div class="catalog-head" style="margin-bottom:16px">
      <div class="section__head" style="text-align:left;margin:0">
        <h1 class="section__title" style="font-size:32px">Каталог</h1>
        <p class="section__sub">Выберите нужную категорию или откройте карточку товара для быстрого заказа.</p>
      </div>
      <?php if ($canManage): ?>
        <a class="btn btn--ghost" href="product_new.php">Добавить товар</a>
      <?php endif; ?>
    </div>

    <div class="about__card filters">
      <div class="catalog-filter-links">
        <?php $queryAll = []; if ($q !== '') $queryAll['q'] = $q; ?>
        <a class="btn btn--ghost btn--sm<?= $cat === 'all' ? ' active-filter' : '' ?>" href="catalog.php<?= $queryAll ? '?' . http_build_query($queryAll) : '' ?>">Все</a>
        <?php foreach ($dynamicFilters as $key => $label): ?>
          <?php $params = ['cat' => $key]; if ($q !== '') $params['q'] = $q; ?>
          <a class="btn btn--ghost btn--sm<?= $cat === $key ? ' active-filter' : '' ?>" href="catalog.php?<?= http_build_query($params) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>

      <form class="catalog-search-wrap" method="get">
        <?php if ($cat !== 'all'): ?>
          <input type="hidden" name="cat" value="<?= h($cat) ?>">
        <?php endif; ?>
        <label class="mute" for="q">Поиск:</label>
        <input id="q" class="input search" type="search" name="q" placeholder="розы, шарики, букет..." value="<?= h($q) ?>">
      </form>
    </div>

    <form class="price-filter" method="get">
      <?php if ($cat !== 'all'): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
      <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>
      <label for="price_min">Цена:</label>
      <input class="input" type="number" id="price_min" name="price_min" placeholder="от" min="0" value="<?= $priceMin !== null ? $priceMin : '' ?>">
      <span style="color:#9ca3af">—</span>
      <input class="input" type="number" id="price_max" name="price_max" placeholder="до" min="0" value="<?= $priceMax !== null ? $priceMax : '' ?>">
      <button class="btn btn--ghost btn--sm" type="submit">Применить</button>
      <?php if ($priceMin !== null || $priceMax !== null): ?>
        <?php $resetParams = []; if ($cat !== 'all') $resetParams['cat'] = $cat; if ($q !== '') $resetParams['q'] = $q; if ($sort !== '') $resetParams['sort'] = $sort; ?>
        <a class="btn btn--ghost btn--sm" href="catalog.php<?= $resetParams ? '?' . http_build_query($resetParams) : '' ?>">Сбросить цену</a>
      <?php endif; ?>

      <span style="width:1px;height:20px;background:#e5e7eb;margin:0 4px"></span>

      <span style="font-size:13px;color:#6b7280;font-weight:600">Сортировка:</span>
      <?php
        function catalog_sort_url($sortVal, $cat, $q, $priceMin, $priceMax) {
          $p = [];
          if ($cat !== 'all') $p['cat'] = $cat;
          if ($q !== '') $p['q'] = $q;
          if ($priceMin !== null) $p['price_min'] = $priceMin;
          if ($priceMax !== null) $p['price_max'] = $priceMax;
          if ($sortVal !== '') $p['sort'] = $sortVal;
          return 'catalog.php' . ($p ? '?' . http_build_query($p) : '');
        }
      ?>
      <div class="sort-btns">
        <a class="btn btn--ghost btn--sm<?= $sort === '' ? ' active-filter' : '' ?>" href="<?= h(catalog_sort_url('', $cat, $q, $priceMin, $priceMax)) ?>">По умолчанию</a>
        <a class="btn btn--ghost btn--sm<?= $sort === 'price_asc' ? ' active-filter' : '' ?>" href="<?= h(catalog_sort_url('price_asc', $cat, $q, $priceMin, $priceMax)) ?>">Дешевле</a>
        <a class="btn btn--ghost btn--sm<?= $sort === 'price_desc' ? ' active-filter' : '' ?>" href="<?= h(catalog_sort_url('price_desc', $cat, $q, $priceMin, $priceMax)) ?>">Дороже</a>
      </div>
    </form>
  </div>
</section>

<section class="section catalog-page" style="padding-top:18px">
  <div class="container">
    <?php if (!$filtered): ?>
      <p class="catalog-empty">По вашему фильтру товаров не найдено.</p>
    <?php else: ?>
      <div class="catalog-meta" style="margin-bottom:12px;color:#6b7280;font-size:13px">
        Найдено товаров: <b style="color:#111827"><?= (int)$totalItems ?></b>
        <?php if ($totalPages > 1): ?>
          &nbsp;·&nbsp; страница <b style="color:#111827"><?= (int)$page ?></b> из <?= (int)$totalPages ?>
        <?php endif; ?>
      </div>
      <div class="catalog-grid">
        <?php foreach ($pageItems as $p):
          $img = favorites_image_src($p['image'] ?? '', $placeholder);
          $desc = trim((string)($p['description'] ?? ''));
          if ($desc === '') $desc = 'Перейдите в карточку товара, чтобы посмотреть детали и быстро оформить заказ.';
          $desc = crop_text_safe($desc, 92);
          $isFav = !empty($favMap[(int)$p['id']]);
        ?>
          <article class="catalog-card product" data-id="<?= (int)$p['id'] ?>">
            <div class="catalog-card__media-wrap">
              <a class="catalog-card__media" href="product.php?id=<?= (int)$p['id'] ?>">
                <img class="catalog-card__img" src="<?= h($img) ?>" alt="<?= h($p['name']) ?>">
              </a>

              <?php if ($canManage): ?>
                <button type="button"
                        class="catalog-card__overlay-btn catalog-card__hide"
                        data-toggle-visibility
                        data-product-id="<?= (int)$p['id'] ?>"
                        title="Скрыть товар из каталога"
                        aria-label="Скрыть товар из каталога">
                  <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M2 12C3.9 8.7 7.6 6 12 6C16.4 6 20.1 8.7 22 12C20.1 15.3 16.4 18 12 18C7.6 18 3.9 15.3 2 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M4 4L20 20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                  </svg>
                </button>
              <?php endif; ?>

              <?php if ($userId): ?>
                <button type="button"
                        class="catalog-card__overlay-btn catalog-card__fav<?= $isFav ? ' is-active' : '' ?>"
                        data-fav-toggle
                        data-product-id="<?= (int)$p['id'] ?>"
                        aria-label="<?= $isFav ? 'Убрать из избранного' : 'Добавить в избранное' ?>"
                        title="<?= $isFav ? 'Убрать из избранного' : 'Добавить в избранное' ?>"><span class="catalog-card__fav-icon"><?= catalog_heart_svg() ?></span></button>
              <?php else: ?>
                <a href="auth.php?mode=login"
                   class="catalog-card__overlay-btn catalog-card__fav"
                   aria-label="Войти и добавить в избранное"
                   title="Войти и добавить в избранное"><span class="catalog-card__fav-icon"><?= catalog_heart_svg() ?></span></a>
              <?php endif; ?>

              <?php if ($canManage): ?>
                <a href="product_edit.php?id=<?= (int)$p['id'] ?>"
                   class="catalog-card__overlay-btn catalog-card__edit"
                   title="Изменить товар"
                   aria-label="Изменить товар">
                  <?= catalog_edit_svg() ?>
                </a>
              <?php endif; ?>
            </div>

            <div class="catalog-card__body">
              <a class="catalog-card__title" href="product.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a>
              <p class="catalog-card__desc"><?= h($desc) ?></p>
              <?php $priceView = pricing_view($p, $pdo); ?>
              <div class="catalog-card__price-wrap">
                <?php if ($priceView['has_discount'] && $priceView['old_price']): ?>
                  <div class="catalog-card__price-old"><?= number_format((float)$priceView['old_price'], 0, ',', ' ') ?> ₽</div>
                <?php endif; ?>
                <div class="catalog-card__price"><?= number_format((float)$priceView['price'], 0, ',', ' ') ?> ₽</div>
                <?php if ($priceView['badge_html']): ?>
                  <div style="margin-top:4px"><?= $priceView['badge_html'] ?></div>
                <?php endif; ?>
              </div>

              <div class="catalog-card__actions">
                <button type="button"
                        class="btn catalog-card__buy"
                        data-add-cart
                        data-product-id="<?= (int)$p['id'] ?>">В корзину</button>

                <?php if ($canManage): ?>
                  <div class="catalog-card__manage">
                    <button type="button" class="btn btn--ghost btn--sm btn-del">Удалить</button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="catalog-pager" aria-label="Страницы каталога">
          <?php
            $prev = max(1, $page - 1);
            $next = min($totalPages, $page + 1);
            // Окно номеров: текущая ±2, плюс первая и последняя.
            $window = [];
            for ($i = 1; $i <= $totalPages; $i++) {
              if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) $window[] = $i;
            }
          ?>

          <?php if ($page > 1): ?>
            <a class="catalog-pager__btn" href="<?= h(catalog_page_url($prev, $cat, $q, $priceMin, $priceMax, $sort)) ?>" rel="prev">← Назад</a>
          <?php else: ?>
            <span class="catalog-pager__btn is-disabled">← Назад</span>
          <?php endif; ?>

          <?php
            $prevNum = 0;
            foreach ($window as $num):
              if ($prevNum && $num - $prevNum > 1):
          ?>
              <span class="catalog-pager__gap">…</span>
          <?php endif; ?>
            <?php if ($num === $page): ?>
              <span class="catalog-pager__num is-active" aria-current="page"><?= $num ?></span>
            <?php else: ?>
              <a class="catalog-pager__num" href="<?= h(catalog_page_url($num, $cat, $q, $priceMin, $priceMax, $sort)) ?>"><?= $num ?></a>
            <?php endif; ?>
          <?php
              $prevNum = $num;
            endforeach;
          ?>

          <?php if ($page < $totalPages): ?>
            <a class="catalog-pager__btn" href="<?= h(catalog_page_url($next, $cat, $q, $priceMin, $priceMax, $sort)) ?>" rel="next">Вперёд →</a>
          <?php else: ?>
            <span class="catalog-pager__btn is-disabled">Вперёд →</span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<div id="catalogToast" class="catalog-toast"></div>

<script>
const catalogToast = document.getElementById('catalogToast');
function showCatalogToast(text){
  if(!catalogToast) return;
  catalogToast.textContent = text;
  catalogToast.style.opacity = '1';
  clearTimeout(window.__catalogToastTimer);
  window.__catalogToastTimer = setTimeout(() => { catalogToast.style.opacity = '0'; }, 1500);
}
async function postForm(url, payload){
  const res = await fetch(url, {
    method: 'POST',
    headers: {'X-Requested-With':'XMLHttpRequest'},
    body: new URLSearchParams(payload)
  });
  return res.json();
}

document.querySelectorAll('[data-add-cart]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const productId = btn.dataset.productId;
    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '...';
    try{
      const data = await postForm('cart_api.php', {action:'add', id:productId, qty:1});
      if(data.ok){
        const countEl = document.getElementById('cartCount');
        if(countEl && typeof data.count !== 'undefined') countEl.textContent = data.count;
        const countEl2 = document.querySelector('.fe-btn__count');
        if(countEl2 && typeof data.count !== 'undefined') countEl2.textContent = data.count;
        showCatalogToast('Добавлено в корзину');
      }else{
        showCatalogToast('Ошибка: ' + (data.error || 'не удалось'));
      }
    }catch(_){
      showCatalogToast('Сеть недоступна');
    }finally{
      btn.disabled = false;
      btn.textContent = oldText;
    }
  });
});

document.querySelectorAll('[data-fav-toggle]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const productId = btn.dataset.productId;
    btn.disabled = true;
    try{
      const data = await postForm('favorite_api.php', {action:'toggle', product_id:productId});
      if(data.ok){
        btn.classList.toggle('is-active', !!data.active);
        const favCount = document.getElementById('favCount');
        if (favCount && typeof data.count !== 'undefined') favCount.textContent = data.count;
        const topFav = document.querySelector('.fe-fav-link');
        if(topFav) topFav.classList.toggle('is-active', Number(data.count || 0) > 0);
        showCatalogToast(data.active ? 'Добавлено в избранное' : 'Убрано из избранного');
      }else{
        if(data.auth){ window.location.href = 'auth.php?mode=login'; return; }
        showCatalogToast('Ошибка: ' + (data.error || 'не удалось'));
      }
    }catch(_){
      showCatalogToast('Сеть недоступна');
    }finally{
      btn.disabled = false;
    }
  });
});

document.querySelectorAll('[data-toggle-visibility]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const productId = btn.dataset.productId;
    const card = btn.closest('.product');
    if(!productId || !card) return;
    if(!confirm('Скрыть товар из каталога?')) return;
    btn.disabled = true;
    try {
      const data = await postForm('product_api.php', {
        action: 'toggle_visibility',
        id: productId,
        csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>
      });
      if (data.ok) {
        card.remove();
        showCatalogToast('Товар скрыт');
      } else {
        showCatalogToast('Ошибка: ' + (data.error || 'не удалось'));
      }
    } catch (_) {
      showCatalogToast('Сеть недоступна');
    } finally {
      btn.disabled = false;
    }
  });
});

document.addEventListener('click', async e => {
  const btn = e.target.closest('.btn-del');
  if(!btn) return;
  const card = btn.closest('.product');
  const id = card?.dataset.id;
  if(!id) return;
  if(!confirm('Удалить этот товар?')) return;
  try{
    const data = await postForm('product_api.php', {
      action: 'delete',
      id: id,
      csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>
    });
    if(data.ok){ card.remove(); showCatalogToast('Удалено'); }
    else { showCatalogToast('Ошибка: ' + (data.error || 'error')); }
  }catch(_){ showCatalogToast('Сеть недоступна'); }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
