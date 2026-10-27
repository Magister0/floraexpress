<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/favorites_lib.php';

$pdo = db();
favorites_init($pdo);

function fail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
function ok(array $payload = []): void {
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$me = $_SESSION['user'] ?? null;
if (!$me) {
    fail(401, 'auth', ['auth' => true]);
}
$userId = (int)($me['id'] ?? 0);
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'toggle') {
    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId <= 0) fail(400, 'bad_product_id');

    try {
        $st = $pdo->prepare("SELECT id FROM products WHERE id=?");
        $st->execute([$productId]);
        if (!$st->fetch()) fail(404, 'product_not_found');

        $active = favorites_toggle($pdo, $userId, $productId);
        $count = favorites_count($pdo, $userId);
        ok(['active' => $active, 'count' => $count]);
    } catch (Throwable $e) {
        fail(500, 'db');
    }
}

if ($action === 'count') {
    try {
        ok(['count' => favorites_count($pdo, $userId)]);
    } catch (Throwable $e) {
        fail(500, 'db');
    }
}

fail(400, 'unknown_action');
