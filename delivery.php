<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Доставка — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .hero-mini{
      border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);
      padding:18px;background:
        radial-gradient(700px 300px at 10% -20%, #fffbeb 0%, rgba(255,251,235,0) 60%),
        radial-gradient(600px 250px at 100% 0%, #ecfeff 0%, rgba(236,254,255,0) 55%),
        #fff;
    }
    .i-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:14px}
    .i-card{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:18px}
    .i-title{font-size:18px;font-weight:800;margin:0 0 8px}
    .li{margin:6px 0}
    @media (max-width:900px){ .i-grid{grid-template-columns:1fr} }
  </style>
</head>
<body>

<?php include __DIR__.'/header.php'; ?>

<section class="section">
  <div class="container hero-mini">
    <h1 class="section__title" style="margin:0">Доставка</h1>
    <p class="section__sub" style="margin:4px 0 0">Екатеринбург: ежедневно 10:00–21:00. Обычно доставляем в пределах 2 часов после подтверждения заказа.</p>
  </div>

  <div class="container i-grid">
    <div class="i-card">
      <div class="i-title">⏱️ Сроки доставки</div>
      <ul>
        <li class="li">Доставка работает ежедневно с 10:00 до 21:00</li>
        <li class="li">Привозим заказ в пределах 2 часов после подтверждения</li>
        <li class="li">Если нужен особый интервал, менеджер согласует его отдельно</li>
      </ul>
    </div>

    <div class="i-card">
      <div class="i-title">🚚 Стоимость</div>
      <ul>
        <li class="li">В пределах города — <b>от 990 ₽</b></li>
        <li class="li">Бесплатно от <b>7 000 ₽</b> заказа</li>
        <li class="li">Пригород — по тарифу за км</li>
      </ul>
    </div>

    <div class="i-card">
      <div class="i-title">🎁 Получатель</div>
      <ul>
        <li class="li">Позвоним или напишем перед доставкой</li>
        <li class="li">Можем не раскрывать отправителя</li>
        <li class="li">После вручения можно запросить подтверждение</li>
      </ul>
    </div>

    <div class="i-card">
      <div class="i-title">📦 Самовывоз</div>
      <ul>
        <li class="li">г. Екатеринбург, проспект Ленина, 24/8 (у площади 1905 года)</li>
        <li class="li">Готовность заказа сообщим по SMS или в Telegram</li>
      </ul>
    </div>
  </div>
</section>

<?php include __DIR__.'/footer.php'; ?>
</body>
</html>
