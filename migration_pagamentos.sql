-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_pagamentos.sql).
-- Plano Plus pago em Telegram Stars: teste de 14 dias, assinatura (mensal renovável ou anual), preço de fundador e região de preço.

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS trial_ate DATETIME NULL DEFAULT NULL,      -- fim do teste grátis do Plus
    ADD COLUMN IF NOT EXISTS plus_ate DATETIME NULL DEFAULT NULL,       -- Plus pago (ou cortesia) válido até
    ADD COLUMN IF NOT EXISTS plus_tipo VARCHAR(12) NULL DEFAULT NULL,   -- mensal | anual | cortesia
    ADD COLUMN IF NOT EXISTS fundador TINYINT(1) NOT NULL DEFAULT 0,    -- garantiu o preço de fundador (pra sempre)
    ADD COLUMN IF NOT EXISTS regiao VARCHAR(2) NULL DEFAULT NULL,       -- BR | PT (define a tabela de preço)
    ADD COLUMN IF NOT EXISTS aviso_plano TINYINT NOT NULL DEFAULT 0;    -- avisos de fim de teste/anual já enviados (0, 1, 2)

-- Todo mundo ganha 14 dias de teste contados de quando entrou
UPDATE usuarios SET trial_ate = DATE_ADD(criado_em, INTERVAL 14 DAY) WHERE trial_ate IS NULL;

-- Quem já usava o bot antes da landing (27/09/2026 19h) fica com Plus de cortesia
UPDATE usuarios SET plus_ate = '2099-12-31 23:59:59', plus_tipo = 'cortesia'
WHERE plus_ate IS NULL AND criado_em < '2026-09-27 19:00:00';

-- Cada pagamento em Stars (primeiro pagamento, renovação automática ou anual)
CREATE TABLE IF NOT EXISTS pagamentos_stars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    telegram_payment_charge_id VARCHAR(255) NOT NULL,
    valor_stars INT NOT NULL,
    tipo VARCHAR(12) NOT NULL,                     -- mensal | anual
    fundador TINYINT(1) NOT NULL DEFAULT 0,
    recorrente TINYINT(1) NOT NULL DEFAULT 0,      -- 1 = renovação automática (não o 1º pagamento)
    expira_em DATETIME NOT NULL,
    reembolsado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL,
    UNIQUE KEY uq_charge (telegram_payment_charge_id),
    KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
