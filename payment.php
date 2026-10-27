<?php
declare(strict_types=1);
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$cartCount = array_sum($_SESSION['cart']);
$me = $_SESSION['user'] ?? null;
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Оплата — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .hero-mini{border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:18px;background:
      radial-gradient(700px 300px at 100% -20%, #dcfce7 0%, rgba(220,252,231,0) 60%), #fff;}
    .p-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:14px}
    .p-card{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:18px}
    .p-title{font-size:18px;font-weight:800;margin:0 0 8px}
    .p-card p{margin:0;color:#6b7280}
    .emoji{font-size:20px;margin-right:6px}
    @media (max-width:900px){ .p-grid{grid-template-columns:1fr} }
  </style>
</head>
<body>

<?php include __DIR__.'/header.php'; ?>

<section class="section">
  <div class="container hero-mini">
    <h1 class="section__title" style="margin:0">Оплата</h1>
    <p class="section__sub" style="margin:4px 0 0">Выберите удобный способ. Чеки и закрывающие документы предоставим по запросу.</p>
  </div>

  <div class="container p-grid">
    <div class="p-card">
      <div class="p-title">💳 Картой онлайн</div>
      <p>Visa/Mastercard/МИР. Платёж защищён, данные карты не сохраняются.</p>
    </div>
    <div class="p-card">
      <div class="p-title">🧾 СБП (QR)</div>
      <p>Оплата по QR через Систему быстрых платежей. Комиссии для клиента нет.</p>
    </div>
    <div class="p-card">
      <div class="p-title">🤝 Наличными/картой курьеру</div>
      <p>Оплата при получении. Электронный чек по SMS/Email.</p>
    </div>
    <div class="p-card">
      <div class="p-title">🏢 Счёт для юрлиц</div>
      <p>Выставим счёт и акт. Договор-оферта, закрывающие — по запросу в <a href="contacts.php">контактах</a>.</p>
    </div>
  </div>
</section>

<?php include __DIR__.'/footer.php'; ?>
</body>
</html>
