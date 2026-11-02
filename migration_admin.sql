-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_admin.sql).
-- Painel administrativo: última atividade de cada usuário (pra "ativos") e links de acesso de uso único.

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS ultima_atividade DATETIME NULL DEFAULT NULL;

-- Link mandado pelo /painel: guarda só o hash do token; vale 10 minutos e uma vez só
CREATE TABLE IF NOT EXISTS admin_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    expira_em DATETIME NOT NULL,
    usado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL,
    UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
