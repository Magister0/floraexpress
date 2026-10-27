<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sfx = '_2026-04-19_18-16-18.jpg';

$products = [
    // ===== ШАРИКИ =====
    [
        'name'        => 'Шар-сердце "Я тебя люблю"',
        'price'       => 290,
        'old_price'   => null,
        'category'    => 'Шарики',
        'image'       => 'img/photo_1' . $sfx,
        'description' => 'Фольгированный шар в форме сердца с надписью «Я тебя люблю». Наполнен гелием.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Букет шаров «С Днём Рождения» синий',
        'price'       => 1500,
        'old_price'   => null,
        'category'    => 'Шарики',
        'image'       => 'img/photo_2' . $sfx,
        'description' => 'Роскошный букет из шаров в синей гамме с фольгированным кругом «С Днём Рождения». Латексные и фольгированные шары на гелии.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Букет шаров белые с серебряным конфетти',
        'price'       => 1200,
        'old_price'   => null,
        'category'    => 'Шарики',
        'image'       => 'img/photo_3' . $sfx,
        'description' => 'Нежный букет из белых и серебристых шаров с конфетти. Прозрачные шары с серебряным конфетти внутри.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Шары-цифры на гелии (розовое золото)',
        'price'       => 2000,
        'old_price'   => null,
        'category'    => 'Шарики',
        'image'       => 'img/photo_4' . $sfx,
        'description' => 'Большие фольгированные цифры в цвете розового золота на гелии + декоративные шарики со звёздами и сердечками. Высота цифр ~100 см.',
        'stem_product'=> false,
    ],

    // ===== БУКЕТЫ =====
    [
        'name'        => 'Букет орхидей с эвкалиптом',
        'price'       => 3500,
        'old_price'   => null,
        'category'    => 'Букеты',
        'image'       => 'img/photo_5' . $sfx,
        'description' => 'Изысканный букет из орхидей цимбидиум фиолетового оттенка с ветками эвкалипта. Упаковка в нежно-голубую крафт-бумагу.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Сборный букет из белых роз с ромашками',
        'price'       => 2800,
        'old_price'   => null,
        'category'    => 'Букеты',
        'image'       => 'img/photo_6' . $sfx,
        'description' => 'Воздушный сборный букет: белые розы, белые гвоздики, ромашки, эвкалипт. Упаковка в бежевую крафт-бумагу.',
        'stem_product'=> false,
    ],
    [
        'name'        => '101 роза белая',
        'price'       => 8900,
        'old_price'   => null,
        'category'    => 'Букеты',
        'image'       => 'img/photo_7' . $sfx,
        'description' => 'Грандиозный букет из 101 белоснежной розы. Оформление в классическую упаковку. Идеальный подарок на значимое событие.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Сборный букет «Розовая нежность»',
        'price'       => 2500,
        'old_price'   => null,
        'category'    => 'Букеты',
        'image'       => 'img/photo_8' . $sfx,
        'description' => 'Яркий сборный букет: пионовидные розы малиновые, кустовые розы, герберы, эустома, хризантемы, эвкалипт. Упаковка в пудровую бумагу.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Корзина «Синяя лагуна»',
        'price'       => 3200,
        'old_price'   => null,
        'category'    => 'Букеты',
        'image'       => 'img/photo_12' . $sfx,
        'description' => 'Плетёная корзина с кустовыми кремовыми розами и синими ирисами и гиацинтами. Декор атласными лентами.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Корзина «Персиковый рай»',
        'price'       => 4500,
        'old_price'   => null,
        'category'    => 'Букеты',
        'image'       => 'img/photo_18' . $sfx,
        'description' => 'Роскошная корзина с пионовидными розами персикового оттенка, белой эустомой, гиперикумом и эвкалиптом. Декор атласными лентами.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Корзина из белых хризантем',
        'price'       => 2800,
        'old_price'   => null,
        'category'    => 'Букеты',
        'image'       => 'img/photo_19' . $sfx,
        'description' => 'Нежная корзина из белых кустовых хризантем с гипсофилой. Декор розовыми и мятными атласными лентами.',
        'stem_product'=> false,
    ],

    // ===== МИНИ-БУКЕТЫ =====
    [
        'name'        => 'Мини-букет из гиацинтов',
        'price'       => 490,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_9' . $sfx,
        'description' => 'Нежный мини-букет из синих гиацинтов. Упаковка в голубую бумагу. Аромат весны в маленьком букете.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Мини-букет пионовидных роз',
        'price'       => 890,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_10' . $sfx,
        'description' => 'Компактный букет из пионовидных роз персикового оттенка с ветками эвкалипта. Упаковка в кремовую бумагу.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Мини-букет в сумочке «Белый»',
        'price'       => 790,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_11' . $sfx,
        'description' => 'Нежный мини-букет в жёлтой дизайнерской сумочке: белые хризантемы, роза, гиперикум, эустома, эвкалипт.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Мини-букет в сумочке «Персиковый»',
        'price'       => 790,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_13' . $sfx,
        'description' => 'Яркий мини-букет в розовой сумочке: пионовидная персиковая роза, кустовые красные розы, белые хризантемы.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Мини-букет тюльпаны с ирисами',
        'price'       => 490,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_14' . $sfx,
        'description' => 'Весенний мини-букет: розовые тюльпаны, синие ирисы, ромашки. Упаковка в пудровую бумагу.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Мини-букет тюльпаны с гиацинтами',
        'price'       => 490,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_15' . $sfx,
        'description' => 'Нежный мини-букет: белые тюльпаны, фиолетовые гиацинты, белая эустома. Упаковка в голубую бумагу.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Мини-букет тюльпаны с нарциссами',
        'price'       => 490,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_16' . $sfx,
        'description' => 'Яркий весенний мини-букет: красные тюльпаны, жёлтые нарциссы, ромашки, солидаго. Упаковка в кремовую бумагу.',
        'stem_product'=> false,
    ],
    [
        'name'        => 'Мини-букет жёлтые тюльпаны с ирисами',
        'price'       => 490,
        'old_price'   => null,
        'category'    => 'Мини-букеты',
        'image'       => 'img/photo_17' . $sfx,
        'description' => 'Солнечный мини-букет: жёлтые тюльпаны, синие ирисы, ромашки, солидаго. Упаковка в кремовую бумагу.',
        'stem_product'=> false,
    ],
];

$stmt = $pdo->prepare("
    INSERT INTO products (name, price, old_price, category, image, description, is_active, stem_product)
    VALUES (:name, :price, :old_price, :category, :image, :description, 1, :stem_product)
");

$ok = 0;
$errors = [];

foreach ($products as $p) {
    try {
        $stmt->execute([
            ':name'         => $p['name'],
            ':price'        => $p['price'],
            ':old_price'    => $p['old_price'],
            ':category'     => $p['category'],
            ':image'        => $p['image'],
            ':description'  => $p['description'],
            ':stem_product' => $p['stem_product'] ? 'true' : 'false',
        ]);
        echo "✅ " . $p['name'] . "<br>";
        $ok++;
    } catch (Throwable $e) {
        $msg = $p['name'] . ': ' . $e->getMessage();
        $errors[] = $msg;
        echo "❌ " . $msg . "<br>";
    }
}

echo "<hr><strong>Готово: $ok из " . count($products) . "</strong>";
if ($errors) {
    echo "<br>Ошибки:<br>" . implode('<br>', array_map('htmlspecialchars', $errors));
}
