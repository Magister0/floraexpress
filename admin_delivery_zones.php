<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/delivery_lib.php';
require_once __DIR__ . '/audit_log_lib.php';

$pdo = db();
delivery_ensure_schema($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('admin_delivery_zones.php'));
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

        if ($action === 'save_zone') {
            $id = (int)($_POST['id'] ?? 0);
            $saved = delivery_zone_save($pdo, $_POST, $id > 0 ? $id : null);
            audit_log_user_action($pdo, $id > 0 ? 'delivery_zone_update' : 'delivery_zone_create', 'delivery_zone', $saved, ['name' => (string)($_POST['name'] ?? '')]);
            $ok = 'Зона сохранена.';
        } elseif ($action === 'delete_zone') {
            $id = (int)($_POST['id'] ?? 0);
            delivery_zone_delete($pdo, $id);
            audit_log_user_action($pdo, 'delivery_zone_delete', 'delivery_zone', $id);
            $ok = 'Зона удалена.';
        } elseif ($action === 'toggle_zone') {
            delivery_zone_toggle($pdo, (int)($_POST['id'] ?? 0));
            $ok = 'Статус зоны изменён.';
        } elseif ($action === 'save_mod') {
            $id = (int)($_POST['id'] ?? 0);
            $saved = delivery_modifier_save($pdo, $_POST, $id > 0 ? $id : null);
            audit_log_user_action($pdo, $id > 0 ? 'delivery_mod_update' : 'delivery_mod_create', 'delivery_modifier', $saved, ['name' => (string)($_POST['name'] ?? '')]);
            $ok = 'Модификатор сохранён.';
        } elseif ($action === 'delete_mod') {
            delivery_modifier_delete($pdo, (int)($_POST['id'] ?? 0));
            $ok = 'Модификатор удалён.';
        } elseif ($action === 'toggle_mod') {
            delivery_modifier_toggle($pdo, (int)($_POST['id'] ?? 0));
            $ok = 'Статус модификатора изменён.';
        }

        header('Location: admin_delivery_zones.php?ok=' . urlencode($ok));
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
if (isset($_GET['ok']) && $err === '') $ok = (string)$_GET['ok'];

$zones     = delivery_zones_list($pdo, false);
$modifiers = delivery_modifiers_list($pdo, false);

$editZ = (int)($_GET['edit_zone'] ?? 0);
$editZone = $editZ > 0 ? delivery_zone_get($pdo, $editZ) : null;
$newZone  = isset($_GET['new_zone']);

$editM = (int)($_GET['edit_mod'] ?? 0);
$editMod = $editM > 0 ? delivery_modifier_get($pdo, $editM) : null;
$newMod  = isset($_GET['new_mod']);

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Зоны доставки — Админка FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .dz-wrap{max-width:1280px;margin:24px auto;padding:0 16px;display:grid;gap:18px}
    .dz-card{background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:20px;box-shadow:var(--shadow-1)}
    .dz-table{width:100%;border-collapse:collapse;font-size:13.5px}
    .dz-table th,.dz-table td{padding:9px 8px;border-bottom:1px solid #f0f4f1;text-align:left;vertical-align:top}
    .dz-table th{font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;background:#f9fbfa;font-weight:700}
    .dz-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .dz-form-grid .full{grid-column:1/-1}
    .dz-field{display:grid;gap:6px}
    .dz-field label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.3px}
    .dz-tag{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:700}
    .dz-tag--on {background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
    .dz-tag--off{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
    .notice{padding:10px 14px;border-radius:12px;margin:0 0 12px}
    .notice--err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .notice--ok {background:#ecfdf5;border:1px solid #bbf7d0;color:#166534}
    .dz-mini{font-size:12px;color:#6b7280}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<div class="dz-wrap">

  <div class="dz-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h1 style="margin:0;font-size:24px">Доставка: зоны и тарифы</h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn--ghost" href="admin_orders.php">К заказам</a>
        <a class="btn" href="admin_delivery_zones.php?new_zone=1">+ Зона</a>
        <a class="btn btn--ghost" href="admin_delivery_zones.php?new_mod=1">+ Модификатор</a>
      </div>
    </div>
    <?php if ($err): ?><div class="notice notice--err" style="margin-top:12px"><?= $h($err) ?></div><?php endif; ?>
    <?php if ($ok && !$err): ?><div class="notice notice--ok" style="margin-top:12px"><?= $h($ok) ?></div><?php endif; ?>
    <p class="dz-mini" style="margin:10px 0 0">
      Зона определяется по координатам адреса (магазин → точка доставки, расстояние по haversine).
      Если адрес попадает в несколько зон — выбирается с большим <code>priority</code>.
      Модификаторы накладываются на финальную цену (ночь, выходные, праздники, срочно, хрупкое).
    </p>
  </div>

  <?php if ($newZone || $editZone):
    $z = $editZone ?? [];
  ?>
  <div class="dz-card">
    <h2 style="margin:0 0 14px;font-size:18px"><?= $editZone ? 'Зона #' . (int)$editZone['id'] : 'Новая зона' ?></h2>
    <form method="post" class="dz-form-grid">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="save_zone">
      <?php if ($editZone): ?><input type="hidden" name="id" value="<?= (int)$editZone['id'] ?>"><?php endif; ?>

      <div class="dz-field full">
        <label>Название</label>
        <input class="input" name="name" required value="<?= $h($z['name'] ?? '') ?>" placeholder="Например: Центр Екатеринбурга">
      </div>
      <div class="dz-field full">
        <label>Описание</label>
        <input class="input" name="description" value="<?= $h($z['description'] ?? '') ?>" placeholder="Видно только в админке">
      </div>

      <div class="dz-field"><label>Lat центра</label><input class="input" name="center_lat" type="number" step="0.000001" required value="<?= $h($z['center_lat'] ?? '56.8385') ?>"></div>
      <div class="dz-field"><label>Lon центра</label><input class="input" name="center_lon" type="number" step="0.000001" required value="<?= $h($z['center_lon'] ?? '60.6053') ?>"></div>
      <div class="dz-field"><label>Радиус (км)</label><input class="input" name="radius_km" type="number" step="0.1" min="0.1" required value="<?= $h($z['radius_km'] ?? '15') ?>"></div>

      <div class="dz-field"><label>База, ₽</label><input class="input" name="base_fee" type="number" step="0.01" min="0" required value="<?= $h($z['base_fee'] ?? '300') ?>"></div>
      <div class="dz-field"><label>Бесплатных км</label><input class="input" name="free_distance_km" type="number" step="0.1" min="0" value="<?= $h($z['free_distance_km'] ?? '3') ?>"></div>
      <div class="dz-field"><label>₽/км сверх</label><input class="input" name="per_km_fee" type="number" step="0.01" min="0" value="<?= $h($z['per_km_fee'] ?? '40') ?>"></div>

      <div class="dz-field"><label>Мин. ₽</label><input class="input" name="min_fee" type="number" step="0.01" min="0" value="<?= $h($z['min_fee'] ?? '300') ?>"></div>
      <div class="dz-field"><label>Бесплатно от, ₽</label><input class="input" name="free_from" type="number" step="0.01" min="0" value="<?= $h($z['free_from'] ?? '') ?>" placeholder="(пусто — не действует)"></div>
      <div class="dz-field"><label>Приоритет</label><input class="input" name="priority" type="number" value="<?= $h($z['priority'] ?? '0') ?>"></div>

      <div class="dz-field"><label>Скорость, км/ч (ETA)</label><input class="input" name="eta_speed_kmh" type="number" step="0.1" min="1" value="<?= $h($z['eta_speed_kmh'] ?? '25') ?>"></div>
      <div class="dz-field"><label>Сборка, мин (ETA)</label><input class="input" name="eta_prep_min" type="number" min="0" value="<?= $h($z['eta_prep_min'] ?? '20') ?>"></div>
      <div class="dz-field">
        <label>Активна</label>
        <label style="display:flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0;font-size:14px">
          <input type="checkbox" name="active" value="1" <?= !isset($z['active']) || !empty($z['active']) ? 'checked' : '' ?>> Включить
        </label>
      </div>

      <div class="full" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <button type="submit" class="btn">Сохранить зону</button>
        <a class="btn btn--ghost" href="admin_delivery_zones.php">Отмена</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="dz-card">
    <h2 style="margin:0 0 14px;font-size:18px">Зоны доставки (<?= count($zones) ?>)</h2>
    <?php if (!$zones): ?>
      <p class="mute">Пока нет ни одной зоны. Запустите <code>seed_delivery_zones.php</code> или создайте вручную.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="dz-table">
        <thead><tr>
          <th>#</th><th>Название</th><th>Центр</th><th>Радиус</th>
          <th>Тариф</th><th>Бесплатно от</th><th>Прио.</th><th>ETA</th>
          <th>Статус</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($zones as $z): ?>
          <tr>
            <td><?= (int)$z['id'] ?></td>
            <td>
              <div style="font-weight:700"><?= $h($z['name']) ?></div>
              <?php if (!empty($z['description'])): ?><div class="dz-mini"><?= $h($z['description']) ?></div><?php endif; ?>
            </td>
            <td class="dz-mini">
              <?= number_format((float)$z['center_lat'], 4) ?><br>
              <?= number_format((float)$z['center_lon'], 4) ?>
            </td>
            <td><?= number_format((float)$z['radius_km'], 1) ?> км</td>
            <td class="dz-mini">
              база: <?= number_format((float)$z['base_fee'], 0, ',', ' ') ?> ₽<br>
              <?= number_format((float)$z['free_distance_km'], 1) ?> км бесплатно,<br>
              далее <?= number_format((float)$z['per_km_fee'], 0, ',', ' ') ?> ₽/км
            </td>
            <td><?= $z['free_from'] !== null ? number_format((float)$z['free_from'], 0, ',', ' ') . ' ₽' : '<span class="dz-mini">—</span>' ?></td>
            <td><?= (int)$z['priority'] ?></td>
            <td class="dz-mini"><?= number_format((float)$z['eta_speed_kmh'], 0) ?> км/ч<br>+ <?= (int)$z['eta_prep_min'] ?> мин</td>
            <td><span class="dz-tag <?= !empty($z['active']) ? 'dz-tag--on' : 'dz-tag--off' ?>"><?= !empty($z['active']) ? 'активна' : 'выкл.' ?></span></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a class="btn btn--ghost btn--sm" href="admin_delivery_zones.php?edit_zone=<?= (int)$z['id'] ?>">Изм.</a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="toggle_zone">
                  <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit"><?= !empty($z['active']) ? 'Выкл.' : 'Вкл.' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Удалить зону?')">
                  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="delete_zone">
                  <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit" style="color:#b91c1c">×</button>
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

  <?php if ($newMod || $editMod):
    $m = $editMod ?? [];
    $wd = $m['weekdays'] ?? '[]';
    if (is_string($wd)) $wd = json_decode($wd, true) ?: [];
    $wdStr = is_array($wd) ? implode(',', $wd) : '';
  ?>
  <div class="dz-card">
    <h2 style="margin:0 0 14px;font-size:18px"><?= $editMod ? 'Модификатор #' . (int)$editMod['id'] : 'Новый модификатор' ?></h2>
    <form method="post" class="dz-form-grid">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="save_mod">
      <?php if ($editMod): ?><input type="hidden" name="id" value="<?= (int)$editMod['id'] ?>"><?php endif; ?>

      <div class="dz-field full"><label>Название</label><input class="input" name="name" required value="<?= $h($m['name'] ?? '') ?>" placeholder="Например: Ночной тариф"></div>

      <div class="dz-field">
        <label>Тип (kind)</label>
        <select class="input" name="kind">
          <?php foreach (['general'=>'Общий','night'=>'Ночной','weekend'=>'Выходные','holiday'=>'Праздник','urgent'=>'Срочно','fragile'=>'Хрупкое','big_order'=>'Большой заказ'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= (string)($m['kind'] ?? 'general') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="dz-field"><label>Коэффициент</label><input class="input" type="number" step="0.01" name="multiplier" value="<?= $h($m['multiplier'] ?? '1.00') ?>"></div>
      <div class="dz-field"><label>Доплата, ₽</label><input class="input" type="number" step="0.01" name="surcharge_fixed" value="<?= $h($m['surcharge_fixed'] ?? '0') ?>"></div>

      <div class="dz-field"><label>Время с (HH:MM)</label><input class="input" name="time_from" type="time" value="<?= $h(substr((string)($m['time_from'] ?? ''), 0, 5)) ?>"></div>
      <div class="dz-field"><label>Время до (HH:MM)</label><input class="input" name="time_to" type="time" value="<?= $h(substr((string)($m['time_to'] ?? ''), 0, 5)) ?>"></div>
      <div class="dz-field">
        <label>Дни недели (1=пн … 7=вс)</label>
        <input class="input" name="weekdays" value="<?= $h($wdStr) ?>" placeholder="например: 6,7">
      </div>

      <div class="dz-field"><label>Дата с</label><input class="input" type="date" name="date_from" value="<?= $h(substr((string)($m['date_from'] ?? ''), 0, 10)) ?>"></div>
      <div class="dz-field"><label>Дата до</label><input class="input" type="date" name="date_to" value="<?= $h(substr((string)($m['date_to'] ?? ''), 0, 10)) ?>"></div>
      <div class="dz-field"><label>Приоритет</label><input class="input" type="number" name="priority" value="<?= $h($m['priority'] ?? '0') ?>"></div>

      <div class="dz-field full">
        <label style="display:flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0;font-size:14px">
          <input type="checkbox" name="active" value="1" <?= !isset($m['active']) || !empty($m['active']) ? 'checked' : '' ?>>
          Модификатор активен
        </label>
      </div>

      <div class="full" style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="btn">Сохранить</button>
        <a class="btn btn--ghost" href="admin_delivery_zones.php">Отмена</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="dz-card">
    <h2 style="margin:0 0 14px;font-size:18px">Модификаторы тарифа (<?= count($modifiers) ?>)</h2>
    <?php if (!$modifiers): ?>
      <p class="mute">Модификаторов нет.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="dz-table">
        <thead><tr>
          <th>#</th><th>Название</th><th>Тип</th><th>Эффект</th><th>Условие</th><th>Прио.</th><th>Статус</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($modifiers as $m):
          $wd = $m['weekdays'] ?? null;
          if (is_string($wd)) $wd = json_decode($wd, true);
          $wdStr = (is_array($wd) && $wd) ? implode(', ', $wd) : '';
        ?>
          <tr>
            <td><?= (int)$m['id'] ?></td>
            <td><div style="font-weight:700"><?= $h($m['name']) ?></div></td>
            <td><?= $h($m['kind']) ?></td>
            <td class="dz-mini">
              <?php if (abs((float)$m['multiplier'] - 1.0) > 0.001): ?>×<?= number_format((float)$m['multiplier'], 2) ?><?php endif; ?>
              <?php if ((float)$m['surcharge_fixed'] != 0.0): ?>
                <?= ((float)$m['surcharge_fixed'] > 0 ? '+' : '') . number_format((float)$m['surcharge_fixed'], 0, ',', ' ') ?> ₽
              <?php endif; ?>
            </td>
            <td class="dz-mini">
              <?php if (!empty($m['time_from']) || !empty($m['time_to'])): ?>
                время: <?= $h(substr((string)$m['time_from'], 0, 5) ?: '00:00') ?>–<?= $h(substr((string)$m['time_to'], 0, 5) ?: '23:59') ?><br>
              <?php endif; ?>
              <?php if ($wdStr !== ''): ?>дни: <?= $h($wdStr) ?><br><?php endif; ?>
              <?php if (!empty($m['date_from']) || !empty($m['date_to'])): ?>
                период: <?= $h(substr((string)$m['date_from'], 0, 10) ?: '—') ?>…<?= $h(substr((string)$m['date_to'], 0, 10) ?: '—') ?>
              <?php endif; ?>
            </td>
            <td><?= (int)$m['priority'] ?></td>
            <td><span class="dz-tag <?= !empty($m['active']) ? 'dz-tag--on' : 'dz-tag--off' ?>"><?= !empty($m['active']) ? 'активен' : 'выкл.' ?></span></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a class="btn btn--ghost btn--sm" href="admin_delivery_zones.php?edit_mod=<?= (int)$m['id'] ?>">Изм.</a>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="toggle_mod">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit"><?= !empty($m['active']) ? 'Выкл.' : 'Вкл.' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Удалить?')">
                  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="delete_mod">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit" style="color:#b91c1c">×</button>
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
