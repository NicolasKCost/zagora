-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_uso_ia.sql).
-- Uso justo do plano Grátis: quantas mensagens do dia precisaram da IA.

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS ia_uso_data DATE NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ia_uso_qtd INT NOT NULL DEFAULT 0;
