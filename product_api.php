<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/product_admin_lib.php';
$pdo = db();
pa_ensure_products_discount_columns($pdo);

function fail(int $code, string $msg, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
function ok(array $payload = []): void {
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function need_staff(): void {
    $u = $_SESSION['user'] ?? null;
    if (!$u || !in_array((string)($u['role'] ?? 'user'), ['admin','manager'], true)) {
        fail(403, 'forbidden');
    }
}
function check_csrf_api(): void {
    if (empty($_SESSION['csrf']) || !isset($_POST['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])) {
        fail(419, 'csrf');
    }
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'list') {
    try {
        $items = $pdo->query('SELECT id, name, price, old_price, image, description, category, is_active, created_at FROM products ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        ok(['items' => $items]);
    } catch (Throwable $e) {
        fail(500, 'db');
    }
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) fail(400, 'bad_id');
    try {
        $st = $pdo->prepare('SELECT id, name, price, old_price, image, description, category, is_active, created_at FROM products WHERE id = ?');
        $st->execute([$id]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) fail(404, 'not_found');
        ok(['item' => $item]);
    } catch (Throwable $e) {
        fail(500, 'db');
    }
}

if ($action === 'create') {
    need_staff();
    check_csrf_api();
    $name = trim((string)($_POST['name'] ?? ''));
    $price = pa_normalize_price($_POST['price'] ?? '0');
    $oldPrice = pa_valid_old_price($_POST['old_price'] ?? '', $price);
    $image = trim((string)($_POST['image'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $cat = trim((string)($_POST['category'] ?? ''));

    if ($name === '' || $price < 0) fail(400, 'bad_data');
    try {
        $st = $pdo->prepare('INSERT INTO products (name, price, old_price, image, description, category, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP) RETURNING id');
        $st->execute([$name, $price, $oldPrice, $image, $desc, $cat]);
        ok(['id' => (int)$st->fetchColumn()]);
    } catch (Throwable $e) {
        fail(500, 'db');
    }
}

if ($action === 'update') {
    need_staff();
    check_csrf_api();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $price = pa_normalize_price($_POST['price'] ?? '0');
    $oldPrice = pa_valid_old_price($_POST['old_price'] ?? '', $price);
    $image = trim((string)($_POST['image'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $cat = trim((string)($_POST['category'] ?? ''));

    if ($id <= 0 || $name === '' || $price < 0) fail(400, 'bad_data');
    try {
        $st = $pdo->prepare('UPDATE products SET name = ?, price = ?, old_price = ?, image = ?, description = ?, category = ? WHERE id = ?');
        $st->execute([$name, $price, $oldPrice, $image, $desc, $cat, $id]);
        ok();
    } catch (Throwable $e) {
        fail(500, 'db');
    }
}

if ($action === 'toggle_visibility') {
    need_staff();
    check_csrf_api();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) fail(400, 'bad_id');
    try {
        $st = $pdo->prepare('UPDATE products SET is_active = CASE WHEN COALESCE(is_active, 1) = 1 THEN 0 ELSE 1 END WHERE id = ? RETURNING COALESCE(is_active, 1)');
        $st->execute([$id]);
        $active = $st->fetchColumn();
        if ($active === false) fail(404, 'not_found');
        ok([
            'id' => $id,
            'active' => ((int)$active === 1),
            'status_label' => ((int)$active === 1) ? 'Активен' : 'Скрыт',
        ]);
    } catch (Throwable $e) {
        fail(500, 'db');
    }
}

if ($action === 'delete') {
    need_staff();
    check_csrf_api();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) fail(400, 'bad_id');
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT image FROM products WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            fail(404, 'not_found');
        }
        $st = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $st->execute([$id]);
        $pdo->commit();
        pa_delete_local_image((string)($row['image'] ?? ''));
        ok(['id' => $id]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail(500, 'db');
    }
}

fail(400, 'unknown_action');
