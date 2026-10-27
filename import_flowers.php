<?php
// Одноразовый скрипт импорта одиночных цветов и открыток. После запуска — удалить с сервера.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? ''), ['admin', 'manager'], true)) {
    http_response_code(403);
    exit('Доступ запрещён. Войдите как администратор.');
}

$pdo = db();

// Одиночные цветы — stem_product=true, цена за 1 стебель/ветку
$stems = [
    [
        'name'        => 'Гербера персиковая',
        'price'       => 120,
        'old_price'   => null,
        'image'       => 'img/photo_1_2026-04-19_17-13-12.jpg',
        'category'    => 'герберы',
        'description' => 'Нежная персиковая гербера на длинном стебле. Солнечный и жизнерадостный цветок — яркий акцент в любом букете или самостоятельный подарок.',
    ],
    [
        'name'        => 'Роза малиновая',
        'price'       => 150,
        'old_price'   => null,
        'image'       => 'img/photo_3_2026-04-19_17-13-12.jpg',
        'category'    => 'розы',
        'description' => 'Пышная малиново-розовая роза с глубоким бархатистым цветом. Классика, которая никогда не выйдет из моды — идеальный выбор для романтического подарка.',
    ],
    [
        'name'        => 'Роза кремовая',
        'price'       => 120,
        'old_price'   => null,
        'image'       => 'img/photo_4_2026-04-19_17-13-12.jpg',
        'category'    => 'розы',
        'description' => 'Элегантная кремово-белая роза с лёгким зеленоватым оттенком лепестков. Утончённая и нежная — прекрасно смотрится как одна, так и в сборном букете.',
    ],
    [
        'name'        => 'Роза розовая',
        'price'       => 130,
        'old_price'   => null,
        'image'       => 'img/photo_5_2026-04-19_17-13-12.jpg',
        'category'    => 'розы',
        'description' => 'Нежная розовая роза с бархатными лепестками и тонким ароматом. Классический и женственный цветок — символ любви и заботы.',
    ],
    [
        'name'        => 'Хризантема кустовая фиолетовая',
        'price'       => 200,
        'old_price'   => null,
        'image'       => 'img/photo_6_2026-04-19_17-13-12.jpg',
        'category'    => 'хризантемы',
        'description' => 'Пышная кустовая хризантема насыщенного фиолетово-сиреневого цвета. Одна ветка с несколькими цветками — долго стоит и радует глаз.',
    ],
    [
        'name'        => 'Хризантема кустовая белая',
        'price'       => 180,
        'old_price'   => null,
        'image'       => 'img/photo_7_2026-04-19_17-13-12.jpg',
        'category'    => 'хризантемы',
        'description' => 'Белая кустовая хризантема ромашковидного типа. Лёгкая и воздушная, с жёлто-зелёной серединкой — классика флористики для любого повода.',
    ],
    [
        'name'        => 'Маттиола белая',
        'price'       => 120,
        'old_price'   => null,
        'image'       => 'img/photo_8_2026-04-19_17-13-12.jpg',
        'category'    => 'другое',
        'description' => 'Нежный стебель белой маттиолы с густыми махровыми цветками и тонким сладким ароматом. Идеальное дополнение к любому букету или самостоятельный акцент.',
    ],
    [
        'name'        => 'Гвоздика красная',
        'price'       => 80,
        'old_price'   => null,
        'image'       => 'img/photo_9_2026-04-19_17-13-12.jpg',
        'category'    => 'гвоздики',
        'description' => 'Яркая тёмно-красная гвоздика — страстная и выразительная. Бархатные резные лепестки и стойкость: стоит до двух недель в воде.',
    ],
    [
        'name'        => 'Гвоздика белая',
        'price'       => 80,
        'old_price'   => null,
        'image'       => 'img/photo_10_2026-04-19_17-13-12.jpg',
        'category'    => 'гвоздики',
        'description' => 'Изящная белая гвоздика с пышными кружевными лепестками. Символ чистоты и искренности — универсальный цветок для любого случая.',
    ],
    [
        'name'        => 'Роза кустовая пионовидная',
        'price'       => 220,
        'old_price'   => null,
        'image'       => 'img/photo_11_2026-04-19_17-13-12.jpg',
        'category'    => 'розы',
        'description' => 'Нежно-розовая кустовая роза пионовидной формы. На одной ветке несколько роскошных многолепестковых бутонов — премиальный выбор для особого подарка.',
    ],
    [
        'name'        => 'Роза кустовая кремовая',
        'price'       => 200,
        'old_price'   => null,
        'image'       => 'img/photo_12_2026-04-19_17-13-12.jpg',
        'category'    => 'розы',
        'description' => 'Кустовая роза с кремово-белыми бутонами на разных стадиях раскрытия. Изысканная и элегантная ветка — отличная основа для составления букета.',
    ],
    [
        'name'        => 'Фрезия белая',
        'price'       => 120,
        'old_price'   => null,
        'image'       => 'img/photo_13_2026-04-19_17-13-12.jpg',
        'category'    => 'другое',
        'description' => 'Нежный стебель белой фрезии с раскрытыми цветками и бутонами. Тонкий сладкий аромат и хрупкая красота — незаменимое дополнение к весенним букетам.',
    ],
    [
        'name'        => 'Хризантема кустовая махровая',
        'price'       => 190,
        'old_price'   => null,
        'image'       => 'img/photo_14_2026-04-19_17-13-12.jpg',
        'category'    => 'хризантемы',
        'description' => 'Кустовая хризантема с пышными белыми махровыми цветками. Пышная и долгоживущая — одна ветка смотрится как маленький букет.',
    ],
];

// Открытки — stem_product=false
$cards = [
    [
        'name'        => 'Открытка «Счастья!»',
        'price'       => 150,
        'old_price'   => null,
        'image'       => 'img/photo_15_2026-04-19_17-13-12.jpg',
        'category'    => 'открытки',
        'description' => 'Стильная открытка с акварельным принтом из зелёных листьев и надписью «Счастья!». Квадратный формат, плотная бумага — идеальное дополнение к букету.',
    ],
    [
        'name'        => 'Открытка «От всего сердца!»',
        'price'       => 150,
        'old_price'   => null,
        'image'       => 'img/photo_16_2026-04-19_17-13-12.jpg',
        'category'    => 'открытки',
        'description' => 'Нежная открытка с акварельным цветком и ветками на белом фоне, надпись «От всего сердца!». Подходит для любого подарка — передайте самые тёплые чувства.',
    ],
    [
        'name'        => 'Открытка «С Днём рождения!»',
        'price'       => 150,
        'old_price'   => null,
        'image'       => 'img/photo_17_2026-04-19_17-13-12.jpg',
        'category'    => 'открытки',
        'description' => 'Лаконичная открытка в серо-голубых тонах с акварельными листьями и надписью «С Днём рождения!». Минималистичный дизайн для тех, кто ценит стиль.',
    ],
    [
        'name'        => 'Открытка «Будь самой счастливой!»',
        'price'       => 150,
        'old_price'   => null,
        'image'       => 'img/photo_18_2026-04-19_17-13-12.jpg',
        'category'    => 'открытки',
        'description' => 'Романтичная открытка в стиле поляроид с фиолетовой хризантемой и надписью «Будь самой счастливой!». Тёплый и душевный подарок для близкого человека.',
    ],
];

$pdo->exec('ALTER TABLE products ADD COLUMN IF NOT EXISTS stem_product BOOLEAN NOT NULL DEFAULT FALSE');

$ins_stem = $pdo->prepare('INSERT INTO products (name, price, old_price, image, description, category, is_active, stem_product, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, TRUE, CURRENT_TIMESTAMP)');
$ins_card = $pdo->prepare('INSERT INTO products (name, price, old_price, image, description, category, is_active, stem_product, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, FALSE, CURRENT_TIMESTAMP)');

$added_stems = 0;
$added_cards = 0;
$errors = [];

foreach ($stems as $p) {
    try {
        $ins_stem->execute([$p['name'], $p['price'], $p['old_price'], $p['image'], $p['description'], $p['category']]);
        $added_stems++;
    } catch (Throwable $e) {
        $errors[] = $p['name'] . ': ' . $e->getMessage();
    }
}

foreach ($cards as $p) {
    try {
        $ins_card->execute([$p['name'], $p['price'], $p['old_price'], $p['image'], $p['description'], $p['category']]);
        $added_cards++;
    } catch (Throwable $e) {
        $errors[] = $p['name'] . ': ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>Импорт цветов и открыток</title>
<style>body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:0 20px}
.ok{color:#166534;background:#dcfce7;border:1px solid #bbf7d0;padding:14px 18px;border-radius:12px;margin-bottom:12px}
.err{color:#991b1b;background:#fee2e2;border:1px solid #fecaca;padding:14px 18px;border-radius:12px;margin-bottom:12px}
ul{margin:10px 0 0;padding-left:20px}</style>
</head>
<body>
<h2>Импорт одиночных цветов и открыток</h2>
<?php if ($added_stems > 0): ?>
  <div class="ok">Одиночных цветов добавлено: <strong><?= $added_stems ?></strong></div>
<?php endif; ?>
<?php if ($added_cards > 0): ?>
  <div class="ok">Открыток добавлено: <strong><?= $added_cards ?></strong></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="err">Ошибки:<ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<p><strong>Готово! Теперь удалите этот файл с сервера.</strong></p>
<p><a href="admin.php">Перейти в админку</a> &nbsp; <a href="catalog.php">Каталог</a></p>
</body>
</html>
