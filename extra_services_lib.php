<?php

declare(strict_types=1);

function es_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function es_card_svg(string $title, string $subtitle, string $bg1, string $bg2, string $ink = '#23413a'): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="620">'
        . '<defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="' . $bg1 . '"/><stop offset="1" stop-color="' . $bg2 . '"/></linearGradient></defs>'
        . '<rect width="100%" height="100%" rx="42" fill="url(#g)"/>'
        . '<circle cx="170" cy="150" r="90" fill="rgba(255,255,255,0.18)"/>'
        . '<circle cx="700" cy="150" r="80" fill="rgba(255,255,255,0.14)"/>'
        . '<circle cx="450" cy="290" r="150" fill="rgba(255,255,255,0.09)"/>'
        . '<text x="450" y="300" text-anchor="middle" font-size="54" font-family="Georgia, Trebuchet MS, Arial" font-weight="500" fill="' . $ink . '">' . es_h($title) . '</text>'
        . '<text x="450" y="360" text-anchor="middle" font-size="24" font-family="Trebuchet MS, Arial" fill="' . $ink . '" opacity="0.78">' . es_h($subtitle) . '</text>'
        . '</svg>'
    );
}

function extra_services_bootstrap(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS extra_services (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        price NUMERIC(10,2) NOT NULL DEFAULT 0,
        description TEXT NOT NULL DEFAULT '',
        image TEXT NOT NULL DEFAULT '',
        allow_message BOOLEAN NOT NULL DEFAULT FALSE,
        sort_order INTEGER NOT NULL DEFAULT 100,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS image TEXT NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS allow_message BOOLEAN NOT NULL DEFAULT FALSE");
    $pdo->exec("ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS sort_order INTEGER NOT NULL DEFAULT 100");
    $pdo->exec("ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE");
    $pdo->exec("ALTER TABLE extra_services ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_extra_services (
        id SERIAL PRIMARY KEY,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        service_id INTEGER REFERENCES extra_services(id) ON DELETE SET NULL,
        name_snapshot VARCHAR(255) NOT NULL,
        qty INTEGER NOT NULL DEFAULT 1,
        price NUMERIC(10,2) NOT NULL DEFAULT 0,
        message_text TEXT NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $count = (int)$pdo->query("SELECT COUNT(*) FROM extra_services")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $seed = [
        [
            'name' => 'Открытка простая',
            'price' => 250,
            'description' => 'Небольшая открытка, на которой можно оставить ваш текст для получателя.',
            'image' => es_card_svg('Открытка', 'нежное дополнение к букету', '#fce7f3', '#fde68a'),
            'allow_message' => true,
            'sort_order' => 10,
        ],
        [
            'name' => 'Открытка с шоколадкой',
            'price' => 490,
            'description' => 'Открытка с небольшим сладким сюрпризом внутри. Текст тоже можно добавить.',
            'image' => es_card_svg('Открытка + шоколад', 'маленький сладкий комплимент', '#fee2e2', '#fecaca'),
            'allow_message' => true,
            'sort_order' => 20,
        ],
        [
            'name' => 'Шарики',
            'price' => 390,
            'description' => 'Праздничные шары, которые дополняют букет и делают подарок заметнее.',
            'image' => es_card_svg('Шарики', 'праздничный акцент', '#dbeafe', '#bfdbfe'),
            'allow_message' => false,
            'sort_order' => 30,
        ],
        [
            'name' => 'Лента',
            'price' => 150,
            'description' => 'Декоративная лента для более аккуратного и праздничного оформления композиции.',
            'image' => es_card_svg('Лента', 'аккуратная деталь оформления', '#dcfce7', '#bbf7d0'),
            'allow_message' => false,
            'sort_order' => 40,
        ],
        [
            'name' => 'Подарочная упаковка',
            'price' => 350,
            'description' => 'Альтернативная упаковка, если хочется другой стиль подачи букета.',
            'image' => es_card_svg('Упаковка', 'другой стиль подачи', '#ede9fe', '#ddd6fe'),
            'allow_message' => false,
            'sort_order' => 50,
        ],
    ];

    $st = $pdo->prepare("INSERT INTO extra_services (name, price, description, image, allow_message, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, TRUE, CURRENT_TIMESTAMP)");
    foreach ($seed as $row) {
        $st->execute([
            $row['name'],
            $row['price'],
            $row['description'],
            $row['image'],
            $row['allow_message'] ? 1 : 0,
            $row['sort_order'],
        ]);
    }
}

function extra_services_fetch_all(PDO $pdo, bool $activeOnly = false): array {
    $sql = 'SELECT * FROM extra_services';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = TRUE';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function extra_services_fetch_one(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM extra_services WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function extra_services_fetch_selected(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($x) => $x > 0)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM extra_services WHERE is_active = TRUE AND id IN ($in) ORDER BY sort_order ASC, id ASC");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function extra_services_image_src(?string $image, string $fallbackTitle = 'Услуга'): string {
    $image = trim((string)$image);
    if ($image !== '') {
        return $image;
    }
    return es_card_svg($fallbackTitle, 'дополнение к заказу', '#e2f8f0', '#dbeafe');
}
