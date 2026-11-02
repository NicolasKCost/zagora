-- Rode no phpMyAdmin, aba SQL (ou: php ferramentas/migrar.php migration_antecedencia.sql).
-- Compromisso (consulta, reunião...) avisa X minutos ANTES do horário. disparar_em continua sendo o horário do compromisso;
-- o cron dispara em disparar_em - antecedencia_min. NULL/0 = na hora (tarefas: água, remédio, contas).

ALTER TABLE lembretes
    ADD COLUMN IF NOT EXISTS antecedencia_min INT NULL DEFAULT NULL;
