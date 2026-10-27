<?php
declare(strict_types=1);

/**
 * Идемпотентная миграция схемы Postgres: добавляет недостающие колонки.
 * Запускать после деплоя:
 *   php migrate_schema.php
 *
 * Доступ через web запрещён в .htaccess. Все ALTER-ы используют IF NOT EXISTS,
 * поэтому повторный запуск безопасен.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Доступ только из CLI.');
}

require_once __DIR__ . '/bootstrap.php';
$pdo = db();

$alters = [
    // users
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS phone TEXT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires TIMESTAMP DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS privacy_consent_at TIMESTAMP NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS privacy_consent_version VARCHAR(32) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS privacy_consent_ip VARCHAR(64) NULL",

    // orders
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS admin_seen_at TIMESTAMP NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report TEXT NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report_at TIMESTAMP NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report_by BIGINT NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS photo_report_requested BOOLEAN NOT NULL DEFAULT FALSE",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_token VARCHAR(64) UNIQUE",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_fee NUMERIC(10,2) NOT NULL DEFAULT 0",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_calc_meta TEXT NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS promo_code TEXT NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS subtotal_amount NUMERIC(12,2) NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS released_to_courier BOOLEAN NOT NULL DEFAULT FALSE",

    // products
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS stock INTEGER DEFAULT NULL",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS old_price NUMERIC(12,2) NULL",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS stem_product BOOLEAN NOT NULL DEFAULT FALSE",

    // extra_services
    "ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS image TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS allow_message BOOLEAN NOT NULL DEFAULT FALSE",
    "ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS sort_order INTEGER NOT NULL DEFAULT 100",
    "ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE",
    "ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",

    // promocodes
    "ALTER TABLE promocodes ADD COLUMN IF NOT EXISTS single_use_per_user BOOLEAN NOT NULL DEFAULT FALSE",
];

$ok = 0;
$skip = 0;
$fail = 0;
foreach ($alters as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK   $sql\n";
        $ok++;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Если таблицы ещё нет — это не критично (например, promocodes/extra_services могут отсутствовать).
        if (stripos($msg, 'does not exist') !== false || stripos($msg, 'relation') !== false) {
            echo "SKIP $sql -- " . trim(explode("\n", $msg)[0]) . "\n";
            $skip++;
        } else {
            echo "FAIL $sql -- " . trim(explode("\n", $msg)[0]) . "\n";
            $fail++;
        }
    }
}

echo "\n--- Готово: $ok успешно, $skip пропущено, $fail ошибок ---\n";
exit($fail > 0 ? 1 : 0);
