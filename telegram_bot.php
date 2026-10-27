<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_config.php';
require_once __DIR__ . '/telegram_notify.php';
require_once __DIR__ . '/courier_location_lib.php';

$pdo = db();

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function answerCallbackQuery(string $callbackId, string $text = ''): array {
    return flora_tg_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => false,
    ]);
}

function editMessage(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null): array {
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup !== null) {
        $data['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    return flora_tg_api('editMessageText', $data);
}

function deliveryStatusLabel(string $status): string {
    $map = [
        'pending'   => 'Ожидает назначения',
        'assigned'  => 'Назначен',
        'picked'    => 'Заказ забран',
        'en_route'  => 'В пути',
        'delivered' => 'Доставлен',
        'failed'    => 'Не удалось доставить',
    ];
    return $map[$status] ?? $status;
}

function statusEmoji(string $status): string {
    $map = [
        'pending'   => '⚪️',
        'assigned'  => '🟡',
        'picked'    => '📦',
        'en_route'  => '🚗',
        'delivered' => '✅',
        'failed'    => '❌',
    ];
    return $map[$status] ?? '•';
}

// Контекстная клавиатура: показываем только следующий логичный шаг,
// а не все статусы сразу. Для завершённых доставок кнопок нет.
function statusKeyboard(int $deliveryId, string $status): ?array {
    $next = match ($status) {
        'assigned' => ['picked'   => '📦 Забрал заказ'],
        'picked'   => ['delivered'=> '✅ Доставлен'],
        'en_route' => ['delivered'=> '✅ Доставлен'],
        default    => null, // delivered / failed / pending — финал или ещё не взята
    };
    if ($next === null) {
        return null;
    }

    $rows = [];
    foreach ($next as $code => $label) {
        $rows[] = [['text' => $label, 'callback_data' => "status:{$deliveryId}:{$code}"]];
    }
    $rows[] = [['text' => '❌ Не удалось доставить', 'callback_data' => "status:{$deliveryId}:failed"]];

    return ['inline_keyboard' => $rows];
}

function mainKeyboard(bool $onShift): array {
    return [
        'keyboard' => [
            [
                ['text' => $onShift ? '🔴 Уйти со смены' : '🟢 Выйти на смену']
            ],
            [
                ['text' => '📋 Свободные доставки'],
                ['text' => '🚚 Мои доставки']
            ],
            [
                ['text' => '📊 Статистика']
            ]
        ],
        'resize_keyboard' => true,
        'is_persistent' => true
    ];
}

function getCourierByTelegramId(PDO $pdo, int|string $telegramUserId): ?array {
    $st = $pdo->prepare("
        SELECT id, name, role, email, phone, telegram_chat_id, on_shift
        FROM users
        WHERE role = 'courier'
          AND (phone = ? OR email = ?)
        LIMIT 1
    ");
    $st->execute([(string)$telegramUserId, $telegramUserId . '@tg.local']);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    flora_tg_log('getCourierByTelegramId', [
        'telegram_id' => $telegramUserId,
        'found' => $row ?: null
    ]);

    return $row ?: null;
}

function syncCourierChatId(PDO $pdo, int $courierId, int|string $chatId): void {
    $st = $pdo->prepare("
        UPDATE users
        SET telegram_chat_id = ?
        WHERE id = ?
    ");
    $st->execute([(int)$chatId, $courierId]);
}

function setCourierShift(PDO $pdo, int $courierId, bool $onShift): void {
    if ($onShift) {
        $st = $pdo->prepare("
            UPDATE users
            SET on_shift = TRUE,
                shift_started_at = COALESCE(shift_started_at, CURRENT_TIMESTAMP)
            WHERE id = ?
        ");
        $st->execute([$courierId]);
        return;
    }

    $st = $pdo->prepare("
        UPDATE users
        SET on_shift = FALSE,
            shift_started_at = NULL
        WHERE id = ?
    ");
    $st->execute([$courierId]);
}

function getFreeDeliveries(PDO $pdo): array {
    $hasReleased = (bool)$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='orders' AND column_name='released_to_courier' LIMIT 1")->fetchColumn();
    $relCond = $hasReleased ? " AND COALESCE(o.released_to_courier, FALSE) = TRUE" : "";
    $st = $pdo->query("
        SELECT
            d.id,
            d.order_id,
            d.status,
            d.courier_fee,
            d.distance_km_est,
            o.address,
            o.phone,
            o.comment,
            o.total,
            o.recipient_name,
            o.delivery_slot,
            o.address_lat,
            o.address_lon
        FROM deliveries d
        JOIN orders o ON o.id = d.order_id
        WHERE d.status = 'pending' AND d.courier_id IS NULL{$relCond}
        ORDER BY d.id DESC
        LIMIT 10
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getCourierDeliveriesCurrent(PDO $pdo, int $courierId): array {
    $st = $pdo->prepare("
        SELECT
            d.id,
            d.order_id,
            d.status,
            d.courier_fee,
            d.distance_km_est,
            o.address,
            o.phone,
            o.comment,
            o.total,
            o.recipient_name,
            o.delivery_slot,
            o.address_lat,
            o.address_lon
        FROM deliveries d
        JOIN orders o ON o.id = d.order_id
        WHERE d.courier_id = ?
          AND d.status IN ('assigned','picked','en_route')
        ORDER BY d.id DESC
        LIMIT 20
    ");
    $st->execute([$courierId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getCourierStats(PDO $pdo, int $courierId): array {
    $st = $pdo->prepare("
        SELECT
            COUNT(*)::int AS delivered_count,
            COALESCE(SUM(courier_fee), 0) AS earned_total,
            COALESCE(SUM(distance_km_est), 0) AS distance_total
        FROM deliveries
        WHERE courier_id = ?
          AND status = 'delivered'
    ");
    $st->execute([$courierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'delivered_count' => (int)($row['delivered_count'] ?? 0),
        'earned_total' => (float)($row['earned_total'] ?? 0),
        'distance_total' => (float)($row['distance_total'] ?? 0),
    ];
}

function renderDeliveryCard(array $d): string {
    $km = isset($d['distance_km_est']) && $d['distance_km_est'] !== null
        ? number_format((float)$d['distance_km_est'], 1, ',', ' ') . ' км'
        : '—';

    $fee = number_format((float)($d['courier_fee'] ?? 0), 0, ',', ' ');
    $status = (string)$d['status'];

    $phone = trim((string)($d['phone'] ?? ''));
    $phoneDigits = preg_replace('~[^\d+]~', '', $phone);
    $phoneLine = $phone !== ''
        ? "📞 Телефон: <a href=\"tel:".h($phoneDigits)."\"><b>".h($phone)."</b></a>\n"
        : '';

    $addrLine = "📍 Адрес: <b>".h((string)($d['address'] ?? '—'))."</b>\n";
    if (!empty($d['address_lat']) && !empty($d['address_lon'])) {
        $lat = (float)$d['address_lat'];
        $lon = (float)$d['address_lon'];
        $addrLine .= "🗺 <a href=\"https://yandex.ru/maps/?pt={$lon},{$lat}&z=17&l=map\">Открыть на карте</a>\n";
    }

    $commentLine = !empty($d['comment'])
        ? "💬 Комментарий: <b>".h((string)$d['comment'])."</b>\n"
        : '';

    return
        "📦 <b>Доставка #".(int)$d['id']."</b>  ·  заказ #".(int)$d['order_id']."\n" .
        statusEmoji($status)." Статус: <b>".h(deliveryStatusLabel($status))."</b>\n" .
        "➖➖➖➖➖➖➖➖➖➖\n" .
        "👤 Получатель: <b>".h((string)($d['recipient_name'] ?? '—'))."</b>\n" .
        $phoneLine .
        $addrLine .
        "🕐 Слот: <b>".h((string)($d['delivery_slot'] ?? '—'))."</b>\n" .
        $commentLine .
        "➖➖➖➖➖➖➖➖➖➖\n" .
        "💰 Сумма заказа: <b>".number_format((float)($d['total'] ?? 0), 0, ',', ' ')." ₽</b>\n" .
        "🤝 Вам за доставку: <b>{$fee} ₽</b>\n" .
        "📏 Расстояние: <b>{$km}</b>";
}

function getDeliveryById(PDO $pdo, int $deliveryId): ?array {
    $st = $pdo->prepare("
        SELECT
            d.id, d.order_id, d.status, d.courier_fee, d.distance_km_est,
            o.address, o.phone, o.comment, o.total, o.recipient_name,
            o.delivery_slot, o.address_lat, o.address_lon
        FROM deliveries d
        JOIN orders o ON o.id = d.order_id
        WHERE d.id = ?
        LIMIT 1
    ");
    $st->execute([$deliveryId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function assignDeliveryToCourier(PDO $pdo, int $deliveryId, int $courierId): bool {
    $st = $pdo->prepare("
        UPDATE deliveries
        SET courier_id = ?, status = 'assigned', assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP)
        WHERE id = ? AND courier_id IS NULL AND status = 'pending'
    ");
    $st->execute([$courierId, $deliveryId]);
    return $st->rowCount() > 0;
}

function addDeliveryEvent(PDO $pdo, int $deliveryId, string $event, array $meta = []): void {
    $st = $pdo->prepare("
        INSERT INTO delivery_events (delivery_id, event, meta_json)
        VALUES (?, ?, ?)
    ");
    $st->execute([$deliveryId, $event, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

function applyDeliveryStatus(PDO $pdo, int $deliveryId, string $status, string $source = 'telegram_bot'): array {
    $allowed = ['assigned','picked','en_route','delivered','failed'];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'error' => 'invalid status'];
    }

    $st = $pdo->prepare("SELECT * FROM deliveries WHERE id = ? LIMIT 1");
    $st->execute([$deliveryId]);
    $delivery = $st->fetch(PDO::FETCH_ASSOC);

    if (!$delivery) {
        return ['ok' => false, 'error' => 'delivery not found'];
    }

    $orderId = (int)$delivery['order_id'];

    if ($status === 'assigned') {
        $pdo->prepare("
            UPDATE deliveries
            SET status = ?, assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP)
            WHERE id = ?
        ")->execute([$status, $deliveryId]);
    } elseif ($status === 'delivered') {
        $pdo->prepare("
            UPDATE deliveries
            SET status = ?, delivered_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$status, $deliveryId]);

        $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?")
            ->execute([$orderId]);
    } else {
        $pdo->prepare("UPDATE deliveries SET status = ? WHERE id = ?")
            ->execute([$status, $deliveryId]);

        if ($status === 'en_route' || $status === 'picked') {
            $pdo->prepare("UPDATE orders SET status = 'on_delivery' WHERE id = ?")
                ->execute([$orderId]);
        }
    }

    addDeliveryEvent($pdo, $deliveryId, 'status', [
        'new' => $status,
        'by' => $source,
        'order_id' => $orderId
    ]);

    return [
        'ok' => true,
        'delivery_id' => $deliveryId,
        'status' => $status,
        'order_id' => $orderId
    ];
}

function sendFreeDeliveriesToCourier(PDO $pdo, int|string $chatId): array {
    $items = getFreeDeliveries($pdo);
    $results = [];

    if (!$items) {
        $results[] = flora_send_message(
            $chatId,
            "Сейчас свободных доставок нет.",
            mainKeyboard(true)
        );
        return $results;
    }

    foreach ($items as $d) {
        $msg = flora_send_message(
            $chatId,
            renderDeliveryCard($d),
            [
                'inline_keyboard' => [
                    [
                        ['text' => '📦 Взять доставку', 'callback_data' => 'take:' . (int)$d['id']]
                    ]
                ]
            ]
        );

        $results[] = ['message' => $msg];

        if (!empty($d['address_lat']) && !empty($d['address_lon'])) {
            $results[] = [
                'location' => flora_send_location(
                    $chatId,
                    (float)$d['address_lat'],
                    (float)$d['address_lon']
                )
            ];
        }
    }

    return $results;
}

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/
$input = file_get_contents('php://input');
$update = json_decode((string)$input, true);

flora_tg_log('RAW INPUT', $input);
flora_tg_log('PARSED UPDATE', $update);

if (!is_array($update)) {
    respond(['ok' => false, 'error' => 'No valid JSON update'], 200);
}

/*
|--------------------------------------------------------------------------
| LIVE LOCATION (как первое сообщение, так и редактирование с обновлёнными координатами)
|--------------------------------------------------------------------------
*/
$locationSource = null;
if (isset($update['edited_message']['location'])) {
    $locationSource = $update['edited_message'];
} elseif (isset($update['message']['location'])) {
    $locationSource = $update['message'];
}

if ($locationSource !== null) {
    $loc = $locationSource['location'];
    $fromId = (int)($locationSource['from']['id'] ?? 0);
    $courier = $fromId > 0 ? getCourierByTelegramId($pdo, $fromId) : null;

    if ($courier && (bool)$courier['on_shift']) {
        $lat = (float)($loc['latitude']  ?? 0);
        $lon = (float)($loc['longitude'] ?? 0);
        $opts = [
            'source'      => 'telegram',
            'live_period' => isset($loc['live_period']) ? (int)$loc['live_period'] : null,
            'heading'     => isset($loc['heading'])     ? (float)$loc['heading']   : null,
            'accuracy'    => isset($loc['horizontal_accuracy']) ? (float)$loc['horizontal_accuracy'] : null,
        ];

        if ($lat != 0.0 || $lon != 0.0) {
            try {
                courier_location_update($pdo, (int)$courier['id'], $lat, $lon, $opts);
            } catch (Throwable $e) {
                flora_tg_log('courier_location_update ERROR', $e->getMessage());
            }

            // Отвечаем подтверждением только на первое сообщение (не на каждое edited).
            // edited_message приходит каждую минуту — не спамим курьеру.
            if (isset($update['message']['location']) && !empty($opts['live_period'])) {
                $chatId = (int)$update['message']['chat']['id'];
                $period = (int)$opts['live_period'];
                $periodMin = max(1, (int)round($period / 60));
                flora_send_message(
                    $chatId,
                    "📍 Геолокация принята.\n" .
                    "Клиенты увидят вас на карте отслеживания заказа.\n" .
                    "Трансляция активна " . $periodMin . " мин."
                );
            }
        }
    }

    respond([
        'ok' => true,
        'action' => 'live_location',
        'courier_linked' => $courier ? true : false,
        'on_shift' => $courier ? (bool)$courier['on_shift'] : null,
    ]);
}

/*
|--------------------------------------------------------------------------
| CALLBACK QUERY
|--------------------------------------------------------------------------
*/
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $callbackId = (string)$cq['id'];
    $chatId = (int)$cq['message']['chat']['id'];
    $messageId = (int)$cq['message']['message_id'];
    $fromId = (int)$cq['from']['id'];
    $data = (string)($cq['data'] ?? '');

    $courier = getCourierByTelegramId($pdo, $fromId);
    if (!$courier) {
        $answerRes = answerCallbackQuery($callbackId, 'Курьер не привязан.');
        respond([
            'ok' => false,
            'error' => 'Courier not linked',
            'answer_callback' => $answerRes
        ]);
    }

    syncCourierChatId($pdo, (int)$courier['id'], $chatId);

    if (!(bool)$courier['on_shift']) {
        $answerRes = answerCallbackQuery($callbackId, 'Сначала выйдите на смену');
        respond([
            'ok' => false,
            'error' => 'Courier is off shift',
            'answer_callback' => $answerRes
        ]);
    }

    if ($data === 'showfree') {
        $answerRes = answerCallbackQuery($callbackId, 'Показываю свободные доставки');
        $results = sendFreeDeliveriesToCourier($pdo, $chatId);

        respond([
            'ok' => true,
            'action' => 'showfree',
            'answer_callback' => $answerRes,
            'messages' => $results
        ]);
    }

    if (preg_match('~^take:(\d+)$~', $data, $m)) {
        $deliveryId = (int)$m[1];

        $ok = assignDeliveryToCourier($pdo, $deliveryId, (int)$courier['id']);
        if ($ok) {
            addDeliveryEvent($pdo, $deliveryId, 'assigned', [
                'by' => 'telegram_bot',
                'courier_id' => (int)$courier['id'],
            ]);

            $answerRes = answerCallbackQuery($callbackId, '✅ Доставка ваша!');
            $d = getDeliveryById($pdo, $deliveryId);
            $cardText = $d
                ? renderDeliveryCard($d) . "\n\n✅ <b>Доставка закреплена за вами.</b>\nОбновляйте статус кнопкой ниже."
                : "Доставка #{$deliveryId} назначена на вас.";
            $editRes = editMessage(
                $chatId,
                $messageId,
                $cardText,
                $d ? statusKeyboard($deliveryId, (string)$d['status']) : null
            );

            respond([
                'ok' => true,
                'action' => 'take',
                'delivery_id' => $deliveryId,
                'answer_callback' => $answerRes,
                'edit_message' => $editRes
            ]);
        }

        $answerRes = answerCallbackQuery($callbackId, 'Эту доставку уже взял другой курьер');
        respond([
            'ok' => false,
            'action' => 'take',
            'delivery_id' => $deliveryId,
            'answer_callback' => $answerRes
        ]);
    }

    if (preg_match('~^status:(\d+):(assigned|picked|en_route|delivered|failed)$~', $data, $m)) {
        $deliveryId = (int)$m[1];
        $status = (string)$m[2];

        $updateRes = applyDeliveryStatus($pdo, $deliveryId, $status, 'telegram_bot');

        if (!empty($updateRes['ok'])) {
            $answerRes = answerCallbackQuery($callbackId, statusEmoji($status).' '.deliveryStatusLabel($status));

            $d = getDeliveryById($pdo, $deliveryId);
            $kb = statusKeyboard($deliveryId, $status);
            $footer = match ($status) {
                'delivered' => "\n\n✅ <b>Заказ доставлен. Спасибо за работу!</b>",
                'failed'    => "\n\n❌ <b>Доставка отменена.</b>",
                default     => "\n\n<i>Обновляйте статус кнопкой ниже.</i>",
            };
            $editRes = editMessage(
                $chatId,
                $messageId,
                ($d ? renderDeliveryCard($d) : "Доставка #{$deliveryId}").$footer,
                // на финальном статусе убираем кнопки (пустая клавиатура)
                $kb ?? ['inline_keyboard' => []]
            );

            respond([
                'ok' => true,
                'action' => 'set_status',
                'delivery_id' => $deliveryId,
                'status' => $status,
                'db_update' => $updateRes,
                'answer_callback' => $answerRes,
                'edit_message' => $editRes
            ]);
        }

        $answerRes = answerCallbackQuery($callbackId, 'Ошибка обновления');
        respond([
            'ok' => false,
            'action' => 'set_status',
            'delivery_id' => $deliveryId,
            'status' => $status,
            'db_update' => $updateRes,
            'answer_callback' => $answerRes
        ]);
    }

    $answerRes = answerCallbackQuery($callbackId, 'Неизвестная команда');
    respond([
        'ok' => false,
        'error' => 'Unknown callback command',
        'answer_callback' => $answerRes
    ]);
}

/*
|--------------------------------------------------------------------------
| MESSAGE
|--------------------------------------------------------------------------
*/
if (!isset($update['message'])) {
    respond(['ok' => false, 'error' => 'No message or callback_query in update'], 200);
}

$message = $update['message'];
$chatId = (int)$message['chat']['id'];
$fromId = (int)$message['from']['id'];
$text = trim((string)($message['text'] ?? ''));
// Текст кнопки без ведущих эмодзи — чтобы кнопки с иконками («🚚 Мои доставки»)
// совпадали с обработчиками так же, как и текстовые команды.
$cmd = trim(preg_replace('~[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]~u', '', $text));

$courier = getCourierByTelegramId($pdo, $fromId);

if ($text === '/start') {
    if (!$courier) {
        $res = flora_send_message(
            $chatId,
            "Привет. Этот бот работает только для курьеров FloraExpress.\n\n" .
            "Чтобы привязать Telegram, укажи в таблице users:\n" .
            "phone = {$fromId}\nили\nemail = {$fromId}@tg.local"
        );

        respond([
            'ok' => true,
            'command' => '/start',
            'courier_found' => false,
            'telegram_response' => $res
        ]);
    }

    syncCourierChatId($pdo, (int)$courier['id'], $chatId);

    $onShift = (bool)$courier['on_shift'];

    $res = flora_send_message(
        $chatId,
        "Привет, ".h((string)$courier['name'])."!\n" .
        "Это ".FLORA_BOT_NAME.".\n\n" .
        "Ваш статус: <b>".($onShift ? 'На смене' : 'Не на смене')."</b>\n\n" .
        "<b>📍 Live-tracking</b>\n" .
        "Когда поедете на доставку, отправьте свою геолокацию в режиме <b>трансляции</b>:\n" .
        "📎 → <i>Геопозиция</i> → <i>Транслировать мою геолокацию</i> (на 1 час или 8 часов).\n" .
        "Клиент будет видеть вас на карте отслеживания заказа.",
        mainKeyboard($onShift)
    );

    respond([
        'ok' => true,
        'command' => '/start',
        'courier_found' => true,
        'courier' => $courier,
        'telegram_response' => $res
    ]);
}

if (!$courier) {
    $res = flora_send_message($chatId, "Вы не привязаны как курьер. Используйте /start.");
    respond([
        'ok' => false,
        'error' => 'Courier not linked',
        'telegram_response' => $res
    ]);
}

syncCourierChatId($pdo, (int)$courier['id'], $chatId);
$onShift = (bool)$courier['on_shift'];

if ($cmd === '/shift_on' || $cmd === 'Выйти на смену') {
    setCourierShift($pdo, (int)$courier['id'], true);

    $res = flora_send_message(
        $chatId,
        "Вы вышли на смену. Показываю свободные доставки.",
        mainKeyboard(true)
    );

    $free = sendFreeDeliveriesToCourier($pdo, $chatId);

    respond([
        'ok' => true,
        'command' => 'shift_on',
        'telegram_response' => $res,
        'free_deliveries' => $free
    ]);
}

if ($cmd === '/shift_off' || $cmd === 'Уйти со смены') {
    setCourierShift($pdo, (int)$courier['id'], false);

    $res = flora_send_message(
        $chatId,
        "Вы ушли со смены. Новые доставки больше приходить не будут.",
        mainKeyboard(false)
    );

    respond([
        'ok' => true,
        'command' => 'shift_off',
        'telegram_response' => $res
    ]);
}

if ($cmd === '/free' || $cmd === 'Свободные доставки') {
    if (!$onShift) {
        $res = flora_send_message($chatId, "Сначала выйдите на смену.", mainKeyboard(false));
        respond([
            'ok' => false,
            'command' => '/free',
            'error' => 'Courier is off shift',
            'telegram_response' => $res
        ]);
    }

    $results = sendFreeDeliveriesToCourier($pdo, $chatId);

    respond([
        'ok' => true,
        'command' => '/free',
        'telegram_responses' => $results
    ]);
}

if ($cmd === '/mine' || $cmd === 'Мои доставки') {
    if (!$onShift) {
        $res = flora_send_message($chatId, "Сначала выйдите на смену.", mainKeyboard(false));
        respond([
            'ok' => false,
            'command' => '/mine',
            'error' => 'Courier is off shift',
            'telegram_response' => $res
        ]);
    }

    $items = getCourierDeliveriesCurrent($pdo, (int)$courier['id']);

    if (!$items) {
        $res = flora_send_message(
            $chatId,
            "Сейчас у вас нет активных доставок.",
            mainKeyboard(true)
        );

        respond([
            'ok' => true,
            'command' => '/mine',
            'count' => 0,
            'telegram_response' => $res
        ]);
    }

    $results = [];
    foreach ($items as $d) {
        $results[] = flora_send_message(
            $chatId,
            renderDeliveryCard($d),
            statusKeyboard((int)$d['id'], (string)$d['status'])
        );

        if (!empty($d['address_lat']) && !empty($d['address_lon'])) {
            $results[] = flora_send_location(
                $chatId,
                (float)$d['address_lat'],
                (float)$d['address_lon']
            );
        }
    }

    respond([
        'ok' => true,
        'command' => '/mine',
        'count' => count($items),
        'telegram_responses' => $results
    ]);
}

if ($cmd === '/stats' || $cmd === 'Статистика') {
    $stats = getCourierStats($pdo, (int)$courier['id']);

    $res = flora_send_message(
        $chatId,
        "<b>Статистика курьера</b>\n\n" .
        "Выполнено доставок: <b>".(int)$stats['delivered_count']."</b>\n" .
        "Заработано: <b>".number_format((float)$stats['earned_total'], 0, ',', ' ')." ₽</b>\n" .
        "Пробег: <b>".number_format((float)$stats['distance_total'], 1, ',', ' ')." км</b>\n\n" .
        "<i>Километры считаются оценочно по координатам точки доставки.</i>",
        mainKeyboard($onShift)
    );

    respond([
        'ok' => true,
        'command' => '/stats',
        'stats' => $stats,
        'telegram_response' => $res
    ]);
}

if ($cmd === '/location' || $cmd === 'Геопозиция') {
    $res = flora_send_message(
        $chatId,
        "📍 <b>Трансляция геопозиции</b>\n\n" .
        "Чтобы клиенты видели вас на карте отслеживания заказа, включите трансляцию одним из способов:\n\n" .
        "1) В этом чате: 📎 → <i>Геопозиция</i> → <i>Транслировать мою геолокацию</i> (на 1 или 8 часов).\n" .
        "2) В приложении курьера (кнопка-меню слева от поля ввода) → кнопка <i>«Транслировать геопозицию»</i>.",
        mainKeyboard($onShift)
    );
    respond([
        'ok' => true,
        'command' => '/location',
        'telegram_response' => $res
    ]);
}

$res = flora_send_message(
    $chatId,
    "Команда не распознана.\n\nИспользуйте кнопки или команды:\n/start\n/free\n/mine\n/stats\n/location\n/shift_on\n/shift_off",
    mainKeyboard($onShift)
);

respond([
    'ok' => true,
    'command' => 'unknown',
    'text' => $text,
    'telegram_response' => $res
]);