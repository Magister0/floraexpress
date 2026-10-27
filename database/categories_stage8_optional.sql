CREATE TABLE IF NOT EXISTS categories (
    id BIGSERIAL PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 100,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO categories (slug, name, sort_order, is_active) VALUES
('roses', 'Розы', 10, TRUE),
('tulips', 'Тюльпаны', 20, TRUE),
('bouquets', 'Букеты', 30, TRUE),
('mini', 'Мини-букеты', 40, TRUE),
('cards', 'Открытки', 50, TRUE),
('balloons', 'Шарики', 60, TRUE),
('other', 'Другое', 999, TRUE)
ON CONFLICT (slug) DO NOTHING;
