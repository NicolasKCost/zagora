-- Rode no phpMyAdmin, aba SQL.
-- Metas de poupança: a meta (quanto, até quando) e cada vez que guardou/tirou dinheiro dela.
-- Guardar numa meta NÃO é gasto: não entra em transacoes e não mexe no saldo — é só o controle do que foi separado.

CREATE TABLE IF NOT EXISTS metas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    nome VARCHAR(100) NOT NULL,
    valor_alvo DECIMAL(10,2) NOT NULL,
    moeda VARCHAR(3) NOT NULL DEFAULT 'EUR',
    prazo DATE NULL DEFAULT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'ativa',   -- ativa | concluida | cancelada
    criado_em DATETIME NOT NULL,
    KEY idx_usuario_status (usuario_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS metas_aportes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meta_id INT NOT NULL,
    usuario_id INT NOT NULL,
    valor DECIMAL(10,2) NOT NULL,                  -- negativo = tirou dinheiro da meta
    criado_em DATETIME NOT NULL,
    KEY idx_meta (meta_id),
    KEY idx_usuario_data (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
