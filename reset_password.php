<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';


$pdo = db();

// Добавляем колонки если нет
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires TIMESTAMP DEFAULT NULL");
} catch (Throwable $e) {}

$error = $notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Введите корректный email.';
    } else {
        $st = $pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?')
                ->execute([$token, $expires, $user['id']]);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $link = $protocol . '://' . $host . '/reset_confirm.php?token=' . $token;

            $subject = 'Сброс пароля — FloraExpress';
            $body = "Здравствуйте, {$user['name']}!\n\n"
                  . "Для сброса пароля перейдите по ссылке:\n{$link}\n\n"
                  . "Ссылка действительна 1 час.\n"
                  . "Если вы не запрашивали сброс — просто проигнорируйте это письмо.\n\n"
                  . "С уважением, FloraExpress";

            $sent = smtp_send($email, $subject, $body);

            // Если SMTP не настроен — сохраняем ссылку в лог-файл
            if (!$sent) {
                $logLine = date('Y-m-d H:i:s') . ' | ' . $email . ' | ' . $link . PHP_EOL;
                @file_put_contents(__DIR__ . '/reset_links.log', $logLine, FILE_APPEND | LOCK_EX);
            }
        }

        // Одинаковое сообщение — не раскрываем существует ли email
        $notice = 'Если этот email зарегистрирован, мы отправили письмо со ссылкой для сброса пароля.';
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Восстановление пароля — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .auth{max-width:420px;margin:40px auto;background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:24px}
    .note{background:#ecfeff;border:1px solid #bae6fd;color:#075985;padding:10px 14px;border-radius:10px;margin:10px 0}
    .err{background:#fee2e2;border:1px solid #fecdd3;color:#7f1d1d;padding:10px 14px;border-radius:10px;margin:10px 0}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <div class="auth">
      <h1 style="margin:0 0 8px">Восстановление пароля</h1>
      <p class="mute">Введите email вашего аккаунта — мы отправим ссылку для сброса пароля.</p>

      <?php if ($notice): ?><div class="note"><?= h($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

      <?php if (!$notice): ?>
      <form method="post" style="display:grid;gap:12px;margin-top:14px">
        <input class="input" type="email" name="email" placeholder="Email" required>
        <button class="btn" type="submit">Отправить письмо</button>
      </form>
      <?php endif; ?>

      <div style="margin-top:14px">
        <a class="btn btn--ghost btn--sm" href="auth.php?mode=login">Вернуться ко входу</a>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
