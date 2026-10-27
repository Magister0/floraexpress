<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/order_presenter.php';
require_once __DIR__ . '/audit_log_lib.php';
require_once __DIR__ . '/telegram_notify.php';
$pdo = db();

function add_delivery_event(PDO $pdo, int $deliveryId, string $event, array $meta = []): void {
    $st = $pdo->prepare('INSERT INTO delivery_events (delivery_id, event, meta_json, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
    $st->execute([$deliveryId, $event, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
}

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('admin_order_view.php' . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : '')));
    exit;
}
if (!in_array((string)($me['role'] ?? 'user'), ['admin','manager'], true)) {
    http_response_code(403);
    echo '403';
    exit;
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: admin_orders.php');
    exit;
}

$hasDeliveryMethod = column_exists($pdo, 'orders', 'delivery_method');
$hasCancelReason = column_exists($pdo, 'orders', 'cancel_reason');
$hasOnShift = column_exists($pdo, 'users', 'on_shift');

// Колонки orders.photo_report*, admin_seen_at создаются через migrate_schema.php.
try {
    $pdo->prepare("UPDATE orders SET admin_seen_at = CURRENT_TIMESTAMP WHERE id = ? AND admin_seen_at IS NULL")
        ->execute([$orderId]);
} catch (Throwable $e) { /* колонки нет — игнорируем, прогоните migrate_schema.php */ }

$err = '';
$ok = '';

$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_check($_POST['csrf'] ?? null)) {
            throw new RuntimeException('Сессия истекла, обновите страницу и попробуйте снова.');
        }
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'order_status') {
            $new = trim((string)($_POST['status'] ?? ''));
            $allowed = ['new','paid','assembling','on_delivery','delivered','canceled'];
            if (!in_array($new, $allowed, true)) {
                throw new RuntimeException('Недопустимый статус заказа.');
            }
            $prev = (string)$pdo->query('SELECT status FROM orders WHERE id = ' . (int)$orderId)->fetchColumn();
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$new, $orderId]);
            audit_log_user_action($pdo, 'order_status_change', 'order', $orderId, ['old' => $prev, 'new' => $new]);
            $ok = 'Статус заказа обновлён.';
        }

        if ($action === 'assign_courier') {
            $courierId = (int)($_POST['courier_id'] ?? 0);
            if ($courierId <= 0) {
                throw new RuntimeException('Выберите курьера.');
            }

            $pdo->beginTransaction();
            $stOrder = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stOrder->execute([$orderId]);
            $orderRow = $stOrder->fetch(PDO::FETCH_ASSOC);
            if (!$orderRow) {
                throw new RuntimeException('Заказ не найден.');
            }
            $method = infer_delivery_method($orderRow, $hasDeliveryMethod);
            if ($method !== 'delivery') {
                throw new RuntimeException('Для самовывоза курьер не назначается.');
            }

            $stDelivery = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = ? LIMIT 1 FOR UPDATE');
            $stDelivery->execute([$orderId]);
            $deliveryRow = $stDelivery->fetch(PDO::FETCH_ASSOC);

            if ($deliveryRow) {
                $pdo->prepare("UPDATE deliveries SET courier_id = ?, status = 'assigned', assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP) WHERE id = ?")
                    ->execute([$courierId, (int)$deliveryRow['id']]);
                $deliveryId = (int)$deliveryRow['id'];
            } else {
                $pdo->prepare("INSERT INTO deliveries (order_id, courier_id, status, assigned_at, created_at) VALUES (?, ?, 'assigned', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
                    ->execute([$orderId, $courierId]);
                $deliveryId = (int)$pdo->lastInsertId();
            }

            add_delivery_event($pdo, $deliveryId, 'assign', ['courier_id' => $courierId, 'by' => 'admin']);
            $relSql = column_exists($pdo, 'orders', 'released_to_courier') ? ', released_to_courier = TRUE' : '';
            $pdo->prepare("UPDATE orders SET status = CASE WHEN status = 'new' THEN 'paid' ELSE status END{$relSql} WHERE id = ?")
                ->execute([$orderId]);
            $pdo->commit();
            // Уведомляем назначенного курьера в Telegram
            $courierNotified = false;
            $stCN = $pdo->prepare("SELECT name, telegram_chat_id FROM users WHERE id = ? LIMIT 1");
            $stCN->execute([$courierId]);
            $cn = $stCN->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($cn['telegram_chat_id'])) {
                try {
                    flora_send_message((int)$cn['telegram_chat_id'], "📦 <b>Вам назначена доставка по заказу #{$orderId}</b>\nОткройте приложение курьера, чтобы посмотреть адрес и состав.");
                    $courierNotified = true;
                } catch (Throwable $te) { /* не валим основной флоу */ }
            }
            audit_log_user_action($pdo, 'courier_assign', 'order', $orderId, ['courier_id' => $courierId, 'delivery_id' => $deliveryId]);
            $ok = 'Курьер назначен.' . ($courierNotified ? ' Курьер уведомлён в Telegram.' : '');
        }

        if ($action === 'release_to_couriers') {
            $pdo->beginTransaction();
            $stOrder = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stOrder->execute([$orderId]);
            $orderRow = $stOrder->fetch(PDO::FETCH_ASSOC);
            if (!$orderRow) {
                throw new RuntimeException('Заказ не найден.');
            }
            $method = infer_delivery_method($orderRow, $hasDeliveryMethod);
            if ($method !== 'delivery') {
                throw new RuntimeException('Для самовывоза передавать курьеру не нужно.');
            }
            // Должна быть свободная доставка (создаётся при оформлении); если нет — создаём
            $stD = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = ? LIMIT 1 FOR UPDATE');
            $stD->execute([$orderId]);
            $dRow = $stD->fetch(PDO::FETCH_ASSOC);
            if ($dRow) {
                $deliveryId = (int)$dRow['id'];
            } else {
                $stIns = $pdo->prepare("INSERT INTO deliveries (order_id, status, created_at) VALUES (?, 'pending', CURRENT_TIMESTAMP) RETURNING id");
                $stIns->execute([$orderId]);
                $deliveryId = (int)$stIns->fetchColumn();
            }
            $relSql = column_exists($pdo, 'orders', 'released_to_courier') ? 'released_to_courier = TRUE, ' : '';
            $pdo->prepare("UPDATE orders SET {$relSql}status = CASE WHEN status = 'new' THEN 'assembling' ELSE status END WHERE id = ?")
                ->execute([$orderId]);
            add_delivery_event($pdo, $deliveryId, 'released', ['by' => 'admin']);
            $pdo->commit();
            // Рассылаем уведомление курьерам на смене
            $notify = notifyActiveCouriersAboutDelivery($pdo, $deliveryId);
            $cc = (int)($notify['couriers_count'] ?? 0);
            audit_log_user_action($pdo, 'order_release_couriers', 'order', $orderId, ['delivery_id' => $deliveryId, 'couriers_notified' => $cc]);
            $ok = 'Заказ передан курьерам.' . ($cc > 0 ? " Уведомлено курьеров на смене: {$cc}." : ' Сейчас нет курьеров на смене для уведомления.');
        }

        if ($action === 'delivery_status') {
            $deliveryId = (int)($_POST['delivery_id'] ?? 0);
            $new = trim((string)($_POST['status'] ?? ''));
            $allowed = ['pending','assigned','picked','en_route','delivered','failed','cancelled'];
            if ($deliveryId <= 0 || !in_array($new, $allowed, true)) {
                throw new RuntimeException('Недопустимый статус доставки.');
            }

            if ($new === 'assigned') {
                $pdo->prepare("UPDATE deliveries SET status = ?, assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP) WHERE id = ?")
                    ->execute([$new, $deliveryId]);
            } elseif ($new === 'delivered') {
                $pdo->prepare("UPDATE deliveries SET status = ?, delivered_at = CURRENT_TIMESTAMP WHERE id = ?")
                    ->execute([$new, $deliveryId]);
                $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?")->execute([$orderId]);
            } else {
                $pdo->prepare('UPDATE deliveries SET status = ? WHERE id = ?')->execute([$new, $deliveryId]);
                if ($new === 'en_route') {
                    $pdo->prepare("UPDATE orders SET status = 'on_delivery' WHERE id = ?")->execute([$orderId]);
                }
            }

            add_delivery_event($pdo, $deliveryId, 'status', ['new' => $new, 'by' => 'admin']);
            audit_log_user_action($pdo, 'delivery_status_change', 'order', $orderId, ['delivery_id' => $deliveryId, 'new' => $new]);
            $ok = 'Статус доставки обновлён.';
        }

        if ($action === 'cancel_order') {
            $reason = trim((string)($_POST['cancel_reason'] ?? ''));
            if ($reason === '') {
                throw new RuntimeException('Укажите причину отмены.');
            }

            if ($hasCancelReason) {
                $pdo->prepare("UPDATE orders SET status = 'canceled', cancel_reason = ? WHERE id = ?")->execute([$reason, $orderId]);
            } else {
                $pdo->prepare("UPDATE orders SET status = 'canceled', comment = TRIM(BOTH ';' FROM CONCAT(COALESCE(comment,''), CASE WHEN COALESCE(comment,'') = '' THEN '' ELSE '; ' END, 'Причина отмены: ', ?)) WHERE id = ?")
                    ->execute([$reason, $orderId]);
            }

            // Найдём назначенного курьера ДО смены статуса доставки — чтобы знать, кого уведомлять
            $stCourier = $pdo->prepare("
                SELECT d.id AS delivery_id, d.courier_id, d.status AS delivery_status,
                       u.telegram_chat_id, u.name AS courier_name
                FROM deliveries d
                LEFT JOIN users u ON u.id = d.courier_id
                WHERE d.order_id = ? AND d.status <> 'delivered' AND d.status <> 'cancelled'
                LIMIT 1
            ");
            $stCourier->execute([$orderId]);
            $courierInfo = $stCourier->fetch(PDO::FETCH_ASSOC) ?: null;

            // Обновляем статус доставки на cancelled
            $pdo->prepare("UPDATE deliveries SET status = 'cancelled' WHERE order_id = ? AND status <> 'delivered'")->execute([$orderId]);

            // Логируем событие в delivery_events, чтобы видно было в истории
            if ($courierInfo && !empty($courierInfo['delivery_id'])) {
                add_delivery_event($pdo, (int)$courierInfo['delivery_id'], 'cancelled', [
                    'by'     => 'admin',
                    'reason' => $reason,
                ]);
            }

            // Шлём уведомление курьеру в Telegram, если он привязан
            if ($courierInfo && !empty($courierInfo['telegram_chat_id'])) {
                $courierName = trim((string)($courierInfo['courier_name'] ?? '')) ?: 'Курьер';
                $notifyText = "❌ <b>Заказ #{$orderId} отменён</b>\n"
                            . "Доставка #" . (int)$courierInfo['delivery_id'] . " снята с вас.\n"
                            . "Причина: " . htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n"
                            . "Можно вернуться к свободным доставкам.";
                try {
                    flora_send_message((int)$courierInfo['telegram_chat_id'], $notifyText);
                } catch (Throwable $te) {
                    // не валим основной флоу, если телега упала
                }
            }

            audit_log_user_action($pdo, 'order_cancel', 'order', $orderId, [
                'reason'      => $reason,
                'delivery_id' => $courierInfo['delivery_id'] ?? null,
                'courier_id'  => $courierInfo['courier_id']  ?? null,
                'courier_notified' => !empty($courierInfo['telegram_chat_id']),
            ]);
            $ok = 'Заказ отменён.' . (!empty($courierInfo['telegram_chat_id']) ? ' Курьер уведомлён в Telegram.' : '');
        }

        if ($action === 'upload_photo_report') {
            if (empty($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'] ?? '')) {
                throw new RuntimeException('Выберите файл изображения.');
            }
            $f = $_FILES['photo'];
            if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Ошибка загрузки файла (код ' . (int)$f['error'] . ').');
            }
            if ($f['size'] > 8 * 1024 * 1024) {
                throw new RuntimeException('Файл больше 8 МБ.');
            }
            $mime = function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : null;
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[(string)$mime])) {
                throw new RuntimeException('Допустимы только JPG, PNG, WEBP.');
            }
            $ext = $allowed[$mime];

            $dirRel = 'img/photo_reports';
            $dirAbs = __DIR__ . '/' . $dirRel;
            if (!is_dir($dirAbs)) {
                @mkdir($dirAbs, 0775, true);
            }
            if (!is_writable($dirAbs)) {
                throw new RuntimeException('Папка для фото недоступна для записи: /' . $dirRel);
            }

            $fileName = 'order_' . $orderId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $pathRel = $dirRel . '/' . $fileName;
            $pathAbs = $dirAbs . '/' . $fileName;

            if (!move_uploaded_file($f['tmp_name'], $pathAbs)) {
                throw new RuntimeException('Не удалось сохранить файл.');
            }

            // Удалим старое фото, если было
            $oldPhoto = (string)$pdo->query("SELECT photo_report FROM orders WHERE id = " . (int)$orderId)->fetchColumn();
            if ($oldPhoto !== '' && file_exists(__DIR__ . '/' . $oldPhoto) && $oldPhoto !== $pathRel) {
                @unlink(__DIR__ . '/' . $oldPhoto);
            }

            $pdo->prepare("UPDATE orders SET photo_report = ?, photo_report_at = NOW(), photo_report_by = ? WHERE id = ?")
                ->execute([$pathRel, (int)($me['id'] ?? 0), $orderId]);

            audit_log_user_action($pdo, 'order_photo_report_upload', 'order', $orderId, ['path' => $pathRel]);
            $ok = 'Фото добавлено. Клиент увидит его на странице отслеживания.';
        }

        if ($action === 'remove_photo_report') {
            $oldPhoto = (string)$pdo->query("SELECT photo_report FROM orders WHERE id = " . (int)$orderId)->fetchColumn();
            if ($oldPhoto !== '' && file_exists(__DIR__ . '/' . $oldPhoto)) {
                @unlink(__DIR__ . '/' . $oldPhoto);
            }
            $pdo->prepare("UPDATE orders SET photo_report = NULL, photo_report_at = NULL, photo_report_by = NULL WHERE id = ?")
                ->execute([$orderId]);
            audit_log_user_action($pdo, 'order_photo_report_remove', 'order', $orderId);
            $ok = 'Фото удалено.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

// Самовосстановление: гарантируем колонки фото-отчёта, иначе o.* их не вернёт
// и заказ всегда покажет «Фото не требуется», даже если клиент просил фото.
ensure_order_photo_columns($pdo);

$extraSql = [];
$extraSql[] = $hasDeliveryMethod ? "COALESCE(o.delivery_method, 'delivery') AS delivery_method" : "NULL::text AS delivery_method";
$extraSql[] = $hasCancelReason ? "COALESCE(o.cancel_reason, '') AS cancel_reason" : "NULL::text AS cancel_reason";
$st = $pdo->prepare("\n    SELECT\n        o.*,\n        " . implode(', ', $extraSql) . ",\n        d.id AS delivery_id,\n        d.status AS delivery_status,\n        d.courier_id,\n        d.assigned_at,\n        d.delivered_at,\n        d.eta,\n        u.name AS courier_name\n    FROM orders o\n    LEFT JOIN deliveries d ON d.order_id = o.id\n    LEFT JOIN users u ON u.id = d.courier_id\n    WHERE o.id = ?\n    LIMIT 1\n");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    header('Location: admin_orders.php');
    exit;
}

$stI = $pdo->prepare("\n    SELECT oi.qty, oi.price, p.name, p.image\n    FROM order_items oi\n    LEFT JOIN products p ON p.id = oi.product_id\n    WHERE oi.order_id = ?\n    ORDER BY oi.id ASC\n");
$stI->execute([$orderId]);
$items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

$events = [];
if (!empty($order['delivery_id'])) {
    $stE = $pdo->prepare('SELECT event, meta_json, created_at FROM delivery_events WHERE delivery_id = ? ORDER BY id DESC LIMIT 30');
    $stE->execute([(int)$order['delivery_id']]);
    $events = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$courierOrder = $hasOnShift ? 'COALESCE(on_shift, FALSE) DESC, name ASC' : 'name ASC';
$stC = $pdo->query("SELECT id, name" . ($hasOnShift ? ", on_shift" : ", FALSE AS on_shift") . " FROM users WHERE role = 'courier' ORDER BY $courierOrder");
$couriers = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];

$method = infer_delivery_method($order, $hasDeliveryMethod);
$commentData = split_comment_parts($order['comment'] ?? '');
$placeholder = placeholder_image();
$recipient = trim((string)($order['recipient_name'] ?? ''));
$slot = trim((string)($order['delivery_slot'] ?? ''));
$entrance = detail_value($commentData['details'], 'Подъезд');
$floor = detail_value($commentData['details'], 'Этаж');
$domofon = detail_value($commentData['details'], 'Домофон');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Админ — Заказ #<?= (int)$orderId ?></title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .stack{display:grid;gap:16px}
    .grid{display:grid;grid-template-columns:minmax(320px,.92fr) minmax(0,1.08fr);gap:16px;align-items:start}
    .box{background:#fff;border:1px solid #eef2f7;border-radius:18px;box-shadow:var(--shadow-1);padding:18px}
    .hero{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .mini{display:grid;grid-template-columns:150px 1fr;gap:14px;padding:12px 0;border-bottom:1px solid #eef2f7}
    .mini:last-child{border-bottom:none;padding-bottom:0}
    .label{font-size:13px;color:#6b7280;font-weight:700}
    .value{font-size:15px;color:#0f172a;font-weight:600;line-height:1.6}
    .tag{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;border:1px solid #d7eadf;background:#f2faf6;color:#185a37;font-weight:700;font-size:12px}
    .tag--gray{border-color:#e5e7eb;background:#f8fafc;color:#475569}
    .tag--accent{border-color:#dbeafe;background:#eff6ff;color:#1d4ed8}
    .tag--danger{border-color:#fecdd3;background:#fff1f2;color:#be123c}
    .tag--ok{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}
    .notice{padding:12px 14px;border-radius:14px}
    .notice--err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .notice--ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .item{display:grid;grid-template-columns:72px 1fr auto;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid #eef2f7}
    .item:last-child{border-bottom:none}
    .item-photo{width:72px;height:72px;border-radius:16px;overflow:hidden;background:#f3f4f6;border:1px solid #edf1ef}
    .item-photo img{width:100%;height:100%;object-fit:cover;display:block}
    .chips{display:flex;gap:8px;flex-wrap:wrap}
    .chip{padding:8px 12px;border-radius:999px;background:#f8faf9;border:1px solid #e3ebe6;font-size:13px;color:#294252;font-weight:700}
    /* ── Timeline ── */
    .tl{display:grid;gap:0;padding:4px 0}
    .tl-item{display:grid;grid-template-columns:28px 1fr;gap:0 10px}
    .tl-dot-col{display:flex;flex-direction:column;align-items:center}
    .tl-dot{width:11px;height:11px;border-radius:50%;background:#0f6b45;border:2px solid #fff;box-shadow:0 0 0 2px #0f6b45;flex-shrink:0;margin-top:4px}
    .tl-dot--muted{background:#94a3b8;box-shadow:0 0 0 2px #94a3b8}
    .tl-line{flex:1;width:2px;background:#e2ebe6;margin:4px 0 0}
    .tl-body{padding:0 0 18px}
    .tl-item:last-child .tl-body{padding-bottom:0}
    .tl-name{font-size:13.5px;font-weight:700;color:#0f172a;line-height:1.4}
    .tl-time{font-size:12px;color:#94a3b8;margin-top:2px}
    .tl-meta{margin-top:5px;display:flex;flex-wrap:wrap;gap:5px}
    .tl-meta-tag{font-size:11.5px;font-weight:600;padding:3px 8px;border-radius:999px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}
    @media (max-width:950px){.grid,.form-grid{grid-template-columns:1fr}}
    @media (max-width:640px){.mini{grid-template-columns:1fr}.item{grid-template-columns:60px 1fr}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<section class="section">
  <div class="container stack">
    <section class="box">
      <div class="hero">
        <div>
          <h1 class="section__title" style="margin-bottom:6px">Заказ #<?= (int)$orderId ?></h1>
          <div class="mute">Создан: <?= h($order['created_at'] ?? '') ?></div>
          <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
            <span class="<?= h(delivery_method_badge_class($method)) ?>"><?= h(delivery_method_label($method)) ?></span>
            <span class="<?= h(order_status_class((string)($order['status'] ?? 'new'))) ?>">Заказ: <?= h(order_status_label((string)($order['status'] ?? 'new'))) ?></span>
            <?php if ($method === 'delivery'): ?><span class="<?= h(delivery_status_class((string)($order['delivery_status'] ?? ''))) ?>">Доставка: <?= h(delivery_status_label((string)($order['delivery_status'] ?? ''))) ?></span><?php endif; ?>
          </div>
        </div>
        <a class="btn btn--ghost" href="admin_orders.php">Назад к заказам</a>
      </div>
      <?php if ($err): ?><div class="notice notice--err" style="margin-top:12px"><?= h($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="notice notice--ok" style="margin-top:12px"><?= h($ok) ?></div><?php endif; ?>
    </section>

    <div class="grid">
      <section class="box stack">
        <div>
          <h2 style="margin:0 0 14px;font-size:20px">Информация по заказу</h2>
          <div class="mini"><div class="label">Получатель</div><div class="value"><?= h($recipient !== '' ? $recipient : '—') ?></div></div>
          <div class="mini"><div class="label">Телефон</div><div class="value"><?= h($order['phone'] ?? '—') ?></div></div>
          <?php
            $fullAddr = trim((string)($order['address'] ?? ''));
            $fullAddr = preg_replace('/^Адрес:\s*/ui', '', $fullAddr);
            if ($fullAddr === '') $fullAddr = $method === 'pickup' ? 'Самовывоз из магазина FloraExpress' : '—';
          ?>
          <div class="mini"><div class="label"><?= $method === 'pickup' ? 'Место выдачи' : 'Адрес' ?></div><div class="value"><?= h($fullAddr) ?></div></div>
          <?php if ($slot !== ''): ?><div class="mini"><div class="label"><?= $method === 'pickup' ? 'Время выдачи' : 'Слот' ?></div><div class="value"><?= h($slot) ?></div></div><?php endif; ?>
          <?php if ($method === 'delivery' && ($entrance !== '' || $floor !== '' || $domofon !== '')): ?>
            <div class="mini"><div class="label">Детали адреса</div><div class="value"><div class="chips"><?php if ($entrance !== ''): ?><span class="chip">Подъезд: <?= h($entrance) ?></span><?php endif; ?><?php if ($floor !== ''): ?><span class="chip">Этаж: <?= h($floor) ?></span><?php endif; ?><?php if ($domofon !== ''): ?><span class="chip">Домофон: <?= h($domofon) ?></span><?php endif; ?></div></div></div>
          <?php endif; ?>
          <?php if (($commentData['note'] ?? '') !== ''): ?><div class="mini"><div class="label">Комментарий</div><div class="value"><?= h($commentData['note']) ?></div></div><?php endif; ?>
          <?php if (!empty($order['cancel_reason'])): ?><div class="mini"><div class="label">Причина отмены</div><div class="value" style="color:#be123c"><?= h($order['cancel_reason']) ?></div></div><?php endif; ?>
          <?php if ((float)($order['delivery_fee'] ?? 0) > 0): ?>
            <div class="mini"><div class="label">Доставка</div><div class="value"><?= number_format((float)$order['delivery_fee'], 0, ',', ' ') ?> ₽</div></div>
            <?php
              $dMeta = null;
              if (!empty($order['delivery_calc_meta'])) {
                  $dMeta = is_array($order['delivery_calc_meta']) ? $order['delivery_calc_meta'] : json_decode((string)$order['delivery_calc_meta'], true);
              }
              if (is_array($dMeta) && !empty($dMeta['zone']['name'])):
            ?>
              <div class="mini">
                <div class="label">Зона/ETA</div>
                <div class="value" style="font-weight:500">
                  <?= h((string)$dMeta['zone']['name']) ?>
                  <?php if (!empty($dMeta['distance_km'])): ?> · <?= number_format((float)$dMeta['distance_km'], 1) ?> км<?php endif; ?>
                  <?php if (!empty($dMeta['eta_min'])): ?> · ETA <?= (int)$dMeta['eta_min'] ?> мин<?php endif; ?>
                  <?php if (!empty($dMeta['modifiers'])): ?>
                    <div class="chips" style="margin-top:6px">
                      <?php foreach ((array)$dMeta['modifiers'] as $mfx): ?>
                        <span class="chip" style="background:#fff7ed;border-color:#fed7aa;color:#9a3412"><?= h((string)($mfx['name'] ?? '')) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <div class="mini"><div class="label">Сумма</div><div class="value" style="font-size:22px;font-weight:900"><?= number_format((float)($order['total'] ?? 0), 0, ',', ' ') ?> ₽</div></div>
        </div>

        <div>
          <h2 style="margin:0 0 14px;font-size:20px">Управление</h2>
          <div class="stack">
            <form class="box" style="padding:14px" method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="order_status">
              <div class="label" style="margin-bottom:8px">Статус заказа</div>
              <div class="form-grid">
                <select class="input" name="status">
                  <?php foreach (['new'=>'Новый','paid'=>'Оплачен','assembling'=>'Собирается','on_delivery'=>'Передан в доставку','delivered'=>'Доставлен','canceled'=>'Отменён'] as $k => $v): ?>
                    <option value="<?= h($k) ?>" <?= (string)($order['status'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Сохранить статус</button>
              </div>
            </form>

            <?php if ($method === 'delivery'): ?>
              <?php if (empty($order['courier_id'])): $isReleased = !empty($order['released_to_courier']); ?>
              <form class="box" style="padding:14px" method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="release_to_couriers">
                <div class="label" style="margin-bottom:8px">Передача курьерам</div>
                <div style="font-size:13px;color:#6b7280;margin-bottom:10px">
                  <?= $isReleased ? 'Заказ уже передан курьерам — виден во «Свободных». Можно уведомить ещё раз.' : 'Заказ ещё не передан. Курьеры его не видят, пока вы не нажмёте кнопку.' ?>
                </div>
                <button class="btn" type="submit"><?= $isReleased ? 'Уведомить курьеров ещё раз' : 'Готово — передать курьерам' ?></button>
              </form>
              <?php endif; ?>

              <form class="box" style="padding:14px" method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="assign_courier">
                <div class="label" style="margin-bottom:8px">Назначить курьера</div>
                <div class="form-grid">
                  <select class="input" name="courier_id">
                    <option value="">Выберите курьера</option>
                    <?php foreach ($couriers as $courier): ?>
                      <option value="<?= (int)$courier['id'] ?>" <?= (int)($order['courier_id'] ?? 0) === (int)$courier['id'] ? 'selected' : '' ?>><?= h($courier['name'] ?? ('Курьер #' . (int)$courier['id'])) ?><?= !empty($courier['on_shift']) ? ' — на смене' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn" type="submit">Назначить</button>
                </div>
              </form>

              <?php if (!empty($order['delivery_id'])): ?>
                <form class="box" style="padding:14px" method="post">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delivery_status">
                  <input type="hidden" name="delivery_id" value="<?= (int)$order['delivery_id'] ?>">
                  <div class="label" style="margin-bottom:8px">Статус доставки</div>
                  <div class="form-grid">
                    <select class="input" name="status">
                      <?php foreach (['pending'=>'Ожидает назначения','assigned'=>'Назначен курьер','picked'=>'Курьер забрал заказ','en_route'=>'В пути','delivered'=>'Доставлен','failed'=>'Не удалось доставить','cancelled'=>'Доставка отменена'] as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= (string)($order['delivery_status'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn" type="submit">Сохранить доставку</button>
                  </div>
                </form>
              <?php endif; ?>
            <?php endif; ?>

            <?php
              $photoRequested = !empty($order['photo_report_requested']) && (string)$order['photo_report_requested'] !== 'f';
            ?>
            <div class="box" style="padding:14px<?= $photoRequested ? ';border:1.5px solid #f59e0b;background:#fffbeb' : '' ?>">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap">
                <div class="label" style="margin:0">Фото-отчёт от флориста</div>
                <?php if ($photoRequested): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#f59e0b;color:#fff;border-radius:999px;font-size:11.5px;font-weight:700;letter-spacing:.02em">📸 КЛИЕНТ ПРОСИТ ФОТО</span>
                <?php else: ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#e5e7eb;color:#6b7280;border-radius:999px;font-size:11.5px;font-weight:700;letter-spacing:.02em">Фото не требуется</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($order['photo_report'])): ?>
                <div style="display:grid;gap:10px">
                  <a href="<?= h((string)$order['photo_report']) ?>" target="_blank" style="display:block;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">
                    <img src="<?= h((string)$order['photo_report']) ?>" alt="Фото-отчёт" style="width:100%;height:auto;display:block;max-height:280px;object-fit:cover">
                  </a>
                  <div class="mute" style="font-size:12px">
                    Загружено: <?= h(substr((string)($order['photo_report_at'] ?? ''), 0, 16)) ?>
                  </div>
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="upload_photo_report">
                      <input class="input" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                      <button class="btn" type="submit">Заменить</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Удалить фото-отчёт?')">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="remove_photo_report">
                      <button class="btn btn--ghost" type="submit" style="color:#b91c1c">Удалить</button>
                    </form>
                  </div>
                </div>
              <?php else: ?>
                <form method="post" enctype="multipart/form-data" style="display:grid;gap:10px">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="upload_photo_report">
                  <input class="input" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                  <div class="mute" style="font-size:12px">
                    <?php if ($photoRequested): ?>
                      <strong style="color:#92400e">Клиент попросил фото при оформлении заказа.</strong> Сделайте снимок готового букета перед отправкой — клиент увидит его на странице отслеживания. JPG/PNG/WEBP, до 8 МБ.
                    <?php else: ?>
                      Клиент не запрашивал фото-отчёт, но при желании можно загрузить. JPG/PNG/WEBP, до 8 МБ.
                    <?php endif; ?>
                  </div>
                  <div><button class="btn" type="submit">Загрузить фото</button></div>
                </form>
              <?php endif; ?>
            </div>

            <form class="box" style="padding:14px" method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="cancel_order">
              <div class="label" style="margin-bottom:8px">Отмена заказа</div>
              <div class="stack" style="gap:10px">
                <textarea class="input" name="cancel_reason" rows="4" placeholder="Укажите причину отмены" style="border-radius:16px;padding-top:12px;padding-bottom:12px;resize:vertical"></textarea>
                <div><button class="btn" type="submit" onclick="return confirm('Отменить заказ?')">Отменить заказ</button></div>
              </div>
            </form>
          </div>
        </div>
      </section>

      <section class="box stack">
        <div>
          <h2 style="margin:0 0 14px;font-size:20px">Состав заказа</h2>
          <?php if ($items): ?>
            <?php foreach ($items as $item): ?>
              <div class="item">
                <div class="item-photo"><img src="<?= h(image_src($item['image'] ?? '', $placeholder)) ?>" alt="<?= h($item['name'] ?? 'Товар') ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';"></div>
                <div>
                  <div style="font-size:16px;font-weight:800"><?= h($item['name'] ?? 'Товар') ?></div>
                  <div class="mute"><?= (int)($item['qty'] ?? 0) ?> × <?= number_format((float)($item['price'] ?? 0), 0, ',', ' ') ?> ₽</div>
                </div>
                <div style="font-size:18px;font-weight:900;white-space:nowrap"><?= number_format((float)($item['qty'] ?? 0) * (float)($item['price'] ?? 0), 0, ',', ' ') ?> ₽</div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="mute">Состав заказа пуст.</div>
          <?php endif; ?>
        </div>

        <?php if ($method === 'delivery' && $events): ?>
          <div>
            <h2 style="margin:0 0 16px;font-size:20px">История доставки</h2>
            <?php
              $eventLabels = [
                'assign'        => 'Курьер назначен',
                'assigned'      => 'Курьер назначен',
                'status'        => 'Статус изменён',
                'picked_up'     => 'Курьер забрал заказ',
                'picked'        => 'Курьер забрал заказ',
                'on_the_way'    => 'Курьер в пути',
                'en_route'      => 'Курьер в пути',
                'delivered'     => 'Заказ доставлен',
                'failed'        => 'Не удалось доставить',
                'cancelled'     => 'Доставка отменена',
                'created'       => 'Доставка создана',
              ];
              $statusLabels = [
                'pending'   => 'Ожидает назначения',
                'assigned'  => 'Назначен курьер',
                'picked'    => 'Курьер забрал заказ',
                'en_route'  => 'В пути',
                'delivered' => 'Доставлен',
                'failed'    => 'Не удалось доставить',
                'cancelled' => 'Отменена',
              ];
              $reversedEvents = array_reverse($events);
            ?>
            <div class="tl">
              <?php
                $byLabels     = ['admin' => 'Администратор', 'courier' => 'Курьер', 'system' => 'Система'];
                foreach ($reversedEvents as $idx => $ev):
                  $evKey  = strtolower(trim((string)($ev['event'] ?? '')));
                  $evName = $eventLabels[$evKey] ?? ucfirst(str_replace('_', ' ', $evKey)) ?: 'Событие';

                  $meta = [];
                  if (!empty($ev['meta_json'])) {
                      $meta = json_decode($ev['meta_json'], true) ?: [];
                  }

                  $tags = [];
                  if (!empty($meta['new'])) {
                      $tags[] = 'Новый статус: ' . ($statusLabels[$meta['new']] ?? $meta['new']);
                  }
                  if (!empty($meta['old'])) {
                      $tags[] = 'Был: ' . ($statusLabels[$meta['old']] ?? $meta['old']);
                  }
                  if (!empty($meta['by'])) {
                      $tags[] = $byLabels[$meta['by']] ?? $meta['by'];
                  }
                  if (!empty($meta['courier_id'])) {
                      $tags[] = 'Курьер #' . (int)$meta['courier_id'];
                  }
                  foreach ($meta as $k => $v) {
                      if (in_array($k, ['new','old','by','courier_id'], true)) continue;
                      if (is_scalar($v) && $v !== '') $tags[] = h($k) . ': ' . h((string)$v);
                  }

                  $isLast = $idx === array_key_last($reversedEvents);
              ?>
                <div class="tl-item">
                  <div class="tl-dot-col">
                    <div class="tl-dot <?= $isLast ? '' : 'tl-dot--muted' ?>"></div>
                    <?php if (!$isLast): ?><div class="tl-line"></div><?php endif; ?>
                  </div>
                  <div class="tl-body">
                    <div class="tl-name"><?= h($evName) ?></div>
                    <div class="tl-time"><?= h($ev['created_at'] ?? '') ?></div>
                    <?php if ($tags): ?>
                      <div class="tl-meta">
                        <?php foreach ($tags as $tag): ?>
                          <span class="tl-meta-tag"><?= $tag ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
