-- Rode no phpMyAdmin, aba SQL.
-- Orçamento mensal por categoria. categoria = '*' é o orçamento TOTAL (todos os gastos do mês).

CREATE TABLE IF NOT EXISTS orcamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    categoria VARCHAR(100) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    moeda VARCHAR(3) NOT NULL DEFAULT 'EUR',
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario_categoria_moeda (usuario_id, categoria, moeda)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
