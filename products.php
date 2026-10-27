<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/category_lib.php';
require_once __DIR__ . '/product_admin_lib.php';
require_once __DIR__ . '/pricing_lib.php';
$pdo = db();
categories_bootstrap($pdo);
pa_ensure_products_discount_columns($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? 'user'), ['admin','manager'], true)) {
  header('Location: auth.php?mode=login&return=' . urlencode('products.php')); exit;
}
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];
$catMap = categories_fetch_map($pdo, false);

$err = $ok = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
      throw new RuntimeException('Сессия устарела. Обновите страницу.');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Некорректный ID товара.');

    if ($action === 'toggle') {
      $st = $pdo->prepare("UPDATE products SET is_active = CASE WHEN COALESCE(is_active,1)=1 THEN 0 ELSE 1 END WHERE id=?");
      $st->execute([$id]);
      $ok = 'Статус товара обновлён.';
    } elseif ($action === 'delete') {
      $st = $pdo->prepare('SELECT image FROM products WHERE id = ? LIMIT 1');
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $del = $pdo->prepare('DELETE FROM products WHERE id = ?');
      $del->execute([$id]);
      if ($row) category_delete_local_image($row['image'] ?? '', __DIR__);
      $ok = 'Товар удалён.';
    } elseif ($action === 'duplicate') {
      $st = $pdo->prepare('SELECT name, price, old_price, image, description, category, COALESCE(is_active,1) AS is_active FROM products WHERE id = ? LIMIT 1');
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Товар не найден.');
      $ins = $pdo->prepare('INSERT INTO products (name, price, old_price, image, description, category, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
      $ins->execute([$row['name'] . ' (копия)', $row['price'], $row['old_price'] ?? null, $row['image'], $row['description'], $row['category'], $row['is_active']]);
      $ok = 'Копия товара создана.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$filterCat = trim((string)($_GET['category'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? 'all'));

$where = [];
$args = [];
if ($q !== '') {
  $where[] = '(name ILIKE ? OR COALESCE(description, \'\') ILIKE ? OR COALESCE(category, \'\') ILIKE ?)';
  $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%";
}
if ($filterCat !== '' && isset($catMap[$filterCat])) {
  $where[] = 'COALESCE(category, \'other\') = ?';
  $args[] = $filterCat;
}
if ($filterStatus === 'active') $where[] = 'COALESCE(is_active,1) = 1';
if ($filterStatus === 'hidden') $where[] = 'COALESCE(is_active,1) = 0';

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$products = [];
$stats = ['total' => 0, 'active' => 0, 'hidden' => 0];
try {
  $products = $pdo->prepare("SELECT id,name,price,old_price,image,category,is_active,created_at FROM products $sqlWhere ORDER BY id DESC");
  $products->execute($args);
  $products = $products->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
  $stats['active'] = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE COALESCE(is_active,1)=1')->fetchColumn();
  $stats['hidden'] = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE COALESCE(is_active,1)=0')->fetchColumn();
} catch (Throwable $e) {
  $err = $err ?: $e->getMessage();
  $products = [];
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Товары — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .grid-top{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}.stat{background:#fff;border:1px solid #e7efe9;border-radius:18px;box-shadow:var(--shadow-1);padding:18px}.stat__label{color:#6b7280;font-size:13px;margin-bottom:6px}.stat__value{font-size:30px;font-weight:800;color:#111827}
    .box{background:#fff;border:1px solid #e7efe9;border-radius:20px;box-shadow:var(--shadow-1);padding:18px}.filters{display:grid;grid-template-columns:1.2fr .9fr .8fr auto auto;gap:10px;align-items:end;margin-bottom:16px}.table-wrap{overflow:auto}.img{width:72px;height:72px;border-radius:14px;object-fit:cover;border:1px solid #eef2f7;background:#f3f4f6}.actions{display:flex;gap:8px;flex-wrap:wrap}.status-pill{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid #d7eadf;background:#f4fbf7;color:#185a37}.status-pill.off{background:#fff1f2;border-color:#fecdd3;color:#9f1239}.msg{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:700}.msg.ok{background:#ecfdf3;border:1px solid #c9f1d8;color:#166534}.msg.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    @media (max-width:980px){.grid-top{grid-template-columns:1fr}.filters{grid-template-columns:1fr 1fr}.filters .btn{width:100%}}
    @media (max-width:640px){.filters{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <div class="section__head" style="text-align:left;margin-bottom:16px">
      <h1 class="section__title" style="margin:0">Товары</h1>
      <p class="section__sub">Управление ассортиментом, фильтрами и карточками товаров.</p>
    </div>

    <div class="grid-top">
      <div class="stat"><div class="stat__label">Всего товаров</div><div class="stat__value"><?= $stats['total'] ?></div></div>
      <div class="stat"><div class="stat__label">Активных в каталоге</div><div class="stat__value"><?= $stats['active'] ?></div></div>
      <div class="stat"><div class="stat__label">Скрытых</div><div class="stat__value"><?= $stats['hidden'] ?></div></div>
    </div>

    <?php if ($ok): ?><div class="msg ok"><?= h($ok) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

    <div class="box">
      <form method="get" class="filters">
        <label>
          <div class="mute" style="margin-bottom:6px">Поиск</div>
          <input class="input" name="q" value="<?= h($q) ?>" placeholder="Название, описание, slug категории">
        </label>
        <label>
          <div class="mute" style="margin-bottom:6px">Категория</div>
          <select class="input" name="category">
            <option value="">Все категории</option>
            <?php foreach ($catMap as $slug => $cat): ?>
              <option value="<?= h($slug) ?>" <?= $filterCat === $slug ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <div class="mute" style="margin-bottom:6px">Статус</div>
          <select class="input" name="status">
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Все</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Только активные</option>
            <option value="hidden" <?= $filterStatus === 'hidden' ? 'selected' : '' ?>>Только скрытые</option>
          </select>
        </label>
        <button class="btn" type="submit">Применить</button>
        <a class="btn btn--ghost" href="product_new.php">Добавить товар</a>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Фото</th>
              <th>Название</th>
              <th>Категория</th>
              <th>Цена</th>
              <th>Статус</th>
              <th>Дата</th>
              <th style="min-width:320px">Действия</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$products): ?>
            <tr><td colspan="8" class="mute">Товаров по выбранным фильтрам нет.</td></tr>
          <?php else: ?>
            <?php foreach ($products as $p): $slug = trim((string)($p['category'] ?? '')) ?: 'other'; ?>
              <tr>
                <td><strong>#<?= (int)$p['id'] ?></strong></td>
                <td><?php if (!empty($p['image'])): ?><img class="img" src="<?= h($p['image']) ?>" alt=""><?php else: ?><div class="img"></div><?php endif; ?></td>
                <td><strong><?= h($p['name']) ?></strong></td>
                <td><?= h($catMap[$slug]['name'] ?? category_humanize_slug($slug)) ?></td>
                <?php $priceView = pricing_view($p, $pdo); ?><td style="white-space:nowrap;color:#1f6b42;font-weight:700">
                  <?= number_format((float)$priceView['price'], 0, ',', ' ') ?> ₽
                  <?php if ($priceView['has_discount'] && $priceView['old_price']): ?><div style="font-size:12px;color:#0f6b45;opacity:.75;text-decoration:line-through;text-decoration-color:#22c55e"><?= number_format((float)$priceView['old_price'], 0, ',', ' ') ?> ₽</div><?php endif; ?>
                  <?php if ($priceView['badge_html']): ?><div style="margin-top:4px"><?= $priceView['badge_html'] ?></div><?php endif; ?>
                </td>
                <td><span class="status-pill<?= ((int)($p['is_active'] ?? 0) === 1) ? '' : ' off' ?>"><?= ((int)($p['is_active'] ?? 0) === 1) ? 'Активен' : 'Скрыт' ?></span></td>
                <td class="mute"><?= h(substr((string)($p['created_at'] ?? ''), 0, 16)) ?></td>
                <td>
                  <div class="actions">
                    <a class="btn btn--ghost btn--sm" href="product.php?id=<?= (int)$p['id'] ?>" target="_blank">Открыть</a>
                    <a class="btn btn--ghost btn--sm" href="product_edit.php?id=<?= (int)$p['id'] ?>">Изменить</a>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button class="btn btn--ghost btn--sm" type="submit">Дублировать</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button class="btn btn--ghost btn--sm" type="submit"><?= ((int)($p['is_active'] ?? 0) === 1) ? 'Скрыть' : 'Показать' ?></button>
                    </form>
                    <form method="post" onsubmit="return confirm('Удалить товар?')">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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
    </div>
  </div>
</section>
<?php include __DIR__.'/footer.php'; ?>
</body>
</html>
