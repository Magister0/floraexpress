<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) { header('Location: auth.php?mode=login&return='.urlencode('account_profile.php')); exit; }

/* гарантируем колонку phone (SQLite) */
function ensurePhoneColumn(PDO $pdo){
  try{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
      $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($cols as $c) if (isset($c['name']) && strtolower($c['name'])==='phone') return;
      $pdo->exec("ALTER TABLE users ADD COLUMN phone TEXT NULL");
    }
  } catch(Throwable $e){}
}
ensurePhoneColumn($pdo);

/* CSRF */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

$ok = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try{
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
      throw new RuntimeException('Сессия устарела, обновите страницу.');
    }

    $name  = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if ($name === '') throw new RuntimeException('Укажите имя.');
    if ($phone !== '') {
      $phone = preg_replace('~[^\d\+\-\(\)\s]~u','',$phone);
      if (!preg_match('~^\+?\d[\d\-\s\(\)]{6,}$~u', $phone)) {
        throw new RuntimeException('Телефон указан в неверном формате.');
      }
    }

    // проверяем, что phone реально есть
    $canPhone = true;
    try{ $pdo->query("SELECT phone FROM users LIMIT 1"); } catch(Throwable $e){ $canPhone=false; }

    if ($canPhone) {
      $st = $pdo->prepare("UPDATE users SET name=?, phone=? WHERE id=?");
      $st->execute([$name, $phone !== '' ? $phone : null, (int)$me['id']]);
    } else {
      $st = $pdo->prepare("UPDATE users SET name=? WHERE id=?");
      $st->execute([$name, (int)$me['id']]);
    }

    $st2 = $pdo->prepare("SELECT id,name,email".($canPhone?",phone":"").",role FROM users WHERE id=?");
    $st2->execute([(int)$me['id']]);
    $_SESSION['user'] = $st2->fetch(PDO::FETCH_ASSOC) ?: $_SESSION['user'];

    $ok = 'Изменения сохранены.';
  } catch(Throwable $e){
    $err = $e->getMessage();
  }
}

$me = $_SESSION['user'];
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$cartCount = array_sum($_SESSION['cart']);

$name  = (string)($me['name'] ?? '');
$phone = (string)($me['phone'] ?? '');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Профиль — Личный кабинет</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .lk-wrap{ max-width:860px; margin:0 auto; display:grid; gap:16px; }
    .lk-back{ display:inline-flex; align-items:center; gap:6px; color:#6b7280; font-size:14px; text-decoration:none; }
    .lk-back:hover{ color:#0f172a; }
    .lk-tabs{ display:flex; gap:4px; background:#fff; border:1px solid #e8edf2; border-radius:14px; padding:6px; box-shadow:0 2px 8px rgba(15,23,42,.05); flex-wrap:wrap; }
    .lk-tab{ padding:8px 16px; border-radius:10px; color:#6b7280; text-decoration:none; font-size:14px; font-weight:600; transition:.15s ease; }
    .lk-tab:hover{ background:#f3f6f4; color:#0f172a; }
    .lk-tab.active{ background:var(--pistachio-soft); color:#0f172a; border:1px solid #cfe9d9; }
    .acc-box{ background:#fff; border:1px solid var(--surface-2); border-radius:16px; padding:24px; box-shadow:var(--shadow-1); }
    .form-row{ display:grid; grid-template-columns:180px 1fr; gap:12px; align-items:center; margin-bottom:12px; }
    @media(max-width:640px){ .form-row{ grid-template-columns:1fr; } .lk-tab{ font-size:13px; padding:7px 12px; } }
    .msg-ok{ background:#E8F5EC; border:1px solid #cfe9d9; padding:10px 12px; border-radius:10px; color:#124d31; margin-bottom:12px; }
    .msg-err{ background:#fee2e2; border:1px solid #fecdd3; padding:10px 12px; border-radius:10px; color:#7f1d1d; margin-bottom:12px; }
    label{ color:#374151; }
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
        <a class="lk-tab active" href="account_profile.php">Профиль</a>
        <a class="lk-tab" href="account_favorites.php">Избранное</a>
        <a class="lk-tab" href="account_addresses.php">Адреса</a>
      </nav>
      <main class="acc-box">
        <h2 style="margin:0 0 8px">Профиль</h2>
        <p class="mute" style="margin:0 0 14px">Измени имя и телефон, чтобы ускорить оформление доставки.</p>

        <?php if ($ok): ?><div class="msg-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <form method="post" action="account_profile.php" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="form-row">
            <label for="name">Имя</label>
            <input class="input" id="name" name="name" required value="<?= htmlspecialchars($name) ?>">
          </div>
          <div class="form-row">
            <label for="phone">Телефон</label>
            <input class="input" id="phone" name="phone" placeholder="+7 (900) 000-00-00" value="<?= htmlspecialchars($phone) ?>" data-phone-mask inputmode="tel">
          </div>
          <div class="row" style="gap:10px">
            <button class="btn" type="submit">Сохранить</button>
            <a class="btn btn--ghost" href="account.php">Назад</a>
          </div>
        </form>
      </main>
    </div>
  </div>
</section>

<?php include __DIR__.'/footer.php'; ?>
</body>
</html>