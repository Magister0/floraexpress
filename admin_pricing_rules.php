<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/pricing_lib.php';
require_once __DIR__ . '/audit_log_lib.php';

$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('admin_pricing_rules.php'));
    exit;
}
$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['admin','manager'], true)) {
    http_response_code(403);
    echo '403';
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('CSRF не прошёл проверку.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $saved = pricing_rule_save($pdo, $_POST, $id > 0 ? $id : null);
            audit_log_user_action($pdo, $id > 0 ? 'pricing_rule_update' : 'pricing_rule_create', 'pricing_rule', $saved, [
                'name' => (string)($_POST['name'] ?? ''),
            ]);
            $ok = 'Правило сохранено.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            pricing_rule_delete($pdo, $id);
            audit_log_user_action($pdo, 'pricing_rule_delete', 'pricing_rule', $id);
            $ok = 'Правило удалено.';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            pricing_rule_toggle($pdo, $id);
            audit_log_user_action($pdo, 'pricing_rule_toggle', 'pricing_rule', $id);
            $ok = 'Статус правила изменён.';
        }

        header('Location: admin_pricing_rules.php?ok=' . urlencode($ok));
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

if (isset($_GET['ok']) && $err === '') {
    $ok = (string)$_GET['ok'];
}

$rules = pricing_rule_list($pdo);

$editId = (int)($_GET['edit'] ?? 0);
$editRule = $editId > 0 ? pricing_rule_get($pdo, $editId) : null;
$isNew = isset($_GET['new']);

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

function fmt_dt(?string $v): string {
    if (!$v) return '';
    $v = preg_replace('~\.\d+$~', '', $v);
    return substr((string)$v, 0, 16);
}

function dt_input(?string $v): string {
    if (!$v) return '';
    $v = preg_replace('~\.\d+$~', '', $v);
    return str_replace(' ', 'T', substr((string)$v, 0, 16));
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Динамические цены — Админка FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .pr-wrap{max-width:1200px;margin:24px auto;padding:0 16px;display:grid;gap:18px}
    .pr-card{background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:20px;box-shadow:var(--shadow-1)}
    .pr-table{width:100%;border-collapse:collapse;font-size:14px}
    .pr-table th,.pr-table td{padding:10px 8px;border-bottom:1px solid #f0f4f1;text-align:left;vertical-align:top}
    .pr-table th{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;background:#f9fbfa;font-weight:700}
    .pr-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:700}
    .pr-tag--on{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
    .pr-tag--off{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
    .pr-tag--surge{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}
    .pr-tag--sale{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
    .pr-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .pr-form-grid .full{grid-column:1/-1}
    .pr-field{display:grid;gap:6px}
    .pr-field label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.3px}
    .notice{padding:10px 14px;border-radius:12px;margin:0 0 12px}
    .notice--err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .notice--ok {background:#ecfdf5;border:1px solid #bbf7d0;color:#166534}
    .pr-actions{display:flex;gap:8px;flex-wrap:wrap}
    .pr-mini{font-size:12px;color:#6b7280}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="pr-wrap">
  <div class="pr-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h1 style="margin:0;font-size:24px">Динамические цены</h1>
      <div class="pr-actions">
        <a class="btn btn--ghost" href="admin_orders.php">К заказам</a>
        <a class="btn" href="admin_pricing_rules.php?new=1">+ Новое правило</a>
      </div>
    </div>
    <p class="pr-mini" style="margin:10px 0 0">
      Правило умножает цену товара на коэффициент и/или прибавляет фиксированную сумму. Можно ограничить периодом, категорией или конкретными товарами. Если правил несколько — применяются в порядке приоритета.
    </p>

    <?php if ($err): ?><div class="notice notice--err" style="margin-top:12px"><?= $h($err) ?></div><?php endif; ?>
    <?php if ($ok && !$err): ?><div class="notice notice--ok" style="margin-top:12px"><?= $h($ok) ?></div><?php endif; ?>
  </div>

  <?php if ($isNew || $editRule):
    $r = $editRule ?? [];
  ?>
  <div class="pr-card">
    <h2 style="margin:0 0 16px;font-size:18px"><?= $editRule ? 'Редактирование правила #' . (int)$editRule['id'] : 'Новое правило' ?></h2>
    <form method="post" class="pr-form-grid">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <?php if ($editRule): ?><input type="hidden" name="id" value="<?= (int)$editRule['id'] ?>"><?php endif; ?>

      <div class="pr-field full">
        <label>Название</label>
        <input class="input" name="name" required value="<?= $h($r['name'] ?? '') ?>" placeholder="Например: 8 марта · общая наценка">
      </div>

      <div class="pr-field full">
        <label>Описание</label>
        <input class="input" name="description" value="<?= $h($r['description'] ?? '') ?>" placeholder="Видно только в админке">
      </div>

      <div class="pr-field">
        <label>Начало периода</label>
        <input class="input" type="datetime-local" name="starts_at" value="<?= $h(dt_input($r['starts_at'] ?? null)) ?>">
        <span class="pr-mini">Пусто = действует с любого момента</span>
      </div>

      <div class="pr-field">
        <label>Конец периода</label>
        <input class="input" type="datetime-local" name="ends_at" value="<?= $h(dt_input($r['ends_at'] ?? null)) ?>">
        <span class="pr-mini">Пусто = без срока</span>
      </div>

      <div class="pr-field">
        <label>Коэффициент</label>
        <input class="input" type="number" step="0.01" min="0.01" name="multiplier" required
               value="<?= $h(number_format((float)($r['multiplier'] ?? 1.0), 3, '.', '')) ?>">
        <span class="pr-mini">1.50 = +50%; 0.85 = −15%</span>
      </div>

      <div class="pr-field">
        <label>Фиксированная надбавка, ₽</label>
        <input class="input" type="number" step="0.01" name="surcharge_fixed"
               value="<?= $h(number_format((float)($r['surcharge_fixed'] ?? 0), 2, '.', '')) ?>">
      </div>

      <div class="pr-field">
        <label>Область применения</label>
        <select class="input" name="applies_to" id="applies_to_select">
          <?php foreach (['all'=>'Все товары','category'=>'Только указанные категории','product'=>'Только указанные товары'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= (string)($r['applies_to'] ?? 'all') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="pr-field">
        <label>Приоритет</label>
        <input class="input" type="number" name="priority" value="<?= (int)($r['priority'] ?? 0) ?>">
        <span class="pr-mini">Чем больше — тем раньше применяется</span>
      </div>

      <div class="pr-field full">
        <label>Категории (через запятую) — для applies_to=category</label>
        <?php
          $cats = $r['target_categories'] ?? '[]';
          if (is_string($cats)) { $cats = json_decode($cats, true) ?: []; }
          $catsStr = is_array($cats) ? implode(', ', $cats) : '';
        ?>
        <input class="input" name="target_categories" value="<?= $h($catsStr) ?>" placeholder="roses, tulips">
      </div>

      <div class="pr-field full">
        <label>ID товаров (через запятую) — для applies_to=product</label>
        <?php
          $ids = $r['target_product_ids'] ?? '[]';
          if (is_string($ids)) { $ids = json_decode($ids, true) ?: []; }
          $idsStr = is_array($ids) ? implode(', ', $ids) : '';
        ?>
        <input class="input" name="target_product_ids" value="<?= $h($idsStr) ?>" placeholder="3, 7, 12">
      </div>

      <div class="pr-field full">
        <label>Бейдж на карточке товара</label>
        <input class="input" name="badge_label" value="<?= $h($r['badge_label'] ?? '') ?>" placeholder="Например: Цена на 8 марта">
        <span class="pr-mini">Если пусто — показывается название правила</span>
      </div>

      <div class="pr-field full">
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:14px">
          <input type="checkbox" name="active" value="1" <?= !isset($r['active']) || !empty($r['active']) ? 'checked' : '' ?>>
          Правило активно
        </label>
      </div>

      <div class="full pr-actions">
        <button type="submit" class="btn">Сохранить</button>
        <a class="btn btn--ghost" href="admin_pricing_rules.php">Отмена</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="pr-card">
    <h2 style="margin:0 0 14px;font-size:18px">Все правила (<?= count($rules) ?>)</h2>
    <?php if (!$rules): ?>
      <p class="mute">Правил пока нет. Запустите <code>seed_pricing_rules.php</code>, чтобы залить демо-набор, или создайте вручную.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="pr-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Название</th>
            <th>Период</th>
            <th>Коэф.</th>
            <th>Область</th>
            <th>Приор.</th>
            <th>Статус</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rules as $row):
            $mult = (float)$row['multiplier'];
            $isSurge = $mult > 1.001;
            $isSale  = $mult < 0.999;
            $cats = $row['target_categories'] ?? '[]';
            if (is_string($cats)) $cats = json_decode($cats, true) ?: [];
            $ids = $row['target_product_ids'] ?? '[]';
            if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
        ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td>
              <div style="font-weight:700"><?= $h($row['name']) ?></div>
              <?php if (!empty($row['description'])): ?>
                <div class="pr-mini" style="margin-top:2px"><?= $h($row['description']) ?></div>
              <?php endif; ?>
              <?php if (!empty($row['badge_label'])): ?>
                <div class="pr-mini" style="margin-top:4px">Бейдж: «<?= $h($row['badge_label']) ?>»</div>
              <?php endif; ?>
            </td>
            <td class="pr-mini">
              <?php if ($row['starts_at']): ?>с <?= $h(fmt_dt($row['starts_at'])) ?><br><?php endif; ?>
              <?php if ($row['ends_at']):   ?>по <?= $h(fmt_dt($row['ends_at'])) ?><?php endif; ?>
              <?php if (!$row['starts_at'] && !$row['ends_at']): ?><span>бессрочно</span><?php endif; ?>
            </td>
            <td>
              <span class="pr-tag <?= $isSurge ? 'pr-tag--surge' : ($isSale ? 'pr-tag--sale' : 'pr-tag--off') ?>">
                ×<?= number_format($mult, 2) ?>
              </span>
              <?php if ((float)$row['surcharge_fixed'] !== 0.0): ?>
                <div class="pr-mini" style="margin-top:2px">+<?= number_format((float)$row['surcharge_fixed'], 0, ',', ' ') ?> ₽</div>
              <?php endif; ?>
            </td>
            <td class="pr-mini">
              <?php
                $aMap = ['all'=>'все товары','category'=>'категории','product'=>'товары'];
                echo $h($aMap[(string)$row['applies_to']] ?? $row['applies_to']);
              ?>
              <?php if ((string)$row['applies_to'] === 'category' && $cats): ?>
                <div style="margin-top:2px"><?= $h(implode(', ', $cats)) ?></div>
              <?php elseif ((string)$row['applies_to'] === 'product' && $ids): ?>
                <div style="margin-top:2px">ID: <?= $h(implode(', ', $ids)) ?></div>
              <?php endif; ?>
            </td>
            <td><?= (int)$row['priority'] ?></td>
            <td>
              <span class="pr-tag <?= !empty($row['active']) ? 'pr-tag--on' : 'pr-tag--off' ?>">
                <?= !empty($row['active']) ? 'активно' : 'выключено' ?>
              </span>
            </td>
            <td>
              <div class="pr-actions" style="gap:6px">
                <a class="btn btn--ghost btn--sm" href="admin_pricing_rules.php?edit=<?= (int)$row['id'] ?>">Изменить</a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit"><?= !empty($row['active']) ? 'Выкл.' : 'Вкл.' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Удалить правило безвозвратно?')">
                  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit" style="color:#b91c1c">Удалить</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
