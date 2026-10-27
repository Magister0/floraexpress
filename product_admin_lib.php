<?php
require_once __DIR__ . '/helpers.php';
flora_start_session();

function pa_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pa_lower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function pa_category_options(): array {
    return [
        'bouquets' => 'Букеты',
        'roses'    => 'Розы',
        'tulips'   => 'Тюльпаны',
        'mini'     => 'Мини-букеты',
        'cards'    => 'Открытки',
        'balloons' => 'Шарики',
        'other'    => 'Другое',
    ];
}

function pa_category_label(?string $raw): string {
    $raw = trim((string)$raw);
    $key = pa_lower($raw);
    $map = [
        'bouquets' => 'Букеты', 'bouquet' => 'Букеты', 'букеты' => 'Букеты', 'букет' => 'Букеты',
        'roses' => 'Розы', 'rose' => 'Розы', 'роза' => 'Розы', 'розы' => 'Розы',
        'tulips' => 'Тюльпаны', 'tulip' => 'Тюльпаны', 'тюльпан' => 'Тюльпаны', 'тюльпаны' => 'Тюльпаны',
        'mini' => 'Мини-букеты', 'мини' => 'Мини-букеты',
        'cards' => 'Открытки', 'card' => 'Открытки', 'открытки' => 'Открытки',
        'balloons' => 'Шарики', 'balloon' => 'Шарики', 'шарики' => 'Шарики', 'шары' => 'Шарики',
        'other' => 'Другое',
    ];
    return $map[$key] ?? ($raw !== '' ? $raw : 'Без категории');
}

function pa_placeholder_image(): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800">'
        . '<rect width="100%" height="100%" fill="#f4f6f8"/>'
        . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="28" fill="#9ca3af">FloraExpress</text>'
        . '</svg>'
    );
}

function pa_image_src(?string $path, ?string $fallback = null): string {
    $path = trim((string)$path);
    if ($path === '') return $fallback ?: pa_placeholder_image();
    if (preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, 'data:') || str_starts_with($path, '/')) {
        return $path;
    }
    return ltrim($path, './');
}

function pa_require_staff(string $returnFile = 'products.php'): array {
    $me = $_SESSION['user'] ?? null;
    if (!$me) {
        header('Location: auth.php?mode=login&return=' . urlencode($returnFile));
        exit;
    }
    $role = (string)($me['role'] ?? 'user');
    if (!in_array($role, ['admin', 'manager'], true)) {
        http_response_code(403);
        echo '403';
        exit;
    }
    return $me;
}

function pa_csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : sha1(uniqid((string)mt_rand(), true));
    }
    return (string)$_SESSION['csrf'];
}

function pa_check_csrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals((string)pa_csrf(), (string)$_POST['csrf'])) {
        throw new RuntimeException('Сессия устарела. Обновите страницу.');
    }
}


function pa_substr(string $s, int $start, ?int $length = null): string {
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($s, $start) : substr($s, $start, $length);
}

function pa_strlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function pa_normalize_price($value): float {
    $value = str_replace([' ', ','], ['', '.'], (string)$value);
    return (float)$value;
}

function pa_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ? LIMIT 1");
    $st->execute([strtolower($table), strtolower($column)]);
    return $cache[$key] = (bool)$st->fetchColumn();
}

function pa_ensure_products_discount_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!pa_column_exists($pdo, 'products', 'old_price')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN old_price NUMERIC(12,2) NULL");
    }
    if (!pa_column_exists($pdo, 'products', 'stock')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN stock INTEGER DEFAULT NULL");
    }
}

function pa_valid_old_price($value, float $price): ?float {
    $old = pa_normalize_price($value);
    if ($old <= 0 || $old <= $price) return null;
    return round($old, 2);
}

function pa_price_view(float $price, $oldPrice = null): array {
    $old = $oldPrice !== null ? (float)$oldPrice : 0.0;
    if ($old > $price && $price >= 0) {
        return ['price' => round($price, 2), 'old_price' => round($old, 2), 'has_discount' => true];
    }
    return ['price' => round($price, 2), 'old_price' => null, 'has_discount' => false];
}

function pa_is_local_image(string $path): bool {
    return $path !== ''
        && !preg_match('~^(https?:)?//~i', $path)
        && !str_starts_with($path, 'data:')
        && !str_starts_with($path, '/');
}

function pa_delete_local_image(string $path): void {
    $path = trim($path);
    if ($path === '' || !pa_is_local_image($path)) return;
    $abs = __DIR__ . '/' . ltrim($path, './');
    if (is_file($abs)) @unlink($abs);
}

function pa_ensure_img_dir(): string {
    $dir = __DIR__ . '/img';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function pa_process_image_upload(array $file, string $currentImage = '', bool $removeCurrent = false): string {
    $currentImage = trim($currentImage);

    if ($removeCurrent && $currentImage !== '') {
        pa_delete_local_image($currentImage);
        $currentImage = '';
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentImage;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Ошибка загрузки изображения.');
    }

    $tmp  = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Файл изображения не найден.');
    }
    if ($size > 6 * 1024 * 1024) {
        throw new RuntimeException('Файл слишком большой. Максимум 6 МБ.');
    }

    $mime = null;
    $info = @getimagesize($tmp);
    if ($info && !empty($info['mime'])) {
        $mime = (string)$info['mime'];
    } elseif (function_exists('mime_content_type')) {
        $mime = @mime_content_type($tmp) ?: null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!$mime || !isset($allowed[$mime])) {
        throw new RuntimeException('Разрешены только JPG, PNG, WEBP и GIF.');
    }

    $filename = date('Ymd_His') . '_' . substr(sha1(uniqid((string)mt_rand(), true)), 0, 10) . '.' . $allowed[$mime];
    $dir = pa_ensure_img_dir();
    $dest = $dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Не удалось сохранить изображение.');
    }

    if ($currentImage !== '') {
        pa_delete_local_image($currentImage);
    }

    return 'img/' . $filename;
}

function pa_pick_image_from_request(string $currentImage = ''): string {
    $currentImage = trim($currentImage);
    $removeCurrent = isset($_POST['remove_image']) && (string)$_POST['remove_image'] === '1';
    $imageUrl = trim((string)($_POST['image_url'] ?? ''));

    if (!empty($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        return pa_process_image_upload($_FILES['image_file'], $currentImage, $removeCurrent);
    }

    if ($removeCurrent) {
        pa_delete_local_image($currentImage);
        $currentImage = '';
    }

    if ($imageUrl !== '') {
        if (!(preg_match('~^https?://~i', $imageUrl) || str_starts_with($imageUrl, 'img/') || str_starts_with($imageUrl, '/'))) {
            throw new RuntimeException('Укажите корректную ссылку на изображение или загрузите файл.');
        }
        if ($currentImage !== '' && $imageUrl !== $currentImage) {
            pa_delete_local_image($currentImage);
        }
        return $imageUrl;
    }

    return $currentImage;
}

function pa_build_product_description(string $name, string $category, string $description): string {
    $description = trim($description);
    if ($description !== '') return $description;
    $cat = pa_lower(pa_category_label($category));
    return $name . ' — аккуратно собранный ' . $cat . ' для подарка, сюрприза или красивого повода.';
}
