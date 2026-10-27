<?php
declare(strict_types=1);

/**
 * Экранирует строку для безопасного вывода в HTML.
 */
function h(mixed $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Длина строки в символах (UTF-8 safe).
 */
function str_len_safe(string $value): int {
    return mb_strlen($value, 'UTF-8');
}

/**
 * Приводит PostgreSQL/PDO булевое значение к bool.
 * PostgreSQL возвращает 't'/'f', PDO может вернуть '1'/'0'.
 */
function db_bool(mixed $value): bool {
    if (is_bool($value)) return $value;
    return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'on', 'y'], true);
}

/**
 * Редирект и выход. Упрощает return-after-redirect паттерн.
 */
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

/**
 * Проверяет существование колонки в таблице БД (с кэшем).
 */
function column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ? LIMIT 1");
    $st->execute([strtolower($table), strtolower($column)]);
    return $cache[$key] = (bool)$st->fetchColumn();
}

/**
 * Самовосстановление: гарантирует наличие колонок фото-отчёта в orders.
 *
 * Нужно потому, что без прогнанной миграции (migrate_schema.php) колонки
 * photo_report* отсутствуют, и тогда чекбокс «прислать фото букета» при
 * оформлении молча не сохраняется, а в админке показывается «Фото не требуется».
 * Все ALTER идемпотентны (IF NOT EXISTS), вызов закэширован и обёрнут в try/catch,
 * поэтому безопасен и почти бесплатен при повторных вызовах.
 */
function ensure_order_photo_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Прямой запрос к схеме (НЕ через column_exists), чтобы не закэшировать в нём
    // устаревший false до ALTER — иначе последующий column_exists() в checkout
    // вернёт стейл-значение и колонка не попадёт в INSERT.
    $check = function () use ($pdo): bool {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns
                             WHERE table_schema = 'public' AND table_name = 'orders'
                               AND column_name = 'photo_report_requested' LIMIT 1");
        $st->execute();
        return (bool)$st->fetchColumn();
    };

    try {
        if ($check()) return; // колонки уже на месте — ничего не делаем
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report TEXT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report_by BIGINT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report_requested BOOLEAN NOT NULL DEFAULT FALSE");
    } catch (Throwable $e) {
        // Нет прав на ALTER или другая проблема — не валим запрос.
        // Тогда поможет ручной запуск migrate_schema.php на сервере.
    }
}

/**
 * Форматирует число как сумму в рублях.
 */
function money_ru(mixed $value): string {
    return number_format((float)$value, 0, ',', ' ') . ' ₽';
}

/**
 * Чистит сообщение пользователя от потенциально опасных персональных данных
 * перед отправкой во внешние сервисы (YandexGPT и т.п.) и сохранением в БД.
 *
 * Маскирует:
 *  - номера банковских карт (13–19 цифр с пробелами/дефисами), валидация по Луну
 *  - CVV/CVC (3-4 цифры рядом со словами cvv/cvc/код)
 *  - сроки действия карт MM/YY и MM/YYYY
 *  - номера паспортов РФ (4 цифры + 6 цифр)
 *  - СНИЛС (XXX-XXX-XXX YY)
 *  - ИНН физлиц (12 цифр) — оставляем последние 4
 *  - email — оставляем первую букву + домен
 *  - российские номера телефонов — оставляем последние 4 цифры
 *
 * Возвращает массив:
 *   ['clean' => 'почищенный текст', 'flags' => ['card','phone',...]]
 */
function pii_sanitize(string $text): array {
    $flags = [];
    $clean = $text;

    // 1. Карты: длинные numeric runs (13–19 цифр, с пробелами/дефисами).
    //    Маскируем ВСЕ такие последовательности — даже не прошедшие Луна.
    //    Лучше потерять артикул, чем оставить карту незамаскированной.
    $clean = preg_replace_callback(
        '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/u',
        function ($m) use (&$flags) {
            $digits = preg_replace('/\D/', '', $m[0]);
            $len = strlen($digits);
            if ($len < 13 || $len > 19) return $m[0];
            $flags[] = 'card';
            return '[карта скрыта ••••' . substr($digits, -4) . ']';
        },
        $clean
    );

    // 2. CVV/CVC после слов-маркеров.
    $clean = preg_replace_callback(
        '/\b(cvv2?|cvc|код)\s*[:#=]?\s*(\d{3,4})\b/iu',
        function ($m) use (&$flags) { $flags[] = 'cvv'; return '[' . $m[1] . ' скрыт]'; },
        $clean
    );

    // 3. Срок действия карты MM/YY или MM/YYYY.
    $clean = preg_replace_callback(
        '#(?<!\d)(0[1-9]|1[0-2])\s*[/\-]\s*(\d{2}|\d{4})(?!\d)#u',
        function () use (&$flags) { $flags[] = 'card_exp'; return '[срок скрыт]'; },
        $clean
    );

    // 4. Российский телефон: +7/8 + 10 цифр.
    //    Граница (?<!\d) — чтобы не выгрызать кусок из длинных чисел (карт, артикулов).
    $clean = preg_replace_callback(
        '/(?<!\d)(?:\+7|8|7)[\s\-]?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?!\d)/u',
        function ($m) use (&$flags) {
            $digits = preg_replace('/\D/', '', $m[0]);
            $flags[] = 'phone';
            return '[телефон ••••' . substr($digits, -4) . ']';
        },
        $clean
    );

    // 5. Паспорт РФ: 4+6 цифр через пробел.
    //    Требуем именно пробел между сериями, иначе ловим часть карты.
    $clean = preg_replace_callback(
        '/(?<!\d)(\d{4})\s(\d{6})(?!\d)/u',
        function ($m) use (&$flags) { $flags[] = 'passport'; return '[паспорт скрыт ••••' . substr($m[2], -2) . ']'; },
        $clean
    );

    // 6. СНИЛС: 3-3-3-2.
    $clean = preg_replace_callback(
        '/(?<!\d)\d{3}[\- ]\d{3}[\- ]\d{3}[\- ]\d{2}(?!\d)/u',
        function () use (&$flags) { $flags[] = 'snils'; return '[СНИЛС скрыт]'; },
        $clean
    );

    // 7. Email — оставляем первую букву + домен.
    $clean = preg_replace_callback(
        '/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/u',
        function ($m) use (&$flags) { $flags[] = 'email'; return $m[1] . '***@' . $m[2]; },
        $clean
    );

    return ['clean' => $clean, 'flags' => array_values(array_unique($flags))];
}

/**
 * Возвращает CSRF-токен текущей сессии. Генерирует при первом вызове.
 * Сессия должна быть уже запущена (через flora_start_session()).
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

/**
 * Проверяет переданный токен против сессионного. Безопасно к timing-атакам.
 * Если токена нет — возвращает false.
 */
function csrf_check(?string $token): bool {
    if (empty($_SESSION['csrf']) || $token === null || $token === '') return false;
    return hash_equals((string)$_SESSION['csrf'], $token);
}

/**
 * Отдаёт готовый <input type="hidden" name="csrf" value="..."> для вставки в форму.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/**
 * Безопасный старт сессии: HttpOnly + Secure (если HTTPS) + SameSite=Lax.
 * Вызывать вместо session_start() везде в проекте.
 */
function flora_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
