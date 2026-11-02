-- Rode no phpMyAdmin, aba SQL.

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS ultimo_resumo_semanal DATE NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ultimo_resumo_mensal DATE NULL DEFAULT NULL;
