<?php
declare(strict_types=1);

function fav_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function favorites_init(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_favorites (
        id BIGSERIAL PRIMARY KEY,
        user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, product_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_favorites_user_id ON user_favorites(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_favorites_product_id ON user_favorites(product_id)");
}

function favorites_user_id(): int {
    return (int)($_SESSION['user']['id'] ?? 0);
}

function favorites_normalize_ids(array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        $ids[(int)($row['product_id'] ?? 0)] = true;
    }
    return array_keys($ids);
}

function favorites_ids(PDO $pdo, int $userId): array {
    favorites_init($pdo);
    if ($userId <= 0) return [];
    $st = $pdo->prepare('SELECT product_id FROM user_favorites WHERE user_id = ?');
    $st->execute([$userId]);
    return favorites_normalize_ids($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function favorites_map(PDO $pdo, int $userId): array {
    $map = [];
    foreach (favorites_ids($pdo, $userId) as $id) $map[(int)$id] = true;
    return $map;
}

function favorites_has(PDO $pdo, int $userId, int $productId): bool {
    favorites_init($pdo);
    if ($userId <= 0 || $productId <= 0) return false;
    $st = $pdo->prepare('SELECT 1 FROM user_favorites WHERE user_id = ? AND product_id = ? LIMIT 1');
    $st->execute([$userId, $productId]);
    return (bool)$st->fetchColumn();
}

function favorites_add(PDO $pdo, int $userId, int $productId): void {
    favorites_init($pdo);
    if ($userId <= 0 || $productId <= 0) return;
    $st = $pdo->prepare('INSERT INTO user_favorites(user_id, product_id) VALUES (?, ?) ON CONFLICT (user_id, product_id) DO NOTHING');
    $st->execute([$userId, $productId]);
}

function favorites_remove(PDO $pdo, int $userId, int $productId): void {
    favorites_init($pdo);
    if ($userId <= 0 || $productId <= 0) return;
    $st = $pdo->prepare('DELETE FROM user_favorites WHERE user_id = ? AND product_id = ?');
    $st->execute([$userId, $productId]);
}

function favorites_toggle(PDO $pdo, int $userId, int $productId): bool {
    if (favorites_has($pdo, $userId, $productId)) {
        favorites_remove($pdo, $userId, $productId);
        return false;
    }
    favorites_add($pdo, $userId, $productId);
    return true;
}

function favorites_count(PDO $pdo, int $userId): int {
    favorites_init($pdo);
    if ($userId <= 0) return 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM user_favorites WHERE user_id = ?');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

function favorites_items(PDO $pdo, int $userId): array {
    favorites_init($pdo);
    if ($userId <= 0) return [];
    $st = $pdo->prepare('SELECT p.*, uf.created_at AS favorited_at
        FROM user_favorites uf
        JOIN products p ON p.id = uf.product_id
        WHERE uf.user_id = ?
        ORDER BY uf.created_at DESC');
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function favorites_placeholder_image(): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="24" fill="#9ca3af">Нет изображения</text></svg>'
    );
}

function favorites_image_src(?string $path, string $placeholder): string {
    $path = trim((string)$path);
    if ($path === '') return $placeholder;
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:') || str_starts_with($path, '/')) return $path;
    return ltrim($path, './');
}
