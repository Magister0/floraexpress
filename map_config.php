<?php
// Настройки карты для checkout и адресной книги.
// Чтобы включить Яндекс.Карты, задай API-ключ в config.local.php (_FLORA_YANDEX_MAPS_API_KEY)
// или в переменной окружения FLORA_YANDEX_MAPS_API_KEY.
// Если ключ пустой, карта Яндекса подключается без ключа (с ограничениями API).

require_once __DIR__ . '/config.php';

if (!defined('FLORA_MAP_PROVIDER')) {
    define('FLORA_MAP_PROVIDER', 'yandex');
}

if (!defined('FLORA_YANDEX_MAPS_API_KEY')) {
    define('FLORA_YANDEX_MAPS_API_KEY', getenv('FLORA_YANDEX_MAPS_API_KEY') ?: (defined('_FLORA_YANDEX_MAPS_API_KEY') ? _FLORA_YANDEX_MAPS_API_KEY : ''));
}

// === Магазин FloraExpress: в центре Екатеринбурга, у площади 1905 года ===
// Адрес: проспект Ленина, 24/8 — самый центр, рядом с пл. 1905 г.
if (!defined('FLORA_SHOP_LAT')) {
    define('FLORA_SHOP_LAT', 56.8389);
}
if (!defined('FLORA_SHOP_LON')) {
    define('FLORA_SHOP_LON', 60.6051);
}
if (!defined('FLORA_SHOP_ADDRESS')) {
    define('FLORA_SHOP_ADDRESS', 'г. Екатеринбург, проспект Ленина, 24/8 (у площади 1905 года)');
}

// Координаты по умолчанию для центрирования карты при выборе адреса доставки
if (!defined('FLORA_MAP_DEFAULT_LAT')) {
    define('FLORA_MAP_DEFAULT_LAT', FLORA_SHOP_LAT);
}

if (!defined('FLORA_MAP_DEFAULT_LON')) {
    define('FLORA_MAP_DEFAULT_LON', FLORA_SHOP_LON);
}
