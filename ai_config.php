<?php
declare(strict_types=1);

/**
 * Настройки AI-консультанта (YandexGPT).
 *
 * Получение ключа:
 *  1. https://console.cloud.yandex.ru → Регистрация
 *  2. Создать каталог → Сервисный аккаунт → роль ai.languageModels.user
 *  3. Создать API-ключ для SA — это YANDEX_GPT_API_KEY
 *  4. folder_id берём из адресной строки каталога
 *
 * Чтобы не светить ключ в репозитории, реальные значения положить в config.local.php:
 *   define('_YANDEX_GPT_API_KEY', 'AQVN...');
 *   define('_YANDEX_GPT_FOLDER_ID', 'b1g...');
 */

if (!defined('YANDEX_GPT_API_KEY')) {
    define('YANDEX_GPT_API_KEY', getenv('YANDEX_GPT_API_KEY')
        ?: (defined('_YANDEX_GPT_API_KEY') ? _YANDEX_GPT_API_KEY : ''));
}

if (!defined('YANDEX_GPT_FOLDER_ID')) {
    define('YANDEX_GPT_FOLDER_ID', getenv('YANDEX_GPT_FOLDER_ID')
        ?: (defined('_YANDEX_GPT_FOLDER_ID') ? _YANDEX_GPT_FOLDER_ID : ''));
}

if (!defined('YANDEX_GPT_MODEL')) {
    // Варианты: yandexgpt-lite/latest (бесплатно), yandexgpt/latest (полная), yandexgpt/rc
    define('YANDEX_GPT_MODEL', getenv('YANDEX_GPT_MODEL')
        ?: (defined('_YANDEX_GPT_MODEL') ? _YANDEX_GPT_MODEL : 'yandexgpt-lite/latest'));
}

if (!defined('YANDEX_GPT_API_URL')) {
    define('YANDEX_GPT_API_URL', 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion');
}

if (!defined('AI_ADVISOR_ENABLED')) {
    define('AI_ADVISOR_ENABLED', true);
}

/** Доступен ли реальный YandexGPT (есть ключ и folder_id, и это не плейсхолдер). */
function ai_advisor_yandex_available(): bool {
    $key = YANDEX_GPT_API_KEY;
    $folder = YANDEX_GPT_FOLDER_ID;
    if ($key === '' || $folder === '') return false;
    // Незаполненные плейсхолдеры из config.local.php — считаем «не настроено»,
    // иначе бот делает 2 обречённых запроса к API и только потом уходит в fallback.
    if (stripos($key, 'ВСТАВЬ') !== false || stripos($folder, 'ВСТАВЬ') !== false) return false;
    return true;
}
