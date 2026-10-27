<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ai_advisor_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();
ai_advisor_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!AI_ADVISOR_ENABLED) {
    echo json_encode(['ok' => false, 'error' => 'disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Парсим вход: form-data ИЛИ JSON
$payload = $_POST;
if (empty($payload)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $payload = $decoded;
    }
}

$action  = trim((string)($payload['action'] ?? 'message'));
$message = trim((string)($payload['message'] ?? ''));
$session = trim((string)($payload['session'] ?? ''));

$user = $_SESSION['user'] ?? null;
$userId = isset($user['id']) ? (int)$user['id'] : null;

// Сессионный токен — храним в куке/POST, привязка к одному устройству
if ($session === '') {
    if (!empty($_COOKIE['ai_session'])) {
        $session = (string)$_COOKIE['ai_session'];
    } else {
        $session = bin2hex(random_bytes(16));
    }
}
// Устанавливаем cookie на 30 дней
setcookie('ai_session', $session, [
    'expires'  => time() + 30 * 86400,
    'path'     => '/',
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
    'httponly' => true,
]);

if ($action === 'history') {
    $chat = ai_chat_get_or_create($pdo, $session, $userId);
    $history = ai_chat_history($pdo, (int)$chat['id'], 30);

    // Подтянем продукты для всех сообщений ассистента, где есть recommended_product_ids
    $allIds = [];
    foreach ($history as $h) {
        if (!empty($h['recommended_product_ids'])) {
            $ids = json_decode((string)$h['recommended_product_ids'], true) ?: [];
            foreach ($ids as $id) $allIds[(int)$id] = true;
        }
    }
    $productsMap = [];
    if ($allIds) {
        $ids = array_keys($allIds);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, name, price, image FROM products WHERE id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $productsMap[(int)$row['id']] = $row;
    }

    $out = [];
    foreach ($history as $h) {
        $row = [
            'role'      => (string)$h['role'],
            'content'   => (string)$h['content'],
            'fallback'  => (bool)$h['fallback'],
            'created_at'=> (string)$h['created_at'],
            'products'  => [],
        ];
        if (!empty($h['recommended_product_ids'])) {
            $ids = json_decode((string)$h['recommended_product_ids'], true) ?: [];
            foreach ($ids as $id) {
                if (isset($productsMap[(int)$id])) $row['products'][] = $productsMap[(int)$id];
            }
        }
        $out[] = $row;
    }

    echo json_encode([
        'ok'      => true,
        'session' => $session,
        'history' => $out,
        'yandex_enabled' => ai_advisor_yandex_available(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reset') {
    setcookie('ai_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
    ]);
    echo json_encode(['ok' => true, 'reset' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// action = 'message'
if ($message === '') {
    echo json_encode(['ok' => false, 'error' => 'empty_message'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($message, 'UTF-8') > 600) {
    $message = mb_substr($message, 0, 600, 'UTF-8');
}

// Фильтр персональных данных: чистим карту/CVV/паспорт/телефон/email
// ДО отправки в YandexGPT и сохранения в БД (152-ФЗ + PCI DSS).
$piiResult = pii_sanitize($message);
$message = $piiResult['clean'];
$piiFlags = $piiResult['flags'];

// Если нашли — добавим в ответ предупреждение для пользователя.
$piiWarning = null;
if ($piiFlags) {
    $labels = [
        'card' => 'номер банковской карты',
        'cvv' => 'CVV/CVC код',
        'card_exp' => 'срок действия карты',
        'passport' => 'паспортные данные',
        'snils' => 'СНИЛС',
        'phone' => 'телефон',
        'email' => 'email',
    ];
    $found = array_values(array_intersect_key($labels, array_flip($piiFlags)));
    $piiWarning = '⚠️ В сообщении были скрыты персональные данные (' . implode(', ', $found)
        . '). Не отправляйте платёжную и паспортную информацию в чат — это небезопасно.';
}

// Если в сообщении найдены персональные данные — НЕ запускаем подбор букетов.
// Иначе остатки маски (например, последние цифры телефона) могут быть прочитаны
// как бюджет, и Алиса начнёт советовать букеты «в пределах вашего бюджета».
// Поведение по требованию: только предупреждаем и мягко возвращаем к теме.
if ($piiFlags) {
    $safeReply = 'Давайте вернёмся к букетам 🌸 Напишите повод, получателя или бюджет — и я подберу несколько вариантов.';

    // Сохраняем обмен в историю, чтобы при перезагрузке чата он не пропадал.
    try {
        $chat = ai_chat_get_or_create($pdo, $session, $userId);
        $chatId = (int)$chat['id'];
        ai_chat_add_message($pdo, $chatId, 'user', $message);
        ai_chat_add_message($pdo, $chatId, 'assistant', $safeReply, [], null, 'pii_block', false);
    } catch (Throwable $e) {
        // история необязательна — без неё ответ всё равно вернём
    }

    echo json_encode([
        'ok'           => true,
        'session'      => $session,
        'reply'        => $safeReply,
        'recommended'  => [],
        'fallback'     => false,
        'model'        => 'pii_block',
        'tokens'       => 0,
        'signals'      => ['pii_block' => true],
        'pii_warning'  => $piiWarning,
        'user_message_masked' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = ai_advisor_handle_message($pdo, $session, $message, $userId);

    echo json_encode([
        'ok'           => true,
        'session'      => $session,
        'reply'        => $result['reply'],
        'recommended'  => $result['recommended'],
        'fallback'     => $result['fallback'],
        'model'        => $result['model'],
        'tokens'       => $result['tokens'],
        'signals'      => $result['signals'],
        'pii_warning'  => $piiWarning,
        'user_message_masked' => $message,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    @file_put_contents(
        __DIR__ . '/ai_advisor_error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );
    echo json_encode([
        'ok'    => false,
        'error' => 'server_error',
        'debug' => APP_DEBUG ? ($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : null,
    ], JSON_UNESCAPED_UNICODE);
}
