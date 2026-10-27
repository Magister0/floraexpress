-- Миграция: добавить поддержку VK-бота для курьеров
-- Выполнить на PostgreSQL-сервере

-- Добавить колонку vk_user_id в таблицу users
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS vk_user_id BIGINT DEFAULT NULL;

-- Индекс для быстрого поиска курьера по VK ID
CREATE INDEX IF NOT EXISTS users_vk_user_id_idx
    ON users (vk_user_id)
    WHERE vk_user_id IS NOT NULL;
