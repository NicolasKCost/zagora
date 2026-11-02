-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_listas.sql).
-- Listas do usuário (compras, tarefas, filmes...) e os itens de cada uma. "feito" = riscado.

CREATE TABLE IF NOT EXISTS listas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    nome VARCHAR(60) NOT NULL,
    criado_em DATETIME NOT NULL,
    KEY idx_usuario (usuario_id)
);

CREATE TABLE IF NOT EXISTS lista_itens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lista_id INT NOT NULL,
    usuario_id INT NOT NULL,
    texto VARCHAR(120) NOT NULL,
    feito TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL,
    feito_em DATETIME NULL,
    KEY idx_lista (lista_id, feito),
    KEY idx_usuario (usuario_id)
);
