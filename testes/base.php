<?php
// Base dos testes do Zágora. Roda NO SERVIDOR, numa cópia do projeto (ferramentas/testar.ps1 faz isso),
// porque precisa do banco e da IA de verdade. Cada cenário usa um usuário fictício (telegram_id negativo)
// dentro de uma transação desfeita no fim — nada fica no banco, e nenhuma mensagem vai pro Telegram (token falso).

date_default_timezone_set('Europe/Lisbon');
require_once dirname(__DIR__) . '/processar.php';
require_once dirname(__DIR__) . '/backup_banco.php';

$GLOBALS['testes'] = ['passou' => 0, 'falhas' => [], 'cenario' => '(sem cenário)'];

function registrar_resultado($ok, $nome, $detalhe = '') {
    $t = &$GLOBALS['testes'];
    if ($ok) {
        $t['passou']++;
        return true;
    }
    $t['falhas'][] = "[{$t['cenario']}] {$nome}" . ($detalhe !== '' ? "\n      {$detalhe}" : '');
    echo "  ❌ {$nome}\n" . ($detalhe !== '' ? "     " . str_replace("\n", "\n     ", $detalhe) . "\n" : '');
    return false;
}

function igual($nome, $obtido, $esperado) {
    return registrar_resultado($obtido === $esperado, $nome, 'obtido: ' . json_encode($obtido, JSON_UNESCAPED_UNICODE) . ' | esperado: ' . json_encode($esperado, JSON_UNESCAPED_UNICODE));
}

function verdadeiro($nome, $condicao, $detalhe = '') {
    return registrar_resultado((bool) $condicao, $nome, $detalhe);
}

// Todos os trechos precisam aparecer (sem diferenciar maiúscula/minúscula)
function contem($nome, $texto, ...$trechos) {
    $faltando = array_filter($trechos, fn($t) => mb_stripos((string) $texto, $t) === false);
    return registrar_resultado(!$faltando, $nome, $faltando ? 'faltou: ' . implode(' | ', $faltando) . "\n      resposta: " . str_replace("\n", ' ⏎ ', (string) $texto) : '');
}

function nao_contem($nome, $texto, ...$trechos) {
    $presentes = array_filter($trechos, fn($t) => mb_stripos((string) $texto, $t) !== false);
    return registrar_resultado(!$presentes, $nome, $presentes ? 'não devia ter: ' . implode(' | ', $presentes) . "\n      resposta: " . str_replace("\n", ' ⏎ ', (string) $texto) : '');
}

// Conversa com o bot pelo MESMO caminho do webhook (processar_mensagem / processar_callback)
class Conversa {
    public $telegram_id;
    public $botoes = [];
    private $config;

    function __construct($telegram_id, $nome = 'Teste') {
        $this->telegram_id = $telegram_id;
        $this->config = require dirname(__DIR__) . '/config.php';
        $this->config['telegram_token'] = 'token-falso-de-teste';
        config_zagora($this->config); // tudo que lê a config (links de pagamento, suporte...) usa o token falso
        get_or_create_user($telegram_id, $nome);
    }

    function usuario() {
        return get_or_create_user($this->telegram_id, 'Teste');
    }

    function msg($texto) {
        botoes_resposta(null, true);
        $resposta = processar_mensagem($this->usuario(), $texto, $this->config);
        $this->botoes = botoes_resposta(null, true) ?? [];
        return (string) $resposta;
    }

    function clicar($callback_data, $texto_original = '') {
        botoes_resposta(null, true);
        $cb = ['id' => 'teste', 'data' => $callback_data, 'from' => ['id' => $this->telegram_id],
               'message' => ['message_id' => 1, 'chat' => ['id' => $this->telegram_id], 'text' => $texto_original]];
        $resposta = processar_callback($this->usuario(), $cb, $this->config);
        $this->botoes = botoes_resposta(null, true) ?? [];
        return (string) $resposta;
    }

    // callback_data dos botões da última resposta (pra conferir se um botão apareceu)
    function dados_botoes() {
        return array_merge(...array_map(fn($linha) => array_column($linha, 1), $this->botoes ?: [[]]));
    }

    function modo($modo) {
        definir_modo_contas($this->usuario()['id'], $modo);
    }

    // Simula o fim do teste grátis (usuário vira Grátis)
    function acabar_teste() {
        get_db()->prepare("UPDATE usuarios SET trial_ate = ?, plus_ate = NULL, plus_tipo = NULL WHERE id = ?")
            ->execute([date('Y-m-d H:i:s', strtotime('-1 day')), $this->usuario()['id']]);
    }

    // Simula o Telegram confirmando um pagamento em Stars (o que chega no webhook como successful_payment)
    function pagar($tipo, $stars, $extra = []) {
        $u = $this->usuario();
        return (string) registrar_pagamento_stars($u, $extra + [
            'currency' => 'XTR', 'total_amount' => $stars,
            'invoice_payload' => "plus|{$tipo}|{$u['id']}|1",
            'telegram_payment_charge_id' => 'teste_' . uniqid(),
            'subscription_expiration_date' => $tipo === 'mensal' ? strtotime('+30 days') : null,
            'is_recurring' => $tipo === 'mensal', 'is_first_recurring' => $tipo === 'mensal',
        ]);
    }
}

// Roda um cenário isolado: usuário fictício novo + transação desfeita no fim (mesmo se der erro)
function cenario($nome, callable $corpo) {
    static $proximo_id = -990000000;
    // --so=palavra roda só os cenários cujo nome contém a palavra (economiza chamadas à IA)
    $so = $GLOBALS['testes']['so'] ?? null;
    if ($so !== null && mb_stripos($nome, $so) === false) {
        return;
    }
    $GLOBALS['testes']['cenario'] = $nome;
    echo "▶ {$nome}\n";
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $corpo(new Conversa($proximo_id--));
    } catch (Throwable $e) {
        registrar_resultado(false, 'erro inesperado', get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        $pdo->rollBack();
        botoes_resposta(null, true);
    }
}
