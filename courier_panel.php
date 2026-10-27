<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

function lower_u(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}
function deliveryStatusLabel(string $status): string {
    $map = [
        'pending'   => 'Ожидает назначения',
        'assigned'  => 'Назначен',
        'picked'    => 'Заказ забран',
        'en_route'  => 'В пути',
        'delivered' => 'Доставлен',
        'failed'    => 'Не удалось доставить',
        'cancelled' => 'Отменена',
    ];
    return $map[$status] ?? $status;
}
function deliveryStatusClass(string $status): string {
    if ($status === 'delivered') return 'is-ok';
    if ($status === 'failed' || $status === 'cancelled') return 'is-danger';
    if ($status === 'pending') return 'is-muted';
    return 'is-accent';
}
function addDeliveryEvent(PDO $pdo, int $deliveryId, string $event, array $meta = []): void {
    $st = $pdo->prepare("INSERT INTO delivery_events (delivery_id, event, meta_json) VALUES (?, ?, ?)");
    $st->execute([$deliveryId, $event, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}
function fmt_dt(?string $value): string {
    $raw = trim((string)$value);
    if ($raw === '') return '—';
    $ts = strtotime($raw);
    if ($ts === false) return $raw;
    return date('d.m.Y H:i', $ts);
}
function fmt_date(?string $value): string {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $ts = strtotime($raw);
    if ($ts === false) return $raw;
    return date('d.m.Y', $ts);
}
function short_address_local(?string $address): string {
    $address = trim((string)$address);
    if ($address === '') return '—';
    $tokens = array_values(array_filter(array_map('trim', explode(',', $address)), static fn($v) => $v !== ''));
    if (!$tokens) return $address;
    $filtered = [];
    foreach ($tokens as $token) {
        $low = lower_u($token);
        if (preg_match('~^\d{5,6}$~u', $token)) continue;
        if (preg_match('~\b(россия|российская федерация|область|край|республика|автономный округ|федеральный округ|район|городской округ)\b~u', $low)) continue;
        $filtered[] = $token;
    }
    if (!$filtered) $filtered = $tokens;
    return implode(', ', array_slice($filtered, 0, 3));
}
function known_slots(): array {
    return ['10:00–13:00','13:00–16:00','16:00–19:00','19:00–21:00','10:00-13:00','13:00-16:00','16:00-19:00','19:00-21:00'];
}

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode('courier_panel.php'));
    exit;
}

$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['courier','admin','manager'], true)) {
    http_response_code(403);
    echo '403';
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex((string)uniqid('', true));
}
$csrf = $_SESSION['csrf'];

$err = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
            throw new RuntimeException('Сессия устарела. Обновите страницу.');
        }

        $action = (string)($_POST['action'] ?? '');
        $deliveryId = (int)($_POST['delivery_id'] ?? 0);
        if ($deliveryId <= 0) {
            throw new RuntimeException('Некорректная доставка.');
        }

        $st = $pdo->prepare("SELECT * FROM deliveries WHERE id = ? LIMIT 1");
        $st->execute([$deliveryId]);
        $delivery = $st->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) {
            throw new RuntimeException('Доставка не найдена.');
        }

        $courierId = (int)$me['id'];
        $currentCourierId = isset($delivery['courier_id']) ? (int)$delivery['courier_id'] : 0;
        $currentStatus = (string)($delivery['status'] ?? 'pending');
        $orderId = (int)$delivery['order_id'];

        if ($action === 'take') {
            if ($currentStatus !== 'pending' && $currentCourierId !== 0 && $currentCourierId !== $courierId) {
                throw new RuntimeException('Эта доставка уже назначена другому курьеру.');
            }

            $stTake = $pdo->prepare("UPDATE deliveries SET courier_id = ?, status = 'assigned', assigned_at = COALESCE(assigned_at, CURRENT_TIMESTAMP) WHERE id = ? AND courier_id IS NULL AND status = 'pending'");
            $stTake->execute([$courierId, $deliveryId]);
            if ($stTake->rowCount() < 1) {
                throw new RuntimeException('Эту доставку уже взял другой курьер.');
            }

            addDeliveryEvent($pdo, $deliveryId, 'assigned', [
                'by' => 'courier_panel',
                'courier_id' => $courierId,
                'order_id' => $orderId
            ]);
            $ok = 'Доставка назначена на вас.';
        }

        if ($action === 'set_status') {
            $new = trim((string)($_POST['status'] ?? ''));
            $allowed = ['assigned','picked','en_route','delivered','failed'];
            if (!in_array($new, $allowed, true)) {
                throw new RuntimeException('Недопустимый статус.');
            }
            if ($currentCourierId !== $courierId && !in_array($role, ['admin','manager'], true)) {
                throw new RuntimeException('Можно менять только свои доставки.');
            }

            if ($new === 'delivered') {
                $pdo->prepare("UPDATE deliveries SET status = ?, delivered_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$new, $deliveryId]);
                $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?")->execute([$orderId]);
            } else {
                $pdo->prepare("UPDATE deliveries SET status = ? WHERE id = ?")->execute([$new, $deliveryId]);
                if ($new === 'en_route') {
                    $pdo->prepare("UPDATE orders SET status = 'on_delivery' WHERE id = ?")->execute([$orderId]);
                }
            }

            addDeliveryEvent($pdo, $deliveryId, 'status', [
                'new' => $new,
                'by' => 'courier_panel',
                'courier_id' => $courierId,
                'order_id' => $orderId
            ]);
            $ok = 'Статус доставки обновлён.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$tab = (string)($_GET['tab'] ?? 'active');
if (!in_array($tab, ['active', 'archive'], true)) $tab = 'active';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$slotFilter = trim((string)($_GET['slot'] ?? ''));
$orderFilter = trim((string)($_GET['sort'] ?? 'nearest'));
if (!in_array($orderFilter, ['nearest', 'newest'], true)) $orderFilter = 'nearest';

$activeStatuses = ['pending','assigned','picked','en_route'];
$archiveStatuses = ['delivered','failed','cancelled'];

$whereCommon = [];
$argsCommon = [];
if ($dateFrom !== '') {
    $whereCommon[] = 'DATE(o.created_at) >= ?';
    $argsCommon[] = $dateFrom;
}
if ($dateTo !== '') {
    $whereCommon[] = 'DATE(o.created_at) <= ?';
    $argsCommon[] = $dateTo;
}
if ($slotFilter !== '') {
    $whereCommon[] = 'COALESCE(o.delivery_slot, \'\') = ?';
    $argsCommon[] = $slotFilter;
}

$orderBy = $orderFilter === 'nearest'
    ? "CASE COALESCE(o.delivery_slot, '') WHEN '10:00–13:00' THEN 1 WHEN '10:00-13:00' THEN 1 WHEN '13:00–16:00' THEN 2 WHEN '13:00-16:00' THEN 2 WHEN '16:00–19:00' THEN 3 WHEN '16:00-19:00' THEN 3 WHEN '19:00–21:00' THEN 4 WHEN '19:00-21:00' THEN 4 ELSE 99 END ASC, o.created_at DESC"
    : 'o.created_at DESC';

$freeDeliveries = [];
$myDeliveries = [];

try {
    $freeWhere = ["o.status <> 'canceled'"];
    $myWhere = ['1=1'];
    $freeArgs = [];
    $myArgs = [(int)$me['id']];

    foreach ($whereCommon as $piece) {
        $freeWhere[] = $piece;
        $myWhere[] = $piece;
    }
    foreach ($argsCommon as $arg) {
        $freeArgs[] = $arg;
        $myArgs[] = $arg;
    }

    if ($tab === 'active') {
        $freeWhere[] = "d.status = 'pending'";
        $freeWhere[] = 'd.courier_id IS NULL';
        $myWhere[] = "d.status IN ('assigned','picked','en_route')";
        $myWhere[] = "COALESCE(o.status, '') <> 'canceled'";
    } else {
        $freeWhere[] = '1=0';
        $myWhere[] = "(d.status IN ('delivered','failed','cancelled') OR COALESCE(o.status, '') IN ('delivered','canceled'))";
    }

    $sqlSelect = "SELECT d.*, o.address, o.phone, o.comment, o.total, o.created_at, o.recipient_name, o.delivery_slot, o.status AS order_status";
    $sqlFrom = ' FROM deliveries d JOIN orders o ON o.id = d.order_id ';

    $stMine = $pdo->prepare($sqlSelect . $sqlFrom . ' WHERE d.courier_id = ? AND ' . implode(' AND ', $myWhere) . ' ORDER BY ' . $orderBy . ' LIMIT 100');
    $stMine->execute($myArgs);
    $myDeliveries = $stMine->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($tab === 'active') {
        $stFree = $pdo->prepare($sqlSelect . $sqlFrom . ' WHERE ' . implode(' AND ', $freeWhere) . ' ORDER BY ' . $orderBy . ' LIMIT 100');
        $stFree->execute($freeArgs);
        $freeDeliveries = $stFree->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

$slotOptions = array_values(array_unique(array_filter(array_map('trim', array_merge(
    known_slots(),
    array_map(static fn($row) => (string)($row['delivery_slot'] ?? ''), $myDeliveries),
    array_map(static fn($row) => (string)($row['delivery_slot'] ?? ''), $freeDeliveries)
)), static fn($v) => $v !== '')));

$freeCount = count($freeDeliveries);
$mineCount = count($myDeliveries);
$enRouteCount = 0;
$doneCount = 0;
foreach ($myDeliveries as $d) {
    if (($d['status'] ?? '') === 'en_route') $enRouteCount++;
    if (($d['status'] ?? '') === 'delivered') $doneCount++;
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Панель курьера — FloraExpress</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .cp-wrap{display:grid;gap:18px}
    .cp-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
    .cp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .cp-stat{background:#fff;border:1px solid #e7efe9;border-radius:18px;box-shadow:var(--shadow-1);padding:16px}
    .cp-stat__label{color:#6b7280;font-size:13px;margin-bottom:6px}
    .cp-stat__value{font-size:28px;font-weight:900;color:#111827}
    .cp-filter{background:linear-gradient(135deg,#eef8f1 0%,#eef8f1 30%,#ffffff 30%,#ffffff 100%);border:1px solid #dbeadf;border-radius:20px;box-shadow:var(--shadow-1);padding:16px}
    .cp-filter__top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
    .cp-tabs{display:flex;gap:8px;flex-wrap:wrap}
    .cp-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}
    .cp-section{background:#fff;border:1px solid #e7efe9;border-radius:22px;box-shadow:var(--shadow-1);padding:16px}
    .cp-section__head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
    .cp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
    .delivery-card{position:relative;background:linear-gradient(135deg,#edf8f1 0%,#edf8f1 18%,#ffffff 18%,#ffffff 100%);border:1px solid #e2ece5;border-radius:20px;box-shadow:var(--shadow-1);padding:16px;display:flex;flex-direction:column;gap:12px;min-height:100%}
    .delivery-card__top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
    .delivery-card__id{font-size:24px;font-weight:900;line-height:1;color:#111827}
    .delivery-card__meta{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .delivery-badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #d7eadf;background:#f4fbf7;color:#185a37;font-weight:800;font-size:12px}
    .delivery-badge.is-accent{background:#eef8f1;border-color:#cfe9d9;color:#155e37}
    .delivery-badge.is-ok{background:#e6f7ec;border-color:#bde3ca;color:#166534}
    .delivery-badge.is-danger{background:#fff1f2;border-color:#fecdd3;color:#9f1239}
    .delivery-badge.is-muted{background:#f8fafc;border-color:#e5e7eb;color:#64748b}
    .delivery-card__date{color:#667085;font-size:13px}
    .delivery-card__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .delivery-mini{background:#fff;border:1px solid #edf2ef;border-radius:14px;padding:10px}
    .delivery-mini__label{font-size:12px;color:#6b7280;margin-bottom:4px}
    .delivery-mini__value{font-weight:800;color:#111827;line-height:1.4;word-break:break-word}
    .delivery-card__address{padding:12px 14px;border:1px solid #edf2ef;border-radius:16px;background:#fff}
    .delivery-card__address-label{font-size:12px;color:#6b7280;margin-bottom:4px}
    .delivery-card__address-value{font-weight:700;line-height:1.5;color:#111827}
    .delivery-card__actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:auto}
    .delivery-card__actions .input{max-width:220px}
    .cp-empty{margin:0;color:#6b7280}
    .notice{padding:10px 12px;border-radius:12px;font-weight:700}
    .notice--err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
    .notice--ok{background:#ecfdf3;border:1px solid #c9f1d8;color:#166534}
    .archive-details{border-top:1px dashed #d7eadf;padding-top:10px}
    .archive-details summary{cursor:pointer;font-weight:700;color:#1f2937}
    .archive-details summary::-webkit-details-marker{display:none}
    @media (max-width:1100px){.cp-stats,.cp-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:700px){.cp-stats,.cp-grid,.delivery-card__grid{grid-template-columns:1fr}.cp-cards{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<section class="section">
  <div class="container cp-wrap">
    <div class="cp-top">
      <div>
        <h1 class="section__title" style="margin:0 0 6px">Панель курьера</h1>
        <p class="section__sub" style="margin:0">Активные доставки, компактные карточки и быстрый архив по дате и времени.</p>
      </div>
    </div>

    <div class="cp-stats">
      <div class="cp-stat"><div class="cp-stat__label">Свободные доставки</div><div class="cp-stat__value"><?= (int)$freeCount ?></div></div>
      <div class="cp-stat"><div class="cp-stat__label">Мои активные</div><div class="cp-stat__value"><?= (int)$mineCount ?></div></div>
      <div class="cp-stat"><div class="cp-stat__label">Сейчас в пути</div><div class="cp-stat__value"><?= (int)$enRouteCount ?></div></div>
      <div class="cp-stat"><div class="cp-stat__label">Архив</div><div class="cp-stat__value"><?= $tab === 'archive' ? (int)$mineCount : '—' ?></div></div>
    </div>

    <?php if ($err): ?><div class="notice notice--err"><?= h($err) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="notice notice--ok"><?= h($ok) ?></div><?php endif; ?>

    <form class="cp-filter" method="get">
      <div class="cp-filter__top">
        <div>
          <div style="font-size:12px;color:#6b7280;margin-bottom:4px">Режим просмотра</div>
          <div class="cp-tabs">
            <a class="btn btn--ghost btn--sm<?= $tab === 'active' ? ' active-filter' : '' ?>" href="courier_panel.php?<?= h(http_build_query(array_filter(['tab' => 'active', 'date_from' => $dateFrom, 'date_to' => $dateTo, 'slot' => $slotFilter, 'sort' => $orderFilter], static fn($v) => $v !== ''))) ?>">Активные</a>
            <a class="btn btn--ghost btn--sm<?= $tab === 'archive' ? ' active-filter' : '' ?>" href="courier_panel.php?<?= h(http_build_query(array_filter(['tab' => 'archive', 'date_from' => $dateFrom, 'date_to' => $dateTo, 'slot' => $slotFilter, 'sort' => $orderFilter], static fn($v) => $v !== ''))) ?>">Архив</a>
          </div>
        </div>
        <a class="btn btn--ghost btn--sm" href="courier_panel.php?tab=<?= $tab === 'archive' ? 'archive' : 'active' ?>">Сбросить фильтры</a>
      </div>

      <div class="cp-grid">
        <label>
          <div class="mute" style="margin-bottom:6px">Дата от</div>
          <input class="input" type="date" name="date_from" value="<?= h($dateFrom) ?>">
        </label>
        <label>
          <div class="mute" style="margin-bottom:6px">Дата до</div>
          <input class="input" type="date" name="date_to" value="<?= h($dateTo) ?>">
        </label>
        <label>
          <div class="mute" style="margin-bottom:6px">Слот / время</div>
          <select class="input" name="slot">
            <option value="">Все интервалы</option>
            <?php foreach ($slotOptions as $slot): ?>
              <option value="<?= h($slot) ?>" <?= $slotFilter === $slot ? 'selected' : '' ?>><?= h($slot) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <div class="mute" style="margin-bottom:6px">Сортировка</div>
          <select class="input" name="sort">
            <option value="nearest" <?= $orderFilter === 'nearest' ? 'selected' : '' ?>>Сначала ближайшие слоты</option>
            <option value="newest" <?= $orderFilter === 'newest' ? 'selected' : '' ?>>Сначала новые заказы</option>
          </select>
        </label>
      </div>

      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn" type="submit">Применить фильтры</button>
        <span class="mute" style="align-self:center">По умолчанию показываются только активные доставки. Доставленные и старые находятся в архиве.</span>
      </div>
    </form>

    <?php if ($tab === 'active'): ?>
      <section class="cp-section">
        <div class="cp-section__head">
          <div>
            <h2 style="margin:0;font-size:22px">Свободные доставки</h2>
            <p class="section__sub" style="margin:4px 0 0">Карточки стали компактнее: важное видно сразу, адрес и сумма не занимают весь экран.</p>
          </div>
          <span class="delivery-badge is-muted"><?= (int)$freeCount ?> шт.</span>
        </div>

        <?php if (!$freeDeliveries): ?>
          <p class="cp-empty">Свободных доставок по текущим фильтрам нет.</p>
        <?php else: ?>
          <div class="cp-cards">
            <?php foreach ($freeDeliveries as $d): ?>
              <article class="delivery-card">
                <div class="delivery-card__top">
                  <div>
                    <div class="delivery-card__id">#<?= (int)$d['order_id'] ?></div>
                    <div class="delivery-card__date">Создан: <?= h(fmt_dt((string)($d['created_at'] ?? ''))) ?></div>
                  </div>
                  <div class="delivery-card__meta">
                    <span class="delivery-badge is-muted">Свободен</span>
                    <?php if (trim((string)($d['delivery_slot'] ?? '')) !== ''): ?><span class="delivery-badge is-accent"><?= h((string)$d['delivery_slot']) ?></span><?php endif; ?>
                  </div>
                </div>

                <div class="delivery-card__grid">
                  <div class="delivery-mini"><div class="delivery-mini__label">Получатель</div><div class="delivery-mini__value"><?= h($d['recipient_name'] ?? '—') ?></div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Сумма</div><div class="delivery-mini__value"><?= number_format((float)($d['total'] ?? 0),0,',',' ') ?> ₽</div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Телефон</div><div class="delivery-mini__value"><?= h($d['phone'] ?? '—') ?></div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Дата заказа</div><div class="delivery-mini__value"><?= h(fmt_date((string)($d['created_at'] ?? ''))) ?></div></div>
                </div>

                <div class="delivery-card__address">
                  <div class="delivery-card__address-label">Адрес</div>
                  <div class="delivery-card__address-value"><?= h(short_address_local($d['address'] ?? '')) ?></div>
                </div>

                <div class="delivery-card__actions">
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="take">
                    <input type="hidden" name="delivery_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn" type="submit">Взять доставку</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="cp-section">
        <div class="cp-section__head">
          <div>
            <h2 style="margin:0;font-size:22px">Мои доставки</h2>
            <p class="section__sub" style="margin:4px 0 0">Только активные назначения. Доставленные и неактуальные заказы ушли в архив.</p>
          </div>
          <span class="delivery-badge is-accent"><?= (int)$mineCount ?> шт.</span>
        </div>

        <?php if (!$myDeliveries): ?>
          <p class="cp-empty">У вас нет активных доставок по выбранным фильтрам.</p>
        <?php else: ?>
          <div class="cp-cards">
            <?php foreach ($myDeliveries as $d): $cur = (string)($d['status'] ?? ''); ?>
              <article class="delivery-card">
                <div class="delivery-card__top">
                  <div>
                    <div class="delivery-card__id">#<?= (int)$d['order_id'] ?></div>
                    <div class="delivery-card__date">Создан: <?= h(fmt_dt((string)($d['created_at'] ?? ''))) ?></div>
                  </div>
                  <div class="delivery-card__meta">
                    <span class="delivery-badge <?= h(deliveryStatusClass($cur)) ?>"><?= h(deliveryStatusLabel($cur)) ?></span>
                    <?php if (trim((string)($d['delivery_slot'] ?? '')) !== ''): ?><span class="delivery-badge is-accent"><?= h((string)$d['delivery_slot']) ?></span><?php endif; ?>
                  </div>
                </div>

                <div class="delivery-card__grid">
                  <div class="delivery-mini"><div class="delivery-mini__label">Получатель</div><div class="delivery-mini__value"><?= h($d['recipient_name'] ?? '—') ?></div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Сумма</div><div class="delivery-mini__value"><?= number_format((float)($d['total'] ?? 0),0,',',' ') ?> ₽</div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Телефон</div><div class="delivery-mini__value"><?= h($d['phone'] ?? '—') ?></div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Дата заказа</div><div class="delivery-mini__value"><?= h(fmt_date((string)($d['created_at'] ?? ''))) ?></div></div>
                </div>

                <div class="delivery-card__address">
                  <div class="delivery-card__address-label">Адрес</div>
                  <div class="delivery-card__address-value"><?= h(short_address_local($d['address'] ?? '')) ?></div>
                </div>

                <form method="post" class="delivery-card__actions">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="delivery_id" value="<?= (int)$d['id'] ?>">

                  <select class="input" name="status">
                    <?php foreach (['assigned' => 'Назначен', 'picked' => 'Заказ забран', 'en_route' => 'В пути', 'delivered' => 'Доставлен', 'failed' => 'Не удалось доставить'] as $key => $label): ?>
                      <option value="<?= h($key) ?>" <?= $cur === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn" type="submit">Обновить статус</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="cp-section">
        <div class="cp-section__head">
          <div>
            <h2 style="margin:0;font-size:22px">Архив доставок</h2>
            <p class="section__sub" style="margin:4px 0 0">Здесь только завершённые, неудачные и уже неактуальные доставки. Можно отфильтровать по дате и слоту.</p>
          </div>
          <span class="delivery-badge is-muted"><?= (int)$mineCount ?> шт.</span>
        </div>

        <?php if (!$myDeliveries): ?>
          <p class="cp-empty">В архиве по выбранным фильтрам ничего нет.</p>
        <?php else: ?>
          <div class="cp-cards">
            <?php foreach ($myDeliveries as $d): $cur = (string)($d['status'] ?? ''); ?>
              <article class="delivery-card">
                <div class="delivery-card__top">
                  <div>
                    <div class="delivery-card__id">#<?= (int)$d['order_id'] ?></div>
                    <div class="delivery-card__date">Создан: <?= h(fmt_dt((string)($d['created_at'] ?? ''))) ?></div>
                  </div>
                  <div class="delivery-card__meta">
                    <span class="delivery-badge <?= h(deliveryStatusClass($cur)) ?>"><?= h(deliveryStatusLabel($cur)) ?></span>
                    <?php if (trim((string)($d['delivery_slot'] ?? '')) !== ''): ?><span class="delivery-badge is-accent"><?= h((string)$d['delivery_slot']) ?></span><?php endif; ?>
                  </div>
                </div>

                <div class="delivery-card__grid">
                  <div class="delivery-mini"><div class="delivery-mini__label">Получатель</div><div class="delivery-mini__value"><?= h($d['recipient_name'] ?? '—') ?></div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Сумма</div><div class="delivery-mini__value"><?= number_format((float)($d['total'] ?? 0),0,',',' ') ?> ₽</div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Телефон</div><div class="delivery-mini__value"><?= h($d['phone'] ?? '—') ?></div></div>
                  <div class="delivery-mini"><div class="delivery-mini__label">Дата заказа</div><div class="delivery-mini__value"><?= h(fmt_date((string)($d['created_at'] ?? ''))) ?></div></div>
                </div>

                <div class="delivery-card__address">
                  <div class="delivery-card__address-label">Адрес</div>
                  <div class="delivery-card__address-value"><?= h(short_address_local($d['address'] ?? '')) ?></div>
                </div>

                <details class="archive-details">
                  <summary>Показать детали заказа</summary>
                  <div style="margin-top:10px;display:grid;gap:10px">
                    <div class="delivery-mini"><div class="delivery-mini__label">Полный адрес</div><div class="delivery-mini__value"><?= h($d['address'] ?? '—') ?></div></div>
                    <?php if (trim((string)($d['comment'] ?? '')) !== ''): ?><div class="delivery-mini"><div class="delivery-mini__label">Комментарий</div><div class="delivery-mini__value"><?= h($d['comment']) ?></div></div><?php endif; ?>
                    <div class="delivery-mini"><div class="delivery-mini__label">Статус заказа</div><div class="delivery-mini__value"><?= h((string)($d['order_status'] ?? '—')) ?></div></div>
                    <?php if (in_array($role, ['admin','manager'], true)): ?>
                      <div><a class="btn btn--ghost btn--sm" href="admin_order_view.php?id=<?= (int)$d['order_id'] ?>">Открыть детали в админке</a></div>
                    <?php endif; ?>
                  </div>
                </details>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
