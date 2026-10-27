<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/map_config.php';
require_once __DIR__ . '/promo_lib.php';
require_once __DIR__ . '/telegram_notify.php';
require_once __DIR__ . '/vk_notify.php';
require_once __DIR__ . '/cart_store.php';
require_once __DIR__ . '/extra_services_lib.php';
require_once __DIR__ . '/audit_log_lib.php';
require_once __DIR__ . '/pricing_lib.php';
require_once __DIR__ . '/delivery_lib.php';

$pdo = db();
promo_ensure_schema($pdo);
extra_services_bootstrap($pdo);
delivery_ensure_schema($pdo);
cart_store_sync();

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadius * $c, 2);
}
function placeholder_image(): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="100%" height="100%" rx="18" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="12" fill="#9ca3af">Нет фото</text></svg>');
}

function normalize_ru_phone(string $phone): string {
    $digits = preg_replace('~\D+~', '', $phone) ?? '';
    if ($digits === '') throw new RuntimeException('Укажите телефон.');
    if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
    if (strlen($digits) === 10) $digits = '7' . $digits;
    if (strlen($digits) !== 11 || $digits[0] !== '7') {
        throw new RuntimeException('Телефон указан в неверном формате.');
    }
    return '+7 (' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7, 2) . '-' . substr($digits, 9, 2);
}

function validate_short_text(string $value, string $fieldLabel, int $max = 40, string $pattern = '~^[\p{L}\d\s\-\/№#.,]+$~u'): string {
    $value = trim(preg_replace('~\s+~u', ' ', $value) ?? '');
    if ($value === '') return '';
    if (str_len_safe($value) > $max) throw new RuntimeException($fieldLabel . ' заполнено слишком длинно.');
    if (!preg_match($pattern, $value)) throw new RuntimeException($fieldLabel . ' содержит недопустимые символы.');
    return $value;
}
function compose_order_comment(string $deliveryMethod, string $apartment, string $entrance, string $floor, string $intercom, string $comment): ?string {
    $parts = [];
    if ($deliveryMethod === 'pickup') $parts[] = 'Способ: Самовывоз';
    if ($apartment !== '') $parts[] = 'Квартира: ' . preg_replace('~\s+~u', ' ', $apartment);
    if ($entrance !== '') $parts[] = 'Подъезд: ' . preg_replace('~\s+~u', ' ', $entrance);
    if ($floor !== '') $parts[] = 'Этаж: ' . preg_replace('~\s+~u', ' ', $floor);
    if ($intercom !== '') $parts[] = 'Домофон: ' . preg_replace('~\s+~u', ' ', $intercom);
    if ($comment !== '') $parts[] = 'Комментарий: ' . preg_replace('~\s+~u', ' ', $comment);
    return $parts ? implode('; ', $parts) : null;
}
function normalize_service_ids($raw): array {
    $ids = is_array($raw) ? $raw : [];
    $ids = array_map('intval', $ids);
    $ids = array_values(array_unique(array_filter($ids, static fn($x) => $x > 0)));
    sort($ids);
    return $ids;
}
function normalize_service_messages($raw): array {
    $result = [];
    if (!is_array($raw)) return $result;
    foreach ($raw as $id => $text) {
        $sid = (int)$id;
        if ($sid <= 0) continue;
        $result[$sid] = trim((string)$text);
    }
    return $result;
}

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('checkout.php'));
    exit;
}
$userId = (int)($me['id'] ?? 0);

$cart = cart_store_get();
$ids = array_map('intval', array_keys($cart));
$items = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, price, old_price, image, is_active, category FROM products WHERE id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $pid = (int)$r['id'];
        $qty = max(0, (int)($cart[$pid] ?? 0));
        if ($qty <= 0 || (int)$r['is_active'] !== 1) continue;
        $priceView = pricing_view($r, $pdo);
        $price = (float)$priceView['price'];
        $items[] = [
            'id'         => $pid,
            'name'       => (string)$r['name'],
            'price'      => $price,
            'base_price' => (float)$priceView['base'],
            'badge_html' => (string)$priceView['badge_html'],
            'image'      => (string)($r['image'] ?? ''),
            'qty'        => $qty,
            'sum'        => $price * $qty,
            'category'   => (string)($r['category'] ?? ''),
        ];
    }
}
$productSubtotal = (float)array_sum(array_column($items, 'sum'));
$hasBalloons = !empty(array_filter($items, function($i) {
    $c = mb_strtolower(trim($i['category']));
    return str_contains($c, 'шар') || str_contains($c, 'balloon');
}));
$placeholder = placeholder_image();

if (!$items) {
    ?>
    <!doctype html><html lang="ru"><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Оформление заказа — FloraExpress</title>
      <link rel="stylesheet" href="styles.css">
    </head><body>
    <?php include __DIR__ . '/header.php'; ?>
    <section class="section"><div class="container"><h1 class="section__title">Оформление заказа</h1><p class="mute">Корзина пуста или товары недоступны.</p><a class="btn" href="catalog.php">В каталог</a></div></section>
    <?php include __DIR__ . '/footer.php'; ?>
    </body></html>
    <?php
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

$err = null;
$promoError = null;
$promoMessage = null;
$userPhone = '';
$userName = (string)($me['name'] ?? '');
$addressBook = [];
$defaultAddress = null;
$shopLat = defined('FLORA_SHOP_LAT') ? (float)FLORA_SHOP_LAT : 56.8389;
$shopLon = defined('FLORA_SHOP_LON') ? (float)FLORA_SHOP_LON : 60.6057;
$shopAddress = defined('FLORA_SHOP_ADDRESS') ? (string)FLORA_SHOP_ADDRESS : 'г. Екатеринбург, ул. Цветочная, 10';
$hasDeliveryMethodColumn = column_exists($pdo, 'orders', 'delivery_method');
$hasPromoCodeColumn = column_exists($pdo, 'orders', 'promo_code');
$hasDiscountAmountColumn = column_exists($pdo, 'orders', 'discount_amount');
$hasSubtotalAmountColumn = column_exists($pdo, 'orders', 'subtotal_amount');
$hasTrackingToken = column_exists($pdo, 'orders', 'tracking_token');
$hasStockColumn = column_exists($pdo, 'products', 'stock');

// Колонки tracking_token и stock создаются через migrate_schema.php.
// Если их нет в БД — ниже флаги $hasTrackingToken/$hasStockColumn просто останутся false.

try {
    $st = $pdo->prepare('SELECT phone, name FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
        $userPhone = (string)($u['phone'] ?? '');
        if (!empty($u['name'])) {
            $_SESSION['user']['name'] = $u['name'];
            $userName = (string)$u['name'];
        }
    }
} catch (Throwable $e) {}

try {
    require_once __DIR__ . '/address_book_lib.php';
    address_book_ensure_columns($pdo);
    $stA = $pdo->prepare('SELECT id, label, address, lat, lon, is_default, apartment, entrance, floor, intercom FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC');
    $stA->execute([$userId]);
    $addressBook = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($addressBook as $a) {
        if (db_bool($a['is_default'] ?? false)) {
            $defaultAddress = $a;
            break;
        }
    }
    if (!$defaultAddress && $addressBook) $defaultAddress = $addressBook[0];
} catch (Throwable $e) {}

$currentMethod = $_POST['delivery_method'] ?? ($_SESSION['checkout_delivery_method'] ?? 'delivery');
if (!in_array($currentMethod, ['delivery', 'pickup'], true)) $currentMethod = 'delivery';
$_SESSION['checkout_delivery_method'] = $currentMethod;

$selectedServiceIds = normalize_service_ids($_SESSION['checkout_extra_service_ids'] ?? []);
$serviceMessages = normalize_service_messages($_SESSION['checkout_extra_service_messages'] ?? []);
$selectedServiceRows = extra_services_fetch_selected($pdo, $selectedServiceIds);
$validServiceIds = [];
foreach ($selectedServiceRows as $row) {
    $validServiceIds[] = (int)$row['id'];
}
if ($validServiceIds !== $selectedServiceIds) {
    $_SESSION['checkout_extra_service_ids'] = $validServiceIds;
    $selectedServiceIds = $validServiceIds;
    $serviceMessages = array_intersect_key($serviceMessages, array_flip($selectedServiceIds));
    $_SESSION['checkout_extra_service_messages'] = $serviceMessages;
}
$extraSubtotal = 0.0;
foreach ($selectedServiceRows as $serviceRow) {
    $extraSubtotal += (float)$serviceRow['price'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promo_action'])) {
    $promoAction = (string)$_POST['promo_action'];
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        $promoError = 'Сессия устарела. Обновите страницу.';
    } elseif ($promoAction === 'apply') {
        $code = promo_normalize_code((string)($_POST['promo_code'] ?? ''));
        if ($code === '') {
            $promoError = 'Введите промокод';
        } else {
            $promo = promo_fetch_by_code($pdo, $code);
            if (!$promo) {
                $promoError = 'Промокод не найден';
            } else {
                $calcTry = promo_validate($pdo, $promo, $productSubtotal, $currentMethod, $userId);
                if (!$calcTry['ok']) {
                    $promoError = implode(' ', $calcTry['errors']);
                } else {
                    $_SESSION['promo_code'] = $code;
                    $promoMessage = 'Промокод применён';
                }
            }
        }
    } elseif ($promoAction === 'remove') {
        unset($_SESSION['promo_code']);
        $promoMessage = 'Промокод удалён';
    }
}

$promoCalc = promo_calculate_from_session($pdo, $productSubtotal, $currentMethod, $userId);
$total = (float)($promoCalc['total'] ?? $productSubtotal) + $extraSubtotal;

// ---- Превью стоимости доставки (для отображения в сводке) ----
$displayDeliveryQuote = null;
if ($currentMethod === 'delivery') {
    $previewLat = isset($_POST['address_lat']) ? (float)$_POST['address_lat']
                : (isset($defaultAddress['lat']) ? (float)$defaultAddress['lat'] : 0.0);
    $previewLon = isset($_POST['address_lon']) ? (float)$_POST['address_lon']
                : (isset($defaultAddress['lon']) ? (float)$defaultAddress['lon'] : 0.0);
    $previewFragile = !empty(array_filter($items, fn($i) => stripos((string)($i['category'] ?? ''), 'balloon') !== false));
    if ($previewLat != 0.0 || $previewLon != 0.0) {
        $displayDeliveryQuote = delivery_calc_quote(
            $pdo, $previewLat, $previewLon, new DateTimeImmutable('now'), $total,
            ['fragile' => $previewFragile, 'urgent' => false]
        );
        if (!empty($displayDeliveryQuote['ok'])) {
            $total += (float)$displayDeliveryQuote['fee'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['promo_action'])) {
    try {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            throw new RuntimeException('Сессия устарела. Обновите страницу.');
        }

        $deliveryMethod = trim((string)($_POST['delivery_method'] ?? 'delivery'));
        if (!in_array($deliveryMethod, ['delivery', 'pickup'], true)) $deliveryMethod = 'delivery';
        $_SESSION['checkout_delivery_method'] = $deliveryMethod;

        $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $deliveryDate = trim((string)($deliveryMethod === 'pickup' ? ($_POST['pickup_date'] ?? '') : ($_POST['delivery_date'] ?? '')));
        $deliveryTime = trim((string)($deliveryMethod === 'pickup' ? ($_POST['pickup_time'] ?? '') : ($_POST['delivery_time'] ?? '')));
        $comment = trim((string)($_POST['comment'] ?? ''));
        $photoReportRequested = !empty($_POST['photo_report_requested']);
        $apartment = trim((string)($_POST['apartment'] ?? ''));
        $entrance = trim((string)($_POST['entrance'] ?? ''));
        $floor = trim((string)($_POST['floor'] ?? ''));
        $intercom = trim((string)($_POST['intercom'] ?? ''));
        $addressLat = isset($_POST['address_lat']) ? (float)$_POST['address_lat'] : 0.0;
        $addressLon = isset($_POST['address_lon']) ? (float)$_POST['address_lon'] : 0.0;

        if ($recipientName === '' || str_len_safe($recipientName) < 2 || !preg_match('~^[\p{L}\s\-]{2,}$~u', $recipientName)) throw new RuntimeException('Укажите корректное имя получателя.');
        if ($phone === '') throw new RuntimeException('Укажите телефон.');
        if ($deliveryDate === '') throw new RuntimeException($deliveryMethod === 'pickup' ? 'Выберите дату самовывоза.' : 'Выберите дату доставки.');
        if ($deliveryTime === '') throw new RuntimeException($deliveryMethod === 'pickup' ? 'Выберите время самовывоза.' : 'Выберите время доставки.');
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $deliveryDate)) throw new RuntimeException('Дата указана в неверном формате.');
        if (!preg_match('~^\d{2}:\d{2}$~', $deliveryTime)) throw new RuntimeException('Время указано в неверном формате.');
        $timeParts = explode(':', $deliveryTime);
        $hour = (int)($timeParts[0] ?? 0);
        $minute = (int)($timeParts[1] ?? 0);
        if ($hour < 10 || $hour > 21 || ($hour === 21 && $minute > 0)) {
            throw new RuntimeException($deliveryMethod === 'pickup' ? 'Время самовывоза должно быть с 10:00 до 21:00.' : 'Время доставки должно быть с 10:00 до 21:00.');
        }
        $slotTs = strtotime($deliveryDate . ' ' . $deliveryTime);
        if ($slotTs === false) throw new RuntimeException('Не удалось распознать дату и время.');
        if ($slotTs < time() - 300) throw new RuntimeException('Дата и время не могут быть в прошлом.');
        $slot = date('d.m.Y H:i', $slotTs);

        $phone = normalize_ru_phone($phone);
        $comment = trim($comment);
        if ($comment !== '' && str_len_safe($comment) > 400) throw new RuntimeException('Комментарий слишком длинный.');
        $apartment = validate_short_text($apartment, 'Квартира', 20);
        $entrance = validate_short_text($entrance, 'Подъезд', 20);
        $floor = validate_short_text($floor, 'Этаж', 10, '~^[\d\-]{1,10}$~u');
        $intercom = validate_short_text($intercom, 'Домофон', 20, '~^[\p{L}\d\s\-#*]+$~u');

        if ($deliveryMethod === 'pickup') {
            $address = $shopAddress;
            $addressLat = $shopLat;
            $addressLon = $shopLon;
            $apartment = $entrance = $floor = $intercom = '';
        } else {
            if ($address === '' || str_len_safe($address) < 8) throw new RuntimeException('Укажите полный адрес доставки.');
            if ($addressLat == 0.0 || $addressLon == 0.0) throw new RuntimeException('Выберите адрес на карте.');
            if ($apartment === '') throw new RuntimeException('Укажите номер квартиры.');
            if ($entrance === '') throw new RuntimeException('Укажите номер подъезда.');
            if ($floor === '') throw new RuntimeException('Укажите этаж.');
            if ($intercom === '') throw new RuntimeException('Укажите код домофона (если его нет, напишите «нет»).');
        }

        $cart = cart_store_get();
        $ids = array_map('intval', array_keys($cart));
        if (!$ids) throw new RuntimeException('Корзина пуста.');

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stP = $pdo->prepare("SELECT id, price, old_price, category, is_active FROM products WHERE id IN ($in)");
        $stP->execute($ids);
        $prows = $stP->fetchAll(PDO::FETCH_ASSOC);
        $pmap = [];
        foreach ($prows as $r) $pmap[(int)$r['id']] = $r;

        $orderItems = [];
        $subtotal = 0.0;
        $pricingRulesApplied = [];
        foreach ($cart as $pid => $qty) {
            $pid = (int)$pid; $qty = (int)$qty;
            if ($qty <= 0 || !isset($pmap[$pid]) || (int)$pmap[$pid]['is_active'] !== 1) continue;
            $priceInfo = pricing_apply($pmap[$pid], $pdo);
            $price = (float)$priceInfo['effective'];
            $orderItems[] = ['pid' => $pid, 'qty' => $qty, 'price' => $price, 'base_price' => (float)$priceInfo['base']];
            $subtotal += $price * $qty;
            foreach ($priceInfo['rules'] as $rule) {
                $pricingRulesApplied[(int)$rule['id']] = $rule['name'];
            }
        }
        if (!$orderItems) throw new RuntimeException('В корзине нет доступных товаров.');

        $selectedServiceIds = normalize_service_ids($_SESSION['checkout_extra_service_ids'] ?? []);
        $serviceMessages = normalize_service_messages($_SESSION['checkout_extra_service_messages'] ?? []);
        $selectedServiceRows = extra_services_fetch_selected($pdo, $selectedServiceIds);
        $extraSubtotal = 0.0;
        foreach ($selectedServiceRows as $serviceRow) {
            $extraSubtotal += (float)$serviceRow['price'];
        }

        $promoCalc = promo_calculate_from_session($pdo, $subtotal, $deliveryMethod, $userId);
        $discountAmount = (float)($promoCalc['discount'] ?? 0.0);
        $finalTotal = (float)($promoCalc['total'] ?? $subtotal) + $extraSubtotal;
        $promoCode = ($promoCalc['hasPromo'] ?? false) ? (string)($promoCalc['code'] ?? '') : null;
        $promoId = ($promoCalc['hasPromo'] ?? false) ? (int)(($promoCalc['promo']['id'] ?? 0)) : 0;

        $distanceKm = $deliveryMethod === 'delivery' ? haversineKm($shopLat, $shopLon, $addressLat, $addressLon) : 0.0;
        $deliveryFee = 0.0;
        $courierFee  = 0.0;
        $deliveryQuote = null;

        if ($deliveryMethod === 'delivery') {
            $slotWhen = null;
            try {
                if ($slot && preg_match('~(\d{4}-\d{2}-\d{2}).*?(\d{1,2}[:.]\d{2})~', $slot, $mm)) {
                    $slotWhen = new DateTimeImmutable($mm[1] . ' ' . str_replace('.', ':', $mm[2]));
                }
            } catch (Throwable $e) { $slotWhen = null; }

            $isUrgent = false;
            if ($slotWhen !== null) {
                $diffMin = ($slotWhen->getTimestamp() - time()) / 60;
                $isUrgent = $diffMin > 0 && $diffMin <= 120;
            }

            $hasFragile = !empty(array_filter($orderItems, function($oi) use ($pmap) {
                $cat = (string)($pmap[$oi['pid']]['category'] ?? '');
                return stripos($cat, 'balloon') !== false;
            }));

            $deliveryQuote = delivery_calc_quote(
                $pdo, $addressLat, $addressLon,
                $slotWhen ?? new DateTimeImmutable('now'),
                (float)$subtotal + (float)$extraSubtotal - (float)$discountAmount,
                ['urgent' => $isUrgent, 'fragile' => $hasFragile]
            );

            if (empty($deliveryQuote['ok'])) {
                throw new RuntimeException(
                    ($deliveryQuote['error'] ?? '') === 'out_of_zone'
                        ? 'Адрес доставки вне зон обслуживания. Свяжитесь с менеджером для расчёта.'
                        : 'Не удалось рассчитать стоимость доставки. Уточните адрес.'
                );
            }

            $deliveryFee = (float)$deliveryQuote['fee'];
            $courierFee  = round($deliveryFee * 0.65, 0); // курьеру 65%, остальное — маржа компании
        }

        // Финальная сумма с учётом доставки
        $finalTotal += $deliveryFee;

        $fullComment = compose_order_comment($deliveryMethod, $apartment, $entrance, $floor, $intercom, $comment);

        // Проверка остатков
        if ($hasStockColumn) {
            foreach ($orderItems as $oi) {
                $stStock = $pdo->prepare("SELECT stock FROM products WHERE id = ? LIMIT 1");
                $stStock->execute([$oi['pid']]);
                $stockVal = $stStock->fetchColumn();
                if ($stockVal !== false && $stockVal !== null && (int)$stockVal < $oi['qty']) {
                    throw new RuntimeException('Один из товаров закончился или недостаточно на складе.');
                }
            }
        }

        $trackingToken = bin2hex(random_bytes(16));

        // Самовосстановление схемы: создаём колонки фото-отчёта, если их нет
        // (без них чекбокс «прислать фото букета» не сохранялся бы). ALTER нельзя
        // внутри транзакции — поэтому до beginTransaction().
        ensure_order_photo_columns($pdo);

        $pdo->beginTransaction();

        $orderColumns = ['user_id', 'status', 'address', 'phone', 'comment', 'total', 'recipient_name', 'delivery_slot', 'address_lat', 'address_lon'];
        $orderValues = [$userId, 'new', $address, $phone, $fullComment, $finalTotal, $recipientName, $slot, $addressLat, $addressLon];
        if ($hasTrackingToken) { $orderColumns[] = 'tracking_token'; $orderValues[] = $trackingToken; }
        if ($hasDeliveryMethodColumn) { $orderColumns[] = 'delivery_method'; $orderValues[] = $deliveryMethod; }
        if ($hasPromoCodeColumn) { $orderColumns[] = 'promo_code'; $orderValues[] = $promoCode; }
        if ($hasDiscountAmountColumn) { $orderColumns[] = 'discount_amount'; $orderValues[] = $discountAmount; }
        if ($hasSubtotalAmountColumn) { $orderColumns[] = 'subtotal_amount'; $orderValues[] = $subtotal; }
        if (column_exists($pdo, 'orders', 'delivery_fee'))       { $orderColumns[] = 'delivery_fee';       $orderValues[] = $deliveryFee; }
        if (column_exists($pdo, 'orders', 'delivery_calc_meta')) { $orderColumns[] = 'delivery_calc_meta'; $orderValues[] = $deliveryQuote ? json_encode($deliveryQuote, JSON_UNESCAPED_UNICODE) : null; }
        if (column_exists($pdo, 'orders', 'photo_report_requested')) {
            $orderColumns[] = 'photo_report_requested';
            $orderValues[] = $photoReportRequested ? 't' : 'f';
        }

        $placeholders = implode(', ', array_fill(0, count($orderColumns), '?'));
        $sql = 'INSERT INTO orders (' . implode(', ', $orderColumns) . ") VALUES ($placeholders) RETURNING id";
        $stO = $pdo->prepare($sql);
        $stO->execute($orderValues);
        $orderId = (int)$stO->fetchColumn();

        audit_log_user_action($pdo, 'order_create', 'order', $orderId, [
            'total'                  => (float)$finalTotal,
            'delivery_method'        => $deliveryMethod,
            'delivery_fee'           => $deliveryFee,
            'delivery_zone'          => $deliveryQuote['zone']['name'] ?? null,
            'delivery_modifiers'     => array_column((array)($deliveryQuote['modifiers'] ?? []), 'name'),
            'items_count'            => count($orderItems),
            'pricing_rules_applied'  => array_values($pricingRulesApplied),
        ]);

        if ($promoId > 0 && !promo_consume($pdo, $promoId, $userId, $orderId)) {
            throw new RuntimeException('Промокод больше недоступен. Обновите страницу оформления.');
        }

        $stI = $pdo->prepare('INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?, ?, ?, ?)');
        foreach ($orderItems as $oi) {
            $stI->execute([$orderId, $oi['pid'], $oi['qty'], $oi['price']]);
        }

        if ($selectedServiceRows) {
            $stS = $pdo->prepare('INSERT INTO order_extra_services (order_id, service_id, name_snapshot, qty, price, message_text, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            foreach ($selectedServiceRows as $serviceRow) {
                $sid = (int)$serviceRow['id'];
                $stS->execute([
                    $orderId,
                    $sid,
                    (string)$serviceRow['name'],
                    1,
                    (float)$serviceRow['price'],
                    !empty($serviceRow['allow_message']) ? (string)($serviceMessages[$sid] ?? '') : '',
                ]);
            }
        }

        $deliveryId = 0;
        if ($deliveryMethod === 'delivery') {
            $stD = $pdo->prepare('INSERT INTO deliveries (order_id, status, assigned_at, courier_fee, distance_km_est) VALUES (?, ?, ?, ?, ?) RETURNING id');
            $stD->execute([$orderId, 'pending', null, $courierFee, $distanceKm]);
            $deliveryId = (int)$stD->fetchColumn();

            $meta = json_encode([
                'order_id' => $orderId,
                'recipient_name' => $recipientName,
                'phone' => $phone,
                'address' => $address,
                'slot' => $slot,
                'comment' => $fullComment,
                'address_lat' => $addressLat,
                'address_lon' => $addressLon,
                'distance_km' => $distanceKm,
                'courier_fee' => $courierFee,
                'promo_code' => $promoCode,
                'discount_amount' => $discountAmount,
                'subtotal' => $subtotal,
                'extra_subtotal' => $extraSubtotal,
                'final_total' => $finalTotal,
            ], JSON_UNESCAPED_UNICODE);
            $stE = $pdo->prepare('INSERT INTO delivery_events (delivery_id, event, meta_json) VALUES (?, ?, ?)');
            $stE->execute([$deliveryId, 'created', $meta]);
        }

        $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$phone, $userId]);

        // Декремент остатков
        if ($hasStockColumn) {
            foreach ($orderItems as $oi) {
                $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ? AND stock IS NOT NULL")
                    ->execute([$oi['qty'], $oi['pid']]);
            }
        }

        $pdo->commit();

        // ВАЖНО: курьеров здесь НЕ уведомляем. Сначала заказ видят админ/менеджер,
        // собирают букет и только потом нажимают «Передать курьерам» в админке
        // (admin_orders.php → action=release_to_couriers), что и шлёт уведомление
        // курьерам через notifyActiveCouriersAboutDelivery(). Доставка создаётся
        // в статусе pending, но курьерам не рассылается до ручной передачи.

        // Уведомление персоналу при оформлении заказа НЕ отправляем намеренно.
        // Новые заказы персонал видит в админке. В Telegram уведомление уходит
        // только курьерам — после того как букет собран и нажата кнопка
        // «Передать курьерам» (admin_orders.php → action=release_to_couriers).

        cart_store_clear();
        unset($_SESSION['promo_code'], $_SESSION['checkout_extra_service_ids'], $_SESSION['checkout_extra_service_messages']);
        header('Location: order_success.php?id=' . $orderId . '&token=' . urlencode($trackingToken));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

$currentMethod = $_POST['delivery_method'] ?? ($_SESSION['checkout_delivery_method'] ?? 'delivery');
$currentAddressValue = $_POST['address'] ?? ($defaultAddress['address'] ?? '');
$currentLatValue = $_POST['address_lat'] ?? ($defaultAddress['lat'] ?? '');
$currentLonValue = $_POST['address_lon'] ?? ($defaultAddress['lon'] ?? '');
$selectedSavedId = '';
if ($addressBook) {
    if (!empty($_POST['address'])) {
        foreach ($addressBook as $a) {
            if ((string)$a['address'] === (string)($_POST['address'] ?? '') && (string)$a['lat'] === (string)($_POST['address_lat'] ?? '') && (string)$a['lon'] === (string)($_POST['address_lon'] ?? '')) {
                $selectedSavedId = (string)$a['id'];
                break;
            }
        }
    } elseif (!empty($defaultAddress['id'])) {
        $selectedSavedId = (string)$defaultAddress['id'];
    }
}
$promoCalc = promo_calculate_from_session($pdo, $productSubtotal, $currentMethod, $userId);
$total = (float)($promoCalc['total'] ?? $productSubtotal) + $extraSubtotal;
$todayDate = date('Y-m-d');
$defaultDeliveryDate = (string)($_POST['delivery_date'] ?? $todayDate);
$defaultDeliveryTime = (string)($_POST['delivery_time'] ?? '12:00');
$defaultDeliveryTime = preg_match('~^(?:[01]\d|2[0-3]):[0-5]\d$~', $defaultDeliveryTime) ? $defaultDeliveryTime : '12:00';
$defaultPickupDate = (string)($_POST['pickup_date'] ?? $todayDate);
$defaultPickupTime = (string)($_POST['pickup_time'] ?? '12:00');
$defaultPickupTime = preg_match('~^(?:[01]\d|2[0-3]):[0-5]\d$~', $defaultPickupTime) ? $defaultPickupTime : '12:00';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Оформление заказа — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <?php $ymapsSrc = FLORA_YANDEX_MAPS_API_KEY !== '' ? 'https://api-maps.yandex.ru/2.1/?apikey=' . rawurlencode(FLORA_YANDEX_MAPS_API_KEY) . '&lang=ru_RU' : 'https://api-maps.yandex.ru/2.1/?lang=ru_RU'; ?>
  <script src="<?= h($ymapsSrc) ?>" type="text/javascript"></script>
  <style>
    .checkout-wrap{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(320px,.92fr);gap:18px;align-items:start}
    @media (max-width:980px){.checkout-wrap{grid-template-columns:1fr}}
    .checkout-box{background:#fff;border:1px solid #e6ece8;border-radius:18px;box-shadow:var(--shadow-1);padding:18px}
    .checkout-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .checkout-row--three{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .checkout-row--four{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    @media (max-width:860px){.checkout-row,.checkout-row--three,.checkout-row--four{grid-template-columns:1fr}}
    .checkout-error{background:#fee2e2;border:1px solid #fecdd3;color:#7f1d1d;padding:10px 12px;border-radius:12px;margin:10px 0}
    .checkout-note{background:#ecfeff;border:1px solid #bae6fd;color:#075985;padding:10px 12px;border-radius:12px;margin:10px 0}
    .checkout-map{height:360px;border-radius:18px;overflow:hidden;border:1px solid #dbe7de;margin-top:10px}
    .checkout-methods{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
    @media (max-width:720px){.checkout-methods{grid-template-columns:1fr}}
    .checkout-method{position:relative;border:1px solid #d9e4dc;border-radius:16px;padding:14px;background:#fff;cursor:pointer}
    .checkout-method input{position:absolute;opacity:0;pointer-events:none}
    .checkout-method__title{font-weight:800;font-size:16px}
    .checkout-method__desc{margin-top:6px;color:#667085;font-size:14px;line-height:1.45}
    .checkout-hint{margin-top:8px;color:#6b7280;font-size:12px;line-height:1.45}
    .checkout-method.is-active{border-color:#8BC49A;box-shadow:0 0 0 3px rgba(139,196,154,.18)}
    .checkout-section-hidden{display:none}
    .checkout-saved{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
    .checkout-field label{display:block;margin:0 0 6px;font-size:13px;color:#6b7280;font-weight:700}
    .checkout-summary{display:grid;gap:16px}
    .checkout-product{display:grid;grid-template-columns:64px 1fr auto;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #eef3f0}
    .checkout-product:last-child{border-bottom:none}
    .checkout-product__img{width:64px;height:64px;border-radius:14px;object-fit:cover;border:1px solid #edf1ef;background:#f8fafc}
    .checkout-product__name{font-weight:800;font-size:15px;color:#111827}
    .checkout-product__meta{margin-top:4px;color:#6b7280;font-size:13px}
    .checkout-product__sum{font-weight:800;font-size:15px;color:#111827;white-space:nowrap}
    .checkout-total{display:flex;justify-content:space-between;gap:12px;padding-top:14px;margin-top:2px;border-top:1px solid #eef3f0;font-weight:900;font-size:20px}
    .checkout-summary-note{font-size:13px;color:#667085;line-height:1.55;background:#fff;border:1px dashed #dbe7de;border-radius:16px;padding:14px}
    .checkout-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}
    .promoBox{margin-top:14px;padding:14px;border:1px solid #e2e8f0;border-radius:16px;background:#f8fafc}
    .promoForm{display:grid;grid-template-columns:1fr auto;gap:10px}
    .promoTag{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-weight:700;font-size:13px}
    .checkout-line{display:flex;justify-content:space-between;gap:12px;padding:5px 0;color:#374151}
    .checkout-line.is-discount{color:#15803d;font-weight:700}
    .checkout-warning{margin-top:18px;padding:16px;border-radius:18px;background:#fff8f2;border:1px solid #fed7aa;color:#9a3412;line-height:1.65}
    .checkout-change-services{margin-top:14px;padding:14px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
    .input--error{border-color:#f87171 !important;box-shadow:0 0 0 3px rgba(248,113,113,.18) !important;background:#fff9f9 !important}
    .field-err-msg{color:#dc2626;font-size:12px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px}
    .field-err-msg::before{content:'!';display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#dc2626;color:#fff;font-size:10px;font-weight:800;flex-shrink:0}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container">
    <h1 class="section__title">Оформление заказа</h1>
    <p class="section__sub">Подтвердите способ получения и данные получателя. Дополнительные услуги уже выбраны на предыдущем шаге.</p>

    <?php if ($err): ?><div class="checkout-error"><?= h($err) ?></div><?php endif; ?>
    <?php if ($promoError): ?><div class="checkout-error"><?= h($promoError) ?></div><?php endif; ?>
    <?php if ($promoMessage): ?><div class="checkout-note"><?= h($promoMessage) ?></div><?php endif; ?>

    <div class="checkout-wrap">
      <div class="checkout-box">
        <form method="post" id="checkoutForm">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <div class="checkout-methods">
            <label class="checkout-method <?= $currentMethod !== 'pickup' ? 'is-active' : '' ?>">
              <input type="radio" name="delivery_method" value="delivery" <?= $currentMethod !== 'pickup' ? 'checked' : '' ?>>
              <div class="checkout-method__title">Доставка</div>
              <div class="checkout-method__desc">Курьер привезёт заказ по выбранному адресу.</div>
              <div class="checkout-hint">Выберите удобные дату и время доставки.</div>
            </label>
            <label class="checkout-method <?= $currentMethod === 'pickup' ? 'is-active' : '' ?>">
              <input type="radio" name="delivery_method" value="pickup" <?= $currentMethod === 'pickup' ? 'checked' : '' ?>>
              <div class="checkout-method__title">Самовывоз</div>
              <div class="checkout-method__desc">Заберёте заказ сами в магазине FloraExpress.</div>
              <div class="checkout-hint">Можно выбрать дату и время получения.</div>
            </label>
          </div>

          <div class="checkout-row" style="margin-top:14px">
            <div class="checkout-field">
              <label>Имя получателя</label>
              <input class="input" name="recipient_name" value="<?= h($_POST['recipient_name'] ?? $userName) ?>" placeholder="Например: Анна" required>
            </div>
            <div class="checkout-field">
              <label>Телефон</label>
              <input class="input" name="phone" value="<?= h($_POST['phone'] ?? $userPhone) ?>" placeholder="+7 (999) 123-45-67" required data-phone-mask inputmode="tel">
            </div>
          </div>

          <div id="deliveryFields" class="<?= $currentMethod === 'pickup' ? 'checkout-section-hidden' : '' ?>">
            <?php if ($addressBook): ?>
              <div class="checkout-saved">
                <label class="mute" for="savedAddressSelect" style="margin:0">Сохранённые адреса</label>
                <select class="input" id="savedAddressSelect" style="max-width:420px">
                  <option value="">Выбрать адрес</option>
                  <?php foreach ($addressBook as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" data-address="<?= h((string)$a['address']) ?>" data-lat="<?= h((string)$a['lat']) ?>" data-lon="<?= h((string)$a['lon']) ?>" data-apartment="<?= h((string)($a['apartment'] ?? '')) ?>" data-entrance="<?= h((string)($a['entrance'] ?? '')) ?>" data-floor="<?= h((string)($a['floor'] ?? '')) ?>" data-intercom="<?= h((string)($a['intercom'] ?? '')) ?>" <?= $selectedSavedId === (string)$a['id'] ? 'selected' : '' ?>>
                      <?= h((string)$a['label']) ?><?= db_bool($a['is_default'] ?? false) ? ' — по умолчанию' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <a class="btn btn--ghost btn--sm" href="account_addresses.php">Адресная книга</a>
              </div>
            <?php endif; ?>

            <div class="checkout-field" style="margin-top:12px">
              <label>Адрес доставки</label>
              <input class="input" id="addressInput" name="address" value="<?= h($currentAddressValue) ?>" placeholder="Выберите точку на карте" required>
              <input type="hidden" id="addressLat" name="address_lat" value="<?= h((string)$currentLatValue) ?>">
              <input type="hidden" id="addressLon" name="address_lon" value="<?= h((string)$currentLonValue) ?>">
              <div class="checkout-saved" style="margin-top:10px">
                <button class="btn btn--ghost btn--sm" type="button" id="detectLocationBtn">Определить моё местоположение</button>
                <span class="mute">Нажмите на карту, чтобы уточнить адрес</span>
              </div>
            </div>

            <div class="checkout-map" id="checkoutMap"></div>

            <div class="checkout-row--three" style="margin-top:12px">
              <div class="checkout-field">
                <label>Дата доставки</label>
                <input class="input" type="date" name="delivery_date" id="deliveryDateInput" min="<?= h($todayDate) ?>" value="<?= h($defaultDeliveryDate) ?>" required>
              </div>
              <div class="checkout-field">
                <label>Время доставки</label>
                <input class="input" type="time" name="delivery_time" id="deliveryTimeInput" step="900" min="10:00" max="21:00" value="<?= h($defaultDeliveryTime) ?>" required>
                <div class="checkout-hint">Доступно с 10:00 до 21:00</div>
              </div>
              <div class="checkout-field">
                <label>Домофон <span style="color:#dc2626">*</span></label>
                <input class="input" name="intercom" placeholder="Код или «нет»" value="<?= h($_POST['intercom'] ?? '') ?>" maxlength="20">
              </div>
            </div>

            <div class="checkout-row--three" style="margin-top:12px">
              <div class="checkout-field"><label>Квартира <span style="color:#dc2626">*</span></label><input class="input" name="apartment" placeholder="Например: 24" value="<?= h($_POST['apartment'] ?? '') ?>" maxlength="20"></div>
              <div class="checkout-field"><label>Подъезд <span style="color:#dc2626">*</span></label><input class="input" name="entrance" placeholder="Например: 2" value="<?= h($_POST['entrance'] ?? '') ?>" maxlength="20"></div>
              <div class="checkout-field"><label>Этаж <span style="color:#dc2626">*</span></label><input class="input" name="floor" placeholder="Например: 7" value="<?= h($_POST['floor'] ?? '') ?>" maxlength="10" inputmode="numeric"></div>
            </div>
          </div>

          <div id="pickupFields" class="<?= $currentMethod === 'pickup' ? '' : 'checkout-section-hidden' ?>">
            <div class="checkout-field" style="margin-top:12px">
              <label>Адрес самовывоза</label>
              <input class="input" value="<?= h($shopAddress) ?>" readonly>
            </div>
            <div class="checkout-row" style="margin-top:12px">
              <div class="checkout-field">
                <label>Дата самовывоза</label>
                <input class="input" type="date" name="pickup_date" id="pickupDateInput" min="<?= h($todayDate) ?>" value="<?= h($defaultPickupDate) ?>">
              </div>
              <div class="checkout-field">
                <label>Время получения</label>
                <input class="input" type="time" name="pickup_time" id="pickupTimeInput" step="900" min="10:00" max="21:00" value="<?= h($defaultPickupTime) ?>">
                <div class="checkout-hint">Самовывоз доступен с 10:00 до 21:00</div>
              </div>
            </div>
          </div>

          <div class="checkout-field" style="margin-top:12px">
            <label>Комментарий к заказу</label>
            <textarea class="input" style="min-height:84px;padding-top:12px;padding-bottom:12px;border-radius:16px;resize:vertical" name="comment" placeholder="Например: позвонить за 10 минут"><?= h($_POST['comment'] ?? '') ?></textarea>
          </div>

          <label class="photo-report-toggle" style="display:flex;gap:12px;align-items:flex-start;padding:14px 16px;margin-top:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:16px;cursor:pointer;line-height:1.5">
            <input type="checkbox" name="photo_report_requested" value="1" <?= !empty($_POST['photo_report_requested']) ? 'checked' : '' ?> style="margin-top:2px;flex-shrink:0;width:18px;height:18px;cursor:pointer;accent-color:#0f6b45">
            <span style="display:flex;flex-direction:column;gap:4px">
              <strong style="color:#111827;font-size:14.5px">📸 Прислать фото готового букета</strong>
              <span style="color:#6b7280;font-size:13px">Флорист сфотографирует букет перед отправкой и пришлёт фото на странице отслеживания заказа. Если фото не нужно — оставьте галочку снятой.</span>
            </span>
          </label>

          <div class="checkout-warning">
            <strong>Важно:</strong> если точного состава не окажется в наличии, продавец свяжется с вами и предложит похожий вариант замены или возврат средств.
          </div>

          <?php if ($hasBalloons): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px">
              <div style="padding:14px;border-radius:16px;background:linear-gradient(135deg,#f3fbf7 0%,#eef8ff 100%);border:1px solid #dceee5">
                <strong style="display:block;margin-bottom:6px;color:#175a37">Уход за цветами</strong>
                <p style="margin:0 0 10px;color:#405468;font-size:14px;line-height:1.6">Советы по воде, срезу и хранению букета.</p>
                <a class="btn btn--ghost btn--sm" href="care.php" target="_blank">Открыть памятку</a>
              </div>
              <div style="padding:14px;border-radius:16px;background:linear-gradient(135deg,#eef6ff 0%,#e0f0ff 100%);border:1px solid #bfdbfe">
                <strong style="display:block;margin-bottom:6px;color:#1e3a8a">Уход за шарами</strong>
                <p style="margin:0 0 10px;color:#405468;font-size:14px;line-height:1.6">Как хранить, сколько держится гелий и как продлить жизнь шариков.</p>
                <a class="btn btn--ghost btn--sm" href="care_balloons.php" target="_blank">Открыть памятку</a>
              </div>
            </div>
          <?php endif; ?>

          <div class="checkout-actions">
            <button class="btn" type="submit">Подтвердить заказ</button>
            <a class="btn btn--ghost" href="checkout_services.php">Изменить доп. услуги</a>
            <?php if (!$hasBalloons): ?>
              <a class="btn btn--ghost" href="care.php">Памятка по уходу</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <div class="checkout-summary">
        <div class="checkout-box">
          <h2 style="margin:0 0 8px;font-size:20px">Ваш заказ</h2>
          <?php foreach ($items as $it): ?>
            <div class="checkout-product">
              <img class="checkout-product__img" src="<?= h($it['image'] ?: $placeholder) ?>" alt="<?= h($it['name']) ?>" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';">
              <div>
                <div class="checkout-product__name"><?= h($it['name']) ?></div>
                <div class="checkout-product__meta"><?= (int)$it['qty'] ?> × <?= number_format((float)$it['price'], 0, ',', ' ') ?> ₽</div>
              </div>
              <div class="checkout-product__sum"><?= number_format((float)$it['sum'], 0, ',', ' ') ?> ₽</div>
            </div>
          <?php endforeach; ?>

          <?php foreach ($selectedServiceRows as $service): ?>
            <div class="checkout-product">
              <img class="checkout-product__img" src="<?= h(extra_services_image_src($service['image'] ?? '', (string)$service['name'])) ?>" alt="<?= h($service['name']) ?>">
              <div>
                <div class="checkout-product__name"><?= h($service['name']) ?></div>
                <div class="checkout-product__meta"><?= !empty($service['allow_message']) && !empty($serviceMessages[(int)$service['id']]) ? 'Текст добавлен' : 'Дополнение к заказу' ?></div>
              </div>
              <div class="checkout-product__sum">+<?= number_format((float)$service['price'], 0, ',', ' ') ?> ₽</div>
            </div>
          <?php endforeach; ?>

          <div class="checkout-change-services">
            <div>
              <strong>Дополнительные услуги</strong><br>
              <span class="mute" style="font-size:14px;line-height:1.5">На этом шаге они только показываются в итогах. Выбрать или убрать их можно на отдельной странице.</span>
            </div>
            <a class="btn btn--ghost btn--sm" href="checkout_services.php">Изменить</a>
          </div>

          <div class="promoBox">
            <?php if ($promoCalc['hasPromo'] ?? false): ?>
              <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                <span class="promoTag">Промокод: <?= h((string)$promoCalc['code']) ?></span>
                <form method="post" class="promo-preserve">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="delivery_method" value="<?= h($currentMethod) ?>">
                  <button class="btn btn--ghost btn--sm" type="submit" name="promo_action" value="remove">Удалить</button>
                </form>
              </div>
            <?php else: ?>
              <form method="post" class="promoForm promo-preserve">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="delivery_method" value="<?= h($currentMethod) ?>">
                <input class="input" type="text" name="promo_code" placeholder="Промокод">
                <button class="btn btn--ghost" type="submit" name="promo_action" value="apply">Применить</button>
              </form>
            <?php endif; ?>
          </div>

          <div class="checkout-line"><span>Товары</span><strong><?= number_format($productSubtotal, 0, ',', ' ') ?> ₽</strong></div>
          <div class="checkout-line"><span>Доп. услуги</span><strong><?= number_format($extraSubtotal, 0, ',', ' ') ?> ₽</strong></div>
          <div class="checkout-line is-discount"><span>Скидка</span><strong>-<?= number_format((float)($promoCalc['discount'] ?? 0), 0, ',', ' ') ?> ₽</strong></div>
          <div class="checkout-line" id="deliveryLine" style="<?= $currentMethod === 'pickup' ? 'display:none' : '' ?>">
            <span>
              Доставка
              <?php if ($displayDeliveryQuote && !empty($displayDeliveryQuote['ok'])): ?>
                <span class="dz-mini" id="deliveryZoneName" style="display:block;font-size:11px;color:#6b7280;font-weight:400;margin-top:2px"><?= h((string)($displayDeliveryQuote['zone']['name'] ?? '')) ?><?php if (!empty($displayDeliveryQuote['eta_min'])): ?> · ETA ≈ <?= (int)$displayDeliveryQuote['eta_min'] ?> мин<?php endif; ?></span>
              <?php else: ?>
                <span class="dz-mini" id="deliveryZoneName" style="display:block;font-size:11px;color:#9ca3af;font-weight:400;margin-top:2px">Укажите адрес для расчёта</span>
              <?php endif; ?>
            </span>
            <strong id="deliveryFeeAmount">
              <?php if ($displayDeliveryQuote && !empty($displayDeliveryQuote['ok'])): ?>
                <?php if (!empty($displayDeliveryQuote['free_applied'])): ?>
                  <span style="color:#16a34a">Бесплатно</span>
                <?php else: ?>
                  <?= number_format((float)$displayDeliveryQuote['fee'], 0, ',', ' ') ?> ₽
                <?php endif; ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </strong>
          </div>
          <?php if ($displayDeliveryQuote && !empty($displayDeliveryQuote['ok']) && !empty($displayDeliveryQuote['breakdown'])): ?>
            <details id="deliveryBreakdown" style="margin-top:-4px;<?= $currentMethod === 'pickup' ? 'display:none' : '' ?>">
              <summary class="dz-mini" style="cursor:pointer;color:#6b7280;font-size:11px;padding:4px 0">Расшифровка</summary>
              <div id="deliveryBreakdownBody" style="margin-top:6px;padding:8px 12px;background:#f9fbfa;border-radius:10px;font-size:12px;color:#475569;display:grid;gap:3px">
                <?php foreach ($displayDeliveryQuote['breakdown'] as $b): ?>
                  <div style="display:flex;justify-content:space-between;gap:8px">
                    <span><?= h((string)$b['label']) ?></span>
                    <?php if (isset($b['amount']) && $b['amount'] !== null): ?>
                      <span style="white-space:nowrap"><?= ((float)$b['amount'] >= 0 ? '+' : '') . number_format((float)$b['amount'], 0, ',', ' ') ?> ₽</span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php else: ?>
            <details id="deliveryBreakdown" style="margin-top:-4px;display:none">
              <summary class="dz-mini" style="cursor:pointer;color:#6b7280;font-size:11px;padding:4px 0">Расшифровка</summary>
              <div id="deliveryBreakdownBody" style="margin-top:6px;padding:8px 12px;background:#f9fbfa;border-radius:10px;font-size:12px;color:#475569;display:grid;gap:3px"></div>
            </details>
          <?php endif; ?>
          <div class="checkout-total"><span>Итого</span><span id="checkoutTotalAmount"><?= number_format((float)$total, 0, ',', ' ') ?> ₽</span></div>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  const mapEl = document.getElementById('checkoutMap');
  const addressInput = document.getElementById('addressInput');
  const latInput = document.getElementById('addressLat');
  const lonInput = document.getElementById('addressLon');
  const detectBtn = document.getElementById('detectLocationBtn');
  const savedSelect = document.getElementById('savedAddressSelect');
  const modeInputs = document.querySelectorAll('input[name="delivery_method"]');
  const deliveryFields = document.getElementById('deliveryFields');
  const pickupFields = document.getElementById('pickupFields');
  const deliveryDateInput = document.getElementById('deliveryDateInput');
  const deliveryTimeInput = document.getElementById('deliveryTimeInput');
  const pickupDateInput = document.getElementById('pickupDateInput');
  const pickupTimeInput = document.getElementById('pickupTimeInput');

  function clampTime(input) {
    if (!input) return;
    const value = input.value || '';
    if (!/^\d{2}:\d{2}$/.test(value)) return;
    if (value < '10:00') input.value = '10:00';
    if (value > '21:00') input.value = '21:00';
  }

  function syncTodayMinTime(dateInput, timeInput) {
    if (!dateInput || !timeInput) return;
    const today = <?= json_encode($todayDate, JSON_UNESCAPED_UNICODE) ?>;
    const now = new Date();
    const rounded = new Date(now.getTime());
    rounded.setMinutes(Math.ceil(rounded.getMinutes() / 15) * 15, 0, 0);
    let minTime = '10:00';
    if (dateInput.value === today) {
      const hh = String(rounded.getHours()).padStart(2, '0');
      const mm = String(rounded.getMinutes()).padStart(2, '0');
      minTime = hh + ':' + mm;
      if (minTime < '10:00') minTime = '10:00';
      if (minTime > '21:00') minTime = '21:00';
    }
    timeInput.min = minTime;
    timeInput.max = '21:00';
    if (!timeInput.value || timeInput.value < minTime) timeInput.value = minTime;
    if (timeInput.value > '21:00') timeInput.value = '21:00';
  }

  function setMethodCardState() {
    document.querySelectorAll('.checkout-method').forEach(card => {
      const input = card.querySelector('input[type="radio"]');
      card.classList.toggle('is-active', !!input && input.checked);
    });
  }

  function toggleMode() {
    const method = document.querySelector('input[name="delivery_method"]:checked')?.value || 'delivery';
    deliveryFields.classList.toggle('checkout-section-hidden', method === 'pickup');
    pickupFields.classList.toggle('checkout-section-hidden', method !== 'pickup');
    setMethodCardState();
    if (method === 'pickup') {
      addressInput.required = false;
      if (deliveryDateInput) { deliveryDateInput.required = false; deliveryDateInput.disabled = true; }
      if (deliveryTimeInput) { deliveryTimeInput.required = false; deliveryTimeInput.disabled = true; }
      if (pickupDateInput) { pickupDateInput.required = true; pickupDateInput.disabled = false; }
      if (pickupTimeInput) { pickupTimeInput.required = true; pickupTimeInput.disabled = false; }
      addressInput.value = <?= json_encode($shopAddress, JSON_UNESCAPED_UNICODE) ?>;
      latInput.value = <?= json_encode($shopLat, JSON_UNESCAPED_UNICODE) ?>;
      lonInput.value = <?= json_encode($shopLon, JSON_UNESCAPED_UNICODE) ?>;
    } else {
      addressInput.required = true;
      if (deliveryDateInput) { deliveryDateInput.required = true; deliveryDateInput.disabled = false; }
      if (deliveryTimeInput) { deliveryTimeInput.required = true; deliveryTimeInput.disabled = false; }
      if (pickupDateInput) { pickupDateInput.required = false; pickupDateInput.disabled = true; }
      if (pickupTimeInput) { pickupTimeInput.required = false; pickupTimeInput.disabled = true; }
    }
  }
  modeInputs.forEach(el => el.addEventListener('change', toggleMode));
  if (deliveryDateInput && deliveryTimeInput) {
    syncTodayMinTime(deliveryDateInput, deliveryTimeInput);
    deliveryDateInput.addEventListener('change', () => syncTodayMinTime(deliveryDateInput, deliveryTimeInput));
    deliveryTimeInput.addEventListener('change', () => clampTime(deliveryTimeInput));
  }
  if (pickupDateInput && pickupTimeInput) {
    syncTodayMinTime(pickupDateInput, pickupTimeInput);
    pickupDateInput.addEventListener('change', () => syncTodayMinTime(pickupDateInput, pickupTimeInput));
    pickupTimeInput.addEventListener('change', () => clampTime(pickupTimeInput));
  }

  if (window.FloraUi) {
    window.FloraUi.bindPhoneMasks(document);
  }

  if (mapEl && window.ymaps) {
    ymaps.ready(function () {
      const startLat = latInput.value ? parseFloat(latInput.value) : <?= json_encode($shopLat, JSON_UNESCAPED_UNICODE) ?>;
      const startLon = lonInput.value ? parseFloat(lonInput.value) : <?= json_encode($shopLon, JSON_UNESCAPED_UNICODE) ?>;
      const map = new ymaps.Map('checkoutMap', {
        center: [startLat, startLon],
        zoom: 13,
        controls: ['zoomControl']
      }, {
        searchControlProvider: 'yandex#search'
      });

      let placemark = null;

      function setValues(lat, lon, address) {
        latInput.value = lat;
        lonInput.value = lon;
        if (typeof address === 'string' && address.trim() !== '') addressInput.value = address;
        if (placemark) {
          placemark.geometry.setCoordinates([lat, lon]);
        } else {
          placemark = new ymaps.Placemark([lat, lon], {}, { preset: 'islands#redDotIcon', draggable: true });
          placemark.events.add('dragend', function () {
            reverseGeocode(placemark.geometry.getCoordinates());
          });
          map.geoObjects.add(placemark);
        }
        map.setCenter([lat, lon], 16);
      }

      function applyPlacemark(lat, lon) {
        if (placemark) {
          placemark.geometry.setCoordinates([lat, lon]);
        } else {
          placemark = new ymaps.Placemark([lat, lon], {}, { preset: 'islands#redDotIcon', draggable: true });
          placemark.events.add('dragend', function () {
            reverseGeocode(placemark.geometry.getCoordinates());
          });
          map.geoObjects.add(placemark);
        }
        map.setCenter([lat, lon], 16);
      }

      function reverseGeocode(coords) {
        const lat = Number(coords[0]);
        const lon = Number(coords[1]);

        latInput.value = String(lat);
        lonInput.value = String(lon);

        if (placemark) {
          placemark.geometry.setCoordinates([lat, lon]);
        } else {
          placemark = new ymaps.Placemark([lat, lon], {}, { preset: 'islands#redDotIcon', draggable: true });
          placemark.events.add('dragend', function () {
            reverseGeocode(placemark.geometry.getCoordinates());
          });
          map.geoObjects.add(placemark);
        }

        map.setCenter([lat, lon], 16);

        ymaps.geocode([lat, lon], { results: 1, kind: 'house' }).then(function (res) {
          const obj = res.geoObjects.get(0);
          const address = obj ? obj.getAddressLine() : '';
          if (address) {
            addressInput.value = address;
          } else {
            addressInput.value = lat.toFixed(6) + ', ' + lon.toFixed(6);
          }
          addressInput.dispatchEvent(new Event('input', { bubbles: true }));
          addressInput.dispatchEvent(new Event('change', { bubbles: true }));
          if (savedSelect) savedSelect.value = '';
        }).catch(function () {
          addressInput.value = lat.toFixed(6) + ', ' + lon.toFixed(6);
          addressInput.dispatchEvent(new Event('input', { bubbles: true }));
          addressInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }

      map.events.add('click', function (e) {
        reverseGeocode(e.get('coords'));
      });

      // Подставляет детали входа (квартира/подъезд/этаж/домофон) из выбранного адреса.
      function fillAddressExtras(option, onlyEmpty) {
        if (!option) return;
        [['apartment', option.dataset.apartment],
         ['entrance',  option.dataset.entrance],
         ['floor',     option.dataset.floor],
         ['intercom',  option.dataset.intercom]].forEach(function (pair) {
          const el = document.querySelector('[name="' + pair[0] + '"]');
          if (!el) return;
          if (onlyEmpty && el.value.trim() !== '') return;
          el.value = pair[1] || '';
          el.dispatchEvent(new Event('input', { bubbles: true }));
        });
      }

      if (savedSelect) {
        savedSelect.addEventListener('change', function () {
          const option = this.options[this.selectedIndex];
          if (!option || !option.dataset.lat || !option.dataset.lon) return;
          setValues(parseFloat(option.dataset.lat), parseFloat(option.dataset.lon), option.dataset.address || '');
          fillAddressExtras(option, false);
        });

        // При загрузке: если адрес уже выбран по умолчанию — заполняем пустые поля деталей.
        if (savedSelect.value) {
          fillAddressExtras(savedSelect.options[savedSelect.selectedIndex], true);
        }
      }

      if (detectBtn) {
        detectBtn.addEventListener('click', function () {
          if (!navigator.geolocation) return alert('Геолокация не поддерживается браузером.');
          navigator.geolocation.getCurrentPosition(
            function (pos) {
              reverseGeocode([pos.coords.latitude, pos.coords.longitude]);
            },
            function () { alert('Не удалось определить местоположение.'); },
            {enableHighAccuracy: true, timeout: 10000}
          );
        });
      }

      if (latInput.value && lonInput.value && addressInput.value && (document.querySelector('input[name="delivery_method"]:checked')?.value || 'delivery') === 'delivery') {
        setValues(parseFloat(latInput.value), parseFloat(lonInput.value), addressInput.value);
      }
    });
  }

  document.querySelectorAll('.promo-preserve').forEach(function(form){
    form.addEventListener('submit', function(){
      const checkoutForm = document.getElementById('checkoutForm');
      if (!checkoutForm) return;
      form.querySelectorAll('[data-preserved-field="1"]').forEach(function(node){ node.remove(); });
      ['recipient_name','phone','address','address_lat','address_lon','delivery_date','delivery_time','pickup_date','pickup_time','comment','apartment','entrance','floor','intercom'].forEach(function(name){
        const field = checkoutForm.querySelector('[name="' + name + '"]');
        if (!field || field.disabled) return;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = name;
        hidden.value = field.value || '';
        hidden.setAttribute('data-preserved-field', '1');
        form.appendChild(hidden);
      });
    });
  });

  toggleMode();

  // ===== Клиентская валидация =====
  function clearFieldError(input) {
    if (!input) return;
    input.classList.remove('input--error');
    var next = input.nextSibling;
    while (next && next.classList && next.classList.contains('field-err-msg')) {
      var toRemove = next;
      next = next.nextSibling;
      toRemove.remove();
    }
  }

  function markFieldError(input, message) {
    if (!input) return;
    clearFieldError(input);
    input.classList.add('input--error');
    var msg = document.createElement('div');
    msg.className = 'field-err-msg';
    msg.textContent = message;
    input.parentNode.insertBefore(msg, input.nextSibling);
  }

  function validateCheckout() {
    var method = (document.querySelector('input[name="delivery_method"]:checked') || {}).value || 'delivery';
    var firstError = null;

    // Убрать старый баннер
    var oldBanner = document.getElementById('checkoutValBanner');
    if (oldBanner) oldBanner.remove();

    var errs = [];

    function check(input, message) {
      clearFieldError(input);
      if (!input) return;
      if (!input.value || input.value.trim() === '') {
        markFieldError(input, message);
        errs.push(message);
        if (!firstError) firstError = input;
      }
    }

    // Имя получателя
    var nameInput = document.querySelector('[name="recipient_name"]');
    clearFieldError(nameInput);
    if (!nameInput || nameInput.value.trim().length < 2) {
      markFieldError(nameInput, 'Укажите имя получателя (не менее 2 символов)');
      errs.push('Имя получателя');
      if (!firstError) firstError = nameInput;
    }

    // Телефон
    var phoneInput = document.querySelector('[name="phone"]');
    clearFieldError(phoneInput);
    var digits = (phoneInput ? phoneInput.value : '').replace(/\D/g, '');
    if (digits.length < 10) {
      markFieldError(phoneInput, 'Укажите телефон (например: +7 999 123-45-67)');
      errs.push('Телефон');
      if (!firstError) firstError = phoneInput;
    }

    if (method === 'delivery') {
      // Адрес
      var addrInput = document.getElementById('addressInput');
      var latVal  = (document.getElementById('addressLat')  || {}).value || '';
      var lonVal  = (document.getElementById('addressLon')  || {}).value || '';
      clearFieldError(addrInput);
      if (!addrInput || addrInput.value.trim().length < 5) {
        markFieldError(addrInput, 'Укажите адрес доставки');
        errs.push('Адрес доставки');
        if (!firstError) firstError = addrInput;
      } else if (!latVal || !lonVal || parseFloat(latVal) === 0 || parseFloat(lonVal) === 0) {
        markFieldError(addrInput, 'Выберите точку на карте для подтверждения адреса');
        errs.push('Адрес на карте');
        if (!firstError) firstError = addrInput;
      }

      // Дата доставки
      var dDateInput = document.getElementById('deliveryDateInput');
      clearFieldError(dDateInput);
      if (!dDateInput || !dDateInput.value) {
        markFieldError(dDateInput, 'Выберите дату доставки');
        errs.push('Дата доставки');
        if (!firstError) firstError = dDateInput;
      }

      // Время доставки
      var dTimeInput = document.getElementById('deliveryTimeInput');
      clearFieldError(dTimeInput);
      if (!dTimeInput || !dTimeInput.value) {
        markFieldError(dTimeInput, 'Выберите время доставки');
        errs.push('Время доставки');
        if (!firstError) firstError = dTimeInput;
      } else if (dTimeInput.value < '10:00' || dTimeInput.value > '21:00') {
        markFieldError(dTimeInput, 'Время доставки должно быть с 10:00 до 21:00');
        errs.push('Время доставки');
        if (!firstError) firstError = dTimeInput;
      }

      // Квартира
      var aptInput = document.querySelector('[name="apartment"]');
      clearFieldError(aptInput);
      if (!aptInput || aptInput.value.trim() === '') {
        markFieldError(aptInput, 'Укажите номер квартиры');
        errs.push('Квартира');
        if (!firstError) firstError = aptInput;
      }

      // Подъезд
      var entrInput = document.querySelector('[name="entrance"]');
      clearFieldError(entrInput);
      if (!entrInput || entrInput.value.trim() === '') {
        markFieldError(entrInput, 'Укажите номер подъезда');
        errs.push('Подъезд');
        if (!firstError) firstError = entrInput;
      }

      // Этаж
      var floorInput = document.querySelector('[name="floor"]');
      clearFieldError(floorInput);
      if (!floorInput || floorInput.value.trim() === '') {
        markFieldError(floorInput, 'Укажите этаж');
        errs.push('Этаж');
        if (!firstError) firstError = floorInput;
      }

      // Домофон
      var intercomInput = document.querySelector('[name="intercom"]');
      clearFieldError(intercomInput);
      if (!intercomInput || intercomInput.value.trim() === '') {
        markFieldError(intercomInput, 'Укажите код домофона (если нет — напишите «нет»)');
        errs.push('Домофон');
        if (!firstError) firstError = intercomInput;
      }

    } else {
      // Дата самовывоза
      var pDateInput = document.getElementById('pickupDateInput');
      clearFieldError(pDateInput);
      if (!pDateInput || !pDateInput.value) {
        markFieldError(pDateInput, 'Выберите дату самовывоза');
        errs.push('Дата самовывоза');
        if (!firstError) firstError = pDateInput;
      }

      // Время самовывоза
      var pTimeInput = document.getElementById('pickupTimeInput');
      clearFieldError(pTimeInput);
      if (!pTimeInput || !pTimeInput.value) {
        markFieldError(pTimeInput, 'Выберите время самовывоза');
        errs.push('Время самовывоза');
        if (!firstError) firstError = pTimeInput;
      } else if (pTimeInput.value < '10:00' || pTimeInput.value > '21:00') {
        markFieldError(pTimeInput, 'Время самовывоза: с 10:00 до 21:00');
        errs.push('Время самовывоза');
        if (!firstError) firstError = pTimeInput;
      }
    }

    if (errs.length > 0) {
      var banner = document.createElement('div');
      banner.id = 'checkoutValBanner';
      banner.className = 'checkout-error';
      banner.style.marginBottom = '16px';
      banner.textContent = 'Заполните обязательные поля: ' + errs.join(', ');
      var form = document.getElementById('checkoutForm');
      form.insertBefore(banner, form.firstChild);
      if (firstError) {
        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(function(){ firstError.focus(); }, 300);
      }
      return false;
    }

    return true;
  }

  // Сброс ошибки при вводе
  document.getElementById('checkoutForm') && document.getElementById('checkoutForm').querySelectorAll('.input').forEach(function(el) {
    el.addEventListener('input', function(){ clearFieldError(this); });
    el.addEventListener('change', function(){ clearFieldError(this); });
  });

  var checkoutForm = document.getElementById('checkoutForm');
  if (checkoutForm) {
    checkoutForm.addEventListener('submit', function(e) {
      if (!validateCheckout()) {
        e.preventDefault();
      }
    });
  }

  // ---------- Live-расчёт стоимости доставки ----------
  const baseTotalNoDelivery = <?= json_encode((float)((float)$productSubtotal + (float)$extraSubtotal - (float)($promoCalc['discount'] ?? 0)), JSON_UNESCAPED_UNICODE) ?>;
  const deliveryLine        = document.getElementById('deliveryLine');
  const deliveryFeeAmount   = document.getElementById('deliveryFeeAmount');
  const deliveryZoneName    = document.getElementById('deliveryZoneName');
  const deliveryBreakdown   = document.getElementById('deliveryBreakdown');
  const deliveryBreakdownBody = document.getElementById('deliveryBreakdownBody');
  const checkoutTotalAmount = document.getElementById('checkoutTotalAmount');

  function rubFmt(n) {
    return Math.round(n).toLocaleString('ru-RU') + ' ₽';
  }

  function getCurrentSlotString() {
    const d = (deliveryDateInput && deliveryDateInput.value) || '';
    const t = (deliveryTimeInput && deliveryTimeInput.value) || '';
    if (d && t) return d + ' ' + t;
    if (t) return t;
    return '';
  }

  function updateTotalDisplay(deliveryFee) {
    if (!checkoutTotalAmount) return;
    const isPickup = document.querySelector('input[name="delivery_method"]:checked')?.value === 'pickup';
    const finalTotal = baseTotalNoDelivery + (isPickup ? 0 : (deliveryFee || 0));
    checkoutTotalAmount.textContent = rubFmt(finalTotal);
  }

  let lastQuoteController = null;
  let quoteDebounceTimer  = null;

  function fetchDeliveryQuote() {
    const method = document.querySelector('input[name="delivery_method"]:checked')?.value || 'delivery';
    if (method === 'pickup') {
      if (deliveryLine) deliveryLine.style.display = 'none';
      if (deliveryBreakdown) deliveryBreakdown.style.display = 'none';
      updateTotalDisplay(0);
      return;
    }
    if (deliveryLine) deliveryLine.style.display = '';

    const lat = parseFloat((latInput && latInput.value) || '0');
    const lon = parseFloat((lonInput && lonInput.value) || '0');
    if (!lat || !lon) {
      if (deliveryFeeAmount) deliveryFeeAmount.innerHTML = '—';
      if (deliveryZoneName)  deliveryZoneName.textContent = 'Укажите адрес для расчёта';
      if (deliveryBreakdown) deliveryBreakdown.style.display = 'none';
      updateTotalDisplay(0);
      return;
    }

    if (lastQuoteController) lastQuoteController.abort();
    lastQuoteController = new AbortController();

    const slot = getCurrentSlotString();
    const params = new URLSearchParams({
      lat: String(lat),
      lon: String(lon),
      total: String(baseTotalNoDelivery),
      slot: slot,
    });

    if (deliveryFeeAmount) deliveryFeeAmount.innerHTML = '<span style="color:#9ca3af">…</span>';

    fetch('delivery_quote.php?' + params.toString(), { signal: lastQuoteController.signal })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.ok) {
          if (deliveryFeeAmount) deliveryFeeAmount.innerHTML = '<span style="color:#b91c1c">Вне зон</span>';
          if (deliveryZoneName)  deliveryZoneName.textContent = data && data.distance_km ? ('расстояние ≈ ' + data.distance_km + ' км — свяжитесь с менеджером') : '';
          if (deliveryBreakdown) deliveryBreakdown.style.display = 'none';
          updateTotalDisplay(0);
          return;
        }

        const fee = parseFloat(data.fee || 0);
        if (data.free_applied) {
          deliveryFeeAmount.innerHTML = '<span style="color:#16a34a">Бесплатно</span>';
        } else {
          deliveryFeeAmount.textContent = rubFmt(fee);
        }

        if (deliveryZoneName) {
          let txt = (data.zone && data.zone.name) ? data.zone.name : '';
          if (data.eta_min)  txt += ' · ETA ≈ ' + data.eta_min + ' мин';
          if (data.distance_km) txt += ' · ' + data.distance_km + ' км';
          deliveryZoneName.textContent = txt;
        }

        if (deliveryBreakdown && deliveryBreakdownBody && Array.isArray(data.breakdown) && data.breakdown.length) {
          deliveryBreakdown.style.display = '';
          deliveryBreakdownBody.innerHTML = data.breakdown.map(b => {
            const amount = (b && b.amount != null)
              ? ('<span style="white-space:nowrap">' + (b.amount >= 0 ? '+' : '') + Math.round(b.amount).toLocaleString('ru-RU') + ' ₽</span>')
              : '';
            return '<div style="display:flex;justify-content:space-between;gap:8px"><span>' + (b.label || '') + '</span>' + amount + '</div>';
          }).join('');
        } else if (deliveryBreakdown) {
          deliveryBreakdown.style.display = 'none';
        }

        updateTotalDisplay(fee);
      })
      .catch(err => {
        if (err.name === 'AbortError') return;
        if (deliveryFeeAmount) deliveryFeeAmount.textContent = '—';
        updateTotalDisplay(0);
      });
  }

  function scheduleQuote() {
    clearTimeout(quoteDebounceTimer);
    quoteDebounceTimer = setTimeout(fetchDeliveryQuote, 250);
  }

  // Триггеры: смена координат, способа доставки, даты/времени слота
  if (latInput) {
    const obs = new MutationObserver(scheduleQuote);
    obs.observe(latInput, { attributes: true, attributeFilter: ['value'] });
    latInput.addEventListener('input', scheduleQuote);
    latInput.addEventListener('change', scheduleQuote);
  }
  if (lonInput) {
    lonInput.addEventListener('input', scheduleQuote);
    lonInput.addEventListener('change', scheduleQuote);
  }
  modeInputs.forEach(el => el.addEventListener('change', scheduleQuote));
  [deliveryDateInput, deliveryTimeInput].forEach(el => el && el.addEventListener('change', scheduleQuote));

  // Первичный расчёт через секунду (после инициализации карты)
  setTimeout(fetchDeliveryQuote, 600);
})();
</script>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
