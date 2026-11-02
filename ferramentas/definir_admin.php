<?php
// Grava no config.php o telegram_id do dono do bot (usuário #1), pra receber o /paysupport e usar /responder e /reembolsar.
// Uso único, no servidor, a partir de uma cópia do projeto: php ferramentas/definir_admin.php
require dirname(__DIR__) . '/db.php';

$arquivo = realpath(dirname(__DIR__) . '/config.php');
$config = require $arquivo;
if (!empty($config['admin_telegram_id'])) {
    exit("admin_telegram_id já está configurado — nada a fazer.\n");
}

$id = get_db()->query("SELECT telegram_id FROM usuarios WHERE id = 1")->fetchColumn();
if (!$id) {
    fwrite(STDERR, "Usuário #1 não encontrado.\n");
    exit(1);
}

$original = file_get_contents($arquivo);
copy($arquivo, getenv('HOME') . '/config.php.bak-' . date('Ymd-His')); // backup fora do public_html
$novo = preg_replace('/\r?\n\];\s*$/', "\n    'admin_telegram_id' => " . (int) $id . ",\n];\n", $original, 1, $n);
if ($n !== 1) {
    fwrite(STDERR, "Não achei o fim do array em config.php — nada alterado\n");
    exit(1);
}
file_put_contents($arquivo, $novo);
if (function_exists('opcache_invalidate')) opcache_invalidate($arquivo, true);
$config = require $arquivo;
if ((string) ($config['admin_telegram_id'] ?? '') !== (string) $id) {
    file_put_contents($arquivo, $original);
    fwrite(STDERR, "config.php não ficou certo — restaurado\n");
    exit(1);
}
echo "admin_telegram_id configurado (usuário #1).\n";
