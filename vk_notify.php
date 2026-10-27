<?php
require_once __DIR__ . '/vk_config.php';

function flora_vk_log(string $title, $data = null): void
{
    $log = __DIR__ . '/vk_debug.log';
    $text = '[' . date('Y-m-d H:i:s') . '] ' . $title . PHP_EOL;

    if ($data !== null) {
        $text .= is_array($data) || is_object($data)
            ? print_r($data, true)
            : (string)$data;
        $text .= PHP_EOL;
    }

    $text .= str_repeat('-', 60) . PHP_EOL;
    file_put_contents($log, $text, FILE_APPEND);
}

function flora_vk_api(string $method, array $params = []): array
{
    if (FLORA_VK_TOKEN === '') {
        return ['error' => ['error_msg' => 'VK token is not configured']];
    }
    $params['access_token'] = FLORA_VK_TOKEN;
    $params['v']            = FLORA_VK_API_VERSION;

    $url = 'https://api.vk.com/method/' . $method;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if (!FLORA_VK_SSL_VERIFY) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $out = ['ok' => false, 'error' => $err, 'method' => $method];
        flora_vk_log('flora_vk_api CURL ERROR', $out);
        return $out;
    }

    $decoded = json_decode((string)$res, true);
    if (!is_array($decoded)) {
        $out = ['ok' => false, 'error' => 'Invalid JSON', 'raw' => $res];
        flora_vk_log('flora_vk_api INVALID JSON', $out);
        return $out;
    }

    if (isset($decoded['error'])) {
        flora_vk_log('flora_vk_api VK ERROR', $decoded);
    }

    return $decoded;
}

/**
 * Отправить сообщение пользователю ВКонтакте.
 *
 * @param int        $peerId   VK user_id (он же peer_id для личных сообщений)
 * @param string     $text     Текст сообщения
 * @param array|null $keyboard Клавиатура (см. vk_main_keyboard / vk_inline_keyboard)
 */
function flora_vk_send_message(int $peerId, string $text, ?array $keyboard = null): array
{
    $params = [
        'peer_id'   => $peerId,
        'message'   => $text,
        'random_id' => random_int(0, 2147483647),
    ];

    if ($keyboard !== null) {
        $params['keyboard'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    $result = flora_vk_api('messages.send', $params);
    flora_vk_log('flora_vk_send_message', ['peer_id' => $peerId, 'result' => $result]);
    return $result;
}

/**
 * Ответить на нажатие callback-кнопки (показать снэкбар).
 */
function flora_vk_answer_event(string $eventId, int $userId, int $peerId, string $text = ''): array
{
    $eventData = json_encode(['type' => 'show_snackbar', 'text' => $text ?: ' '], JSON_UNESCAPED_UNICODE);

    return flora_vk_api('messages.sendMessageEventAnswer', [
        'event_id'   => $eventId,
        'user_id'    => $userId,
        'peer_id'    => $peerId,
        'event_data' => $eventData,
    ]);
}

/**
 * Главное меню курьера (текстовые кнопки, аналог Reply Keyboard в Telegram).
 */
function vk_main_keyboard(bool $onShift): array
{
    $shiftBtn = $onShift
        ? [['action' => ['type' => 'text', 'label' => 'Уйти со смены',     'payload' => '{"cmd":"shift_off"}'], 'color' => 'negative']]
        : [['action' => ['type' => 'text', 'label' => 'Выйти на смену',    'payload' => '{"cmd":"shift_on"}'],  'color' => 'positive']];

    return [
        'one_time' => false,
        'buttons'  => [
            $shiftBtn,
            [
                ['action' => ['type' => 'text', 'label' => 'Свободные доставки', 'payload' => '{"cmd":"free"}'], 'color' => 'primary'],
                ['action' => ['type' => 'text', 'label' => 'Мои доставки',       'payload' => '{"cmd":"mine"}'], 'color' => 'primary'],
            ],
            [
                ['action' => ['type' => 'text', 'label' => 'Статистика', 'payload' => '{"cmd":"stats"}'], 'color' => 'secondary'],
            ],
        ],
    ];
}

/**
 * Inline-клавиатура для свободной доставки ("Взять доставку").
 */
function vk_take_keyboard(int $deliveryId): array
{
    return [
        'inline'  => true,
        'buttons' => [
            [
                [
                    'action' => [
                        'type'    => 'callback',
                        'label'   => 'Взять доставку',
                        'payload' => json_encode(['cmd' => 'take', 'id' => $deliveryId]),
                    ],
                    'color' => 'positive',
                ],
            ],
        ],
    ];
}

/**
 * Inline-клавиатура для смены статуса доставки ("Мои доставки").
 */
function vk_status_keyboard(int $deliveryId): array
{
    return [
        'inline'  => true,
        'buttons' => [
            [
                ['action' => ['type' => 'callback', 'label' => 'Назначен', 'payload' => json_encode(['cmd' => 'status', 'id' => $deliveryId, 'st' => 'assigned'])], 'color' => 'secondary'],
                ['action' => ['type' => 'callback', 'label' => 'Забрал',   'payload' => json_encode(['cmd' => 'status', 'id' => $deliveryId, 'st' => 'picked'])],   'color' => 'secondary'],
            ],
            [
                ['action' => ['type' => 'callback', 'label' => 'В пути',   'payload' => json_encode(['cmd' => 'status', 'id' => $deliveryId, 'st' => 'en_route'])],  'color' => 'primary'],
                ['action' => ['type' => 'callback', 'label' => 'Доставлен','payload' => json_encode(['cmd' => 'status', 'id' => $deliveryId, 'st' => 'delivered'])], 'color' => 'positive'],
            ],
            [
                ['action' => ['type' => 'callback', 'label' => 'Неудача',  'payload' => json_encode(['cmd' => 'status', 'id' => $deliveryId, 'st' => 'failed'])],   'color' => 'negative'],
            ],
        ],
    ];
}

/**
 * Inline-клавиатура «Связь и маршрут» — кнопки «Позвонить» и «Маршрут на карте».
 * VK не умеет HTML-ссылки в тексте, поэтому tel:/карту выносим в open_link-кнопки
 * (аналог ссылок «Позвонить»/«Открыть на карте» в Telegram-боте).
 *
 * Возвращает null, если нет ни телефона, ни координат.
 */
function vk_contact_keyboard(array $d): ?array
{
    $rows = [];

    $phone       = trim((string)($d['phone'] ?? ''));
    $phoneDigits = preg_replace('~[^\d+]~', '', $phone);
    if ($phoneDigits !== '') {
        $rows[] = [[
            'action' => [
                'type'  => 'open_link',
                'link'  => 'tel:' . $phoneDigits,
                'label' => '📞 Позвонить получателю',
            ],
        ]];
    }

    $lat = $d['address_lat'] ?? null;
    $lon = $d['address_lon'] ?? null;
    if ($lat !== null && $lat !== '' && $lon !== null && $lon !== '') {
        $latF = (float)$lat;
        $lonF = (float)$lon;
        $rows[] = [[
            'action' => [
                'type'  => 'open_link',
                'link'  => "https://yandex.ru/maps/?pt={$lonF},{$latF}&z=17&l=map",
                'label' => '🗺 Маршрут на карте',
            ],
        ]];
    }

    if (!$rows) {
        return null;
    }

    return ['inline' => true, 'buttons' => $rows];
}

/**
 * Отправляет отдельным сообщением кнопки «Позвонить»/«Маршрут» для доставки.
 * Шлётся вторым сообщением после карточки, чтобы не упереться в лимит inline-кнопок
 * (у клавиатуры статусов он уже почти исчерпан) и чтобы возможная ошибка ссылки
 * не сломала основную карточку с кнопками действий.
 */
function vk_send_contact_actions(int $peerId, array $d): void
{
    $kb = vk_contact_keyboard($d);
    if ($kb === null) {
        return;
    }
    $id = (int)($d['id'] ?? $d['delivery_id'] ?? 0);
    flora_vk_send_message($peerId, "📍 Связь и маршрут по доставке #{$id}:", $kb);
}

/**
 * Текстовая карточка доставки (без HTML — VK не поддерживает HTML-теги).
 */
function vk_delivery_card(array $d): string
{
    $km      = isset($d['distance_km_est']) && $d['distance_km_est'] !== null
        ? number_format((float)$d['distance_km_est'], 1, ',', ' ') . ' км'
        : '—';
    $fee     = number_format((float)($d['courier_fee'] ?? 0), 0, ',', ' ');
    $comment = !empty($d['comment']) ? "Комментарий: " . $d['comment'] . "\n" : '';

    return
        "📦 Доставка #" . (int)$d['id'] . "\n" .
        "Заказ: #" . (int)$d['order_id'] . "\n" .
        "Статус: " . vk_delivery_status_label((string)$d['status']) . "\n" .
        "Получатель: " . ($d['recipient_name'] ?? '—') . "\n" .
        "Телефон: " . ($d['phone'] ?? '') . "\n" .
        "Адрес: " . ($d['address'] ?? '') . "\n" .
        "Слот: " . ($d['delivery_slot'] ?? '—') . "\n" .
        $comment .
        "Сумма заказа: " . number_format((float)($d['total'] ?? 0), 0, ',', ' ') . " ₽\n" .
        "Оплата курьеру: {$fee} ₽\n" .
        "Расстояние: {$km}";
}

function vk_delivery_status_label(string $status): string
{
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

/**
 * Уведомить всех активных курьеров с VK о новой доставке.
 */
function notifyActiveCouriersAboutDeliveryVK(PDO $pdo, int $deliveryId): array
{
    $st = $pdo->prepare("
        SELECT
            d.id AS delivery_id,
            d.order_id,
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
        WHERE d.id = ?
        LIMIT 1
    ");
    $st->execute([$deliveryId]);
    $delivery = $st->fetch(PDO::FETCH_ASSOC);

    if (!$delivery) {
        return ['ok' => false, 'error' => 'delivery not found'];
    }

    $stC = $pdo->query("
        SELECT id, name, vk_user_id
        FROM users
        WHERE role = 'courier'
          AND on_shift = TRUE
          AND vk_user_id IS NOT NULL
        ORDER BY id ASC
    ");
    $couriers = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $text =
        "🌸 Новая доставка #" . (int)$delivery['delivery_id'] . "\n" .
        "Заказ: #" . (int)$delivery['order_id'] . "\n" .
        "Получатель: " . ($delivery['recipient_name'] ?? '—') . "\n" .
        "Телефон: " . ($delivery['phone'] ?? '') . "\n" .
        "Адрес: " . ($delivery['address'] ?? '') . "\n" .
        "Слот: " . ($delivery['delivery_slot'] ?? '—') . "\n" .
        (!empty($delivery['comment']) ? "Комментарий: " . $delivery['comment'] . "\n" : '') .
        "Сумма заказа: " . number_format((float)($delivery['total'] ?? 0), 0, ',', ' ') . " ₽\n" .
        "Оплата курьеру: " . number_format((float)($delivery['courier_fee'] ?? 0), 0, ',', ' ') . " ₽\n\n" .
        "Хотите взять эту доставку?";

    $results = [];
    foreach ($couriers as $c) {
        $results[] = [
            'courier_id' => (int)$c['id'],
            'vk_user_id' => (int)$c['vk_user_id'],
            'message'    => flora_vk_send_message(
                (int)$c['vk_user_id'],
                $text,
                vk_take_keyboard((int)$delivery['delivery_id'])
            ),
        ];
        // Вторым сообщением — кнопки «Позвонить»/«Маршрут».
        vk_send_contact_actions((int)$c['vk_user_id'], $delivery);
    }

    flora_vk_log('notifyActiveCouriersAboutDeliveryVK', $results);

    return [
        'ok'             => true,
        'delivery_id'    => $deliveryId,
        'couriers_count' => count($couriers),
        'results'        => $results,
    ];
}
