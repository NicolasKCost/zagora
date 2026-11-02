<?php
// Textos do perfil do bot no Telegram (o que aparece ANTES de a pessoa tocar em "Iniciar"), nos três pilares.
// Rodar no servidor, numa pasta com ferramentas/ e um link pro config.php:  php ferramentas/perfil_bot.php
// Mostra os textos de antes e grava os novos (padrão + português). Não mostra o token.

$config = require dirname(__DIR__) . '/config.php';

$descricao = "Oi! Sou o Zágora 👋 Cuido do seu dinheiro e da sua agenda, só conversando aqui no Telegram.\n\n"
    . "💰 Anoto gastos e receitas, mostro seu saldo, aviso antes das contas vencerem e ajudo a juntar dinheiro pras suas metas.\n"
    . "🗓️ Te lembro dos compromissos — e aviso antes da hora.\n"
    . "📝 Guardo suas listas (compras, tarefas) e te ajudo com a rotina.\n\n"
    . "Sem planilha, sem app novo. Toca em Iniciar e me conta um gasto.";
$curta = "Seu dinheiro e sua agenda organizados numa conversa: gastos, contas, compromissos e listas.";

function tg($config, $metodo, $params = []) {
    $ch = curl_init("https://api.telegram.org/bot{$config['telegram_token']}/{$metodo}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_TIMEOUT => 20]);
    $r = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return $r;
}

printf("Tamanhos: descrição %d/512, curta %d/120\n", mb_strlen($descricao), mb_strlen($curta));
if (mb_strlen($descricao) > 512 || mb_strlen($curta) > 120) exit("Texto grande demais — nada foi mudado.\n");

foreach (['' => 'padrão', 'pt' => 'português'] as $lang => $rotulo) {
    $p = $lang ? ['language_code' => $lang] : [];
    $antes = tg($config, 'getMyShortDescription', $p)['result']['short_description'] ?? '';
    echo "[$rotulo] curta antes: " . ($antes !== '' ? $antes : '(vazia)') . "\n";
    $a = tg($config, 'setMyDescription', $p + ['description' => $descricao]);
    $b = tg($config, 'setMyShortDescription', $p + ['short_description' => $curta]);
    echo "[$rotulo] " . (!empty($a['ok']) && !empty($b['ok']) ? '✅ atualizado' : '❌ ' . ($a['description'] ?? '') . ' ' . ($b['description'] ?? '')) . "\n";
}
