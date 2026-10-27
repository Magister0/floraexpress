<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_advisor_lib.php';

/**
 * AI-powered product tagging.
 *
 * Идея: один раз пропускаем каждый товар через LLM, получаем структурированные теги,
 * сохраняем в product_ai_metadata. Дальше подбор по тегам идёт мгновенно без вызова LLM.
 *
 * Для диплома: "AI-augmented product enrichment via LLM-based metadata extraction".
 */

function product_ai_tagging_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_ai_metadata (
        product_id BIGINT PRIMARY KEY,
        occasions TEXT NULL,
        avoid_occasions TEXT NULL,
        recipients TEXT NULL,
        moods TEXT NULL,
        style TEXT NULL,
        season TEXT NULL,
        age_group TEXT NULL,
        price_tier TEXT NULL,
        keywords TEXT NULL,
        analysis TEXT NULL,
        confidence NUMERIC(3,2) NULL,
        model_used TEXT NULL,
        analyzed_at TIMESTAMP NOT NULL DEFAULT NOW(),
        manually_edited BOOLEAN NOT NULL DEFAULT FALSE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pai_meta_analyzed ON product_ai_metadata (analyzed_at DESC)");

    // То же самое для дополнительных услуг (шары, открытки, ленты и т.п.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS extra_service_ai_metadata (
        service_id BIGINT PRIMARY KEY,
        occasions TEXT NULL,
        avoid_occasions TEXT NULL,
        recipients TEXT NULL,
        moods TEXT NULL,
        keywords TEXT NULL,
        analysis TEXT NULL,
        confidence NUMERIC(3,2) NULL,
        model_used TEXT NULL,
        analyzed_at TIMESTAMP NOT NULL DEFAULT NOW(),
        manually_edited BOOLEAN NOT NULL DEFAULT FALSE
    )");

    $done = true;
}

/* ----------------------------------------------------------- */
/* Анализ одного товара через YandexGPT                       */
/* ----------------------------------------------------------- */

function product_ai_analyze_one(PDO $pdo, array $product): array {
    if (!ai_advisor_yandex_available()) {
        return ['ok' => false, 'error' => 'no_api_key'];
    }
    product_ai_tagging_ensure_schema($pdo);

    $meanings = ai_load_flower_meanings($pdo);
    $meaningLines = [];
    foreach ($meanings as $fm) {
        $name = (string)$fm['flower_name_ru'];
        $color = trim((string)($fm['color'] ?? ''));
        if ($color !== '') $name = $color . ' ' . $name;
        $meaningLines[] = sprintf('- %s: %s', $name, (string)$fm['meaning']);
    }
    $meaningsBlock = implode("\n", $meaningLines);

    $sysPrompt = <<<PROMPT
Ты — флорист-эксперт с 15-летним опытом. Анализируешь товар цветочного магазина и определяешь, для каких поводов, получателей и настроений он подходит.

ВАЖНО: учитывай реальную флористическую практику и культурный контекст России:
- Белые хризантемы и каллы традиционно ассоциируются с трауром.
- Жёлтые гвоздики/розы означают разочарование, отказ — НЕ дарят на свадьбу, романтические поводы.
- Чётное количество цветов — на похороны, нечётное — на праздничные поводы.
- Красные гвоздики в РФ — про память и победу, не для романтики.
- Лилии (сильный аромат) — не для пожилых людей и при болезни.

ЯЗЫК ЦВЕТОВ:
{$meaningsBlock}

Ответ строго в формате JSON, без обёртки ```json``` и без пояснений:
{
  "occasions": ["birthday","march8","valentine","wedding","anniversary","mothers_day","first_school","apology","funeral","recovery","congrats","romance","just_because"],
  "avoid_occasions": ["funeral","wedding","romance",...],
  "recipients": ["mother","father","girlfriend","wife","colleague","teacher","child","friend","grandma","husband","man","anyone"],
  "moods": ["romantic","bold","passionate","cheerful","elegant","natural","sad","festive","minimalist"],
  "style": "classic|modern|rustic|minimalist|premium|playful",
  "season": ["spring","summer","autumn","winter","year_round"],
  "age_group": ["young","middle","elderly","any"],
  "price_tier": "budget|mid|premium",
  "keywords": ["короткие","ассоциации","до","6","слов"],
  "analysis": "Одно предложение для админа: что это и кому подходит.",
  "confidence": 0.0-1.0
}

Только массивы из перечисленных значений. Если повод НЕ подходит — обязательно добавь его в avoid_occasions.
PROMPT;

    $userMsg = sprintf(
        "Название: %s\nКатегория: %s\nЦена: %s ₽\nОписание: %s",
        (string)($product['name'] ?? ''),
        (string)($product['category'] ?? ''),
        number_format((float)($product['price'] ?? 0), 0, '.', ''),
        (string)($product['description'] ?? '')
    );

    $result = ai_call_yandexgpt($sysPrompt, [['role'=>'user','content'=>$userMsg]], 0.3, 700);

    if (empty($result['ok'])) {
        return ['ok' => false, 'error' => $result['error'] ?? 'unknown'];
    }

    $text = (string)$result['text'];
    // Бывает, что модель оборачивает в ```json ... ```
    $text = preg_replace('~^```(?:json)?\s*|\s*```$~i', '', trim($text)) ?? $text;

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'parse_failed', 'raw' => $text];
    }

    // Нормализация полей
    $normArr = function ($v): array {
        if (is_string($v)) return array_filter(array_map('trim', preg_split('~[,;]~', $v) ?: []));
        if (!is_array($v)) return [];
        return array_values(array_filter(array_map(fn($x) => is_scalar($x) ? trim((string)$x) : '', $v), fn($x) => $x !== ''));
    };

    return [
        'ok' => true,
        'data' => [
            'occasions'       => $normArr($parsed['occasions'] ?? []),
            'avoid_occasions' => $normArr($parsed['avoid_occasions'] ?? []),
            'recipients'      => $normArr($parsed['recipients'] ?? []),
            'moods'           => $normArr($parsed['moods'] ?? []),
            'style'           => trim((string)($parsed['style'] ?? '')) ?: null,
            'season'          => $normArr($parsed['season'] ?? []),
            'age_group'       => $normArr($parsed['age_group'] ?? []),
            'price_tier'      => trim((string)($parsed['price_tier'] ?? '')) ?: null,
            'keywords'        => $normArr($parsed['keywords'] ?? []),
            'analysis'        => trim((string)($parsed['analysis'] ?? '')) ?: null,
            'confidence'      => isset($parsed['confidence']) ? (float)$parsed['confidence'] : null,
        ],
        'tokens' => (int)($result['tokens'] ?? 0),
        'model'  => (string)($result['model'] ?? ''),
    ];
}

function product_ai_metadata_save(PDO $pdo, int $productId, array $data, ?string $model = null, bool $manuallyEdited = false): void {
    product_ai_tagging_ensure_schema($pdo);

    $jsonOrNull = fn($arr) => is_array($arr) && $arr ? json_encode(array_values($arr), JSON_UNESCAPED_UNICODE) : null;

    $st = $pdo->prepare("
        INSERT INTO product_ai_metadata
        (product_id, occasions, avoid_occasions, recipients, moods, style, season, age_group, price_tier, keywords, analysis, confidence, model_used, analyzed_at, manually_edited)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?::boolean)
        ON CONFLICT (product_id) DO UPDATE SET
            occasions = EXCLUDED.occasions,
            avoid_occasions = EXCLUDED.avoid_occasions,
            recipients = EXCLUDED.recipients,
            moods = EXCLUDED.moods,
            style = EXCLUDED.style,
            season = EXCLUDED.season,
            age_group = EXCLUDED.age_group,
            price_tier = EXCLUDED.price_tier,
            keywords = EXCLUDED.keywords,
            analysis = EXCLUDED.analysis,
            confidence = EXCLUDED.confidence,
            model_used = EXCLUDED.model_used,
            analyzed_at = NOW(),
            manually_edited = EXCLUDED.manually_edited
    ");
    $st->execute([
        $productId,
        $jsonOrNull($data['occasions'] ?? []),
        $jsonOrNull($data['avoid_occasions'] ?? []),
        $jsonOrNull($data['recipients'] ?? []),
        $jsonOrNull($data['moods'] ?? []),
        $data['style'] ?? null,
        $jsonOrNull($data['season'] ?? []),
        $jsonOrNull($data['age_group'] ?? []),
        $data['price_tier'] ?? null,
        $jsonOrNull($data['keywords'] ?? []),
        $data['analysis'] ?? null,
        isset($data['confidence']) ? (float)$data['confidence'] : null,
        $model,
        $manuallyEdited ? 't' : 'f',
    ]);
}

function product_ai_metadata_get(PDO $pdo, int $productId): ?array {
    product_ai_tagging_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM product_ai_metadata WHERE product_id = ? LIMIT 1');
    $st->execute([$productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['occasions','avoid_occasions','recipients','moods','season','age_group','keywords'] as $k) {
        $row[$k] = $row[$k] ? (json_decode((string)$row[$k], true) ?: []) : [];
    }
    return $row;
}

function product_ai_metadata_all(PDO $pdo): array {
    product_ai_tagging_ensure_schema($pdo);
    $rows = $pdo->query('SELECT * FROM product_ai_metadata')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        foreach (['occasions','avoid_occasions','recipients','moods','season','age_group','keywords'] as $k) {
            $row[$k] = $row[$k] ? (json_decode((string)$row[$k], true) ?: []) : [];
        }
        $out[(int)$row['product_id']] = $row;
    }
    return $out;
}

function product_ai_stats(PDO $pdo): array {
    product_ai_tagging_ensure_schema($pdo);
    $total      = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE COALESCE(is_active,1)=1')->fetchColumn();
    $analyzed   = (int)$pdo->query('SELECT COUNT(*) FROM product_ai_metadata')->fetchColumn();
    $edited     = (int)$pdo->query('SELECT COUNT(*) FROM product_ai_metadata WHERE manually_edited = TRUE')->fetchColumn();
    return [
        'total_products'   => $total,
        'analyzed'         => $analyzed,
        'unanalyzed'       => max(0, $total - $analyzed),
        'manually_edited'  => $edited,
        'coverage_percent' => $total > 0 ? round($analyzed / $total * 100, 1) : 0,
    ];
}

/**
 * Возвращает товары, которые ещё не проанализированы (без записи в product_ai_metadata).
 */
function product_ai_unanalyzed_products(PDO $pdo, int $limit = 0): array {
    product_ai_tagging_ensure_schema($pdo);
    $sql = "SELECT p.id, p.name, p.price, COALESCE(p.category,'') AS category, COALESCE(p.description,'') AS description
            FROM products p
            LEFT JOIN product_ai_metadata m ON m.product_id = p.id
            WHERE COALESCE(p.is_active,1) = 1 AND m.product_id IS NULL
            ORDER BY p.id DESC";
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ----------------------------------------------------------- */
/* Скоринг товаров по тегам (быстрый, без LLM)                */
/* ----------------------------------------------------------- */

/* ----------------------------------------------------------- */
/* Анализ ДОПОЛНИТЕЛЬНЫХ услуг (шары, открытки и т.п.)        */
/* ----------------------------------------------------------- */

function extra_service_ai_analyze_one(PDO $pdo, array $service): array {
    if (!ai_advisor_yandex_available()) {
        return ['ok' => false, 'error' => 'no_api_key'];
    }
    product_ai_tagging_ensure_schema($pdo);

    $sysPrompt = <<<PROMPT
Ты — флорист-эксперт. Анализируешь ДОПОЛНИТЕЛЬНУЮ услугу/аксессуар к букету.
Это не сам букет, а сопутствующий товар: открытка, шары, лента, шоколадка, мягкая игрушка и т.п.

Твоя задача — определить, ПОД КАКИЕ ПОВОДЫ И ПОЛУЧАТЕЛЕЙ эту опцию имеет смысл предлагать ВМЕСТЕ с букетом.

Например:
- Шары → день рождения, особенно для детей; НЕ подходят к траурным букетам.
- Открытка → почти любой повод, кроме похорон.
- Мягкая игрушка → детям, иногда девушке; НЕ для коллег / траура.
- Лента траурная → только для funeral.

Ответ строго в формате JSON, без обёртки ```json``` и без пояснений:
{
  "occasions": ["birthday","march8","valentine","wedding","anniversary","mothers_day","first_school","apology","funeral","recovery","congrats","romance","just_because"],
  "avoid_occasions": ["funeral","wedding","romance",...],
  "recipients": ["mother","father","girlfriend","wife","colleague","teacher","child","friend","grandma","husband","man","anyone"],
  "moods": ["romantic","bold","passionate","cheerful","elegant","natural","sad","festive","minimalist"],
  "keywords": ["короткие","ассоциации","до","5","слов"],
  "analysis": "Одно предложение для админа: когда уместно предлагать.",
  "confidence": 0.0-1.0
}

Если услуга универсальная (например, открытка) — включи много поводов.
Если узкоспециализированная (траурная лента) — только её повод.
PROMPT;

    $userMsg = sprintf(
        "Название: %s\nЦена: %s ₽\nОписание: %s",
        (string)($service['name'] ?? ''),
        number_format((float)($service['price'] ?? 0), 0, '.', ''),
        (string)($service['description'] ?? '')
    );

    $result = ai_call_yandexgpt($sysPrompt, [['role'=>'user','content'=>$userMsg]], 0.3, 500);

    if (empty($result['ok'])) {
        return ['ok' => false, 'error' => $result['error'] ?? 'unknown'];
    }

    $text = (string)$result['text'];
    $text = preg_replace('~^```(?:json)?\s*|\s*```$~i', '', trim($text)) ?? $text;

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'parse_failed', 'raw' => $text];
    }

    $normArr = function ($v): array {
        if (is_string($v)) return array_filter(array_map('trim', preg_split('~[,;]~', $v) ?: []));
        if (!is_array($v)) return [];
        return array_values(array_filter(array_map(fn($x) => is_scalar($x) ? trim((string)$x) : '', $v), fn($x) => $x !== ''));
    };

    return [
        'ok' => true,
        'data' => [
            'occasions'       => $normArr($parsed['occasions'] ?? []),
            'avoid_occasions' => $normArr($parsed['avoid_occasions'] ?? []),
            'recipients'      => $normArr($parsed['recipients'] ?? []),
            'moods'           => $normArr($parsed['moods'] ?? []),
            'keywords'        => $normArr($parsed['keywords'] ?? []),
            'analysis'        => trim((string)($parsed['analysis'] ?? '')) ?: null,
            'confidence'      => isset($parsed['confidence']) ? (float)$parsed['confidence'] : null,
        ],
        'tokens' => (int)($result['tokens'] ?? 0),
        'model'  => (string)($result['model'] ?? ''),
    ];
}

function extra_service_ai_metadata_save(PDO $pdo, int $serviceId, array $data, ?string $model = null, bool $manuallyEdited = false): void {
    product_ai_tagging_ensure_schema($pdo);
    $jsonOrNull = fn($arr) => is_array($arr) && $arr ? json_encode(array_values($arr), JSON_UNESCAPED_UNICODE) : null;

    $st = $pdo->prepare("
        INSERT INTO extra_service_ai_metadata
        (service_id, occasions, avoid_occasions, recipients, moods, keywords, analysis, confidence, model_used, analyzed_at, manually_edited)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?::boolean)
        ON CONFLICT (service_id) DO UPDATE SET
            occasions = EXCLUDED.occasions,
            avoid_occasions = EXCLUDED.avoid_occasions,
            recipients = EXCLUDED.recipients,
            moods = EXCLUDED.moods,
            keywords = EXCLUDED.keywords,
            analysis = EXCLUDED.analysis,
            confidence = EXCLUDED.confidence,
            model_used = EXCLUDED.model_used,
            analyzed_at = NOW(),
            manually_edited = EXCLUDED.manually_edited
    ");
    $st->execute([
        $serviceId,
        $jsonOrNull($data['occasions'] ?? []),
        $jsonOrNull($data['avoid_occasions'] ?? []),
        $jsonOrNull($data['recipients'] ?? []),
        $jsonOrNull($data['moods'] ?? []),
        $jsonOrNull($data['keywords'] ?? []),
        $data['analysis'] ?? null,
        isset($data['confidence']) ? (float)$data['confidence'] : null,
        $model,
        $manuallyEdited ? 't' : 'f',
    ]);
}

function extra_service_ai_unanalyzed(PDO $pdo): array {
    product_ai_tagging_ensure_schema($pdo);
    return $pdo->query("
        SELECT s.id, s.name, s.price, COALESCE(s.description,'') AS description
        FROM extra_services s
        LEFT JOIN extra_service_ai_metadata m ON m.service_id = s.id
        WHERE COALESCE(s.is_active, TRUE) = TRUE AND m.service_id IS NULL
        ORDER BY s.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function extra_service_ai_stats(PDO $pdo): array {
    product_ai_tagging_ensure_schema($pdo);
    $total    = (int)$pdo->query("SELECT COUNT(*) FROM extra_services WHERE COALESCE(is_active, TRUE) = TRUE")->fetchColumn();
    $analyzed = (int)$pdo->query("SELECT COUNT(*) FROM extra_service_ai_metadata")->fetchColumn();
    return ['total' => $total, 'analyzed' => $analyzed, 'unanalyzed' => max(0, $total - $analyzed)];
}

/**
 * Подбирает дополнительные услуги под сигналы пользователя.
 * Возвращает массив сервисов с _score.
 */
function extra_service_recommend_by_tags(PDO $pdo, array $signals, int $limit = 3): array {
    product_ai_tagging_ensure_schema($pdo);

    $rows = $pdo->query("
        SELECT s.id, s.name, s.price, s.description, s.image, s.allow_message,
               m.occasions, m.avoid_occasions, m.recipients, m.moods, m.confidence
        FROM extra_services s
        LEFT JOIN extra_service_ai_metadata m ON m.service_id = s.id
        WHERE COALESCE(s.is_active, TRUE) = TRUE
        ORDER BY s.sort_order ASC, s.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) return [];

    $parseJson = fn($v) => $v ? (json_decode((string)$v, true) ?: []) : [];

    $scored = [];
    foreach ($rows as $r) {
        $occ   = $parseJson($r['occasions']);
        $avoid = $parseJson($r['avoid_occasions']);
        $rcpt  = $parseJson($r['recipients']);
        $mood  = $parseJson($r['moods']);

        // Без AI-разметки — не предлагаем автоматически
        if (!$occ && !$avoid && !$rcpt && !$mood) continue;

        $score = 0.0;
        foreach ((array)$signals['occasions'] as $o) {
            if (in_array($o, $occ, true))   $score += 4;
            if (in_array($o, $avoid, true)) $score -= 20;
        }
        if (!empty($signals['recipient']) && in_array($signals['recipient'], $rcpt, true)) {
            $score += 2;
        }
        foreach ((array)$signals['moods'] as $m) {
            if (in_array($m, $mood, true)) $score += 1;
        }

        $score *= max(0.5, (float)($r['confidence'] ?? 0.8));

        if ($score > 0) {
            $r['_score'] = round($score, 2);
            $scored[] = $r;
        }
    }

    usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return array_slice($scored, 0, $limit);
}

/**
 * Рекомендует товары на основе сигналов пользователя + AI-тегов.
 * Возвращает [{product..., score, match_reasons}]
 */
function product_ai_recommend_by_tags(PDO $pdo, array $signals, int $limit = 8): array {
    $catalog = ai_load_catalog($pdo, 200);
    if (!$catalog) return [];

    $allMeta = product_ai_metadata_all($pdo);

    $scored = [];
    foreach ($catalog as $p) {
        $pid = (int)$p['id'];
        $meta = $allMeta[$pid] ?? null;
        $score = 0.0;
        $reasons = [];

        // === Если у товара есть AI-теги — используем их ===
        if ($meta) {
            // 1. Повод
            foreach ((array)$signals['occasions'] as $occ) {
                if (in_array($occ, $meta['occasions'] ?? [], true)) {
                    $score += 5;
                    $reasons[] = "повод подходит ($occ)";
                }
                if (in_array($occ, $meta['avoid_occasions'] ?? [], true)) {
                    $score -= 20; // жёсткий штраф
                    $reasons[] = "повод НЕ подходит ($occ)";
                }
            }

            // 2. Получатель
            if (!empty($signals['recipient'])) {
                if (in_array($signals['recipient'], $meta['recipients'] ?? [], true)) {
                    $score += 3;
                    $reasons[] = "получатель подходит";
                }
                if (in_array('anyone', $meta['recipients'] ?? [], true)) {
                    $score += 0.5;
                }
            }

            // 3. Настроение
            foreach ((array)$signals['moods'] as $mood) {
                if (in_array($mood, $meta['moods'] ?? [], true)) {
                    $score += 2;
                    $reasons[] = "настроение ($mood)";
                }
            }

            // 4. Бюджет
            if (!empty($signals['budget'])) {
                $price = (float)$p['price'];
                $budget = (float)$signals['budget'];
                if ($price <= $budget) {
                    $score += 1;
                    if ($price >= $budget * 0.5) {
                        $score += 0.5; // близко к бюджету = лучше использовать
                    }
                } else {
                    $score -= 3; // выше бюджета — штраф
                }

                // Tier matching
                $tier = (string)($meta['price_tier'] ?? '');
                $userTier = $budget < 1500 ? 'budget' : ($budget < 4000 ? 'mid' : 'premium');
                if ($tier === $userTier) {
                    $score += 1;
                }
            }

            // 5. Уверенность модели в анализе
            $score *= max(0.5, (float)($meta['confidence'] ?? 0.8));
        }
        // === Если AI-тегов ещё нет — используем regex-логику как раньше (легкий бонус) ===
        else {
            // На непроанализированные товары вешаем нейтральный балл —
            // они тоже могут попасть в выдачу, но в конце списка
            if (empty($signals['occasions']) && empty($signals['moods'])) {
                $score += 0.3;
            }
        }

        // Только товары с положительным скором попадают в выдачу
        if ($score > 0) {
            $p['_score']   = round($score, 2);
            $p['_reasons'] = $reasons;
            $p['_has_ai_tags'] = $meta !== null;
            $scored[] = $p;
        }
    }

    if (!$scored) return array_slice($catalog, 0, $limit);

    usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return array_slice($scored, 0, $limit);
}
