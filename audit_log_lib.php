<?php
declare(strict_types=1);

/**
 * Создаёт таблицу audit_log при первом вызове.
 * Идемпотентно: проверка через CREATE TABLE IF NOT EXISTS + статический флаг.
 */
function audit_log_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id BIGSERIAL PRIMARY KEY,
        user_id BIGINT NULL,
        role TEXT NULL,
        action TEXT NOT NULL,
        entity TEXT NULL,
        entity_id BIGINT NULL,
        ip TEXT NULL,
        user_agent TEXT NULL,
        meta_json JSONB NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log (created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_user_id    ON audit_log (user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_action     ON audit_log (action)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_entity     ON audit_log (entity, entity_id)");

    $done = true;
}

/**
 * Запись действия пользователя/админа в аудит-лог.
 *
 * Не должен ронять основной флоу: при ошибке тихо проглатываем.
 *
 * @param array<string,mixed> $meta Дополнительные данные (JSON), например ['old' => 'paid', 'new' => 'assembling'].
 */
function audit_log_write(
    PDO $pdo,
    ?int $userId,
    ?string $role,
    string $action,
    ?string $entity = null,
    ?int $entityId = null,
    array $meta = []
): void {
    try {
        audit_log_ensure_schema($pdo);

        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $st = $pdo->prepare(
            "INSERT INTO audit_log (user_id, role, action, entity, entity_id, ip, user_agent, meta_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $userId,
            $role,
            $action,
            $entity,
            $entityId,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        // тихо: аудит не должен мешать пользователю
    }
}

/**
 * Удобный шорткат: пишет действие текущего пользователя из $_SESSION['user'].
 */
function audit_log_user_action(
    PDO $pdo,
    string $action,
    ?string $entity = null,
    ?int $entityId = null,
    array $meta = []
): void {
    $user   = $_SESSION['user'] ?? null;
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $role   = isset($user['role']) ? (string)$user['role'] : null;
    audit_log_write($pdo, $userId, $role, $action, $entity, $entityId, $meta);
}
