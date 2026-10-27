<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_config.php';

// Динамическое ценообразование — чтобы консультант показывал актуальные (пересчитанные) цены
if (file_exists(__DIR__ . '/pricing_lib.php')) {
    require_once __DIR__ . '/pricing_lib.php';
}

// Подгружаем AI-тэгинг, если файл доступен (lazy, чтобы не было циклов)
if (file_exists(__DIR__ . '/product_ai_tagging_lib.php') && !function_exists('product_ai_recommend_by_tags')) {
    require_once __DIR__ . '/product_ai_tagging_lib.php';
}

/**
 * Применяет динамическое ценообразование к списку товаров:
 * заменяет поле price на актуальную (пересчитанную правилами) цену.
 * Так консультант «Алиса» показывает те же цены, что и карточка товара.
 */
function ai_apply_dynamic_prices(PDO $pdo, array $products): array {
    if (!function_exists('pricing_effective_price')) return $products;
    foreach ($products as &$p) {
        if (!isset($p['price'])) continue;
        $p['base_price'] = (float)$p['price'];
        try {
            $p['price'] = pricing_effective_price($p, $pdo);
        } catch (Throwable $e) {
            // если правила недоступны — оставляем базовую цену
        }
    }
    unset($p);
    return $products;
}

/**
 * AI-консультант FloraExpress.
 *
 * Архитектура:
 *  - flower_meanings — справочник «язык цветов» (флориография)
 *  - ai_chats       — диалоги с клиентами
 *  - ai_messages    — сообщения внутри диалогов
 *
 * Поток обработки:
 *  1. Клиент → сообщение
 *  2. ai_advisor_handle_message():
 *     - Сохраняет user message
 *     - Извлекает ключевые слова (повод, настроение, получатель)
 *     - Через flower_meanings + правила формирует короткий список товаров (RAG)
 *     - Передаёт LLM системный промпт с каталогом и список рекомендаций
 *     - Если LLM недоступен — fallback на чисто-rule-based ответ
 *     - Сохраняет ответ
 *  3. Возвращает {message, recommended_products, fallback}
 */

function ai_advisor_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS flower_meanings (
        id BIGSERIAL PRIMARY KEY,
        flower_key TEXT NOT NULL,
        flower_name_ru TEXT NOT NULL,
        color TEXT NULL,
        meaning TEXT NOT NULL,
        mood TEXT NULL,
        occasions TEXT NULL,
        avoid_for TEXT NULL,
        source TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_flower_meanings_key ON flower_meanings (flower_key)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_chats (
        id BIGSERIAL PRIMARY KEY,
        user_id BIGINT NULL,
        session_token TEXT NOT NULL,
        title TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_chats_session ON ai_chats (session_token)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_chats_user    ON ai_chats (user_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_messages (
        id BIGSERIAL PRIMARY KEY,
        chat_id BIGINT NOT NULL REFERENCES ai_chats(id) ON DELETE CASCADE,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        recommended_product_ids TEXT NULL,
        tokens_used INT NULL,
        model_used TEXT NULL,
        fallback BOOLEAN NOT NULL DEFAULT FALSE,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_messages_chat ON ai_messages (chat_id, id)");

    $done = true;
}

/* ------------------------------------------------------------------ */
/* Chat lifecycle                                                     */
/* ------------------------------------------------------------------ */

function ai_chat_get_or_create(PDO $pdo, string $sessionToken, ?int $userId = null): array {
    ai_advisor_ensure_schema($pdo);

    $sessionToken = trim($sessionToken);
    if ($sessionToken === '') {
        $sessionToken = bin2hex(random_bytes(16));
    }

    // Существующий диалог за последние 24 часа?
    $st = $pdo->prepare("SELECT * FROM ai_chats WHERE session_token = ? AND updated_at > NOW() - INTERVAL '24 hours' ORDER BY id DESC LIMIT 1");
    $st->execute([$sessionToken]);
    $chat = $st->fetch(PDO::FETCH_ASSOC);
    if ($chat) {
        if ($userId && !$chat['user_id']) {
            $pdo->prepare('UPDATE ai_chats SET user_id = ? WHERE id = ?')->execute([$userId, (int)$chat['id']]);
            $chat['user_id'] = $userId;
        }
        return $chat;
    }

    $st = $pdo->prepare("INSERT INTO ai_chats (user_id, session_token) VALUES (?, ?) RETURNING *");
    $st->execute([$userId, $sessionToken]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function ai_chat_add_message(
    PDO $pdo,
    int $chatId,
    string $role,
    string $content,
    array $recommendedIds = [],
    ?int $tokensUsed = null,
    ?string $modelUsed = null,
    bool $fallback = false
): int {
    // ВАЖНО: PDO + PostgreSQL → PHP bool false передаётся как '' и валит BOOLEAN NOT NULL.
    // Передаём как литералы 't'/'f' через явный SQL-каст.
    $st = $pdo->prepare("INSERT INTO ai_messages
        (chat_id, role, content, recommended_product_ids, tokens_used, model_used, fallback)
        VALUES (?, ?, ?, ?, ?, ?, ?::boolean) RETURNING id");
    $st->execute([
        $chatId, $role, $content,
        $recommendedIds ? json_encode(array_values($recommendedIds)) : null,
        $tokensUsed, $modelUsed, ($fallback ? 't' : 'f'),
    ]);
    $pdo->prepare('UPDATE ai_chats SET updated_at = NOW() WHERE id = ?')->execute([$chatId]);
    return (int)$st->fetchColumn();
}

function ai_chat_history(PDO $pdo, int $chatId, int $limit = 20): array {
    $st = $pdo->prepare("SELECT role, content, recommended_product_ids, fallback, created_at
                         FROM ai_messages WHERE chat_id = ? ORDER BY id ASC LIMIT ?");
    $st->execute([$chatId, $limit]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ------------------------------------------------------------------ */
/* Каталог и язык цветов для RAG                                     */
/* ------------------------------------------------------------------ */

function ai_load_catalog(PDO $pdo, int $limit = 60): array {
    // Исключаем поштучные товары (stem_product = true): это отдельные стебли по 80-150 ₽,
    // которые сами по себе букет не представляют. AI-консультант их рекомендовать не должен.
    $hasStemColumn = false;
    try {
        $hasStemColumn = (bool)$pdo->query("SELECT 1 FROM information_schema.columns
                                            WHERE table_schema='public' AND table_name='products' AND column_name='stem_product'
                                            LIMIT 1")->fetchColumn();
    } catch (Throwable $e) { /* колонки может не быть в старых схемах — это ок */ }

    $where = "COALESCE(is_active,1) = 1";
    if ($hasStemColumn) {
        $where .= " AND COALESCE(stem_product, FALSE) = FALSE";
    }

    $st = $pdo->prepare("SELECT id, name, price, COALESCE(category,'') AS category,
                                COALESCE(description,'') AS description
                         FROM products
                         WHERE {$where}
                         ORDER BY id DESC LIMIT ?");
    $st->execute([$limit]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ai_load_flower_meanings(PDO $pdo): array {
    ai_advisor_ensure_schema($pdo);
    return $pdo->query("SELECT * FROM flower_meanings ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ------------------------------------------------------------------ */
/* Запрос → ключевые сигналы                                          */
/* ------------------------------------------------------------------ */

/**
 * Простая NLP-нормализация на правилах.
 * Извлекает occasion / mood / получателя из запроса.
 */
function ai_extract_signals(string $message): array {
    $m = mb_strtolower($message, 'UTF-8');
    $signals = [
        'occasions'   => [],
        'moods'       => [],
        'recipient'   => null,
        'budget'      => null,      // верхний потолок: «до 3000», «3000 ₽»
        'budget_min'  => null,      // нижний порог: «больше 3 тысяч», «от 5000»
        'colors'      => [],
        'flowers'       => [],      // желаемые виды цветов: «тюльпаны», «пионы»
        'avoid_flowers' => [],      // исключить виды: «без роз», «кроме лилий»
        'avoid'       => [],
        'price_intent' => null, // 'max' | 'min' | null — «самый дорогой / самый дешёвый»
    ];

    $occasionMap = [
        'valentine'    => ['14 февраля', 'валентин', 'святого валентина'],
        'march8'       => ['8 марта', '8марта', 'женский день', 'международный женский'],
        'birthday'     => ['день рожден', 'днюх', 'юбилей', 'годовщин'],
        'wedding'      => ['свадьб', 'венчан'],
        'anniversary'  => ['годовщин', 'юбилей свадьб'],
        'mothers_day'  => ['день матер', 'мам '],
        'first_school' => ['1 сентября', 'учител', 'первое сентября', 'школ'],
        'apology'      => ['извин', 'прости', 'примирен'],
        'funeral'      => ['похорон', 'соболезнован', 'траур'],
        'recovery'     => ['выписк', 'выздоровл', 'болезн'],
        'congrats'     => ['поздрав', 'успех', 'победа', 'защит'],
        'romance'      => ['свидан', 'романт', 'любим', 'девушк', 'влюб'],
    ];
    foreach ($occasionMap as $key => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_strpos($m, $kw) !== false) {
                $signals['occasions'][] = $key;
                break;
            }
        }
    }

    $moodMap = [
        'romantic'   => ['нежн', 'романт', 'трогательн', 'любим', 'любовь'],
        'bold'       => ['яркий', 'мощн', 'эффектн', 'крупн', 'большой', 'роскошн'],
        'passionate' => ['страст', 'жгуч'],
        'cheerful'   => ['весел', 'радостн', 'позитив', 'жизнерадост'],
        'elegant'    => ['элегант', 'стильн', 'минимализм', 'сдержанн'],
        'natural'    => ['полев', 'лесн', 'природн', 'эко'],
        'sad'        => ['грустн', 'печаль', 'утешен'],
    ];
    foreach ($moodMap as $key => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_strpos($m, $kw) !== false) {
                $signals['moods'][] = $key;
                break;
            }
        }
    }

    $recipientMap = [
        'mother'     => ['мам', 'матер'],
        'father'     => ['пап', 'отц'],
        'girlfriend' => ['девушк', 'подруг', 'возлюбленн'],
        'wife'       => ['жен ', 'супруг'],
        'colleague'  => ['коллег', 'офис', 'руководител', 'начальник'],
        'teacher'    => ['учител', 'преподавател'],
        'child'      => ['дочк', 'дочер', 'дет ', 'школьниц'],
        'friend'     => ['друг ', 'подруг'],
        'grandma'    => ['бабушк'],
    ];
    foreach ($recipientMap as $key => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_strpos($m, $kw) !== false) {
                $signals['recipient'] = $key;
                break 2;
            }
        }
    }

    // Бюджет и пороги цены.
    //  - потолок: «до 3000», «не дороже 3000», «дешевле 3 тысяч»  → budget (max)
    //  - порог:   «больше 3 тысяч», «от 5000», «дороже», «свыше»  → budget_min, хочу дороже
    //  - просто сумма: «3000 ₽» → budget (max)
    // ×1000 только если есть суффикс к/тыс/т. Для сравнительных «до 3»/«больше 3»
    // дополнительно множим и при числе <100 («до 3» = до 3 тысяч).
    $mulSuffix  = function (string $num, string $suffix): int {
        $n = (int)$num;
        if ($suffix !== '') $n *= 1000;
        return $n;
    };
    $mulCompare = function (string $num, string $suffix): int {
        $n = (int)$num;
        if ($suffix !== '' || $n < 100) $n *= 1000; // «до 3 тысяч»/«до 3к»/«до 3» → 3000
        return $n;
    };
    $sfx = '(тысяч\w*|тыс\b|т\b|к\b)';
    if (preg_match('~(?:до|не дороже|дешевле|меньше|менее|в пределах|максимум|не более)\s*(\d{1,6})\s*' . $sfx . '?~u', $m, $mm)) {
        $signals['budget'] = $mulCompare($mm[1], $mm[2] ?? '');
        $signals['price_intent'] = 'min'; // «до 3000» — хочу подешевле этого
    } elseif (preg_match('~(?:больше|более|свыше|дороже|не дешевле|от)\s*(\d{1,6})\s*' . $sfx . '?~u', $m, $mm)) {
        $signals['budget_min'] = $mulCompare($mm[1], $mm[2] ?? '');
        $signals['price_intent'] = 'max'; // «больше 3 тысяч» — хочу дороже этого
    } elseif (preg_match('~(?:бюджет|за|около|примерно|в районе|порядка)\s*(\d{1,6})\s*' . $sfx . '?~u', $m, $mm)) {
        $v = $mulSuffix($mm[1], $mm[2] ?? '');            // «бюджет 3000», «за 3000», «около 3к»
        if ($v >= 300) $signals['budget'] = $v;
    } elseif (preg_match('~(\d{3,6})\s*(?:₽|руб\w*|р\b)~u', $m, $mm)) {
        $signals['budget'] = (int)$mm[1];                 // «3000 ₽», «3000 руб», «3000 рублей»
    } elseif (preg_match('~(?<![\d.,])(\d{1,4})\s*' . $sfx . '~u', $m, $mm)) {
        $signals['budget'] = $mulSuffix($mm[1], $mm[2] ?? ''); // «3к», «3 тыс», «3 тысячи»
    } elseif (preg_match('~(?<![\d.,:])(\d{3,6})(?![\d.,:])~u', $m, $mm)) {
        $v = (int)$mm[1];                                  // голое число «3000» — трактуем как бюджет
        if ($v >= 300) $signals['budget'] = $v;
    }

    // Ценовой интент — «самый дорогой / дешёвый», «подороже / подешевле»
    $maxPatterns = ['самый дорог', 'самые дорог', 'подорож', 'дороже', 'премиум', 'люкс', 'дорогой букет', 'дорогие букет', 'максимальн', 'роскошн'];
    $minPatterns = ['самый дешев', 'самый дешёв', 'самые дешев', 'подешев', 'дешевле', 'недорог', 'бюджетн', 'эконом', 'минимальн', 'попроще'];
    foreach ($maxPatterns as $kw) {
        if (mb_strpos($m, $kw) !== false) { $signals['price_intent'] = 'max'; break; }
    }
    if ($signals['price_intent'] === null) {
        foreach ($minPatterns as $kw) {
            if (mb_strpos($m, $kw) !== false) { $signals['price_intent'] = 'min'; break; }
        }
    }

    // Цвета
    $colorMap = [
        'red'    => ['красн', 'алый'],
        'white'  => ['бел'],
        'pink'   => ['розов'],
        'yellow' => ['жёлт', 'желт'],
        'blue'   => ['син ', 'голуб'],
        'purple' => ['фиолет', 'сирен'],
    ];
    foreach ($colorMap as $key => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_strpos($m, $kw) !== false) {
                $signals['colors'][] = $key;
                break;
            }
        }
    }

    // Виды цветов: желаемые («тюльпаны») и исключённые («без роз», «кроме лилий»).
    // Стемы — регэкспы: «роз(?!ов)» отсекает «розовый» (это цвет, а не роза);
    // «пион(?!овидн)» — чтобы «пионовидные розы» не считались пионами.
    foreach (ai_flower_stems() as $label => $stem) {
        if (!preg_match('~' . $stem . '~u', $m)) continue;
        // Отрицание перед цветком: «без роз», «кроме лилий», «не хочу/не люблю/никаких …»
        $negated = (bool)preg_match(
            '~(?:без|кроме|никаких|нет|не\s+хочу|не\s+люблю|не\s+нужн[оы]|исключ\w*)\s+(?:[а-яё]+\s+){0,2}(?:' . $stem . ')~u',
            $m
        );
        if ($negated) $signals['avoid_flowers'][] = $label;
        else          $signals['flowers'][] = $label;
    }
    $signals['flowers']       = array_values(array_unique($signals['flowers']));
    $signals['avoid_flowers'] = array_values(array_unique($signals['avoid_flowers']));
    // Если цветок и в желаемых, и в исключённых — исключение приоритетнее.
    if ($signals['avoid_flowers']) {
        $signals['flowers'] = array_values(array_diff($signals['flowers'], $signals['avoid_flowers']));
    }

    return $signals;
}

/**
 * Справочник видов цветов: метка → регэксп-стем для поиска в названии/описании.
 * Используется и при разборе запроса, и при фильтрации каталога.
 */
function ai_flower_stems(): array {
    return [
        'розы'         => 'роз(?!ов)',
        'тюльпаны'     => 'тюльпан',
        'пионы'        => 'пион(?!овидн)',
        'хризантемы'   => 'хризантем',
        'герберы'      => 'гербер',
        'лилии'        => 'лили',
        'орхидеи'      => 'орхиде',
        'гвоздики'     => 'гвоздик',
        'эустома'      => 'эустом|лизиантус',
        'гортензии'    => 'гортензи',
        'подсолнухи'   => 'подсолнух',
        'ирисы'        => 'ирис',
        'фрезии'       => 'фрези',
        'альстромерии' => 'альстромери',
        'ромашки'      => 'ромашк|хамелациум',
        'георгины'     => 'георгин',
        'каллы'        => 'калл[аыу]',
        'гиацинты'     => 'гиацинт',
        'нарциссы'     => 'нарцисс',
        'маттиола'     => 'маттиол',
    ];
}

/**
 * Фильтрует кандидатов по видам цветов:
 *  - исключает букеты с нежелательными цветами (например, «без роз»);
 *  - если заданы желаемые виды — двигает совпавшие вперёд, при нехватке добирает
 *    подходящие прямым поиском по каталогу.
 */
function ai_apply_flower_constraints(PDO $pdo, array $cands, array $signals, int $limit = 8): array {
    $want  = $signals['flowers'] ?? [];
    $avoid = $signals['avoid_flowers'] ?? [];
    if (!$want && !$avoid) return $cands;

    $stems = ai_flower_stems();
    $hay = fn(array $p): string => mb_strtolower((string)($p['name'] ?? '') . ' ' . (string)($p['description'] ?? ''), 'UTF-8');
    $matches = function (array $p, array $flowers) use ($stems, $hay): bool {
        $h = $hay($p);
        foreach ($flowers as $f) {
            if (isset($stems[$f]) && preg_match('~' . $stems[$f] . '~u', $h)) return true;
        }
        return false;
    };

    // 1. Убираем букеты с нежелательными цветами.
    if ($avoid) {
        $cands = array_values(array_filter($cands, fn($p) => !$matches($p, $avoid)));
    }

    // 2. Желаемые виды — вперёд; если их мало, добираем прямым поиском по каталогу.
    if ($want) {
        $match = array_values(array_filter($cands, fn($p) => $matches($p, $want)));
        if (count($match) < 3) {
            $seen = [];
            foreach ($match as $mm) $seen[(int)$mm['id']] = true;
            foreach (ai_load_catalog($pdo, 200) as $p) {
                if (count($match) >= $limit) break;
                if (isset($seen[(int)$p['id']])) continue;
                if ($avoid && $matches($p, $avoid)) continue;
                if (!$matches($p, $want)) continue;
                $match[] = $p;
                $seen[(int)$p['id']] = true;
            }
        }
        if ($match) {
            $rest = array_values(array_filter($cands, fn($p) => !$matches($p, $want)));
            $cands = array_merge($match, array_slice($rest, 0, 2));
        }
    }

    return $cands;
}

/**
 * Выбирает 3-6 товаров под извлечённые сигналы.
 * Простой rule-based ранжировщик: совпадение категории / слов из meaning / цвета.
 */
function ai_recommend_products(PDO $pdo, array $signals, int $limit = 6): array {
    $catalog  = ai_load_catalog($pdo, 80);
    $meanings = ai_load_flower_meanings($pdo);

    // Карта occasion → ключи цветов из flower_meanings, которые подходят
    $occasionFlowers = [];
    $occasionAvoid   = [];
    foreach ($meanings as $fm) {
        $occ = $fm['occasions'] ? (json_decode((string)$fm['occasions'], true) ?: []) : [];
        $avd = $fm['avoid_for'] ? (json_decode((string)$fm['avoid_for'], true) ?: []) : [];
        foreach ($occ as $o) {
            $occasionFlowers[$o][] = mb_strtolower((string)$fm['flower_name_ru'], 'UTF-8');
        }
        foreach ($avd as $a) {
            $occasionAvoid[$a][] = mb_strtolower((string)$fm['flower_name_ru'], 'UTF-8');
        }
    }

    $preferKeys = [];
    $avoidKeys  = [];
    foreach ($signals['occasions'] as $occ) {
        foreach (($occasionFlowers[$occ] ?? []) as $k) $preferKeys[$k] = ($preferKeys[$k] ?? 0) + 3;
        foreach (($occasionAvoid[$occ] ?? [])    as $k) $avoidKeys[$k]  = true;
    }

    // Скоринг
    $scored = [];
    foreach ($catalog as $p) {
        $name = mb_strtolower((string)$p['name'], 'UTF-8');
        $cat  = mb_strtolower((string)$p['category'], 'UTF-8');
        $desc = mb_strtolower((string)$p['description'], 'UTF-8');
        $hay  = $name . ' ' . $cat . ' ' . $desc;

        $score = 0;
        foreach ($preferKeys as $kw => $weight) {
            if (mb_strpos($hay, $kw) !== false) $score += $weight;
        }
        foreach ($avoidKeys as $kw => $_) {
            if (mb_strpos($hay, $kw) !== false) { $score -= 5; break; }
        }

        // Цветовое совпадение
        foreach ($signals['colors'] as $c) {
            $colorWords = [
                'red'=>['красн'], 'white'=>['бел'], 'pink'=>['розов'],
                'yellow'=>['жёлт','желт'], 'blue'=>['син','голуб'], 'purple'=>['фиолет','сирен']
            ][$c] ?? [];
            foreach ($colorWords as $cw) if (mb_strpos($hay, $cw) !== false) { $score += 2; break; }
        }

        // Бюджет
        if ($signals['budget']) {
            $price = (float)$p['price'];
            if ($price <= $signals['budget']) $score += 1;
            else $score -= 2; // выше бюджета — слегка штрафуем, но не исключаем
        }

        // Если не сработало никаких сигналов — дадим небольшой случайный бонус,
        // чтобы выдача не была пустой
        if (empty($signals['occasions']) && empty($signals['moods']) && empty($signals['colors'])) {
            $score += 0.5;
        }

        if ($score > 0) {
            $scored[] = ['p' => $p, 'score' => $score];
        }
    }

    if (!$scored) {
        // Если есть ценовой интент — отсортируем весь каталог по цене.
        if (!empty($signals['price_intent'])) {
            $sorted = $catalog;
            usort($sorted, fn($a, $b) => $signals['price_intent'] === 'max'
                ? (float)$b['price'] <=> (float)$a['price']
                : (float)$a['price'] <=> (float)$b['price']);
            return array_slice($sorted, 0, $limit);
        }
        // Иначе — самые свежие (новинки)
        return array_slice($catalog, 0, $limit);
    }

    usort($scored, function ($a, $b) use ($signals) {
        // Если пользователь просит самый дорогой/дешёвый — цена становится главным фактором,
        // скор уходит на второе место.
        if (!empty($signals['price_intent'])) {
            $pa = (float)$a['p']['price']; $pb = (float)$b['p']['price'];
            $cmp = $signals['price_intent'] === 'max' ? ($pb <=> $pa) : ($pa <=> $pb);
            if ($cmp !== 0) return $cmp;
        }
        return $b['score'] <=> $a['score'];
    });
    $top = array_slice($scored, 0, $limit);
    return array_map(fn($r) => $r['p'], $top);
}

/* ------------------------------------------------------------------ */
/* Системный промпт                                                   */
/* ------------------------------------------------------------------ */

function ai_build_system_prompt(array $candidates, array $meanings, $signals = null): string {
    // Обратная совместимость: раньше третьим аргументом приходил priceIntent (строка).
    $sig = is_array($signals) ? $signals : [];
    $priceIntent = is_array($signals) ? ($signals['price_intent'] ?? null) : $signals;

    // Каталог-кандидаты: даём модели НЕ только название и цену, но и категорию + состав,
    // иначе ей нечем обосновать выбор и она «советует не то».
    $catalogLines = [];
    foreach ($candidates as $p) {
        $line = sprintf(
            '#%d %s — %s ₽',
            (int)$p['id'], (string)$p['name'],
            number_format((float)$p['price'], 0, '.', '')
        );
        $cat = trim((string)($p['category'] ?? ''));
        if ($cat !== '') $line .= ' [' . $cat . ']';
        $desc = trim((string)preg_replace('~\s+~u', ' ', (string)($p['description'] ?? '')));
        if ($desc !== '') {
            if (mb_strlen($desc, 'UTF-8') > 100) $desc = mb_substr($desc, 0, 100, 'UTF-8') . '…';
            $line .= ' · состав: ' . $desc;
        }
        $catalogLines[] = $line;
    }
    $catalog = implode("\n", $catalogLines);

    // Параметры запроса клиента — передаём модели явно, чтобы она соблюдала
    // бюджет, повод и получателя, а не подбирала «на глаз».
    $occLabels = [
        'valentine' => 'День святого Валентина', 'march8' => '8 Марта', 'birthday' => 'день рождения',
        'wedding' => 'свадьба', 'anniversary' => 'годовщина', 'mothers_day' => 'День матери',
        'first_school' => '1 сентября / учителю', 'apology' => 'извинение / примирение',
        'funeral' => 'траур / соболезнования', 'recovery' => 'выздоровление',
        'congrats' => 'поздравление', 'romance' => 'романтика / свидание',
    ];
    $recLabels = [
        'mother' => 'маме', 'father' => 'папе', 'girlfriend' => 'девушке / подруге',
        'wife' => 'жене', 'colleague' => 'коллеге / руководителю', 'teacher' => 'учителю',
        'child' => 'ребёнку', 'friend' => 'другу', 'grandma' => 'бабушке',
    ];
    $colorLabels = [
        'red' => 'красные', 'white' => 'белые', 'pink' => 'розовые',
        'yellow' => 'жёлтые', 'blue' => 'синие / голубые', 'purple' => 'фиолетовые / сиреневые',
    ];
    $moodLabels = [
        'romantic' => 'нежное / романтичное', 'bold' => 'яркое / эффектное', 'passionate' => 'страстное',
        'cheerful' => 'весёлое', 'elegant' => 'элегантное', 'natural' => 'природное / полевое', 'sad' => 'сдержанное / утешительное',
    ];
    $constraints = [];
    $occ = array_values(array_unique((array)($sig['occasions'] ?? [])));
    if ($occ) {
        $names = array_map(fn($o) => $occLabels[$o] ?? $o, $occ);
        $constraints[] = 'Повод: ' . implode(', ', $names);
    }
    if (!empty($sig['recipient'])) {
        $constraints[] = 'Получатель: ' . ($recLabels[$sig['recipient']] ?? $sig['recipient']);
    }
    if (!empty($sig['budget'])) {
        $constraints[] = 'Бюджет: не дороже ' . number_format((float)$sig['budget'], 0, '.', ' ') . ' ₽';
    }
    if (!empty($sig['budget_min'])) {
        $constraints[] = 'Цена: клиент хочет ДОРОЖЕ ' . number_format((float)$sig['budget_min'], 0, '.', ' ') . ' ₽ — не предлагай дешевле';
    }
    if (!empty($sig['colors'])) {
        $names = array_map(fn($c) => $colorLabels[$c] ?? $c, (array)$sig['colors']);
        $constraints[] = 'Предпочтения по цвету: ' . implode(', ', $names);
    }
    if (!empty($sig['moods'])) {
        $names = array_map(fn($mo) => $moodLabels[$mo] ?? $mo, (array)$sig['moods']);
        $constraints[] = 'Настроение: ' . implode(', ', $names);
    }
    if (!empty($sig['flowers'])) {
        $constraints[] = 'Желаемые цветы: ' . implode(', ', (array)$sig['flowers']);
    }
    if (!empty($sig['avoid_flowers'])) {
        $constraints[] = 'НЕ предлагать букеты с этими цветами: ' . implode(', ', (array)$sig['avoid_flowers']);
    }
    $constraintsBlock = $constraints
        ? "ПАРАМЕТРЫ ЗАПРОСА КЛИЕНТА (соблюдай строго):\n- " . implode("\n- ", $constraints) . "\n\n"
        : '';

    $meaningLines = [];
    foreach ($meanings as $fm) {
        $name = (string)$fm['flower_name_ru'];
        $color = trim((string)($fm['color'] ?? ''));
        if ($color !== '') $name = $color . ' ' . $name;
        $meaningLines[] = sprintf('- %s: %s', $name, (string)$fm['meaning']);
    }
    $meaningsBlock = implode("\n", $meaningLines);

    $priceHint = '';
    if ($priceIntent === 'max') {
        $priceHint = "ВАЖНО: пользователь просит САМЫЙ ДОРОГОЙ букет. Каталог уже отсортирован от самого дорогого к дешёвому — рекомендуй ПЕРВЫЕ позиции и явно подчеркни в ответе, что это самые премиальные варианты.\n\n";
    } elseif ($priceIntent === 'min') {
        $priceHint = "ВАЖНО: пользователь просит САМЫЙ ДЕШЁВЫЙ букет. Каталог уже отсортирован от самого дешёвого к дорогому — рекомендуй ПЕРВЫЕ позиции и явно подчеркни в ответе, что это самые бюджетные варианты.\n\n";
    }

    return <<<PROMPT
Ты — Алиса, флорист-консультант магазина FloraExpress в Екатеринбурге. Ты живой собеседник, а не справочник: говоришь по-человечески, иногда с лёгким юмором, шутишь над собой, разделяешь радость или сочувствие. Не используй канцелярит, не повторяй слова «вариант», «подойдёт», «отличный выбор» — звучит шаблонно.

Когда подбираешь букет — рассуждай вслух: «Если повод романтический, я бы взяла…», «Если бюджет небольшой, мне нравится…». Это создаёт ощущение разговора с человеком, а не с базой данных.

ПРАВИЛА:
- Длина: 2–4 живых предложения. Не пиши «здравствуйте» каждый раз — это не первое сообщение.
- Упомяни 2–4 конкретных букета из каталога по НАЗВАНИЮ. Объясни выбор: «потому что розы символизируют…», «потому что в бюджет 2000 ₽ хорошо ложится…».
- НЕ ПИШИ в видимом тексте id вида #123, №123 — только названия.
- В САМОМ КОНЦЕ добавь служебную строку: <products>3, 7, 12</products> — она будет вырезана.
- Не выдумывай товары, которых нет в каталоге ниже.
- Не повторяй одинаковые формулировки между сообщениями.
- Деликатные поводы (похороны, болезнь) — без восклицаний, без эмодзи, мягкий тон.
- Соблюдай ПАРАМЕТРЫ ЗАПРОСА ниже: не предлагай букеты дороже бюджета и не подходящие к поводу. Если под бюджет в каталоге ничего нет — честно скажи и предложи ближайшее по цене.
- Опирайся на состав букетов из каталога: подбирай и обосновывай по реальным цветам в составе, не придумывай того, чего в составе нет.
- Если клиент просит конкретные цветы (например, тюльпаны) — предлагай только букеты с ними. Если просит без каких-то цветов (например, без роз) — ни в коем случае не предлагай букеты с ними.
- Если запрос неясен — задай ОДИН уточняющий вопрос (повод? бюджет? получатель?), <products> не добавляй.

ХОРОШИЕ ПРИМЕРЫ:
- «Мама — это всегда классика и тепло, поэтому возьмите Нежный ветер: пионы и эустома создают то самое ощущение домашнего уюта. А если хочется поярче — Сборный букет с ромашками подкупает простотой.»
- «На годовщину пионовидные розы — почти беспроигрышно, они в языке цветов означают благородство и восхищение. Если хочется чего-то более камерного — Мини-букет в сумочке „Белый“ выглядит как подарок-сюрприз.»

ПЛОХИЕ ПРИМЕРЫ (так НЕ надо):
- «По вашему запросу подойдут такие варианты: букет 1, букет 2, букет 3. Можно открыть карточки и оформить заказ.»
- «Отличный выбор! Этот букет идеально подойдёт. Также рекомендую посмотреть...»

{$priceHint}{$constraintsBlock}КАТАЛОГ (id — название — цена — [категория] — состав):
{$catalog}

ЯЗЫК ЦВЕТОВ (используй как обоснование, но не цитируй дословно):
{$meaningsBlock}
PROMPT;
}

/* ------------------------------------------------------------------ */
/* Вызов YandexGPT                                                    */
/* ------------------------------------------------------------------ */

/**
 * Один запрос к YandexGPT API. Возвращает ['ok'=>bool, 'text'=>?, 'tokens'=>?, 'error'=>?]
 */
function ai_call_yandexgpt(string $systemPrompt, array $messages, float $temperature = 0.6, int $maxTokens = 800): array {
    if (!ai_advisor_yandex_available()) {
        return ['ok' => false, 'error' => 'no_api_key'];
    }

    $modelUri = 'gpt://' . YANDEX_GPT_FOLDER_ID . '/' . YANDEX_GPT_MODEL;

    $payloadMessages = [['role' => 'system', 'text' => $systemPrompt]];
    foreach ($messages as $m) {
        if (!isset($m['role'], $m['content'])) continue;
        $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
        $payloadMessages[] = ['role' => $role, 'text' => (string)$m['content']];
    }

    $payload = [
        'modelUri' => $modelUri,
        'completionOptions' => [
            'stream'      => false,
            'temperature' => $temperature,
            'maxTokens'   => (string)$maxTokens,
        ],
        'messages' => $payloadMessages,
    ];

    $body = null;
    $err  = null;

    if (function_exists('curl_init')) {
        $ch = curl_init(YANDEX_GPT_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Api-Key ' . YANDEX_GPT_API_KEY,
                'x-folder-id: ' . YANDEX_GPT_FOLDER_ID,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_USERAGENT      => 'FloraExpress/1.0',
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false || $http >= 400) {
            return ['ok' => false, 'error' => 'http_' . $http . ($err ? ': ' . $err : ''), 'raw' => $body];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'timeout' => 30,
                'header'  => "Content-Type: application/json\r\nAuthorization: Api-Key " . YANDEX_GPT_API_KEY . "\r\nx-folder-id: " . YANDEX_GPT_FOLDER_ID . "\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ignore_errors' => true,
            ]
        ]);
        $body = @file_get_contents(YANDEX_GPT_API_URL, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'error' => 'request_failed'];
        }
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data) || !isset($data['result']['alternatives'][0]['message']['text'])) {
        return ['ok' => false, 'error' => 'parse_failed', 'raw' => $body];
    }

    return [
        'ok'     => true,
        'text'   => (string)$data['result']['alternatives'][0]['message']['text'],
        'tokens' => (int)($data['result']['usage']['totalTokens'] ?? 0),
        'model'  => $modelUri,
    ];
}

/* ------------------------------------------------------------------ */
/* Извлечение product IDs из ответа LLM                              */
/* ------------------------------------------------------------------ */

function ai_extract_product_ids(string $text, array $catalog): array {
    $ids = [];

    // 1. Тэг <products>1, 2, 3</products>
    if (preg_match('~<products>([\d,\s]+)</products>~i', $text, $m)) {
        foreach (preg_split('~[,\s]+~', $m[1]) as $tok) {
            $tok = trim($tok);
            if ($tok !== '' && ctype_digit($tok)) $ids[] = (int)$tok;
        }
    }

    // 2. Поиск по названиям если нет тэга
    if (!$ids) {
        $textLower = mb_strtolower($text, 'UTF-8');
        foreach ($catalog as $p) {
            $name = mb_strtolower((string)$p['name'], 'UTF-8');
            if ($name !== '' && mb_strpos($textLower, $name) !== false) {
                $ids[] = (int)$p['id'];
            }
        }
    }

    // Фильтр: оставляем только id из каталога
    $validIds = array_column($catalog, 'id');
    $ids = array_values(array_unique(array_intersect($ids, array_map('intval', $validIds))));
    return array_slice($ids, 0, 6);
}

/* ------------------------------------------------------------------ */
/* Fallback без LLM — чисто на правилах                              */
/* ------------------------------------------------------------------ */

function ai_fallback_reply(array $signals, array $recommended): string {
    if (!$recommended) {
        return 'Расскажите подробнее: для кого букет, какой повод и какой бюджет? Подберу несколько вариантов из нашего каталога.';
    }

    $occasionPhrase = '';
    foreach ($signals['occasions'] as $occ) {
        $occasionPhrase = match ($occ) {
            'valentine'    => 'На День святого Валентина ',
            'march8'       => 'К 8 марта ',
            'birthday'     => 'На день рождения ',
            'wedding'      => 'На свадьбу ',
            'anniversary'  => 'К годовщине ',
            'mothers_day'  => 'Ко Дню матери ',
            'first_school' => 'К 1 сентября ',
            'romance'      => 'Для романтического повода ',
            'funeral'      => 'Для траурного повода ',
            'recovery'     => 'Для пожелания скорейшего выздоровления ',
            'congrats'     => 'Для поздравления ',
            'apology'      => 'Для примирения ',
            default => '',
        };
        if ($occasionPhrase) break;
    }

    if ($occasionPhrase === '') $occasionPhrase = 'По вашему запросу ';

    $namesPart = [];
    foreach (array_slice($recommended, 0, 3) as $p) {
        $namesPart[] = (string)$p['name'];
    }
    $namesStr = implode(', ', $namesPart);

    $ids = array_map(fn($p) => (int)$p['id'], array_slice($recommended, 0, 4));
    $idsStr = implode(', ', $ids);

    // Несколько вариантов формулировок — чтобы в истории чата не было копипасты.
    $templates = [
        "{occasion}посмотрите на {names}. Если что — могу подобрать ещё, скажите бюджет или повод подробнее.",
        "{occasion}хорошо смотрятся {names}. Открывайте карточки — там фото и состав.",
        "{occasion}я бы предложила обратить внимание на {names}. Доставим в день заказа.",
        "{occasion}подобрала несколько идей: {names}. Если нужно другое настроение — напишите, посмотрю ещё варианты.",
        "{occasion}мне нравятся {names}. Уточните бюджет и повод — подберу точнее.",
    ];
    $tpl = $templates[array_rand($templates)];
    $occLower = $occasionPhrase === 'По вашему запросу ' ? '' : mb_strtolower(mb_substr($occasionPhrase, 0, 1), 'UTF-8') . mb_substr($occasionPhrase, 1);
    $body = strtr($tpl, [
        '{occasion}' => $occasionPhrase === 'По вашему запросу ' ? '' : $occasionPhrase,
        '{names}'    => $namesStr,
    ]);
    $body = mb_strtoupper(mb_substr($body, 0, 1), 'UTF-8') . mb_substr($body, 1);

    return $body . "\n<products>{$idsStr}</products>";
}

/* ------------------------------------------------------------------ */
/* Главная точка входа                                                */
/* ------------------------------------------------------------------ */

/**
 * Простые приветствия — на них не надо тянуть RAG и LLM, отвечаем сразу.
 */
function ai_is_greeting(string $msg): bool {
    $m = mb_strtolower(trim($msg), 'UTF-8');
    if (mb_strlen($m, 'UTF-8') > 30) return false; // длинное «Привет, мне нужен букет...» — не приветствие
    $patterns = ['привет', 'здравств', 'добрый день', 'добрый вечер', 'доброе утро', 'хай ', 'хей ', 'йоу ', 'hi ', 'hello'];
    foreach ($patterns as $p) {
        if (mb_strpos($m, $p) !== false) return true;
    }
    return in_array($m, ['ку', 'хай', 'хей', 'йоу', 'hi', 'hello', '!', 'привет!', 'здравствуйте', 'здравствуйте!'], true);
}

/**
 * Обрабатывает одно сообщение клиента: сохраняет, генерирует ответ, возвращает результат.
 *
 * @return array {
 *     reply: string,
 *     recommended: array<int, array>,   // продукты целиком
 *     fallback: bool,
 *     chat_id: int,
 * }
 */
function ai_advisor_handle_message(PDO $pdo, string $sessionToken, string $userMessage, ?int $userId = null): array {
    ai_advisor_ensure_schema($pdo);

    $chat = ai_chat_get_or_create($pdo, $sessionToken, $userId);
    $chatId = (int)$chat['id'];

    ai_chat_add_message($pdo, $chatId, 'user', $userMessage);

    // Короткое приветствие — отвечаем сразу, без LLM-вызова и подборки товаров
    if (ai_is_greeting($userMessage)) {
        $greetings = [
            'Здравствуйте! Расскажите, для кого нужен букет и по какому поводу — подберу варианты.',
            'Привет! Кому и на какой повод выбираете букет? Учту бюджет и предпочтения.',
            'Добрый день! Опишите, что нужно: повод, получатель, любимые цветы или бюджет — и я подскажу.',
        ];
        $greet = $greetings[array_rand($greetings)];
        ai_chat_add_message($pdo, $chatId, 'assistant', $greet, [], null, 'greeting', false);
        return [
            'reply'       => $greet,
            'recommended' => [],
            'fallback'    => false,
            'chat_id'     => $chatId,
            'model'       => 'greeting',
            'tokens'      => 0,
            'signals'     => ['greeting' => true],
        ];
    }

    $signals = ai_extract_signals($userMessage);

    // Контекст диалога: короткие уточнения («больше 3 тысяч», «подешевле», «а другие цвета»)
    // обычно не содержат повод/получателя — подтягиваем их из предыдущих реплик клиента,
    // иначе подбор «забывает», что букет маме на день рождения. Бюджет/порог НЕ наследуем —
    // клиент мог их как раз изменить этим сообщением.
    if (empty($signals['occasions']) && empty($signals['recipient'])
        && empty($signals['colors']) && empty($signals['flowers']) && empty($signals['avoid_flowers'])) {
        try {
            $stPrev = $pdo->prepare("SELECT content FROM ai_messages WHERE chat_id = ? AND role = 'user' ORDER BY id DESC LIMIT 5");
            $stPrev->execute([$chatId]);
            $prevMsgs = $stPrev->fetchAll(PDO::FETCH_COLUMN) ?: [];
            array_shift($prevMsgs); // первая строка — это текущее сообщение, пропускаем
            foreach ($prevMsgs as $pm) {
                $ps = ai_extract_signals((string)$pm);
                if (empty($signals['occasions'])     && !empty($ps['occasions']))     $signals['occasions']     = $ps['occasions'];
                if (empty($signals['recipient'])     && !empty($ps['recipient']))     $signals['recipient']     = $ps['recipient'];
                if (empty($signals['colors'])        && !empty($ps['colors']))        $signals['colors']        = $ps['colors'];
                if (empty($signals['moods'])         && !empty($ps['moods']))         $signals['moods']         = $ps['moods'];
                if (empty($signals['flowers'])       && !empty($ps['flowers']))       $signals['flowers']       = $ps['flowers'];
                if (empty($signals['avoid_flowers']) && !empty($ps['avoid_flowers'])) $signals['avoid_flowers'] = $ps['avoid_flowers'];
                if (!empty($signals['occasions']) && !empty($signals['recipient'])) break;
            }
        } catch (Throwable $e) { /* контекст необязателен — без него тоже работает */ }
    }

    $recommendCandidates = [];
    $usedAiTagging = false;

    // Если пользователь явно просит «самый дорогой/дешёвый» — игнорируем рекомендаторы,
    // грузим топ-N по цене напрямую из products. Иначе AI-tagging-рекомендатор может вернуть
    // только размеченные товары (которых может быть 2-3 штуки) и ценовая сортировка ничего не даст.
    if (!empty($signals['price_intent'])) {
        $direction = $signals['price_intent'] === 'max' ? 'DESC' : 'ASC';
        // Учитываем порог/потолок цены: «больше 3 тысяч» → price >= 3000, «до 3000» → price <= 3000.
        $priceFilter = '';
        if (!empty($signals['budget_min'])) $priceFilter .= ' AND price >= ' . (int)$signals['budget_min'];
        if (!empty($signals['budget']) && $signals['price_intent'] === 'min') {
            $priceFilter .= ' AND price <= ' . (int)$signals['budget'];
        }
        $selectCols = "SELECT id, name, price, COALESCE(category,'') AS category, COALESCE(description,'') AS description, COALESCE(image,'') AS image
                 FROM products
                 WHERE COALESCE(is_active,1)=1 AND price > 0";
        try {
            $st = $pdo->query("{$selectCols}{$priceFilter} ORDER BY price {$direction} LIMIT 8");
            $recommendCandidates = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            // Если под порог ничего не попало (просят дороже, чем есть в каталоге) —
            // показываем хотя бы в нужном направлении, без жёсткого фильтра.
            if (!$recommendCandidates && $priceFilter !== '') {
                $st = $pdo->query("{$selectCols} ORDER BY price {$direction} LIMIT 8");
                $recommendCandidates = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            }
        } catch (Throwable $e) {
            @file_put_contents(
                __DIR__ . '/ai_advisor_error.log',
                '[' . date('Y-m-d H:i:s') . '] price_intent direct query failed: ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }
    }

    // Если ценового интента нет — обычная логика: сначала AI-теги, потом rule-based.
    if (!$recommendCandidates && function_exists('product_ai_recommend_by_tags')) {
        $stats = function_exists('product_ai_stats') ? product_ai_stats($pdo) : ['analyzed' => 0];
        if ((int)($stats['analyzed'] ?? 0) > 0) {
            $recommendCandidates = product_ai_recommend_by_tags($pdo, $signals, 8);
            $usedAiTagging = !empty($recommendCandidates);
        }
    }
    if (!$recommendCandidates) {
        $recommendCandidates = ai_recommend_products($pdo, $signals, 8);
    }

    // Фильтр по видам цветов: убираем нежелательные («без роз») и поднимаем
    // желаемые («тюльпаны») — иначе на запрос «тюльпаны» подбор выдаёт розы.
    $recommendCandidates = ai_apply_flower_constraints($pdo, $recommendCandidates, $signals, 8);

    // Пересчитываем цены по правилам динамического ценообразования,
    // чтобы в подсказке и в карточках были актуальные цены (как на карточке товара).
    $recommendCandidates = ai_apply_dynamic_prices($pdo, $recommendCandidates);

    // Жёсткое соблюдение бюджета. budget — это верхний потолок (и для «до 3000»,
    // и для просто «3000 ₽»). Раньше сюда поверх бюджета подмешивались 2 «дорогих
    // альтернативы» — именно из-за этого консультант предлагал, например, 3600 при
    // бюджете 3000. Теперь дороже бюджета модели не показываем вовсе: она не сможет
    // их порекомендовать. budget_min / «самый дорогой» сюда не попадают (budget = null).
    $budgetCap = !empty($signals['budget']) ? (float)$signals['budget'] : null;
    if ($budgetCap !== null) {
        $tol = $budgetCap * 1.02; // небольшой допуск на округление цен (+2%)
        $within = $over = [];
        foreach ($recommendCandidates as $p) {
            if ((float)($p['price'] ?? 0) <= $tol) $within[] = $p;
            else $over[] = $p;
        }
        if ($within) {
            $recommendCandidates = $within; // есть варианты в бюджет — показываем только их
        } else {
            // В бюджет вообще ничего нет — оставляем самые дешёвые как ближайшие по цене,
            // модель честно скажет, что точно в бюджет ничего не нашлось.
            usort($over, fn($a, $b) => (float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0));
            $recommendCandidates = array_slice($over, 0, 4);
        }
    }

    $meanings = ai_load_flower_meanings($pdo);

    $reply = '';
    $tokens = null;
    $modelUsed = null;
    $fallback = false;
    $recommendedIds = [];

    if (ai_advisor_yandex_available() && $recommendCandidates) {
        $systemPrompt = ai_build_system_prompt($recommendCandidates, $meanings, $signals);

        // Передаём последние 4 сообщения как контекст (больше — раздувает промпт и провоцирует пустые ответы)
        $history = ai_chat_history($pdo, $chatId, 4);
        $historyMessages = [];
        foreach ($history as $h) {
            if ($h['role'] === 'user' || $h['role'] === 'assistant') {
                $historyMessages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }

        // temperature повышен до 0.85 — ответы заметно живее, меньше шаблона.
        // maxTokens поднят до 800 — модель не обрезается на полпути.
        $result = ai_call_yandexgpt($systemPrompt, $historyMessages, 0.85, 800);

        // Ретрай один раз, если первая попытка упала или вернула пустоту.
        if (empty($result['ok']) || trim((string)($result['text'] ?? '')) === '') {
            @file_put_contents(
                __DIR__ . '/ai_advisor_error.log',
                '[' . date('Y-m-d H:i:s') . '] YandexGPT 1st attempt failed/empty, retrying. Result: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
            usleep(300000); // 0.3 сек
            $result = ai_call_yandexgpt($systemPrompt, $historyMessages, 0.7, 800);
        }

        if (!empty($result['ok'])) {
            $reply = (string)$result['text'];
            $tokens = (int)($result['tokens'] ?? 0);
            $modelUsed = (string)($result['model'] ?? '');
            $recommendedIds = ai_extract_product_ids($reply, $recommendCandidates);
        } else {
            $fallback = true;
            @file_put_contents(
                __DIR__ . '/ai_advisor_error.log',
                '[' . date('Y-m-d H:i:s') . '] YandexGPT failed after retry: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
        }
    } else {
        $fallback = true;
        @file_put_contents(
            __DIR__ . '/ai_advisor_error.log',
            '[' . date('Y-m-d H:i:s') . "] YandexGPT not configured or no candidates. Check YANDEX_GPT_API_KEY / YANDEX_GPT_FOLDER_ID.\n",
            FILE_APPEND
        );
    }

    if ($fallback || $reply === '') {
        $reply = ai_fallback_reply($signals, $recommendCandidates);
        $recommendedIds = array_map(fn($p) => (int)$p['id'], array_slice($recommendCandidates, 0, 4));
        $fallback = true;
        $modelUsed = 'fallback_rules';
    }

    // Чистый текст ответа для отображения — без служебного тэга <products>
    $cleanReply = (string)preg_replace('~\s*<products>[^<]*</products>\s*~i', '', $reply);

    // Убираем инлайн-идентификаторы вида (#123), #123, (№123), №123 — пользователю они не нужны
    $cleanReply = (string)preg_replace('~\s*\(\s*[#№]\s*\d+\s*\)~u', '', $cleanReply);   // (#123), (№123)
    $cleanReply = (string)preg_replace('~\s*[#№]\s*\d+\b~u', '', $cleanReply);            // #123, №123
    // Лишние двойные пробелы, оставшиеся после чистки
    $cleanReply = (string)preg_replace('~ {2,}~', ' ', $cleanReply);
    // Лишние пробелы перед знаками препинания
    $cleanReply = (string)preg_replace('~ +([,.!?;:])~u', '$1', $cleanReply);
    $cleanReply = trim($cleanReply);

    // Если после чистки текст пустой/слишком короткий — два варианта:
    // 1) Модель прислала только <products>...</products>: товары есть, текста нет — синтезируем обёртку,
    //    НЕ помечаем как fallback, потому что выбор сделала именно нейросеть.
    // 2) Совсем ничего — переключаемся на rule-based fallback.
    if (mb_strlen($cleanReply, 'UTF-8') < 5) {
        if ($recommendedIds && !empty($result['ok'])) {
            // Берём названия из каталога и оборачиваем в одну из нескольких живых фраз.
            $namesByid = [];
            foreach ($recommendCandidates as $p) {
                $namesByid[(int)$p['id']] = (string)$p['name'];
            }
            $namesPart = [];
            foreach ($recommendedIds as $id) {
                if (isset($namesByid[$id])) $namesPart[] = $namesByid[$id];
            }
            $namesStr = implode(', ', array_slice($namesPart, 0, 3));
            $wrappers = [
                "Посмотрите: {$namesStr}. Если нужно другое настроение — напишите, что важно учесть.",
                "Подобрала несколько идей: {$namesStr}. Расскажите про повод и бюджет — уточню.",
                "Мне нравятся {$namesStr}. Открывайте карточки — там фото и состав.",
                "Хорошо смотрятся {$namesStr}. Сказать, у какого самый яркий аромат?",
            ];
            $cleanReply = $wrappers[array_rand($wrappers)];
            // Это НЕ fallback — модель выбрала товары, мы лишь добавили обёртку.
        } else {
            $fallbackReply = ai_fallback_reply($signals, $recommendCandidates);
            if (!$recommendedIds) {
                $recommendedIds = array_map(fn($p) => (int)$p['id'], array_slice($recommendCandidates, 0, 4));
            }
            $cleanReply = (string)preg_replace('~\s*<products>[^<]*</products>\s*~i', '', $fallbackReply);
            $cleanReply = trim($cleanReply);
            $fallback = true;
            $modelUsed = $modelUsed ?: 'fallback_rules';
        }
    }

    // Финальная страховка по бюджету: YandexGPT нестрого соблюдает инструкции и может
    // всё равно назвать букет дороже бюджета. Убираем такие из выдачи, а если после
    // фильтра ничего не осталось — подставляем самые дешёвые варианты в бюджет, чтобы
    // консультант не отвечал «пусто».
    if ($budgetCap !== null) {
        $priceById = [];
        foreach ($recommendCandidates as $p) $priceById[(int)$p['id']] = (float)($p['price'] ?? 0);
        $tol = $budgetCap * 1.02;
        if ($recommendedIds) {
            $recommendedIds = array_values(array_filter(
                $recommendedIds,
                fn($id) => !isset($priceById[$id]) || $priceById[$id] <= $tol
            ));
        }
        if (!$recommendedIds) {
            $within = array_filter($recommendCandidates, fn($p) => (float)($p['price'] ?? 0) <= $tol);
            usort($within, fn($a, $b) => (float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0));
            $pool = array_values($within ?: $recommendCandidates);
            $recommendedIds = array_map(fn($p) => (int)$p['id'], array_slice($pool, 0, 3));
        }
    }

    ai_chat_add_message($pdo, $chatId, 'assistant', $cleanReply, $recommendedIds, $tokens, $modelUsed, $fallback);

    // Загружаем полные данные товаров для ответа
    $recommendedProducts = [];
    if ($recommendedIds) {
        $in = implode(',', array_fill(0, count($recommendedIds), '?'));
        $st = $pdo->prepare("SELECT id, name, price, image, category FROM products WHERE id IN ($in)");
        $st->execute($recommendedIds);
        $byId = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $byId[(int)$row['id']] = $row;
        foreach ($recommendedIds as $id) {
            if (isset($byId[$id])) $recommendedProducts[] = $byId[$id];
        }
        // Актуальные цены по правилам — чтобы карточки совпадали с ценой на странице товара
        $recommendedProducts = ai_apply_dynamic_prices($pdo, $recommendedProducts);
    }

    return [
        'reply'       => $cleanReply,
        'recommended' => $recommendedProducts,
        'fallback'    => $fallback,
        'chat_id'     => $chatId,
        'model'       => $modelUsed,
        'tokens'      => $tokens,
        'signals'     => $signals,
    ];
}
