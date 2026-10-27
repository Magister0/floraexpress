CREATE TABLE IF NOT EXISTS promocodes (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL DEFAULT '',
    discount_type TEXT NOT NULL DEFAULT 'percent',
    discount_value NUMERIC(12,2) NOT NULL DEFAULT 0,
    min_order_sum NUMERIC(12,2) NOT NULL DEFAULT 0,
    max_discount NUMERIC(12,2) NULL,
    delivery_method TEXT NOT NULL DEFAULT 'any',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    usage_limit INTEGER NULL,
    used_count INTEGER NOT NULL DEFAULT 0,
    starts_at TIMESTAMP NULL,
    ends_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_promocodes_active ON promocodes(active);
CREATE INDEX IF NOT EXISTS idx_promocodes_delivery_method ON promocodes(delivery_method);

ALTER TABLE orders ADD COLUMN IF NOT EXISTS promo_code TEXT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS subtotal_amount NUMERIC(12,2) NULL;
