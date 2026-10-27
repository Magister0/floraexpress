<?php
/*
|--------------------------------------------------------------------------
| VK Bot — конфигурация
|--------------------------------------------------------------------------
|
| 1. Создайте сообщество ВКонтакте (или используйте существующее).
| 2. Управление → Работа с API → Ключи доступа → Создать ключ.
|    Нужны права: Сообщения сообщества.
|    Вставьте токен в FLORA_VK_TOKEN.
| 3. Управление → Настройки → Работа с API → Callback API:
|    - Укажите URL вебхука: https://your-domain.ru/vk_bot.php
|    - Версия API: 5.199
|    - Скопируйте строку подтверждения → FLORA_VK_CONFIRMATION
|    - Задайте секретный ключ → FLORA_VK_SECRET
|    - Включите события: Входящие сообщения, Message event
| 4. ID сообщества — число в URL страницы сообщества (без минуса).
|
*/

require_once __DIR__ . '/config.php';

// Секреты берутся из переменных окружения или config.local.php (см. config.local.example.php).
$floraVkEnv = static function (string $name, string $default = ''): string {
    $v = getenv($name);
    if ($v !== false && $v !== '') return $v;
    return defined('_' . $name) ? (string)constant('_' . $name) : $default;
};

// Токен сообщества ВКонтакте
define('FLORA_VK_TOKEN', $floraVkEnv('FLORA_VK_TOKEN'));

// Числовой ID сообщества (без минуса)
define('FLORA_VK_GROUP_ID', $floraVkEnv('FLORA_VK_GROUP_ID'));

// Строка подтверждения из настроек Callback API
define('FLORA_VK_CONFIRMATION', $floraVkEnv('FLORA_VK_CONFIRMATION'));

// Секретный ключ из настроек Callback API (оставьте пустым, если не задан)
define('FLORA_VK_SECRET', $floraVkEnv('FLORA_VK_SECRET'));

// Версия VK API
const FLORA_VK_API_VERSION = '5.199';

// Проверять SSL-сертификаты (false на хостинге с самоподписанным сертификатом)
const FLORA_VK_SSL_VERIFY = false;
