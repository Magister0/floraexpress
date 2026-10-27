<?php
declare(strict_types=1);

function store_review_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS store_reviews (
        id BIGSERIAL PRIMARY KEY,
        user_id BIGINT NOT NULL UNIQUE,
        rating SMALLINT NOT NULL,
        review_text TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
        CONSTRAINT store_reviews_rating_chk CHECK (rating BETWEEN 1 AND 5)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_store_reviews_created_at ON store_reviews (created_at DESC)");
    $done = true;
}

function store_review_allowed_statuses(): array {
    return ['paid', 'assembling', 'on_delivery', 'delivered', 'completed'];
}

function store_review_can_leave(PDO $pdo, int $userId): bool {
    if ($userId <= 0) return false;
    $statuses = store_review_allowed_statuses();
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT 1
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            WHERE o.user_id = ?
              AND COALESCE(o.status, '') IN ($in)
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $params = array_merge([$userId], $statuses);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

function store_review_get_user(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    $st = $pdo->prepare('SELECT id, user_id, rating, review_text, created_at, updated_at FROM store_reviews WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function store_review_save(PDO $pdo, int $userId, int $rating, string $text): void {
    $rating = max(1, min(5, $rating));
    $text = trim($text);
    $st = $pdo->prepare("INSERT INTO store_reviews (user_id, rating, review_text, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
        ON CONFLICT (user_id)
        DO UPDATE SET rating = EXCLUDED.rating, review_text = EXCLUDED.review_text, updated_at = NOW()");
    $st->execute([$userId, $rating, $text]);
}

function store_review_delete(PDO $pdo, int $reviewId): void {
    if ($reviewId <= 0) return;
    $st = $pdo->prepare('DELETE FROM store_reviews WHERE id = ?');
    $st->execute([$reviewId]);
}

function store_review_stats(PDO $pdo): array {
    $row = $pdo->query('SELECT COUNT(*)::int AS total_reviews, COALESCE(ROUND(AVG(rating)::numeric, 1), 0) AS avg_rating FROM store_reviews')->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total_reviews' => (int)($row['total_reviews'] ?? 0),
        'avg_rating' => (float)($row['avg_rating'] ?? 0),
    ];
}

function store_review_latest(PDO $pdo, int $limit = 0): array {
    $limitSql = '';
    if ($limit > 0) {
        $limitSql = ' LIMIT ' . (int)$limit;
    }
    $sql = "SELECT sr.id, sr.rating, sr.review_text, sr.created_at, sr.updated_at,
                   COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.email), ''), 'Покупатель FloraExpress') AS author_name
            FROM store_reviews sr
            LEFT JOIN users u ON u.id = sr.user_id
            ORDER BY COALESCE(sr.updated_at, sr.created_at) DESC, sr.id DESC{$limitSql}";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
