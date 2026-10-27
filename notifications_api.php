<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$me = $_SESSION['user'] ?? null;
if (!$me || !in_array((string)($me['role'] ?? ''), ['admin', 'manager', 'courier'], true)) {
    echo json_encode(['count' => 0]);
    exit;
}

$pdo = db();

// Отметить просмотренным — запоминаем текущий max id
if (isset($_GET['mark_seen'])) {
    try {
        $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM orders")->fetchColumn();
        $_SESSION['staff_last_order_id'] = $max;
    } catch (Throwable $e) {}
    echo json_encode(['ok' => true]);
    exit;
}

// Инициализируем last_seen при первом обращении
if (!isset($_SESSION['staff_last_order_id'])) {
    try {
        $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM orders")->fetchColumn();
        $_SESSION['staff_last_order_id'] = $max;
    } catch (Throwable $e) {
        $_SESSION['staff_last_order_id'] = 0;
    }
    echo json_encode(['count' => 0]);
    exit;
}

$lastId = (int)$_SESSION['staff_last_order_id'];

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE id > ? AND status = 'new'");
    $st->execute([$lastId]);
    $count = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $count = 0;
}

echo json_encode(['count' => $count]);
