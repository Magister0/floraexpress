<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$pdo = db();


$me = $_SESSION['user'] ?? null;
if (!$me) { header('Location: auth.php?mode=login&return=' . urlencode('admin_reports.php')); exit; }
$role = (string)($me['role'] ?? 'user');
if (!in_array($role, ['admin','manager'], true)) { http_response_code(403); echo '403'; exit; }

$hasDeliveryMethod = column_exists($pdo, 'orders', 'delivery_method');
$methodExpr = $hasDeliveryMethod
    ? "CASE WHEN COALESCE(o.delivery_method,'')='pickup' OR COALESCE(o.comment,'') ILIKE '%Способ: Самовывоз%' OR o.address ILIKE '%магазин FloraExpress%' OR o.address ILIKE 'Самовывоз%' THEN 'Самовывоз' ELSE 'Доставка' END"
    : "CASE WHEN COALESCE(o.comment,'') ILIKE '%Способ: Самовывоз%' OR o.address ILIKE '%магазин FloraExpress%' OR o.address ILIKE 'Самовывоз%' THEN 'Самовывоз' ELSE 'Доставка' END";

$summary = $pdo->query("SELECT COUNT(*) AS orders_total, COALESCE(SUM(CASE WHEN status <> 'canceled' THEN total ELSE 0 END),0) AS revenue_total, COUNT(*) FILTER (WHERE DATE(created_at)=CURRENT_DATE) AS orders_today, COALESCE(SUM(CASE WHEN DATE(created_at)=CURRENT_DATE AND status <> 'canceled' THEN total ELSE 0 END),0) AS revenue_today FROM orders")->fetch(PDO::FETCH_ASSOC) ?: [];
$methods = $pdo->query("SELECT $methodExpr AS method, COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN o.status <> 'canceled' THEN o.total ELSE 0 END),0) AS revenue FROM orders o GROUP BY 1 ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$topProducts = $pdo->query("SELECT p.name, SUM(oi.qty) AS qty, COALESCE(SUM(oi.qty * oi.price),0) AS revenue FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id WHERE o.status <> 'canceled' GROUP BY p.id, p.name ORDER BY qty DESC, revenue DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$days = $pdo->query("SELECT DATE(created_at) AS day, COUNT(*) AS orders_cnt, COALESCE(SUM(CASE WHEN status <> 'canceled' THEN total ELSE 0 END),0) AS revenue FROM orders GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 31")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filename = 'flora_reports_' . date('Y-m-d_H-i-s') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename=' . $filename);
echo "\xEF\xBB\xBF";
?>
<html>
<head>
  <meta charset="utf-8">
  <style>
    table{border-collapse:collapse;margin-bottom:20px;width:100%}
    th,td{border:1px solid #cfd8dc;padding:8px 10px;text-align:left}
    th{background:#eef6f0;font-weight:700}
    h2{margin:18px 0 8px}
  </style>
</head>
<body>
  <h2>Сводка</h2>
  <table>
    <tr><th>Показатель</th><th>Значение</th></tr>
    <tr><td>Всего заказов</td><td><?= h(number_format((float)($summary['orders_total'] ?? 0),0,',',' ')) ?></td></tr>
    <tr><td>Выручка всего</td><td><?= h(number_format((float)($summary['revenue_total'] ?? 0),0,',',' ')) ?> ₽</td></tr>
    <tr><td>Заказов за сегодня</td><td><?= h(number_format((float)($summary['orders_today'] ?? 0),0,',',' ')) ?></td></tr>
    <tr><td>Выручка за сегодня</td><td><?= h(number_format((float)($summary['revenue_today'] ?? 0),0,',',' ')) ?> ₽</td></tr>
  </table>

  <h2>Способы получения</h2>
  <table>
    <tr><th>Способ</th><th>Заказы</th><th>Выручка</th></tr>
    <?php foreach ($methods as $row): ?>
      <tr>
        <td><?= h($row['method'] ?? '') ?></td>
        <td><?= h(number_format((float)($row['cnt'] ?? 0),0,',',' ')) ?></td>
        <td><?= h(number_format((float)($row['revenue'] ?? 0),0,',',' ')) ?> ₽</td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2>Товары</h2>
  <table>
    <tr><th>Товар</th><th>Количество</th><th>Сумма</th></tr>
    <?php foreach ($topProducts as $row): ?>
      <tr>
        <td><?= h($row['name'] ?? '') ?></td>
        <td><?= h(number_format((float)($row['qty'] ?? 0),0,',',' ')) ?></td>
        <td><?= h(number_format((float)($row['revenue'] ?? 0),0,',',' ')) ?> ₽</td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2>Последние дни</h2>
  <table>
    <tr><th>Дата</th><th>Заказы</th><th>Выручка</th></tr>
    <?php foreach ($days as $row): ?>
      <tr>
        <td><?= h($row['day'] ?? '') ?></td>
        <td><?= h(number_format((float)($row['orders_cnt'] ?? 0),0,',',' ')) ?></td>
        <td><?= h(number_format((float)($row['revenue'] ?? 0),0,',',' ')) ?> ₽</td>
      </tr>
    <?php endforeach; ?>
  </table>
</body>
</html>
