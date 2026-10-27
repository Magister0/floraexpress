<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/product_ai_tagging_lib.php';
require_once __DIR__ . '/audit_log_lib.php';

$pdo = db();
product_ai_tagging_ensure_schema($pdo);

$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? ''), ['admin','manager'], true)) {
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

        if ($action === 'save_tags') {
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) throw new RuntimeException('Неверный товар.');

            $splitField = function (string $v): array {
                $arr = preg_split('~[,;\n]+~', $v) ?: [];
                return array_values(array_filter(array_map('trim', $arr), fn($x) => $x !== ''));
            };

            $data = [
                'occasions'       => $splitField((string)($_POST['occasions'] ?? '')),
                'avoid_occasions' => $splitField((string)($_POST['avoid_occasions'] ?? '')),
                'recipients'      => $splitField((string)($_POST['recipients'] ?? '')),
                'moods'           => $splitField((string)($_POST['moods'] ?? '')),
                'season'          => $splitField((string)($_POST['season'] ?? '')),
                'age_group'       => $splitField((string)($_POST['age_group'] ?? '')),
                'keywords'        => $splitField((string)($_POST['keywords'] ?? '')),
                'style'           => trim((string)($_POST['style'] ?? '')) ?: null,
                'price_tier'      => trim((string)($_POST['price_tier'] ?? '')) ?: null,
                'analysis'        => trim((string)($_POST['analysis'] ?? '')) ?: null,
                'confidence'      => 1.0,
            ];

            product_ai_metadata_save($pdo, $productId, $data, 'manual_edit', true);
            audit_log_user_action($pdo, 'product_ai_tags_edit', 'product', $productId);
            $ok = 'Теги обновлены.';
        } elseif ($action === 'delete') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $pdo->prepare('DELETE FROM product_ai_metadata WHERE product_id = ?')->execute([$productId]);
            audit_log_user_action($pdo, 'product_ai_tags_delete', 'product', $productId);
            $ok = 'Анализ удалён, можно перепрогнать в /ai_analyze_catalog.php.';
        }

        header('Location: admin_ai_catalog.php?ok=' . urlencode($ok));
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

if (isset($_GET['ok']) && $err === '') $ok = (string)$_GET['ok'];

$stats = product_ai_stats($pdo);

$editId = (int)($_GET['edit'] ?? 0);
$editProduct = null;
$editMeta = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT id, name, price, COALESCE(category,'') AS category, COALESCE(description,'') AS description, image FROM products WHERE id = ? LIMIT 1");
    $st->execute([$editId]);
    $editProduct = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $editMeta = product_ai_metadata_get($pdo, $editId);
}

// Список товаров с тегами + товары без тегов отдельно
$rows = $pdo->query("
    SELECT p.id, p.name, p.price, p.image, COALESCE(p.category,'') AS category,
           m.occasions, m.avoid_occasions, m.recipients, m.moods, m.style,
           m.price_tier, m.confidence, m.analysis, m.manually_edited, m.analyzed_at
    FROM products p
    LEFT JOIN product_ai_metadata m ON m.product_id = p.id
    WHERE COALESCE(p.is_active,1) = 1
    ORDER BY (m.product_id IS NULL) DESC, m.analyzed_at DESC NULLS LAST, p.id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$arrToStr = function ($v): string {
    if (is_string($v)) {
        $d = json_decode($v, true);
        if (is_array($d)) return implode(', ', $d);
        return $v;
    }
    return is_array($v) ? implode(', ', $v) : '';
};
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI-теги товаров — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .at-wrap{max-width:1280px;margin:24px auto;padding:0 16px;display:grid;gap:18px}
    .at-card{background:#fff;border:1px solid #eef2f7;border-radius:18px;padding:20px;box-shadow:var(--shadow-1)}
    .at-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
    .at-stat{padding:11px;border-radius:12px;background:#f9fbfa;border:1px solid #e7ece8}
    .at-stat__label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;font-weight:700}
    .at-stat__value{font-size:20px;font-weight:900;color:#175a37;margin-top:4px}
    .at-table{width:100%;border-collapse:collapse;font-size:13px}
    .at-table th,.at-table td{padding:9px 8px;border-bottom:1px solid #f0f4f1;text-align:left;vertical-align:top}
    .at-table th{font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;background:#f9fbfa;font-weight:700}
    .at-tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;margin:1px}
    .at-tag--good{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
    .at-tag--bad{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}
    .at-tag--mood{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
    .at-tag--none{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
    .at-tag--edit{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
    .at-pic{width:48px;height:48px;border-radius:10px;background:#f3f4f6;overflow:hidden}
    .at-pic img{width:100%;height:100%;object-fit:cover}
    .at-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .at-form-grid .full{grid-column:1/-1}
    .at-field{display:grid;gap:5px}
    .at-field label{font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.3px}
    .notice{padding:10px 14px;border-radius:12px;margin:0 0 12px}
    .notice--err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .notice--ok {background:#ecfdf5;border:1px solid #bbf7d0;color:#166534}
    .at-mini{font-size:11px;color:#6b7280}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="at-wrap">
  <div class="at-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h1 style="margin:0;font-size:24px">AI-теги товаров</h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn" href="ai_analyze_catalog.php">Запустить анализ</a>
        <a class="btn btn--ghost" href="admin_orders.php">К заказам</a>
      </div>
    </div>

    <div class="at-stats" style="margin-top:14px">
      <div class="at-stat"><div class="at-stat__label">Товаров</div><div class="at-stat__value"><?= (int)$stats['total_products'] ?></div></div>
      <div class="at-stat"><div class="at-stat__label">Размечено AI</div><div class="at-stat__value"><?= (int)$stats['analyzed'] ?></div></div>
      <div class="at-stat"><div class="at-stat__label">Без разметки</div><div class="at-stat__value"><?= (int)$stats['unanalyzed'] ?></div></div>
      <div class="at-stat"><div class="at-stat__label">Покрытие</div><div class="at-stat__value"><?= number_format((float)$stats['coverage_percent'], 1) ?>%</div></div>
      <div class="at-stat"><div class="at-stat__label">Редактировано</div><div class="at-stat__value"><?= (int)$stats['manually_edited'] ?></div></div>
    </div>

    <?php if ($err): ?><div class="notice notice--err" style="margin-top:12px"><?= $h($err) ?></div><?php endif; ?>
    <?php if ($ok && !$err): ?><div class="notice notice--ok" style="margin-top:12px"><?= $h($ok) ?></div><?php endif; ?>
  </div>

  <?php if ($editProduct): ?>
  <div class="at-card">
    <h2 style="margin:0 0 12px;font-size:18px">Редактирование тегов: <?= $h($editProduct['name']) ?></h2>
    <form method="post" class="at-form-grid">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="save_tags">
      <input type="hidden" name="product_id" value="<?= (int)$editProduct['id'] ?>">

      <div class="at-field full">
        <label>Анализ (фраза для админов)</label>
        <input class="input" name="analysis" value="<?= $h((string)($editMeta['analysis'] ?? '')) ?>">
      </div>

      <div class="at-field">
        <label>Поводы (occasions) — через запятую</label>
        <input class="input" name="occasions" value="<?= $h($arrToStr($editMeta['occasions'] ?? '')) ?>" placeholder="birthday, march8, romance">
        <span class="at-mini">birthday · march8 · valentine · wedding · anniversary · mothers_day · first_school · apology · funeral · recovery · congrats · romance · just_because</span>
      </div>

      <div class="at-field">
        <label>Не подходит для (avoid)</label>
        <input class="input" name="avoid_occasions" value="<?= $h($arrToStr($editMeta['avoid_occasions'] ?? '')) ?>" placeholder="funeral, wedding">
      </div>

      <div class="at-field">
        <label>Получатели</label>
        <input class="input" name="recipients" value="<?= $h($arrToStr($editMeta['recipients'] ?? '')) ?>" placeholder="mother, wife, girlfriend">
        <span class="at-mini">mother · father · girlfriend · wife · husband · colleague · teacher · child · friend · grandma · man · anyone</span>
      </div>

      <div class="at-field">
        <label>Настроение</label>
        <input class="input" name="moods" value="<?= $h($arrToStr($editMeta['moods'] ?? '')) ?>" placeholder="romantic, elegant">
        <span class="at-mini">romantic · bold · passionate · cheerful · elegant · natural · sad · festive · minimalist</span>
      </div>

      <div class="at-field">
        <label>Стиль</label>
        <input class="input" name="style" value="<?= $h((string)($editMeta['style'] ?? '')) ?>" placeholder="classic, modern, rustic, premium">
      </div>

      <div class="at-field">
        <label>Бюджетный класс</label>
        <select class="input" name="price_tier">
          <?php foreach ([''=>'—','budget'=>'budget','mid'=>'mid','premium'=>'premium'] as $k => $v): ?>
            <option value="<?= $h($k) ?>" <?= (string)($editMeta['price_tier'] ?? '') === $k ? 'selected' : '' ?>><?= $h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="at-field">
        <label>Сезон</label>
        <input class="input" name="season" value="<?= $h($arrToStr($editMeta['season'] ?? '')) ?>" placeholder="spring, year_round">
      </div>

      <div class="at-field">
        <label>Возрастная группа</label>
        <input class="input" name="age_group" value="<?= $h($arrToStr($editMeta['age_group'] ?? '')) ?>" placeholder="young, middle, elderly, any">
      </div>

      <div class="at-field full">
        <label>Ключевые слова</label>
        <input class="input" name="keywords" value="<?= $h($arrToStr($editMeta['keywords'] ?? '')) ?>" placeholder="нежный, пастель, юбилей">
      </div>

      <div class="full" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <button type="submit" class="btn">Сохранить</button>
        <a class="btn btn--ghost" href="admin_ai_catalog.php">Отмена</a>
        <?php if ($editMeta): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Удалить AI-разметку этого товара?')">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="product_id" value="<?= (int)$editProduct['id'] ?>">
            <button class="btn btn--ghost" type="submit" style="color:#b91c1c">Удалить разметку</button>
          </form>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="at-card">
    <h2 style="margin:0 0 14px;font-size:18px">Все товары с разметкой (<?= count($rows) ?>)</h2>
    <div style="overflow-x:auto">
      <table class="at-table">
        <thead><tr>
          <th></th><th>Товар</th><th>Поводы</th><th>Не подходит</th><th>Получатели</th><th>Настроение</th><th>Класс</th><th>Статус</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $occ   = $r['occasions']       ? (json_decode((string)$r['occasions'], true) ?: []) : [];
          $avoid = $r['avoid_occasions'] ? (json_decode((string)$r['avoid_occasions'], true) ?: []) : [];
          $rcpt  = $r['recipients']      ? (json_decode((string)$r['recipients'], true) ?: []) : [];
          $mood  = $r['moods']           ? (json_decode((string)$r['moods'], true) ?: []) : [];
          $hasMeta = !empty($r['analyzed_at']);
        ?>
          <tr style="<?= $hasMeta ? '' : 'background:#fff7ed' ?>">
            <td>
              <div class="at-pic">
                <?php if (!empty($r['image'])): ?>
                  <img src="<?= $h($r['image']) ?>" alt="" onerror="this.style.display='none'">
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div style="font-weight:700"><?= $h($r['name']) ?></div>
              <div class="at-mini"><?= $h((string)$r['category']) ?> · <?= number_format((float)$r['price'], 0, ',', ' ') ?> ₽</div>
              <?php if (!empty($r['analysis'])): ?>
                <div class="at-mini" style="margin-top:3px"><?= $h((string)$r['analysis']) ?></div>
              <?php endif; ?>
            </td>
            <td><?php if (!$occ): ?><span class="at-tag at-tag--none">—</span><?php endif; foreach ($occ as $t): ?><span class="at-tag at-tag--good"><?= $h($t) ?></span><?php endforeach; ?></td>
            <td><?php if (!$avoid): ?><span class="at-tag at-tag--none">—</span><?php endif; foreach ($avoid as $t): ?><span class="at-tag at-tag--bad"><?= $h($t) ?></span><?php endforeach; ?></td>
            <td><?php if (!$rcpt): ?><span class="at-tag at-tag--none">—</span><?php endif; foreach ($rcpt as $t): ?><span class="at-tag at-tag--mood"><?= $h($t) ?></span><?php endforeach; ?></td>
            <td><?php if (!$mood): ?><span class="at-tag at-tag--none">—</span><?php endif; foreach ($mood as $t): ?><span class="at-tag at-tag--mood"><?= $h($t) ?></span><?php endforeach; ?></td>
            <td><?= $h((string)($r['price_tier'] ?? '—')) ?></td>
            <td>
              <?php if (!$hasMeta): ?>
                <span class="at-tag at-tag--none">Не размечен</span>
              <?php else: ?>
                <?php if (!empty($r['manually_edited'])): ?>
                  <span class="at-tag at-tag--edit">правлен вручную</span>
                <?php else: ?>
                  <span class="at-tag at-tag--good">AI</span>
                <?php endif; ?>
                <?php if ($r['confidence'] !== null): ?>
                  <div class="at-mini">уверенность <?= number_format((float)$r['confidence'] * 100, 0) ?>%</div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td><a class="btn btn--ghost btn--sm" href="admin_ai_catalog.php?edit=<?= (int)$r['id'] ?>">Изм.</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
