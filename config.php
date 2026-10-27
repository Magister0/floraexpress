<?php
declare(strict_types=1);

// Если есть локальный файл с настройками — загружаем его.
// config.local.php НЕ должен попадать в git (добавьте в .gitignore).
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Настройки БД: сначала env-переменные, потом константы из config.local.php, потом дефолт.
define('DB_HOST',   getenv('DB_HOST')   ?: (defined('_DB_HOST')   ? _DB_HOST   : '127.0.0.1'));
define('DB_PORT',   getenv('DB_PORT')   ?: (defined('_DB_PORT')   ? _DB_PORT   : '5432'));
define('DB_NAME',   getenv('DB_NAME')   ?: (defined('_DB_NAME')   ? _DB_NAME   : 'floraexpress'));
define('DB_USER',   getenv('DB_USER')   ?: (defined('_DB_USER')   ? _DB_USER   : 'postgres'));
define('DB_PASS',   getenv('DB_PASS')   ?: (defined('_DB_PASS')   ? _DB_PASS   : ''));

// Режим отладки: 1 только локально через env APP_DEBUG=1 или в config.local.php
define('APP_DEBUG', filter_var(
    getenv('APP_DEBUG') ?: (defined('_APP_DEBUG') ? _APP_DEBUG : false),
    FILTER_VALIDATE_BOOLEAN
));

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}
