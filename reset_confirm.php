<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';


$pdo = db();
$token = trim((string)($_GET['token'] ?? ''));
$error = $notice = null;
$user = null;

if ($token !== '') {
    $st = $pdo->prepare("SELECT id, name, email FROM users WHERE reset_token = ? AND reset_token_expires > NOW() LIMIT 1");
    $st->execute([$token]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($token === '' || !$user) {
    $error = 'Ссылка недействительна или устарела. Запросите сброс пароля заново.';
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (strlen($pass) < 6) {
        $error = 'Пароль должен быть не короче 6 символов.';
    } elseif ($pass !== $pass2) {
        $error = 'Пароли не совпадают.';
    } else {
        $pdo->prepare("UPDATE users SET pass_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
        $notice = 'Пароль успешно изменён. Теперь вы можете войти.';
        $user = null;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Новый пароль — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .auth{max-width:420px;margin:40px auto;background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:24px}
    .note{background:#ecfdf3;border:1px solid #6ee7b7;color:#065f46;padding:10px 14px;border-radius:10px;margin:10px 0}
    .err{background:#fee2e2;border:1px solid #fecdd3;color:#7f1d1d;padding:10px 14px;border-radius:10px;margin:10px 0}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <div class="auth">
      <h1 style="margin:0 0 8px">Новый пароль</h1>

      <?php if ($notice): ?>
        <div class="note"><?= h($notice) ?></div>
        <div style="margin-top:14px">
          <a class="btn" href="auth.php?mode=login">Войти в аккаунт</a>
        </div>
      <?php elseif ($error): ?>
        <div class="err"><?= h($error) ?></div>
        <div style="margin-top:14px">
          <a class="btn btn--ghost btn--sm" href="reset_password.php">Запросить новую ссылку</a>
        </div>
      <?php else: ?>
        <p class="mute">Придумайте новый пароль для аккаунта <strong><?= h($user['email']) ?></strong>.</p>
        <form method="post" style="display:grid;gap:12px;margin-top:14px">
          <input class="input" type="password" name="password" placeholder="Новый пароль (минимум 6 символов)" required>
          <input class="input" type="password" name="password2" placeholder="Повторите пароль" required>
          <button class="btn" type="submit">Сохранить пароль</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
