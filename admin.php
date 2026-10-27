<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$user = $_SESSION['user'] ?? null;
if (!$user || $user['role'] !== 'admin') {
    header('Location: auth.php?mode=login');
    exit;
}

$db = db();
$msg = $err = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        $err = 'Сессия устарела, обновите страницу';
    }
}

$allowedRoles = ['user', 'manager', 'courier', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create' && !$err) {
    try {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $pass    = (string)($_POST['password'] ?? '');
        $roleRaw = trim($_POST['role'] ?? 'user');
        $role    = in_array($roleRaw, $allowedRoles, true) ? $roleRaw : 'user';

        if ($name === '' || $email === '' || $pass === '') {
            throw new RuntimeException('Заполните все поля');
        }

        $vkId  = trim($_POST['vk_user_id'] ?? '');
        $tgId  = trim($_POST['telegram_chat_id'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $st = $db->prepare('
            INSERT INTO users (email, name, pass_hash, role, created_at, vk_user_id, telegram_chat_id, phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $st->execute([
            $email,
            $name,
            password_hash($pass, PASSWORD_DEFAULT),
            $role,
            date('Y-m-d H:i:s'),
            $vkId !== '' ? (int)$vkId : null,
            $tgId !== '' ? (int)$tgId : null,
            $phone !== '' ? $phone : null,
        ]);
        $msg = 'Пользователь создан';
    } catch (Throwable $e) {
        $err = 'Такой email уже существует или ошибка данных';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset' && !$err) {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['newpass'] ?? '');
        if (!$id || $pass === '') {
            throw new RuntimeException('Укажите новый пароль');
        }

        $st = $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?');
        $st->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        $msg = 'Пароль обновлён';
    } catch (Throwable $e) {
        $err = 'Не удалось обновить пароль';
    }
}

if (($_GET['action'] ?? '') === 'role' && isset($_GET['id'], $_GET['to'])) {
    $id = (int)$_GET['id'];
    $toRaw = (string)$_GET['to'];
    $to = in_array($toRaw, $allowedRoles, true) ? $toRaw : 'user';

    if ($id !== (int)$user['id']) {
        $st = $db->prepare('UPDATE users SET role = ? WHERE id = ?');
        $st->execute([$to, $id]);
        $msg = 'Роль обновлена';
    } else {
        $err = 'Нельзя менять собственную роль';
    }

    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_courier' && !$err) {
    try {
        $id    = (int)($_POST['id'] ?? 0);
        $vkId  = trim($_POST['vk_user_id'] ?? '');
        $tgId  = trim($_POST['telegram_chat_id'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!$id) throw new RuntimeException('Не указан ID');

        $db->prepare("UPDATE users SET vk_user_id = ?, telegram_chat_id = ?, phone = ? WHERE id = ? AND role = 'courier'")
           ->execute([
               $vkId !== '' ? (int)$vkId : null,
               $tgId !== '' ? (int)$tgId : null,
               $phone !== '' ? $phone : null,
               $id,
           ]);
        $msg = 'Данные курьера обновлены';
    } catch (Throwable $e) {
        $err = 'Ошибка: ' . $e->getMessage();
    }
}

$users = $db->query('SELECT id, email, name, role, created_at FROM users ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$couriers = $db->query("SELECT id, name, email, phone, telegram_chat_id, vk_user_id, on_shift FROM users WHERE role = 'courier' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Админ-панель — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .admin{display:grid; gap:16px; max-width:1000px; margin:0 auto;}
    .card-box{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:16px;}
    table{width:100%; border-collapse: collapse;}
    th,td{padding:10px 12px; border-bottom:1px solid #f0f3f7; text-align:left;}
    th{color:#6b7280; font-weight:700;}
    .tag{padding:4px 10px;border-radius:999px;border:1px solid #e5e7eb;display:inline-block;font-size:12px;}
    .ok{background:#ecfeff;border-color:#bae6fd;color:#075985;}
    .err{background:#fee2e2;border-color:#fecdd3;color:#7f1d1d;}
    @media (max-width:780px){ .hide-sm{display:none;} }
  </style>
</head>
<body>

<?php include __DIR__.'/header.php'; ?>

<section class="section">
  <div class="container admin">
    <h1 class="section__title" style="font-size:28px;">Админ-панель</h1>
    <p class="section__sub">Управление пользователями.</p>

    <?php if($msg): ?><div class="tag ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if($err): ?><div class="tag err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="card-box">
      <h3 style="margin:0 0 14px;">Курьеры</h3>
      <?php if ($couriers): ?>
        <?php foreach ($couriers as $c): ?>
        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:12px;">
          <div style="font-weight:600;margin-bottom:8px;">
            <?= htmlspecialchars($c['name']) ?>
            <span style="font-weight:400;color:#6b7280;font-size:13px;">(#<?= (int)$c['id'] ?> · <?= htmlspecialchars($c['email']) ?>)</span>
            <?php if ($c['on_shift']): ?>
              <span class="tag ok" style="font-size:11px;padding:2px 8px;">на смене</span>
            <?php else: ?>
              <span class="tag" style="font-size:11px;padding:2px 8px;">не на смене</span>
            <?php endif; ?>
          </div>
          <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="hidden" name="action" value="update_courier">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
              Телефон
              <input class="input" style="width:160px" name="phone" placeholder="+7 (900) 000-00-00"
                     value="<?= htmlspecialchars((string)($c['phone'] ?? '')) ?>" data-phone-mask inputmode="tel">
            </label>
            <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
              VK ID
              <input class="input" style="width:160px" name="vk_user_id" placeholder="VK ID"
                     value="<?= htmlspecialchars((string)($c['vk_user_id'] ?? '')) ?>">
            </label>
            <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
              Telegram Chat ID
              <input class="input" style="width:180px" name="telegram_chat_id" placeholder="Telegram Chat ID"
                     value="<?= htmlspecialchars((string)($c['telegram_chat_id'] ?? '')) ?>">
            </label>
            <button class="btn btn--sm" type="submit" style="align-self:flex-end;">Сохранить</button>
          </form>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="color:#6b7280;">Курьеров пока нет. Создайте пользователя с ролью <b>courier</b>.</p>
      <?php endif; ?>
    </div>

    <div class="card-box">
      <h3 style="margin:0 0 10px;">Создать пользователя</h3>
      <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
          Имя
          <input class="input" style="width:180px" name="name" placeholder="Имя" required>
        </label>
        <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
          Email
          <input class="input" style="width:220px" type="email" name="email" placeholder="Email" required>
        </label>
        <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
          Пароль
          <input class="input" style="width:180px" type="text" name="password" placeholder="Пароль" required>
        </label>
        <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
          Роль
          <select class="input" name="role" id="create-role" style="width:140px;padding:10px 12px;border-radius:10px;" onchange="toggleCourierFields()">
            <option value="user">user</option>
            <option value="manager">manager</option>
            <option value="courier">courier</option>
            <option value="admin">admin</option>
          </select>
        </label>
        <div id="courier-fields" style="display:none;flex-wrap:wrap;gap:10px;width:100%;">
          <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
            Телефон
            <input class="input" style="width:160px" name="phone" placeholder="+7 (900) 000-00-00" data-phone-mask inputmode="tel">
          </label>
          <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
            VK ID
            <input class="input" style="width:160px" name="vk_user_id" placeholder="необязательно">
          </label>
          <label style="font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px;">
            Telegram Chat ID
            <input class="input" style="width:180px" name="telegram_chat_id" placeholder="необязательно">
          </label>
        </div>
        <button class="btn btn--sm" type="submit" style="align-self:flex-end;">Создать</button>
      </form>
      <script>
        function toggleCourierFields() {
          var role = document.getElementById('create-role').value;
          var fields = document.getElementById('courier-fields');
          fields.style.display = role === 'courier' ? 'flex' : 'none';
          if (role === 'courier' && window.FloraUi) {
            FloraUi.bindPhoneMasks(fields);
          }
        }
      </script>
    </div>

    <div class="card-box">
      <h3 style="margin:0 0 10px;">Пользователи</h3>
      <div style="overflow:auto;">
        <table>
          <thead>
            <tr><th>ID</th><th>Имя</th><th>Email</th><th>Роль</th><th class="hide-sm">Создан</th><th>Действия</th></tr>
          </thead>
          <tbody>
          <?php foreach($users as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= htmlspecialchars($u['name']) ?></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="tag"><?= htmlspecialchars($u['role']) ?></span></td>
              <td class="hide-sm"><?= htmlspecialchars($u['created_at']) ?></td>
              <td class="row" style="gap:8px; flex-wrap:wrap;">
                <?php if ($u['id'] !== (int)$user['id']): ?>
                  <a class="btn btn--ghost btn--sm" href="admin.php?action=role&id=<?= $u['id'] ?>&to=user">user</a>
                  <a class="btn btn--ghost btn--sm" href="admin.php?action=role&id=<?= $u['id'] ?>&to=manager">manager</a>
                  <a class="btn btn--ghost btn--sm" href="admin.php?action=role&id=<?= $u['id'] ?>&to=courier">courier</a>
                  <a class="btn btn--ghost btn--sm" href="admin.php?action=role&id=<?= $u['id'] ?>&to=admin">admin</a>
                <?php endif; ?>

                <form method="post" style="display:inline-flex; gap:6px;">
                  <input type="hidden" name="action" value="reset">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input class="input" style="width:160px" name="newpass" placeholder="Новый пароль" required>
                  <button class="btn btn--ghost btn--sm" type="submit">Сбросить пароль</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>
<script src="site_ui.js"></script>
</body>
</html>