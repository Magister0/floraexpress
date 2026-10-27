-- FloraExpress — базовая схема PostgreSQL (14+).
--
-- Здесь только «ядро»: пользователи, товары, заказы, доставки, адреса.
-- Остальные таблицы (категории, промокоды, зоны доставки, ценовые правила, избранное,
-- отзывы, AI-чаты, журнал аудита, геолокация курьеров и т.д.) создаются автоматически
-- библиотеками *_lib.php при первом обращении (CREATE TABLE IF NOT EXISTS).
--
-- Полная схема всех таблиц — database/floraexpress_schema.dbml и docs/er_diagram.png.
-- После создания схемы можно прогнать: php migrate_schema.php (идемпотентно).

CREATE TABLE IF NOT EXISTS users (
    id                      BIGSERIAL PRIMARY KEY,
    email                   TEXT NOT NULL UNIQUE,
    name                    TEXT NOT NULL,
    pass_hash               TEXT NOT NULL,
    role                    TEXT NOT NULL DEFAULT 'user'
                            CHECK (role IN ('user', 'manager', 'courier', 'admin')),
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    phone                   TEXT NULL,
    telegram_chat_id        BIGINT NULL,
    vk_user_id              BIGINT NULL,
    on_shift                BOOLEAN NOT NULL DEFAULT FALSE,
    shift_started_at        TIMESTAMP NULL,
    reset_token             VARCHAR(64) NULL,
    reset_token_expires     TIMESTAMP NULL,
    privacy_consent_at      TIMESTAMP NULL,
    privacy_consent_version VARCHAR(32) NULL,
    privacy_consent_ip      VARCHAR(64) NULL
);

CREATE TABLE IF NOT EXISTS products (
    id           BIGSERIAL PRIMARY KEY,
    name         TEXT NOT NULL,
    price        NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
    old_price    NUMERIC(12,2) NULL,
    image        TEXT,
    description  TEXT,
    category     TEXT,
    is_active    INTEGER NOT NULL DEFAULT 1,
    stock        INTEGER NULL,
    stem_product BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id                     BIGSERIAL PRIMARY KEY,
    user_id                BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    status                 TEXT NOT NULL DEFAULT 'new',
    address                TEXT,
    phone                  TEXT,
    comment                TEXT,
    total                  NUMERIC(12,2) NOT NULL DEFAULT 0,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipient_name         TEXT,
    delivery_slot          TEXT,
    delivery_method        TEXT DEFAULT 'delivery' CHECK (delivery_method IN ('delivery', 'pickup')),
    cancel_reason          TEXT,
    address_lat            DOUBLE PRECISION NULL,
    address_lon            DOUBLE PRECISION NULL,
    promo_code             TEXT NULL,
    discount_amount        NUMERIC(12,2) NOT NULL DEFAULT 0,
    subtotal_amount        NUMERIC(12,2) NULL,
    delivery_fee           NUMERIC(10,2) NOT NULL DEFAULT 0,
    delivery_calc_meta     TEXT NULL,
    tracking_token         VARCHAR(64) UNIQUE,
    admin_seen_at          TIMESTAMP NULL,
    photo_report           TEXT NULL,
    photo_report_at        TIMESTAMP NULL,
    photo_report_by        BIGINT NULL,
    photo_report_requested BOOLEAN NOT NULL DEFAULT FALSE,
    released_to_courier    BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS order_items (
    id         BIGSERIAL PRIMARY KEY,
    order_id   BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id BIGINT NULL REFERENCES products(id) ON DELETE SET NULL,
    qty        INTEGER NOT NULL DEFAULT 1 CHECK (qty > 0),
    price      NUMERIC(12,2) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS deliveries (
    id              BIGSERIAL PRIMARY KEY,
    order_id        BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    courier_id      BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    status          TEXT NOT NULL DEFAULT 'pending',
    eta             TEXT NULL,
    assigned_at     TIMESTAMP NULL,
    delivered_at    TIMESTAMP NULL,
    courier_fee     NUMERIC(10,2) NULL,
    distance_km_est NUMERIC(8,2) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS delivery_events (
    id          BIGSERIAL PRIMARY KEY,
    delivery_id BIGINT NOT NULL REFERENCES deliveries(id) ON DELETE CASCADE,
    event       TEXT NOT NULL,
    meta_json   TEXT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_addresses (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label      TEXT NULL,
    address    TEXT NOT NULL,
    lat        DOUBLE PRECISION NULL,
    lon        DOUBLE PRECISION NULL,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    apartment  VARCHAR(20) NULL,
    entrance   VARCHAR(20) NULL,
    floor      VARCHAR(10) NULL,
    intercom   VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_orders_user       ON orders (user_id);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items (order_id);
CREATE INDEX IF NOT EXISTS idx_deliveries_order  ON deliveries (order_id);
CREATE INDEX IF NOT EXISTS idx_deliveries_courier ON deliveries (courier_id);
