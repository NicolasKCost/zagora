<?php

date_default_timezone_set('Europe/Lisbon'); // corrige a hora usada em lembretes, datas etc.

require_once __DIR__ . '/processar.php';

$config = config_zagora();

// Só aceita pedidos que vêm mesmo do Telegram (ele manda o secret_token registrado no setWebhook nesse header)
$secret = $config['webhook_secret'] ?? null;
if ($secret && !hash_equals($secret, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(401);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);

// Cada update uma vez só: se o Telegram reenviar (timeout, erro de rede), não anota o mesmo gasto duas vezes
if (ja_processado_update($update['update_id'] ?? null)) {
    http_response_code(200);
    exit;
}

// Pagamento em Stars, passo 1: o Telegram pergunta "posso cobrar?" — tem que responder em até 10 segundos
if (!empty($update['pre_checkout_query'])) {
    $pcq = $update['pre_checkout_query'];
    $usuario = get_or_create_user($pcq['from']['id'], $pcq['from']['first_name'] ?? '');
    [$ok, $erro] = validar_pre_checkout($usuario, $pcq);
    chamar_telegram($config, 'answerPreCheckoutQuery', array_filter(['pre_checkout_query_id' => $pcq['id'], 'ok' => $ok, 'error_message' => $erro], fn($v) => $v !== null));
    http_response_code(200);
    exit;
}

$de = $update['callback_query']['from'] ?? $update['message']['from'] ?? null;
$telegram_id = $de['id'] ?? null;
if (!$telegram_id) {
    http_response_code(200);
    exit;
}
$usuario = get_or_create_user($telegram_id, $de['first_name'] ?? '');
get_db()->prepare("UPDATE usuarios SET ultima_atividade = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $usuario['id']]); // pro painel ("ativos")

// Tabela de preço pelo idioma do Telegram (o /start site_br|site_pt da landing, quando vem, manda mais)
if (empty($usuario['regiao']) && ($regiao = regiao_por_idioma($de['language_code'] ?? null))) {
    definir_regiao($usuario['id'], $regiao);
    // Recarrega: a moeda do país já vale pra ESTA mensagem (antes o 1º gasto de um brasileiro saía em euro)
    $usuario = get_or_create_user($telegram_id, $de['first_name'] ?? '');
}

if (!empty($update['callback_query'])) {
    // Clique num botão
    chamar_telegram($config, 'answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']]); // tira o "carregando" do botão
    $resposta = processar_callback($usuario, $update['callback_query'], $config);
} elseif (!empty($update['message']['successful_payment'])) {
    // Pagamento em Stars, passo 2: confirmado (1º pagamento, renovação automática ou anual)
    $resposta = registrar_pagamento_stars($usuario, $update['message']['successful_payment']);
} elseif (!empty($update['message']['text'])) {
    $resposta = processar_mensagem($usuario, $update['message']['text'], $config);
} elseif (!empty($update['message']['photo'])) {
    // Print mandado pro /reportar (o bot não interpreta imagens)
    $resposta = processar_foto($usuario, $update['message']);
} elseif (!empty($update['message']) && empty($update['message']['refunded_payment'])) {
    // Áudio, figurinha, arquivo... — responde em vez de ficar calado (aviso de reembolso não precisa de resposta)
    $resposta = processar_nao_texto($update['message']);
} else {
    http_response_code(200);
    exit;
}

if ($resposta) {
    $dados_envio = json_decode(enviar_telegram($config, $telegram_id, $resposta, botoes_resposta(null, true)), true);
    if (!empty($dados_envio['result']['message_id'])) {
        registrar_mensagem_enviada($usuario['id'], $dados_envio['result']['message_id']);
    }
}

http_response_code(200);
