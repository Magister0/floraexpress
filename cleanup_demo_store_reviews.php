<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
$pdo = db();
$pdo->exec("DELETE FROM store_reviews WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'demo.review.%@flora.local' OR email LIKE 'demo.extra.%@flora.local')");
$pdo->exec("DELETE FROM users WHERE email LIKE 'demo.review.%@flora.local' OR email LIKE 'demo.extra.%@flora.local'");
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Демо-отзывы удалены</title></head><body style="font-family:Arial,sans-serif;padding:40px"><h1>Демо-отзывы удалены</h1><p><a href="index.php#store-reviews">Вернуться на главную</a></p></body></html>
