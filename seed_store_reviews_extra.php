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

$reviews = [
    ['name' => 'Карина',    'rating' => 5, 'days_ago' => 0,  'text' => 'Заказала букет на годовщину родителям. Доставили точно к указанному времени, мама была очень тронута. Цветы свежие, открытка добавлена красивая. Спасибо!'],
    ['name' => 'Глеб',      'rating' => 5, 'days_ago' => 1,  'text' => 'Брал большой букет роз девушке на день рождения. Сделали с запасом, доставили чуть раньше слота — это плюс. Букет стоит уже неделю и выглядит свежо.'],
    ['name' => 'Лариса',    'rating' => 4, 'days_ago' => 2,  'text' => 'Хорошо, что можно указать время доставки точно. Курьер позвонил за 15 минут, всё аккуратно передал. Снизила звезду только из-за того, что одна роза была чуть мятой.'],
    ['name' => 'Руслан',    'rating' => 5, 'days_ago' => 3,  'text' => 'Брали корпоративно на 8 марта, 12 букетов в один день, развезли по адресам без задержек. Отдельное спасибо менеджеру за то, что быстро решил вопрос с упаковкой.'],
    ['name' => 'Виолетта',  'rating' => 5, 'days_ago' => 4,  'text' => 'Очень понравились пионы — выглядят как на фото, не пересушенные. Брала самовывозом, к указанному времени всё было уже готово, не пришлось ждать.'],
    ['name' => 'Тимур',     'rating' => 5, 'days_ago' => 5,  'text' => 'Удобный сайт, оформление заказа занимает буквально пару минут. Оплата картой прошла без проблем, в смс пришла ссылка для отслеживания — это реально удобно.'],
    ['name' => 'Зинаида',   'rating' => 4, 'days_ago' => 6,  'text' => 'В целом всё устроило: букет красивый, доставка вовремя. Хотелось бы видеть больше композиций в стиле «лесной букет», но это скорее пожелание, чем минус.'],
    ['name' => 'Владислав', 'rating' => 5, 'days_ago' => 7,  'text' => 'Заказал в последний момент, на следующее утро уже доставили. Курьер вежливый, не торопил при получении. Букет очень понравился жене, спасибо за работу.'],
    ['name' => 'Альбина',   'rating' => 5, 'days_ago' => 8,  'text' => 'Брала впервые, немного переживала за качество. Получился очень нежный букет, даже лучше, чем ожидала по фотографиям на сайте. Цена адекватная, вернусь снова.'],
    ['name' => 'Эльдар',    'rating' => 5, 'days_ago' => 9,  'text' => 'Спасибо за оперативность. Заказал утром, к обеду букет уже был у получателя в офисе. Открытку оформили аккуратно, без опечаток. Отличный сервис.'],
];

$created = 0;
$updated = 0;

$findUser   = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
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
    $name = (string)$item['name'];
    $email = sprintf('demo.extra.%02d@flora.local', $idx);

    $findUser->execute([$email]);
    $userId = (int)($findUser->fetchColumn() ?: 0);
    if ($userId <= 0) {
        $insertUser->execute([$email, $name, password_hash('demo12345', PASSWORD_DEFAULT), 'user']);
        $userId = (int)$insertUser->fetchColumn();
        $created++;
    }

    $days = (int)$item['days_ago'];
    $upsertReview->execute([$userId, (int)$item['rating'], (string)$item['text'], $days, $days]);
    $updated++;
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM store_reviews')->fetchColumn();
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Дополнительные отзывы добавлены</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f7f7f8;color:#111827;padding:40px;line-height:1.6}
    .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
    h1{margin:0 0 12px;font-size:32px}
    .ok{display:inline-block;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:10px 14px;border-radius:999px;font-weight:700;margin-bottom:16px}
    .actions a{display:inline-block;margin-right:12px;padding:12px 16px;border-radius:12px;text-decoration:none;border:1px solid #d1d5db;color:#111827}
    .actions a.primary{background:#175a37;color:#fff;border-color:#175a37}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <div class="card">
    <div class="ok">Готово</div>
    <h1>Добавлено 10 отзывов</h1>
    <p>Создано или обновлено <strong><?= $updated ?></strong> отзывов. Новых демо-пользователей: <strong><?= $created ?></strong>.</p>
    <p>Всего отзывов о магазине сейчас: <strong><?= $total ?></strong>.</p>
    <p>Эти пользователи имеют email вида <code>demo.extra.01@flora.local</code> и пароль <code>demo12345</code>. Чтобы удалить позже — можно расширить <code>cleanup_demo_store_reviews.php</code> на префикс <code>demo.extra.</code>.</p>
    <div class="actions">
      <a class="primary" href="index.php#store-reviews">Открыть главную и посмотреть отзывы</a>
    </div>
  </div>
</body>
</html>
