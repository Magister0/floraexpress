<?php
declare(strict_types=1);
require_once __DIR__ . '/telegram_config.php';

$token      = FLORA_BOT_TOKEN;
$secret     = FLORA_WEBHOOK_SECRET;
$protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$webhookUrl = $protocol . '://' . $host . '/telegram_bot.php';

$apiUrl = 'https://api.telegram.org/bot' . $token . '/setWebhook';

$result   = null;
$curlError = null;

if (function_exists('curl_init')) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'url'             => $webhookUrl,
            'secret_token'    => $secret,
            'max_connections' => 10,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'FloraExpress/1.0',
    ]);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response) {
        $result = json_decode($response, true);
    }
} else {
    $curlError = 'cURL не установлен на сервере';
}

$ok = !empty($result['ok']);

// Диагностика
$diag = [
    'curl_available'    => function_exists('curl_init'),
    'allow_url_fopen'   => ini_get('allow_url_fopen'),
    'webhook_url'       => $webhookUrl,
    'token_prefix'      => substr($token, 0, 10) . '...',
    'php_version'       => PHP_VERSION,
    'curl_error'        => $curlError ?: null,
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Webhook Setup — FloraExpress</title>
  <style>
    body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; }
    .ok  { background: #ecfdf3; border: 1px solid #6ee7b7; color: #065f46; padding: 16px; border-radius: 10px; }
    .err { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; padding: 16px; border-radius: 10px; }
    pre  { background: #f1f5f9; padding: 12px; border-radius: 8px; overflow: auto; font-size: 13px; }
    .warn { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; padding: 12px; border-radius: 8px; margin-top: 16px; }
    table { border-collapse: collapse; width: 100%; margin-top: 8px; }
    td, th { border: 1px solid #e2e8f0; padding: 7px 10px; font-size: 13px; text-align: left; }
    th { background: #f8fafc; }
  </style>
</head>
<body>
  <h1>Регистрация Telegram Webhook</h1>

  <?php if ($ok): ?>
    <div class="ok">
      <b>Webhook успешно зарегистрирован!</b><br><br>
      Бот работает автоматически — никакой консоли запускать не нужно.<br>
      После проверки удалите этот файл с сервера.
    </div>
  <?php else: ?>
    <div class="err">
      <b>Ошибка регистрации webhook.</b>
      <?php if ($curlError): ?>
        <br><br>Ошибка cURL: <code><?= htmlspecialchars($curlError) ?></code>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h3>Ответ Telegram API:</h3>
  <pre><?= htmlspecialchars(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>

  <h3>Диагностика сервера:</h3>
  <table>
    <tr><th>Параметр</th><th>Значение</th></tr>
    <?php foreach ($diag as $k => $v): ?>
      <tr>
        <td><?= htmlspecialchars($k) ?></td>
        <td><?= htmlspecialchars((string)($v ?? 'null')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="warn">
    Удалите <code>webhook_setup.php</code> после успешной регистрации.
  </div>
</body>
</html>
