<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/category_lib.php';
require_once __DIR__ . '/product_admin_lib.php';
$pdo = db();
categories_bootstrap($pdo);
pa_ensure_products_discount_columns($pdo);
$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? 'user'), ['admin','manager'], true)) {
  header('Location: auth.php?mode=login'); exit;
}
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: products.php'); exit; }

$st = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
$st->execute([$id]);
$product = $st->fetch(PDO::FETCH_ASSOC);
if (!$product) { http_response_code(404); echo 'Товар не найден'; exit; }

$cats = categories_fetch_all($pdo, false);
$err = $ok = null;
$form = [
  'name' => (string)$product['name'],
  'price' => (string)$product['price'],
  'description' => (string)($product['description'] ?? ''),
  'old_price' => ($product['old_price'] ?? null) !== null ? (string)$product['old_price'] : '',
  'category' => trim((string)($product['category'] ?? '')) ?: 'other',
  'image_url' => (string)($product['image'] ?? ''),
  'is_active' => (int)($product['is_active'] ?? 1) === 1 ? 1 : 0,
  'remove_image' => 0,
  'new_category_name' => '',
  'new_category_slug' => '',
  'stock' => ($product['stock'] ?? null) !== null ? (string)$product['stock'] : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form = array_merge($form, [
    'name' => trim((string)($_POST['name'] ?? '')),
    'price' => (string)($_POST['price'] ?? ''),
    'description' => trim((string)($_POST['description'] ?? '')),
    'old_price' => (string)($_POST['old_price'] ?? ''),
    'category' => trim((string)($_POST['category'] ?? '')),
    'image_url' => trim((string)($_POST['image_url'] ?? '')),
    'is_active' => isset($_POST['is_active']) ? 1 : 0,
    'remove_image' => isset($_POST['remove_image']) ? 1 : 0,
    'new_category_name' => trim((string)($_POST['new_category_name'] ?? '')),
    'new_category_slug' => trim((string)($_POST['new_category_slug'] ?? '')),
    'stock' => trim((string)($_POST['stock'] ?? '')),
  ]);

  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) throw new RuntimeException('Сессия устарела.');
    $name = $form['name'];
    $price = pa_normalize_price($form['price']);
    $oldPrice = pa_valid_old_price($form['old_price'], $price);
    if ($name === '' || $price < 0) throw new RuntimeException('Заполните название и корректную цену.');

    $category = $form['category'] !== '' ? $form['category'] : 'other';
    if ($form['new_category_name'] !== '') {
      $newSlug = $form['new_category_slug'] !== '' ? category_slugify($form['new_category_slug']) : category_slugify($form['new_category_name']);
      $pdo->prepare('INSERT INTO categories (slug, name, sort_order, is_active) VALUES (?, ?, 100, TRUE) ON CONFLICT (slug) DO NOTHING')->execute([$newSlug, $form['new_category_name']]);
      $category = $newSlug;
      $cats = categories_fetch_all($pdo, false);
    }

    $image = (string)($product['image'] ?? '');
    if ($form['remove_image']) {
      category_delete_local_image($image, __DIR__);
      $image = '';
    }
    if (!empty($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $newImage = category_save_uploaded_image($_FILES['image_file'], __DIR__ . '/img', 'img');
      category_delete_local_image($image, __DIR__);
      $image = $newImage;
    } elseif ($form['image_url'] !== '') {
      $image = $form['image_url'];
    } elseif ($form['remove_image']) {
      $image = '';
    }

    $stock = $form['stock'] !== '' && is_numeric($form['stock']) ? max(0, (int)$form['stock']) : null;
    $stUp = $pdo->prepare('UPDATE products SET name = ?, price = ?, old_price = ?, image = ?, description = ?, category = ?, is_active = ?, stock = ? WHERE id = ?');
    $stUp->execute([$name, $price, $oldPrice, $image, $form['description'], $category, $form['is_active'], $stock, $id]);

    $product = array_merge($product, ['name'=>$name,'price'=>$price,'old_price'=>$oldPrice,'image'=>$image,'description'=>$form['description'],'category'=>$category,'is_active'=>$form['is_active']]);
    $form['image_url'] = $image;
    $form['category'] = $category;
    $ok = 'Изменения сохранены.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$previewImage = trim((string)($form['image_url'] ?? '')) ?: ('data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="16" fill="#9ca3af">Нет фото</text></svg>'));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Изменить товар #<?= $id ?> — FloraExpress</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    .pe-wrap { max-width: 980px; margin: 0 auto; display: grid; gap: 18px; }

    .pe-topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .pe-topbar__left { display: flex; align-items: center; gap: 10px; }
    .pe-back { display: inline-flex; align-items: center; gap: 6px; color: #6b7280; font-size: 14px; text-decoration: none; }
    .pe-back:hover { color: #0f172a; }
    .pe-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; }
    .pe-id { font-size: 14px; font-weight: 600; color: #94a3b8; }

    .pe-notice { padding: 13px 16px; border-radius: 14px; font-size: 14px; font-weight: 600; line-height: 1.5; }
    .pe-notice--ok  { background: #ecfdf3; border: 1px solid #c9f1d8; color: #166534; }
    .pe-notice--err { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }

    .pe-layout { display: grid; grid-template-columns: minmax(0, 340px) minmax(0, 1fr); gap: 18px; align-items: start; }
    @media (max-width: 860px) { .pe-layout { grid-template-columns: 1fr; } }

    /* Left sidebar */
    .pe-sidebar { display: grid; gap: 14px; min-width: 0; }
    .pe-main { min-width: 0; }
    .pe-box { background: #fff; border: 1px solid #eef2f7; border-radius: 18px; box-shadow: var(--shadow-1); padding: 18px; min-width: 0; overflow: hidden; }

    .pe-preview { width: 100%; aspect-ratio: 1/1; object-fit: cover; border-radius: 14px; border: 1px solid #eef2f7; background: #f4f6f8; display: block; }
    .pe-preview-actions { display: grid; gap: 8px; margin-top: 12px; min-width: 0; }
    .pe-preview-actions .input { width: 100%; box-sizing: border-box; min-width: 0; }

    .pe-status { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .pe-status__label { font-size: 14px; font-weight: 700; color: #0f172a; }
    .pe-status__sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
    .pe-toggle { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
    .pe-toggle input { opacity: 0; width: 0; height: 0; }
    .pe-toggle__track { position: absolute; inset: 0; border-radius: 999px; background: #d1d5db; transition: .2s; cursor: pointer; }
    .pe-toggle input:checked + .pe-toggle__track { background: #0f6b45; }
    .pe-toggle__track::after { content: ''; position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: .2s; }
    .pe-toggle input:checked + .pe-toggle__track::after { transform: translateX(20px); }

    /* Right main */
    .pe-main { display: grid; gap: 14px; }
    .pe-section-title { font-size: 13px; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: .06em; margin: 0 0 14px; }
    .pe-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .pe-grid > * { min-width: 0; }
    @media (max-width: 600px) { .pe-grid { grid-template-columns: 1fr; } }

    .pe-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .pe-field label { font-size: 13px; font-weight: 700; color: #374151; }
    .pe-field .hint { font-size: 12px; color: #94a3b8; margin-top: 2px; }
    .pe-field .input, .pe-field select { width: 100%; min-width: 0; box-sizing: border-box; }

    .pe-new-cat { background: #f8faf9; border: 1px solid #e3ebe6; border-radius: 16px; padding: 16px; }
    .pe-new-cat__title { font-size: 14px; font-weight: 800; color: #0f172a; margin: 0 0 12px; display: flex; align-items: center; gap: 7px; }
    .pe-new-cat__icon { width: 20px; height: 20px; background: #e8f5ee; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; color: #0f6b45; font-size: 13px; }

    .pe-check { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #374151; font-weight: 600; cursor: pointer; }
    .pe-check input { width: 17px; height: 17px; accent-color: #0f6b45; cursor: pointer; }

    .pe-footer { display: flex; gap: 10px; flex-wrap: wrap; }
    textarea.input { border-radius: 12px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section">
  <div class="container">
    <div class="pe-wrap">

      <!-- Topbar -->
      <div class="pe-topbar">
        <div class="pe-topbar__left">
          <a class="pe-back" href="products.php">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
            К списку товаров
          </a>
          <span style="color:#e2e8f0">|</span>
          <h1 class="pe-title">Редактирование <span class="pe-id">#<?= $id ?></span></h1>
        </div>
        <a class="btn btn--ghost btn--sm" href="product.php?id=<?= $id ?>" target="_blank">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:5px"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Открыть карточку
        </a>
      </div>

      <?php if ($ok): ?><div class="pe-notice pe-notice--ok"><?= h($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="pe-notice pe-notice--err"><?= h($err) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="pe-layout">

          <!-- Sidebar: preview + visibility -->
          <div class="pe-sidebar">
            <div class="pe-box">
              <img id="preview" class="pe-preview" alt="Превью" src="<?= h($previewImage) ?>">
              <div class="pe-preview-actions">
                <label class="pe-field">
                  <span style="font-size:13px;font-weight:700;color:#374151">Загрузить файл</span>
                  <input class="input-file" type="file" name="image_file" id="image_file" accept="image/*">
                </label>
                <label class="pe-field">
                  <span style="font-size:13px;font-weight:700;color:#374151">Или ссылка</span>
                  <input class="input" name="image_url" id="image_url" value="<?= h($form['image_url']) ?>" placeholder="https://...">
                </label>
                <label class="pe-check">
                  <input type="checkbox" name="remove_image" <?= $form['remove_image'] ? 'checked' : '' ?>>
                  Удалить изображение
                </label>
              </div>
            </div>

            <div class="pe-box">
              <div class="pe-status">
                <div>
                  <div class="pe-status__label">Показывать в каталоге</div>
                  <div class="pe-status__sub">Товар виден покупателям</div>
                </div>
                <label class="pe-toggle">
                  <input type="checkbox" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>>
                  <span class="pe-toggle__track"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- Main fields -->
          <div class="pe-main">

            <!-- Basic info -->
            <div class="pe-box">
              <div class="pe-section-title">Основная информация</div>
              <div class="pe-grid">
                <div class="pe-field" style="grid-column:1/-1">
                  <label for="f-name">Название товара</label>
                  <input class="input" id="f-name" name="name" value="<?= h($form['name']) ?>" required placeholder="Например: Букет из 25 роз">
                </div>
                <div class="pe-field">
                  <label for="f-price">Цена, ₽</label>
                  <input class="input" id="f-price" name="price" value="<?= h((string)$form['price']) ?>" required placeholder="3500">
                </div>
                <div class="pe-field">
                  <label for="f-old-price">Старая цена, ₽ <span style="font-weight:500;color:#94a3b8">(скидка)</span></label>
                  <input class="input" id="f-old-price" name="old_price" value="<?= h((string)$form['old_price']) ?>" placeholder="Например: 4500">
                </div>
                <div class="pe-field">
                  <label for="f-cat">Категория</label>
                  <select class="input" id="f-cat" name="category">
                    <?php foreach ($cats as $cat): ?>
                      <option value="<?= h($cat['slug']) ?>" <?= $form['category'] === $cat['slug'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="pe-field">
                  <label for="f-stock">Остаток на складе</label>
                  <input class="input" id="f-stock" type="number" min="0" name="stock" value="<?= h($form['stock']) ?>" placeholder="Пусто — без ограничений">
                </div>
                <div class="pe-field" style="grid-column:1/-1">
                  <label for="f-desc">Описание</label>
                  <textarea class="input" id="f-desc" name="description" rows="5" style="resize:vertical"><?= h($form['description']) ?></textarea>
                </div>
              </div>
            </div>

            <!-- New category -->
            <div class="pe-box">
              <div class="pe-new-cat">
                <div class="pe-new-cat__title">
                  <span class="pe-new-cat__icon">+</span>
                  Создать новую категорию
                </div>
                <div class="pe-grid">
                  <div class="pe-field">
                    <label for="f-newcat-name">Название</label>
                    <input class="input" id="f-newcat-name" name="new_category_name" value="<?= h($form['new_category_name']) ?>" placeholder="Например: Композиции">
                  </div>
                  <div class="pe-field">
                    <label for="f-newcat-slug">Slug <span style="font-weight:500;color:#94a3b8">(можно оставить пустым)</span></label>
                    <input class="input" id="f-newcat-slug" name="new_category_slug" value="<?= h($form['new_category_slug']) ?>" placeholder="kompozitsii">
                  </div>
                </div>
              </div>
            </div>

            <!-- Footer actions -->
            <div class="pe-footer">
              <button class="btn" type="submit">Сохранить изменения</button>
              <a class="btn btn--ghost" href="products.php">Отмена</a>
            </div>

          </div>
        </div>
      </form>

    </div>
  </div>
</section>

<script>
(function () {
  const fileInput = document.getElementById('image_file');
  const urlInput  = document.getElementById('image_url');
  const preview   = document.getElementById('preview');

  fileInput?.addEventListener('change', () => {
    const f = fileInput.files?.[0];
    if (f) preview.src = URL.createObjectURL(f);
  });
  urlInput?.addEventListener('input', () => {
    if (urlInput.value.trim() && !fileInput.files?.length) {
      preview.src = urlInput.value.trim();
    }
  });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
