ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS delivery_method text DEFAULT 'delivery';

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS cancel_reason text;

UPDATE orders
SET delivery_method = 'pickup'
WHERE delivery_method IS NULL
  AND (
    address ILIKE 'Самовывоз%'
    OR COALESCE(comment, '') ILIKE '%Способ: Самовывоз%'
  );

UPDATE orders
SET delivery_method = COALESCE(delivery_method, 'delivery');

ALTER TABLE orders
    ALTER COLUMN delivery_method SET DEFAULT 'delivery';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'orders_delivery_method_check'
    ) THEN
        ALTER TABLE orders
            ADD CONSTRAINT orders_delivery_method_check
            CHECK (delivery_method IN ('delivery', 'pickup'));
    END IF;
END$$;
