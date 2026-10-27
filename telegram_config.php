<?php
// Настройки Telegram-бота. Секреты (токен, секрет вебхука, chat_id владельца)
// берутся из переменных окружения или config.local.php — в репозитории их нет.
// Каждая константа защищена индивидуальной проверкой — они могут быть определены
// в map_config.php или других местах, подключённых раньше.

require_once __DIR__ . '/config.php';

if (!defined('FLORA_BOT_TOKEN')) {
    define('FLORA_BOT_TOKEN', getenv('FLORA_BOT_TOKEN') ?: (defined('_FLORA_BOT_TOKEN') ? _FLORA_BOT_TOKEN : ''));
}
if (!defined('FLORA_WEBHOOK_SECRET')) {
    define('FLORA_WEBHOOK_SECRET', getenv('FLORA_WEBHOOK_SECRET') ?: (defined('_FLORA_WEBHOOK_SECRET') ? _FLORA_WEBHOOK_SECRET : ''));
}
if (!defined('FLORA_BOT_NAME')) {
    define('FLORA_BOT_NAME', 'FloraExpress Delivery Bot');
}
if (!defined('FLORA_BOT_SSL_VERIFY')) {
    define('FLORA_BOT_SSL_VERIFY', false);
}

/*
 * Координаты магазина / точки старта курьера.
 * Дублируются в map_config.php — используем то, что уже определено.
 */
if (!defined('FLORA_SHOP_LAT')) {
    define('FLORA_SHOP_LAT', 56.8389);
}
if (!defined('FLORA_SHOP_LON')) {
    define('FLORA_SHOP_LON', 60.6057);
}

/*
 * Сколько получает курьер за одну доставку по умолчанию
 */
if (!defined('FLORA_DEFAULT_COURIER_FEE')) {
    define('FLORA_DEFAULT_COURIER_FEE', 300.00);
}

/*
 * Chat ID владельца для уведомлений о новых заказах.
 * Узнать свой chat_id: напишите боту @userinfobot в Telegram.
 */
if (!defined('FLORA_OWNER_CHAT_ID')) {
    define('FLORA_OWNER_CHAT_ID', getenv('FLORA_OWNER_CHAT_ID') ?: (defined('_FLORA_OWNER_CHAT_ID') ? _FLORA_OWNER_CHAT_ID : ''));
}
