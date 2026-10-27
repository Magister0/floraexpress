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

$cats = categories_fetch_all($pdo, false);
$err = null;
$form = ['name'=>'','price'=>'','old_price'=>'','description'=>'','category'=>'bouquets','image_url'=>'','is_active'=>1,'new_category_name'=>'','new_category_slug'=>'','stock'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form = array_merge($form, [
    'name' => trim((string)($_POST['name'] ?? '')),
    'price' => (string)($_POST['price'] ?? ''),
    'description' => trim((string)($_POST['description'] ?? '')),
    'old_price' => (string)($_POST['old_price'] ?? ''),
    'category' => trim((string)($_POST['category'] ?? '')),
    'image_url' => trim((string)($_POST['image_url'] ?? '')),
    'is_active' => isset($_POST['is_active']) ? 1 : 0,
    'new_category_name' => trim((string)($_POST['new_category_name'] ?? '')),
    'new_category_slug' => trim((string)($_POST['new_category_slug'] ?? '')),
    'stock' => trim((string)($_POST['stock'] ?? '')),
  ]);
  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) throw new RuntimeException('Сессия устарела.');
    $name = $form['name'];
    $price = pa_normalize_price($form['price']);
    $oldPrice = pa_valid_old_price($form['old_price'], $price);
    $desc = $form['description'];
    $cat = $form['category'] !== '' ? $form['category'] : 'other';
    if ($name === '' || $price < 0) throw new RuntimeException('Заполните название и корректную цену.');

    if ($form['new_category_name'] !== '') {
      $newSlug = $form['new_category_slug'] !== '' ? category_slugify($form['new_category_slug']) : category_slugify($form['new_category_name']);
      $stCat = $pdo->prepare('INSERT INTO categories (slug, name, sort_order, is_active) VALUES (?, ?, 100, TRUE) ON CONFLICT (slug) DO NOTHING');
      $stCat->execute([$newSlug, $form['new_category_name']]);
      $cat = $newSlug;
    }

    $image = '';
    if (!empty($_FILES['image_file']) && ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $image = category_save_uploaded_image($_FILES['image_file'], __DIR__ . '/img', 'img');
    } else {
      $url = $form['image_url'];
      if ($url !== '') $image = $url;
    }

    $stock = $form['stock'] !== '' && is_numeric($form['stock']) ? max(0, (int)$form['stock']) : null;
    $st = $pdo->prepare('INSERT INTO products (name, price, old_price, image, description, category, is_active, stock, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
    $st->execute([$name, $price, $oldPrice, $image, $desc, $cat, $form['is_active'], $stock]);
    $newId = (int)$pdo->lastInsertId();

    $saveMode = (string)($_POST['save_mode'] ?? 'back');
    if ($saveMode === 'open') { header('Location: product.php?id=' . $newId); exit; }
    if ($saveMode === 'edit') { header('Location: product_edit.php?id=' . $newId); exit; }
    header('Location: products.php'); exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
    $cats = categories_fetch_all($pdo, false);
  }
}
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Добавить товар — FloraExpress</title><link rel="stylesheet" href="styles.css">
<style>.form-card{background:#fff;border:1px solid #e7efe9;border-radius:20px;box-shadow:var(--shadow-1);padding:20px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.msg{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:700}.msg.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.preview-wrap{display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start}.preview{width:220px;height:220px;border-radius:18px;object-fit:cover;border:1px solid #e7efe9;background:#f4f6f8}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}@media (max-width:900px){.grid,.preview-wrap{grid-template-columns:1fr}.preview{width:100%;max-width:260px;height:auto;aspect-ratio:1/1}}</style>
</head><body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section"><div class="container"><div class="section__head" style="text-align:left;margin-bottom:16px"><h1 class="section__title" style="margin:0">Новый товар</h1><p class="section__sub">Изображение можно загрузить файлом, а категорию выбрать или создать сразу здесь.</p></div><?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
<div class="form-card"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<div class="preview-wrap">
  <img id="preview" class="preview" alt="preview" src="<?= h($form['image_url'] ?: 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="16" fill="#9ca3af">Превью</text></svg>')) ?>">
  <div class="grid">
    <label><div class="mute" style="margin-bottom:6px">Название</div><input class="input" name="name" value="<?= h($form['name']) ?>" required></label>
    <label><div class="mute" style="margin-bottom:6px">Цена</div><input class="input" name="price" value="<?= h($form['price']) ?>" placeholder="4500" required></label>
    <label><div class="mute" style="margin-bottom:6px">Старая цена для скидки</div><input class="input" name="old_price" value="<?= h($form['old_price']) ?>" placeholder="Например: 6000"></label>
    <label><div class="mute" style="margin-bottom:6px">Остаток на складе</div><input class="input" type="number" min="0" name="stock" value="<?= h($form['stock']) ?>" placeholder="Пусто = без ограничений"></label>
    <label><div class="mute" style="margin-bottom:6px">Категория</div><select class="input" name="category"><?php foreach ($cats as $cat): ?><option value="<?= h($cat['slug']) ?>" <?= $form['category'] === $cat['slug'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option><?php endforeach; ?></select></label>
    <label><div class="mute" style="margin-bottom:6px">Изображение файлом</div><input class="input-file" type="file" name="image_file" id="image_file" accept="image/*"></label>
    <label style="grid-column:1/-1"><div class="mute" style="margin-bottom:6px">Или ссылка на изображение</div><input class="input" name="image_url" id="image_url" value="<?= h($form['image_url']) ?>" placeholder="https://... или img/example.jpg"></label>
    <label style="grid-column:1/-1"><div class="mute" style="margin-bottom:6px">Описание</div><textarea class="input" name="description" rows="5" placeholder="Короткое описание товара"><?= h($form['description']) ?></textarea></label>
    <div style="grid-column:1/-1;background:#f8faf9;border:1px solid #e3ebe6;border-radius:16px;padding:14px"><div style="font-weight:800;margin-bottom:10px">Быстро создать новую категорию</div><div class="grid"><label><div class="mute" style="margin-bottom:6px">Название новой категории</div><input class="input" name="new_category_name" value="<?= h($form['new_category_name']) ?>" placeholder="Например: Композиции"></label><label><div class="mute" style="margin-bottom:6px">Slug новой категории</div><input class="input" name="new_category_slug" value="<?= h($form['new_category_slug']) ?>" placeholder="Например: kompozitsii"></label></div><div class="mini-note" style="margin-top:8px;color:#6b7280">Если заполнить этот блок, товар сохранится именно в новую категорию.</div></div>
    <label class="row" style="grid-column:1/-1;justify-content:flex-start"><input type="checkbox" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>><span>Сразу показывать товар в каталоге</span></label>
  </div>
</div>
<div class="toolbar"><button class="btn" type="submit" name="save_mode" value="back">Сохранить</button><button class="btn btn--ghost" type="submit" name="save_mode" value="edit">Сохранить и редактировать</button><button class="btn btn--ghost" type="submit" name="save_mode" value="open">Сохранить и открыть карточку</button><a class="btn btn--ghost" href="products.php">Отмена</a></div>
</form></div></div></section>
<script>
const fileInput=document.getElementById('image_file');const urlInput=document.getElementById('image_url');const preview=document.getElementById('preview');
fileInput?.addEventListener('change',()=>{const f=fileInput.files?.[0];if(f){preview.src=URL.createObjectURL(f);}});urlInput?.addEventListener('input',()=>{if(urlInput.value.trim() && !fileInput.files?.length){preview.src=urlInput.value.trim();}});
</script>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
