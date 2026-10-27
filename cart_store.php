<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
flora_start_session();

function cart_store_bucket_key(?array $user = null): string {
    $user ??= ($_SESSION['user'] ?? null);
    $id = (int)($user['id'] ?? 0);
    return $id > 0 ? 'user_' . $id : 'guest';
}

function cart_store_normalize(array $cart): array {
    $result = [];
    foreach ($cart as $id => $qty) {
        $pid = (int)$id;
        $count = max(0, (int)$qty);
        if ($pid > 0 && $count > 0) {
            $result[$pid] = $count;
        }
    }
    ksort($result);
    return $result;
}

function cart_store_sync(?array $targetUser = null): void {
    $targetKey = cart_store_bucket_key($targetUser);

    if (!isset($_SESSION['cart_store']) || !is_array($_SESSION['cart_store'])) {
        $_SESSION['cart_store'] = [];
    }

    $currentKey = (string)($_SESSION['cart_context'] ?? '');
    if ($currentKey === '') {
        $hasLegacyCart = array_key_exists('cart', $_SESSION) && is_array($_SESSION['cart']) && !empty($_SESSION['cart']);
        if ($hasLegacyCart && !empty($_SESSION['user']['id'])) {
            $currentKey = cart_store_bucket_key((array)$_SESSION['user']);
        } else {
            $currentKey = $hasLegacyCart ? 'guest' : $targetKey;
        }
    }

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $_SESSION['cart_store'][$currentKey] = cart_store_normalize((array)$_SESSION['cart']);

    if (!isset($_SESSION['cart_store'][$targetKey]) || !is_array($_SESSION['cart_store'][$targetKey])) {
        $_SESSION['cart_store'][$targetKey] = [];
    }

    $_SESSION['cart'] = cart_store_normalize((array)$_SESSION['cart_store'][$targetKey]);
    $_SESSION['cart_context'] = $targetKey;
}

function cart_store_get(): array {
    cart_store_sync();
    return cart_store_normalize((array)($_SESSION['cart'] ?? []));
}

function cart_store_set(array $cart): array {
    cart_store_sync();
    $normalized = cart_store_normalize($cart);
    $_SESSION['cart'] = $normalized;
    $key = (string)($_SESSION['cart_context'] ?? cart_store_bucket_key());
    $_SESSION['cart_store'][$key] = $normalized;
    return $normalized;
}

function cart_store_add(int $productId, int $qty = 1): array {
    $cart = cart_store_get();
    if ($productId > 0 && $qty > 0) {
        $cart[$productId] = ($cart[$productId] ?? 0) + $qty;
    }
    return cart_store_set($cart);
}

function cart_store_set_item(int $productId, int $qty): array {
    $cart = cart_store_get();
    if ($productId <= 0) return $cart;
    if ($qty <= 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = $qty;
    }
    return cart_store_set($cart);
}

function cart_store_remove(int $productId): array {
    $cart = cart_store_get();
    unset($cart[$productId]);
    return cart_store_set($cart);
}

function cart_store_clear(): array {
    return cart_store_set([]);
}

function cart_store_count(?array $cart = null): int {
    $cart = $cart === null ? cart_store_get() : cart_store_normalize($cart);
    return array_sum($cart);
}
