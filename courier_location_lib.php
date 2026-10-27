<?php
declare(strict_types=1);

/**
 * Live-tracking курьеров.
 *
 * Источник координат — Telegram Live Location:
 *   Курьер в боте: 📎 → Геопозиция → «Транслировать мою геолокацию»
 *   Бот получает в webhook первое сообщение message.location (с live_period > 0),
 *   затем каждую ~минуту — edited_message.location с обновлёнными координатами.
 *
 * Хранение:
 *   courier_locations_current — последняя позиция (по одной на курьера)
 *   courier_location_track     — история точек (для отрисовки пройденного пути)
 *
 * Доступ клиента — через GET /courier_location.php?token=<tracking_token>
 * (только если delivery.status IN ('picked','en_route') и не старше LIVE_TTL_SECONDS).
 */

const COURIER_LOCATION_TTL_SEC = 600; // 10 минут — считаем координаты «свежими»

function courier_location_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS courier_locations_current (
        courier_id BIGINT PRIMARY KEY,
        lat NUMERIC(10,6) NOT NULL,
        lon NUMERIC(10,6) NOT NULL,
        accuracy_m NUMERIC(8,2) NULL,
        heading NUMERIC(5,2) NULL,
        speed_kmh NUMERIC(6,2) NULL,
        source TEXT NOT NULL DEFAULT 'telegram',
        live_period_sec INT NULL,
        delivery_id BIGINT NULL,
        received_at TIMESTAMP NOT NULL DEFAULT NOW(),
        expires_at TIMESTAMP NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS courier_location_track (
        id BIGSERIAL PRIMARY KEY,
        courier_id BIGINT NOT NULL,
        delivery_id BIGINT NULL,
        lat NUMERIC(10,6) NOT NULL,
        lon NUMERIC(10,6) NOT NULL,
        received_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_track_courier_time ON courier_location_track (courier_id, received_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_track_delivery    ON courier_location_track (delivery_id, received_at DESC)");

    $done = true;
}

/**
 * Обновляет текущую позицию курьера + пишет точку в трек.
 * Вызывается из webhook'а Telegram.
 */
function courier_location_update(
    PDO $pdo,
    int $courierId,
    float $lat,
    float $lon,
    array $opts = []
): void {
    courier_location_ensure_schema($pdo);

    $accuracy = isset($opts['accuracy']) ? (float)$opts['accuracy'] : null;
    $heading  = isset($opts['heading'])  ? (float)$opts['heading']  : null;
    $speed    = isset($opts['speed_kmh'])? (float)$opts['speed_kmh']: null;
    $source   = (string)($opts['source'] ?? 'telegram');
    $livePeriod = isset($opts['live_period']) ? (int)$opts['live_period'] : null;

    // Активная доставка курьера — связываем с ней (если есть)
    $stDel = $pdo->prepare("
        SELECT id FROM deliveries
        WHERE courier_id = ? AND status IN ('picked','en_route','assigned')
        ORDER BY id DESC LIMIT 1
    ");
    $stDel->execute([$courierId]);
    $deliveryId = (int)($stDel->fetchColumn() ?: 0) ?: null;

    $expiresAt = $livePeriod ? date('Y-m-d H:i:s', time() + $livePeriod) : date('Y-m-d H:i:s', time() + COURIER_LOCATION_TTL_SEC);

    $st = $pdo->prepare("
        INSERT INTO courier_locations_current
            (courier_id, lat, lon, accuracy_m, heading, speed_kmh, source, live_period_sec, delivery_id, received_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ON CONFLICT (courier_id) DO UPDATE SET
            lat = EXCLUDED.lat,
            lon = EXCLUDED.lon,
            accuracy_m = EXCLUDED.accuracy_m,
            heading = EXCLUDED.heading,
            speed_kmh = EXCLUDED.speed_kmh,
            source = EXCLUDED.source,
            live_period_sec = EXCLUDED.live_period_sec,
            delivery_id = EXCLUDED.delivery_id,
            received_at = NOW(),
            expires_at = EXCLUDED.expires_at
    ");
    $st->execute([
        $courierId, $lat, $lon, $accuracy, $heading, $speed, $source, $livePeriod, $deliveryId, $expiresAt,
    ]);

    // Пишем точку в трек (отдельная история, для рисования пути)
    $stT = $pdo->prepare("
        INSERT INTO courier_location_track (courier_id, delivery_id, lat, lon)
        VALUES (?, ?, ?, ?)
    ");
    $stT->execute([$courierId, $deliveryId, $lat, $lon]);

    // Очистка очень старых точек трека (старше 24 часов) — раз в ~100 вставок
    if (mt_rand(1, 100) === 1) {
        $pdo->exec("DELETE FROM courier_location_track WHERE received_at < NOW() - INTERVAL '24 hours'");
    }
}

/**
 * Возвращает текущую позицию курьера или null, если координаты устарели/нет.
 */
function courier_location_get_current(PDO $pdo, int $courierId): ?array {
    courier_location_ensure_schema($pdo);
    $st = $pdo->prepare("
        SELECT
            courier_id, lat::float8 AS lat, lon::float8 AS lon,
            accuracy_m::float8 AS accuracy_m,
            heading::float8 AS heading,
            speed_kmh::float8 AS speed_kmh,
            source, live_period_sec, delivery_id,
            received_at,
            expires_at,
            EXTRACT(EPOCH FROM (NOW() - received_at))::int AS age_seconds
        FROM courier_locations_current
        WHERE courier_id = ?
        LIMIT 1
    ");
    $st->execute([$courierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    // Считаем актуальной, если age <= TTL И не прошёл expires_at
    $age = (int)$row['age_seconds'];
    $expired = $age > COURIER_LOCATION_TTL_SEC;
    if (!empty($row['expires_at'])) {
        $expired = $expired || (strtotime((string)$row['expires_at']) < time());
    }
    $row['is_fresh'] = !$expired;
    return $row;
}

/**
 * Возвращает последние N точек трека для отрисовки пути по конкретной доставке.
 */
function courier_location_track_recent(PDO $pdo, int $deliveryId, int $limit = 100): array {
    courier_location_ensure_schema($pdo);
    $st = $pdo->prepare("
        SELECT lat::float8 AS lat, lon::float8 AS lon, received_at
        FROM courier_location_track
        WHERE delivery_id = ?
        ORDER BY id ASC
        LIMIT ?
    ");
    $st->execute([$deliveryId, $limit]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
