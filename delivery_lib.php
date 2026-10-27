<?php
declare(strict_types=1);

/**
 * Динамический расчёт стоимости доставки.
 *
 * Архитектура:
 *  - delivery_zones                — зоны (центр/город/пригород/другой город).
 *  - delivery_tariff_modifiers     — модификаторы тарифа (ночь, выходные, срочно и т.д.).
 *  - orders.delivery_fee           — цена доставки, которую заплатил клиент.
 *  - orders.delivery_calc_meta     — JSONB-расшифровка расчёта.
 *
 * Точка входа: delivery_calc_quote($lat, $lon, $when, $orderTotal, $flags).
 */

function delivery_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_zones (
        id BIGSERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        description TEXT NULL,
        center_lat NUMERIC(10,6) NOT NULL,
        center_lon NUMERIC(10,6) NOT NULL,
        radius_km NUMERIC(6,2) NOT NULL,
        base_fee NUMERIC(10,2) NOT NULL DEFAULT 0,
        free_distance_km NUMERIC(6,2) NOT NULL DEFAULT 0,
        per_km_fee NUMERIC(10,2) NOT NULL DEFAULT 0,
        min_fee NUMERIC(10,2) NOT NULL DEFAULT 0,
        free_from NUMERIC(10,2) NULL,
        eta_speed_kmh NUMERIC(5,2) NOT NULL DEFAULT 25,
        eta_prep_min INT NOT NULL DEFAULT 20,
        priority INT NOT NULL DEFAULT 0,
        active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_delivery_zones_active ON delivery_zones (active, priority DESC)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_tariff_modifiers (
        id BIGSERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        kind TEXT NOT NULL,
        multiplier NUMERIC(6,3) NOT NULL DEFAULT 1.000,
        surcharge_fixed NUMERIC(10,2) NOT NULL DEFAULT 0,
        time_from TIME NULL,
        time_to   TIME NULL,
        weekdays JSONB NULL,
        date_from DATE NULL,
        date_to   DATE NULL,
        priority INT NOT NULL DEFAULT 0,
        active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_delivery_modifiers_active ON delivery_tariff_modifiers (active, priority DESC)");

    // Добавляем поля в orders, если их нет (meta — TEXT для совместимости с PDO без явных приведений типов)
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_fee NUMERIC(10,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_calc_meta TEXT NULL");

    $done = true;
}

function delivery_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function delivery_shop_coords(): array {
    $lat = defined('FLORA_SHOP_LAT') ? (float)FLORA_SHOP_LAT
         : (defined('FLORA_MAP_DEFAULT_LAT') ? (float)FLORA_MAP_DEFAULT_LAT : 56.8389);
    $lon = defined('FLORA_SHOP_LON') ? (float)FLORA_SHOP_LON
         : (defined('FLORA_MAP_DEFAULT_LON') ? (float)FLORA_MAP_DEFAULT_LON : 60.6057);
    return [$lat, $lon];
}

/** Загружает активные зоны (с кэшем). */
function delivery_zones_list(PDO $pdo, bool $onlyActive = true): array {
    static $cache = [];
    $key = $onlyActive ? 'active' : 'all';
    if (isset($cache[$key])) return $cache[$key];

    delivery_ensure_schema($pdo);
    $sql = "SELECT id, name, description,
                   center_lat::float8 AS center_lat,
                   center_lon::float8 AS center_lon,
                   radius_km::float8 AS radius_km,
                   base_fee::float8 AS base_fee,
                   free_distance_km::float8 AS free_distance_km,
                   per_km_fee::float8 AS per_km_fee,
                   min_fee::float8 AS min_fee,
                   free_from,
                   eta_speed_kmh::float8 AS eta_speed_kmh,
                   eta_prep_min,
                   priority, active
            FROM delivery_zones";
    if ($onlyActive) {
        $sql .= " WHERE active = TRUE";
    }
    $sql .= " ORDER BY priority DESC, id ASC";

    return $cache[$key] = ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function delivery_modifiers_list(PDO $pdo, bool $onlyActive = true): array {
    static $cache = [];
    $key = $onlyActive ? 'active' : 'all';
    if (isset($cache[$key])) return $cache[$key];

    delivery_ensure_schema($pdo);
    $sql = "SELECT id, name, kind,
                   multiplier::float8 AS multiplier,
                   surcharge_fixed::float8 AS surcharge_fixed,
                   time_from, time_to,
                   weekdays, date_from, date_to,
                   priority, active
            FROM delivery_tariff_modifiers";
    if ($onlyActive) {
        $sql .= " WHERE active = TRUE";
    }
    $sql .= " ORDER BY priority DESC, id ASC";

    return $cache[$key] = ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Выбирает подходящую зону для координат адреса.
 */
function delivery_find_zone(PDO $pdo, float $lat, float $lon): ?array {
    $zones = delivery_zones_list($pdo, true);
    $best = null;
    foreach ($zones as $z) {
        $d = delivery_haversine_km((float)$z['center_lat'], (float)$z['center_lon'], $lat, $lon);
        if ($d <= (float)$z['radius_km']) {
            $z['_distance_to_center'] = $d;
            if ($best === null || (int)$z['priority'] > (int)$best['priority']) {
                $best = $z;
            }
        }
    }
    return $best;
}

/**
 * Подходит ли модификатор моменту $when (+ доп. флаги).
 */
function delivery_modifier_applies(array $mod, DateTimeImmutable $when, array $flags = []): bool {
    $kind = (string)($mod['kind'] ?? '');

    // Календарное окно
    if (!empty($mod['date_from'])) {
        if ($when->format('Y-m-d') < (string)$mod['date_from']) return false;
    }
    if (!empty($mod['date_to'])) {
        if ($when->format('Y-m-d') > (string)$mod['date_to']) return false;
    }

    // Дни недели (ISO 1..7, пн..вс)
    $weekdays = $mod['weekdays'] ?? null;
    if (is_string($weekdays)) $weekdays = json_decode($weekdays, true);
    if (is_array($weekdays) && $weekdays) {
        $wd = (int)$when->format('N');
        $wdList = array_map('intval', $weekdays);
        if (!in_array($wd, $wdList, true)) return false;
    }

    // Временной слот
    if (!empty($mod['time_from']) || !empty($mod['time_to'])) {
        $tNow  = $when->format('H:i');
        $tFrom = !empty($mod['time_from']) ? substr((string)$mod['time_from'], 0, 5) : null;
        $tTo   = !empty($mod['time_to'])   ? substr((string)$mod['time_to'], 0, 5)   : null;
        if ($tFrom !== null && $tTo !== null) {
            if ($tFrom <= $tTo) {
                if (!($tNow >= $tFrom && $tNow <= $tTo)) return false;
            } else {
                // Через полночь: 22:00..06:00
                if (!($tNow >= $tFrom || $tNow <= $tTo)) return false;
            }
        } elseif ($tFrom !== null && $tNow < $tFrom) {
            return false;
        } elseif ($tTo !== null && $tNow > $tTo) {
            return false;
        }
    }

    // Дополнительные флаги (по kind)
    if ($kind === 'urgent' && empty($flags['urgent'])) return false;
    if ($kind === 'fragile' && empty($flags['fragile'])) return false;
    if ($kind === 'big_order' && empty($flags['big_order'])) return false;

    return true;
}

/**
 * Главная функция расчёта.
 *
 * @param array $flags ['urgent'=>bool, 'fragile'=>bool, 'big_order'=>bool]
 *
 * @return array
 *   [
 *     'ok' => true,
 *     'fee' => 350.0,
 *     'distance_km' => 8.2,
 *     'eta_min' => 40,
 *     'zone' => ['id','name','description'],
 *     'breakdown' => [
 *         ['label' => 'Базовый тариф зоны «Город»', 'amount' => 200.0],
 *         ['label' => 'Расстояние сверх 3 км: 5.2 × 30 ₽', 'amount' => 156.0],
 *         ['label' => 'Ночной тариф ×1.5', 'amount' => null, 'multiplier' => 1.5],
 *         ['label' => 'Хрупкий заказ +200 ₽', 'amount' => 200.0],
 *     ],
 *     'modifiers' => [['id','name','multiplier','surcharge_fixed'], ...],
 *     'free_applied' => false,
 *   ]
 *
 *  Если адрес вне всех зон:
 *   ['ok' => false, 'error' => 'out_of_zone', 'distance_km' => 42.5]
 */
function delivery_calc_quote(
    PDO $pdo,
    float $lat,
    float $lon,
    ?DateTimeImmutable $when = null,
    float $orderTotal = 0,
    array $flags = []
): array {
    $when ??= new DateTimeImmutable('now');

    [$shopLat, $shopLon] = delivery_shop_coords();
    $distance = delivery_haversine_km($shopLat, $shopLon, $lat, $lon);

    if ($lat == 0.0 && $lon == 0.0) {
        return ['ok' => false, 'error' => 'no_coords'];
    }

    $zone = delivery_find_zone($pdo, $lat, $lon);
    if ($zone === null) {
        return [
            'ok'          => false,
            'error'       => 'out_of_zone',
            'distance_km' => round($distance, 2),
        ];
    }

    $breakdown = [];

    $base = (float)$zone['base_fee'];
    $breakdown[] = [
        'label'  => 'Базовый тариф зоны «' . (string)$zone['name'] . '»',
        'amount' => $base,
    ];

    $billableKm = max(0.0, $distance - (float)$zone['free_distance_km']);
    $distFee = round($billableKm * (float)$zone['per_km_fee'], 2);
    if ($distFee > 0) {
        $breakdown[] = [
            'label'  => sprintf('Расстояние сверх %s км: %.1f × %s ₽',
                rtrim(rtrim(number_format((float)$zone['free_distance_km'], 2, '.', ''), '0'), '.'),
                $billableKm,
                rtrim(rtrim(number_format((float)$zone['per_km_fee'], 2, '.', ''), '0'), '.')
            ),
            'amount' => $distFee,
        ];
    }

    $fee = $base + $distFee;

    // Минимум
    $minFee = (float)$zone['min_fee'];
    if ($fee < $minFee) {
        $breakdown[] = [
            'label'  => 'Доводим до минимальной стоимости',
            'amount' => round($minFee - $fee, 2),
        ];
        $fee = $minFee;
    }

    // Модификаторы
    $appliedModifiers = [];
    $multiplierChain = 1.0;
    $surchargeTotal  = 0.0;
    foreach (delivery_modifiers_list($pdo, true) as $mod) {
        if (!delivery_modifier_applies($mod, $when, $flags)) continue;

        $mult = (float)$mod['multiplier'];
        $surch = (float)$mod['surcharge_fixed'];
        if (abs($mult - 1.0) < 0.001 && abs($surch) < 0.001) continue;

        $oldFee = $fee;
        $fee = $fee * $mult + $surch;
        $multiplierChain *= $mult;
        $surchargeTotal += $surch;

        $appliedModifiers[] = [
            'id'   => (int)$mod['id'],
            'name' => (string)$mod['name'],
            'kind' => (string)$mod['kind'],
            'multiplier' => $mult,
            'surcharge_fixed' => $surch,
        ];

        if (abs($mult - 1.0) > 0.001) {
            $breakdown[] = [
                'label'      => (string)$mod['name'] . ' (×' . number_format($mult, 2) . ')',
                'amount'     => round($fee - $oldFee - $surch, 2),
                'multiplier' => $mult,
            ];
        }
        if (abs($surch) > 0.001) {
            $breakdown[] = [
                'label'  => (string)$mod['name'] . ($surch > 0 ? ' +' : ' ') . number_format($surch, 0, '.', ' ') . ' ₽',
                'amount' => round($surch, 2),
            ];
        }
    }

    // Бесплатная доставка от суммы
    $freeApplied = false;
    if (!empty($zone['free_from']) && $orderTotal >= (float)$zone['free_from']) {
        $freeApplied = true;
        $breakdown[] = [
            'label'  => 'Бесплатная доставка (заказ от ' . number_format((float)$zone['free_from'], 0, ',', ' ') . ' ₽)',
            'amount' => -1 * $fee,
        ];
        $fee = 0.0;
    }

    if ($fee < 0) $fee = 0.0;

    // ETA
    $speed = max(5.0, (float)$zone['eta_speed_kmh']);
    $etaMin = (int)round(($distance / $speed) * 60 + (int)$zone['eta_prep_min']);

    return [
        'ok'           => true,
        'fee'          => round($fee, 2),
        'distance_km'  => round($distance, 2),
        'eta_min'      => $etaMin,
        'zone'         => [
            'id'          => (int)$zone['id'],
            'name'        => (string)$zone['name'],
            'description' => (string)($zone['description'] ?? ''),
        ],
        'breakdown'    => $breakdown,
        'modifiers'    => $appliedModifiers,
        'free_applied' => $freeApplied,
    ];
}

/* ---------- CRUD для админки ---------- */

function delivery_zone_save(PDO $pdo, array $data, ?int $id = null): int {
    delivery_ensure_schema($pdo);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Название зоны обязательно.');

    $fields = [
        'name'             => $name,
        'description'      => trim((string)($data['description'] ?? '')) ?: null,
        'center_lat'       => (float)($data['center_lat'] ?? 0),
        'center_lon'       => (float)($data['center_lon'] ?? 0),
        'radius_km'        => (float)($data['radius_km'] ?? 0),
        'base_fee'         => (float)($data['base_fee'] ?? 0),
        'free_distance_km' => (float)($data['free_distance_km'] ?? 0),
        'per_km_fee'       => (float)($data['per_km_fee'] ?? 0),
        'min_fee'          => (float)($data['min_fee'] ?? 0),
        'free_from'        => isset($data['free_from']) && $data['free_from'] !== '' ? (float)$data['free_from'] : null,
        'eta_speed_kmh'    => (float)($data['eta_speed_kmh'] ?? 25),
        'eta_prep_min'     => (int)($data['eta_prep_min'] ?? 20),
        'priority'         => (int)($data['priority'] ?? 0),
        'active'           => !empty($data['active']),
    ];

    if ($id) {
        $st = $pdo->prepare("UPDATE delivery_zones SET
            name=?, description=?, center_lat=?, center_lon=?, radius_km=?,
            base_fee=?, free_distance_km=?, per_km_fee=?, min_fee=?, free_from=?,
            eta_speed_kmh=?, eta_prep_min=?, priority=?, active=?, updated_at=NOW()
            WHERE id=?");
        $st->execute([
            $fields['name'], $fields['description'], $fields['center_lat'], $fields['center_lon'], $fields['radius_km'],
            $fields['base_fee'], $fields['free_distance_km'], $fields['per_km_fee'], $fields['min_fee'], $fields['free_from'],
            $fields['eta_speed_kmh'], $fields['eta_prep_min'], $fields['priority'], $fields['active'],
            $id,
        ]);
        return $id;
    }

    $st = $pdo->prepare("INSERT INTO delivery_zones
        (name, description, center_lat, center_lon, radius_km,
         base_fee, free_distance_km, per_km_fee, min_fee, free_from,
         eta_speed_kmh, eta_prep_min, priority, active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id");
    $st->execute([
        $fields['name'], $fields['description'], $fields['center_lat'], $fields['center_lon'], $fields['radius_km'],
        $fields['base_fee'], $fields['free_distance_km'], $fields['per_km_fee'], $fields['min_fee'], $fields['free_from'],
        $fields['eta_speed_kmh'], $fields['eta_prep_min'], $fields['priority'], $fields['active'],
    ]);
    return (int)$st->fetchColumn();
}

function delivery_zone_delete(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$id]);
}

function delivery_zone_toggle(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $pdo->prepare('UPDATE delivery_zones SET active = NOT active, updated_at = NOW() WHERE id = ?')->execute([$id]);
}

function delivery_zone_get(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM delivery_zones WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function delivery_modifier_save(PDO $pdo, array $data, ?int $id = null): int {
    delivery_ensure_schema($pdo);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Название модификатора обязательно.');

    $weekdays = $data['weekdays'] ?? [];
    if (is_string($weekdays)) {
        $weekdays = array_filter(array_map('trim', preg_split('~[,\s]+~', $weekdays) ?: []));
    }
    $weekdays = array_values(array_map('intval', array_filter((array)$weekdays)));

    $fields = [
        'name' => $name,
        'kind' => trim((string)($data['kind'] ?? 'general')),
        'multiplier' => (float)($data['multiplier'] ?? 1.0),
        'surcharge_fixed' => (float)($data['surcharge_fixed'] ?? 0),
        'time_from' => trim((string)($data['time_from'] ?? '')) ?: null,
        'time_to'   => trim((string)($data['time_to']   ?? '')) ?: null,
        'date_from' => trim((string)($data['date_from'] ?? '')) ?: null,
        'date_to'   => trim((string)($data['date_to']   ?? '')) ?: null,
        'weekdays'  => $weekdays ? json_encode($weekdays) : null,
        'priority'  => (int)($data['priority'] ?? 0),
        'active'    => !empty($data['active']),
    ];

    if ($id) {
        $st = $pdo->prepare("UPDATE delivery_tariff_modifiers SET
            name=?, kind=?, multiplier=?, surcharge_fixed=?,
            time_from=?, time_to=?, date_from=?, date_to=?, weekdays=?::jsonb,
            priority=?, active=?, updated_at=NOW()
            WHERE id=?");
        $st->execute([
            $fields['name'], $fields['kind'], $fields['multiplier'], $fields['surcharge_fixed'],
            $fields['time_from'], $fields['time_to'], $fields['date_from'], $fields['date_to'], $fields['weekdays'],
            $fields['priority'], $fields['active'],
            $id,
        ]);
        return $id;
    }

    $st = $pdo->prepare("INSERT INTO delivery_tariff_modifiers
        (name, kind, multiplier, surcharge_fixed,
         time_from, time_to, date_from, date_to, weekdays, priority, active)
        VALUES (?,?,?,?,?,?,?,?,?::jsonb,?,?) RETURNING id");
    $st->execute([
        $fields['name'], $fields['kind'], $fields['multiplier'], $fields['surcharge_fixed'],
        $fields['time_from'], $fields['time_to'], $fields['date_from'], $fields['date_to'], $fields['weekdays'],
        $fields['priority'], $fields['active'],
    ]);
    return (int)$st->fetchColumn();
}

function delivery_modifier_delete(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $pdo->prepare('DELETE FROM delivery_tariff_modifiers WHERE id = ?')->execute([$id]);
}

function delivery_modifier_toggle(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $pdo->prepare('UPDATE delivery_tariff_modifiers SET active = NOT active, updated_at = NOW() WHERE id = ?')->execute([$id]);
}

function delivery_modifier_get(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM delivery_tariff_modifiers WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
