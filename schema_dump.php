<?php
declare(strict_types=1);

/**
 * Выгрузка актуальной схемы базы данных PostgreSQL.
 * Запускать на сервере из CLI:
 *   php schema_dump.php            — вывод в консоль
 *   php schema_dump.php > schema.txt   — сохранить в файл
 *
 * Доступ через web запрещён (как у migrate_schema.php). Только чтение
 * information_schema, никаких изменений в БД.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Доступ только из CLI.');
}

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

// Список таблиц схемы public
$tables = $pdo->query(
    "SELECT table_name
       FROM information_schema.tables
      WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
      ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

echo "БАЗА ДАННЫХ: " . DB_NAME . "  | таблиц: " . count($tables) . "\n";
echo str_repeat('=', 70) . "\n\n";

// Первичные ключи
$pkStmt = $pdo->prepare(
    "SELECT kcu.column_name
       FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu
         ON tc.constraint_name = kcu.constraint_name
      WHERE tc.table_schema='public' AND tc.table_name = :t
        AND tc.constraint_type='PRIMARY KEY'"
);

// Внешние ключи
$fkStmt = $pdo->prepare(
    "SELECT kcu.column_name, ccu.table_name AS ref_table, ccu.column_name AS ref_col
       FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu
         ON tc.constraint_name = kcu.constraint_name
       JOIN information_schema.constraint_column_usage ccu
         ON ccu.constraint_name = tc.constraint_name
      WHERE tc.table_schema='public' AND tc.table_name = :t
        AND tc.constraint_type='FOREIGN KEY'"
);

$colStmt = $pdo->prepare(
    "SELECT column_name, data_type, character_maximum_length,
            is_nullable, column_default
       FROM information_schema.columns
      WHERE table_schema='public' AND table_name = :t
      ORDER BY ordinal_position"
);

foreach ($tables as $t) {
    $pkStmt->execute([':t' => $t]);
    $pk = $pkStmt->fetchAll(PDO::FETCH_COLUMN);

    $fkStmt->execute([':t' => $t]);
    $fk = [];
    foreach ($fkStmt->fetchAll() as $r) {
        $fk[$r['column_name']] = $r['ref_table'] . '.' . $r['ref_col'];
    }

    $colStmt->execute([':t' => $t]);
    $cols = $colStmt->fetchAll();

    // кол-во строк
    $cnt = $pdo->query("SELECT count(*) FROM " . $t)->fetchColumn();

    echo "ТАБЛИЦА: {$t}   (строк: {$cnt})\n";
    echo str_repeat('-', 70) . "\n";
    foreach ($cols as $c) {
        $type = $c['data_type'];
        if ($c['character_maximum_length']) {
            $type .= '(' . $c['character_maximum_length'] . ')';
        }
        $flags = [];
        if (in_array($c['column_name'], $pk, true)) $flags[] = 'PK';
        if (isset($fk[$c['column_name']]))          $flags[] = 'FK->' . $fk[$c['column_name']];
        if ($c['is_nullable'] === 'NO')             $flags[] = 'NOT NULL';
        if ($c['column_default'] !== null)          $flags[] = 'default ' . $c['column_default'];

        printf("  %-26s %-16s %s\n",
            $c['column_name'], $type, implode(', ', $flags));
    }
    echo "\n";
}
