<?php
// Roda todos os testes. No servidor, numa cópia do projeto:
//   php testes/rodar.php            (tudo)
//   php testes/rodar.php --rapido   (só as regras sem IA — segundos)
// Sai com código 1 se algo falhar (o deploy.ps1 usa isso pra não subir código quebrado).

require __DIR__ . '/base.php';

$rapido = in_array('--rapido', $argv ?? [], true);
//   php testes/rodar.php --so=reporte  (unidade + só os cenários com "reporte" no nome)
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--so=')) $GLOBALS['testes']['so'] = substr($arg, 5);
}
$inicio = microtime(true);

// Cache das respostas da IA entre rodadas (mesma pergunta + mesmo prompt + mesmo dia = não paga de novo).
// Fica fora da cópia de teste (que é apagada a cada rodada). --sem-cache força perguntar tudo de novo.
if (!in_array('--sem-cache', $argv ?? [], true)) {
    $pasta_cache = getenv('HOME') . '/zagora_cache_ia';
    @mkdir($pasta_cache, 0700, true);
    foreach (glob("$pasta_cache/*.json") ?: [] as $velho) {
        if (filemtime($velho) < strtotime('-3 days')) @unlink($velho);
    }
    define('ZAGORA_CACHE_IA', $pasta_cache);
}

require __DIR__ . '/unidade.php';
if (!$rapido) {
    require __DIR__ . '/fluxos.php';
}

$t = $GLOBALS['testes'];
$total = $t['passou'] + count($t['falhas']);
printf("\n%s %d de %d verificações passaram (%.0fs)%s\n",
    $t['falhas'] ? '❌' : '✅', $t['passou'], $total, microtime(true) - $inicio, $rapido ? ' — modo rápido, sem os fluxos com IA' : '');
if (!$rapido && defined('ZAGORA_CACHE_IA')) {
    $novas = $GLOBALS['ia_cache_chamadas'] ?? 0;
    printf("🤖 IA: %d chamada(s) paga(s), %d reaproveitada(s) do cache (≈ US$ %.2f gastos)\n", $novas, $GLOBALS['ia_cache_acertos'] ?? 0, $novas * 0.0035);
}

if ($t['falhas']) {
    echo "\nFalhas:\n- " . implode("\n- ", $t['falhas']) . "\n";
    exit(1);
}
