<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/category_lib.php';

$pdo = db();
$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? 'user'), ['admin','manager'], true)) {
    header('Location: auth.php?mode=login&return=' . urlencode('categories.php'));
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

categories_bootstrap($pdo);

function bool_pg($v): bool {
    if (is_bool($v)) return $v;
    $str = strtolower(trim((string)$v));
    return in_array($str, ['1','t','true','yes','on'], true);
}
function contains_ci(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    return stripos($haystack, $needle) !== false;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            throw new RuntimeException('Сессия устарела. Обновите страницу.');
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $slugRaw = trim((string)($_POST['slug'] ?? ''));
            $slug = $slugRaw !== '' ? category_slugify($slugRaw) : category_slugify($name);
            $sort = (int)($_POST['sort_order'] ?? 100);
            $active = isset($_POST['is_active']) ? 'true' : 'false';
            if ($name === '') throw new RuntimeException('Введите название категории.');
            $st = $pdo->prepare('INSERT INTO categories (slug, name, sort_order, is_active) VALUES (?, ?, ?, ?::boolean) ON CONFLICT (slug) DO NOTHING');
            $st->execute([$slug, $name, $sort, $active]);
            if ($st->rowCount() < 1) throw new RuntimeException('Категория с таким slug уже существует.');
            $success = 'Категория добавлена.';
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = category_slugify(trim((string)($_POST['slug'] ?? '')));
            $sort = (int)($_POST['sort_order'] ?? 100);
            $active = isset($_POST['is_active']) ? 'true' : 'false';
            if ($id <= 0 || $name === '' || $slug === '') throw new RuntimeException('Заполните название и slug.');

            $stOld = $pdo->prepare('SELECT slug FROM categories WHERE id = ? LIMIT 1');
            $stOld->execute([$id]);
            $old = $stOld->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new RuntimeException('Категория не найдена.');
            $oldSlug = (string)$old['slug'];

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE categories SET slug = ?, name = ?, sort_order = ?, is_active = ?::boolean WHERE id = ?')->execute([$slug, $name, $sort, $active, $id]);
            if ($oldSlug !== $slug) {
                $pdo->prepare('UPDATE products SET category = ? WHERE category = ?')->execute([$slug, $oldSlug]);
            }
            $pdo->commit();
            $success = 'Категория обновлена.';
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Некорректная категория.');
            $pdo->prepare('UPDATE categories SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
            $success = 'Статус категории обновлён.';
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Некорректная категория.');
            $st = $pdo->prepare('SELECT slug FROM categories WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Категория не найдена.');
            $slug = (string)$row['slug'];
            if ($slug === 'other') throw new RuntimeException('Категорию «Другое» удалять нельзя.');

            $countSt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category = ?');
            $countSt->execute([$slug]);
            if ((int)$countSt->fetchColumn() > 0) throw new RuntimeException('Нельзя удалить категорию, пока в ней есть товары.');

            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            $success = 'Категория удалена.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'active', 'hidden'], true)) $status = 'all';

$categories = categories_fetch_all($pdo, false);
$productCounts = [];
foreach ($pdo->query("SELECT COALESCE(NULLIF(TRIM(category), ''), 'other') AS slug, COUNT(*) AS cnt FROM products GROUP BY 1") as $row) {
    $productCounts[(string)$row['slug']] = (int)$row['cnt'];
}

$totalCategories = count($categories);
$categories = array_values(array_filter($categories, static function (array $cat) use ($q, $status): bool {
    $active = bool_pg($cat['is_active'] ?? false);
    if ($status === 'active' && !$active) return false;
    if ($status === 'hidden' && $active) return false;
    if ($q !== '') {
        $hay = (string)($cat['name'] ?? '') . ' ' . (string)($cat['slug'] ?? '');
        if (!contains_ci($hay, $q)) return false;
    }
    return true;
}));
$filteredCount = count($categories);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Категории — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .categories-page{padding-top:10px}
    .top-zone{margin:0 0 28px;padding:18px;border:1px solid #e3edf4;border-radius:28px;background:linear-gradient(180deg,#f7fbff 0%, #fcfefd 100%);box-shadow:0 14px 34px rgba(14,30,37,.04)}
    .top-grid{display:grid;grid-template-columns:minmax(0,.92fr) minmax(0,1.08fr);gap:18px;align-items:stretch}
    @media (max-width:1100px){.top-grid{grid-template-columns:1fr}}
    .box{background:#fff;border:1px solid #dfeaf0;border-radius:24px;padding:22px;min-width:0}
    .box-title{display:flex;align-items:center;gap:10px;margin:0 0 10px;font-size:20px;font-weight:800;color:#0f172a}
    .dot{width:10px;height:10px;border-radius:999px;background:linear-gradient(135deg,#22c55e,#34d399);box-shadow:0 0 0 6px rgba(34,197,94,.12)}
    .box-sub{margin:0 0 18px;color:#667085;line-height:1.65}
    .msg{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:700}
    .msg.ok{background:#ecfdf3;border:1px solid #c9f1d8;color:#166534}
    .msg.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .field-label{display:block;font-size:14px;font-weight:700;color:#667085;margin-bottom:7px}
    .create-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.78fr) 120px;gap:12px}
    .create-check{margin-top:12px;display:flex;align-items:center;gap:12px;border:1px solid #dfe8e3;border-radius:16px;padding:14px 16px;background:#fbfdfc;font-weight:700;color:#10233c}
    .create-check input{width:18px;height:18px;accent-color:#16a34a}
    .top-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    .filter-form{display:grid;gap:14px}
    .filter-search-row{display:grid;grid-template-columns:1fr;gap:10px}
    .filter-controls{display:grid;grid-template-columns:minmax(220px,300px) auto auto;gap:12px;align-items:end}
    .filter-stats{color:#667085;font-size:14px}
    .categories-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
    @media (max-width:980px){.categories-grid{grid-template-columns:1fr}}
    .cat-item{display:flex;flex-direction:column;gap:14px;border:1px solid #edf1ef;border-radius:24px;padding:20px;background:#fff;min-width:0;box-shadow:var(--shadow-1)}
    .cat-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
    .cat-title{margin:0 0 6px;font-size:24px;font-weight:800;line-height:1.15;color:#07152f}
    .cat-desc{margin:0;color:#667085;line-height:1.55;max-width:420px}
    .cat-meta{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .pill{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;font-size:13px;font-weight:800;border:1px solid #d7eadf;background:#f4fbf7;color:#185a37;white-space:nowrap}
    .pill.off{background:#fff1f2;border-color:#fecdd3;color:#9f1239}
    .cat-form-grid{display:grid;grid-template-columns:minmax(0,1fr) 140px;gap:12px}
    .checkbox-card{display:flex;align-items:center;gap:12px;border:1px solid #dfe8e3;border-radius:18px;padding:15px 16px;background:#fbfdfc;font-weight:700;color:#10233c}
    .checkbox-card input{width:18px;height:18px;accent-color:#16a34a;flex:0 0 auto}
    .cat-actions-line{display:flex;flex-wrap:wrap;gap:10px;align-items:stretch}
    .cat-actions-line form{display:flex;margin:0}
    .cat-actions-line .btn{min-height:46px;padding:0 18px;display:inline-flex;align-items:center;justify-content:center;border-radius:14px;font-weight:800}
    .cat-actions-line .btn--ghost{background:#fff;border:1px solid #d9e5de}
    .mini-note{font-size:14px;color:#667085;line-height:1.6;margin-top:2px}
    @media (max-width:920px){
      .create-grid,.filter-controls,.cat-form-grid{grid-template-columns:1fr}
      .filter-controls .btn,.top-actions .btn,.cat-actions-line .btn{width:100%}
      .cat-actions-line form{width:100%}
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section categories-page">
  <div class="container">
    <h1 class="section__title">Категории</h1>
    <p class="section__sub">Новые категории автоматически появляются в каталоге и в формах добавления и редактирования товаров.</p>

    <?php if ($success): ?><div class="msg ok"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

    <div class="top-zone">
      <div class="top-grid">
        <div class="box">
          <h2 class="box-title"><span class="dot"></span>Новая категория</h2>
          <p class="box-sub">Создайте новый раздел каталога. Если slug не заполнить, он соберётся автоматически из названия.</p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <div class="create-grid">
              <div>
                <label class="field-label">Название категории</label>
                <input class="input" name="name" placeholder="Например: Композиции" required>
              </div>
              <div>
                <label class="field-label">Slug</label>
                <input class="input" name="slug" placeholder="Например: kompozitsii">
              </div>
              <div>
                <label class="field-label">Порядок</label>
                <input class="input" type="number" name="sort_order" value="100">
              </div>
            </div>
            <label class="create-check"><input type="checkbox" name="is_active" checked> Показывать в каталоге</label>
            <div class="top-actions">
              <button class="btn" type="submit">Добавить категорию</button>
            </div>
          </form>
        </div>

        <div class="box">
          <h2 class="box-title"><span class="dot"></span>Фильтр</h2>
          <p class="box-sub">Сначала можно ввести название или slug на всю строку, а ниже уже выбрать статус и применить фильтр.</p>
          <form class="filter-form" method="get">
            <div class="filter-search-row">
              <div>
                <label class="field-label">Поиск по названию или slug</label>
                <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="Например: roses, bukety, mini">
              </div>
            </div>
            <div class="filter-controls">
              <div>
                <label class="field-label">Статус</label>
                <select class="input" name="status">
                  <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все</option>
                  <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Активные</option>
                  <option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>Скрытые</option>
                </select>
              </div>
              <button class="btn" type="submit">Применить</button>
              <a class="btn btn--ghost" href="categories.php">Сбросить</a>
            </div>
          </form>
          <div class="filter-stats">Показано: <?= $filteredCount ?> из <?= $totalCategories ?></div>
        </div>
      </div>
    </div>

    <?php if (!$categories): ?>
      <div class="box"><p class="mute" style="margin:0">По текущему фильтру категории не найдены.</p></div>
    <?php else: ?>
      <div class="categories-grid">
        <?php foreach ($categories as $cat): ?>
          <?php
            $catId = (int)($cat['id'] ?? 0);
            $slug = (string)($cat['slug'] ?? '');
            $name = (string)($cat['name'] ?? '');
            $sort = (int)($cat['sort_order'] ?? 100);
            $active = bool_pg($cat['is_active'] ?? false);
            $count = (int)($productCounts[$slug] ?? 0);
          ?>
          <article class="cat-item">
            <div class="cat-head">
              <div>
                <h2 class="cat-title"><?= h($name) ?></h2>
                <p class="cat-desc">Редактирование названия, slug и порядка показа в каталоге.</p>
              </div>
              <div class="cat-meta">
                <span class="pill">slug: <?= h($slug) ?></span>
                <span class="pill">товаров: <?= $count ?></span>
                <span class="pill<?= $active ? '' : ' off' ?>"><?= $active ? 'активна' : 'скрыта' ?></span>
              </div>
            </div>

            <form method="post" style="display:grid;gap:14px">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $catId ?>">

              <div class="cat-form-grid">
                <div>
                  <label class="field-label">Название</label>
                  <input class="input" name="name" value="<?= h($name) ?>" required>
                </div>
                <div>
                  <label class="field-label">Порядок</label>
                  <input class="input" type="number" name="sort_order" value="<?= $sort ?>">
                </div>
              </div>

              <div>
                <label class="field-label">Slug</label>
                <input class="input" name="slug" value="<?= h($slug) ?>" required>
              </div>

              <label class="checkbox-card"><input type="checkbox" name="is_active" <?= $active ? 'checked' : '' ?>> Показывать категорию в каталоге</label>

              <div class="cat-actions-line">
                <button class="btn" type="submit">Сохранить</button>
              </div>
            </form>

            <div class="cat-actions-line">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $catId ?>">
                <button class="btn btn--ghost" type="submit"><?= $active ? 'Скрыть' : 'Показать' ?></button>
              </form>
              <form method="post" onsubmit="return confirm('Удалить категорию?');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $catId ?>">
                <button class="btn btn--ghost" type="submit">Удалить</button>
              </form>
            </div>

            <div class="mini-note">Если изменить slug у категории, он автоматически обновится и у товаров, которые уже были привязаны к этому разделу.</div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
