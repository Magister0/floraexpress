<?php

declare(strict_types=1);

if (!function_exists('category_h')) {
    function category_h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('category_slugify')) {
    function category_slugify(string $value): string {
        $value = trim($value);
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
            'х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
        ];
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $lower = strtr($lower, $map);
        $lower = preg_replace('~[^a-z0-9]+~', '-', $lower) ?? '';
        $lower = trim($lower, '-');
        return $lower !== '' ? $lower : 'other';
    }
}

if (!function_exists('category_humanize_slug')) {
    function category_humanize_slug(string $slug): string {
        $slug = trim($slug);
        if ($slug === '') return 'Другое';
        $map = [
            'roses' => 'Розы',
            'tulips' => 'Тюльпаны',
            'bouquets' => 'Букеты',
            'mini' => 'Мини-букеты',
            'cards' => 'Открытки',
            'balloons' => 'Шарики',
            'other' => 'Другое',
        ];
        if (isset($map[$slug])) return $map[$slug];
        $name = str_replace(['-', '_'], ' ', $slug);
        return function_exists('mb_convert_case')
            ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8')
            : ucwords($name);
    }
}

if (!function_exists('categories_bootstrap')) {
    function categories_bootstrap(PDO $pdo): void {
        static $done = false;
        if ($done) return;

        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
            id BIGSERIAL PRIMARY KEY,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 100,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $defaults = [
            ['roses', 'Розы', 10, true],
            ['tulips', 'Тюльпаны', 20, true],
            ['bouquets', 'Букеты', 30, true],
            ['mini', 'Мини-букеты', 40, true],
            ['cards', 'Открытки', 50, true],
            ['balloons', 'Шарики', 60, true],
            ['other', 'Другое', 999, true],
        ];
        $ins = $pdo->prepare("INSERT INTO categories (slug, name, sort_order, is_active)
                              VALUES (?, ?, ?, ?::boolean)
                              ON CONFLICT (slug) DO UPDATE SET
                                name = EXCLUDED.name,
                                sort_order = EXCLUDED.sort_order");
        foreach ($defaults as [$slug, $name, $sort, $active]) {
            $ins->execute([$slug, $name, $sort, $active ? 'true' : 'false']);
        }

        try {
            $rows = $pdo->query("SELECT DISTINCT TRIM(COALESCE(category, '')) AS category FROM products WHERE TRIM(COALESCE(category, '')) <> ''")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $insUnknown = $pdo->prepare("INSERT INTO categories (slug, name, sort_order, is_active)
                                         VALUES (?, ?, 900, true)
                                         ON CONFLICT (slug) DO NOTHING");
            foreach ($rows as $row) {
                $slug = trim((string)($row['category'] ?? ''));
                if ($slug === '') continue;
                $insUnknown->execute([$slug, category_humanize_slug($slug)]);
            }
        } catch (Throwable $e) {
            // ignore sync errors
        }

        $done = true;
    }
}

if (!function_exists('categories_fetch_all')) {
    function categories_fetch_all(PDO $pdo, bool $activeOnly = false): array {
        categories_bootstrap($pdo);
        $sql = "SELECT id, slug, name, sort_order, is_active FROM categories" . ($activeOnly ? " WHERE is_active = TRUE" : "") . " ORDER BY sort_order ASC, name ASC, id ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('categories_fetch_map')) {
    function categories_fetch_map(PDO $pdo, bool $activeOnly = false): array {
        $map = [];
        foreach (categories_fetch_all($pdo, $activeOnly) as $row) {
            $map[(string)$row['slug']] = $row;
        }
        return $map;
    }
}

if (!function_exists('category_label')) {
    function category_label(PDO $pdo, ?string $slug): string {
        $slug = trim((string)$slug);
        if ($slug === '') return 'Другое';
        $map = categories_fetch_map($pdo, false);
        return $map[$slug]['name'] ?? category_humanize_slug($slug);
    }
}

if (!function_exists('category_save_uploaded_image')) {
    function category_save_uploaded_image(array $file, string $targetDirAbs, string $targetDirRel = 'img'): string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Ошибка загрузки изображения.');
        }
        if (!is_dir($targetDirAbs) && !@mkdir($targetDirAbs, 0777, true) && !is_dir($targetDirAbs)) {
            throw new RuntimeException('Не удалось создать папку для изображений.');
        }
        $tmp = (string)$file['tmp_name'];
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > 6 * 1024 * 1024) {
            throw new RuntimeException('Файл должен быть не больше 6 МБ.');
        }
        $info = @getimagesize($tmp);
        $mime = $info['mime'] ?? (function_exists('mime_content_type') ? @mime_content_type($tmp) : null);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!$mime || !isset($allowed[$mime])) {
            throw new RuntimeException('Допустимы JPG, PNG, WEBP или GIF.');
        }
        $ext = $allowed[$mime];
        $base = date('Ymd_His') . '_' . bin2hex(function_exists('random_bytes') ? random_bytes(4) : random_bytes(4));
        $filename = $base . '.' . $ext;
        $destAbs = rtrim($targetDirAbs, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $destAbs)) {
            throw new RuntimeException('Не удалось сохранить изображение.');
        }
        return trim($targetDirRel, '/\\') . '/' . $filename;
    }
}

if (!function_exists('category_delete_local_image')) {
    function category_delete_local_image(?string $path, string $baseDir): void {
        $path = trim((string)$path);
        if ($path === '' || !preg_match('~^img/[a-zA-Z0-9._\-/]+$~', $path)) return;
        $abs = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (is_file($abs)) @unlink($abs);
    }
}
