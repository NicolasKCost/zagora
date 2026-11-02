-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_ultimo_intent.sql).
-- Último tipo de pedido do usuário (saldo, query_spending...) — pra "detalha" sozinho saber o que detalhar.

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS ultimo_intent VARCHAR(40) NULL DEFAULT NULL;
