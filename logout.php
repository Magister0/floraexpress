<?php
declare(strict_types=1);

require_once __DIR__ . '/cart_store.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log_lib.php';

$user = $_SESSION['user'] ?? null;
if ($user) {
    audit_log_write(db(), (int)($user['id'] ?? 0) ?: null, (string)($user['role'] ?? ''), 'logout', 'user', (int)($user['id'] ?? 0) ?: null);
}

unset($_SESSION['user']);
cart_store_sync(null);

// После logout — новый session ID
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

header('Location: index.php');
exit;
