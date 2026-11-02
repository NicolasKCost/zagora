-- Rode no phpMyAdmin, aba SQL.
-- Modo de uso: 'contas' (controla por conta/banco, pergunta a conta de cada gasto) ou 'simples' (só anota gastos).
-- NULL = ainda não escolheu (usuário novo — o bot oferece as duas opções na primeira conversa).

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS modo_contas VARCHAR(10) NULL DEFAULT NULL;

-- Quem já usa o bot continua como estava (por conta)
UPDATE usuarios u SET modo_contas = 'contas'
WHERE modo_contas IS NULL
  AND (EXISTS (SELECT 1 FROM contas c WHERE c.usuario_id = u.id)
       OR EXISTS (SELECT 1 FROM transacoes t WHERE t.usuario_id = u.id));
