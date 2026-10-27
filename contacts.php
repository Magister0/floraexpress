<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$me = $_SESSION['user'] ?? null;
$userName = (string)($me['name'] ?? '');
$userPhone = (string)($me['phone'] ?? '');
$userEmail = (string)($me['email'] ?? '');

if ($me) {
  try {
    $pdo = db();
    $st = $pdo->prepare('SELECT name, phone, email FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)($me['id'] ?? 0)]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $userName = trim((string)($row['name'] ?? $userName));
      $userPhone = trim((string)($row['phone'] ?? $userPhone));
      $userEmail = trim((string)($row['email'] ?? $userEmail));
    }
  } catch (Throwable $e) {}
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Контакты — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .contact-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:20px}
    .card-box{background:#fff;border:1px solid #eef2f7;border-radius:16px;box-shadow:var(--shadow-1);padding:16px}
    .map iframe{width:100%;height:330px;border:0;border-radius:14px}
    @media (max-width:900px){ .contact-grid{grid-template-columns:1fr} }
  </style>
</head>
<body>

<?php include __DIR__.'/header.php'; ?>

<section class="section">
  <div class="container">
    <h1 class="section__title">Контакты</h1>
    <div class="contact-grid">
      <div class="card-box">
        <h3>Как с нами связаться</h3>
        <p class="mute">Мы отвечаем быстро каждый день с 9:00 до 21:00.</p>
        <div style="display:grid;gap:8px;margin-top:10px">
          <div><b>Телефон:</b> <a href="tel:+77001234567">+7 700 123-45-67</a></div>
          <div><b>Email:</b> <a href="mailto:hello@flora.local">hello@flora.local</a></div>
          <div><b>Telegram:</b> <a target="_blank" rel="noopener" href="https://t.me/your_shop">@your_shop</a></div>
          <div><b>Адрес:</b> г. Екатеринбург, проспект Ленина, 24/8 (у площади 1905 года)</div>
          <div><b>Время работы:</b> ежедневно 9:00–21:00</div>
        </div>

        <hr style="border:none;border-top:1px solid #f1f5f9;margin:16px 0">
        <h3>Обратная связь</h3>
        <form id="contactForm" style="display:grid;gap:10px;margin-top:8px">
          <input class="input" name="name" placeholder="Ваше имя *" required value="<?= h($userName) ?>">
          <input class="input" name="phone" placeholder="Телефон *" required value="<?= h($userPhone) ?>" data-phone-mask inputmode="tel">
          <input class="input" name="email" type="email" placeholder="Email (по желанию)" value="<?= h($userEmail) ?>">
          <textarea class="input" name="message" rows="4" placeholder="Сообщение *" required></textarea>
          <button class="btn" type="submit">Отправить</button>
          <div id="contactNote" class="mute"></div>
        </form>
      </div>

      <div class="card-box map">
        <h3>Мы на карте</h3>
        <iframe src="https://yandex.ru/map-widget/v1/?mode=search&text=Екатеринбург%20проспект%20Ленина%2024&ll=60.6051,56.8389&z=16"></iframe>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__.'/footer.php'; ?>

<script>
document.getElementById('contactForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const form = e.target;
  const note = document.getElementById('contactNote');
  note.textContent = 'Отправляем...';
  try{
    const res = await fetch('contact_api.php', { method:'POST', body: new FormData(form) });
    const data = await res.json();
    if(data.ok){
      note.textContent = 'Спасибо! Мы свяжемся с вами.';
      const message = form.querySelector('textarea[name="message"]');
      if (message) message.value = '';
    }
    else{ note.textContent = 'Ошибка: '+(data.error||''); }
  }catch(err){ note.textContent = 'Сеть недоступна'; }
});
</script>
</body>
</html>
