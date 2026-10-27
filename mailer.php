<?php
declare(strict_types=1);

/*
 * Простая отправка через SMTP (Яндекс.Почта) без внешних библиотек.
 * Настройки берутся из config.local.php или переменных окружения:
 *   define('_SMTP_USER', 'shop@example.ru');
 *   define('_SMTP_PASS', 'пароль приложения');
 */

if (!defined('SMTP_USER')) {
    define('SMTP_USER', getenv('SMTP_USER') ?: (defined('_SMTP_USER') ? _SMTP_USER : ''));
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: (defined('_SMTP_PASS') ? _SMTP_PASS : ''));
}
if (!defined('SMTP_FROM')) {
    define('SMTP_FROM', getenv('SMTP_FROM') ?: (defined('_SMTP_FROM') ? _SMTP_FROM : SMTP_USER));
}
if (!defined('SMTP_HELO_DOMAIN')) {
    define('SMTP_HELO_DOMAIN', getenv('SMTP_HELO_DOMAIN') ?: (defined('_SMTP_HELO_DOMAIN') ? _SMTP_HELO_DOMAIN : 'localhost'));
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'FloraExpress');
}

function smtp_send(string $to, string $subject, string $body): bool
{
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $from     = SMTP_FROM ?: $user;
    $fromName = SMTP_FROM_NAME;

    if ($user === '' || $pass === '') {
        return false;
    }

    $host    = 'smtp.yandex.ru';
    $port    = 587;
    $timeout = 15;

    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if (!$socket) return false;

    stream_set_timeout($socket, $timeout);

    $read = function() use ($socket): string {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if ($line[3] === ' ') break;
        }
        return $data;
    };

    $send = function(string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $read(); // 220 welcome

    $send('EHLO ' . SMTP_HELO_DOMAIN);
    $read();

    $send('STARTTLS');
    $read();

    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);

    $send('EHLO ' . SMTP_HELO_DOMAIN);
    $read();

    $send('AUTH LOGIN');
    $read();

    $send(base64_encode($user));
    $read();

    $send(base64_encode($pass));
    $resp = $read();
    if (strpos($resp, '235') === false) {
        fclose($socket);
        return false;
    }

    $send("MAIL FROM:<{$from}>");
    $read();

    $send("RCPT TO:<{$to}>");
    $read();

    $send('DATA');
    $read();

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $date           = date('r');
    $messageId      = '<' . uniqid('flora', true) . '@' . SMTP_HELO_DOMAIN . '>';

    $message = "Date: {$date}\r\n"
             . "From: {$encodedFrom} <{$from}>\r\n"
             . "To: <{$to}>\r\n"
             . "Subject: {$encodedSubject}\r\n"
             . "Message-ID: {$messageId}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n"
             . "\r\n"
             . chunk_split(base64_encode($body))
             . "\r\n.\r\n";

    $send($message);
    $resp = $read();

    $send('QUIT');
    fclose($socket);

    return strpos($resp, '250') !== false;
}
