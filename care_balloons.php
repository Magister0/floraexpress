<?php
declare(strict_types=1);

function balloon_svg(string $title, string $sub, string $bg1, string $bg2, string $ink = '#1a3a5c'): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="620">'
        . '<defs>'
        . '<linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="' . $bg1 . '"/><stop offset="1" stop-color="' . $bg2 . '"/></linearGradient>'
        . '</defs>'
        . '<rect width="100%" height="100%" rx="42" fill="url(#g)"/>'
        . '<circle cx="140" cy="140" r="88" fill="rgba(255,255,255,0.20)"/>'
        . '<circle cx="720" cy="150" r="80" fill="rgba(255,255,255,0.16)"/>'
        . '<circle cx="310" cy="460" r="62" fill="rgba(255,255,255,0.12)"/>'
        . '<circle cx="620" cy="480" r="54" fill="rgba(255,255,255,0.10)"/>'
        . '<rect x="135" y="205" width="630" height="188" rx="34" fill="rgba(255,255,255,0.42)"/>'
        . '<text x="450" y="285" text-anchor="middle" font-size="58" font-family="Trebuchet MS, Arial, sans-serif" font-weight="600" fill="' . htmlspecialchars($ink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</text>'
        . '<text x="450" y="335" text-anchor="middle" font-size="26" font-family="Trebuchet MS, Arial, sans-serif" font-weight="500" fill="' . htmlspecialchars($ink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" opacity="0.82">' . htmlspecialchars($sub, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</text>'
        . '</svg>'
    );
}

$cards = [
    [
        'title' => 'Держите подальше от острых предметов',
        'text'  => 'Шарики боятся любого контакта с острыми краями: гвоздями в стенах, украшениями, ногтями, ключами. Разместите их в просторном месте, где нет риска зацепиться.',
        'img'   => balloon_svg('Без острых краёв', 'держите в безопасном месте', '#e0eeff', '#d6f4ff', '#1a3a6e'),
    ],
    [
        'title' => 'Комфортная температура',
        'text'  => 'Холод заставляет гелий сжиматься — шарики выглядят спущенными, но при возвращении в тепло восстанавливаются. Прямое солнце, наоборот, перегревает гелий и может разорвать шарик. Идеальная температура: 18–22 °C.',
        'img'   => balloon_svg('18–22 °C', 'не на холод и не под прямой солнечный свет', '#fff4e0', '#ffefd6', '#7a4a10'),
    ],
    [
        'title' => 'Сколько держится гелий',
        'text'  => 'Латексные шарики держат гелий 12–24 часа. Фольгированные круглые и фигурные — 3–7 дней. Шары-цифры и буквы могут держаться до 2 недель. Время зависит от температуры и качества завязки.',
        'img'   => balloon_svg('Срок службы', 'латекс — 1 день, фольга — до 2 недель', '#edfff5', '#e0fbe8', '#14553a'),
    ],
    [
        'title' => 'Транспортировка и хранение',
        'text'  => 'При перевозке в автомобиле избегайте нагрева от лобового стекла и сквозняков. Завяжите шарики к грузику или мебели, чтобы они не улетели. Не храните рядом с работающим вентилятором или кондиционером.',
        'img'   => balloon_svg('Хранение', 'закрепите и защитите от сквозняков', '#f5e8ff', '#eee0ff', '#4a1a7a'),
    ],
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Уход за шарами — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .care-hero{background:#fff;border:1px solid #eef2f7;border-radius:22px;box-shadow:var(--shadow-1);padding:24px}
    .care-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:18px;margin-top:18px}
    .care-card{background:#fff;border:1px solid #eef2f7;border-radius:22px;box-shadow:var(--shadow-1);overflow:hidden}
    .care-card img{width:100%;aspect-ratio:4/3;object-fit:cover;border-bottom:1px solid #eef2f7;background:#f8fafc;display:block}
    .care-card__body{padding:18px}
    .care-card__body h3{margin:0 0 10px;font-size:21px;line-height:1.35}
    .care-card__body p{margin:0;color:#596275;line-height:1.75;font-size:15px}
    .care-note{margin-top:18px;padding:18px;border-radius:18px;background:#eef6ff;border:1px solid #bfdbfe;color:#1e3a5f;line-height:1.65}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <div class="care-hero">
      <h1 class="section__title" style="margin:0 0 8px;">Уход за шарами</h1>
      <p class="section__sub">Простые советы, которые помогут шарикам радовать дольше — дома, на празднике или в декоре.</p>
      <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn--ghost btn--sm" href="catalog.php">Вернуться в каталог</a>
        <a class="btn btn--ghost btn--sm" href="care.php">Памятка по уходу за цветами</a>
      </div>
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
      <strong>Полезно знать:</strong> латексные шарики чувствительны к холоду и теплу сильнее, чем фольгированные. Если шарик выглядит спущенным после холода — занесите в тепло на 15–20 минут, он, скорее всего, восстановится. По любым вопросам обращайтесь к продавцам FloraExpress.
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
