<?php
// Toda a lógica de "chegou um texto do usuário -> qual a resposta". Usada pelo webhook (mensagem digitada
// ou botão que "digita" por ele) e pelos testes. Botões da resposta ficam em botoes_resposta().

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/classifier.php';
require_once __DIR__ . '/handlers.php';

function processar_mensagem($usuario, $mensagem, $config) {
    usuario_atual($usuario['id']); // o uso da IA desta mensagem conta pra este usuário
    $recarregar = fn() => get_or_create_user($usuario['telegram_id'], $usuario['nome']);

    // "Apago X? (sim/não)" pendente: resolve antes de tudo (senão "beleza"/"perfeito" viraria só um 👍 e nada seria apagado)
    $aviso_nao_apagou = '';
    if (($usuario['estado'] ?? null) === 'aguardando_confirmacao_apagar') {
        $resposta = resolver_confirmacao_apagar($usuario, $mensagem);
        if ($resposta !== null) {
            return $resposta;
        }
        // Não respondeu sim/não — é um pedido novo; desiste de apagar e segue o fluxo normal
        $aviso_nao_apagou = obter_lembrete_pendente($usuario);
        limpar_estado($usuario['id']);
        $usuario = $recarregar();
    }

    $resposta = responder_texto($usuario, $mensagem, $config, $recarregar);

    return $aviso_nao_apagou ? $resposta . "\n\n" . $aviso_nao_apagou : $resposta;
}

function responder_texto($usuario, $mensagem, $config, $recarregar) {
    $comando = trim($mensagem);

    // "/start site_br" = veio por um botão da landing (o que vem depois do /start diz de onde — e qual tabela de preço)
    if ($comando === '/start' || str_starts_with($comando, '/start ')) {
        $origem = trim(substr($comando, 6));
        if ($origem === 'site_br' || $origem === 'site_pt') {
            definir_regiao($usuario['id'], strtoupper(substr($origem, 5)));
        }
        // De onde veio (só a primeira vez): o que vem depois do /start, ou "direto" se achou o bot no Telegram
        if (empty($usuario['origem'])) {
            $origem_limpa = preg_replace('/[^a-z0-9_]/', '', strtolower($origem)) ?: 'direto';
            get_db()->prepare("UPDATE usuarios SET origem = ? WHERE id = ?")->execute([substr($origem_limpa, 0, 30), $usuario['id']]);
        }
        return handle_greeting($usuario);
    }
    if ($comando === '/plano' || $comando === '/assinar') {
        return handle_plano($usuario);
    }
    if ($comando === '/paysupport') {
        return handle_paysupport($usuario);
    }
    if ($comando === '/termos') {
        return "📄 Termos de uso: https://zagora.space/termos/\n🔒 Privacidade: https://zagora.space/privacidade/";
    }
    if ($comando === '/painel' && eh_admin($usuario)) {
        return gerar_link_painel();
    }
    if (str_starts_with($comando, '/responder') || str_starts_with($comando, '/reembolsar')) {
        $resposta = handle_comando_admin($usuario, $comando);
        if ($resposta !== null) return $resposta;
    }
    if (($usuario['estado'] ?? null) === 'aguardando_suporte') {
        return encaminhar_suporte($usuario, $mensagem);
    }
    // /reportar (ou /bug, /erro) — com ou sem texto junto
    if (preg_match('/^\/(reportar|bug|erro)(?:@\w+)?\b\s*(.*)$/isu', $comando, $m)) {
        return handle_reportar($usuario, $m[2]);
    }
    if (($usuario['estado'] ?? null) === 'aguardando_reporte') {
        return salvar_reporte($usuario, $mensagem, null);
    }
    if (($usuario['estado'] ?? null) === 'aguardando_reporte_descricao') {
        return completar_reporte($usuario, $mensagem);
    }
    if ($comando === '/reset') {
        return handle_reset($usuario);
    }
    if ($comando === '/ajuda' || $comando === '/help') {
        return handle_ajuda($usuario);
    }
    if ($comando === '/limpar') {
        foreach (listar_mensagens_enviadas($usuario['id']) as $message_id) {
            chamar_telegram($config, 'deleteMessage', ['chat_id' => $usuario['telegram_id'], 'message_id' => $message_id]);
        }
        limpar_mensagens_enviadas($usuario['id']);
        return "Limpei tudo que eu tinha mandado! 🧹 As suas mensagens eu não tenho permissão de apagar — isso só dá pra fazer na mão (segura a mensagem → Apagar, ou \"Limpar histórico\" no menu da conversa).";
    }
    if (stripos($comando, '/moeda') === 0) {
        return handle_set_moeda($usuario, trim(substr($comando, strlen('/moeda'))));
    }
    // O bot perguntou o prazo de uma meta ("15 dias", "dezembro") — se não parece prazo, segue a vida normal
    if (($usuario['estado'] ?? null) === 'aguardando_prazo_meta') {
        $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];
        limpar_estado($usuario['id']);
        $usuario['estado'] = null;
        if ($prazo = interpretar_prazo($mensagem)) {
            return responder_prazo_meta($usuario, $s['meta_id'] ?? 0, $prazo, $s['frequencia'] ?? null);
        }
        // Resposta curta com número que não deu pra entender ("15", "15 xx"): pergunta de novo em vez de virar gasto
        if (preg_match('/\d/', $mensagem) && str_word_count(sem_acentos($mensagem)) <= 2 && !preg_match('/gast|pagu|compr|receb/iu', $mensagem)) {
            salvar_estado($usuario['id'], 'aguardando_prazo_meta', $s);
            return "Não entendi o prazo 🤔 São dias, semanas ou meses? (ex: \"15 dias\", \"2 meses\", \"dezembro\")";
        }
    }
    // "Quanto foi o gasto?" (do seguro) -> "65 cadastra como gasto fixo": anota o gasto E cadastra o fixo, com o que já se sabe
    if (($usuario['estado'] ?? null) === 'aguardando_valor_gasto'
        && preg_match('/^\s*(?:foi\s+|foram\s+)?(\d+(?:[.,]\d+)?)\s*(?:€|euros?|reais)?\s*[,.;]?\s*(?:e\s+)?(?:pode\s+|j[aá]\s+)?(?:cadastra|cadastrar|cadastre|registra|registre|coloca|p[oõ]e|marca|salva)\b.*\b(?:fix[oa]|todo\s+m[eê]s|mensal)/iu', $mensagem, $m)) {
        $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];
        $nome = trim($s['descricao'] ?? '') ?: trim($s['estabelecimento'] ?? '');
        if ($nome !== '') {
            $dia = preg_match('/\bdia\s+(\d{1,2})\b/iu', $mensagem, $d) ? (int) $d[1] : (int) date('j', strtotime($s['data'] ?? 'today'));
            $fixo = exige_plus($usuario, 'Os gastos fixos') ?? handle_cadastrar_conta_fixa($usuario,
                ['descricao' => $nome, 'valor' => (float) str_replace(',', '.', $m[1]), 'dia_mes' => $dia, 'moeda' => $s['moeda'] ?? null, 'categoria' => $s['categoria'] ?? null]);
            $botoes_fixo = botoes_resposta(null, true); // os botões que valem são os do gasto (pergunta da conta), se houver
            $gasto = handle_continuacao_estado($usuario, $m[1]);
            if (botoes_resposta() === null && $botoes_fixo) botoes_resposta($botoes_fixo);
            return $fixo . "\n\n" . $gasto; // a pergunta da conta (se houver) fica por último
        }
    }
    // Estava no meio de uma pergunta e disse "cancela"/"esquece": larga tudo, sem anotar nada
    if (!empty($usuario['estado']) && preg_match('/^\s*(cancela|cancelar|cancele|esquece|esquecer|esqueça|deixa\s+(?:pra\s+l[aá]|quieto)|n[aã]o\s+era\s+isso|errei|desisto)\s*[.!]*\s*$/iu', $mensagem)) {
        limpar_estado($usuario['id']);
        return "👍 Cancelado — não anotei nada.";
    }
    // "Qual o nome da lista?" / "O que vai nela?" — a resposta pode ser longa ("tomar banho, lavar roupas e ...")
    if ($resposta = continuar_criacao_lista($usuario, $mensagem)) {
        return $resposta;
    }
    if (in_array($usuario['estado'] ?? null, ['aguardando_nome_lista', 'aguardando_itens_lista'], true)) {
        $usuario['estado'] = null; // era um pedido novo: a pergunta da lista ficou pra trás
    }
    if (($usuario['estado'] ?? null) === 'aguardando_texto_lembrete') {
        $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];
        limpar_estado($usuario['id']);
        $usuario['estado'] = null;
        if (parece_resposta_direta($mensagem)) {
            return handle_set_reminder($usuario, ['texto_lembrete' => trim($mensagem, " .!")] + $s);
        }
    }
    // "me lembra 30 minutos antes" / "avisa a consulta 1 dia antes": muda a antecedência de um lembrete que já existe
    if ($resposta = ajustar_antecedencia_pelo_texto($usuario, $mensagem) ?? avisar_as_pelo_texto($usuario, $mensagem)
        ?? resposta_recorrencia_conta($usuario, $mensagem)) {
        return $resposta;
    }
    // "pronto", "só foram estas as despesas" = terminou de lançar (antes de tudo: senão "pronto" vira categoria de um gasto solto)
    if (parece_finalizacao($mensagem) && in_array($usuario['estado'] ?? null, [null, 'aguardando_valor_lembrete', 'aguardando_conta_varios'], true)) {
        limpar_estado($usuario['id']);
        return handle_finalizacao($usuario);
    }
    if (parece_confirmacao($mensagem) && !in_array($usuario['estado'] ?? null, ['aguardando_qual_categoria_corrigir', 'aguardando_nome_categoria_nova'], true)) {
        // (quando o bot perguntou o NOME de uma categoria, "perfeito"/"top" pode ser a própria resposta)
        return handle_confirmacao($usuario);
    }

    $tem_estado        = !empty($usuario['estado']);
    $tem_categoria     = !empty($usuario['aguardando_transacao_id']);
    $gasto_pendente_id = (!$tem_estado && !$tem_categoria) ? buscar_gasto_pendente_recente($usuario['id'], $mensagem) : null;
    $tem_pendencia     = $tem_estado || $tem_categoria || $gasto_pendente_id;

    if ($tem_pendencia && parece_resposta_direta($mensagem)) {
        // Resposta curta a uma pergunta pendente (ou a um gasto solto recente) — resolve direto, sem gastar chamada de IA
        if ($tem_estado) {
            return handle_continuacao_estado($usuario, $mensagem);
        }
        if ($tem_categoria) {
            return handle_resposta_categoria($usuario, $mensagem);
        }
        return categorizar_gasto_pendente($usuario['id'], $gasto_pendente_id, $mensagem);
    }

    // Pedido comum exato ("saldo", "meus lembretes"...) não gasta IA; o resto conta no uso justo do Grátis
    $dados = atalho_sem_ia($mensagem) ?? atalho_lista($usuario, $mensagem) ?? atalho_detalha($usuario, $mensagem) ?? atalho_levantamento($mensagem)
        ?? atalho_correcao_lembrete($usuario, $mensagem);
    if ($dados === null) {
        if ($limite = limite_uso_ia($usuario)) {
            return $limite;
        }
        $dados = classificar_intencao($mensagem, $usuario['ultima_conta'] ?? null);
    }
    $intent = $dados['intent'] ?? 'unknown';

    // Tempo relativo ("daqui X minutos") e dia da semana ("sexta") são calculados em PHP — a IA erra essas contas
    [$intent, $dados] = corrigir_compromisso($intent, $dados, $mensagem);
    [$intent, $dados] = corrigir_saldo_inicial($intent, $dados, $mensagem);
    [$intent, $dados] = corrigir_aporte_meta($intent, $dados, $mensagem);
    $dados = corrigir_datas($intent, $dados, $mensagem);
    [$intent, $dados] = corrigir_edicao_lembrete($usuario['id'], $intent, $dados, $mensagem);
    [$intent, $dados] = corrigir_cancelar_lembrete($usuario['id'], $intent, $dados, $mensagem);
    $dados = corrigir_palavras_pela_mensagem($dados, $mensagem);

    // Se a IA mandou uma data que não existe no calendário (tipo 31/09), não confia nela
    $aviso_data = null;
    if (!empty($dados['data']) && validar_data_ou_invalida($dados['data']) === false) {
        $aviso_data = $dados['data'];
        unset($dados['data']);
    }

    if ($intent === 'unknown' && $tem_estado) {
        return handle_continuacao_estado($usuario, $mensagem);
    }
    if ($intent === 'unknown' && $tem_categoria) {
        return handle_resposta_categoria($usuario, $mensagem);
    }

    // Guarda o tipo do pedido — "detalha" sozinho, na próxima mensagem, sabe o que detalhar
    if ($intent !== 'unknown' && $intent !== ($usuario['ultimo_intent'] ?? null)) {
        get_db()->prepare("UPDATE usuarios SET ultimo_intent = ? WHERE id = ?")->execute([$intent, $usuario['id']]);
    }

    // Não força a limpeza da pendência aqui — deixa como está, e confere depois se ela sobreviveu ou não.
    $estado_antes    = $usuario['estado'] ?? null;
    $categoria_antes = $usuario['aguardando_transacao_id'] ?? null;

    $resposta = match ($intent) {
        'greeting'            => handle_greeting($usuario),
        'ajuda'               => handle_ajuda($usuario),
        'definir_modo_contas' => handle_definir_modo($usuario, $dados['modo'] ?? MODO_CONTAS),
        'confirmacao'         => handle_confirmacao($usuario),
        'log_expense'         => handle_log_expense($usuario, $dados, $mensagem),
        'apagar_conta'        => handle_apagar_conta($usuario, $dados),
        'log_income'          => handle_log_income($usuario, $dados),
        'query_spending'      => handle_query_spending($usuario, $dados),
        'list_expenses'       => handle_list_expenses($usuario, $dados),
        'list_income'         => handle_list_income($usuario, $dados),
        'levantamento'        => handle_levantamento($usuario, $dados),
        'lista_criar'         => handle_lista_criar($usuario, $dados),
        'lista_adicionar'     => handle_lista_adicionar($usuario, $dados),
        'lista_ver'           => handle_lista_ver($usuario, $dados),
        'lista_marcar'        => handle_lista_marcar($usuario, $dados),
        'lista_remover'       => handle_lista_marcar($usuario, $dados, true),
        'lista_limpar'        => handle_lista_limpar($usuario, $dados),
        'saldo'               => handle_saldo($usuario, $dados),
        'saldo_por_conta'     => handle_saldo_por_conta($usuario, $dados),
        'transferencia'       => handle_transferencia($usuario, $dados),
        'buscar_transacao'    => handle_buscar_transacao($usuario, $dados),
        'renomear_categoria'  => handle_renomear_categoria($usuario, $dados, $mensagem),
        'renomear_conta'      => handle_renomear_conta($usuario, $dados),
        'saldo_inicial'       => handle_saldo_inicial($usuario, $dados, $mensagem),
        'definir_conta_gasto' => handle_definir_conta_gasto($usuario, $dados),
        'deletar_transacao'   => handle_deletar_transacao($usuario, $dados, $mensagem),
        'editar_transacao'    => handle_editar_valor_transacao($usuario, $dados, $mensagem),
        'set_reminder'        => limite_lembretes_gratis($usuario) ?? handle_set_reminder($usuario, $dados),
        'listar_lembretes'    => (!empty($dados['periodo']) ? exige_plus($usuario, 'A agenda por período') : null) ?? handle_listar_lembretes($usuario, $dados),
        'editar_lembrete'     => handle_editar_lembrete($usuario, $dados),
        'cancelar_lembrete'   => handle_cancelar_lembrete($usuario, $dados),
        'listar_categorias'   => handle_listar_categorias($usuario, $dados),
        'apagar_categoria'    => handle_apagar_categoria($usuario, $dados),
        // Recursos do Plus (exige_plus devolve a oferta se não tiver; null = pode usar).
        // Apagar/cancelar continua liberado no Grátis — ninguém fica refém dos próprios dados.
        'contas_pendentes'    => exige_plus($usuario, 'As contas a pagar') ?? handle_contas_pendentes($usuario, $dados),
        'cadastrar_conta_fixa' => exige_plus($usuario, 'Os gastos fixos') ?? handle_cadastrar_conta_fixa($usuario, $dados, $mensagem),
        'listar_contas_fixas' => exige_plus($usuario, 'Os gastos fixos') ?? handle_listar_contas_fixas($usuario),
        'resumo'              => exige_plus($usuario, 'Os resumos inteligentes') ?? handle_resumo($usuario, $dados),
        'criar_meta'          => exige_plus($usuario, 'As metas de poupança') ?? handle_criar_meta($usuario, $dados, $mensagem),
        'aporte_meta'         => exige_plus($usuario, 'As metas de poupança') ?? handle_aporte_meta($usuario, $dados),
        'listar_metas'        => exige_plus($usuario, 'As metas de poupança') ?? handle_listar_metas($usuario, $dados),
        'editar_meta'         => exige_plus($usuario, 'As metas de poupança') ?? handle_editar_meta($usuario, $dados),
        'cancelar_meta'       => handle_cancelar_meta($usuario, $dados),
        'registrar_agua'      => exige_plus($usuario, 'O controle de água') ?? handle_registrar_agua($usuario, $dados),
        'relatorio_agua'      => exige_plus($usuario, 'O controle de água') ?? handle_relatorio_agua($usuario, $dados),
        'definir_meta_agua'   => exige_plus($usuario, 'O controle de água') ?? handle_definir_meta_agua($usuario, $dados),
        'apagar_agua'         => handle_apagar_agua($usuario),
        'definir_orcamento'   => exige_plus($usuario, 'O orçamento com alertas') ?? handle_definir_orcamento($usuario, $dados),
        'listar_orcamentos'   => exige_plus($usuario, 'O orçamento com alertas') ?? handle_listar_orcamentos($usuario, $dados),
        'remover_orcamento'   => handle_remover_orcamento($usuario, $dados),
        'marcar_conta_paga'   => handle_marcar_conta_paga($usuario, $dados),
        'plano'               => handle_plano($usuario),
        default               => handle_unknown($usuario, $dados, $mensagem),
    };

    // "comprei leite 2 euros": anotou o gasto — se o leite estava numa lista, risca também
    if ($intent === 'log_expense' && !empty($dados['valor']) && empty($dados['gastos'])) {
        $resposta .= lista_riscar_pelo_gasto($usuario, $dados);
    }

    // Só avisa que a pendência anterior se perdeu se ela REALMENTE foi substituída
    // (alguns intents, tipo listar/consultar, não mexem em nada e a pergunta antiga continua valendo)
    if ($tem_pendencia && $intent !== 'unknown') {
        $usuario_agora = $recarregar();
        $pendencia_sumiu = ($estado_antes && ($usuario_agora['estado'] ?? null) !== $estado_antes)
            || ($categoria_antes && empty($usuario_agora['aguardando_transacao_id']));

        // "altera a categoria desse gasto" sobre o MESMO gasto que esperava categoria: a pergunta não se perdeu, só mudou de forma
        $dados_agora = json_decode($usuario_agora['estado_dados'] ?? '{}', true) ?: [];
        if ($categoria_antes && ($usuario_agora['estado'] ?? null) === 'aguardando_dados_edicao'
            && (int) ($dados_agora['alvo']['id'] ?? 0) === (int) $categoria_antes) {
            $pendencia_sumiu = false;
        }

        $aviso = $pendencia_sumiu ? obter_lembrete_pendente($usuario) : ''; // usa o snapshot antigo pra descrever o que se perdeu
        if ($aviso) {
            $resposta .= "\n\n" . $aviso;
        }
    }

    if ($aviso_data) {
        $resposta .= "\n\n⚠️ \"{$aviso_data}\" não existe no calendário (confere o dia) — registrei sem data específica. Me diz a data certa se quiser corrigir.";
    }

    return $resposta;
}

// Clique num botão. Devolve a resposta (ou null se não precisa mandar nada novo)
function processar_callback($usuario, $callback, $config) {
    usuario_atual($usuario['id']);
    $dados = $callback['data'] ?? '';
    $chat_id = $callback['message']['chat']['id'] ?? $usuario['telegram_id'];
    $message_id = $callback['message']['message_id'] ?? null;
    $texto_original = $callback['message']['text'] ?? '';

    if (str_starts_with($dados, 'tx:')) {
        // Botão que "digita" pelo usuário — tira os botões da mensagem (evita clique duplo) e segue como texto normal
        if ($message_id) {
            chamar_telegram($config, 'editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => ['inline_keyboard' => []]]);
        }
        return processar_mensagem($usuario, substr($dados, 3), $config);
    }

    if (str_starts_with($dados, 'M:')) {
        // Escolha do modo nas boas-vindas — tira os botões da mensagem pra não escolher duas vezes
        if ($message_id) {
            chamar_telegram($config, 'editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => ['inline_keyboard' => []]]);
        }
        return handle_definir_modo($usuario, substr($dados, 2) === 'simples' ? MODO_SIMPLES : MODO_CONTAS);
    }

    if (str_starts_with($dados, 'A:')) {
        [$marca, $resposta] = handle_callback_agua($usuario, $dados);
        if ($marca && $message_id) {
            // Marca o aviso com o que foi registrado e tira os botões (evita contar duas vezes)
            chamar_telegram($config, 'editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $texto_original . "\n\n" . $marca]);
        }
        return $resposta;
    }

    if (str_starts_with($dados, 'I:')) {
        // Riscar item / limpar riscados: atualiza a própria mensagem da lista (sem mandar outra)
        $lista = handle_callback_lista($usuario, $dados);
        if (!$lista) return "Esse item não está mais na lista 🤔 Manda \"mostra a lista\" pra ver como ficou.";
        [$texto, $botoes] = $lista;
        if ($message_id) {
            chamar_telegram($config, 'editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $texto,
                'reply_markup' => $botoes ? montar_teclado($botoes) : ['inline_keyboard' => []]]);
            return null;
        }
        botoes_resposta($botoes);
        return $texto;
    }

    if (str_starts_with($dados, 'C:')) {
        // Troca de moeda nas boas-vindas — tira só o botão da moeda; os do modo continuam pra quem ainda não escolheu
        if ($message_id) {
            $resto = empty($usuario['modo_contas']) ? [[['💳 Controlar por conta/banco', 'M:contas']], [['📝 Só anotar gastos', 'M:simples']]] : [];
            chamar_telegram($config, 'editMessageReplyMarkup', ['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => $resto ? montar_teclado($resto) : ['inline_keyboard' => []]]);
        }
        return handle_callback_moeda($usuario, $dados);
    }

    if (str_starts_with($dados, 'R:')) {
        // "É uma conta de todo mês?" — marca a escolha e tira os botões
        [$marca, $resposta] = handle_callback_recorrencia($usuario, $dados);
        if ($marca && $message_id) {
            chamar_telegram($config, 'editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $texto_original . "\n\n" . $marca]);
        }
        return $resposta;
    }

    if (str_starts_with($dados, 'V:')) {
        // Escolha de quando avisar um compromisso — marca a escolha e tira os botões
        [$marca, $resposta] = handle_callback_antecedencia($usuario, $dados);
        if ($marca && $message_id) {
            chamar_telegram($config, 'editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $texto_original . "\n\n" . $marca]);
        }
        return $resposta;
    }

    if (str_starts_with($dados, 'L:')) {
        [$marca, $resposta] = handle_callback_lembrete($usuario, $dados);
        if ($marca && $message_id) {
            // Marca o aviso original com o que foi feito, sem os botões
            chamar_telegram($config, 'editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $texto_original . "\n\n" . $marca]);
        }
        return $resposta;
    }

    return null;
}
