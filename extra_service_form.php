<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/product_admin_lib.php';
require_once __DIR__ . '/extra_services_lib.php';

$pdo = db();
extra_services_bootstrap($pdo);
pa_require_staff('extra_service_form.php');
$csrf = pa_csrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $id > 0;
$current = $editing ? extra_services_fetch_one($pdo, $id) : null;
if ($editing && !$current) {
    http_response_code(404);
    echo 'Услуга не найдена';
    exit;
}

$form = [
    'name' => (string)($current['name'] ?? ''),
    'price' => (string)($current['price'] ?? ''),
    'description' => (string)($current['description'] ?? ''),
    'image_url' => (string)($current['image'] ?? ''),
    'allow_message' => !empty($current['allow_message']) ? 1 : 0,
    'is_active' => array_key_exists('is_active', (array)$current) ? (!empty($current['is_active']) ? 1 : 0) : 1,
    'sort_order' => (string)($current['sort_order'] ?? 100),
    'remove_image' => 0,
];
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, [
        'name' => trim((string)($_POST['name'] ?? '')),
        'price' => (string)($_POST['price'] ?? ''),
        'description' => trim((string)($_POST['description'] ?? '')),
        'image_url' => trim((string)($_POST['image_url'] ?? '')),
        'allow_message' => isset($_POST['allow_message']) ? 1 : 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'sort_order' => (string)($_POST['sort_order'] ?? '100'),
        'remove_image' => isset($_POST['remove_image']) ? 1 : 0,
    ]);
    try {
        pa_check_csrf();
        $name = $form['name'];
        $price = pa_normalize_price($form['price']);
        $description = $form['description'];
        $sortOrder = (int)$form['sort_order'];
        if ($name === '' || $price < 0) {
            throw new RuntimeException('Заполните название и корректную цену.');
        }
        $image = pa_pick_image_from_request((string)($current['image'] ?? ''));

        if ($editing) {
            $st = $pdo->prepare('UPDATE extra_services SET name = ?, price = ?, description = ?, image = ?, allow_message = ?, is_active = ?, sort_order = ? WHERE id = ?');
            $st->execute([$name, $price, $description, $image, $form['allow_message'], $form['is_active'], $sortOrder, $id]);
        } else {
            $st = $pdo->prepare('INSERT INTO extra_services (name, price, description, image, allow_message, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            $st->execute([$name, $price, $description, $image, $form['allow_message'], $form['is_active'], $sortOrder]);
        }
        header('Location: extra_services.php');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $editing ? 'Редактировать услугу' : 'Новая услуга' ?> — FloraExpress</title><link rel="stylesheet" href="styles.css">
<style>.form-card{background:#fff;border:1px solid #e7efe9;border-radius:20px;box-shadow:var(--shadow-1);padding:20px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.msg{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:700}.msg.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.preview-wrap{display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start}.preview{width:220px;height:220px;border-radius:18px;object-fit:cover;border:1px solid #e7efe9;background:#f4f6f8}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}@media (max-width:900px){.grid,.preview-wrap{grid-template-columns:1fr}.preview{width:100%;max-width:260px;height:auto;aspect-ratio:1/1}}</style>
</head><body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section"><div class="container"><div class="section__head" style="text-align:left;margin-bottom:16px"><h1 class="section__title" style="margin:0"><?= $editing ? 'Редактировать услугу' : 'Новая услуга' ?></h1><p class="section__sub">Карточка допуслуги для оформления заказа и админки.</p></div><?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
<div class="form-card"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
<div class="preview-wrap">
  <img id="preview" class="preview" alt="preview" src="<?= h(extra_services_image_src($form['image_url'], $form['name'] ?: 'Услуга')) ?>">
  <div class="grid">
    <label><div class="mute" style="margin-bottom:6px">Название</div><input class="input" name="name" value="<?= h($form['name']) ?>" required></label>
    <label><div class="mute" style="margin-bottom:6px">Цена</div><input class="input" name="price" value="<?= h($form['price']) ?>" placeholder="250" required></label>
    <label><div class="mute" style="margin-bottom:6px">Порядок вывода</div><input class="input" name="sort_order" value="<?= h($form['sort_order']) ?>" placeholder="100"></label>
    <label class="row" style="margin-top:26px;justify-content:flex-start"><input type="checkbox" name="allow_message" <?= $form['allow_message'] ? 'checked' : '' ?>><span>Можно написать текст</span></label>
    <label><div class="mute" style="margin-bottom:6px">Изображение файлом</div><input class="input-file" type="file" name="image_file" id="image_file" accept="image/*"></label>
    <label><div class="mute" style="margin-bottom:6px">Ссылка на изображение</div><input class="input" name="image_url" id="image_url" value="<?= h($form['image_url']) ?>" placeholder="https://... или img/example.jpg"></label>
    <label style="grid-column:1/-1"><div class="mute" style="margin-bottom:6px">Описание</div><textarea class="input" name="description" rows="5" placeholder="Короткое описание услуги"><?= h($form['description']) ?></textarea></label>
    <?php if ($editing && !empty($current['image'])): ?><label class="row" style="grid-column:1/-1;justify-content:flex-start"><input type="checkbox" name="remove_image"><span>Удалить текущее изображение</span></label><?php endif; ?>
    <label class="row" style="grid-column:1/-1;justify-content:flex-start"><input type="checkbox" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>><span>Показывать в дополнительных услугах</span></label>
  </div>
</div>
<div class="toolbar"><button class="btn" type="submit">Сохранить</button><a class="btn btn--ghost" href="extra_services.php">Назад</a></div>
</form></div></div></section>
<script>
const fileInput=document.getElementById('image_file');const urlInput=document.getElementById('image_url');const preview=document.getElementById('preview');
fileInput?.addEventListener('change',()=>{const f=fileInput.files?.[0];if(f){preview.src=URL.createObjectURL(f);}});urlInput?.addEventListener('input',()=>{if(urlInput.value.trim() && !fileInput.files?.length){preview.src=urlInput.value.trim();}});
</script>
<?php include __DIR__ . '/footer.php'; ?>
</body></html>
