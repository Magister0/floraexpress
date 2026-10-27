<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FloraExpress — API для Telegram Mini App курьера
|--------------------------------------------------------------------------
| Принимает JSON: { action, initData, delivery_id?, status?, on? }
| Аутентификация — по подписи initData из Telegram WebApp.
*/

require_once __DIR__ . '/webapp_courier_lib.php';
require_once __DIR__ . '/courier_location_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wa_log(string $title, $data = null): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $title;
    if ($data !== null) {
        $line .= ' ' . (is_scalar($data) ? (string) $data : json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    @file_put_contents(__DIR__ . '/webapp_courier_debug.log', $line . PHP_EOL, FILE_APPEND);
}

function wa_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode((string) $raw, true);
if (!is_array($req)) {
    $req = $_POST;
}

$action   = (string) ($req['action'] ?? '');
$initData = (string) ($req['initData'] ?? ($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ''));

$auth = wa_validate_init_data($initData, FLORA_BOT_TOKEN);
if ($auth === null || empty($auth['user']['id'])) {
    $dbg = wa_auth_debug($initData, FLORA_BOT_TOKEN);
    $dbg['init_len'] = strlen($initData);
    wa_log('AUTH FAILED', $dbg);
    wa_json([
        'ok' => false,
        'error' => 'auth_failed',
        'message' => 'Вход не подтверждён. поля: [' . $dbg['keys'] . '] '
            . 'sig:' . $dbg['has_signature']
            . ' matchNoSig:' . $dbg['match_no_sig']
            . ' matchWithSig:' . $dbg['match_with_sig']
            . ' bot:' . $dbg['bot_id'],
        'debug' => $dbg,
    ], 401);
}

try {
    $pdo = db();
    $tgId = (int) $auth['user']['id'];
    $courier = wa_find_courier($pdo, $tgId);

    if (!$courier) {
        wa_log('NOT COURIER', ['tg_id' => $tgId]);
        wa_json([
            'ok' => false,
            'error' => 'not_courier',
            'message' => 'Этот аккаунт (id ' . $tgId . ') не привязан как курьер FloraExpress.',
            'telegram_id' => $tgId,
        ], 403);
    }

    $courierId = (int) $courier['id'];
    wa_log('REQUEST', ['action' => $action, 'tg_id' => $tgId, 'courier_id' => $courierId]);

    // Поддерживаем актуальный chat_id, как и бот.
    $pdo->prepare("UPDATE users SET telegram_chat_id = ? WHERE id = ? AND telegram_chat_id IS DISTINCT FROM ?")
        ->execute([$tgId, $courierId, $tgId]);

    switch ($action) {
        case 'state':
            wa_json([
                'ok' => true,
                'courier' => ['id' => $courierId, 'name' => $courier['name'], 'on_shift' => (bool) $courier['on_shift']],
                'free' => wa_free_deliveries($pdo),
                'mine' => wa_my_deliveries($pdo, $courierId),
                'stats' => wa_courier_stats($pdo, $courierId),
                'shop' => ['lat' => FLORA_SHOP_LAT, 'lon' => FLORA_SHOP_LON],
            ]);
            break;

        case 'shift':
            $on = !empty($req['on']);
            wa_set_shift($pdo, $courierId, $on);
            wa_json(['ok' => true, 'on_shift' => $on]);
            break;

        case 'take':
            $deliveryId = (int) ($req['delivery_id'] ?? 0);
            if (!(bool) $courier['on_shift']) {
                wa_json(['ok' => false, 'error' => 'off_shift', 'message' => 'Сначала выйдите на смену.'], 409);
            }
            $ok = wa_take_delivery($pdo, $deliveryId, $courierId);
            wa_json([
                'ok' => $ok,
                'error' => $ok ? null : 'already_taken',
                'message' => $ok ? 'Доставка ваша!' : 'Эту доставку уже взял другой курьер.',
            ]);
            break;

        case 'status':
            $deliveryId = (int) ($req['delivery_id'] ?? 0);
            $status = (string) ($req['status'] ?? '');

            // Геозона: отметить «Доставлен» можно только в пределах 100 м от адреса.
            if ($status === 'delivered') {
                $stA = $pdo->prepare("
                    SELECT o.address_lat, o.address_lon
                    FROM deliveries d JOIN orders o ON o.id = d.order_id
                    WHERE d.id = ? AND d.courier_id = ? LIMIT 1
                ");
                $stA->execute([$deliveryId, $courierId]);
                $addr = $stA->fetch(PDO::FETCH_ASSOC);
                if ($addr && !empty($addr['address_lat']) && !empty($addr['address_lon'])) {
                    $lat = isset($req['lat']) ? (float) $req['lat'] : null;
                    $lon = isset($req['lon']) ? (float) $req['lon'] : null;
                    if ($lat === null || $lon === null) {
                        wa_json(['ok' => false, 'error' => 'need_location',
                            'message' => 'Не удалось определить вашу геопозицию. Разрешите доступ и попробуйте снова.'], 409);
                    }
                    $dist = wa_haversine_m($lat, $lon, (float) $addr['address_lat'], (float) $addr['address_lon']);
                    if ($dist > 100) {
                        wa_json(['ok' => false, 'error' => 'too_far',
                            'message' => 'Вы примерно в ' . round($dist) . ' м от адреса. Отметить «Доставлен» можно в пределах 100 м.'], 409);
                    }
                }
            }

            $res = wa_apply_status($pdo, $deliveryId, $courierId, $status);
            wa_json($res, !empty($res['ok']) ? 200 : 409);
            break;

        case 'location':
            $lat = isset($req['lat']) ? (float) $req['lat'] : 0.0;
            $lon = isset($req['lon']) ? (float) $req['lon'] : 0.0;
            if (abs($lat) < 0.0001 && abs($lon) < 0.0001) {
                wa_json(['ok' => false, 'error' => 'bad_coords', 'message' => 'Некорректные координаты.'], 400);
            }
            $opts = ['source' => 'webapp', 'live_period' => 3600];
            if (isset($req['accuracy'])) { $opts['accuracy'] = (float) $req['accuracy']; }
            if (isset($req['heading']) && $req['heading'] !== null) { $opts['heading'] = (float) $req['heading']; }
            if (isset($req['speed']) && $req['speed'] !== null) { $opts['speed_kmh'] = (float) $req['speed']; }
            courier_location_update($pdo, $courierId, $lat, $lon, $opts);
            wa_json(['ok' => true]);
            break;

        default:
            wa_json(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (Throwable $e) {
    wa_log('EXCEPTION', ['action' => $action, 'msg' => $e->getMessage(), 'where' => $e->getFile() . ':' . $e->getLine()]);
    wa_json([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Ошибка на сервере: ' . $e->getMessage(),
    ], 500);
}
