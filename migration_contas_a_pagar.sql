-- Rode no phpMyAdmin, aba SQL.
-- Contas a pagar: lembrete pode ter valor/categoria/conta e ser marcado como "conta" (ganha botões Paguei/Amanhã/Não vou pagar).

ALTER TABLE lembretes
    ADD COLUMN IF NOT EXISTS valor DECIMAL(10,2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS moeda VARCHAR(3) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS categoria VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS conta VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS eh_conta TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS origem_id INT NULL DEFAULT NULL,         -- lembrete adiado ("Amanhã") aponta pro original
    ADD COLUMN IF NOT EXISTS ocorrencia_origem DATE NULL DEFAULT NULL; -- ...e pra qual vencimento dele

-- Lembretes que já existem e são claramente pagamentos viram conta
UPDATE lembretes SET eh_conta = 1 WHERE eh_conta = 0 AND (texto LIKE 'pagar%' OR texto LIKE 'pago %');

-- Um registro por vencimento de conta: pago ou pulado ("não vou pagar")
CREATE TABLE IF NOT EXISTS pagamentos_conta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    lembrete_id INT NOT NULL,
    data_ocorrencia DATE NOT NULL,
    status VARCHAR(10) NOT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lembrete_ocorrencia (lembrete_id, data_ocorrencia),
    KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
