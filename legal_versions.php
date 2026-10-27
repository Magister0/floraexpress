<?php
declare(strict_types=1);

/**
 * Версии юридических документов. При изменении privacy.php или offer.php —
 * увеличиваем дату здесь, и факт согласия пользователей с новой редакцией
 * можно будет аудировать через таблицу users.privacy_consent_version.
 */

if (!defined('PRIVACY_VERSION')) {
    define('PRIVACY_VERSION', '2026-05-23');
}
if (!defined('OFFER_VERSION')) {
    define('OFFER_VERSION', '2026-05-23');
}
