-- Rode no phpMyAdmin, aba SQL.
-- Controle de água: cada "bebi" vira um registro em ml. Meta diária e tamanho do copo ficam no usuário (NULL = padrão 2000 / 250).

CREATE TABLE IF NOT EXISTS agua_registros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    ml INT NOT NULL,
    registrado_em DATETIME NOT NULL,
    KEY idx_usuario_data (usuario_id, registrado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS agua_meta_ml INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS agua_copo_ml INT NULL DEFAULT NULL;
