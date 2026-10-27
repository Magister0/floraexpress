<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/product_admin_lib.php';
require_once __DIR__ . '/extra_services_lib.php';

$pdo = db();
extra_services_bootstrap($pdo);
pa_require_staff('extra_services.php');
$csrf = pa_csrf();

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        pa_check_csrf();
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Услуга не найдена.');
        }
        if ($action === 'toggle') {
            $st = $pdo->prepare('UPDATE extra_services SET is_active = NOT is_active WHERE id = ?');
            $st->execute([$id]);
            $msg = 'Статус услуги обновлён.';
        } elseif ($action === 'delete') {
            $st = $pdo->prepare('DELETE FROM extra_services WHERE id = ?');
            $st->execute([$id]);
            $msg = 'Услуга удалена.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$rows = extra_services_fetch_all($pdo, false);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Дополнительные услуги — FloraExpress</title>
<link rel="stylesheet" href="styles.css">
<style>
.msg{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:700}.msg.ok{background:#ecfdf3;border:1px solid #c9f1d8;color:#166534}.msg.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
.service-table{background:#fff;border:1px solid #e7efe9;border-radius:20px;box-shadow:var(--shadow-1);overflow:hidden}
.service-table table{width:100%;border-collapse:collapse}.service-table th,.service-table td{padding:14px 16px;border-bottom:1px solid #eef3f0;vertical-align:middle}
.service-table th{font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.service-thumb{width:72px;height:72px;border-radius:16px;object-fit:cover;border:1px solid #e6ece8;background:#f8fafc}
.actions{display:flex;gap:8px;flex-wrap:wrap}.toolbar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:16px}.status-pill{display:inline-flex;padding:7px 12px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-weight:700;font-size:13px}.status-pill.off{background:#f8fafc;border-color:#e2e8f0;color:#64748b}
@media (max-width:900px){.service-table{overflow:auto;}}
</style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section"><div class="container">
  <div class="toolbar">
    <div>
      <h1 class="section__title" style="margin:0">Дополнительные услуги</h1>
      <p class="section__sub">Карточки, шарики, ленты и другие дополнения для оформления заказа.</p>
    </div>
    <a class="btn" href="extra_service_form.php">Добавить услугу</a>
  </div>

  <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

  <div class="service-table">
    <table>
      <thead>
        <tr>
          <th>Изображение</th>
          <th>Название</th>
          <th>Цена</th>
          <th>Текст на открытке</th>
          <th>Статус</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="mute">Услуги пока не добавлены.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><img class="service-thumb" src="<?= h(extra_services_image_src($row['image'] ?? '', (string)($row['name'] ?? 'Услуга'))) ?>" alt="<?= h($row['name'] ?? 'Услуга') ?>"></td>
            <td>
              <div style="font-weight:800"><?= h($row['name'] ?? '') ?></div>
              <div class="mute" style="margin-top:4px;max-width:420px"><?= h($row['description'] ?? '') ?></div>
            </td>
            <td style="white-space:nowrap;color:#1f6b42;font-weight:700"><?= number_format((float)($row['price'] ?? 0), 0, ',', ' ') ?> ₽</td>
            <td><?= !empty($row['allow_message']) ? 'Да' : 'Нет' ?></td>
            <td><span class="status-pill<?= !empty($row['is_active']) ? '' : ' off' ?>"><?= !empty($row['is_active']) ? 'Активна' : 'Скрыта' ?></span></td>
            <td>
              <div class="actions">
                <a class="btn btn--ghost btn--sm" href="extra_service_form.php?id=<?= (int)$row['id'] ?>">Изменить</a>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit"><?= !empty($row['is_active']) ? 'Скрыть' : 'Показать' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Удалить услугу?')">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit">Удалить</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div></section>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
