-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_uso_ia_diario.sql).
-- Uso da IA por usuário e por dia: chamadas e tokens (a Cerebras devolve os tokens em cada resposta) — pro painel
-- mostrar o custo real por usuário, por plano e por país.

CREATE TABLE IF NOT EXISTS uso_ia_diario (
    usuario_id INT NOT NULL,
    data DATE NOT NULL,
    chamadas INT NOT NULL DEFAULT 0,
    tokens_entrada INT NOT NULL DEFAULT 0,
    tokens_saida INT NOT NULL DEFAULT 0,
    PRIMARY KEY (usuario_id, data),
    KEY idx_data (data)
);
