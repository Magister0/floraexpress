<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/favorites_lib.php';
$pdo = db();
favorites_init($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me) { header('Location: auth.php?mode=login'); exit; }

try{
  $canPhone = true;
  try{ $pdo->query("SELECT phone FROM users LIMIT 1"); }
  catch(Throwable $e){ $canPhone = false; }
  $sql = "SELECT id,name,email".($canPhone?",phone":"").",role FROM users WHERE id=?";
  $st  = $pdo->prepare($sql);
  $st->execute([(int)$me['id']]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) { $_SESSION['user'] = $row; $me = $row; }
}catch(Throwable $e){}

$name  = (string)($me['name'] ?? '');
$email = (string)($me['email'] ?? '');
$phone = (string)($me['phone'] ?? '');
$favoritesCount = favorites_count($pdo, (int)$me['id']);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Личный кабинет — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .lk-wrap{ max-width:860px; margin:0 auto; display:grid; gap:24px; }

    /* Баннер профиля */
    .lk-hero{
      background: linear-gradient(135deg, #0c4a34 0%, #166534 60%, #15803d 100%);
      border-radius: 20px;
      padding: 32px 36px;
      display: flex;
      align-items: center;
      gap: 24px;
      position: relative;
      overflow: hidden;
      box-shadow: 0 8px 32px rgba(12,74,52,.25);
    }
    .lk-hero::before{
      content:'';
      position:absolute;
      top:-40px; right:-40px;
      width:200px; height:200px;
      border-radius:50%;
      background:rgba(255,255,255,.06);
    }
    .lk-hero::after{
      content:'';
      position:absolute;
      bottom:-60px; right:80px;
      width:160px; height:160px;
      border-radius:50%;
      background:rgba(255,255,255,.04);
    }
    .lk-avatar{
      width:72px; height:72px;
      border-radius:50%;
      background:rgba(255,255,255,.18);
      border:2px solid rgba(255,255,255,.35);
      display:flex; align-items:center; justify-content:center;
      font-size:28px; font-weight:900; color:#fff;
      flex-shrink:0; letter-spacing:-.02em;
      backdrop-filter:blur(4px);
    }
    .lk-hero__info{ flex:1; min-width:0; }
    .lk-hero__greeting{ color:rgba(255,255,255,.7); font-size:14px; margin:0 0 4px; }
    .lk-hero__name{ color:#fff; font-size:26px; font-weight:900; margin:0 0 6px; }
    .lk-hero__meta{ display:flex; flex-wrap:wrap; gap:12px; }
    .lk-hero__meta span{ color:rgba(255,255,255,.65); font-size:13px; }
    .lk-hero__edit{
      flex-shrink:0;
      background:rgba(255,255,255,.15);
      border:1px solid rgba(255,255,255,.25);
      color:#fff; border-radius:10px;
      padding:8px 16px; font-size:14px; font-weight:600;
      text-decoration:none; transition:.18s ease;
      backdrop-filter:blur(4px);
    }
    .lk-hero__edit:hover{ background:rgba(255,255,255,.25); }

    /* Карточки */
    .lk-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:16px; }
    .lk-card{
      background:#fff;
      border:1px solid #e8edf2;
      border-radius:18px;
      padding:22px;
      text-decoration:none;
      color:inherit;
      display:flex;
      gap:16px;
      align-items:flex-start;
      box-shadow:0 2px 8px rgba(15,23,42,.05);
      transition:.18s ease;
    }
    .lk-card:hover{ box-shadow:0 6px 20px rgba(15,23,42,.1); transform:translateY(-2px); border-color:#c7dfd1; }
    .lk-card__icon{
      width:48px; height:48px; border-radius:14px;
      display:flex; align-items:center; justify-content:center;
      flex-shrink:0;
    }
    .lk-card__icon svg{ width:24px; height:24px; }
    .lk-card__icon--green{ background:#ecfdf5; color:#16a34a; }
    .lk-card__icon--blue{  background:#eff6ff; color:#2563eb; }
    .lk-card__icon--red{   background:#fff1f2; color:#e11d48; }
    .lk-card__icon--amber{ background:#fffbeb; color:#d97706; }
    .lk-card__body{ flex:1; min-width:0; }
    .lk-card__title{ font-size:16px; font-weight:800; margin:0 0 4px; color:#0f172a; }
    .lk-card__desc{ font-size:13px; color:#6b7280; margin:0; line-height:1.5; }
    .lk-card__badge{
      display:inline-block; margin-top:8px;
      background:#ecfdf5; color:#166534;
      border:1px solid #bbf7d0; border-radius:999px;
      padding:2px 10px; font-size:12px; font-weight:700;
    }
    .lk-card__arrow{ color:#d1d5db; margin-left:auto; align-self:center; flex-shrink:0; }

    @media(max-width:640px){
      .lk-hero{ flex-direction:column; align-items:flex-start; padding:24px 20px; }
      .lk-grid{ grid-template-columns:1fr; }
      .lk-hero__edit{ align-self:flex-start; }
    }
  </style>
</head>
<body>
<?php include __DIR__.'/header.php'; ?>
<section class="section">
  <div class="container">
    <div class="lk-wrap">

      <!-- Профильный баннер -->
      <div class="lk-hero">
        <div class="lk-avatar"><?= mb_strtoupper(mb_substr($name ?: 'Я', 0, 1, 'UTF-8'), 'UTF-8') ?></div>
        <div class="lk-hero__info">
          <p class="lk-hero__greeting">Личный кабинет</p>
          <p class="lk-hero__name"><?= htmlspecialchars($name ?: 'Гость') ?></p>
          <div class="lk-hero__meta">
            <?php if ($email): ?><span>✉ <?= htmlspecialchars($email) ?></span><?php endif; ?>
            <?php if ($phone): ?><span>📞 <?= htmlspecialchars($phone) ?></span><?php endif; ?>
          </div>
        </div>
        <a class="lk-hero__edit" href="account_profile.php">Редактировать</a>
      </div>

      <!-- Карточки разделов -->
      <div class="lk-grid">
        <a class="lk-card" href="account_orders.php">
          <div class="lk-card__icon lk-card__icon--blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
          </div>
          <div class="lk-card__body">
            <p class="lk-card__title">Мои заказы</p>
            <p class="lk-card__desc">История покупок и отслеживание доставки</p>
          </div>
          <svg class="lk-card__arrow" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
        </a>

        <a class="lk-card" href="account_favorites.php">
          <div class="lk-card__icon lk-card__icon--red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          </div>
          <div class="lk-card__body">
            <p class="lk-card__title">Избранное</p>
            <p class="lk-card__desc">Сохранённые товары</p>
            <?php if ($favoritesCount > 0): ?>
              <span class="lk-card__badge"><?= (int)$favoritesCount ?> товар<?= $favoritesCount === 1 ? '' : ($favoritesCount < 5 ? 'а' : 'ов') ?></span>
            <?php endif; ?>
          </div>
          <svg class="lk-card__arrow" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
        </a>

        <a class="lk-card" href="account_addresses.php">
          <div class="lk-card__icon lk-card__icon--amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
          </div>
          <div class="lk-card__body">
            <p class="lk-card__title">Адресная книга</p>
            <p class="lk-card__desc">Сохранённые адреса доставки</p>
          </div>
          <svg class="lk-card__arrow" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
        </a>

        <a class="lk-card" href="catalog.php">
          <div class="lk-card__icon lk-card__icon--green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          </div>
          <div class="lk-card__body">
            <p class="lk-card__title">Каталог</p>
            <p class="lk-card__desc">Перейти за новыми букетами и цветами</p>
          </div>
          <svg class="lk-card__arrow" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
        </a>
      </div>

    </div>
  </div>
</section>
<?php include __DIR__.'/footer.php'; ?>
</body>
</html>
