<?php
require_once __DIR__ . '/telegram_config.php';

function flora_tg_log(string $title, $data = null): void {
    $log = __DIR__ . '/telegram_debug.log';
    $text = '[' . date('Y-m-d H:i:s') . '] ' . $title . PHP_EOL;

    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $text .= print_r($data, true);
        } else {
            $text .= (string)$data;
        }
        $text .= PHP_EOL;
    }

    $text .= str_repeat('-', 60) . PHP_EOL;
    file_put_contents($log, $text, FILE_APPEND);
}

function flora_tg_api(string $method, array $data = []): array {
    if (FLORA_BOT_TOKEN === '') {
        return ['ok' => false, 'description' => 'Telegram bot token is not configured'];
    }
    $url = 'https://api.telegram.org/bot' . FLORA_BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if (!FLORA_BOT_SSL_VERIFY) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        $out = [
            'ok' => false,
            'error' => $err,
            'method' => $method,
            'http_code' => $http,
        ];
        flora_tg_log('flora_tg_api CURL ERROR', $out);
        return $out;
    }

    $decoded = json_decode((string)$res, true);
    if (!is_array($decoded)) {
        $out = [
            'ok' => false,
            'error' => 'Invalid JSON from Telegram',
            'raw' => $res,
            'method' => $method,
            'http_code' => $http,
        ];
        flora_tg_log('flora_tg_api INVALID JSON', $out);
        return $out;
    }

    $decoded['_http_code'] = $http;
    flora_tg_log('flora_tg_api RESPONSE', $decoded);
    return $decoded;
}

function flora_send_message(int|string $chatId, string $text, ?array $replyMarkup = null): array {
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }

    return flora_tg_api('sendMessage', $data);
}

function flora_send_location(int|string $chatId, float $lat, float $lon): array {
    return flora_tg_api('sendLocation', [
        'chat_id' => $chatId,
        'latitude' => $lat,
        'longitude' => $lon,
    ]);
}

function flora_delivery_text(array $d): string {
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $km = isset($d['distance_km_est']) && $d['distance_km_est'] !== null
        ? number_format((float)$d['distance_km_est'], 1, ',', ' ') . ' км'
        : '—';
    $fee = number_format((float)($d['courier_fee'] ?? 0), 0, ',', ' ');

    $phone = trim((string)($d['phone'] ?? ''));
    $phoneDigits = preg_replace('~[^\d+]~', '', $phone);
    $phoneLine = $phone !== ''
        ? "📞 Телефон: <a href=\"tel:".$esc($phoneDigits)."\"><b>".$esc($phone)."</b></a>\n"
        : '';

    $addrLine = "📍 Адрес: <b>".$esc($d['address'] ?? '—')."</b>\n";
    if (!empty($d['address_lat']) && !empty($d['address_lon'])) {
        $lat = (float)$d['address_lat'];
        $lon = (float)$d['address_lon'];
        $addrLine .= "🗺 <a href=\"https://yandex.ru/maps/?pt={$lon},{$lat}&z=17&l=map\">Открыть на карте</a>\n";
    }

    $commentLine = !empty($d['comment'])
        ? "💬 Комментарий: <b>".$esc($d['comment'])."</b>\n"
        : '';

    return
        "🆕 <b>Новая доставка #".(int)$d['delivery_id']."</b>  ·  заказ #".(int)$d['order_id']."\n" .
        "➖➖➖➖➖➖➖➖➖➖\n" .
        "👤 Получатель: <b>".$esc($d['recipient_name'] ?? '—')."</b>\n" .
        $phoneLine .
        $addrLine .
        "🕐 Слот: <b>".$esc($d['delivery_slot'] ?? '—')."</b>\n" .
        $commentLine .
        "➖➖➖➖➖➖➖➖➖➖\n" .
        "💰 Сумма заказа: <b>".number_format((float)($d['total'] ?? 0), 0, ',', ' ')." ₽</b>\n" .
        "🤝 Вам за доставку: <b>{$fee} ₽</b>\n" .
        "📏 Расстояние: <b>{$km}</b>\n\n" .
        "Нажмите «Взять доставку», если готовы выполнить заказ.";
}

/**
 * Уведомляет персонал магазина (владельца + всех пользователей с ролью admin/manager,
 * у кого привязан Telegram) о новом заказе. Курьеры здесь НЕ участвуют — им уведомление
 * уходит только после ручной передачи кнопкой «Передать курьерам».
 */
function notifyStaffAboutNewOrder(PDO $pdo, string $text): array {
    $chatIds = [];
    if (defined('FLORA_OWNER_CHAT_ID') && FLORA_OWNER_CHAT_ID !== '') {
        $chatIds[(string)FLORA_OWNER_CHAT_ID] = true;
    }
    try {
        $st = $pdo->query("
            SELECT telegram_chat_id
            FROM users
            WHERE role IN ('admin', 'manager')
              AND telegram_chat_id IS NOT NULL
        ");
        foreach (($st ? $st->fetchAll(PDO::FETCH_COLUMN) : []) as $cid) {
            if ($cid !== null && (string)$cid !== '') $chatIds[(string)$cid] = true;
        }
    } catch (Throwable $e) {
        flora_tg_log('notifyStaffAboutNewOrder query error', $e->getMessage());
    }

    $results = [];
    foreach (array_keys($chatIds) as $cid) {
        $results[$cid] = flora_send_message($cid, $text);
    }
    flora_tg_log('notifyStaffAboutNewOrder', ['count' => count($chatIds), 'chat_ids' => array_keys($chatIds)]);
    return ['ok' => true, 'count' => count($chatIds), 'results' => $results];
}

function notifyActiveCouriersAboutDelivery(PDO $pdo, int $deliveryId): array {
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
        SELECT id, name, telegram_chat_id
        FROM users
        WHERE role = 'courier'
          AND on_shift = TRUE
          AND telegram_chat_id IS NOT NULL
        ORDER BY id ASC
    ");
    $couriers = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($couriers as $c) {
        $row = [
            'courier_id' => (int)$c['id'],
            'chat_id' => (int)$c['telegram_chat_id'],
            'message' => flora_send_message(
                (int)$c['telegram_chat_id'],
                flora_delivery_text($delivery),
                [
                    'inline_keyboard' => [
                        [
                            ['text' => '📦 Взять доставку', 'callback_data' => 'take:' . (int)$delivery['delivery_id']]
                        ],
                        [
                            ['text' => '📋 Все свободные доставки', 'callback_data' => 'showfree']
                        ]
                    ]
                ]
            )
        ];

        if (!empty($delivery['address_lat']) && !empty($delivery['address_lon'])) {
            $row['location'] = flora_send_location(
                (int)$c['telegram_chat_id'],
                (float)$delivery['address_lat'],
                (float)$delivery['address_lon']
            );
        }

        $results[] = $row;
    }

    flora_tg_log('notifyActiveCouriersAboutDelivery', $results);

    return [
        'ok' => true,
        'delivery_id' => $deliveryId,
        'couriers_count' => count($couriers),
        'results' => $results
    ];
}
