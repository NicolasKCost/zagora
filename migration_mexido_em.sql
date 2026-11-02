-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_mexido_em.sql).
-- Quando o lembrete foi criado/alterado pela última vez (horário de Lisboa, gravado em PHP) — pra entender
-- "é 30/10" / "me avisa às 7" como continuação do lembrete de que acabamos de falar.

ALTER TABLE lembretes
    ADD COLUMN IF NOT EXISTS mexido_em DATETIME NULL DEFAULT NULL;
