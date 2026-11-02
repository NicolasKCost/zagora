-- Rode no phpMyAdmin, aba SQL.
-- Guarda o dia do mês desejado em lembretes mensais (ex: 31), pra não "escorregar" pra 30 depois de um mês curto.

ALTER TABLE lembretes
    ADD COLUMN IF NOT EXISTS dia_mes TINYINT NULL DEFAULT NULL;

UPDATE lembretes SET dia_mes = DAY(disparar_em)
WHERE recorrente = 'mensal' AND dia_mes IS NULL AND disparar_em IS NOT NULL;
