<?php
declare(strict_types=1);

// Адресная книга: хранит вместе с адресом детали входа (квартира, подъезд, этаж, домофон),
// чтобы при выборе адреса на оформлении заказа эти поля подставлялись автоматически.

// Добавляет недостающие колонки деталей в user_addresses (выполняется один раз за запрос).
function address_book_ensure_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'apartment' => 'VARCHAR(20)',
        'entrance'  => 'VARCHAR(20)',
        'floor'     => 'VARCHAR(10)',
        'intercom'  => 'VARCHAR(20)',
    ];
    foreach ($columns as $col => $type) {
        try {
            $pdo->exec("ALTER TABLE user_addresses ADD COLUMN IF NOT EXISTS {$col} {$type}");
        } catch (Throwable $e) {
            // колонка уже есть или нет прав — не критично
        }
    }
}

// Обрезает деталь адреса до разумной длины (без жёсткой валидации — она на оформлении заказа).
function address_book_clean(string $value, int $max): string {
    $value = trim(preg_replace('~\s+~u', ' ', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}
