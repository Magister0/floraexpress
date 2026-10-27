<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_advisor_lib.php';

$pdo = db();
ai_advisor_ensure_schema($pdo);

/**
 * Классический язык цветов (флориография) — справочник для AI-подбора.
 * Источники: классические трактаты XIX в. (Le Langage des Fleurs, Charlotte de Latour, 1819) +
 *           современная флористическая практика.
 */
$meanings = [
    // РОЗЫ
    ['rose_red',    'Красная роза',  'красная',   'любовь, страсть, признание', ['romantic','passionate'], ['valentine','romance','anniversary','march8'], []],
    ['rose_white',  'Белая роза',    'белая',     'чистота, невинность, начало', ['elegant','romantic'],   ['wedding','anniversary'], ['funeral']],
    ['rose_pink',   'Розовая роза',  'розовая',   'нежность, восхищение, благодарность', ['romantic','cheerful'], ['birthday','march8','mothers_day','romance'], []],
    ['rose_yellow', 'Жёлтая роза',   'жёлтая',    'дружба, радость, веселье', ['cheerful'], ['friend','congrats','birthday'], ['wedding','romance']],
    ['rose_white_red','Бело-красная роза','бело-красная','единство, гармония', ['elegant'], ['wedding','anniversary'], []],

    // ТЮЛЬПАНЫ
    ['tulip_red',    'Красный тюльпан', 'красный',  'идеальная любовь, чувственность', ['romantic','passionate'], ['march8','valentine','romance'], []],
    ['tulip_pink',   'Розовый тюльпан', 'розовый',  'забота, привязанность', ['romantic'], ['march8','mothers_day'], []],
    ['tulip_yellow', 'Жёлтый тюльпан',  'жёлтый',   'солнечная радость, мысли о тебе', ['cheerful'], ['march8','congrats'], []],
    ['tulip_white',  'Белый тюльпан',   'белый',    'прощение, чистота', ['elegant'], ['apology','march8'], []],

    // ПИОНЫ
    ['peony_pink',   'Розовый пион',  'розовый',  'счастливый брак, удача', ['romantic','elegant'], ['wedding','anniversary','march8'], []],
    ['peony_white',  'Белый пион',    'белый',    'застенчивость, изысканность', ['elegant'], ['wedding','romance'], []],
    ['peony_red',    'Красный пион',  'красный',  'честь, благородство', ['bold','elegant'], ['congrats','anniversary'], []],

    // ХРИЗАНТЕМЫ
    ['chrysanthemum_white',  'Белая хризантема',  'белая',  'правда, искренность; в РФ — траур', ['sad'], ['funeral'], ['romance','wedding','birthday']],
    ['chrysanthemum_yellow', 'Жёлтая хризантема', 'жёлтая', 'долголетие, теплая дружба', ['cheerful'], ['birthday','first_school','friend'], []],
    ['chrysanthemum_red',    'Красная хризантема','красная','глубокая любовь',          ['romantic'], ['anniversary'], []],

    // ЛИЛИИ
    ['lily_white',  'Белая лилия', 'белая',  'чистота, величие', ['elegant'], ['wedding','first_school'], ['funeral']],
    ['lily_pink',   'Розовая лилия','розовая','процветание, женственность', ['elegant'], ['march8','birthday'], []],

    // ГВОЗДИКИ
    ['carnation_red',   'Красная гвоздика', 'красная',  'восхищение, в РФ — память и победа', ['bold'], ['congrats'], ['wedding','romance']],
    ['carnation_white', 'Белая гвоздика',   'белая',    'чистая любовь, удача', ['elegant'], ['birthday','first_school'], []],
    ['carnation_pink',  'Розовая гвоздика', 'розовая',  'материнская любовь', ['romantic'], ['mothers_day','march8'], []],
    ['carnation_yellow','Жёлтая гвоздика',  'жёлтая',   'разочарование, отказ', ['sad'], [], ['wedding','romance','anniversary','valentine']],

    // ОРХИДЕИ
    ['orchid', 'Орхидея', null, 'роскошь, утончённость, экзотика', ['elegant','bold'], ['anniversary','romance','birthday'], []],

    // ГЕРБЕРЫ
    ['gerbera_orange', 'Оранжевая гербера', 'оранжевая', 'жизнерадостность, оптимизм', ['cheerful'], ['birthday','congrats','recovery'], []],
    ['gerbera_pink',   'Розовая гербера',   'розовая',   'нежная радость',              ['cheerful','romantic'], ['birthday','march8'], []],

    // АЛЬСТРОМЕРИИ
    ['alstroemeria', 'Альстромерия', null, 'дружба, преданность, удача', ['cheerful','elegant'], ['friend','congrats','birthday'], []],

    // ЭУСТОМА
    ['eustoma_white', 'Белая эустома', 'белая', 'нежность, спокойствие', ['elegant'], ['wedding','romance'], []],
    ['eustoma_pink',  'Розовая эустома','розовая','очарование',         ['romantic','elegant'], ['romance','march8'], []],

    // ПОЛЕВЫЕ
    ['chamomile', 'Ромашка', null, 'невинность, искренние чувства', ['natural','cheerful'], ['friend','romance','first_school'], []],
    ['cornflower','Василёк',  null, 'верность, простота',           ['natural'], ['friend'], []],
    ['lavender',  'Лаванда',  null, 'преданность, покой',           ['natural','elegant'], ['anniversary'], []],

    // ДЕКОРАТИВНОЕ
    ['eucalyptus','Эвкалипт', null, 'защита, благополучие',          ['natural','elegant'], ['recovery'], []],
    ['baby_breath','Гипсофила', null,'чистота, нежность чувств',     ['elegant','romantic'], ['wedding','romance'], []],
];

$findByKey = $pdo->prepare('SELECT id FROM flower_meanings WHERE flower_key = ? LIMIT 1');
$insert = $pdo->prepare("INSERT INTO flower_meanings
    (flower_key, flower_name_ru, color, meaning, mood, occasions, avoid_for, source)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$update = $pdo->prepare("UPDATE flower_meanings SET
    flower_name_ru=?, color=?, meaning=?, mood=?, occasions=?, avoid_for=?, source=?
    WHERE id=?");

$created = $updated = 0;

foreach ($meanings as $row) {
    [$key, $name, $color, $meaning, $mood, $occasions, $avoid] = $row;

    $findByKey->execute([$key]);
    $existingId = (int)($findByKey->fetchColumn() ?: 0);

    $params = [
        $name, $color, $meaning,
        json_encode($mood, JSON_UNESCAPED_UNICODE),
        json_encode($occasions, JSON_UNESCAPED_UNICODE),
        json_encode($avoid, JSON_UNESCAPED_UNICODE),
        'Классическая флориография (Le Langage des Fleurs, 1819) + современная практика',
    ];

    if ($existingId > 0) {
        $params[] = $existingId;
        $update->execute($params);
        $updated++;
    } else {
        array_unshift($params, $key);
        $insert->execute($params);
        $created++;
    }
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM flower_meanings')->fetchColumn();
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Язык цветов добавлен</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f7f7f8;color:#111827;padding:40px;line-height:1.6}
    .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
    h1{margin:0 0 12px;font-size:30px}
    .ok{display:inline-block;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:10px 14px;border-radius:999px;font-weight:700;margin-bottom:16px}
    .actions a{display:inline-block;margin-right:12px;padding:12px 16px;border-radius:12px;text-decoration:none;border:1px solid #d1d5db;color:#111827}
    .actions a.primary{background:#175a37;color:#fff;border-color:#175a37}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <div class="card">
    <div class="ok">Готово</div>
    <h1>Язык цветов загружен</h1>
    <p>Создано: <strong><?= $created ?></strong>. Обновлено: <strong><?= $updated ?></strong>.</p>
    <p>Всего записей в справочнике: <strong><?= $total ?></strong>.</p>
    <p>Справочник используется AI-консультантом как база знаний для рекомендаций (RAG).</p>
    <div class="actions">
      <a class="primary" href="index.php">На главную (попробовать чат)</a>
      <a href="admin_ai_chats.php">История диалогов</a>
    </div>
  </div>
</body>
</html>
