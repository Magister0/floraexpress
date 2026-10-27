<?php
/*
|--------------------------------------------------------------------------
| VK Callback API — вебхук для курьеров FloraExpress
|--------------------------------------------------------------------------
|
| Установите этот URL в настройках Callback API сообщества ВКонтакте:
|   https://your-domain.ru/vk_bot.php
|
| Включите события: "Входящие сообщения" и "Message event".
|
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vk_config.php';
require_once __DIR__ . '/vk_notify.php';

$pdo = db();

// VK ожидает ответ "ok" на каждый запрос
header('Content-Type: text/plain; charset=utf-8');

$raw    = file_get_contents('php://input');
$update = json_decode((string)$raw, true);

flora_vk_log('RAW INPUT', $raw);
flora_vk_log('PARSED UPDATE', $update);

if (!is_array($update)) {
    http_response_code(200);
    echo 'ok';
    exit;
}

$type = (string)($update['type'] ?? '');

/*
|--------------------------------------------------------------------------
| Подтверждение вебхука (до проверки секрета — VK не передаёт его здесь)
|--------------------------------------------------------------------------
*/
if ($type === 'confirmation') {
    echo FLORA_VK_CONFIRMATION;
    exit;
}

// Проверка секретного ключа (если задан) — только для обычных событий
if (FLORA_VK_SECRET !== '') {
    $secret = (string)($update['secret'] ?? '');
    if ($secret !== FLORA_VK_SECRET) {
        flora_vk_log('SECRET MISMATCH', ['received' => $secret]);
        http_response_code(200);
        echo 'ok';
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Вспомогательные функции
|--------------------------------------------------------------------------
*/

function getCourierByVkId(PDO $pdo, int $vkUserId): ?array
{
    $st = $pdo->prepare("
        SELECT id, name, role, email, phone, vk_user_id, on_shift
        FROM users
        WHERE role = 'courier'
          AND vk_user_id = ?
        LIMIT 1
    ");
    $st->execute([$vkUserId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function syncVkUserId(PDO $pdo, int $courierId, int $vkUserId): void
{
    $pdo->prepare("UPDATE users SET vk_user_id = ? WHERE id = ?")
        ->execute([$vkUserId, $courierId]);
}

function setCourierShiftVk(PDO $pdo, int $courierId, bool $onShift): void
{
    if ($onShift) {
        $pdo->prepare("
            UPDATE users
            SET on_shift = TRUE,
                shift_started_at = COALESCE(shift_started_at, CURRENT_TIMESTAMP)
            WHERE id = ?
        ")->execute([$courierId]);
    } else {
        $pdo->prepare("
            UPDATE users
            SET on_shift = FALSE, shift_started_at = NULL
            WHERE id = ?
        ")->execute([$courierId]);
    }
}

function getFreeDeliveries(PDO $pdo): array
{
    $hasReleased = (bool)$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='orders' AND column_name='released_to_courier' LIMIT 1")->fetchColumn();
    $relCond = $hasReleased ? " AND COALESCE(o.released_to_courier, FALSE) = TRUE" : "";
    $st = $pdo->query("
        SELECT d.id, d.order_id, d.status, d.courier_fee, d.distance_km_est,
               o.address, o.phone, o.comment, o.total, o.recipient_name,
               o.delivery_slot, o.address_lat, o.address_lon
        FROM deliveries d
        JOIN orders o ON o.id = d.order_id
        WHERE d.status = 'pending' AND d.courier_id IS NULL{$relCond}
        ORDER BY d.id DESC
        LIMIT 10
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getCourierDeliveriesCurrent(PDO $pdo, int $courierId): array
{
    $st = $pdo->prepare("
        SELECT d.id, d.order_id, d.status, d.courier_fee, d.distance_km_est,
               o.address, o.phone, o.comment, o.total, o.recipient_name,
               o.delivery_slot, o.address_lat, o.address_lon
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

function getCourierStats(PDO $pdo, int $courierId): array
{
    $st = $pdo->prepare("
        SELECT COUNT(*)::int AS delivered_count,
               COALESCE(SUM(courier_fee), 0) AS earned_total,
               COALESCE(SUM(distance_km_est), 0) AS distance_total
        FROM deliveries
        WHERE courier_id = ? AND status = 'delivered'
    ");
    $st->execute([$courierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'delivered_count' => (int)($row['delivered_count'] ?? 0),
        'earned_total'    => (float)($row['earned_total'] ?? 0),
        'distance_total'  => (float)($row['distance_total'] ?? 0),
    ];
}

function assignDeliveryToCourier(PDO $pdo, int $deliveryId, int $courierId): bool
{
    $st = $pdo->prepare("
        UPDATE deliveries
        SET courier_id = ?, status = 'assigned', assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP)
        WHERE id = ? AND courier_id IS NULL AND status = 'pending'
    ");
    $st->execute([$courierId, $deliveryId]);
    return $st->rowCount() > 0;
}

function addDeliveryEvent(PDO $pdo, int $deliveryId, string $event, array $meta = []): void
{
    $pdo->prepare("
        INSERT INTO delivery_events (delivery_id, event, meta_json)
        VALUES (?, ?, ?)
    ")->execute([$deliveryId, $event, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

function applyDeliveryStatus(PDO $pdo, int $deliveryId, string $status, string $source = 'vk_bot'): array
{
    $allowed = ['assigned', 'picked', 'en_route', 'delivered', 'failed'];
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
            UPDATE deliveries SET status = ?, delivered_at = CURRENT_TIMESTAMP WHERE id = ?
        ")->execute([$status, $deliveryId]);
        $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?")->execute([$orderId]);
    } else {
        $pdo->prepare("UPDATE deliveries SET status = ? WHERE id = ?")->execute([$status, $deliveryId]);
        if ($status === 'en_route') {
            $pdo->prepare("UPDATE orders SET status = 'on_delivery' WHERE id = ?")->execute([$orderId]);
        }
    }

    addDeliveryEvent($pdo, $deliveryId, 'status', [
        'new'      => $status,
        'by'       => $source,
        'order_id' => $orderId,
    ]);

    return ['ok' => true, 'delivery_id' => $deliveryId, 'status' => $status, 'order_id' => $orderId];
}

function sendFreeDeliveriesToCourier(PDO $pdo, int $peerId): void
{
    global $pdo;
    $items = getFreeDeliveries($pdo);

    if (!$items) {
        flora_vk_send_message($peerId, "Сейчас свободных доставок нет.");
        return;
    }

    foreach ($items as $d) {
        flora_vk_send_message($peerId, vk_delivery_card($d), vk_take_keyboard((int)$d['id']));
        vk_send_contact_actions($peerId, $d);
    }
}

/*
|--------------------------------------------------------------------------
| MESSAGE EVENT — нажатие callback-кнопки
|--------------------------------------------------------------------------
*/
if ($type === 'message_event') {
    $obj     = $update['object'] ?? [];
    $userId  = (int)($obj['user_id'] ?? 0);
    $peerId  = (int)($obj['peer_id'] ?? $userId);
    $eventId = (string)($obj['event_id'] ?? '');
    $payload = $obj['payload'] ?? [];

    if (is_string($payload)) {
        $payload = json_decode($payload, true) ?: [];
    }

    $cmd = (string)($payload['cmd'] ?? '');

    $courier = getCourierByVkId($pdo, $userId);
    if (!$courier) {
        flora_vk_answer_event($eventId, $userId, $peerId, 'Курьер не привязан.');
        echo 'ok';
        exit;
    }

    if (!(bool)$courier['on_shift'] && !in_array($cmd, ['shift_on', 'shift_off'], true)) {
        flora_vk_answer_event($eventId, $userId, $peerId, 'Сначала выйдите на смену.');
        echo 'ok';
        exit;
    }

    if ($cmd === 'take') {
        $deliveryId = (int)($payload['id'] ?? 0);
        $ok = assignDeliveryToCourier($pdo, $deliveryId, (int)$courier['id']);

        if ($ok) {
            addDeliveryEvent($pdo, $deliveryId, 'assigned', [
                'by'         => 'vk_bot',
                'courier_id' => (int)$courier['id'],
            ]);
            flora_vk_answer_event($eventId, $userId, $peerId, 'Доставка назначена на вас!');
            flora_vk_send_message($peerId, "Доставка #{$deliveryId} назначена на вас.");
        } else {
            flora_vk_answer_event($eventId, $userId, $peerId, 'Эту доставку уже взял другой курьер.');
        }

        echo 'ok';
        exit;
    }

    if ($cmd === 'status') {
        $deliveryId = (int)($payload['id'] ?? 0);
        $status     = (string)($payload['st'] ?? '');

        $result = applyDeliveryStatus($pdo, $deliveryId, $status, 'vk_bot');

        if (!empty($result['ok'])) {
            flora_vk_answer_event($eventId, $userId, $peerId, 'Статус обновлён.');
            flora_vk_send_message(
                $peerId,
                "Доставка #{$deliveryId}\nНовый статус: " . vk_delivery_status_label($status)
            );
        } else {
            flora_vk_answer_event($eventId, $userId, $peerId, 'Ошибка обновления статуса.');
        }

        echo 'ok';
        exit;
    }

    flora_vk_answer_event($eventId, $userId, $peerId, 'Неизвестная команда.');
    echo 'ok';
    exit;
}

/*
|--------------------------------------------------------------------------
| MESSAGE NEW — входящее сообщение
|--------------------------------------------------------------------------
*/
if ($type === 'message_new') {
    $msg    = $update['object']['message'] ?? [];
    $peerId = (int)($msg['peer_id'] ?? 0);
    $fromId = (int)($msg['from_id'] ?? $peerId);
    $text   = trim((string)($msg['text'] ?? ''));

    // Если нажата кнопка меню — извлекаем команду из payload
    $btnPayload = $msg['payload'] ?? null;
    if (is_string($btnPayload)) {
        $btnPayload = json_decode($btnPayload, true) ?: null;
    }
    $btnCmd = is_array($btnPayload) ? (string)($btnPayload['cmd'] ?? '') : '';

    // Команда: либо из payload кнопки, либо из текста
    $cmd = $btnCmd ?: $text;

    $courier = getCourierByVkId($pdo, $fromId);

    // /start или "Начать"
    if ($cmd === '/start' || mb_strtolower($text) === 'начать') {
        if (!$courier) {
            flora_vk_send_message(
                $peerId,
                "Привет! Этот бот работает только для курьеров FloraExpress.\n\n" .
                "Чтобы привязать VK-аккаунт, сообщите администратору ваш VK ID: {$fromId}\n" .
                "Он добавит вас в систему."
            );
            echo 'ok';
            exit;
        }

        syncVkUserId($pdo, (int)$courier['id'], $fromId);
        $onShift = (bool)$courier['on_shift'];

        flora_vk_send_message(
            $peerId,
            "Привет, {$courier['name']}!\nВаш статус: " . ($onShift ? 'На смене' : 'Не на смене'),
            vk_main_keyboard($onShift)
        );

        echo 'ok';
        exit;
    }

    if (!$courier) {
        flora_vk_send_message($peerId, "Вы не привязаны как курьер. Напишите /start.");
        echo 'ok';
        exit;
    }

    syncVkUserId($pdo, (int)$courier['id'], $fromId);
    $onShift = (bool)$courier['on_shift'];

    // Выйти на смену
    if ($cmd === 'shift_on' || $text === 'Выйти на смену') {
        setCourierShiftVk($pdo, (int)$courier['id'], true);
        flora_vk_send_message($peerId, "Вы вышли на смену. Показываю свободные доставки.", vk_main_keyboard(true));
        sendFreeDeliveriesToCourier($pdo, $peerId);
        echo 'ok';
        exit;
    }

    // Уйти со смены
    if ($cmd === 'shift_off' || $text === 'Уйти со смены') {
        setCourierShiftVk($pdo, (int)$courier['id'], false);
        flora_vk_send_message($peerId, "Вы ушли со смены. Новые доставки приходить не будут.", vk_main_keyboard(false));
        echo 'ok';
        exit;
    }

    // Свободные доставки
    if ($cmd === 'free' || $text === 'Свободные доставки') {
        if (!$onShift) {
            flora_vk_send_message($peerId, "Сначала выйдите на смену.", vk_main_keyboard(false));
            echo 'ok';
            exit;
        }
        sendFreeDeliveriesToCourier($pdo, $peerId);
        echo 'ok';
        exit;
    }

    // Мои доставки
    if ($cmd === 'mine' || $text === 'Мои доставки') {
        if (!$onShift) {
            flora_vk_send_message($peerId, "Сначала выйдите на смену.", vk_main_keyboard(false));
            echo 'ok';
            exit;
        }

        $items = getCourierDeliveriesCurrent($pdo, (int)$courier['id']);

        if (!$items) {
            flora_vk_send_message($peerId, "Сейчас у вас нет активных доставок.", vk_main_keyboard(true));
            echo 'ok';
            exit;
        }

        foreach ($items as $d) {
            flora_vk_send_message($peerId, vk_delivery_card($d), vk_status_keyboard((int)$d['id']));
            vk_send_contact_actions($peerId, $d);
        }

        echo 'ok';
        exit;
    }

    // Статистика
    if ($cmd === 'stats' || $text === 'Статистика') {
        $stats = getCourierStats($pdo, (int)$courier['id']);

        flora_vk_send_message(
            $peerId,
            "📊 Статистика курьера\n\n" .
            "Выполнено доставок: " . (int)$stats['delivered_count'] . "\n" .
            "Заработано: " . number_format((float)$stats['earned_total'], 0, ',', ' ') . " ₽\n" .
            "Пробег: " . number_format((float)$stats['distance_total'], 1, ',', ' ') . " км\n\n" .
            "Километры считаются оценочно по координатам.",
            vk_main_keyboard($onShift)
        );

        echo 'ok';
        exit;
    }

    // Неизвестная команда
    flora_vk_send_message(
        $peerId,
        "Команда не распознана. Используйте кнопки меню или напишите /start.",
        vk_main_keyboard($onShift)
    );

    echo 'ok';
    exit;
}

// Любые другие типы событий — просто ответить "ok"
echo 'ok';
