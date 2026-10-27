<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit_log_lib.php';

$db   = db();
$mode = ($_GET['mode'] ?? 'login') === 'register' ? 'register' : 'login';
$error = $ok = null;

// вход
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    try {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');

        if ($email === '' || $pass === '') {
            throw new RuntimeException('Заполните почту и пароль');
        }

        $st = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if (!$u || !password_verify($pass, $u['pass_hash'])) {
            audit_log_write($db, null, null, 'login_failed', 'user', null, ['email' => $email]);
            throw new RuntimeException('Неверная почта или пароль');
        }

        // Защита от session fixation: новый ID после логина
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'    => $u['id'],
            'email' => $u['email'],
            'name'  => $u['name'],
            'role'  => $u['role'],
        ];

        audit_log_write($db, (int)$u['id'], (string)$u['role'], 'login_success', 'user', (int)$u['id']);

        header('Location: /index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// регистрация
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    try {
        $name            = trim((string)($_POST['name'] ?? ''));
        $email           = trim((string)($_POST['email'] ?? ''));
        $pass            = (string)($_POST['password'] ?? '');
        $captchaAnswer   = strtoupper(trim((string)($_POST['captcha_answer'] ?? '')));
        $captchaExpected = strtoupper((string)($_SESSION['register_captcha_code'] ?? ''));
        $websiteTrap     = trim((string)($_POST['website'] ?? ''));

        if ($websiteTrap !== '') {                   // от спам ботов
            throw new RuntimeException('Проверка не пройдена');
        }

        if ($name === '' || $email === '' || $pass === '' || $captchaAnswer === '') {
            throw new RuntimeException('Заполните все поля');
        }

        if (empty($_POST['privacy_consent'])) {
            throw new RuntimeException('Для регистрации необходимо согласие на обработку персональных данных.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Укажите корректный email');
        }

        if (str_len_safe($name) < 2) {
            throw new RuntimeException('Имя должно быть не короче 2 символов');
        }

        if (str_len_safe($pass) < 6) {
            throw new RuntimeException('Пароль должен быть не короче 6 символов');
        }

        if ($captchaExpected === '') {
            unset($_SESSION['register_captcha_code']);
            throw new RuntimeException('Капча недоступна. Обновите страницу.');
        }

        if (!hash_equals($captchaExpected, $captchaAnswer)) {
            unset($_SESSION['register_captcha_code']);
            throw new RuntimeException('Капча введена неверно');
        }

        $check = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetchColumn()) {
            unset($_SESSION['register_captcha_code']);
            throw new RuntimeException('Такой email уже зарегистрирован');
        }

        $st = $db->prepare('INSERT INTO users (email,name,pass_hash,role,created_at) VALUES (?,?,?,?,?) RETURNING id');
        $st->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'user', date('Y-m-d H:i:s')]);
        $newUserId = (int)$st->fetchColumn();

        // Фиксируем согласие на обработку ПДн: дата, версия Политики, IP — доказательство для суда / РКН.
        require_once __DIR__ . '/legal_versions.php';
        $consentVersion = PRIVACY_VERSION;
        $consentIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            $db->prepare('UPDATE users SET privacy_consent_at = NOW(), privacy_consent_version = ?, privacy_consent_ip = ? WHERE id = ?')
               ->execute([$consentVersion, $consentIp, $newUserId]);
        } catch (Throwable $e) { /* колонок может ещё не быть — прогоните migrate_schema.php */ }

        audit_log_write($db, $newUserId, 'user', 'register', 'user', $newUserId, [
            'email' => $email,
            'privacy_consent_version' => $consentVersion,
        ]);

        unset($_SESSION['register_captcha_code']);
        $ok = 'Аккаунт создан. Теперь войдите.';
        $mode = 'login';
    } catch (PDOException $e) {
        unset($_SESSION['register_captcha_code']);
        $msg = strtolower($e->getMessage());

        if (strpos($msg, 'unique') !== false || strpos($msg, 'duplicate') !== false) {
            $error = 'Такой email уже зарегистрирован';
        } else {
            $error = 'Ошибка при регистрации. Попробуйте ещё раз.';
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        unset($_SESSION['register_captcha_code']);
        $error = 'Ошибка при регистрации. Попробуйте ещё раз.';
    }
}

// если уже вошли — на главную
if (!empty($_SESSION['user'])) {
    header('Location: /index.php');
    exit;
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= $mode === 'register' ? 'Регистрация' : 'Вход' ?> — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .auth{max-width:420px;margin:40px auto;background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:20px;}
    .auth h1{margin:0 0 10px;}
    .note{background:#ecfeff;border:1px solid #bae6fd;color:#075985;padding:8px 12px;border-radius:10px;margin:10px 0;}
    .err{background:#fee2e2;border:1px solid #fecdd3;color:#7f1d1d;padding:8px 12px;border-radius:10px;margin:10px 0;}
    .captcha-box{display:grid;gap:10px;}
    .captcha-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .captcha-image-wrap{display:flex;align-items:center;gap:10px;}
    .captcha-image{display:block;width:180px;height:56px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;cursor:pointer;}
    .captcha-refresh{min-width:auto}
    .hp-field{position:absolute !important;left:-9999px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important;}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section">
  <div class="container">
    <div class="auth">
      <h1><?= $mode === 'register' ? 'Регистрация' : 'Вход в аккаунт' ?></h1>
      <p class="mute">
        <?= $mode === 'register'
          ? 'Создайте учётную запись для заказа и отслеживания.'
          : 'Введите почту и пароль.' ?>
      </p>

      <?php if ($ok): ?><div class="note"><?= h($ok) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

      <?php if ($mode === 'register'): ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="register">
          <input class="hp-field" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">

          <div style="display:grid; gap:10px; margin-top:10px;">
            <input class="input" type="text" name="name" placeholder="Имя" required>
            <input class="input" type="email" name="email" placeholder="Email" required>
            <input class="input" type="password" name="password" placeholder="Пароль" required>

            <div class="captcha-box">
              <label class="mute" for="captcha_answer">Подтвердите, что вы не робот</label>
              <div class="captcha-row">
                <div class="captcha-image-wrap">
                  <img src="captcha_image.php?t=<?= time() ?>" alt="Капча" class="captcha-image" id="captchaImage">
                  <button class="btn btn--ghost btn--sm captcha-refresh" type="button" id="captchaRefresh">Обновить</button>
                </div>
              </div>
              <input class="input" type="text" name="captcha_answer" id="captcha_answer" placeholder="Введите код с картинки" required autocomplete="off">
            </div>

            <label class="privacy-consent" style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:#4b5563;line-height:1.5;cursor:pointer;padding:8px 4px">
              <input type="checkbox" name="privacy_consent" value="1" required style="margin-top:3px;flex-shrink:0;width:16px;height:16px;cursor:pointer">
              <span>Я даю согласие на обработку моих персональных данных в соответствии с <a href="privacy.php" target="_blank" style="color:#0f6b45;text-decoration:underline">Политикой конфиденциальности</a> и принимаю условия <a href="offer.php" target="_blank" style="color:#0f6b45;text-decoration:underline">Публичной оферты</a>.</span>
            </label>

            <button class="btn" type="submit">Зарегистрироваться</button>
          </div>
        </form>
        <div style="margin-top:10px;">
          <a class="btn btn--ghost btn--sm" href="auth.php?mode=login">У меня уже есть аккаунт</a>
        </div>
      <?php else: ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="login">
          <div style="display:grid; gap:10px; margin-top:10px;">
            <input class="input" type="email" name="email" placeholder="Email" required>
            <input class="input" type="password" name="password" placeholder="Пароль" required>
            <button class="btn" type="submit">Войти</button>
          </div>
        </form>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn btn--ghost btn--sm" href="auth.php?mode=register">Создать аккаунт</a>
        </div>
      <?php endif; ?>

    </div>
  </div>
</section>

<?php if ($mode === 'register'): ?>
<script>
(function(){
  const img = document.getElementById('captchaImage');
  const btn = document.getElementById('captchaRefresh');
  if (!img || !btn) return;

  function refreshCaptcha(){
    img.src = 'captcha_image.php?t=' + Date.now();
  }

  btn.addEventListener('click', refreshCaptcha);
  img.addEventListener('click', refreshCaptcha);
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
