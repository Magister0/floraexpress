<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/cart_store.php';

$pdo = db();
cart_store_sync();

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $payload = []): void {
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$act = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($act === 'add') {
    $id = (int)($_POST['id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    if ($id <= 0) fail(400, 'bad_id');

    $st = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
    $st->execute([$id]);
    if (!$st->fetch()) fail(404, 'unknown_product');

    $cart = cart_store_add($id, $qty);
    ok(['count' => cart_store_count($cart), 'items' => $cart]);
}

if ($act === 'set') {
    $id = (int)($_POST['id'] ?? 0);
    $qty = max(0, (int)($_POST['qty'] ?? 0));
    if ($id <= 0) fail(400, 'bad_id');

    if ($qty > 0) {
        $st = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        if (!$st->fetch()) fail(404, 'unknown_product');
    }

    $cart = cart_store_set_item($id, $qty);
    ok(['count' => cart_store_count($cart), 'items' => $cart]);
}

if ($act === 'remove') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) fail(400, 'bad_id');
    $cart = cart_store_remove($id);
    ok(['count' => cart_store_count($cart), 'items' => $cart]);
}

if ($act === 'clear') {
    $cart = cart_store_clear();
    ok(['count' => 0, 'items' => $cart]);
}

if ($act === 'get') {
    $cart = cart_store_get();
    ok(['count' => cart_store_count($cart), 'items' => $cart]);
}

fail(400, 'unknown_action');
