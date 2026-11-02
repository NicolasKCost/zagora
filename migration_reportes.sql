-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_reportes.sql).
-- Reportes de erro (/reportar): texto e/ou print, tratados no painel (/admin/).

CREATE TABLE IF NOT EXISTS reportes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    texto TEXT NULL,
    foto_file_id VARCHAR(255) NULL,              -- file_id do Telegram (a imagem continua no Telegram; o painel busca na hora)
    contexto VARCHAR(255) NULL,                  -- plano, modo, região, estado da conversa no momento
    status VARCHAR(12) NOT NULL DEFAULT 'aberto', -- aberto | resolvido
    resposta TEXT NULL,
    criado_em DATETIME NOT NULL,
    resolvido_em DATETIME NULL,
    KEY idx_status (status, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
