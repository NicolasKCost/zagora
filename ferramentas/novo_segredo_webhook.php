<?php
// Troca o webhook_secret por um novo (aleatório), grava no config.php e registra no Telegram — sem nunca mostrar
// o segredo nem o token. Rodar no servidor, numa pasta com ferramentas/ e um link pro config.php de produção:
//   php ferramentas/novo_segredo_webhook.php
// Use depois de trocar o token do bot (BotFather /revoke) e atualizar o config.php: o setWebhook já vai com o token novo.

$link = dirname(__DIR__) . '/config.php';
$arquivo = realpath($link); // se for um link, grava no arquivo de verdade (rename por cima do link trocaria só o link)
if (!$arquivo || !is_file($arquivo)) exit("Não achei o config.php.\n");

$conteudo = file_get_contents($arquivo);
$novo = bin2hex(random_bytes(32));
$trocado = preg_replace("/('webhook_secret'\s*=>\s*)(['\"])[^'\"]*\\2/", '${1}\'' . $novo . '\'', $conteudo, 1, $n);
if ($n !== 1) exit("Não achei a linha 'webhook_secret' no config.php — nada foi mudado.\n");

// Grava de forma atômica, só o dono lê
$temporario = $arquivo . '.novo';
if (file_put_contents($temporario, $trocado) === false) exit("Não consegui gravar o config.php — nada foi mudado.\n");
chmod($temporario, 0600);
if (!rename($temporario, $arquivo)) { @unlink($temporario); exit("Não consegui trocar o config.php — nada foi mudado.\n"); }
if (function_exists('opcache_invalidate')) opcache_invalidate($arquivo, true);

$config = require $arquivo;
$ch = curl_init("https://api.telegram.org/bot{$config['telegram_token']}/setWebhook");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['url' => 'https://zagora.space/webhook.php', 'secret_token' => $config['webhook_secret']],
    CURLOPT_TIMEOUT        => 20,
]);
$resposta = json_decode((string) curl_exec($ch), true);
curl_close($ch);

if (!empty($resposta['ok'])) {
    echo "✅ Segredo do webhook trocado e registrado no Telegram.\n";
} else {
    // O config.php já tem o segredo novo; sem o setWebhook o Telegram manda o antigo e o bot recusa (401). Rode de novo.
    echo "❌ O Telegram recusou o setWebhook: " . ($resposta['description'] ?? 'sem resposta') . "\n";
    echo "   O token no config.php está certo? Rode este script de novo depois de conferir.\n";
    exit(1);
}
