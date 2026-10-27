<?php
declare(strict_types=1);

/**
 * Динамическое ценообразование: правила-надбавки и скидки по периоду/категории/товару.
 *
 * Таблица pricing_rules + функции pricing_apply()/pricing_render_*.
 */

function pricing_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS pricing_rules (
        id BIGSERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        description TEXT NULL,
        starts_at TIMESTAMP NULL,
        ends_at TIMESTAMP NULL,
        multiplier NUMERIC(6,3) NOT NULL DEFAULT 1.000,
        surcharge_fixed NUMERIC(10,2) NOT NULL DEFAULT 0,
        applies_to TEXT NOT NULL DEFAULT 'all',
        target_categories JSONB NULL,
        target_product_ids JSONB NULL,
        priority INT NOT NULL DEFAULT 0,
        active BOOLEAN NOT NULL DEFAULT TRUE,
        badge_label TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
        CONSTRAINT pricing_rules_applies_chk CHECK (applies_to IN ('all','category','product')),
        CONSTRAINT pricing_rules_multiplier_chk CHECK (multiplier > 0)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pricing_rules_active  ON pricing_rules (active)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pricing_rules_period  ON pricing_rules (starts_at, ends_at)");

    $done = true;
}

/**
 * Загружает активные правила, действующие на момент $when.
 * Кэширует результат в рамках одного HTTP-запроса.
 */
function pricing_get_active_rules(PDO $pdo, ?DateTimeImmutable $when = null): array {
    static $cache = [];
    $when ??= new DateTimeImmutable('now');
    $key = $when->format('Y-m-d H:i:s');

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    pricing_ensure_schema($pdo);

    $st = $pdo->prepare("
        SELECT id, name, description, starts_at, ends_at,
               multiplier::float8        AS multiplier,
               surcharge_fixed::float8   AS surcharge_fixed,
               applies_to, target_categories, target_product_ids,
               priority, active, badge_label
        FROM pricing_rules
        WHERE active = TRUE
          AND (starts_at IS NULL OR starts_at <= ?)
          AND (ends_at   IS NULL OR ends_at   >= ?)
        ORDER BY priority DESC, id ASC
    ");
    $st->execute([$key, $key]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $cache[$key] = $rows;
}

/**
 * Подходит ли правило конкретному товару.
 */
function pricing_rule_matches_product(array $rule, array $product): bool {
    $appliesTo = (string)($rule['applies_to'] ?? 'all');

    if ($appliesTo === 'all') return true;

    if ($appliesTo === 'category') {
        $cats = $rule['target_categories'] ?? null;
        if (is_string($cats)) $cats = json_decode($cats, true);
        if (!is_array($cats) || !$cats) return false;
        $pcat = trim((string)($product['category'] ?? ''));
        if ($pcat === '') return false;
        $catsLower = array_map('mb_strtolower', $cats);
        return in_array(mb_strtolower($pcat), $catsLower, true);
    }

    if ($appliesTo === 'product') {
        $ids = $rule['target_product_ids'] ?? null;
        if (is_string($ids)) $ids = json_decode($ids, true);
        if (!is_array($ids) || !$ids) return false;
        $ids = array_map('intval', $ids);
        return in_array((int)($product['id'] ?? 0), $ids, true);
    }

    return false;
}

/**
 * Применяет все подходящие правила к товару.
 *
 * Возвращает структуру:
 *  [
 *    'base'         => 1500.0,         // исходная цена
 *    'effective'    => 2250.0,         // итоговая
 *    'multiplier'   => 1.5,            // произведение коэффициентов
 *    'surcharge'    => 0.0,            // сумма фиксированных надбавок
 *    'has_discount' => false,
 *    'has_surcharge'=> true,
 *    'rules'        => [['id','name','multiplier','surcharge_fixed','badge_label'], ...]
 *  ]
 */
function pricing_apply(array $product, PDO $pdo, ?DateTimeImmutable $when = null): array {
    $base  = (float)($product['price'] ?? 0);
    $price = $base;
    $applied = [];
    $multiplierChain = 1.0;
    $surchargeTotal  = 0.0;

    foreach (pricing_get_active_rules($pdo, $when) as $rule) {
        if (!pricing_rule_matches_product($rule, $product)) continue;

        $mult  = (float)$rule['multiplier'];
        $surch = (float)$rule['surcharge_fixed'];
        if (abs($mult - 1.0) < 0.001 && abs($surch) < 0.001) continue;

        $price = $price * $mult + $surch;
        $multiplierChain *= $mult;
        $surchargeTotal  += $surch;

        $applied[] = [
            'id'              => (int)$rule['id'],
            'name'            => (string)$rule['name'],
            'multiplier'      => $mult,
            'surcharge_fixed' => $surch,
            'badge_label'     => trim((string)($rule['badge_label'] ?? '')) !== ''
                                    ? (string)$rule['badge_label']
                                    : (string)$rule['name'],
        ];
    }

    if ($price < 0) $price = 0.0;

    return [
        'base'          => round($base, 2),
        'effective'     => round($price, 2),
        'multiplier'    => round($multiplierChain, 4),
        'surcharge'     => round($surchargeTotal, 2),
        'has_discount'  => $price + 0.01 < $base,
        'has_surcharge' => $price > $base + 0.01,
        'rules'         => $applied,
    ];
}

/**
 * Короткий бейдж для карточки: показываем только первое (самое приоритетное) правило.
 */
function pricing_badge_html(array $info): string {
    if (empty($info['rules'])) return '';
    // Бейдж с названием правила цены виден только менеджеру и администратору, не клиентам.
    $role = (string)($_SESSION['user']['role'] ?? '');
    if (!in_array($role, ['manager', 'admin'], true)) return '';
    $rule = $info['rules'][0];
    $cls  = $info['has_surcharge'] ? 'price-badge price-badge--surge' : 'price-badge price-badge--sale';
    return '<span class="' . $cls . '">' . htmlspecialchars((string)$rule['badge_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
}

/**
 * Какое окончательное число писать как «цену» товара для существующего кода.
 * Полезно, когда нужно «по-старому» получить одно число.
 */
function pricing_effective_price(array $product, PDO $pdo, ?DateTimeImmutable $when = null): float {
    return (float)pricing_apply($product, $pdo, $when)['effective'];
}

/**
 * CRUD для админки.
 */
function pricing_rule_save(PDO $pdo, array $data, ?int $id = null): int {
    pricing_ensure_schema($pdo);

    $name        = trim((string)($data['name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $startsAt    = trim((string)($data['starts_at'] ?? ''));
    $endsAt      = trim((string)($data['ends_at'] ?? ''));
    $multiplier  = (float)($data['multiplier'] ?? 1.0);
    $surcharge   = (float)($data['surcharge_fixed'] ?? 0);
    $appliesTo   = (string)($data['applies_to'] ?? 'all');
    $priority    = (int)($data['priority'] ?? 0);
    $active      = !empty($data['active']);
    $badge       = trim((string)($data['badge_label'] ?? ''));

    $cats = $data['target_categories'] ?? [];
    if (is_string($cats)) {
        $cats = array_filter(array_map('trim', preg_split('~[,\n]~', $cats) ?: []));
    }
    $cats = array_values(array_filter((array)$cats, fn($v) => $v !== ''));

    $ids = $data['target_product_ids'] ?? [];
    if (is_string($ids)) {
        $ids = array_filter(array_map('trim', preg_split('~[,\s]+~', $ids) ?: []));
    }
    $ids = array_values(array_map('intval', array_filter((array)$ids)));

    if ($name === '') {
        throw new RuntimeException('Название правила обязательно.');
    }
    if (!in_array($appliesTo, ['all','category','product'], true)) {
        throw new RuntimeException('Неверная область применения.');
    }
    if ($multiplier <= 0) {
        throw new RuntimeException('Коэффициент должен быть больше нуля.');
    }

    $startsAtSql = $startsAt !== '' ? $startsAt : null;
    $endsAtSql   = $endsAt   !== '' ? $endsAt   : null;

    if ($id) {
        $st = $pdo->prepare("UPDATE pricing_rules
            SET name=?, description=?, starts_at=?, ends_at=?, multiplier=?, surcharge_fixed=?,
                applies_to=?, target_categories=?::jsonb, target_product_ids=?::jsonb,
                priority=?, active=?, badge_label=?, updated_at=NOW()
            WHERE id=?");
        $st->execute([
            $name, $description !== '' ? $description : null,
            $startsAtSql, $endsAtSql, $multiplier, $surcharge,
            $appliesTo,
            json_encode($cats, JSON_UNESCAPED_UNICODE),
            json_encode($ids,  JSON_UNESCAPED_UNICODE),
            $priority, $active, $badge !== '' ? $badge : null,
            $id,
        ]);
        return $id;
    }

    $st = $pdo->prepare("INSERT INTO pricing_rules
        (name, description, starts_at, ends_at, multiplier, surcharge_fixed,
         applies_to, target_categories, target_product_ids, priority, active, badge_label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?)
        RETURNING id");
    $st->execute([
        $name, $description !== '' ? $description : null,
        $startsAtSql, $endsAtSql, $multiplier, $surcharge,
        $appliesTo,
        json_encode($cats, JSON_UNESCAPED_UNICODE),
        json_encode($ids,  JSON_UNESCAPED_UNICODE),
        $priority, $active, $badge !== '' ? $badge : null,
    ]);
    return (int)$st->fetchColumn();
}

function pricing_rule_delete(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $pdo->prepare('DELETE FROM pricing_rules WHERE id = ?')->execute([$id]);
}

function pricing_rule_toggle(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $pdo->prepare('UPDATE pricing_rules SET active = NOT active, updated_at = NOW() WHERE id = ?')->execute([$id]);
}

function pricing_rule_list(PDO $pdo): array {
    pricing_ensure_schema($pdo);
    return $pdo->query("SELECT * FROM pricing_rules ORDER BY active DESC, priority DESC, id DESC")
               ->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pricing_rule_get(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM pricing_rules WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Подготавливает полную «view-модель» цены для шаблона.
 * Совмещает старую статическую скидку (old_price из products) и динамические правила.
 *
 * Возвращает:
 *  [
 *    'price'         => 2250.0,       // что показывать как ОСНОВНУЮ (большую, тёмную) цену
 *    'old_price'     => 1500.0|null,  // зачёркнутая (если есть)
 *    'base'          => 1500.0,       // исходная без правил
 *    'has_discount'  => false,
 *    'has_surcharge' => true,         // = праздничная наценка
 *    'rules'         => [...],
 *    'badge_html'    => '<span ...>'  // готовый HTML бейджа (или '')
 *  ]
 */
function pricing_view(array $product, PDO $pdo, ?DateTimeImmutable $when = null): array {
    $info = pricing_apply($product, $pdo, $when);

    $base      = (float)$info['base'];
    $effective = (float)$info['effective'];
    $staticOld = isset($product['old_price']) && $product['old_price'] !== null
                    ? (float)$product['old_price']
                    : 0.0;

    $hasDiscount  = (bool)$info['has_discount'];
    $hasSurcharge = (bool)$info['has_surcharge'];

    // Какую цену показывать «зачёркнутой».
    // 1. Если есть распродажная скидка от динамического правила → показываем base зачёркнутой.
    // 2. Если есть праздничная наценка → не показываем зачёркнутую (чтобы не пугать клиента «было меньше»).
    // 3. Иначе работает старая логика old_price из БД.
    $strikethrough = null;
    if ($hasDiscount) {
        $strikethrough = $base;
    } elseif (!$hasSurcharge && $staticOld > $base) {
        $strikethrough = $staticOld;
    }

    return [
        'price'         => round($effective, 2),
        'old_price'     => $strikethrough !== null ? round($strikethrough, 2) : null,
        'base'          => round($base, 2),
        'has_discount'  => $hasDiscount || ($strikethrough !== null && !$hasSurcharge),
        'has_surcharge' => $hasSurcharge,
        'rules'         => $info['rules'],
        'badge_html'    => pricing_badge_html($info),
    ];
}
