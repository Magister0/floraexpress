<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FloraExpress — библиотека Telegram Mini App для курьера
|--------------------------------------------------------------------------
| Самодостаточный модуль: используется только мини-приложением курьера
| (webapp_courier.php / webapp_courier_api.php) и НЕ влияет на работу
| кнопочного бота (telegram_bot.php). Откат = удалить webapp_courier_* файлы.
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_config.php';

/**
 * Проверка подлинности initData из Telegram WebApp (HMAC-SHA256).
 * Возвращает массив данных (с декодированным user) или null, если подпись неверна.
 *
 * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
function wa_validate_init_data(string $initData, string $botToken, int $maxAgeSeconds = 86400): ?array
{
    if ($initData === '') {
        return null;
    }

    // Ручной разбор, чтобы не искажать ключи и сохранить декодированные значения.
    $data = [];
    foreach (explode('&', $initData) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $data[$k] = urldecode($v);
    }

    $hash = $data['hash'] ?? '';
    if ($hash === '') {
        return null;
    }

    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);

    $buildAndHash = static function (array $d) use ($secretKey): string {
        unset($d['hash']);
        ksort($d);
        $pairs = [];
        foreach ($d as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return bin2hex(hash_hmac('sha256', implode("\n", $pairs), $secretKey, true));
    };

    // Принимаем подпись, если совпал ЛЮБОЙ из двух вариантов строки проверки:
    //  1) со всеми полями, кроме hash — классический алгоритм Telegram;
    //  2) дополнительно без signature — для новых клиентов, где signature
    //     (Ed25519 для сторонней проверки) исключается из HMAC по hash.
    // Подделать любой из вариантов без токена бота нельзя — это безопасно.
    $withoutSignature = $data;
    unset($withoutSignature['signature']);

    $okSignature = hash_equals($buildAndHash($data), $hash)
        || hash_equals($buildAndHash($withoutSignature), $hash);

    if (!$okSignature) {
        return null;
    }

    // Защита от повторного использования старых initData.
    if ($maxAgeSeconds > 0 && isset($data['auth_date'])) {
        $authDate = (int) $data['auth_date'];
        if ($authDate > 0 && (time() - $authDate) > $maxAgeSeconds) {
            return null;
        }
    }

    if (isset($data['user'])) {
        $user = json_decode($data['user'], true);
        $data['user'] = is_array($user) ? $user : null;
    }

    return $data;
}

/**
 * Диагностика подписи initData: считает hash двумя способами и сообщает,
 * какой совпал. Помогает понять причину auth_failed без угадывания.
 */
function wa_auth_debug(string $initData, string $botToken): array
{
    $data = [];
    foreach (explode('&', $initData) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $data[$k] = urldecode($v);
    }
    $hash = $data['hash'] ?? '';

    $build = static function (array $d): string {
        ksort($d);
        $p = [];
        foreach ($d as $k => $v) {
            $p[] = $k . '=' . $v;
        }
        return implode("\n", $p);
    };
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

    // Вариант A: исключаем hash И signature.
    $a = $data; unset($a['hash'], $a['signature']);
    $hashA = bin2hex(hash_hmac('sha256', $build($a), $secret, true));

    // Вариант B: исключаем только hash (signature остаётся).
    $b = $data; unset($b['hash']);
    $hashB = bin2hex(hash_hmac('sha256', $build($b), $secret, true));

    return [
        'keys'           => implode(',', array_keys($data)),
        'has_signature'  => isset($data['signature']) ? 1 : 0,
        'match_no_sig'   => ($hash !== '' && hash_equals($hashA, $hash)) ? 1 : 0,
        'match_with_sig' => ($hash !== '' && hash_equals($hashB, $hash)) ? 1 : 0,
        'recv_hash'      => substr($hash, 0, 10),
        'bot_id'         => explode(':', $botToken)[0] ?? '',
    ];
}

/**
 * Поиск курьера по Telegram user id (та же схема привязки, что и в боте).
 */
function wa_find_courier(PDO $pdo, int|string $telegramUserId): ?array
{
    $st = $pdo->prepare("
        SELECT id, name, role, email, phone, telegram_chat_id, on_shift
        FROM users
        WHERE role = 'courier'
          AND (phone = ? OR email = ?)
        LIMIT 1
    ");
    $st->execute([(string) $telegramUserId, $telegramUserId . '@tg.local']);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function wa_set_shift(PDO $pdo, int $courierId, bool $onShift): void
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

function wa_free_deliveries(PDO $pdo): array
{
    // Заказ виден курьерам во «Свободных» только после того,
    // как менеджер/админ передал его курьерам (флаг orders.released_to_courier).
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
        LIMIT 30
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function wa_my_deliveries(PDO $pdo, int $courierId): array
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
        LIMIT 50
    ");
    $st->execute([$courierId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function wa_courier_stats(PDO $pdo, int $courierId): array
{
    $st = $pdo->prepare("
        SELECT COUNT(*)::int AS delivered_count,
               COALESCE(SUM(courier_fee), 0)    AS earned_total,
               COALESCE(SUM(distance_km_est), 0) AS distance_total
        FROM deliveries
        WHERE courier_id = ? AND status = 'delivered'
    ");
    $st->execute([$courierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'delivered_count' => (int) ($row['delivered_count'] ?? 0),
        'earned_total'    => (float) ($row['earned_total'] ?? 0),
        'distance_total'  => (float) ($row['distance_total'] ?? 0),
    ];
}

function wa_take_delivery(PDO $pdo, int $deliveryId, int $courierId): bool
{
    $st = $pdo->prepare("
        UPDATE deliveries
        SET courier_id = ?, status = 'assigned',
            assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP)
        WHERE id = ? AND courier_id IS NULL AND status = 'pending'
    ");
    $st->execute([$courierId, $deliveryId]);
    if ($st->rowCount() > 0) {
        wa_add_event($pdo, $deliveryId, 'assigned', ['by' => 'webapp', 'courier_id' => $courierId]);
        return true;
    }
    return false;
}

function wa_add_event(PDO $pdo, int $deliveryId, string $event, array $meta = []): void
{
    $pdo->prepare("
        INSERT INTO delivery_events (delivery_id, event, meta_json)
        VALUES (?, ?, ?)
    ")->execute([$deliveryId, $event, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

/**
 * Смена статуса доставки. Курьер может менять только СВОЮ доставку.
 */
function wa_apply_status(PDO $pdo, int $deliveryId, int $courierId, string $status): array
{
    $allowed = ['assigned', 'picked', 'en_route', 'delivered', 'failed'];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'error' => 'invalid status'];
    }

    $st = $pdo->prepare("SELECT * FROM deliveries WHERE id = ? AND courier_id = ? LIMIT 1");
    $st->execute([$deliveryId, $courierId]);
    $delivery = $st->fetch(PDO::FETCH_ASSOC);
    if (!$delivery) {
        return ['ok' => false, 'error' => 'delivery not found'];
    }

    $orderId = (int) $delivery['order_id'];

    if ($status === 'delivered') {
        $pdo->prepare("UPDATE deliveries SET status = ?, delivered_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$status, $deliveryId]);
        $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?")->execute([$orderId]);
    } else {
        $pdo->prepare("UPDATE deliveries SET status = ? WHERE id = ?")->execute([$status, $deliveryId]);
        // «Забрал» теперь означает, что курьер выехал к клиенту → заказ «в доставке».
        if ($status === 'en_route' || $status === 'picked') {
            $pdo->prepare("UPDATE orders SET status = 'on_delivery' WHERE id = ?")->execute([$orderId]);
        }
    }

    wa_add_event($pdo, $deliveryId, 'status', ['new' => $status, 'by' => 'webapp', 'order_id' => $orderId]);

    return ['ok' => true, 'delivery_id' => $deliveryId, 'status' => $status, 'order_id' => $orderId];
}

/** Расстояние между двумя точками в метрах (формула гаверсинуса). */
function wa_haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function wa_status_label(string $status): string
{
    return [
        'pending'   => 'Ожидает',
        'assigned'  => 'Назначен',
        'picked'    => 'Забран',
        'en_route'  => 'В пути',
        'delivered' => 'Доставлен',
        'failed'    => 'Не доставлен',
    ][$status] ?? $status;
}
