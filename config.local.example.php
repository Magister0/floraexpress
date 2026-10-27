<?php
// Шаблон локальных настроек. Скопируйте в config.local.php и заполните.
// config.local.php в .gitignore — реальные пароли и токены в репозиторий не попадают.
// Любую настройку можно задать и переменной окружения без подчёркивания (DB_PASS, FLORA_BOT_TOKEN и т.д.).

// --- PostgreSQL ---
define('_DB_HOST', '127.0.0.1');
define('_DB_PORT', '5432');
define('_DB_NAME', 'floraexpress');
define('_DB_USER', 'postgres');
define('_DB_PASS', 'change-me');

// Показывать ошибки PHP (только для локальной разработки!)
define('_APP_DEBUG', true);

// --- AI-консультант (YandexGPT). Если пусто — работает на правилах (fallback) ---
define('_YANDEX_GPT_API_KEY', '');
define('_YANDEX_GPT_FOLDER_ID', '');
define('_YANDEX_GPT_MODEL', 'yandexgpt-lite/latest');

// --- Яндекс.Карты. Если пусто — используется карта OSM/Leaflet ---
define('_FLORA_YANDEX_MAPS_API_KEY', '');

// --- Telegram-бот курьеров и уведомлений ---
define('_FLORA_BOT_TOKEN', '');        // токен от @BotFather
define('_FLORA_WEBHOOK_SECRET', '');   // произвольная длинная строка
define('_FLORA_OWNER_CHAT_ID', '');    // chat_id владельца для уведомлений о заказах

// --- VK-бот (Callback API сообщества) ---
define('_FLORA_VK_TOKEN', '');
define('_FLORA_VK_GROUP_ID', '');
define('_FLORA_VK_CONFIRMATION', '');
define('_FLORA_VK_SECRET', '');

// --- SMTP для писем (сброс пароля) ---
define('_SMTP_USER', '');
define('_SMTP_PASS', '');
define('_SMTP_HELO_DOMAIN', 'localhost');
