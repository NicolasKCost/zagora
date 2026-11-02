<?php
// Roda um migration_*.sql no banco de produção. No servidor, a partir de uma cópia do projeto com o config.php:
//   php ferramentas/migrar.php migration_metas.sql
// Os migrations são escritos pra poder rodar de novo sem estragar nada (CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS...).

require dirname(__DIR__) . '/db.php';

$arquivo = $argv[1] ?? null;
if (!$arquivo || !is_file($caminho = dirname(__DIR__) . '/' . basename($arquivo))) {
    fwrite(STDERR, "Uso: php ferramentas/migrar.php migration_xxx.sql\n");
    exit(1);
}

$sql = preg_replace('/--.*$/m', '', file_get_contents($caminho));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $comando) {
    $n = get_db()->exec($comando);
    printf("ok (%d linha%s): %s\n", $n, $n === 1 ? '' : 's', mb_strimwidth(preg_replace('/\s+/', ' ', $comando), 0, 80, '…'));
}
echo "Migration " . basename($caminho) . " aplicada.\n";
