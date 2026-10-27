<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/store_review_lib.php';
require_once __DIR__ . '/favorites_lib.php';
require_once __DIR__ . '/pricing_lib.php';

$pdo = db();
store_review_ensure_schema($pdo);
favorites_init($pdo);

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$me = $_SESSION['user'] ?? null;
$userId = (int)($me['id'] ?? 0);
$canModerateStoreReviews = in_array((string)($me['role'] ?? ''), ['admin', 'manager'], true);

function lower_safe(mixed $s): string { return mb_strtolower((string)$s, 'UTF-8'); }
function crop_home_text($s, $len = 120){
  $s = trim((string)$s);
  if ($s === '') return '';
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    return mb_strlen($s, 'UTF-8') > $len ? mb_substr($s, 0, $len, 'UTF-8') . '…' : $s;
  }
  if (function_exists('iconv_strlen') && function_exists('iconv_substr')) {
    $full = iconv_strlen($s, 'UTF-8');
    $cut = iconv_substr($s, 0, $len, 'UTF-8');
    if ($cut !== false) {
      return ($full !== false && $full > $len) ? ($cut . '…') : $cut;
    }
  }
  return strlen($s) > $len ? (substr($s, 0, $len) . '...') : $s;
}
function fmt_review_date($value){
  if (!$value) return '';
  $ts = strtotime((string)$value);
  if (!$ts) return '';
  return date('d.m.Y', $ts);
}
function stars_line($rating){
  $rating = max(0, min(5, (int)$rating));
  return str_repeat('★', $rating) . str_repeat('☆', max(0, 5 - $rating));
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];
$reviewError = null;
$reviewNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save_store_review') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
      $reviewError = 'Не удалось проверить форму. Обновите страницу и попробуйте снова.';
    } elseif (!$userId) {
      header('Location: auth.php?mode=login&return=' . urlencode('index.php#store-reviews'));
      exit;
    } elseif (!store_review_can_leave($pdo, $userId)) {
      $reviewError = 'Оставить отзыв о магазине можно после оформленного заказа.';
    } else {
      $rating = (int)($_POST['rating'] ?? 0);
      $text = trim((string)($_POST['review_text'] ?? ''));
      if ($rating < 1 || $rating > 5) {
        $reviewError = 'Поставьте оценку от 1 до 5.';
      } elseif ($text === '') {
        $reviewError = 'Напишите короткий отзыв о магазине.';
      } elseif ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) < 8) {
        $reviewError = 'Сделайте отзыв чуть подробнее.';
      } else {
        store_review_save($pdo, $userId, $rating, $text);
        header('Location: index.php?review_saved=1#store-reviews');
        exit;
      }
    }
  } elseif ($action === 'delete_store_review') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
      $reviewError = 'Не удалось проверить форму. Обновите страницу и попробуйте снова.';
    } elseif (!$canModerateStoreReviews) {
      $reviewError = 'Удалять отзывы может только администратор.';
    } else {
      $reviewId = (int)($_POST['review_id'] ?? 0);
      store_review_delete($pdo, $reviewId);
      header('Location: index.php?review_deleted=1#store-reviews');
      exit;
    }
  }
}
if (isset($_GET['review_saved'])) {
  $reviewNotice = 'Спасибо! Ваш отзыв о магазине сохранён.';
} elseif (isset($_GET['review_deleted'])) {
  $reviewNotice = 'Отзыв удалён.';
}

$items = [];
try{
  $items = $pdo->query("SELECT id,name,price,image,description,category FROM products WHERE COALESCE(is_active,1)=1 AND lower(coalesce(category,'')) IN ('букеты','букет','сборные','сборный','bouquets','bouquet','mix') ORDER BY id DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){}

$placeholder = 'data:image/svg+xml;utf8,'.rawurlencode(
  '<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="750">'
  . '<defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#e2e8f0"/><stop offset="1" stop-color="#f8fafc"/></linearGradient></defs>'
  . '<rect width="100%" height="100%" fill="url(#g)"/>'
  . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="26" fill="#94a3b8">FloraExpress</text>'
  . '</svg>'
);

$heroProduct = null;
$heroImg = $placeholder;

try {
  $sqlHeroTop = "
    SELECT p.id, p.name, p.price, p.image, p.description, p.category,
           SUM(oi.qty) AS sold_qty,
           COALESCE(SUM(oi.qty * oi.price), 0) AS sold_revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE COALESCE(p.is_active,1)=1
      AND o.status <> 'canceled'
      AND DATE(o.created_at) = CURRENT_DATE
      AND lower(coalesce(p.category,'')) IN ('букеты','букет','сборные','сборный','bouquets','bouquet','mix')
    GROUP BY p.id, p.name, p.price, p.image, p.description, p.category
    ORDER BY sold_qty DESC, sold_revenue DESC, p.id DESC
    LIMIT 1
  ";
  $heroProduct = $pdo->query($sqlHeroTop)->fetch(PDO::FETCH_ASSOC) ?: null;

  if (!$heroProduct) {
    $sqlHeroFallback = "
      SELECT id, name, price, image, description, category, 0 AS sold_qty, 0 AS sold_revenue
      FROM products
      WHERE COALESCE(is_active,1)=1
        AND image IS NOT NULL AND trim(image) <> ''
        AND lower(coalesce(category,'')) IN ('букеты','букет','сборные','сборный','bouquets','bouquet','mix')
      ORDER BY RANDOM()
      LIMIT 1
    ";
    $heroProduct = $pdo->query($sqlHeroFallback)->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  if ($heroProduct && trim((string)($heroProduct['image'] ?? '')) !== '') {
    $heroImg = trim((string)$heroProduct['image']);
  } else {
    $candidates = glob(__DIR__.'/img/*.{jpg,jpeg,png,webp,JPG,PNG,WEBP}', GLOB_BRACE);
    if ($candidates && count($candidates)) {
      $heroImg = 'img/'.basename($candidates[0]);
    }
  }
} catch (Throwable $e) {
  $heroProduct = null;
}

$favMap = $userId ? favorites_map($pdo, $userId) : [];

$storeReviewStats = store_review_stats($pdo);
$storeReviewList = store_review_latest($pdo, 0);
$userStoreReview = $userId ? store_review_get_user($pdo, $userId) : null;
$canLeaveStoreReview = $userId > 0 && store_review_can_leave($pdo, $userId);
$reviewFormRating = (int)($_POST['rating'] ?? ($userStoreReview['rating'] ?? 5));
$reviewFormText = trim((string)($_POST['review_text'] ?? ($userStoreReview['review_text'] ?? '')));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>FloraExpress — цветы с доставкой по Екатеринбургу</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .hero{position:relative;overflow:hidden;border-radius:20px;background:
      radial-gradient(1200px 500px at 10% -10%, #ffedea 0%, rgba(255,237,234,0) 60%),
      radial-gradient(800px 400px at 90% 0%, #e0f2fe 0%, rgba(224,242,254,0) 60%),
      linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border:1px solid #eef2f7;box-shadow:var(--shadow-1)}
    .hero__inner{padding:36px;display:grid;grid-template-columns:1.2fr .8fr;gap:16px;align-items:center}
    .hero__title{font-size:38px;line-height:1.15;margin:0 0 8px}
    .hero__subtitle{color:#6b7280;margin:0 0 16px}
    .hero__media{display:block;position:relative;border-radius:18px;overflow:hidden;text-decoration:none;color:inherit}
    .hero__media:hover .hero__img{transform:scale(1.02)}
    .hero__img{display:block;border-radius:16px;width:100%;aspect-ratio:4/3;object-fit:cover;border:1px solid #eef2f7;background:#f8fafc;transition:transform .22s ease, box-shadow .22s ease}
    .hero__media:hover .hero__img{box-shadow:0 16px 40px rgba(15,23,42,.12)}
    .chipbar{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
    .chip{background:#fff;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px}
    .qwrap{text-align:center}.qwrap .section__head{justify-content:center}.qcats{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}
    .card{background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:12px;display:flex;flex-direction:column}
    .card__img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:14px;border:1px solid #eef2f7}
    .card__img-wrap{position:relative;display:block}
    .card__fav{position:absolute;top:10px;right:10px;z-index:3;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:999px;border:1px solid rgba(255,255,255,.5);background:rgba(255,255,255,.2);color:#fff;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);cursor:pointer;transition:.18s ease;text-decoration:none;appearance:none;-webkit-appearance:none;box-shadow:0 4px 12px rgba(0,0,0,.18)}
    .card__fav:hover{transform:translateY(-1px);background:rgba(255,255,255,.32)}
    .card__fav.is-active{background:rgba(225,29,72,.82);border-color:rgba(255,255,255,.56);color:#fff}
    .card__fav svg{width:20px;height:20px;display:block}
    .card__fav svg path{stroke:currentColor;fill:transparent;transition:fill .18s ease,stroke .18s ease}
    .card__fav.is-active svg path{fill:currentColor;stroke:currentColor}
    .card__title{margin:10px 0 6px;font-size:18px;line-height:1.35}
    .card__title a{text-decoration:none;color:#111827}
    .price{display:inline-block; font-weight:800; color:#0f6b45; background:#f0faf5; border:1.5px solid #a7dfbf; border-radius:999px; padding:5px 14px; font-size:15px; line-height:1.1;}
    .reviews-shell{display:grid;grid-template-columns:minmax(320px,.92fr) minmax(360px,1.08fr);gap:18px;align-items:start}
    .review-panel,.review-form-card,.review-card,.review-feed{background:#fff;border:1px solid #e6edf1;border-radius:20px;box-shadow:var(--shadow-1)}
    .review-panel{padding:22px;background:linear-gradient(135deg,#fff8f3 0%,#ffffff 56%,#f5fbf8 100%)}
    .review-score{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin:10px 0 8px}
    .review-score__value{font-size:44px;line-height:1;font-weight:900;color:#0f6b45}
    .review-score__stars{font-size:24px;letter-spacing:2px;color:#f59e0b}
    .review-score__meta{color:#6b7280;font-size:14px}
    .review-form-card{padding:20px}
    .review-input,.review-textarea{width:100%;border:1px solid #dbe3ea;border-radius:14px;padding:12px 14px;font:inherit;background:#fff}
    .review-textarea{min-height:132px;resize:vertical}
    .review-radio-row{display:flex;gap:8px;flex-wrap:wrap}
    .review-radio{position:relative}
    .review-radio input{position:absolute;opacity:0;pointer-events:none}
    .review-radio span{display:inline-flex;align-items:center;justify-content:center;min-width:46px;height:42px;padding:0 12px;border-radius:999px;border:1px solid #dbe3ea;background:#fff;font-weight:700;cursor:pointer;transition:.18s ease}
    .review-radio input:checked + span{background:#175a37;color:#fff;border-color:#175a37}
    .review-help{margin:0;color:#6b7280;line-height:1.6}
    .review-msg{padding:12px 14px;border-radius:14px;margin:0 0 14px;font-weight:700}
    .review-msg--ok{background:#ecfdf3;border:1px solid #c9f1d8;color:#166534}
    .review-msg--err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .review-feed{padding:16px;max-height:760px;overflow-y:auto;background:linear-gradient(180deg,#ffffff 0%,#fbfdff 100%)}
    .review-feed__head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #eef2f7}
    .review-feed__list{display:grid;grid-template-columns:1fr;gap:14px}
    .review-card{padding:18px}
    .review-card__top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px}
    .review-card__name{font-weight:800;color:#111827}
    .review-card__stars{color:#f59e0b;font-size:18px;letter-spacing:1px}
    .review-card__date{color:#94a3b8;font-size:13px}
    .review-card__text{margin:0;color:#485261;line-height:1.7;word-break:break-word;overflow-wrap:anywhere}
    .review-card__actions{display:flex;justify-content:flex-end;margin-top:14px}
    .review-delete-btn{border:1px solid #f3c5cc;background:#fff5f5;color:#b42318;border-radius:12px;padding:8px 12px;font-weight:700;cursor:pointer}
    .review-feed::-webkit-scrollbar{width:10px}
    .review-feed::-webkit-scrollbar-thumb{background:#d5dde7;border-radius:999px}
    @media (max-width:980px){
      .hero__inner{grid-template-columns:1fr}
      .hero__title{font-size:30px}
      .reviews-shell{grid-template-columns:1fr}
      .review-feed{max-height:none;overflow:visible}
    }
  </style>
</head>
<body>

<?php include __DIR__.'/header.php'; ?>

<section class="section">
  <div class="container hero">
    <div class="hero__inner">
      <div>
        <h1 class="hero__title">Цветы с доставкой по&nbsp;Екатеринбургу — красиво, вовремя и с заботой</h1>
        <p class="hero__subtitle">Собираем букеты в день заказа. Фото перед выездом. Слоты доставки, самовывоз и удобное оформление заказа.</p>
        <div class="row" style="gap:10px;flex-wrap:wrap">
          <a class="btn" href="catalog.php">Перейти в каталог</a>
          <a class="btn btn--ghost" href="contacts.php">Связаться с нами</a>
        </div>
        <div class="chipbar">
          <span class="chip">Свежие цветы</span><span class="chip">Фото перед доставкой</span>
          <span class="chip">Самовывоз</span><span class="chip">Екатеринбург</span>
        </div>
      </div>

      <?php if ($heroProduct): ?>
        <a class="hero__media" href="product.php?id=<?= (int)$heroProduct['id'] ?>" aria-label="Открыть карточку товара: <?= h($heroProduct['name']) ?>">
          <img class="hero__img" src="<?= h($heroImg) ?>" alt="<?= h($heroProduct['name']) ?>" loading="eager" onerror="this.src='<?= h($placeholder) ?>'">
        </a>
      <?php else: ?>
        <img class="hero__img" src="<?= h($heroImg) ?>" alt="Букет" loading="eager" onerror="this.src='<?= h($placeholder) ?>'">
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section__head" style="justify-content:space-between">
      <div>
        <h2 class="section__title">Новинки каталога</h2>
        <p class="section__sub" style="margin-top:4px">У каждой позиции есть отдельная карточка товара.</p>
      </div>
      <a class="btn btn--ghost btn--sm" href="catalog.php">Смотреть всё</a>
    </div>

    <?php if (!$items): ?>
      <p class="mute">Пока новинок нет — добавьте первые товары в админке.</p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($items as $p):
          $img = trim((string)$p['image']) ?: $placeholder;
          $desc = trim((string)($p['description'] ?? ''));
          if ($desc === '') $desc = 'Перейдите в карточку товара, чтобы посмотреть детали и быстро оформить заказ.';
        ?>
        <article class="card product" data-id="<?= (int)$p['id'] ?>">
          <div class="card__img-wrap">
            <a href="product.php?id=<?= (int)$p['id'] ?>">
              <img class="card__img" src="<?= h($img) ?>" alt="<?= h($p['name']) ?>" onerror="this.src='<?= h($placeholder) ?>'">
            </a>
            <?php $isFav = !empty($favMap[(int)$p['id']]); ?>
            <?php if ($userId): ?>
              <button
                class="card__fav<?= $isFav ? ' is-active' : '' ?>"
                data-fav-toggle
                data-product-id="<?= (int)$p['id'] ?>"
                aria-label="<?= $isFav ? 'Убрать из избранного' : 'Добавить в избранное' ?>"
                title="<?= $isFav ? 'Убрать из избранного' : 'Добавить в избранное' ?>">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
            <?php else: ?>
              <a href="auth.php?mode=login&return=<?= urlencode('index.php') ?>"
                class="card__fav"
                aria-label="Войти и добавить в избранное"
                title="Войти и добавить в избранное">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </a>
            <?php endif; ?>
          </div>
          <h3 class="card__title"><a href="product.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a></h3>
          <p class="mute" style="margin:0 0 10px;line-height:1.55"><?= h(crop_home_text($desc, 120)) ?></p>
          <div class="row" style="justify-content:space-between;align-items:center;margin-top:auto;gap:10px;flex-wrap:wrap">
            <?php $homePriceView = pricing_view($p, $pdo); ?>
            <span class="price">
              <?php if ($homePriceView['has_discount'] && $homePriceView['old_price']): ?>
                <span style="text-decoration:line-through;color:#9ca3af;font-weight:600;font-size:13px;margin-right:6px"><?= number_format((float)$homePriceView['old_price'],0,',',' ') ?> ₽</span>
              <?php endif; ?>
              <?= number_format((float)$homePriceView['price'],0,',',' ') ?> ₽
              <?php if ($homePriceView['badge_html']): ?>
                <span style="margin-left:6px"><?= $homePriceView['badge_html'] ?></span>
              <?php endif; ?>
            </span>
            <div class="row" style="gap:8px;flex-wrap:wrap">
              <a class="btn btn--ghost btn--sm" href="product.php?id=<?= (int)$p['id'] ?>">Подробнее</a>
              <form method="post" data-addcart>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="qty" value="1">
                <button class="btn btn--sm" type="submit">В корзину</button>
              </form>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section" id="store-reviews">
  <div class="container">
    <div class="section__head" style="justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
      <div>
        <h2 class="section__title">Отзывы о магазине</h2>
        <p class="section__sub" style="margin-top:4px">Оставить отзыв может только авторизованный покупатель, у которого уже был заказ.</p>
      </div>
      <?php if ($storeReviewStats['total_reviews'] > 0): ?>
        <div class="mute">Всего отзывов: <strong><?= (int)$storeReviewStats['total_reviews'] ?></strong></div>
      <?php endif; ?>
    </div>

    <div class="reviews-shell">
      <div style="display:grid;gap:14px">
        <div class="review-panel">
          <div class="badge" style="display:inline-flex">Нам доверяют покупатели</div>
          <div class="review-score">
            <div class="review-score__value"><?= number_format((float)$storeReviewStats['avg_rating'], 1, ',', ' ') ?></div>
            <div>
              <div class="review-score__stars"><?= h(stars_line((int)round((float)$storeReviewStats['avg_rating']))) ?></div>
              <div class="review-score__meta">Средняя оценка магазина на основе отзывов покупателей.</div>
            </div>
          </div>
          <p class="review-help">Здесь собираются отзывы именно о работе FloraExpress: удобство заказа, качество сервиса, общение и впечатление от покупки.</p>
        </div>

        <?php if ($reviewNotice): ?><div class="review-msg review-msg--ok"><?= h($reviewNotice) ?></div><?php endif; ?>
        <?php if ($reviewError): ?><div class="review-msg review-msg--err"><?= h($reviewError) ?></div><?php endif; ?>

        <div class="review-form-card">
          <?php if (!$userId): ?>
            <h3 style="margin-top:0">Оставить отзыв</h3>
            <p class="review-help">Чтобы оставить отзыв о магазине, нужно войти в аккаунт и иметь хотя бы один оформленный заказ.</p>
            <div class="row" style="gap:10px;flex-wrap:wrap;margin-top:14px">
              <a class="btn" href="auth.php?mode=login&return=<?= urlencode('index.php#store-reviews') ?>">Войти</a>
              <a class="btn btn--ghost" href="auth.php?mode=register&return=<?= urlencode('index.php#store-reviews') ?>">Создать аккаунт</a>
            </div>
          <?php elseif (!$canLeaveStoreReview): ?>
            <h3 style="margin-top:0">Оставить отзыв</h3>
            <p class="review-help">Отзыв о магазине можно оставить после заказа. Как только у вас появится заказ в системе, форма станет доступной здесь на главной странице.</p>
            <div class="row" style="gap:10px;flex-wrap:wrap;margin-top:14px">
              <a class="btn" href="catalog.php">Выбрать букет</a>
              <a class="btn btn--ghost" href="account_orders.php">Мои заказы</a>
            </div>
          <?php else: ?>
            <h3 style="margin-top:0"><?= $userStoreReview ? 'Обновить отзыв о магазине' : 'Оставить отзыв о магазине' ?></h3>
            <form method="post" style="display:grid;gap:14px">
              <input type="hidden" name="action" value="save_store_review">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <div>
                <label style="display:block;font-weight:700;margin-bottom:8px">Оценка</label>
                <div class="review-radio-row">
                  <?php for ($i = 5; $i >= 1; $i--): ?>
                    <label class="review-radio">
                      <input type="radio" name="rating" value="<?= $i ?>" <?= $reviewFormRating === $i ? 'checked' : '' ?>>
                      <span><?= $i ?> ★</span>
                    </label>
                  <?php endfor; ?>
                </div>
              </div>
              <div>
                <label style="display:block;font-weight:700;margin-bottom:8px" for="review_text">Ваш отзыв</label>
                <textarea class="review-textarea" id="review_text" name="review_text" placeholder="Например: удобно оформлять заказ, быстро связались, букет был свежий и красиво собран."><?= h($reviewFormText) ?></textarea>
              </div>
              <div class="row" style="justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
                <p class="review-help" style="margin:0"><?= $userStoreReview ? 'Ваш прошлый отзыв будет обновлён.' : 'Отзыв сразу появится на главной странице.' ?></p>
                <button class="btn" type="submit">Сохранить отзыв</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="review-feed">
        <div class="review-feed__head">
          <div>
            <h3 style="margin:0">Лента отзывов</h3>
            <p class="review-help" style="margin-top:6px">Все отзывы находятся в одном окне. Их можно листать внутри блока справа.</p>
          </div>
          <div class="badge" style="display:inline-flex">Отзывов: <?= (int)$storeReviewStats['total_reviews'] ?></div>
        </div>

        <div class="review-feed__list">
          <?php if (!$storeReviewList): ?>
            <div class="review-card">
              <strong>Пока отзывов нет.</strong>
              <p class="review-card__text" style="margin-top:10px">Первые отзывы покупателей появятся здесь после оформленных заказов.</p>
            </div>
          <?php else: ?>
            <?php foreach ($storeReviewList as $review): ?>
              <article class="review-card">
                <div class="review-card__top">
                  <div>
                    <div class="review-card__name"><?= h($review['author_name'] ?? 'Покупатель FloraExpress') ?></div>
                    <div class="review-card__stars"><?= h(stars_line((int)($review['rating'] ?? 0))) ?></div>
                  </div>
                  <div class="review-card__date"><?= h(fmt_review_date($review['updated_at'] ?? $review['created_at'] ?? '')) ?></div>
                </div>
                <p class="review-card__text"><?= nl2br(h($review['review_text'] ?? '')) ?></p>
                <?php if ($canModerateStoreReviews): ?>
                  <div class="review-card__actions">
                    <form method="post" onsubmit="return confirm('Удалить этот отзыв?');">
                      <input type="hidden" name="action" value="delete_store_review">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="review_id" value="<?= (int)($review['id'] ?? 0) ?>">
                      <button class="review-delete-btn" type="submit">Удалить отзыв</button>
                    </form>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section qwrap">
  <div class="container">
    <div class="section__head" style="justify-content:center">
      <h2 class="section__title">Быстрый выбор <small>популярные категории</small></h2>
    </div>
    <div class="qcats">
      <a class="btn btn--ghost btn--sm" href="catalog.php?cat=roses">Розы</a>
      <a class="btn btn--ghost btn--sm" href="catalog.php?cat=tulips">Тюльпаны</a>
      <a class="btn btn--ghost btn--sm" href="catalog.php?cat=bouquets">Сборные букеты</a>
      <a class="btn btn--ghost btn--sm" href="catalog.php?cat=mini">Мини-букеты</a>
      <a class="btn btn--ghost btn--sm" href="catalog.php?cat=cards">Открытки</a>
      <a class="btn btn--ghost btn--sm" href="catalog.php?cat=balloons">Шарики</a>
    </div>
  </div>
</section>

<?php include __DIR__.'/footer.php'; ?>

<script>
function showHomeToast(msg) {
  let t = document.getElementById('home-toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'home-toast';
    t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#111827;color:#fff;padding:10px 20px;border-radius:12px;font-size:14px;z-index:9999;pointer-events:none;opacity:0;transition:opacity .2s ease';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.opacity = '1';
  clearTimeout(t._tid);
  t._tid = setTimeout(() => { t.style.opacity = '0'; }, 2200);
}

document.querySelectorAll('[data-fav-toggle]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const productId = btn.dataset.productId;
    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('action', 'toggle');
      fd.append('product_id', productId);
      const res = await fetch('favorite_api.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await res.json();
      if (data.ok) {
        btn.classList.toggle('is-active', !!data.active);
        btn.setAttribute('aria-label', data.active ? 'Убрать из избранного' : 'Добавить в избранное');
        btn.setAttribute('title', data.active ? 'Убрать из избранного' : 'Добавить в избранное');
        const favCount = document.getElementById('favCount');
        if (favCount && typeof data.count !== 'undefined') favCount.textContent = data.count;
        const topFav = document.querySelector('.fe-fav-link');
        if (topFav) topFav.classList.toggle('is-active', Number(data.count || 0) > 0);
        showHomeToast(data.active ? 'Добавлено в избранное' : 'Убрано из избранного');
      } else {
        if (data.auth) { window.location.href = 'auth.php?mode=login'; return; }
        showHomeToast('Ошибка: ' + (data.error || 'не удалось'));
      }
    } catch (_) {
      showHomeToast('Сеть недоступна');
    } finally {
      btn.disabled = false;
    }
  });
});

document.addEventListener('submit', async (e) => {
  const form = e.target.closest('form[data-addcart]');
  if (!form) return;
  e.preventDefault();

  const btn = form.querySelector('button[type="submit"]');
  const oldText = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Добавляем...'; }

  try {
    const fd = new FormData(form);
    const res = await fetch('cart_api.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();

    if (!res.ok || !data.ok) throw new Error(data.error || 'Не удалось добавить в корзину');

    const cartBadge = document.getElementById('cart-count');
    if (cartBadge && typeof data.count !== 'undefined') {
      cartBadge.textContent = String(data.count);
    }

    if (btn) btn.textContent = 'Добавлено';
    setTimeout(() => {
      if (btn) {
        btn.disabled = false;
        btn.textContent = oldText || 'В корзину';
      }
    }, 900);
  } catch (err) {
    alert(err.message || 'Ошибка при добавлении в корзину');
    if (btn) {
      btn.disabled = false;
      btn.textContent = oldText || 'В корзину';
    }
  }
});
</script>
</body>
</html>
