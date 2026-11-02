<?php
// Backup diário do banco em PHP puro (a Hostinger não deixa o PHP da web rodar mysqldump).
// Gera ~/backups_banco/zagora-AAAA-MM-DD.sql.gz (fora do public_html) e apaga os com mais de 14 dias.
// Chamado pelo cron_resumo.php (todo dia às 9h). Pra rodar na mão, pelo terminal: php backup_banco.php
//
// Pra restaurar: descompacta (gunzip zagora-AAAA-MM-DD.sql.gz) e importa o .sql no phpMyAdmin (aba Importar).
// ATENÇÃO: o arquivo começa com DROP TABLE de cada tabela — restaurar SUBSTITUI os dados atuais.

require_once __DIR__ . '/db.php';

function fazer_backup_banco($pasta = null, $manter_dias = 14) {
    $pasta = $pasta ?? dirname(__DIR__, 3) . '/backups_banco'; // public_html -> zagora.space -> domains -> home
    if (!is_dir($pasta) && !mkdir($pasta, 0700, true)) {
        throw new RuntimeException("Não consegui criar a pasta de backup: $pasta");
    }

    $arquivo = $pasta . '/zagora-' . date('Y-m-d') . '.sql.gz';
    if (file_exists($arquivo)) {
        return ['arquivo' => $arquivo, 'pulado' => true]; // já tem o de hoje
    }

    $pdo = get_db();
    $sql = "-- Backup do banco do Zágora em " . date('Y-m-d H:i:s') . "\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $tabelas = 0;
    $linhas = 0;

    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $tabela) {
        $criar = $pdo->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_NUM)[1];
        $sql .= "DROP TABLE IF EXISTS `$tabela`;\n$criar;\n";
        $tabelas++;

        $stmt = $pdo->query("SELECT * FROM `$tabela`");
        $lote = [];
        $colunas = null;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $colunas ??= '`' . implode('`, `', array_keys($row)) . '`';
            $lote[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $row)) . ')';
            $linhas++;
            if (count($lote) === 200) {
                $sql .= "INSERT INTO `$tabela` ($colunas) VALUES\n" . implode(",\n", $lote) . ";\n";
                $lote = [];
            }
        }
        if ($lote) {
            $sql .= "INSERT INTO `$tabela` ($colunas) VALUES\n" . implode(",\n", $lote) . ";\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    // Grava num temporário e renomeia: um backup cortado no meio nunca fica com o nome final
    $temporario = $arquivo . '.tmp';
    if (file_put_contents($temporario, gzencode($sql, 9)) === false || !rename($temporario, $arquivo)) {
        @unlink($temporario);
        throw new RuntimeException("Não consegui gravar o backup em $arquivo");
    }
    chmod($arquivo, 0600);

    // Rotação: apaga os mais antigos que $manter_dias
    $apagados = 0;
    foreach (glob($pasta . '/zagora-*.sql.gz') as $antigo) {
        if (filemtime($antigo) < time() - $manter_dias * 86400) {
            @unlink($antigo) && $apagados++;
        }
    }

    return ['arquivo' => $arquivo, 'bytes' => filesize($arquivo), 'tabelas' => $tabelas, 'linhas' => $linhas, 'apagados' => $apagados, 'pulado' => false];
}

// Rodando direto pelo terminal: faz o backup e mostra o resultado
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    date_default_timezone_set('Europe/Lisbon');
    print_r(fazer_backup_banco($argv[1] ?? null));
}
