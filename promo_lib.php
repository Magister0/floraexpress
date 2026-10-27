<?php
declare(strict_types=1);

function promo_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ? LIMIT 1");
    $st->execute([$table, $column]);
    return $cache[$key] = (bool)$st->fetchColumn();
}

function promo_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS promocodes (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL DEFAULT '',
    discount_type TEXT NOT NULL DEFAULT 'percent',
    discount_value NUMERIC(12,2) NOT NULL DEFAULT 0,
    min_order_sum NUMERIC(12,2) NOT NULL DEFAULT 0,
    max_discount NUMERIC(12,2) NULL,
    delivery_method TEXT NOT NULL DEFAULT 'any',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    usage_limit INTEGER NULL,
    used_count INTEGER NOT NULL DEFAULT 0,
    starts_at TIMESTAMP NULL,
    ends_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_promocodes_active ON promocodes(active);
CREATE INDEX IF NOT EXISTS idx_promocodes_delivery_method ON promocodes(delivery_method);
SQL);

    if (!promo_column_exists($pdo, 'promocodes', 'single_use_per_user')) {
        $pdo->exec("ALTER TABLE promocodes ADD COLUMN single_use_per_user BOOLEAN NOT NULL DEFAULT FALSE");
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS promocode_user_usages (
    id BIGSERIAL PRIMARY KEY,
    promo_id BIGINT NOT NULL REFERENCES promocodes(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    order_id INTEGER NULL REFERENCES orders(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (promo_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_promocode_user_usages_user ON promocode_user_usages(user_id);
SQL);

    if (!promo_column_exists($pdo, 'orders', 'promo_code')) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN promo_code TEXT NULL");
    }
    if (!promo_column_exists($pdo, 'orders', 'discount_amount')) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0");
    }
    if (!promo_column_exists($pdo, 'orders', 'subtotal_amount')) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN subtotal_amount NUMERIC(12,2) NULL");
    }

    $done = true;
}

function promo_db_bool($value): bool {
    if (is_bool($value)) return $value;
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 't', 'true', 'yes', 'on', 'y'], true);
}

function promo_upper(string $value): string {
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }
    return strtoupper($value);
}

function promo_normalize_code(string $code): string {
    $code = preg_replace('~\s+~u', '', trim($code));
    return promo_upper((string)$code);
}

function promo_fetch_by_code(PDO $pdo, string $code): ?array {
    promo_ensure_schema($pdo);
    $code = promo_normalize_code($code);
    if ($code === '') return null;
    $st = $pdo->prepare("SELECT * FROM promocodes WHERE UPPER(code) = UPPER(?) LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function promo_user_has_used(PDO $pdo, int $promoId, int $userId): bool {
    if ($promoId <= 0 || $userId <= 0) return false;
    $st = $pdo->prepare('SELECT 1 FROM promocode_user_usages WHERE promo_id = ? AND user_id = ? LIMIT 1');
    $st->execute([$promoId, $userId]);
    return (bool)$st->fetchColumn();
}

function promo_validate(PDO $pdo, array $promo, float $subtotal, string $deliveryMethod, int $userId = 0): array {
    promo_ensure_schema($pdo);
    $now = time();
    $errors = [];

    if (!promo_db_bool($promo['active'] ?? false)) {
        $errors[] = 'Промокод отключён';
    }

    $startsAt = !empty($promo['starts_at']) ? strtotime((string)$promo['starts_at']) : null;
    $endsAt = !empty($promo['ends_at']) ? strtotime((string)$promo['ends_at']) : null;
    if ($startsAt && $now < $startsAt) {
        $errors[] = 'Промокод ещё не активен';
    }
    if ($endsAt && $now > $endsAt) {
        $errors[] = 'Срок действия промокода истёк';
    }

    $minOrder = (float)($promo['min_order_sum'] ?? 0);
    if ($subtotal < $minOrder) {
        $errors[] = 'Минимальная сумма заказа для этого промокода — ' . number_format($minOrder, 0, ',', ' ') . ' ₽';
    }

    $method = (string)($promo['delivery_method'] ?? 'any');
    if (!in_array($method, ['any', '', $deliveryMethod], true)) {
        $errors[] = $method === 'pickup'
            ? 'Промокод действует только на самовывоз'
            : 'Промокод действует только на доставку';
    }

    $usageLimit = $promo['usage_limit'] !== null ? (int)$promo['usage_limit'] : null;
    $usedCount = (int)($promo['used_count'] ?? 0);
    if ($usageLimit !== null && $usageLimit > 0 && $usedCount >= $usageLimit) {
        $errors[] = 'Лимит использований промокода уже исчерпан';
    }

    $singleUse = promo_db_bool($promo['single_use_per_user'] ?? false);
    if ($singleUse) {
        if ($userId <= 0) {
            $errors[] = 'Этот промокод доступен только авторизованным пользователям.';
        } elseif (promo_user_has_used($pdo, (int)($promo['id'] ?? 0), $userId)) {
            $errors[] = 'Этот промокод уже был использован на данном аккаунте.';
        }
    }

    $type = (string)($promo['discount_type'] ?? 'percent');
    $value = (float)($promo['discount_value'] ?? 0);
    $discount = 0.0;
    if (!$errors) {
        if ($type === 'fixed') {
            $discount = $value;
        } else {
            $discount = $subtotal * $value / 100;
        }
        $maxDiscount = $promo['max_discount'] !== null ? (float)$promo['max_discount'] : null;
        if ($maxDiscount !== null && $maxDiscount > 0) {
            $discount = min($discount, $maxDiscount);
        }
        $discount = max(0.0, min($discount, $subtotal));
    }

    return [
        'ok' => !$errors,
        'errors' => $errors,
        'discount' => round($discount, 2),
        'subtotal' => round($subtotal, 2),
        'total' => round(max(0, $subtotal - $discount), 2),
        'promo' => $promo,
        'code' => (string)($promo['code'] ?? ''),
    ];
}

function promo_calculate_from_session(PDO $pdo, float $subtotal, string $deliveryMethod, int $userId = 0): array {
    promo_ensure_schema($pdo);
    $code = promo_normalize_code((string)($_SESSION['promo_code'] ?? ''));
    if ($code === '') {
        return ['ok' => true, 'hasPromo' => false, 'discount' => 0.0, 'subtotal' => $subtotal, 'total' => $subtotal, 'errors' => []];
    }
    $promo = promo_fetch_by_code($pdo, $code);
    if (!$promo) {
        unset($_SESSION['promo_code']);
        return ['ok' => true, 'hasPromo' => false, 'discount' => 0.0, 'subtotal' => $subtotal, 'total' => $subtotal, 'errors' => ['Промокод не найден']];
    }
    $calc = promo_validate($pdo, $promo, $subtotal, $deliveryMethod, $userId);
    $calc['hasPromo'] = true;
    if (!$calc['ok']) {
        unset($_SESSION['promo_code']);
        $calc['hasPromo'] = false;
        $calc['discount'] = 0.0;
        $calc['total'] = $subtotal;
    }
    return $calc;
}

function promo_consume(PDO $pdo, int $promoId, int $userId = 0, ?int $orderId = null): bool {
    promo_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT id, single_use_per_user FROM promocodes WHERE id = ? FOR UPDATE');
    $st->execute([$promoId]);
    $promo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$promo) {
        return false;
    }

    $singleUse = promo_db_bool($promo['single_use_per_user'] ?? false);
    $insertedUsage = false;

    if ($singleUse) {
        if ($userId <= 0) {
            return false;
        }
        $ins = $pdo->prepare('INSERT INTO promocode_user_usages (promo_id, user_id, order_id, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT (promo_id, user_id) DO NOTHING');
        $ins->execute([$promoId, $userId, $orderId]);
        if ($ins->rowCount() < 1) {
            return false;
        }
        $insertedUsage = true;
    }

    $up = $pdo->prepare('UPDATE promocodes SET used_count = used_count + 1 WHERE id = ? AND (usage_limit IS NULL OR usage_limit <= 0 OR used_count < usage_limit)');
    $up->execute([$promoId]);
    if ($up->rowCount() < 1) {
        if ($insertedUsage && !$pdo->inTransaction()) {
            $pdo->prepare('DELETE FROM promocode_user_usages WHERE promo_id = ? AND user_id = ?')->execute([$promoId, $userId]);
        }
        return false;
    }

    if ($singleUse && $orderId !== null) {
        $pdo->prepare('UPDATE promocode_user_usages SET order_id = ? WHERE promo_id = ? AND user_id = ?')->execute([$orderId, $promoId, $userId]);
    }

    return true;
}

function promo_delivery_label(string $method): string {
    return match($method) {
        'delivery' => 'Только доставка',
        'pickup' => 'Только самовывоз',
        default => 'Любой способ',
    };
}

function promo_type_label(string $type, float $value): string {
    return $type === 'fixed'
        ? ('-' . number_format($value, 0, ',', ' ') . ' ₽')
        : ('-' . rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',') . '%');
}
