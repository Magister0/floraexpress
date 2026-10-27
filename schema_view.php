<?php
declare(strict_types=1);

/**
 * Просмотр актуальной схемы БД в браузере.
 * Доступ только администратору/менеджеру (как у остальных admin-страниц).
 * Только чтение information_schema — никаких изменений в БД.
 */

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('schema_view.php'));
    exit;
}
$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('403');
}

$h = fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema='public' AND table_type='BASE TABLE'
      ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$pkStmt = $pdo->prepare(
    "SELECT kcu.column_name FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name
      WHERE tc.table_schema='public' AND tc.table_name=:t AND tc.constraint_type='PRIMARY KEY'");
$fkStmt = $pdo->prepare(
    "SELECT kcu.column_name, ccu.table_name AS rt, ccu.column_name AS rc
       FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name
       JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name=tc.constraint_name
      WHERE tc.table_schema='public' AND tc.table_name=:t AND tc.constraint_type='FOREIGN KEY'");
$colStmt = $pdo->prepare(
    "SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
       FROM information_schema.columns
      WHERE table_schema='public' AND table_name=:t ORDER BY ordinal_position");

/* ---- Текстовый режим: ?txt=1 — удобно скопировать всё разом ---- */
if (isset($_GET['txt'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "БАЗА ДАННЫХ: " . DB_NAME . "  | таблиц: " . count($tables) . "\n";
    echo str_repeat('=', 70) . "\n\n";
    foreach ($tables as $t) {
        $pkStmt->execute([':t'=>$t]); $pk = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
        $fkStmt->execute([':t'=>$t]); $fk = [];
        foreach ($fkStmt->fetchAll() as $r) { $fk[$r['column_name']] = $r['rt'].'.'.$r['rc']; }
        $colStmt->execute([':t'=>$t]); $cols = $colStmt->fetchAll();
        $cnt = (int)$pdo->query("SELECT count(*) FROM " . $t)->fetchColumn();
        echo "ТАБЛИЦА: {$t}   (строк: {$cnt})\n" . str_repeat('-', 70) . "\n";
        foreach ($cols as $c) {
            $type = $c['data_type'] . ($c['character_maximum_length'] ? '('.$c['character_maximum_length'].')' : '');
            $flags = [];
            if (in_array($c['column_name'], $pk, true)) $flags[] = 'PK';
            if (isset($fk[$c['column_name']]))          $flags[] = 'FK->' . $fk[$c['column_name']];
            if ($c['is_nullable'] === 'NO')             $flags[] = 'NOT NULL';
            if ($c['column_default'] !== null)          $flags[] = 'default ' . $c['column_default'];
            printf("  %-26s %-16s %s\n", $c['column_name'], $type, implode(', ', $flags));
        }
        echo "\n";
    }
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Схема базы данных — FloraExpress</title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f4f6f9;color:#222}
  header{background:#2d6a4f;color:#fff;padding:16px 24px}
  header h1{margin:0;font-size:20px}
  header a{color:#cde9dd;font-size:14px}
  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  .meta{margin:0 0 18px;color:#555}
  .toc{background:#fff;border:1px solid #e0e6ee;border-radius:8px;padding:12px 16px;margin-bottom:20px}
  .toc a{display:inline-block;margin:3px 10px 3px 0;font-size:13px;color:#2d6a4f;text-decoration:none}
  table{width:100%;border-collapse:collapse;background:#fff;margin:0 0 26px;
        border:1px solid #cdd6e2;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05)}
  caption{caption-side:top;text-align:left;font-weight:700;font-size:16px;color:#1f3864;
          padding:12px 14px;background:#dce6f4;border-bottom:1px solid #cdd6e2}
  caption .cnt{font-weight:400;color:#5a6b86;font-size:13px}
  th,td{padding:7px 12px;text-align:left;font-size:13px;border-bottom:1px solid #eef1f6}
  th{background:#f0f4fa;color:#33445f;font-weight:600}
  tr:last-child td{border-bottom:none}
  .pk{color:#c0504d;font-weight:700}
  .fk{color:#2d6a4f;font-weight:600}
  .nn{color:#888}
  code{background:#f3f3f3;padding:1px 5px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<header>
  <h1>Схема базы данных «<?= $h(DB_NAME) ?>»</h1>
  <a href="admin_panel.php">← в админ-панель</a>
</header>
<div class="wrap">
  <p class="meta">Всего таблиц: <b><?= count($tables) ?></b>. Данные получены из <code>information_schema</code> (только чтение).
     &nbsp;|&nbsp; <a href="?txt=1" target="_blank"><b>Показать как текст</b></a> (для копирования).</p>

  <div class="toc">
    <?php foreach ($tables as $t): ?><a href="#t_<?= $h($t) ?>"><?= $h($t) ?></a><?php endforeach; ?>
  </div>

  <?php foreach ($tables as $t):
      $pkStmt->execute([':t'=>$t]); $pk = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
      $fkStmt->execute([':t'=>$t]); $fk = [];
      foreach ($fkStmt->fetchAll() as $r) { $fk[$r['column_name']] = $r['rt'].'.'.$r['rc']; }
      $colStmt->execute([':t'=>$t]); $cols = $colStmt->fetchAll();
      $cnt = (int)$pdo->query("SELECT count(*) FROM " . $t)->fetchColumn();
  ?>
  <table id="t_<?= $h($t) ?>">
    <caption><?= $h($t) ?> <span class="cnt">— строк: <?= $cnt ?>, полей: <?= count($cols) ?></span></caption>
    <tr><th>Поле</th><th>Тип</th><th>Ключ / ссылка</th><th>NULL</th><th>По умолчанию</th></tr>
    <?php foreach ($cols as $c):
        $type = $c['data_type'] . ($c['character_maximum_length'] ? '('.$c['character_maximum_length'].')' : '');
        $isPk = in_array($c['column_name'], $pk, true);
        $isFk = isset($fk[$c['column_name']]);
    ?>
    <tr>
      <td<?= $isPk ? ' class="pk"' : '' ?>><?= $h($c['column_name']) ?></td>
      <td><code><?= $h($type) ?></code></td>
      <td><?php if ($isPk) echo '<span class="pk">PK</span> ';
                if ($isFk) echo '<span class="fk">FK → '.$h($fk[$c['column_name']]).'</span>'; ?></td>
      <td><?= $c['is_nullable']==='NO' ? '<span class="nn">NOT NULL</span>' : '' ?></td>
      <td><?= $c['column_default']!==null ? '<code>'.$h($c['column_default']).'</code>' : '' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endforeach; ?>
</div>
</body>
</html>
