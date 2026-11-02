-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_origem.sql).
-- De onde o usuário veio: site_br / site_pt (botões da landing), direto (achou o bot no Telegram), etc.

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS origem VARCHAR(30) NULL DEFAULT NULL;
