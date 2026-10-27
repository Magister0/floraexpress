<?php
declare(strict_types=1);

function review_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (
        id BIGSERIAL PRIMARY KEY,
        product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
        body TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_product_reviews_user_product ON product_reviews(user_id, product_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_product_reviews_product_created ON product_reviews(product_id, created_at DESC)");

    $done = true;
}

function review_user_row(PDO $pdo, int $userId, int $productId): ?array {
    review_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, rating, body, created_at, updated_at FROM product_reviews WHERE user_id = ? AND product_id = ? LIMIT 1');
    $st->execute([$userId, $productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function review_save(PDO $pdo, int $userId, int $productId, int $rating, string $body): void {
    review_ensure_schema($pdo);
    $rating = max(1, min(5, $rating));
    $body = trim($body);
    $st = $pdo->prepare(
        'INSERT INTO product_reviews (product_id, user_id, rating, body, created_at, updated_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         ON CONFLICT (user_id, product_id)
         DO UPDATE SET rating = EXCLUDED.rating, body = EXCLUDED.body, updated_at = CURRENT_TIMESTAMP'
    );
    $st->execute([$productId, $userId, $rating, $body]);
}

function review_stats(PDO $pdo, int $productId): array {
    review_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT COUNT(*)::int AS cnt, COALESCE(AVG(rating), 0) AS avg_rating FROM product_reviews WHERE product_id = ?');
    $st->execute([$productId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'avg_rating' => 0];
}

function review_list(PDO $pdo, int $productId, int $limit = 10): array {
    review_ensure_schema($pdo);
    $limit = max(1, min(50, $limit));
    $sql = "SELECT r.id, r.rating, r.body, r.created_at, r.updated_at,
                   COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.email), ''), 'Покупатель') AS author_name
            FROM product_reviews r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.product_id = ?
            ORDER BY r.updated_at DESC, r.id DESC
            LIMIT " . (int)$limit;
    $st = $pdo->prepare($sql);
    $st->execute([$productId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function review_plural_reviews(int $count): string {
    $n = abs($count) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) return 'отзывов';
    if ($n1 > 1 && $n1 < 5) return 'отзыва';
    if ($n1 == 1) return 'отзыв';
    return 'отзывов';
}

function review_date_ru(string $value): string {
    $ts = strtotime($value);
    if (!$ts) return '';
    $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $m = (int)date('n', $ts);
    $month = $months[$m - 1] ?? date('m', $ts);
    return date('d', $ts) . ' ' . $month . ' ' . date('Y', $ts);
}
