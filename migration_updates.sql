-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_updates.sql).
-- Cada update do Telegram (update_id) é processado uma vez só: se o Telegram reenviar a mesma mensagem
-- (timeout, erro de rede), o webhook ignora a repetição em vez de anotar o gasto duas vezes.
-- O cron diário apaga os registros com mais de 7 dias.

CREATE TABLE IF NOT EXISTS updates_processados (
    update_id BIGINT NOT NULL PRIMARY KEY,
    criado_em DATETIME NOT NULL,
    KEY idx_criado (criado_em)
);
