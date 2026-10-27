<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS store_reviews (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL UNIQUE,
    rating SMALLINT NOT NULL,
    review_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT store_reviews_rating_chk CHECK (rating BETWEEN 1 AND 5)
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_store_reviews_created_at ON store_reviews (created_at DESC)");

$names = [
    'Анна', 'Мария', 'Екатерина', 'Ольга', 'Ирина', 'Алексей', 'Дмитрий', 'Наталья', 'Светлана', 'Юлия',
    'Ксения', 'Елена', 'Татьяна', 'Виктория', 'Анастасия', 'Павел', 'Михаил', 'Сергей', 'Дарья', 'Валерия',
    'Полина', 'Алина', 'Никита', 'Кирилл', 'Артём'
];

$reviews = [
    ['rating' => 5, 'text' => 'Очень понравился сервис. Букет собрали аккуратно, доставили вовремя, курьер заранее позвонил.'],
    ['rating' => 5, 'text' => 'Заказывали букет маме на день рождения. Всё пришло свежее, оформление красивое, впечатление отличное.'],
    ['rating' => 4, 'text' => 'В целом всё хорошо. Немного переживали из-за времени доставки, но заказ привезли в нужный интервал.'],
    ['rating' => 5, 'text' => 'Хороший магазин. Удобно оформить заказ, менеджер быстро связался и всё уточнил.'],
    ['rating' => 5, 'text' => 'Очень вежливое общение и красивый букет. Получатель остался доволен, будем заказывать ещё.'],
    ['rating' => 4, 'text' => 'Понравилось, что перед оформлением всё понятно: цена, доставка, комментарий. Сервис удобный.'],
    ['rating' => 5, 'text' => 'Спасибо за аккуратную упаковку и быструю доставку. Цветы стояли долго, выглядели свежо.'],
    ['rating' => 5, 'text' => 'Заказывал впервые и остался доволен. Сайт удобный, всё прошло без проблем.'],
    ['rating' => 4, 'text' => 'Нормальный магазин, менеджер помог с заменой букета на похожий вариант. Всё согласовали заранее.'],
    ['rating' => 5, 'text' => 'Очень красиво собрали композицию. Фото и реальный букет выглядели почти одинаково.'],
    ['rating' => 5, 'text' => 'Брали букет с самовывозом. Всё было готово вовремя, персонал вежливый.'],
    ['rating' => 5, 'text' => 'Порадовало, что можно добавить доп. услуги и комментарий. Впечатление от заказа осталось хорошее.'],
    ['rating' => 4, 'text' => 'Небольшая задержка по времени, но в остальном всё отлично. Цветы свежие, оформление приятное.'],
    ['rating' => 5, 'text' => 'Заказывали в подарок коллеге. Сервис понравился, оформление простое и понятное.'],
    ['rating' => 5, 'text' => 'Понравилось, что магазин быстро отвечает и уточняет детали. Чувствуется нормальный сервис.'],
    ['rating' => 4, 'text' => 'Заказ оформили без проблем. Хотелось бы чуть больше фото букетов, но в целом всё хорошо.'],
    ['rating' => 5, 'text' => 'Удобная корзина, понятная оплата, доставка без сюрпризов. Всё как и ожидали.'],
    ['rating' => 5, 'text' => 'Букет выглядел красиво и свежо, получатель был очень рад. Спасибо за качественную работу.'],
    ['rating' => 5, 'text' => 'Хороший магазин для заказа цветов онлайн. Уже не первый раз всё проходит спокойно.'],
    ['rating' => 4, 'text' => 'Промокод сработал, заказ оформили быстро. Сервисом довольны.'],
    ['rating' => 5, 'text' => 'Спасибо менеджеру за помощь с выбором и заменой упаковки. Получилось очень красиво.'],
    ['rating' => 5, 'text' => 'Приятный сайт и понятный процесс оформления. Видно, что магазин старается сделать удобно.'],
    ['rating' => 4, 'text' => 'Заказ пришёл вовремя, курьер вежливый. В целом всё понравилось.'],
    ['rating' => 5, 'text' => 'Сервис произвёл хорошее впечатление. Всё чётко, без лишней суеты и с нормальной связью по заказу.'],
    ['rating' => 5, 'text' => 'Отличный опыт заказа. Букет получился красивый, а оформление заказа заняло всего пару минут.'],
];

$created = 0;
$updated = 0;

$findUser = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$insertUser = $pdo->prepare('INSERT INTO users (email, name, pass_hash, role, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id');
$upsertReview = $pdo->prepare("INSERT INTO store_reviews (user_id, rating, review_text, created_at, updated_at)
    VALUES (?, ?, ?, NOW() - (? || ' days')::interval, NOW() - (? || ' days')::interval)
    ON CONFLICT (user_id)
    DO UPDATE SET rating = EXCLUDED.rating,
                  review_text = EXCLUDED.review_text,
                  updated_at = EXCLUDED.updated_at
");

foreach ($reviews as $i => $item) {
    $idx = $i + 1;
    $name = $names[$i] ?? ('Покупатель ' . $idx);
    $email = sprintf('demo.review.%02d@flora.local', $idx);

    $findUser->execute([$email]);
    $userId = (int)($findUser->fetchColumn() ?: 0);
    if ($userId <= 0) {
        $insertUser->execute([$email, $name, password_hash('demo12345', PASSWORD_DEFAULT), 'user']);
        $userId = (int)$insertUser->fetchColumn();
        $created++;
    }

    $upsertReview->execute([$userId, (int)$item['rating'], (string)$item['text'], 26 - $idx, 26 - $idx]);
    $updated++;
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM store_reviews')->fetchColumn();
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Демо-отзывы добавлены</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f7f7f8;color:#111827;padding:40px;line-height:1.6}
    .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
    h1{margin:0 0 12px;font-size:32px}
    .ok{display:inline-block;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:10px 14px;border-radius:999px;font-weight:700;margin-bottom:16px}
    .actions a{display:inline-block;margin-right:12px;padding:12px 16px;border-radius:12px;text-decoration:none;border:1px solid #d1d5db;color:#111827}
    .actions a.primary{background:#e11d48;color:#fff;border-color:#e11d48}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <div class="card">
    <div class="ok">Готово</div>
    <h1>Демо-отзывы добавлены</h1>
    <p>Создано или обновлено <strong><?= $updated ?></strong> отзывов. Новых демо-пользователей: <strong><?= $created ?></strong>.</p>
    <p>Всего отзывов о магазине сейчас: <strong><?= $total ?></strong>.</p>
    <p>Для теста созданы пользователи с адресами вида <code>demo.review.01@flora.local</code>. Пароль у всех одинаковый: <code>demo12345</code>.</p>
    <div class="actions">
      <a class="primary" href="index.php#store-reviews">Открыть главную и посмотреть отзывы</a>
      <a href="auth.php?mode=login">Открыть вход</a>
    </div>
  </div>
</body>
</html>
