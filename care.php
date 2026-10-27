<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/cart_store.php')) {
    require_once __DIR__ . '/cart_store.php';
    cart_store_sync();
}

function care_svg(string $title, string $sub, string $bg1, string $bg2, string $ink = '#22434f'): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="620">'
        . '<defs>'
        . '<linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="' . $bg1 . '"/><stop offset="1" stop-color="' . $bg2 . '"/></linearGradient>'
        . '</defs>'
        . '<rect width="100%" height="100%" rx="42" fill="url(#g)"/>'
        . '<circle cx="150" cy="145" r="92" fill="rgba(255,255,255,0.22)"/>'
        . '<circle cx="730" cy="145" r="88" fill="rgba(255,255,255,0.18)"/>'
        . '<circle cx="300" cy="470" r="66" fill="rgba(255,255,255,0.12)"/>'
        . '<circle cx="630" cy="470" r="58" fill="rgba(255,255,255,0.10)"/>'
        . '<rect x="135" y="205" width="630" height="188" rx="34" fill="rgba(255,255,255,0.42)"/>'
        . '<text x="450" y="285" text-anchor="middle" font-size="64" font-family="Trebuchet MS, Arial, sans-serif" font-weight="600" fill="' . htmlspecialchars($ink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</text>'
        . '<text x="450" y="335" text-anchor="middle" font-size="28" font-family="Trebuchet MS, Arial, sans-serif" font-weight="500" fill="' . htmlspecialchars($ink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" opacity="0.82">' . htmlspecialchars($sub, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</text>'
        . '</svg>'
    );
}

$cards = [
    [
        'title' => 'Обновите срез сразу после распаковки',
        'text' => 'Перед тем как поставить букет в воду, аккуратно подрежьте стебли на 1–2 см под углом. Так цветы быстрее напитаются водой и будут дольше выглядеть свежими.',
        'img' => care_svg('Свежий срез', 'обновите стебли на 1–2 см', '#dff4ee', '#dcecff', '#235a63'),
    ],
    [
        'title' => 'Наливайте чистую прохладную воду',
        'text' => 'Лучше менять воду каждый день, а вазу быстро ополаскивать. Листья, которые могут оказаться в воде, желательно убрать сразу, чтобы букет дольше оставался аккуратным.',
        'img' => care_svg('Чистая вода', 'меняйте воду ежедневно', '#fdf0e2', '#fff4df', '#755434'),
    ],
    [
        'title' => 'Держите букет подальше от жары',
        'text' => 'Прямое солнце, батареи, горячий воздух и кухня рядом с плитой заметно сокращают свежесть цветов. Самое удачное место — светлая прохладная зона без перегрева.',
        'img' => care_svg('Без перегрева', 'прохлада помогает дольше', '#e4f1ff', '#dbf7f0', '#24556e'),
    ],
    [
        'title' => 'Уточняйте уход под конкретный сорт',
        'text' => 'У роз, тюльпанов, хризантем и сборных букетов есть свои нюансы по воде, температуре и срезу. Поэтому точную рекомендацию по вашему составу лучше уточнить у продавцов FloraExpress.',
        'img' => care_svg('Разные цветы', 'у каждого букета свои нюансы', '#f6ecf8', '#eef2ff', '#594568'),
    ],
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Уход за цветами — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .care-hero{background:#fff;border:1px solid #eef2f7;border-radius:22px;box-shadow:var(--shadow-1);padding:24px}
    .care-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:18px;margin-top:18px}
    .care-card{background:#fff;border:1px solid #eef2f7;border-radius:22px;box-shadow:var(--shadow-1);overflow:hidden}
    .care-card img{width:100%;aspect-ratio:4/3;object-fit:cover;border-bottom:1px solid #eef2f7;background:#f8fafc;display:block}
    .care-card__body{padding:18px}
    .care-card__body h3{margin:0 0 10px;font-size:21px;line-height:1.35}
    .care-card__body p{margin:0;color:#596275;line-height:1.75;font-size:15px}
    .care-note{margin-top:18px;padding:18px;border-radius:18px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.65}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <div class="care-hero">
      <h1 class="section__title" style="margin:0 0 8px;">Уход за цветами</h1>
      <p class="section__sub">Небольшая памятка по базовому уходу, чтобы букет дольше сохранял свежесть, форму и красивый вид дома.</p>
      <div style="margin-top:14px"><a class="btn btn--ghost btn--sm" href="catalog.php">Вернуться в каталог</a></div>
    </div>

    <div class="care-grid">
      <?php foreach ($cards as $card): ?>
        <article class="care-card">
          <img src="<?= htmlspecialchars($card['img'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($card['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div class="care-card__body">
            <h3><?= htmlspecialchars($card['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars($card['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="care-note">
      <strong>Важно:</strong> у разных цветов уход отличается. Для роз, тюльпанов, хризантем, сезонных и сборных букетов рекомендации могут немного меняться, поэтому точные детали лучше уточнять у продавцов FloraExpress лично.
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
