<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

$me = $_SESSION['user'] ?? null;
if (!$me) {
    header('Location: auth.php?mode=login&return=' . urlencode($_SERVER['REQUEST_URI'] ?? 'account_orders.php'));
    exit;
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: account_orders.php');
    exit;
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$st = $pdo->prepare("
    SELECT oi.product_id, oi.qty, p.is_active
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ? AND o.user_id = ?
");
$st->execute([$orderId, (int)$me['id']]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    header('Location: order_view.php?id=' . $orderId . '&repeat=0');
    exit;
}

$added = 0;
foreach ($rows as $r) {
    if ((int)$r['is_active'] !== 1) {
        continue;
    }

    $pid = (int)$r['product_id'];
    $qty = max(1, (int)$r['qty']);

    if (!isset($_SESSION['cart'][$pid])) {
        $_SESSION['cart'][$pid] = 0;
    }

    $_SESSION['cart'][$pid] += $qty;
    $added += $qty;
}

header('Location: cart.php?repeated=' . ($added > 0 ? 1 : 0));
exit;