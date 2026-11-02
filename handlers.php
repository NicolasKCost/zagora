<?php

require_once __DIR__ . '/db.php';

// Reconhece agradecimento/confirmação casual direto, sem depender da IA (ela não generaliza bem
// combinações tipo "valeu cara", "vlw mano" etc.)
function parece_confirmacao($texto) {
    $texto = trim($texto);
    $palavras = preg_split('/\s+/', $texto);
    if (count($palavras) > 4) return false;
    return (bool) preg_match('/\b(valeu|obrigad[oa]|vlw|blz|beleza|tamo\s*junto|[ée]\s*n[oó]is|fechou|j[oó]ia|top|show|massa|de\s*nada|thanks|gratid[ãa]o|perfeit[oa]|[óo]tim[oa]|excelente|maravilha|legal|bacana|fixe|combinado|entendi)\b/ui', $texto);
}

// Reconhece qualquer jeito comum de dizer "sim/confirmo", em português ou não
function eh_confirmacao_afirmativa($texto) {
    $texto = strtolower(trim($texto, " .,!?"));
    $palavras = ['sim', 's', 'ya', 'yea', 'yeah', 'yes', 'y', 'oui', 'ok', 'okay', 'okey', 'isso', 'isso mesmo', 'aham', 'uhum', 'certo', 'exato', 'mesma', 'mesmo', 'essa mesma', 'a mesma'];
    return in_array($texto, $palavras, true);
}

// "pronto", "é isso", "só foram estas as despesas", "acabei" — a pessoa terminou de lançar
function parece_finalizacao($texto) {
    $t = sem_acentos(trim($texto, " .,!?\n\t"));
    return (bool) preg_match('/^(pronto|e isso|era isso|so isso|e so isso|foi isso|foi so isso|acabou|acabei|terminei|finalizei|finalizar|finaliza|mais nada|nada mais|por hoje e so|por agora e so|(so )?(foram|era|eram) (so )?(essas|estas|esses|estes)( as| os)?( despesas| gastos| contas)?|(so )?(essas|estas|esses|estes) (foram|sao|eram) (as|os) (despesas|gastos)|como (e que )?(finaliza|termina|encerra)\w*)$/u', $t);
}

// Fecha a "sessão" com o resumo do que foi registrado hoje
function handle_finalizacao($usuario) {
    $stmt = get_db()->prepare("SELECT moeda, COUNT(*) AS qtd, SUM(valor) AS total, SUM(categoria = 'pendente') AS sem_cat FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND criado_em >= ? GROUP BY moeda");
    $stmt->execute([$usuario['id'], date('Y-m-d 00:00:00')]);
    $linhas = $stmt->fetchAll();
    if (!$linhas) {
        return "Fechado! 👋 Quando tiver um gasto, é só me mandar — não precisa \"abrir\" nem \"fechar\" nada, cada mensagem já fica registrada.";
    }
    $qtd = array_sum(array_column($linhas, 'qtd'));
    $sem_cat = (int) array_sum(array_column($linhas, 'sem_cat'));
    $texto = sprintf("Fechado! ✅ Hoje você registrou %d gasto%s — total %s.", $qtd, $qtd == 1 ? '' : 's',
        implode(' + ', array_map(fn($l) => formatar_dinheiro($l['total'], $l['moeda']), $linhas)));
    if ($sem_cat) {
        $texto .= sprintf("\n🏷️ %s sem categoria — quando quiser: \"o gasto do X é mercado\".", $sem_cat === 1 ? 'Um ficou' : "{$sem_cat} ficaram");
    }
    return $texto . "\n\nNão precisa \"fechar\" nada: cada mensagem já fica salva. Quando tiver outro gasto, é só mandar. 👋";
}

function handle_confirmacao($usuario) {
    $respostas = [
        "👍", "🙌", "🤝", "😎",
        "Tamo junto! 🙌", "É nóis! 😎", "Fechou! 🤝", "Show de bola! 🎉",
        "Sempre às ordens! 😄", "Disponha! 🙂", "Por nada, parceiro! 🤗",
        "Boraaa! 🚀", "Suave! 😌", "Coleguinha é isso! 💪", "Anotado no coração 💛",
        "De nada! Bora pro próximo. 😄",
    ];
    return $respostas[array_rand($respostas)];
}

// ============================================================================
// Modo de uso: 'contas' (controla por conta/banco) ou 'simples' (só anota gastos). NULL = ainda não escolheu.
// ============================================================================

const MODO_CONTAS = 'contas';
const MODO_SIMPLES = 'simples';

function usa_contas($usuario) {
    return ($usuario['modo_contas'] ?? null) !== MODO_SIMPLES;
}

function definir_modo_contas($usuario_id, $modo) {
    get_db()->prepare("UPDATE usuarios SET modo_contas = ? WHERE id = ?")->execute([$modo, $usuario_id]);
}

function primeiro_nome($usuario) {
    return explode(' ', trim($usuario['nome'] ?? ''))[0] ?? '';
}

function handle_greeting($usuario) {
    $nome = primeiro_nome($usuario);

    if (empty($usuario['modo_contas'])) {
        // Primeira conversa: explica o que faz e deixa escolher como quer usar.
        // A moeda vem do link (site_br/site_pt) ou do idioma do Telegram — o Telegram não diz o país, então mostra qual
        // ficou e deixa trocar com um toque (brasileiro que veio pelo link PT, quem mora em Portugal com Telegram pt-br...).
        $stmt = get_db()->prepare("SELECT moeda_padrao FROM usuarios WHERE id = ?"); // pode ter acabado de mudar nesta mesma mensagem
        $stmt->execute([$usuario['id']]);
        $moeda = $stmt->fetchColumn() ?: 'EUR';
        $outra = $moeda === 'BRL' ? 'EUR' : 'BRL';
        botoes_resposta([
            [['💳 Controlar por conta/banco', 'M:contas']],
            [['📝 Só anotar gastos', 'M:simples']],
            [['💱 Usar ' . nome_moeda($outra), "C:$outra"]],
        ]);
        return "E aí, {$nome}! 👋 Sou o Zágora — cuido do seu dinheiro e da sua agenda, só conversando. É só falar comigo normal, como falaria com um amigo.\n\n"
             . "💰 Anoto seus gastos, mostro seu saldo e não deixo conta vencer\n"
             . "🗓️ Te lembro dos compromissos — e aviso antes da hora\n"
             . "📝 Guardo suas listas e te ajudo com a rotina (até a água 💧)\n\n"
             . "Pra começar pelo dinheiro, como você prefere?\n\n"
             . "💳 Por conta/banco — eu acompanho o saldo de cada conta (Wise, Millennium, dinheiro...) e pergunto de onde saiu cada gasto.\n"
             . "📝 Só anotar gastos — sem pergunta de conta: me conta o gasto e pronto.\n\n"
             . "(Dá pra mudar depois, é só pedir.)\n\n"
             . "💱 Vou anotar em " . nome_moeda($moeda) . ". Se você usa " . nome_moeda($outra) . ", toca no botão de baixo.\n\n"
             . (status_plus($usuario)['tipo'] === 'teste' ? "🎁 Você ganhou 14 dias de Zágora Plus grátis — tudo liberado, sem cartão.\n" : '')
             . "💡 Manda /ajuda a qualquer momento pra ver tudo que eu faço. Achou um erro? /reportar 🐞";
    }

    botoes_resposta([[botao_texto('📋 O que você faz?', 'ajuda')]]);
    return "E aí, {$nome}! 👋 Me conta um gasto, pergunta seu saldo, marca um compromisso ou põe algo na lista. Pra ver tudo que eu faço, manda /ajuda ou toca abaixo. Achou um erro? /reportar 🐞";
}

function handle_definir_modo($usuario, $modo) {
    $modo = $modo === MODO_SIMPLES ? MODO_SIMPLES : MODO_CONTAS;
    definir_modo_contas($usuario['id'], $modo);

    if ($modo === MODO_CONTAS) {
        return "💳 Fechado, vamos por conta!\n\n"
             . "Me diz quanto você tem hoje em cada uma, tipo:\n\"tenho 500 na wise, 200 no millennium e 50 em dinheiro\"\n\n"
             . "Depois é só ir me contando os gastos (\"gastei 20 no mercado com a wise\") — se esquecer de dizer a conta, eu pergunto.\n\n"
             . "Além do dinheiro, também cuido da sua agenda (\"tenho dentista sexta às 15h\") e das suas listas (\"adiciona leite na lista\").\n"
             . "Manda \"ajuda\" quando quiser ver tudo que eu faço.";
    }

    return "📝 Fechado, sem complicação!\n\n"
         . "É só me contar os gastos (\"gastei 20 no mercado\", \"paguei 45 de luz\") que eu anoto — não vou perguntar conta nenhuma.\n"
         . "Se quiser, me diz quanto tem hoje (\"tenho 800\") pra eu te mostrar o saldo.\n\n"
         . "Além do dinheiro, também cuido da sua agenda (\"tenho dentista sexta às 15h\") e das suas listas (\"adiciona leite na lista\").\n\n"
         . "Se um dia quiser controlar por banco, é só dizer \"quero controlar por conta\". Manda \"ajuda\" pra ver tudo que eu faço.";
}

function handle_ajuda($usuario) {
    $contas = usa_contas($usuario)
        ? "💳 Contas: \"tenho 500 na wise\", \"quanto tenho em cada conta?\", \"transferi 100 da wise pro millennium\"\n\n"
        : "💰 Saldo: \"tenho 800\", \"quanto eu tenho?\"\n\n";

    // Três pilares, com o dinheiro na frente (é o que o Plus vende)
    return "Tudo que eu faço 👇\n\n"
         . "━━ 💰 DINHEIRO ━━\n"
         . "💸 Gastos e receitas: \"gastei 20 no mercado\", \"recebi 1200 de salário\", \"lista meus gastos\", \"quanto gastei esse mês?\"\n\n"
         . $contas
         . "📌 Contas a pagar: \"arrendamento 700 todo dia 1\", \"meus gastos fixos\", \"o que falta pagar?\" — o aviso vem com o botão \"Paguei\"\n\n"
         . "📊 Orçamento: \"no máximo 300 em restaurante por mês\", \"como tá meu orçamento?\"\n\n"
         . "🎯 Metas: \"quero juntar 1000 até dezembro pra viagem\", \"guardei 100 pra viagem\", \"quanto guardar por semana?\"\n\n"
         . "📈 Resumos: \"resumo da semana\", \"como foi meu mês?\" (e toda segunda e todo dia 1 eu mando sozinho)\n\n"
         . "✏️ Corrigir: \"muda a categoria do gasto de 12\", \"o gasto do uber foi ontem\", \"apaga o último gasto\"\n\n"
         . "🏷️ Categorias: \"lista minhas categorias\", \"altera a categoria fogo pra tabacaria\"\n\n"
         . "━━ 🗓️ AGENDA ━━\n"
         . "🔔 Compromissos e lembretes: \"tenho dentista sexta às 15h\" (te aviso antes), \"me lembra de ligar pro banco amanhã às 10h\", \"minha agenda da semana\", \"o que tenho amanhã?\"\n\n"
         . "━━ 📝 DIA A DIA ━━\n"
         . "🛒 Listas: \"adiciona leite e pão na lista\", \"mostra a lista\", \"risca o leite\", \"lista de tarefas: ligar pro contador\"\n\n"
         . "💧 Água: \"me lembra de beber água de 2 em 2 horas\", \"bebi 500ml\", \"minha meta de água é 2 litros\"\n\n"
         . "⚙️ Comandos: /plano (seu plano e assinatura) · /moeda (EUR, BRL ou USD) · /limpar (apaga as minhas mensagens) · /paysupport (ajuda com pagamento) · /reportar (avisar de um erro, pode mandar print) · /termos";
}

function handle_reset($usuario) {
    $pdo = get_db();
    foreach (['transacoes', 'lembretes', 'estabelecimentos', 'contas', 'transferencias', 'saldos_iniciais', 'orcamentos', 'pagamentos_conta', 'agua_registros', 'metas', 'metas_aportes', 'mensagens', 'lista_itens', 'listas'] as $tabela) {
        $pdo->prepare("DELETE FROM {$tabela} WHERE usuario_id = ?")->execute([$usuario['id']]);
    }
    // modo_contas volta pra NULL: na próxima conversa ele escolhe de novo como quer usar
    $pdo->prepare("UPDATE usuarios SET ultima_conta = NULL, modo_contas = NULL, agua_meta_ml = NULL, agua_copo_ml = NULL WHERE id = ?")->execute([$usuario['id']]);
    limpar_estado($usuario['id']);

    return "Prontinho, limpei tudo o que você tinha registrado (gastos, receitas, contas, gastos fixos, orçamentos, lembretes, listas e o que eu tinha aprendido). Bora recomeçar do zero. 🧹\n\nManda um \"oi\" pra gente começar.";
}

// "real (R$)", "euro (€)", "dólar ($)"
function nome_moeda($codigo) {
    return match ($codigo) {
        'BRL' => 'real (R$)',
        'USD' => 'dólar ($)',
        default => 'euro (€)',
    };
}

// Botão de moeda das boas-vindas (C:BRL / C:EUR). Devolve a resposta.
function handle_callback_moeda($usuario, $callback_data) {
    $codigo = substr($callback_data, 2);
    if (!in_array($codigo, ['EUR', 'BRL', 'USD'], true)) return "Esse botão não funciona mais 🤔";
    get_db()->prepare("UPDATE usuarios SET moeda_padrao = ? WHERE id = ?")->execute([$codigo, $usuario['id']]);
    return "💱 Certo, vou anotar em " . nome_moeda($codigo) . "."
         . (empty($usuario['modo_contas']) ? " Agora escolhe aí em cima como você prefere usar 👆" : " (Pra mudar de novo: /moeda)");
}

function simbolo_moeda($codigo) {
    return match ($codigo) {
        'BRL' => 'R$',
        'USD' => '$',
        default => '€',
    };
}

// "água" -> "agua" (pra busca por palavra-chave não depender de acento)
function sem_acentos($texto) {
    return strtr(mb_strtolower($texto), [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
}

// Formata valor com símbolo, pondo o sinal antes do símbolo (-€50.00 em vez de €-50.00)
function formatar_dinheiro($valor, $moeda) {
    // Padrão de Portugal e do Brasil: vírgula nos centavos, ponto no milhar ("R$ 1.255,00", "1.255,00 €")
    $v = number_format(abs((float) $valor), 2, ',', '.');
    $sinal = $valor < 0 ? '-' : '';
    return match ($moeda) {
        'BRL'   => "{$sinal}R$ {$v}",
        'USD'   => "{$sinal}US$ {$v}",
        default => "{$sinal}{$v} €",
    };
}

function handle_set_moeda($usuario, $codigo) {
    $codigo = strtoupper(trim($codigo));
    if (!in_array($codigo, ['EUR', 'BRL', 'USD'])) {
        return "Só sei trabalhar com EUR, BRL ou USD por enquanto 🙂 Manda \"/moeda EUR\", \"/moeda BRL\" ou \"/moeda USD\".";
    }

    $pdo = get_db();
    $pdo->prepare("UPDATE usuarios SET moeda_padrao = ? WHERE id = ?")->execute([$codigo, $usuario['id']]);

    return sprintf("Feito! Agora quando você não disser a moeda, vou assumir %s (%s). Mas pode sempre dizer outra na hora (\"gastei 10 dólares\"), sem problema.", $codigo, simbolo_moeda($codigo));
}

function handle_log_expense($usuario, $dados, $texto_original = '') {
    // Vários gastos na mesma mensagem ("507 com o carro, 450 de aluguel e 30 na farmácia") — nenhum pode se perder
    if (!empty($dados['gastos']) && is_array($dados['gastos'])) {
        return handle_varios_gastos($usuario, $dados['gastos'], $dados);
    }

    $valor           = $dados['valor'] ?? null;
    $moeda           = $dados['moeda'] ?? null;
    $estabelecimento = $dados['estabelecimento'] ?? null;
    $categoria       = $dados['categoria'] ?? null;
    $descricao       = $dados['descricao'] ?? ($estabelecimento ?? '');
    $data            = $dados['data'] ?? date('Y-m-d');
    $conta           = $dados['conta'] ?? null;

    // A IA às vezes não vê o número em frases curtas ("Academia 84") — se tem número na frase, é o valor
    if ($valor === null && $texto_original !== '') {
        [$valor_texto, $moeda_texto] = extrair_valor_e_moeda($texto_original);
        if ($valor_texto !== null) {
            $valor = $valor_texto;
            $moeda = $moeda ?? $moeda_texto;
            $limpar = fn($s) => trim(preg_replace('/\s*(r\$|€|\$)?\s*\d+(?:[.,]\d+)?\s*(€|euros?|reais|r\$|d[oó]lares?)?\s*/ui', ' ', (string) $s));
            $descricao = $limpar($descricao);
            $estabelecimento = $estabelecimento !== null ? ($limpar($estabelecimento) ?: null) : null;
        }
    }

    if ($valor === null) {
        $lembrete_id = $dados['lembrete_id'] ?? null; // gasto vindo do "Paguei" de uma conta sem valor
        salvar_estado($usuario['id'], 'aguardando_valor_gasto', compact('moeda', 'estabelecimento', 'categoria', 'descricao', 'data', 'conta', 'lembrete_id'));
        return "Quanto foi o gasto? 💸";
    }

    // A IA às vezes esquece a categoria em "gastei 90 no restaurante" — se a descrição é exatamente uma categoria conhecida, usa
    if (!$categoria) {
        $categoria = categoria_por_palavra($descricao ?: (string) $estabelecimento);
    }

    $nota_moeda = ajustar_moeda_padrao($usuario, $moeda);
    return avancar_gasto($usuario, $valor, $moeda, $estabelecimento, $categoria, $descricao, $data, $conta) . $nota_moeda;
}

// Descrição que é EXATAMENTE uma categoria (ou um sinônimo óbvio) → a categoria. Qualquer outra coisa → null (a IA/usuário decide).
function categoria_por_palavra($texto) {
    $t = sem_acentos(trim((string) $texto, " .,!?"));
    $t = preg_replace('/^(no|na|o|a|de|do|da)\s+/u', '', $t);
    $mapa = [
        'mercado' => 'mercado', 'supermercado' => 'mercado', 'feira' => 'mercado', 'padaria' => 'mercado',
        'restaurante' => 'restaurante', 'almoco' => 'restaurante', 'jantar' => 'restaurante', 'lanche' => 'restaurante',
        'transporte' => 'transporte', 'uber' => 'transporte', 'bolt' => 'transporte', 'gasolina' => 'transporte', 'combustivel' => 'transporte', 'onibus' => 'transporte', 'metro' => 'transporte', 'taxi' => 'transporte',
        'lazer' => 'lazer', 'cinema' => 'lazer',
        'saude' => 'saude', 'farmacia' => 'saude', 'academia' => 'saude', 'ginasio' => 'saude', 'medico' => 'saude', 'dentista' => 'saude',
        'contas' => 'contas', 'aluguel' => 'contas', 'arrendamento' => 'contas', 'renda' => 'contas', 'luz' => 'contas', 'internet' => 'contas', 'agua' => 'contas',
        'roupa' => 'roupa', 'roupas' => 'roupa', 'presente' => 'presente',
    ];
    return $mapa[$t] ?? null;
}

// Primeiros registros com uma moeda explícita diferente da padrão ("87€" pra quem começou em R$): ela vira a padrão.
// Assim quem é do Brasil mas mora em Portugal (ou o contrário) não precisa descobrir o /moeda.
function ajustar_moeda_padrao($usuario, $moeda) {
    if (!$moeda || $moeda === ($usuario['moeda_padrao'] ?? 'EUR')) return '';
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM transacoes WHERE usuario_id = ?");
    $stmt->execute([$usuario['id']]);
    if ((int) $stmt->fetchColumn() > 3) return '';
    get_db()->prepare("UPDATE usuarios SET moeda_padrao = ? WHERE id = ?")->execute([$moeda, $usuario['id']]);
    return sprintf("\n\n💱 Percebi que você usa %s — vou considerar essa moeda quando não disser outra. (Pra mudar: /moeda)", $moeda === 'EUR' ? 'euro (€)' : ($moeda === 'BRL' ? 'real (R$)' : 'dólar ($)'));
}

// Registra vários gastos de uma vez. Quem controla por conta ganha UMA pergunta de conta pra todos.
function handle_varios_gastos($usuario, $itens, $dados) {
    $pdo = get_db();
    $moeda_padrao = $usuario['moeda_padrao'] ?? 'EUR';
    $conta_geral = !empty($dados['conta']) ? salvar_conta($usuario['id'], $dados['conta']) : null;
    $data_geral = $dados['data'] ?? date('Y-m-d');
    $linhas = [];
    $totais = [];
    $sem_conta = [];
    $sem_categoria = 0;
    $sem_valor = [];
    // Moeda dita explicitamente (avaliada antes de gravar — depois já teria gastos demais pra ajustar a padrão)
    $moeda_vista = $dados['moeda'] ?? (array_values(array_filter(array_column($itens, 'moeda')))[0] ?? null);
    $nota_moeda = ajustar_moeda_padrao($usuario, $moeda_vista);
    if ($nota_moeda) $moeda_padrao = $moeda_vista;

    foreach ($itens as $item) {
        $valor = isset($item['valor']) && is_numeric($item['valor']) ? (float) $item['valor'] : null;
        $descricao = trim($item['descricao'] ?? ($item['estabelecimento'] ?? ''));
        if ($valor === null || $valor <= 0) {
            if ($descricao !== '') $sem_valor[] = $descricao;
            continue;
        }
        $moeda = $item['moeda'] ?? ($dados['moeda'] ?? $moeda_padrao);
        $categoria = !empty($item['categoria']) ? normalizar_categoria($item['categoria'])
                   : (!empty($item['estabelecimento']) ? buscar_categoria_conhecida($usuario['id'], $item['estabelecimento']) : null);
        $conta = !empty($item['conta']) ? salvar_conta($usuario['id'], $item['conta']) : $conta_geral;
        $data = (!empty($item['data']) && validar_data_ou_invalida($item['data'])) ? $item['data'] : $data_geral;

        $pdo->prepare("INSERT INTO transacoes (usuario_id, valor, moeda, tipo, categoria, descricao, data, conta) VALUES (?, ?, ?, 'gasto', ?, ?, ?, ?)")
            ->execute([$usuario['id'], $valor, $moeda, $categoria ?: 'pendente', $descricao, $data, $conta]);
        if (!$conta) $sem_conta[] = (int) $pdo->lastInsertId();
        if (!$categoria) $sem_categoria++;

        $linhas[] = sprintf("• %s — %s (%s)", formatar_dinheiro($valor, $moeda), $descricao ?: 'gasto', rotulo_categoria($categoria ?: 'pendente'));
        $totais[$moeda] = ($totais[$moeda] ?? 0) + $valor;
    }

    if (!$linhas) {
        return "Não consegui ver os valores 🤔 Manda um gasto por linha, tipo \"mercado 240\".";
    }

    limpar_estado($usuario['id']);
    $texto = sprintf("Anotei %d gasto%s 📝\n%s\nTotal: %s", count($linhas), count($linhas) === 1 ? '' : 's', implode("\n", $linhas),
        implode(' + ', array_map(fn($m, $t) => formatar_dinheiro($t, $m), array_keys($totais), $totais)));
    if ($sem_valor) {
        $texto .= "\n\n⚠️ Faltou o valor de: " . implode(', ', $sem_valor) . " — me manda tipo \"" . $sem_valor[0] . " 30\".";
    }
    if ($sem_categoria) {
        $texto .= "\n\n🏷️ " . ($sem_categoria === 1 ? 'Um ficou' : "{$sem_categoria} ficaram") . " sem categoria — pra corrigir: \"o gasto do X é mercado\".";
    }
    $texto .= $nota_moeda;

    if ($sem_conta && usa_contas($usuario)) {
        salvar_estado($usuario['id'], 'aguardando_conta_varios', ['ids' => $sem_conta]);
        $texto .= "\n\n" . pergunta_conta($usuario, "De qual conta saíram? 💳 (vale pra todos)");
    }
    return $texto;
}

// Depois de saber o valor, garante que a conta também foi informada antes de salvar de vez.
function avancar_gasto($usuario, $valor, $moeda, $estabelecimento, $categoria, $descricao, $data, $conta) {
    if (!$conta) {
        if (!usa_contas($usuario)) {
            return continuar_gasto($usuario, $valor, $moeda, $estabelecimento, $categoria, $descricao, $data, null);
        }
        salvar_estado($usuario['id'], 'aguardando_conta_gasto', compact('valor', 'moeda', 'estabelecimento', 'categoria', 'descricao', 'data'));
        return pergunta_conta($usuario, "Em qual conta você pagou? 💳");
    }

    return continuar_gasto($usuario, $valor, $moeda, $estabelecimento, $categoria, $descricao, $data, $conta);
}

// "Em qual conta?" com botões das contas + saída pra quem não quer responder
// (quem ainda não escolheu o modo ganha "Não uso contas", que desliga a pergunta de vez)
function pergunta_conta($usuario, $pergunta) {
    $contas = listar_nomes_contas($usuario['id'], $usuario['ultima_conta'] ?? null);
    $botoes = array_chunk(array_map(fn($c) => botao_texto(ucfirst($c), $c), $contas), 3);

    if (empty($usuario['modo_contas'])) {
        $botoes[] = [botao_texto('📝 Não uso contas', 'não uso contas')];
        botoes_resposta($botoes);
        return $pergunta . ($contas ? " (toca numa ou escreve outra)" : " (ex: wise, millennium, dinheiro)")
             . "\nSe não quiser controlar por conta, toca em \"Não uso contas\" que eu paro de perguntar.";
    }

    $botoes[] = [botao_texto('⏭️ Sem conta', 'sem conta')];
    botoes_resposta($botoes);
    return $pergunta . ($contas ? " (toca numa ou escreve outra)" : "");
}

// Resposta a "em qual conta?" que NÃO é o nome de uma conta. Devolve:
// 'desligar' (não quer usar contas nunca), 'sem' (só dessa vez), 'dinheiro' (pagou em dinheiro vivo) ou null (é um nome de conta)
function interpretar_resposta_conta($texto) {
    $t = sem_acentos(trim($texto, " .,!?\n\t"));
    if (preg_match('/^(eu\s+)?nao\s+(uso|quero|controlo|tenho|preciso)(\s+(usar|de|controlar))?\s+(contas?|bancos?)/u', $t)) return 'desligar';
    if (preg_match('/^(sem\s+conta|nenhuma?|nao\s+sei|nao\s+lembro|nao\s+uso|sei\s+la|tanto\s+faz|pular?|pula|skip|-)$/u', $t)) return 'sem';
    if (preg_match('/^(em\s+|no\s+|com\s+)?(dinheiro(\s+vivo)?|cash|numerario|especie|notas?|carteira\s+fisica)$/u', $t)) return 'dinheiro';
    return null;
}

// Contas do usuário pra virar botão (a última usada primeiro), no máximo 6
function listar_nomes_contas($usuario_id, $primeira = null) {
    $stmt = get_db()->prepare("SELECT nome FROM contas WHERE usuario_id = ? AND nome <> 'geral' ORDER BY nome"); // 'geral' = saldo de quem não usa contas
    $stmt->execute([$usuario_id]);
    $nomes = array_column($stmt->fetchAll(), 'nome');
    if ($primeira && in_array($primeira, $nomes, true)) {
        $nomes = array_merge([$primeira], array_diff($nomes, [$primeira]));
    }
    return array_slice($nomes, 0, 6);
}

// Botões de categoria: as que o usuário mais usa + as padrão, até 6
function botoes_categorias($usuario_id) {
    $stmt = get_db()->prepare("SELECT categoria FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND categoria <> 'pendente' GROUP BY categoria ORDER BY COUNT(*) DESC LIMIT 6");
    $stmt->execute([$usuario_id]);
    $categorias = array_unique(array_merge(array_column($stmt->fetchAll(), 'categoria'), ['mercado', 'restaurante', 'transporte', 'lazer', 'contas', 'saude']));
    $categorias = array_slice(array_values($categorias), 0, 6);
    return array_chunk(array_map(fn($c) => botao_texto(ucfirst(rotulo_categoria($c)), $c), $categorias), 3);
}

// Calcula o saldo atual de uma conta específica (saldo inicial + receitas - gastos +/- transferências)
function calcular_saldo_conta($usuario_id, $conta, $moeda) {
    $pdo = get_db();
    $conta = strtolower(trim($conta));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(valor), 0) as total FROM (
            SELECT valor FROM saldos_iniciais WHERE usuario_id = ? AND conta = ? AND moeda = ?
            UNION ALL
            SELECT valor FROM transacoes WHERE usuario_id = ? AND conta = ? AND moeda = ? AND tipo = 'receita'
            UNION ALL
            SELECT -valor FROM transacoes WHERE usuario_id = ? AND conta = ? AND moeda = ? AND tipo = 'gasto'
            UNION ALL
            SELECT valor FROM transferencias WHERE usuario_id = ? AND conta_destino = ? AND moeda = ?
            UNION ALL
            SELECT -valor FROM transferencias WHERE usuario_id = ? AND conta_origem = ? AND moeda = ?
        ) t
    ");
    $stmt->execute([
        $usuario_id, $conta, $moeda, $usuario_id, $conta, $moeda, $usuario_id, $conta, $moeda,
        $usuario_id, $conta, $moeda, $usuario_id, $conta, $moeda,
    ]);
    return (float) $stmt->fetch()['total'];
}

function continuar_gasto($usuario, $valor, $moeda, $estabelecimento, $categoria, $descricao, $data, $conta = null) {
    $pdo = get_db();
    $moeda = $moeda ?? $usuario['moeda_padrao'] ?? 'EUR';

    if (!$categoria && $estabelecimento) {
        $categoria = buscar_categoria_conhecida($usuario['id'], $estabelecimento);
    }

    if ($conta) {
        $conta = salvar_conta($usuario['id'], $conta); // corrige typo pra conta existente, se for o caso
        $pdo->prepare("UPDATE usuarios SET ultima_conta = ? WHERE id = ?")->execute([$conta, $usuario['id']]);
        if (empty($usuario['modo_contas'])) {
            definir_modo_contas($usuario['id'], MODO_CONTAS); // disse a conta sem ter escolhido o modo: é quem usa contas
        }
    }

    $pdo->prepare("INSERT INTO transacoes (usuario_id, valor, moeda, tipo, categoria, descricao, data, conta) VALUES (?, ?, ?, 'gasto', ?, ?, ?, ?)")
        ->execute([$usuario['id'], $valor, $moeda, $categoria ?? 'pendente', $descricao, $data, $conta]);
    $transacao_id = $pdo->lastInsertId();

    $simbolo = simbolo_moeda($moeda);
    $orcamento = linhas_orcamento_apos_gasto($usuario['id'], $transacao_id);

    if ($categoria) {
        limpar_estado($usuario['id']);
        $resposta = sprintf("Boa, anotei! %s em %s 📝", formatar_dinheiro($valor, $moeda), rotulo_categoria($categoria));
        if ($conta) {
            $saldo_conta = calcular_saldo_conta($usuario['id'], $conta, $moeda);
            $resposta .= sprintf("\n💳 Saldo %s: %s", ucfirst($conta), formatar_dinheiro($saldo_conta, $moeda));
        }
        return $resposta . $orcamento;
    }

    if ($estabelecimento) {
        marcar_aguardando_categoria($usuario['id'], $transacao_id, $estabelecimento);
        botoes_resposta(botoes_categorias($usuario['id']));
        return sprintf(
            "Anotei os %s! Só não conheço o \"%s\" ainda — é mercado, transporte, lazer, contas, saúde, roupa, restaurante ou outra coisa? 🤔\n(depois dessa já vou saber sozinho da próxima vez)",
            formatar_dinheiro($valor, $moeda), $estabelecimento
        ) . $orcamento;
    }

    // Sem categoria nem estabelecimento — fica registrado do mesmo jeito, só sem categoria
    limpar_estado($usuario['id']);
    botoes_resposta(botoes_categorias($usuario['id']));
    return sprintf("Anotei! %s 📝 Ficou como gasto solto (sem categoria) — se quiser, toca numa categoria ou me diz qual é.", formatar_dinheiro($valor, $moeda)) . $orcamento;
}

// Chamada quando o usuário está respondendo "o que é esse estabelecimento" ou "qual a categoria"
function handle_resposta_categoria($usuario, $texto) {
    if (!parece_categoria_valida($texto)) {
        return "Desculpa, não entendi — qual é a categoria certa? 🤔";
    }

    $pdo = get_db();
    $transacao_id     = $usuario['aguardando_transacao_id'];
    $estabelecimento  = $usuario['aguardando_estabelecimento'];
    $categoria        = normalizar_categoria($texto);

    $pdo->prepare("UPDATE transacoes SET categoria = ? WHERE id = ? AND usuario_id = ?")
        ->execute([$categoria, $transacao_id, $usuario['id']]);

    limpar_aguardando_categoria($usuario['id']);
    $orcamento = linhas_orcamento_apos_gasto($usuario['id'], $transacao_id);

    if ($estabelecimento) {
        salvar_estabelecimento($usuario['id'], $estabelecimento, $categoria);
        return sprintf("Entendi! 😊 Vou lembrar que \"%s\" é %s a partir de agora.", $estabelecimento, rotulo_categoria($categoria)) . $orcamento;
    }

    return sprintf("Entendi, marquei como %s. ✅", rotulo_categoria($categoria)) . $orcamento;
}

// Categoriza o gasto solto mais recente (usado tanto pelo atalho de resposta curta quanto pelo fallback de "não entendi")
function categorizar_gasto_pendente($usuario_id, $transacao_id, $categoria_texto) {
    if (!parece_categoria_valida($categoria_texto)) {
        return "Desculpa, não entendi — qual é a categoria certa? 🤔";
    }
    $pdo = get_db();
    $categoria = normalizar_categoria($categoria_texto);
    $pdo->prepare("UPDATE transacoes SET categoria = ? WHERE id = ? AND usuario_id = ?")
        ->execute([$categoria, $transacao_id, $usuario_id]);

    return sprintf("Ah, entendi! Categorizei aquele gasto solto como %s. ✅", rotulo_categoria($categoria)) . linhas_orcamento_apos_gasto($usuario_id, $transacao_id);
}

// Tira "altera a categoria X [pra Y]" direto do texto — a IA às vezes não preenche categoria_antiga
// quando o nome da categoria é uma palavra comum (ex: "perfeito", "nao").
function extrair_renomeacao_categoria($texto) {
    $padrao = '/\b(?:altera|alterar|corrige|corrigir|muda|mudar|renomeia|renomear|troca|trocar)\s+(?:a\s+)?categoria\s+(?:d[eoa]s?\s+)?["“]?(.+?)["”]?(?:\s+(?:pra|para|pro|por)\s+["“]?(.+?)["”]?)?[\s.!?]*$/ui';
    if (!preg_match($padrao, trim($texto), $m)) {
        return [null, null];
    }
    return [strtolower(trim($m[1])), isset($m[2]) && $m[2] !== '' ? strtolower(trim($m[2])) : null];
}

function handle_renomear_categoria($usuario, $dados, $texto_original = '') {
    $antiga = normalizar_categoria($dados['categoria_antiga'] ?? '');
    $nova   = isset($dados['categoria_nova']) ? normalizar_categoria($dados['categoria_nova']) : null;

    if (!$antiga && $texto_original) {
        [$antiga_texto, $nova_texto] = extrair_renomeacao_categoria($texto_original);
        $antiga = normalizar_categoria($antiga_texto ?? '');
        $nova   = $nova ?? ($nova_texto !== null ? normalizar_categoria($nova_texto) : null);
    }

    // "altera a categoria DESSE GASTO" não é renomear a categoria "desse gasto" — é mudar a categoria de um gasto
    // (variação da IA vista nos testes). Manda pra edição de transação, que usa o contexto pra achar qual.
    if (preg_match('/^(d?ess[ea]|d?est[ea]|d[oa]\s+[uú]ltim[oa]|[uú]ltim[oa]|dele|dela)(\s+(gasto|despesa|transa[çc][aã]o|receita))?$|^(d[oa]\s+)?(gasto|despesa)$/u', $antiga)) {
        return handle_editar_valor_transacao($usuario, array_filter(['campo' => 'categoria', 'categoria_nova' => $nova]), $texto_original);
    }

    if (!$antiga) {
        salvar_estado($usuario['id'], 'aguardando_qual_categoria_corrigir', ['categoria_nova' => $nova]);
        return "Qual categoria você quer corrigir?";
    }

    if (!$nova) {
        salvar_estado($usuario['id'], 'aguardando_nome_categoria_nova', ['categoria_antiga' => $antiga]);
        return sprintf("Certo, \"%s\" vira o quê?", $antiga);
    }

    return aplicar_renomeacao_categoria($usuario['id'], $antiga, $nova);
}

function aplicar_renomeacao_categoria($usuario_id, $antiga, $nova) {
    $pdo = get_db();
    $stmt = $pdo->prepare("UPDATE transacoes SET categoria = ? WHERE usuario_id = ? AND categoria = ?");
    $stmt->execute([$nova, $usuario_id, $antiga]);
    $afetadas = $stmt->rowCount();

    // Também corrige o que já tinha sido aprendido por estabelecimento, se for o caso
    $pdo->prepare("UPDATE estabelecimentos SET categoria = ? WHERE usuario_id = ? AND categoria = ?")
        ->execute([$nova, $usuario_id, $antiga]);

    if ($afetadas > 0) {
        return sprintf("Prontinho! Mudei %d gasto(s) de \"%s\" pra \"%s\". ✅", $afetadas, rotulo_categoria($antiga), rotulo_categoria($nova));
    }

    // Não achou nada com esse nome de CATEGORIA — talvez seja o nome de um ESTABELECIMENTO que ele quer corrigir
    $stmt2 = $pdo->prepare("SELECT id FROM estabelecimentos WHERE usuario_id = ? AND nome = ?");
    $stmt2->execute([$usuario_id, $antiga]);
    $estab = $stmt2->fetch();

    if ($estab) {
        $pdo->prepare("UPDATE estabelecimentos SET nome = ? WHERE id = ?")->execute([$nova, $estab['id']]);
        $pdo->prepare("UPDATE transacoes SET descricao = ? WHERE usuario_id = ? AND descricao = ?")
            ->execute([$nova, $usuario_id, $antiga]);
        return sprintf("Prontinho! Corrigi o nome \"%s\" pra \"%s\" (era um estabelecimento, não categoria). ✅", $antiga, $nova);
    }

    // Nem categoria nem estabelecimento aprendido — talvez seja só o texto solto na descrição do gasto
    $stmt3 = $pdo->prepare("SELECT COUNT(*) as total FROM transacoes WHERE usuario_id = ? AND LOWER(descricao) = LOWER(?)");
    $stmt3->execute([$usuario_id, $antiga]);
    if ($stmt3->fetch()['total'] > 0) {
        $pdo->prepare("UPDATE transacoes SET descricao = ? WHERE usuario_id = ? AND LOWER(descricao) = LOWER(?)")
            ->execute([$nova, $usuario_id, $antiga]);
        return sprintf("Prontinho! Corrigi \"%s\" pra \"%s\" na descrição do gasto. ✅", $antiga, $nova);
    }

    return sprintf("Não achei nenhum gasto nem estabelecimento com o nome \"%s\". 🤔", $antiga);
}

// No banco, gasto sem categoria fica como 'pendente' — o usuário chama isso de "sem categoria"/"gasto solto"
function normalizar_categoria($texto) {
    $c = mb_strtolower(trim($texto ?? '', " .,!?\"'"));
    if (in_array($c, ['sem categoria', 'gasto solto', 'gastos soltos', 'solto', 'soltos', 'pendente'], true)) return 'pendente';
    // Categorias padrão ficam sem acento no banco (é o que o botão manda) — "saúde" digitado e o botão "Saúde" caem na mesma
    return ['saúde' => 'saude', 'educação' => 'educacao'][$c] ?? $c;
}

function rotulo_categoria($categoria) {
    // No banco fica sem acento (é o que o botão manda); na tela, com acento
    return ['pendente' => 'sem categoria', 'saude' => 'saúde', 'educacao' => 'educação'][$categoria] ?? $categoria;
}

// "remove a conta cartão de crédito" — conta criada por engano. Gastos que estavam nela ficam registrados, só sem conta.
function handle_apagar_conta($usuario, $dados) {
    $bruta = trim($dados['conta'] ?? '');
    if ($bruta === '') {
        $contas = listar_nomes_contas($usuario['id']);
        return $contas ? "Qual conta você quer remover? Tenho: " . implode(', ', array_map('ucfirst', $contas)) . "." : "Você não tem nenhuma conta cadastrada. 👀";
    }
    $nome = normalizar_nome_conta($usuario['id'], $bruta);
    if (!buscar_conta_conhecida($usuario['id'], $nome)) {
        // Nome parcial: "cartão de crédito" acha "cartão" (e vice-versa)
        $alvo = sem_acentos($bruta);
        $nome = null;
        foreach (get_db()->query("SELECT nome FROM contas WHERE usuario_id = " . (int) $usuario['id'])->fetchAll(PDO::FETCH_COLUMN) as $c) {
            if (str_contains($alvo, sem_acentos($c)) || str_contains(sem_acentos($c), $alvo)) { $nome = $c; break; }
        }
        if (!$nome) {
            return sprintf("Não achei a conta \"%s\". 🤔 Manda \"quanto tenho em cada conta?\" pra ver os nomes.", $bruta);
        }
    }
    $pdo = get_db();
    $pdo->prepare("DELETE FROM saldos_iniciais WHERE usuario_id = ? AND conta = ?")->execute([$usuario['id'], $nome]);
    $pdo->prepare("DELETE FROM contas WHERE usuario_id = ? AND nome = ?")->execute([$usuario['id'], $nome]);
    $stmt = $pdo->prepare("UPDATE transacoes SET conta = NULL WHERE usuario_id = ? AND conta = ?");
    $stmt->execute([$usuario['id'], $nome]);
    $movidos = $stmt->rowCount();
    $pdo->prepare("UPDATE usuarios SET ultima_conta = NULL WHERE id = ? AND ultima_conta = ?")->execute([$usuario['id'], $nome]);

    return sprintf("🗑️ Removi a conta \"%s\" e o saldo inicial dela.%s", ucfirst($nome),
        $movidos ? sprintf(" Os %d registros que estavam nela continuam salvos, só ficaram sem conta.", $movidos) : '');
}

function handle_listar_categorias($usuario, $dados) {
    $pdo = get_db();
    $sql = "SELECT categoria, moeda, COUNT(*) as qtd, SUM(valor) as total FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto'";
    $params = [$usuario['id']];
    $rotulo_periodo = '';
    if (!empty($dados['periodo'])) {
        [$inicio, $fim] = calcular_intervalo_periodo($dados['periodo']);
        $sql .= " AND data BETWEEN ? AND ?";
        array_push($params, $inicio, $fim);
        $rotulo_periodo = sprintf(" (%s a %s)", date('d/m', strtotime($inicio)), date('d/m', strtotime($fim)));
    }
    $sql .= " GROUP BY categoria, moeda ORDER BY categoria = 'pendente', categoria";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $por_categoria = [];
    foreach ($stmt->fetchAll() as $linha) {
        $c = $linha['categoria'];
        $por_categoria[$c]['qtd'] = ($por_categoria[$c]['qtd'] ?? 0) + $linha['qtd'];
        $por_categoria[$c]['totais'][] = formatar_dinheiro($linha['total'], $linha['moeda']);
    }

    if (!$por_categoria) {
        return "Ainda não tenho nenhum gasto registrado{$rotulo_periodo} pra te mostrar as categorias. 👀";
    }

    $texto = "🏷️ Suas categorias{$rotulo_periodo}:\n";
    foreach ($por_categoria as $categoria => $info) {
        $texto .= sprintf("• %s — %d gasto%s — %s\n", ucfirst(rotulo_categoria($categoria)), $info['qtd'], $info['qtd'] == 1 ? '' : 's', implode(' + ', $info['totais']));
    }
    return trim($texto);
}

// Apagar uma categoria NÃO apaga os gastos — eles continuam registrados, só ficam sem categoria
function handle_apagar_categoria($usuario, $dados) {
    $categoria = normalizar_categoria($dados['categoria'] ?? '');
    if ($categoria === '') {
        salvar_estado($usuario['id'], 'aguardando_qual_categoria_apagar', []);
        return "Qual categoria você quer apagar?";
    }
    if ($categoria === 'pendente') {
        return "Gasto sem categoria não é bem uma categoria 😅 Se quiser dar uma categoria pra eles, manda \"altera a categoria sem categoria pra X\".";
    }

    $pdo = get_db();
    $stmt = $pdo->prepare("UPDATE transacoes SET categoria = 'pendente' WHERE usuario_id = ? AND tipo = 'gasto' AND categoria = ?");
    $stmt->execute([$usuario['id'], $categoria]);
    $afetados = $stmt->rowCount();

    $stmt2 = $pdo->prepare("DELETE FROM estabelecimentos WHERE usuario_id = ? AND categoria = ?");
    $stmt2->execute([$usuario['id'], $categoria]);

    if ($afetados === 0 && $stmt2->rowCount() === 0) {
        return sprintf("Não achei nenhuma categoria \"%s\". 🤔 Manda \"lista categorias\" pra ver as que existem.", $categoria);
    }

    if ($afetados === 0) {
        return sprintf("🗑️ Apaguei a categoria \"%s\" (nenhum gasto usava ela).", $categoria);
    }
    $gastos = $afetados === 1 ? 'O gasto dela continua registrado, só ficou' : "Os {$afetados} gastos dela continuam registrados, só ficaram";
    return sprintf(
        "🗑️ Apaguei a categoria \"%s\". %s sem categoria. Se quiser juntar com outra, da próxima vez manda \"altera a categoria %s pra X\".",
        $categoria, $gastos, $categoria
    );
}

function handle_log_income($usuario, $dados) {
    $valor      = $dados['valor'] ?? null;
    $moeda      = $dados['moeda'] ?? null;
    $descricao  = $dados['descricao'] ?? '';
    $data       = $dados['data'] ?? date('Y-m-d');
    $conta_nome = $dados['conta'] ?? null;

    if ($valor === null) {
        salvar_estado($usuario['id'], 'aguardando_valor_receita', compact('moeda', 'descricao', 'data', 'conta_nome'));
        return "Quanto foi que você recebeu? 💰";
    }

    return continuar_receita($usuario, $valor, $moeda, $descricao, $data, $conta_nome);
}

function continuar_receita($usuario, $valor, $moeda, $descricao, $data, $conta_nome) {
    $moeda = $moeda ?? $usuario['moeda_padrao'] ?? 'EUR';

    if (!$conta_nome) {
        if (!usa_contas($usuario)) {
            return salvar_receita_sem_conta($usuario, $valor, $moeda, $descricao, $data);
        }
        salvar_estado($usuario['id'], 'aguardando_conta_receita', compact('valor', 'moeda', 'descricao', 'data'));
        return pergunta_conta($usuario, "Boa! Em qual conta isso caiu? 💰");
    }

    return resolver_conta_e_salvar_receita($usuario, $valor, $moeda, $descricao, $data, $conta_nome, false);
}

function salvar_receita_sem_conta($usuario, $valor, $moeda, $descricao, $data) {
    get_db()->prepare("INSERT INTO transacoes (usuario_id, valor, moeda, tipo, categoria, descricao, data, conta) VALUES (?, ?, ?, 'receita', 'receita', ?, ?, NULL)")
        ->execute([$usuario['id'], $valor, $moeda, $descricao, $data]);
    limpar_estado($usuario['id']);
    return sprintf("Boa! Recebi aqui: +%s 💰", formatar_dinheiro($valor, $moeda));
}

// Bancos e carteiras comuns em Portugal e no Brasil (pra não perguntar "O que é millenium?")
function eh_banco_conhecido($nome) {
    return (bool) preg_match('/\b(mill?enn?ium|bcp|wise|revolut|nubank|nu|inter|itau|bradesco|santander|caixa|cgd|bpi|novo\s+banco|activo\s?bank|moey|n26|montepio|c6|picpay|mercado\s+pago|paypal|mb\s?way|banco\s+do\s+brasil|bb|sicoob|sicredi|neon|next|btg|xp|credito\s+agricola|bankinter|abanca|universo|openbank|monzo|bunq|dinheiro|carteira|poupanca|banco)\b/u', sem_acentos($nome));
}

// $conta_confirmada = true quando o nome veio como resposta direta a "em qual conta?" (não precisa perguntar de novo o que é)
function resolver_conta_e_salvar_receita($usuario, $valor, $moeda, $descricao, $data, $conta_nome, $conta_confirmada) {
    $conta_nome = normalizar_nome_conta($usuario['id'], $conta_nome); // corrige typo antes de checar se já é conhecida
    $conhecida = buscar_conta_conhecida($usuario['id'], $conta_nome);

    // Banco/carteira que todo mundo conhece não precisa de "o que é isso?"
    if (!$conhecida && !$conta_confirmada && eh_banco_conhecido($conta_nome)) {
        $conta_confirmada = true;
    }
    if (!$conhecida && !$conta_confirmada) {
        salvar_estado($usuario['id'], 'aguardando_confirmacao_conta', compact('valor', 'moeda', 'descricao', 'data', 'conta_nome'));
        return sprintf("O que é \"%s\"? (banco, carteira digital, etc — só pra eu reconhecer sozinho da próxima vez)", $conta_nome);
    }

    $conta_nome = salvar_conta($usuario['id'], $conta_nome);

    $pdo = get_db();
    $pdo->prepare("INSERT INTO transacoes (usuario_id, valor, moeda, tipo, categoria, descricao, data, conta) VALUES (?, ?, ?, 'receita', 'receita', ?, ?, ?)")
        ->execute([$usuario['id'], $valor, $moeda, $descricao, $data, $conta_nome]);
    $pdo->prepare("UPDATE usuarios SET ultima_conta = ? WHERE id = ?")->execute([$conta_nome, $usuario['id']]);

    limpar_estado($usuario['id']);
    return sprintf("Boa! Recebi aqui: +%s na conta %s 💰", formatar_dinheiro($valor, $moeda), ucfirst($conta_nome));
}

// Tira um número e, se der, a moeda de uma resposta curta tipo "900" ou "900 euros"
function extrair_valor_e_moeda($texto) {
    $moeda = null;
    if (preg_match('/\b(eur|euro|euros|€)\b/i', $texto)) $moeda = 'EUR';
    elseif (preg_match('/\b(r\$|real|reais|brl)\b/i', $texto)) $moeda = 'BRL';
    elseif (preg_match('/(\$|dolar|dólar|dolares|dólares|usd|dollar)/i', $texto)) $moeda = 'USD';

    $valor = null;
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $texto, $m)) {
        $valor = (float) str_replace(',', '.', $m[1]);
    }

    return [$valor, $moeda];
}

// Continua uma conversa em etapas (usuário respondendo valor, conta, ou o que é uma conta nova)
function handle_continuacao_estado($usuario, $texto) {
    $estado = $usuario['estado'];
    $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];

    if ($estado === 'aguardando_valor_gasto') {
        [$valor, $moeda_detectada] = extrair_valor_e_moeda($texto);
        if ($valor === null) {
            return "Não consegui entender o valor 🤔 Manda só o número, tipo \"20\" ou \"20 euros\".";
        }
        $moeda = $moeda_detectada ?? ($s['moeda'] ?? null);

        // Veio do "Paguei" de uma conta sem valor: guarda o valor na conta pra próxima vez não perguntar
        $nota = '';
        if (!empty($s['lembrete_id'])) {
            $stmt = get_db()->prepare("UPDATE lembretes SET valor = ?, moeda = COALESCE(moeda, ?) WHERE id = ? AND usuario_id = ? AND valor IS NULL");
            $stmt->execute([$valor, $moeda ?? $usuario['moeda_padrao'] ?? 'EUR', $s['lembrete_id'], $usuario['id']]);
            if ($stmt->rowCount()) {
                $nota = sprintf("\n\n💡 Guardei %s como valor dessa conta — da próxima vez o \"Paguei\" já registra direto.", formatar_dinheiro($valor, $moeda ?? $usuario['moeda_padrao'] ?? 'EUR'));
            }
        }
        return avancar_gasto($usuario, $valor, $moeda, $s['estabelecimento'] ?? null, $s['categoria'] ?? null, $s['descricao'] ?? '', $s['data'] ?? date('Y-m-d'), $s['conta'] ?? null) . $nota;
    }

    if ($estado === 'aguardando_conta_varios') {
        limpar_estado($usuario['id']);
        $ids = array_map('intval', $s['ids'] ?? []);
        $tipo = interpretar_resposta_conta($texto);
        if ($tipo === 'desligar') {
            definir_modo_contas($usuario['id'], MODO_SIMPLES);
            return "👍 Beleza, ficam sem conta — e a partir de agora não pergunto mais. (Se mudar de ideia: \"quero controlar por conta\".)";
        }
        if ($tipo === 'sem' || !$ids) {
            return "👍 Beleza, ficam sem conta.";
        }
        $conta = salvar_conta($usuario['id'], $tipo === 'dinheiro' ? 'dinheiro' : limpar_nome_conta($texto));
        if (empty($usuario['modo_contas'])) definir_modo_contas($usuario['id'], MODO_CONTAS);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        get_db()->prepare("UPDATE transacoes SET conta = ? WHERE usuario_id = ? AND id IN ($marcadores)")->execute(array_merge([$conta, $usuario['id']], $ids));
        get_db()->prepare("UPDATE usuarios SET ultima_conta = ? WHERE id = ?")->execute([$conta, $usuario['id']]);
        $moeda = $usuario['moeda_padrao'] ?? 'EUR';
        return sprintf("✅ Marquei a conta %s nos %d gastos.\n💳 Saldo %s: %s", ucfirst($conta), count($ids), ucfirst($conta), formatar_dinheiro(calcular_saldo_conta($usuario['id'], $conta, $moeda), $moeda));
    }

    if ($estado === 'aguardando_meta_aporte') {
        limpar_estado($usuario['id']);
        [$meta] = achar_meta($usuario['id'], $texto);
        if (!$meta) {
            return "Não achei essa meta 🤔 Manda \"minhas metas\" pra ver os nomes.";
        }
        return registrar_aporte_meta($usuario, $meta, (float) ($s['valor'] ?? 0));
    }

    if ($estado === 'aguardando_nome_conta_fixa') {
        limpar_estado($usuario['id']);
        return handle_cadastrar_conta_fixa($usuario, array_merge($s, ['texto_lembrete' => trim($texto)]));
    }

    if ($estado === 'aguardando_dia_conta_fixa') {
        if (!preg_match('/\b(\d{1,2})\b/', $texto, $m) || (int) $m[1] < 1 || (int) $m[1] > 31) {
            return "Não entendi o dia 🤔 Manda só o número do dia (de 1 a 31).";
        }
        limpar_estado($usuario['id']);
        return handle_cadastrar_conta_fixa($usuario, array_merge($s, ['dia_mes' => (int) $m[1]]));
    }

    if ($estado === 'aguardando_valor_lembrete') {
        limpar_estado($usuario['id']);
        [$valor, $moeda] = extrair_valor_e_moeda($texto);
        if ($valor === null) {
            return "Beleza, fica sem valor fixo — quando tocar em \"Paguei\" eu pergunto quanto foi. 👍";
        }
        $moeda = $moeda ?? $usuario['moeda_padrao'] ?? 'EUR';
        get_db()->prepare("UPDATE lembretes SET valor = ?, moeda = ?, eh_conta = 1 WHERE id = ? AND usuario_id = ?")
            ->execute([$valor, $moeda, $s['lembrete_id'] ?? 0, $usuario['id']]);
        return sprintf("Anotado! %s é %s — quando tocar em \"Paguei\" eu já registro direto. 👍", ucfirst(conta_com_artigo($s['texto'] ?? 'a conta')), formatar_dinheiro($valor, $moeda));
    }

    if ($estado === 'aguardando_conta_gasto') {
        $tipo_resposta = interpretar_resposta_conta($texto);
        $nota = '';
        if ($tipo_resposta === 'desligar') {
            definir_modo_contas($usuario['id'], MODO_SIMPLES);
            $nota = "\n\n👍 Beleza, a partir de agora não pergunto mais a conta. (Se mudar de ideia: \"quero controlar por conta\".)";
        }
        $conta = match (true) {
            in_array($tipo_resposta, ['desligar', 'sem'], true)              => null,
            $tipo_resposta === 'dinheiro'                                    => 'dinheiro',
            eh_confirmacao_afirmativa($texto) && !empty($usuario['ultima_conta']) => $usuario['ultima_conta'],
            default                                                          => limpar_nome_conta($texto),
        };
        return continuar_gasto($usuario, $s['valor'], $s['moeda'] ?? null, $s['estabelecimento'] ?? null, $s['categoria'] ?? null, $s['descricao'] ?? '', $s['data'] ?? date('Y-m-d'), $conta) . $nota;
    }

    if ($estado === 'aguardando_valor_receita') {
        [$valor, $moeda_detectada] = extrair_valor_e_moeda($texto);
        if ($valor === null) {
            return "Não consegui entender o valor 🤔 Manda só o número, tipo \"900\" ou \"900 euros\".";
        }
        $moeda = $moeda_detectada ?? ($s['moeda'] ?? null);
        return continuar_receita($usuario, $valor, $moeda, $s['descricao'] ?? '', $s['data'] ?? date('Y-m-d'), $s['conta_nome'] ?? null);
    }

    if ($estado === 'aguardando_conta_receita') {
        $tipo_resposta = interpretar_resposta_conta($texto);
        if ($tipo_resposta === 'desligar' || $tipo_resposta === 'sem') {
            $nota = '';
            if ($tipo_resposta === 'desligar') {
                definir_modo_contas($usuario['id'], MODO_SIMPLES);
                $nota = "\n\n👍 Beleza, a partir de agora não pergunto mais a conta. (Se mudar de ideia: \"quero controlar por conta\".)";
            }
            return salvar_receita_sem_conta($usuario, $s['valor'], $s['moeda'], $s['descricao'] ?? '', $s['data'] ?? date('Y-m-d')) . $nota;
        }
        $conta_nome = $tipo_resposta === 'dinheiro' ? 'dinheiro' : limpar_nome_conta($texto);
        if (empty($usuario['modo_contas'])) {
            definir_modo_contas($usuario['id'], MODO_CONTAS);
        }
        return resolver_conta_e_salvar_receita($usuario, $s['valor'], $s['moeda'], $s['descricao'] ?? '', $s['data'] ?? date('Y-m-d'), $conta_nome, true);
    }

    if ($estado === 'aguardando_confirmacao_conta') {
        salvar_conta($usuario['id'], $s['conta_nome']);
        $pdo = get_db();
        $pdo->prepare("INSERT INTO transacoes (usuario_id, valor, moeda, tipo, categoria, descricao, data, conta) VALUES (?, ?, ?, 'receita', 'receita', ?, ?, ?)")
            ->execute([$usuario['id'], $s['valor'], $s['moeda'], $s['descricao'] ?? '', $s['data'] ?? date('Y-m-d'), strtolower(trim($s['conta_nome']))]);
        limpar_estado($usuario['id']);
        return sprintf(
            "Entendi, \"%s\" vou lembrar como conta a partir de agora. 😊\nBoa! Recebi aqui: +%s na conta %s 💰",
            $s['conta_nome'], formatar_dinheiro($s['valor'], $s['moeda']), ucfirst($s['conta_nome'])
        );
    }

    if ($estado === 'aguardando_nome_categoria_nova') {
        $resposta = aplicar_renomeacao_categoria($usuario['id'], $s['categoria_antiga'], normalizar_categoria($texto));
        limpar_estado($usuario['id']);
        return $resposta;
    }

    if ($estado === 'aguardando_qual_categoria_corrigir') {
        $antiga = normalizar_categoria($texto);
        $nova = $s['categoria_nova'] ?? null;
        limpar_estado($usuario['id']);
        if (!$nova) {
            salvar_estado($usuario['id'], 'aguardando_nome_categoria_nova', ['categoria_antiga' => $antiga]);
            return sprintf("Certo, \"%s\" vira o quê?", $antiga);
        }
        return aplicar_renomeacao_categoria($usuario['id'], $antiga, $nova);
    }

    if ($estado === 'aguardando_qual_apagar') {
        limpar_estado($usuario['id']);
        [$valor_detectado] = extrair_valor_e_moeda($texto);
        $alvo = ['tipo' => $s['tipo'] ?? null] + ALVO_PADRAO;
        if ($valor_detectado !== null && preg_match('/^\s*[\d.,]+\s*$/', $texto)) {
            $alvo['valor'] = $valor_detectado; // texto era só o número — usa como valor
        } else {
            $alvo['termo'] = trim($texto);
        }
        return executar_delecao_transacao($usuario['id'], $alvo);
    }

    if ($estado === 'aguardando_dados_edicao') {
        $alvo     = ($s['alvo'] ?? []) + ALVO_PADRAO;
        $mudancas = $s['mudancas'] ?? [];
        $campo    = $s['campo'] ?? null;
        limpar_estado($usuario['id']);

        [$valor_detectado] = extrair_valor_e_moeda($texto);
        $texto_e_so_numero = $valor_detectado !== null && preg_match('/^\s*[\d.,]+\s*(€|euros?|reais|r\$)?\s*$/ui', $texto);

        if (alvo_vazio($alvo)) {
            // Ainda não sabíamos qual transação — essa resposta identifica ela
            if ($texto_e_so_numero) { $alvo['valor'] = $valor_detectado; } else { $alvo['termo'] = trim($texto); }
            return fluxo_edicao_transacao($usuario, $alvo, $mudancas, $campo, $s['estabelecimento'] ?? null);
        }

        // Já sabíamos qual — essa resposta diz O QUE mudar ("categoria") ou é o valor novo em si
        if (!$campo && ($campo_escolhido = interpretar_campo_edicao($texto))) {
            return fluxo_edicao_transacao($usuario, $alvo, [], $campo_escolhido, $s['estabelecimento'] ?? null);
        }

        if ($campo === 'categoria' && !empty($s['estabelecimento'])) {
            $categoria = normalizar_categoria($texto);
            salvar_estabelecimento($usuario['id'], $s['estabelecimento'], $categoria);
            return executar_edicao_transacao($usuario['id'], $alvo, ['categoria' => $categoria])
                . sprintf("\n😊 E vou lembrar que \"%s\" é %s a partir de agora.", $s['estabelecimento'], $categoria);
        }

        switch ($campo) {
            case 'categoria':
                $mudancas['categoria'] = normalizar_categoria($texto);
                break;
            case 'conta':
                $mudancas['conta'] = trim($texto);
                break;
            case 'valor':
                if ($valor_detectado === null) {
                    salvar_estado($usuario['id'], 'aguardando_dados_edicao', $s);
                    return "Não entendi o valor 🤔 Manda só o número, tipo \"12\" ou \"12,50\".";
                }
                $mudancas['valor'] = $valor_detectado;
                break;
            case 'data':
                $data = interpretar_data_simples($texto);
                if (!$data) {
                    salvar_estado($usuario['id'], 'aguardando_dados_edicao', $s);
                    return "Não entendi a data 🤔 Tenta \"ontem\", \"25/09\" ou \"sexta\".";
                }
                $mudancas['data'] = $data;
                break;
            case 'descricao':
                $mudancas['descricao'] = trim($texto);
                break;
            default:
                if ($texto_e_so_numero) { $mudancas['valor'] = $valor_detectado; } else { $mudancas['descricao'] = trim($texto); }
        }

        return executar_edicao_transacao($usuario['id'], $alvo, $mudancas);
    }

    if ($estado === 'aguardando_quando_lembrete') {
        $disparar_em = detectar_tempo_relativo($texto) ?? hora_no_dia_guardado($s, $texto) ?? quando_da_frase($texto) ?? resolver_data_hora_livre($texto);
        $data_parte  = $disparar_em ? substr($disparar_em, 0, 10) : null;
        if (!$disparar_em || validar_data_ou_invalida($data_parte) === false) {
            return "Ainda não entendi quando 🤔 Tenta algo tipo \"daqui 10 minutos\" ou \"amanhã às 9h\".";
        }

        limpar_estado($usuario['id']);
        return salvar_lembrete($usuario['id'], $s['texto'], $disparar_em, $s['recorrente'] ?? null, $s['intervalo_horas'] ?? null, null, $s['extras'] ?? []);
    }

    if ($estado === 'aguardando_qual_lembrete_editar') {
        limpar_estado($usuario['id']);
        return handle_editar_lembrete($usuario, array_merge($s, ['termo' => trim($texto)]));
    }

    if ($estado === 'aguardando_mudanca_lembrete') {
        limpar_estado($usuario['id']);
        $lembrete = get_db()->prepare("SELECT * FROM lembretes WHERE id = ? AND usuario_id = ? AND status = 'pendente'");
        $lembrete->execute([$s['lembrete_id'] ?? 0, $usuario['id']]);
        $lembrete = $lembrete->fetch();
        if (!$lembrete) {
            return "Esse lembrete não está mais marcado 🤔";
        }
        // Se a resposta for um horário, muda o horário; se não, é o texto novo
        $quando = detectar_tempo_relativo($texto) ?? hora_no_dia_guardado($s, $texto) ?? quando_da_frase($texto) ?? resolver_data_hora_livre($texto);
        $mudancas = $quando ? ['disparar_em_novo' => $quando] : ['texto_novo' => trim($texto)];
        return aplicar_edicao_lembrete($usuario['id'], $lembrete, $mudancas);
    }

    if ($estado === 'aguardando_qual_lembrete_cancelar') {
        limpar_estado($usuario['id']);
        return handle_cancelar_lembrete($usuario, ['termo' => trim($texto)]);
    }

    if ($estado === 'aguardando_conta_levantamento') {
        limpar_estado($usuario['id']);
        return handle_levantamento($usuario, $s + ['conta_origem' => limpar_nome_conta($texto)]);
    }

    if ($estado === 'aguardando_qual_categoria_apagar') {
        limpar_estado($usuario['id']);
        return handle_apagar_categoria($usuario, ['categoria' => trim($texto)]);
    }

    return handle_unknown($usuario, [], $texto);
}

// Confere se uma data no formato YYYY-MM-DD existe de verdade no calendário (ex: barra "31/09" não existe)
// Retorna: a própria data se for válida, null se não veio nada, false se veio algo mas é inválido.
function validar_data_ou_invalida($data_str) {
    if (!$data_str) return null;
    $partes = explode('-', $data_str);
    if (count($partes) !== 3) return false;
    [$ano, $mes, $dia] = array_map('intval', $partes);
    return checkdate($mes, $dia, $ano) ? $data_str : false;
}

// Calcula a data de início e fim de um período, usando o calendário de verdade
// (mês = do dia 1 até hoje; mês passado = do dia 1 ao último dia do mês anterior)
function calcular_intervalo_periodo($periodo) {
    $hoje = date('Y-m-d');

    // Mês específico no formato "YYYY-MM" (ex: usuário pediu "agosto")
    if (preg_match('/^\d{4}-\d{2}$/', $periodo ?? '')) {
        return [$periodo . '-01', date('Y-m-t', strtotime($periodo . '-01'))];
    }

    switch ($periodo) {
        case 'hoje':
            return [$hoje, $hoje];
        case 'semana':
            return [date('Y-m-d', strtotime('-7 days')), $hoje];
        case 'mes_passado':
            return [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))];
        case 'mes':
        default:
            return [date('Y-m-01'), $hoje];
    }
}

function handle_query_spending($usuario, $dados) {
    $pdo = get_db();
    [$inicio, $fim] = calcular_intervalo_periodo($dados['periodo'] ?? 'mes');
    $categoria = $dados['categoria'] ?? null;

    $sql = "SELECT moeda, SUM(valor) as total FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND data BETWEEN ? AND ?";
    $params = [$usuario['id'], $inicio, $fim];
    if ($categoria) {
        $sql .= " AND categoria = ?";
        $params[] = strtolower(trim($categoria));
    }
    $sql .= " GROUP BY moeda";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    $rotulo = $categoria ? " em \"{$categoria}\"" : '';

    if (!$linhas) {
        return "Não achei nenhum gasto{$rotulo} nesse período. 👀";
    }

    $partes = [];
    foreach ($linhas as $linha) {
        $partes[] = formatar_dinheiro($linha['total'], $linha['moeda']);
    }

    return "Até agora você gastou{$rotulo} " . implode(' + ', $partes) . " nesse período. 👀";
}

function handle_list_expenses($usuario, $dados) {
    $pdo = get_db();
    [$inicio, $fim] = calcular_intervalo_periodo($dados['periodo'] ?? 'mes');
    $categoria = $dados['categoria'] ?? null;

    $sql = "SELECT valor, moeda, categoria, descricao, data FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND data BETWEEN ? AND ?";
    $params = [$usuario['id'], $inicio, $fim];
    if ($categoria) {
        $sql .= " AND categoria = ?";
        $params[] = normalizar_categoria($categoria);
    }
    $sql .= " ORDER BY data DESC, id DESC LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    if (!$linhas) {
        return "Não achei nenhum gasto registrado nesse período. 👀";
    }

    $texto = "Seus gastos recentes:\n";
    foreach ($linhas as $linha) {
        $data_formatada = date('d/m', strtotime($linha['data']));
        $simbolo = simbolo_moeda($linha['moeda']);
        $desc = $linha['descricao'] ? ' — ' . $linha['descricao'] : '';
        $texto .= sprintf("• %s — %s (%s)%s\n", $data_formatada, formatar_dinheiro($linha['valor'], $linha['moeda']), rotulo_categoria($linha['categoria']), $desc);
    }

    return trim($texto);
}

function handle_saldo($usuario, $dados) {
    $pdo = get_db();
    $stmt = $pdo->prepare("
        SELECT moeda,
               SUM(CASE WHEN tipo = 'receita' THEN valor ELSE 0 END) as total_receitas,
               SUM(CASE WHEN tipo = 'gasto' THEN valor ELSE 0 END) as total_gastos
        FROM transacoes
        WHERE usuario_id = ?
        GROUP BY moeda
    ");
    $stmt->execute([$usuario['id']]);
    $linhas = $stmt->fetchAll();

    $stmt2 = $pdo->prepare("SELECT moeda, SUM(valor) as total FROM saldos_iniciais WHERE usuario_id = ? GROUP BY moeda");
    $stmt2->execute([$usuario['id']]);
    $iniciais = [];
    foreach ($stmt2->fetchAll() as $row) {
        $iniciais[$row['moeda']] = (float) $row['total'];
    }

    if (!$linhas && !$iniciais) {
        return "Ainda não tenho nada registrado pra calcular seu saldo. 👀";
    }

    $partes = [];
    $moedas_vistas = [];
    foreach ($linhas as $linha) {
        $saldo = $linha['total_receitas'] - $linha['total_gastos'] + ($iniciais[$linha['moeda']] ?? 0);
        $partes[] = formatar_dinheiro($saldo, $linha['moeda']);
        $moedas_vistas[$linha['moeda']] = true;
    }
    foreach ($iniciais as $moeda => $valor) {
        if (!isset($moedas_vistas[$moeda])) {
            $partes[] = formatar_dinheiro($valor, $moeda);
        }
    }

    // Quem usa contas pode abrir o detalhe com um toque (ou dizendo "detalha")
    if (count(listar_nomes_contas($usuario['id'])) > 1) {
        botoes_resposta([[botao_texto('🏦 Ver por conta', 'saldo por conta')]]);
    }
    return "Seu saldo (receitas - gastos): " . implode(' + ', $partes) . " 💰";
}

function handle_list_income($usuario, $dados) {
    $pdo = get_db();
    [$inicio, $fim] = calcular_intervalo_periodo($dados['periodo'] ?? 'mes');

    $stmt = $pdo->prepare("SELECT valor, moeda, descricao, data, conta FROM transacoes WHERE usuario_id = ? AND tipo = 'receita' AND data BETWEEN ? AND ? ORDER BY data DESC, id DESC LIMIT 20");
    $stmt->execute([$usuario['id'], $inicio, $fim]);
    $linhas = $stmt->fetchAll();

    if (!$linhas) {
        return "Não achei nenhuma receita registrada nesse período. 👀";
    }

    $texto = "Suas receitas recentes:\n";
    foreach ($linhas as $linha) {
        $data_formatada = date('d/m', strtotime($linha['data']));
        $simbolo = simbolo_moeda($linha['moeda']);
        $conta = $linha['conta'] ? " (" . ucfirst($linha['conta']) . ")" : '';
        $desc = $linha['descricao'] ?: 'receita';
        $texto .= sprintf("• %s — +%s%s — %s\n", $data_formatada, formatar_dinheiro($linha['valor'], $linha['moeda']), $conta, $desc);
    }

    return trim($texto);
}

// Reflete receitas + transferências + saldo inicial + gastos (só os que têm conta marcada) de cada conta.
// Gastos sem conta definida não entram aqui — por isso o total geral (handle_saldo) é sempre a fonte confiável;
// isso aqui é o detalhamento, que fica mais preciso conforme você for dizendo de qual conta cada gasto saiu.
function handle_saldo_por_conta($usuario, $dados) {
    $pdo = get_db();
    $stmt = $pdo->prepare("
        SELECT conta, moeda, SUM(valor) as total FROM (
            SELECT conta, moeda, valor FROM saldos_iniciais WHERE usuario_id = ?
            UNION ALL
            SELECT conta, moeda, valor FROM transacoes WHERE usuario_id = ? AND tipo = 'receita' AND conta IS NOT NULL
            UNION ALL
            SELECT conta, moeda, -valor FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND conta IS NOT NULL
            UNION ALL
            SELECT conta_destino as conta, moeda, valor FROM transferencias WHERE usuario_id = ?
            UNION ALL
            SELECT conta_origem as conta, moeda, -valor FROM transferencias WHERE usuario_id = ?
        ) t
        GROUP BY conta, moeda
        ORDER BY conta
    ");
    $stmt->execute([$usuario['id'], $usuario['id'], $usuario['id'], $usuario['id'], $usuario['id']]);
    $linhas = $stmt->fetchAll();

    if (!$linhas) {
        return "Ainda não tenho nada registrado com conta pra te mostrar isso. 👀";
    }

    $tem_gasto_sem_conta = $pdo->prepare("SELECT 1 FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND conta IS NULL LIMIT 1");
    $tem_gasto_sem_conta->execute([$usuario['id']]);
    $aviso = $tem_gasto_sem_conta->fetch() ? "\n\n(alguns gastos ainda não têm conta marcada, então isso pode não bater 100% com o total geral)" : '';

    $texto = "Saldo por conta:\n";
    foreach ($linhas as $linha) {
        $texto .= sprintf("• %s: %s\n", ucfirst($linha['conta']), formatar_dinheiro($linha['total'], $linha['moeda']));
    }

    return trim($texto) . $aviso;
}

// Se há uma pergunta pendente que vai ficar pra trás porque o usuário mudou de assunto,
// devolve um lembrete gentil em vez de simplesmente descartar (e perder o rascunho junto).
function obter_lembrete_pendente($usuario) {
    if (!empty($usuario['aguardando_transacao_id'])) {
        $estab = $usuario['aguardando_estabelecimento'];
        return $estab
            ? sprintf("Ah, e não cheguei a saber a categoria de \"%s\" — como você mudou de assunto, vou deixar esse gasto sem categoria mesmo (dá pra categorizar depois com \"lista gastos\" + apontar qual).", $estab)
            : "Ah, e não cheguei a saber a categoria daquele outro gasto — vai ficar sem categoria, já que você mudou de assunto.";
    }

    if (!empty($usuario['estado'])) {
        $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];
        switch ($usuario['estado']) {
            case 'aguardando_confirmacao_conta':
                return sprintf("Ah, e não cheguei a saber o que é \"%s\" — vou descartar aquela receita, já que você mudou de assunto. Se quiser registrar, manda de novo.", $s['conta_nome'] ?? '');
            case 'aguardando_conta_receita':
                return "Ah, e não cheguei a saber em qual conta caiu aquela outra receita — vou descartar ela, já que você mudou de assunto. Se quiser, manda de novo.";
            case 'aguardando_valor_receita':
                return "Ah, e não cheguei a saber o valor daquela outra receita — vou descartar ela, já que você mudou de assunto. Se quiser, manda de novo.";
            case 'aguardando_valor_gasto':
                return "Ah, e não cheguei a saber o valor daquele outro gasto — vou descartar ele, já que você mudou de assunto. Se quiser, manda de novo.";
            case 'aguardando_conta_gasto':
                return "Ah, e não cheguei a saber em qual conta saiu aquele outro gasto — vou descartar ele, já que você mudou de assunto. Se quiser, manda de novo.";
            case 'aguardando_nome_categoria_nova':
                return "Ah, e não cheguei a saber o novo nome daquela categoria que você queria corrigir — deixei como estava.";
            case 'aguardando_qual_categoria_corrigir':
                return "Ah, e não cheguei a saber qual categoria você queria corrigir — deixei como estava.";
            case 'aguardando_qual_apagar':
                return "Ah, e cancelei aquele pedido de apagar, já que você mudou de assunto — se ainda quiser, é só pedir de novo.";
            case 'aguardando_dados_edicao':
            case 'aguardando_qual_lembrete_editar':
            case 'aguardando_mudanca_lembrete':
                return "Ah, e cancelei aquela correção que você tinha começado, já que você mudou de assunto — se ainda quiser corrigir, é só pedir de novo.";
            case 'aguardando_quando_lembrete':
                return "Ah, e não cheguei a saber quando era aquele lembrete — não marquei, já que você mudou de assunto. Se quiser, manda de novo.";
            case 'aguardando_qual_lembrete_cancelar':
                return "Ah, e não cheguei a saber qual lembrete você queria cancelar — deixei todos como estavam.";
            case 'aguardando_qual_categoria_apagar':
                return "Ah, e não cheguei a saber qual categoria você queria apagar — deixei como estava.";
            case 'aguardando_valor_lembrete':
                return ''; // era só um "se quiser, me diz o valor" — não vale avisar que ficou pra trás
            case 'aguardando_conta_varios':
                return "Ah, e aqueles gastos ficaram sem conta, já que você mudou de assunto — estão registrados do mesmo jeito.";
            case 'aguardando_meta_aporte':
                return "Ah, e não cheguei a saber pra qual meta era aquele valor — não anotei. Se quiser, manda de novo dizendo a meta.";
            case 'aguardando_nome_conta_fixa':
            case 'aguardando_dia_conta_fixa':
                return "Ah, e não terminei de cadastrar aquele gasto fixo, já que você mudou de assunto — se quiser, manda de novo.";
            case 'aguardando_confirmacao_apagar':
                return sprintf("Ah, e não apaguei %s, já que você mudou de assunto.", $s['descricao'] ?? 'aquela transação');
            case 'aguardando_conta_levantamento':
                return "Ah, e não anotei aquele levantamento, já que não cheguei a saber de qual conta saiu — se quiser, manda de novo (\"levantei 20 do millennium\").";
        }
    }

    return '';
}

// Critérios pra achar UMA transação: id (já identificada), valor, termo (descrição ou categoria),
// categoria atual (ex: "o de 8 euros SEM CATEGORIA"), tipo (gasto/receita) e/ou "ultimo" (a mais recente)
const ALVO_PADRAO = ['id' => null, 'valor' => null, 'termo' => null, 'categoria' => null, 'tipo' => null, 'ultimo' => false];

function extrair_alvo_transacao($dados, $campo_valor = 'valor_antigo') {
    $tipo = in_array($dados['tipo'] ?? null, ['gasto', 'receita'], true) ? $dados['tipo'] : null;
    return [
        'valor'     => $dados[$campo_valor] ?? null,
        'termo'     => trim($dados['termo'] ?? '') ?: null,
        'categoria' => !empty($dados['categoria']) ? normalizar_categoria($dados['categoria']) : null,
        'tipo'      => $tipo,
        'ultimo'    => !empty($dados['ultimo']),
    ] + ALVO_PADRAO;
}

function alvo_vazio($alvo) {
    $alvo += ALVO_PADRAO;
    return !$alvo['id'] && $alvo['valor'] === null && !$alvo['termo'] && !$alvo['categoria'] && !$alvo['ultimo'];
}

// "Não achei" já mostrando os últimos registros — pra pessoa ver o valor/nome certo sem ter que pedir a lista
function mensagem_nao_achei($usuario_id, $acao, $tipo = null) {
    $sql = "SELECT id, valor, moeda, tipo, descricao, categoria, data FROM transacoes WHERE usuario_id = ?" . ($tipo ? " AND tipo = ?" : '') . " ORDER BY id DESC LIMIT 5";
    $stmt = get_db()->prepare($sql);
    $stmt->execute($tipo ? [$usuario_id, $tipo] : [$usuario_id]);
    $linhas = $stmt->fetchAll();

    if (!$linhas) {
        return "Não achei nada pra {$acao} — ainda não tem nada registrado. 👀";
    }
    $lista = implode("\n", array_map(fn($r) => sprintf("• %s — %s (%s)%s",
        date('d/m', strtotime($r['data'])), formatar_dinheiro($r['valor'], $r['moeda']),
        rotulo_categoria($r['categoria']), $r['descricao'] ? ' — ' . $r['descricao'] : ''), $linhas));
    return "Não achei essa transação pra {$acao}. 🤔 Os últimos registros são:\n{$lista}\n\nMe diz qual (pelo valor ou pelo nome).";
}

function descrever_transacao($row) {
    return sprintf(
        "%s de %s (%s, %s)",
        $row['tipo'] === 'receita' ? 'a receita' : 'o gasto',
        formatar_dinheiro($row['valor'], $row['moeda']),
        $row['descricao'] ?: rotulo_categoria($row['categoria']),
        date('d/m', strtotime($row['data']))
    );
}

// Acha a transação mais recente que bate com o alvo (termo procura na descrição e na categoria).
// Com $tolerante, se valor + termo juntos não acharem nada, tenta só pelo valor — o termo que a pessoa usa às vezes
// é a categoria antiga ou um apelido que não está escrito em lugar nenhum. Pra APAGAR isso não é usado (arriscado demais).
function buscar_transacao_alvo($usuario_id, $alvo, $tolerante = true) {
    $alvo += ALVO_PADRAO;
    $pdo = get_db();

    if ($alvo['id']) {
        $stmt = $pdo->prepare("SELECT id, valor, moeda, tipo, descricao, categoria, data, conta FROM transacoes WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$alvo['id'], $usuario_id]);
        return $stmt->fetch() ?: null;
    }

    $tentativas = [[$alvo['valor'], $alvo['termo']]];
    if ($tolerante && $alvo['valor'] !== null && $alvo['termo']) {
        $tentativas[] = [$alvo['valor'], null];
    }

    foreach ($tentativas as [$v, $t]) {
        $sql = "SELECT id, valor, moeda, tipo, descricao, categoria, data, conta FROM transacoes WHERE usuario_id = ?";
        $params = [$usuario_id];
        if ($alvo['categoria']) {
            $sql .= " AND categoria = ?";
            $params[] = $alvo['categoria'];
        }
        if ($alvo['tipo']) {
            $sql .= " AND tipo = ?";
            $params[] = $alvo['tipo'];
        }
        if ($v !== null) {
            $sql .= " AND valor = ?";
            $params[] = $v;
        }
        if ($t) {
            $sql .= " AND (descricao LIKE ? OR categoria LIKE ?)";
            $params[] = '%' . $t . '%';
            $params[] = '%' . normalizar_categoria($t) . '%';
        }
        $sql .= " ORDER BY id DESC LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($row = $stmt->fetch()) {
            return $row;
        }
    }
    return null;
}

function handle_deletar_transacao($usuario, $dados, $texto_original = '') {
    // (sempre pede confirmação antes de apagar, então usar o contexto aqui é seguro)
    $alvo = completar_alvo_pelo_contexto($usuario, extrair_alvo_transacao($dados, 'valor'), $texto_original);

    if (alvo_vazio($alvo)) {
        salvar_estado($usuario['id'], 'aguardando_qual_apagar', ['tipo' => $alvo['tipo']]);
        return "Qual gasto/receita você quer apagar? Me diz o valor ou uma palavra-chave.";
    }

    return executar_delecao_transacao($usuario['id'], $alvo);
}

// Não apaga direto: mostra qual transação achou e pede confirmação (a busca pega a mais recente que bate, pode não ser a certa)
function executar_delecao_transacao($usuario_id, $alvo) {
    $row = buscar_transacao_alvo($usuario_id, $alvo, false);

    if (!$row) {
        return mensagem_nao_achei($usuario_id, 'apagar', $alvo['tipo'] ?? null);
    }

    $descricao = descrever_transacao($row);
    salvar_estado($usuario_id, 'aguardando_confirmacao_apagar', ['transacao_id' => $row['id'], 'descricao' => $descricao]);
    botoes_resposta([[botao_texto('🗑️ Sim, apaga', 'sim'), botao_texto('Não', 'não')]]);

    return sprintf("Apago %s? 🗑️ (sim/não)", $descricao);
}

// true = sim, false = não, null = não é uma resposta de sim/não
function resposta_sim_nao($texto) {
    $t = mb_strtolower(trim($texto, " .,!?\n\t"));
    if (preg_match('/^(n[aã]o|n|nope|no|cancela|cancelar|deixa|esquece|para|nem)(?![\p{L}])/u', $t)) {
        return false;
    }
    if (eh_confirmacao_afirmativa($t)
        || preg_match('/^(sim|pode|apaga|apague|confirm|beleza|blz|fechou|claro|manda\s+ver|bora|perfeito|isso|ok|yes|ya|s)(?![\p{L}])/u', $t)) {
        return true;
    }
    return null;
}

// Resposta ao "Apago ...? (sim/não)". Devolve null se a mensagem não for sim/não (aí o webhook trata como pedido novo).
function resolver_confirmacao_apagar($usuario, $texto) {
    $decisao = resposta_sim_nao($texto);
    if ($decisao === null) {
        return null;
    }

    $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];
    limpar_estado($usuario['id']);

    if ($decisao === false) {
        return "Beleza, não apaguei nada. 👍";
    }

    // Lista inteira ("apaga a lista de filmes")
    if (!empty($s['lista_id'])) {
        get_db()->prepare("DELETE FROM lista_itens WHERE lista_id = ? AND usuario_id = ?")->execute([$s['lista_id'], $usuario['id']]);
        $stmt = get_db()->prepare("DELETE FROM listas WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$s['lista_id'], $usuario['id']]);
        return $stmt->rowCount() ? "🗑️ Apaguei " . ($s['descricao'] ?? 'a lista') . "." : "Essa lista já não existia mais. 🤔";
    }

    // Vários lembretes de uma vez ("apaga a consulta do dia 22 e a do dia 23")
    if (!empty($s['lembrete_ids'])) {
        $ids = array_map('intval', $s['lembrete_ids']);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = get_db()->prepare("UPDATE lembretes SET status = 'cancelado' WHERE usuario_id = ? AND status = 'pendente' AND id IN ($marcadores)");
        $stmt->execute(array_merge([$usuario['id']], $ids));
        return $stmt->rowCount() ? "🗑️ Cancelei {$stmt->rowCount()} lembrete(s)." : "Esses lembretes já não existiam mais. 🤔";
    }

    $stmt = get_db()->prepare("DELETE FROM transacoes WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$s['transacao_id'] ?? 0, $usuario['id']]);

    return $stmt->rowCount()
        ? sprintf("🗑️ Apaguei %s.", $s['descricao'] ?? 'a transação')
        : "Essa transação já não existia mais. 🤔";
}

// Junta o que a IA mandou mudar numa transação: valor, descricao, categoria, data, conta
function extrair_mudancas_transacao($dados) {
    $m = [];
    if (isset($dados['valor_novo']) && $dados['valor_novo'] !== '') $m['valor'] = (float) $dados['valor_novo'];
    if (!empty($dados['descricao_nova']))  $m['descricao'] = trim($dados['descricao_nova']);
    if (!empty($dados['categoria_nova']))  $m['categoria'] = normalizar_categoria($dados['categoria_nova']);
    if (!empty($dados['data_nova']))       $m['data'] = $dados['data_nova'];
    if (!empty($dados['conta_nova']))      $m['conta'] = $dados['conta_nova'];
    return $m;
}

const CAMPOS_EDICAO = ['valor', 'descricao', 'categoria', 'data', 'conta'];

function handle_editar_valor_transacao($usuario, $dados, $texto_original = '') {
    $campo = in_array($dados['campo'] ?? null, CAMPOS_EDICAO, true) ? $dados['campo'] : null;
    return fluxo_edicao_transacao($usuario, completar_alvo_pelo_contexto($usuario, extrair_alvo_transacao($dados), $texto_original), extrair_mudancas_transacao($dados), $campo);
}

// A IA às vezes não marca "ultimo" em "altera a categoria DESSE gasto". Se ela não identificou nenhuma transação:
// 1) um gasto esperando categoria agora é o "esse"; 2) "esse/este/último/dele" na frase = a transação mais recente.
function completar_alvo_pelo_contexto($usuario, $alvo, $texto_original) {
    if (!alvo_vazio($alvo)) {
        return $alvo;
    }
    if (!empty($usuario['aguardando_transacao_id'])) {
        return ['id' => (int) $usuario['aguardando_transacao_id']] + ALVO_PADRAO;
    }
    if (preg_match('/\b(d?ess[ea]|d?est[ea]|[uú]ltim[oa]|dele|dela|isso)\b/ui', $texto_original)) {
        $alvo['ultimo'] = true;
    }
    return $alvo;
}

// Edição em etapas: 1) acha a transação  2) descobre o que mudar (pergunta o campo certo, com botões)  3) aplica
function fluxo_edicao_transacao($usuario, $alvo, $mudancas, $campo = null, $estabelecimento = null) {
    if (alvo_vazio($alvo)) {
        salvar_estado($usuario['id'], 'aguardando_dados_edicao', ['alvo' => $alvo, 'mudancas' => $mudancas, 'campo' => $campo]);
        return "Qual transação você quer editar? Me diz o valor ou uma palavra-chave.";
    }

    // Já confere se a transação existe antes de perguntar o que mudar — e trava nela pelo id
    $row = buscar_transacao_alvo($usuario['id'], $alvo);
    if (!$row) {
        return mensagem_nao_achei($usuario['id'], 'editar', $alvo['tipo'] ?? null);
    }
    $alvo = ['id' => $row['id']] + ALVO_PADRAO;

    if ($mudancas) {
        return executar_edicao_transacao($usuario['id'], $alvo, $mudancas);
    }

    // Se esse gasto é o que estava esperando categoria de um lugar novo ("chinês"), a resposta também ensina o lugar
    if (!$estabelecimento && (int) ($usuario['aguardando_transacao_id'] ?? 0) === (int) $row['id']) {
        $estabelecimento = $usuario['aguardando_estabelecimento'] ?? null;
    }

    salvar_estado($usuario['id'], 'aguardando_dados_edicao', ['alvo' => $alvo, 'mudancas' => [], 'campo' => $campo, 'estabelecimento' => $estabelecimento]);
    $desc = descrever_transacao($row);

    switch ($campo) {
        case 'categoria':
            botoes_resposta(botoes_categorias($usuario['id']));
            return "Qual a categoria nova d{$desc}? 🏷️";
        case 'conta':
            $contas = listar_nomes_contas($usuario['id']);
            if ($contas) botoes_resposta(array_chunk(array_map(fn($c) => botao_texto(ucfirst($c), $c), $contas), 3));
            return "De qual conta saiu {$desc}? 💳";
        case 'data':
            return "Qual a data certa d{$desc}? 📅 (ex: \"ontem\", \"25/09\", \"sexta\")";
        case 'valor':
            return "Qual o valor certo d{$desc}? 💸";
        case 'descricao':
            return "Qual o nome certo d{$desc}? ✏️";
    }

    botoes_resposta([
        [botao_texto('Valor', 'valor'), botao_texto('Nome', 'nome'), botao_texto('Categoria', 'categoria')],
        [botao_texto('Data', 'data'), botao_texto('Conta', 'conta')],
    ]);
    return "O que corrijo n{$desc}?";
}

// "categoria", "a data", "o nome" -> campo de edição
function interpretar_campo_edicao($texto) {
    $t = mb_strtolower(trim($texto, " .,!?"));
    $t = preg_replace('/^(a|o|na|no|mudar|muda|altera|corrige)\s+/u', '', $t);
    return match (true) {
        (bool) preg_match('/^categori/u', $t)                  => 'categoria',
        (bool) preg_match('/^(data|dia)$/u', $t)               => 'data',
        (bool) preg_match('/^(valor|pre[çc]o|quantia)$/u', $t) => 'valor',
        (bool) preg_match('/^(nome|descri[çc][ãa]o)$/u', $t)   => 'descricao',
        (bool) preg_match('/^(conta|banco|cart[ãa]o)$/u', $t)  => 'conta',
        default                                                => null,
    };
}

// "ontem", "anteontem", "hoje", "25/09", "25/09/2026", "2026-09-25", "sexta" -> Y-m-d (ou null)
function interpretar_data_simples($texto) {
    $t = mb_strtolower(trim($texto, " .,!?"));
    if ($t === 'hoje') return date('Y-m-d');
    if ($t === 'ontem') return date('Y-m-d', strtotime('-1 day'));
    if ($t === 'anteontem') return date('Y-m-d', strtotime('-2 days'));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $t : null;
    }
    if (preg_match('/^(?:dia\s+)?(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?$/u', $t, $m)) {
        $ano = isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]) : (int) date('Y');
        if (!isset($m[3]) && sprintf('%04d-%02d-%02d', $ano, $m[2], $m[1]) > date('Y-m-d')) {
            $ano--; // "25/12" dito em setembro = dezembro passado (gasto é sempre no passado)
        }
        return checkdate((int) $m[2], (int) $m[1], $ano) ? sprintf('%04d-%02d-%02d', $ano, $m[2], $m[1]) : null;
    }
    return detectar_dia_semana($t, 'passado');
}

function executar_edicao_transacao($usuario_id, $alvo, $mudancas) {
    if (isset($mudancas['data']) && !validar_data_ou_invalida($mudancas['data'])) {
        return sprintf("\"%s\" não existe no calendário 🤔 Me diz a data certa.", $mudancas['data']);
    }

    $row = buscar_transacao_alvo($usuario_id, $alvo);
    if (!$row) {
        return mensagem_nao_achei($usuario_id, 'editar', $alvo['tipo'] ?? null);
    }

    if (isset($mudancas['conta'])) {
        $mudancas['conta'] = salvar_conta($usuario_id, limpar_nome_conta($mudancas['conta']));
    }

    $novo = array_merge($row, $mudancas);
    get_db()->prepare("UPDATE transacoes SET valor = ?, descricao = ?, categoria = ?, data = ?, conta = ? WHERE id = ?")
        ->execute([$novo['valor'], $novo['descricao'], $novo['categoria'], $novo['data'], $novo['conta'], $row['id']]);

    $partes = [];
    if (isset($mudancas['valor']))     $partes[] = sprintf("valor pra %s", formatar_dinheiro($mudancas['valor'], $row['moeda']));
    if (isset($mudancas['descricao'])) $partes[] = sprintf("nome pra \"%s\"", $mudancas['descricao']);
    if (isset($mudancas['categoria'])) $partes[] = sprintf("categoria pra %s", rotulo_categoria($mudancas['categoria']));
    if (isset($mudancas['data']))      $partes[] = sprintf("data pra %s", date('d/m/Y', strtotime($mudancas['data'])));
    if (isset($mudancas['conta']))     $partes[] = sprintf("conta pra %s", ucfirst($mudancas['conta']));

    // Mudou valor/categoria/data de um gasto: mostra como ficou o orçamento
    $orcamento = array_intersect_key($mudancas, array_flip(['valor', 'categoria', 'data'])) ? linhas_orcamento_apos_gasto($usuario_id, $row['id']) : '';

    return sprintf("✅ Atualizei %s: %s.", descrever_transacao($row), implode(' e ', $partes)) . $orcamento;
}

// Corrige a conta de um gasto que JÁ foi registrado antes, sem criar um gasto novo/duplicado
function handle_definir_conta_gasto($usuario, $dados) {
    $valor = $dados['valor'] ?? null;
    $termo = $dados['termo'] ?? null;
    $conta = $dados['conta'] ?? null;

    if (!$conta || ($valor === null && !$termo)) {
        return "Preciso saber qual gasto (o valor ou uma palavra-chave) e de qual conta saiu.";
    }

    $pdo = get_db();
    $sql = "SELECT id FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto'";
    $params = [$usuario['id']];
    if ($valor !== null) {
        $sql .= " AND valor = ?";
        $params[] = $valor;
    }
    if ($termo) {
        $sql .= " AND descricao LIKE ?";
        $params[] = '%' . $termo . '%';
    }
    $sql .= " ORDER BY id DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row) {
        return "Não achei esse gasto pra atualizar. 🤔";
    }

    $conta = salvar_conta($usuario['id'], $conta);
    $pdo->prepare("UPDATE transacoes SET conta = ? WHERE id = ?")->execute([$conta, $row['id']]);

    return sprintf("Prontinho! Marquei que esse gasto saiu da conta %s. ✅", ucfirst($conta));
}

// "tenho 700 no cartão de crédito" → "cartão de crédito" (quando a IA não preenche o campo conta)
function extrair_conta_do_texto($texto) {
    return preg_match('/\b(?:no|na|nos|nas|em)\s+(.+?)[.!?]*$/iu', trim($texto), $m) ? trim($m[1]) : null;
}

function handle_saldo_inicial($usuario, $dados, $texto_original = '') {
    // A IA às vezes põe a conta em outro campo (conta_destino...) ou nem põe — tenta de novo antes de perguntar
    if (empty($dados['contas']) && empty($dados['conta'])) {
        $dados['conta'] = $dados['conta_destino'] ?? $dados['conta_origem'] ?? ($texto_original !== '' ? extrair_conta_do_texto($texto_original) : null);
    }
    // Se o usuário mencionou mais de uma conta na mesma frase ("tenho 45 na wise e 30 na revolut"),
    // a IA devolve uma lista em "contas" — processa cada uma, sem perder nenhuma.
    if (!empty($dados['contas']) && is_array($dados['contas'])) {
        $confirmacoes = [];
        foreach ($dados['contas'] as $item) {
            $confirmacoes[] = salvar_saldo_inicial_unico($usuario, $item['valor'] ?? null, $item['moeda'] ?? null, $item['conta'] ?? null);
        }
        return implode("\n", $confirmacoes);
    }

    return salvar_saldo_inicial_unico($usuario, $dados['valor'] ?? null, $dados['moeda'] ?? null, $dados['conta'] ?? null);
}

function salvar_saldo_inicial_unico($usuario, $valor, $moeda, $conta) {
    $moeda = $moeda ?? $usuario['moeda_padrao'] ?? 'EUR';

    if ($valor === null) {
        return "Quanto você tinha? Ex: \"tenho 500 na wise\"" . (usa_contas($usuario) ? '' : ' ou só "tenho 800"') . ".";
    }

    // Sem conta: quem não usa contas (ou ainda não escolheu) guarda num saldo "geral"; quem usa contas precisa dizer qual
    $sem_conta = !$conta;
    if ($sem_conta) {
        if (($usuario['modo_contas'] ?? null) === MODO_CONTAS) {
            return "De qual conta? Ex: \"tenho 500 na wise\" ou \"tenho 50 em dinheiro\".";
        }
        $conta = 'geral';
    } elseif (empty($usuario['modo_contas'])) {
        definir_modo_contas($usuario['id'], MODO_CONTAS);
    }

    $conta = salvar_conta($usuario['id'], $conta);

    $pdo = get_db();
    $pdo->prepare("INSERT INTO saldos_iniciais (usuario_id, conta, moeda, valor) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute([$usuario['id'], $conta, $moeda, $valor]);

    if ($sem_conta) {
        return sprintf("Anotado! 📌 Vou considerar que você tinha %s quando a gente começou. Daqui pra frente é só me contar os gastos e receitas que eu mantenho o saldo.", formatar_dinheiro($valor, $moeda));
    }
    return sprintf("Anotado! 📌 Vou considerar que a conta %s já tinha %s antes da gente começar.", ucfirst($conta), formatar_dinheiro($valor, $moeda));
}

// Corrige/junta o nome de uma conta (ex: uma conta "Ya" criada por engano vira "Wise" de verdade,
// somando os saldos iniciais se as duas já tiverem valor guardado)
function handle_renomear_conta($usuario, $dados) {
    $antiga = strtolower(trim($dados['conta_antiga'] ?? ''));
    $nova   = strtolower(trim($dados['conta_nova'] ?? ''));

    if (!$antiga || !$nova) {
        return "Preciso saber o nome da conta atual e o nome novo. Ex: \"renomeia a conta Ya pra Wise\".";
    }

    if (!buscar_conta_conhecida($usuario['id'], $antiga)) {
        return sprintf("Não achei nenhuma conta chamada \"%s\". 🤔", $antiga);
    }

    $pdo = get_db();

    $pdo->prepare("UPDATE transacoes SET conta = ? WHERE usuario_id = ? AND conta = ?")->execute([$nova, $usuario['id'], $antiga]);
    $pdo->prepare("UPDATE transferencias SET conta_origem = ? WHERE usuario_id = ? AND conta_origem = ?")->execute([$nova, $usuario['id'], $antiga]);
    $pdo->prepare("UPDATE transferencias SET conta_destino = ? WHERE usuario_id = ? AND conta_destino = ?")->execute([$nova, $usuario['id'], $antiga]);

    // saldos_iniciais tem uma linha por (conta, moeda) — se a conta nova já tiver saldo, soma em vez de duplicar
    $stmt = $pdo->prepare("SELECT moeda, valor FROM saldos_iniciais WHERE usuario_id = ? AND conta = ?");
    $stmt->execute([$usuario['id'], $antiga]);
    foreach ($stmt->fetchAll() as $linha) {
        $pdo->prepare("INSERT INTO saldos_iniciais (usuario_id, conta, moeda, valor) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE valor = valor + VALUES(valor)")
            ->execute([$usuario['id'], $nova, $linha['moeda'], $linha['valor']]);
    }
    $pdo->prepare("DELETE FROM saldos_iniciais WHERE usuario_id = ? AND conta = ?")->execute([$usuario['id'], $antiga]);

    $pdo->prepare("DELETE FROM contas WHERE usuario_id = ? AND nome = ?")->execute([$usuario['id'], $antiga]);
    salvar_conta($usuario['id'], $nova);
    $pdo->prepare("UPDATE usuarios SET ultima_conta = ? WHERE id = ? AND ultima_conta = ?")->execute([$nova, $usuario['id'], $antiga]);

    return sprintf("Prontinho! Juntei tudo que estava em \"%s\" na conta \"%s\". ✅", $antiga, $nova);
}

// "detalha" sozinho depende do que veio antes: depois do saldo = saldo por conta; depois de "quanto gastei" = a lista
function atalho_detalha($usuario, $texto) {
    if (!preg_match('/^(?:me\s+)?(?:detalha|detalhe|detalhar|detalhado|detalhes|mais\s+detalhes|mostra\s+detalhado)(?:\s+(?:isso|melhor|mais|a[ií]|por\s+favor))?$/u', sem_acentos(trim($texto, " .!?\n\t")))) {
        return null;
    }
    return match ($usuario['ultimo_intent'] ?? null) {
        'saldo', 'saldo_inicial', 'transferencia', 'levantamento' => ['intent' => 'saldo_por_conta'],
        'query_spending'                                          => ['intent' => 'list_expenses', 'periodo' => 'mes'],
        default                                                   => null, // sem contexto claro: a IA decide
    };
}

// "levantei 20 euros do millenium" / "saquei 50" — levantar dinheiro não é gasto: sai do banco e vira dinheiro na mão
function atalho_levantamento($texto) {
    if (!preg_match('/^\s*(?:eu\s+)?(?:levantei|saquei|fiz\s+um\s+(?:levantamento|saque)\s+de)\s+(?:r\$|€|\$)?\s*(\d+(?:[.,]\d+)?)\s*(€|euros?|reais|r\$)?(?:\s+em\s+(?:dinheiro|esp[eé]cie|notas))?(?:\s+(?:d[oa]|n[oa]|de|em)\s+(?:minha\s+)?(?:conta\s+(?:d[oa]\s+)?)?(.+?))?(?:\s+em\s+(?:dinheiro|esp[eé]cie|notas))?\s*[.!]*$/iu', $texto, $m)) {
        return null;
    }
    $conta = trim($m[3] ?? '');
    // "no multibanco", "no caixa", "no banco" não dizem de qual conta saiu
    if (preg_match('/^(?:o\s+|a\s+)?(multibanco|mb|caixa|caixa\s+eletr[oô]nico|atm|banco|banco\s+24\s*h(?:oras)?)$/iu', $conta)) $conta = '';
    $moeda = isset($m[2]) && $m[2] !== '' ? (preg_match('/reais|r\$/i', $m[2]) ? 'BRL' : 'EUR') : null;
    return array_filter(['intent' => 'levantamento', 'valor' => (float) str_replace(',', '.', $m[1]), 'moeda' => $moeda, 'conta_origem' => $conta ?: null]);
}

function handle_levantamento($usuario, $dados) {
    $valor = (float) ($dados['valor'] ?? 0);
    $moeda = $dados['moeda'] ?? $usuario['moeda_padrao'] ?? 'EUR';
    if ($valor <= 0) return "Quanto você levantou? Ex: \"levantei 20 do millennium\".";

    $origem = $dados['conta_origem'] ?? null;
    if (!$origem) {
        if (($usuario['modo_contas'] ?? null) === MODO_SIMPLES || !listar_nomes_contas($usuario['id'])) {
            // Sem contas: o dinheiro só mudou de lugar, o saldo total é o mesmo
            return sprintf("💶 Levantar dinheiro não é gasto — os %s só saíram do banco pra sua mão, e o seu saldo continua igual.\nQuando gastar, é só me dizer (\"gastei 5 no café\").", formatar_dinheiro($valor, $moeda));
        }
        salvar_estado($usuario['id'], 'aguardando_conta_levantamento', ['valor' => $valor, 'moeda' => $moeda]);
        $contas = array_values(array_diff(listar_nomes_contas($usuario['id'], $usuario['ultima_conta'] ?? null), ['dinheiro']));
        botoes_resposta(array_chunk(array_map(fn($c) => botao_texto(ucfirst($c), $c), $contas), 3));
        return "De qual conta você levantou? 🏧 (toca numa ou escreve)";
    }

    $origem = salvar_conta($usuario['id'], $origem);
    if ($origem === 'dinheiro') return "Levantar de \"dinheiro\" pra dinheiro não muda nada 🙂 De qual conta do banco saiu?";
    $destino = salvar_conta($usuario['id'], 'dinheiro');
    get_db()->prepare("INSERT INTO transferencias (usuario_id, conta_origem, conta_destino, valor, moeda, data) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$usuario['id'], $origem, $destino, $valor, $moeda, date('Y-m-d')]);
    botoes_resposta([[botao_texto('🏦 Ver saldo por conta', 'saldo por conta')]]);
    return sprintf("💶 Levantamento anotado: %s saíram de %s e viraram Dinheiro.\nNão conta como gasto — quando gastar esse dinheiro, me diz (\"gastei 5 no café, em dinheiro\").",
        formatar_dinheiro($valor, $moeda), ucfirst($origem));
}

function handle_transferencia($usuario, $dados) {
    $valor  = $dados['valor'] ?? null;
    $moeda  = $dados['moeda'] ?? $usuario['moeda_padrao'] ?? 'EUR';
    $origem = $dados['conta_origem'] ?? null;
    $destino = $dados['conta_destino'] ?? null;
    $data   = $dados['data'] ?? date('Y-m-d');

    if ($valor === null || !$origem || !$destino) {
        return "Pra registrar a transferência preciso do valor, de qual conta saiu e pra qual foi. Ex: \"transferi 200 da revolut pro millennium\".";
    }

    $origem = salvar_conta($usuario['id'], $origem);
    $destino = salvar_conta($usuario['id'], $destino);

    $pdo = get_db();
    $pdo->prepare("INSERT INTO transferencias (usuario_id, conta_origem, conta_destino, valor, moeda, data) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$usuario['id'], $origem, $destino, $valor, $moeda, $data]);

    return sprintf("Feito! Movi %s de %s pra %s. 🔄", formatar_dinheiro($valor, $moeda), ucfirst($origem), ucfirst($destino));
}

function handle_buscar_transacao($usuario, $dados) {
    $termo = trim($dados['termo'] ?? '');
    if (!$termo) {
        return "O que você quer que eu busque? Me diz uma palavra-chave.";
    }

    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT valor, moeda, tipo, categoria, descricao, data, conta FROM transacoes WHERE usuario_id = ? AND (descricao LIKE ? OR conta LIKE ?) ORDER BY data DESC LIMIT 10");
    $stmt->execute([$usuario['id'], '%' . $termo . '%', '%' . $termo . '%']);
    $linhas = $stmt->fetchAll();

    if (!$linhas) {
        return sprintf("Não achei nada com \"%s\" registrado. 👀", $termo);
    }

    $texto = "Achei isso:\n";
    foreach ($linhas as $linha) {
        $data_formatada = date('d/m/Y', strtotime($linha['data']));
        $simbolo = simbolo_moeda($linha['moeda']);
        $sinal = $linha['tipo'] === 'receita' ? '+' : '-';
        $conta = $linha['conta'] ? " ({$linha['conta']})" : '';
        $texto .= sprintf("• %s — %s%s%s — %s\n", $data_formatada, $sinal, formatar_dinheiro($linha['valor'], $linha['moeda']), $conta, $linha['descricao']);
    }

    return trim($texto);
}

function formatar_data_hora_amigavel($datahora) {
    $ts = strtotime($datahora);
    if (date('Y-m-d') === date('Y-m-d', $ts)) {
        return "hoje às " . date('H:i', $ts);
    }
    if (date('Y-m-d', strtotime('+1 day')) === date('Y-m-d', $ts)) {
        return "amanhã às " . date('H:i', $ts);
    }
    return date('d/m', $ts) . " às " . date('H:i', $ts);
}

function rotulo_recorrencia($recorrente, $intervalo_horas = null, $formato = 'travessao', $dia_mes = null, $datahora = null) {
    if ($recorrente === 'horas') {
        $texto = ($intervalo_horas == 1) ? 'de hora em hora' : sprintf('a cada %d horas', $intervalo_horas ?: 1);
    } else {
        $dias_semana = ['todo domingo', 'toda segunda', 'toda terça', 'toda quarta', 'toda quinta', 'toda sexta', 'todo sábado'];
        $texto = match ($recorrente) {
            'diario'  => 'todo dia',
            'semanal' => $datahora ? $dias_semana[(int) date('w', strtotime($datahora))] : 'toda semana',
            'mensal'  => $dia_mes ? "todo mês no dia {$dia_mes}" . ($dia_mes > 28 && $formato !== 'parenteses' ? ' (ou no último dia, nos meses mais curtos)' : '') : 'todo mês',
            default   => null,
        };
    }

    if ($texto === null) return '';
    return $formato === 'parenteses' ? " ({$texto})" : " — {$texto}";
}

function normalizar_recorrencia($recorrente) {
    return in_array($recorrente, ['diario', 'semanal', 'mensal', 'horas'], true) ? $recorrente : null;
}

function handle_set_reminder($usuario, $dados) {
    $texto = $dados['texto_lembrete'] ?? null;
    if ($texto) $texto = preg_replace('/^(?:minhas?|meus?)\s+/iu', '', trim($texto)); // "minha reunião" -> "reunião"
    // "me avisa às 7" virou texto "avisa": é sobre o lembrete de que acabamos de falar
    if ($texto && preg_match('/^(?:me\s+)?(?:avisa|avise|avisar|lembra|lembre|lembrar|lembrete|isso|disso)$/u', sem_acentos(trim($texto)))) {
        $recente = lembrete_recente($usuario['id'], 30);
        if ($recente && !empty($dados['disparar_em']) && strlen($dados['disparar_em']) >= 16) {
            return avisar_lembrete_as($usuario, $recente, substr($dados['disparar_em'], 11, 5));
        }
        $texto = null;
    }
    if (!$texto) {
        // A próxima resposta curta ("reunião") vira o texto do lembrete, com o que já veio (data, hora...)
        salvar_estado($usuario['id'], 'aguardando_texto_lembrete', array_diff_key($dados, ['intent' => 1]));
        return "Do que você quer que eu te lembre?";
    }

    $recorrente = normalizar_recorrencia($dados['recorrente'] ?? null);
    $intervalo_horas = ($recorrente === 'horas') ? max(1, (int) ($dados['intervalo_horas'] ?? 1)) : null;
    $disparar_em = $dados['disparar_em'] ?? null;

    // "todo mês dia 31": o dia vem separado e a data da 1ª vez é calculada aqui (a IA inventaria 31/09, que não existe)
    $dia_mes = null;
    if ($recorrente === 'mensal' && !empty($dados['dia_mes'])) {
        $dia_mes = max(1, min(31, (int) $dados['dia_mes']));
        $hora = ($disparar_em && strlen($disparar_em) >= 16) ? substr($disparar_em, 11, 5) : '09:00';
        $disparar_em = substr(primeira_ocorrencia_mensal($dia_mes, $hora . ':00'), 0, 16);
    }

    $data_parte  = $disparar_em ? substr($disparar_em, 0, 10) : null;
    $valido      = $data_parte ? validar_data_ou_invalida($data_parte) : null;

    // Conta a pagar: valor/conta/categoria vêm junto (ex: "pagar o seguro todo dia 31, são 45 euros, sai da wise")
    $extras = [
        'valor'     => isset($dados['valor']) && is_numeric($dados['valor']) ? (float) $dados['valor'] : null,
        'moeda'     => $dados['moeda'] ?? ($usuario['moeda_padrao'] ?? 'EUR'),
        'conta'     => !empty($dados['conta']) ? salvar_conta($usuario['id'], $dados['conta']) : null,
        'categoria' => !empty($dados['categoria']) ? normalizar_categoria($dados['categoria']) : null,
        'dia_passou' => $dados['dia_passou'] ?? null,
        'antecedencia_min'  => isset($dados['antecedencia_min']) && is_numeric($dados['antecedencia_min']) ? max(0, (int) $dados['antecedencia_min']) : null,
        'tem_hora'          => $dados['tem_hora'] ?? null,
        'compromisso_frase' => !empty($dados['compromisso_frase']),
        'perguntar_mensal'  => !empty($dados['perguntar_mensal']),
    ];

    // "hoje" sem hora, e as 9h de hoje já passaram: pergunta a hora (antes ia parar em amanhã às 09:00)
    if (!empty($dados['hoje_sem_hora']) && !$recorrente && time() >= strtotime(date('Y-m-d') . ' 09:00:00')) {
        salvar_estado($usuario['id'], 'aguardando_quando_lembrete', ['texto' => $texto, 'recorrente' => null, 'intervalo_horas' => null, 'extras' => $extras, 'dia' => date('Y-m-d')]);
        botoes_resposta(botoes_hora_hoje());
        return sprintf("Hoje a que horas você quer que eu te lembre de \"%s\"? 🕐", $texto);
    }

    if (!$disparar_em || $valido === false) {
        salvar_estado($usuario['id'], 'aguardando_quando_lembrete', ['texto' => $texto, 'recorrente' => $recorrente, 'intervalo_horas' => $intervalo_horas, 'extras' => $extras]);
        return "Quando você quer que eu te lembre disso? 🕐 (ex: \"daqui 10 minutos\", \"amanhã às 9h\", \"sexta\")";
    }

    return salvar_lembrete($usuario['id'], $texto, $disparar_em, $recorrente, $intervalo_horas, $dia_mes, $extras);
}

// Botões pra "hoje a que horas?": daqui 1 hora + os próximos horários redondos que ainda não passaram
function botoes_hora_hoje() {
    $botoes = [botao_texto('Daqui 1 hora', 'daqui 1 hora')];
    foreach ([12, 15, 18, 20, 22] as $h) {
        if (count($botoes) < 4 && strtotime(date('Y-m-d') . " $h:00:00") > time() + 5400) {
            $botoes[] = botao_texto("Às {$h}h", "hoje às {$h}h");
        }
    }
    return array_chunk($botoes, 2);
}

// Resposta a "quando?" resolvida em PHP (sem IA): "dia 03", "dia 3 às 15h", "amanhã", "sexta às 10h", "às 18h", "28/10".
// Sem hora = 09:00; só a hora = hoje (se já passou, o salvar_lembrete joga pra amanhã). Null = não deu pra entender aqui.
function quando_da_frase($texto) {
    if ($relativo = detectar_tempo_relativo($texto)) return $relativo;
    $dia = data_da_frase($texto) ?? dia_relativo_da_frase($texto) ?? detectar_dia_semana($texto, 'futuro');
    // "03" sozinho não é hora; "dia 3" já foi pro $dia — tira ele antes de procurar a hora
    $hora = hora_da_frase(preg_replace('/\bdia\s+\d{1,2}(?:\/\d{1,2}(?:\/\d{2,4})?)?\b|\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/u', '', $texto));
    if ($dia) return "$dia " . ($hora ?? '09:00');
    if ($hora) return date('Y-m-d') . " $hora";
    return null;
}

// Resposta a "hoje a que horas?": "às 18h", "18h", "as 21" — no dia que ficou guardado (sem IA). Null = não é só uma hora.
function hora_no_dia_guardado($s, $texto) {
    if (empty($s['dia']) || detectar_tempo_relativo($texto)) return null;
    $hora = hora_da_frase($texto) ?? (preg_match('/^\s*(\d{1,2})\s*$/', $texto, $m) && (int) $m[1] < 24 ? sprintf('%02d:00', $m[1]) : null);
    return $hora ? "{$s['dia']} $hora" : null;
}

// "pagar o seguro do carro" / conta com valor -> é conta a pagar (ganha os botões Paguei/Amanhã/Não vou pagar)
function parece_conta_a_pagar($texto, $valor) {
    return $valor !== null || (bool) preg_match('/^(pagar|pago|paga)\b/ui', trim($texto));
}

// "pagar o seguro do carro" -> "seguro do carro" (vira a descrição do gasto quando paga)
function descricao_da_conta($texto) {
    return trim(preg_replace('/^(pagar|pago|paga)\s+(d?[oa]s?\s+)?/ui', '', trim($texto))) ?: $texto;
}

// "pagar a internet" -> "a internet" (mantém o artigo pra frase sair certa: "a internet é 45 euros")
function conta_com_artigo($texto) {
    return trim(preg_replace('/^(pagar|pago|paga)\s+/ui', '', trim($texto))) ?: $texto;
}

// Nunca grava lembrete no passado (o cron dispararia na hora): recorrente vai pra próxima repetição,
// e um único que caiu hoje numa hora que já passou ("às 8h" dito às 16h) vai pra amanhã.
function ajustar_para_futuro($disparar_em, $recorrente, $intervalo_horas = null, $dia_mes = null) {
    $disparar_em = substr($disparar_em, 0, 16) . ':00';
    if (strtotime($disparar_em) > time()) {
        return $disparar_em;
    }
    if ($recorrente) {
        return proxima_ocorrencia($disparar_em, $recorrente, $intervalo_horas, $dia_mes);
    }
    if (date('Y-m-d', strtotime($disparar_em)) === date('Y-m-d')) {
        return date('Y-m-d H:i:s', strtotime('+1 day', strtotime($disparar_em)));
    }
    // Dia que já passou ("consulta dia 28" dito no dia 29) = o dia 28 do mês que vem, mesma hora
    return primeira_ocorrencia_mensal((int) date('j', strtotime($disparar_em)), date('H:i:s', strtotime($disparar_em)));
}

function salvar_lembrete($usuario_id, $texto, $disparar_em, $recorrente, $intervalo_horas, $dia_mes = null, $extras = []) {
    if ($recorrente === 'mensal' && !$dia_mes) {
        $dia_mes = (int) date('j', strtotime($disparar_em));
    }
    $pedido = substr($disparar_em, 0, 10);
    $disparar_em = ajustar_para_futuro($disparar_em, $recorrente, $intervalo_horas, $dia_mes);
    // Único numa data que já tinha passado (não hoje): avisa que foi pro mês que vem
    $empurrado = !$recorrente && $pedido < date('Y-m-d') && substr($disparar_em, 0, 10) !== $pedido;
    $valor = $extras['valor'] ?? null;
    $eh_conta = parece_conta_a_pagar($texto, $valor);
    $pdo = get_db();

    // Compromisso (consulta, reunião...) avisa ANTES; tarefa (água, remédio, conta) avisa na hora
    $antecedencia = $extras['antecedencia_min'] ?? null;
    $tem_hora = $extras['tem_hora'] ?? (substr($disparar_em, 11, 5) !== '09:00');
    $compromisso = !$eh_conta && !eh_lembrete_agua($texto) && !in_array($recorrente, ['horas', 'diario'], true)
        && eh_compromisso($texto, $extras['compromisso_frase'] ?? false);

    // "me lembra da reunião do dia 15, 30 minutos antes" quando a reunião já está marcada: só ajusta o aviso dela
    if ($antecedencia !== null && !$recorrente && ($existente = lembrete_igual_no_dia($usuario_id, $texto, substr($disparar_em, 0, 10)))) {
        $hora_nova = $tem_hora ? substr($disparar_em, 0, 16) . ':00' : $existente['disparar_em'];
        $pdo->prepare("UPDATE lembretes SET antecedencia_min = ?, disparar_em = ? WHERE id = ?")->execute([$antecedencia, $hora_nova, $existente['id']]);
        marcar_lembrete_mexido($existente['id']);
        return confirmacao_antecedencia($existente['texto'], $hora_nova, $antecedencia);
    }
    // Daqui a pouco (menos de 2 h) não tem por que perguntar "1 dia antes?": avisa na hora
    $perguntar = $compromisso && $antecedencia === null && strtotime($disparar_em) - time() > 2 * 3600;
    if ($perguntar) {
        $antecedencia = $tem_hora ? 60 : 0; // padrão se não tocar em nada: 1 h antes (sem hora: no dia, às 9h)
    }

    $pdo->prepare("INSERT INTO lembretes (usuario_id, texto, disparar_em, recorrente, intervalo_horas, dia_mes, valor, moeda, categoria, conta, eh_conta, antecedencia_min, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente')")
        ->execute([$usuario_id, $texto, substr($disparar_em, 0, 16) . ':00', $recorrente, $intervalo_horas, $dia_mes, $valor, $extras['moeda'] ?? null, $extras['categoria'] ?? null, $extras['conta'] ?? null, $eh_conta ? 1 : 0, $antecedencia]);
    $id = (int) $pdo->lastInsertId();
    marcar_lembrete_mexido($id);

    if ($compromisso || $antecedencia) {
        $resposta = sprintf("Combinado! 🗓️ %s — %s%s.", ucfirst($texto), formatar_data_hora_amigavel($disparar_em),
            rotulo_recorrencia($recorrente, $intervalo_horas, 'travessao', $dia_mes, $disparar_em));
        if ($perguntar) {
            $resposta .= "\n⏰ " . frase_aviso($disparar_em, $antecedencia) . " Prefere outro momento?";
            botoes_resposta(botoes_antecedencia($id, $tem_hora));
        } else {
            $resposta .= "\n⏰ " . frase_aviso($disparar_em, $antecedencia);
        }
    } else {
        $resposta = sprintf("Combinado! 🔔 Te aviso sobre \"%s\"%s %s%s.",
            $texto,
            $valor !== null ? ' (' . formatar_dinheiro($valor, $extras['moeda'] ?? 'EUR') . ')' : '',
            formatar_data_hora_amigavel($disparar_em),
            rotulo_recorrencia($recorrente, $intervalo_horas, 'travessao', $dia_mes, $disparar_em)
        );
    }
    if ($empurrado || !empty($extras['dia_passou'])) {
        $resposta .= sprintf("\n📅 O dia %s deste mês já passou, então marquei pra %s. Se não era isso, é só dizer \"muda o lembrete %s para ...\".",
            $empurrado ? date('d/m', strtotime($pedido)) : $extras['dia_passou'], date('d/m', strtotime($disparar_em)), mb_strtolower($texto));
    }
    if ($eh_conta) {
        $resposta .= "\n💸 Como é uma conta, o aviso vem com o botão \"Paguei\" — um toque e eu já registro o gasto.";
        // Não disse "todo mês": fica só pra essa data, mas oferece virar conta mensal com um toque
        if (!$recorrente && !empty($extras['perguntar_mensal'])) {
            $resposta .= "\n🔁 É uma conta de todo mês? (se não tocar em nada, fica só dessa vez)";
            botoes_resposta([[['🔁 Sim, todo mês', "R:mensal:$id"], ['Só dessa vez', "R:unico:$id"]]]);
        }
        if ($valor === null) {
            // Fica esperando o valor: se a próxima mensagem for só um número, ele vira o valor da conta (e não um gasto novo)
            salvar_estado($usuario_id, 'aguardando_valor_lembrete', ['lembrete_id' => $id, 'texto' => $texto]);
            $resposta .= "\n\nQuanto é essa conta? Me diz o valor (se muda todo mês, pode ignorar — eu pergunto na hora de pagar).";
        }
    }
    return $resposta;
}

// ---------- Aviso com antecedência (compromissos) ----------
// disparar_em = horário do compromisso; o cron avisa em disparar_em - antecedencia_min.

// Consulta, reunião, dentista... (ou frase "tenho X dia Y" / "marquei X") — tarefas como remédio e água não entram
function eh_compromisso($texto, $frase_compromisso = false) {
    if ($frase_compromisso) return true;
    $t = sem_acentos($texto);
    if (preg_match('/\b(consulta|dentista|medic[oa]|reuniao|reunioes|evento|entrevista|voo|embarque|jantar|almoco|aniversario|festa|casamento|exame|call|meeting|compromisso|encontro|audiencia|vacina|cabeleireir[oa]|barbeiro|manicure|fisioterapia|fisio|terapia|psicolog[oa]|aula|prova|show|cinema|jogo|visita|palestra|workshop|webinar)\b/u', $t)) {
        return true;
    }
    // Erro de digitação nas palavras mais longas ("reunão", "consuta", "dentsta")
    preg_match_all('/\p{L}{5,}/u', $t, $m);
    foreach ($m[0] as $p) {
        foreach (['consulta', 'dentista', 'reuniao', 'entrevista', 'aniversario', 'casamento', 'compromisso', 'audiencia', 'fisioterapia', 'palestra'] as $k) {
            if (levenshtein($p, $k) <= 1) return true;
        }
    }
    return false;
}

// "30 minutos antes", "meia hora antes", "1h antes", "um dia antes", "na véspera" -> minutos (null se não pediu)
function detectar_antecedencia($texto) {
    $t = sem_acentos($texto);
    if (preg_match('/\bna vespera\b/u', $t)) return 1440;
    if (preg_match('/\bmeia\s+hora\s+antes\b/u', $t)) return 30;
    $numeros = ['um' => 1, 'uma' => 1, 'dois' => 2, 'duas' => 2, 'tres' => 3, 'quatro' => 4, 'cinco' => 5, 'dez' => 10, 'quinze' => 15, 'vinte' => 20, 'trinta' => 30, 'quarenta' => 40];
    if (preg_match('/\b(\d{1,3}|' . implode('|', array_keys($numeros)) . ')\s*(min\w*|h\w*|dias?)\s+(?:de\s+)?antes\b/u', $t, $m)) {
        $n = is_numeric($m[1]) ? (int) $m[1] : $numeros[$m[1]];
        return $n * match ($m[2][0]) { 'm' => 1, 'h' => 60, default => 1440 };
    }
    return null;
}

// Tira o pedaço "30 minutos antes" da frase (senão "2 horas antes" parece horário)
function sem_trecho_antecedencia($texto) {
    return preg_replace('/\b(?:na\s+v[eé]spera|meia\s+hora\s+antes|\S+\s*(?:min\w*|h\w*|dias?)\s+(?:de\s+)?antes)\b/iu', '', $texto);
}

// Horário dito na frase: "às 15h", "15:30", "15h30", "19 horas", "meio-dia" -> "HH:MM"
function hora_da_frase($texto) {
    if (preg_match('/\bmeio[\s-]dia\b/iu', $texto)) return '12:00';
    if (preg_match('/\b(\d{1,2})(?:\s*h(?:oras?)?\s*(\d{2})?\b|:(\d{2})\b)(\s*da\s+(?:tarde|noite))?/iu', $texto, $m) && (int) $m[1] < 24) {
        $h = (int) $m[1];
        if (!empty($m[4]) && $h < 12) $h += 12; // "3h da tarde" = 15:00
        return sprintf('%02d:%02d', $h, ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : 0));
    }
    // "as 10", "às 7", "às 3 da tarde" (sem "h") — só quando o número fecha a frase ou vem "da tarde"/"pra"/"com"...
    if (preg_match('/(?:^|\s)(?:[àa]s|a\s+partir\s+das|pelas)\s+(\d{1,2})(?=\s*(?:$|[,.!?;)]|e\s|da\s+(?:manh|tarde|noite)|pra\b|para\b|no\b|na\b|com\b))(\s*da\s+(tarde|noite))?/iu', $texto, $m) && (int) $m[1] < 24) {
        $h = (int) $m[1];
        if (!empty($m[3]) && $h < 12) $h += 12;
        return sprintf('%02d:00', $h);
    }
    return null;
}

function rotulo_antecedencia($min) {
    $min = (int) $min;
    if ($min <= 0) return 'na hora';
    if ($min % 1440 === 0) return ($min / 1440) . ($min === 1440 ? ' dia antes' : ' dias antes');
    if ($min % 60 === 0) return ($min / 60) . ' h antes';
    if ($min > 60) return intdiv($min, 60) . ' h ' . ($min % 60) . ' min antes';
    return "$min min antes";
}

// "Te aviso 1 h antes (hoje às 14:00)." / "Te aviso no dia, às 09:00."
function frase_aviso($disparar_em, $antecedencia) {
    if (!$antecedencia) {
        return "Te aviso " . (substr($disparar_em, 11, 5) === '09:00' ? 'no dia, às 09:00.' : 'na hora.');
    }
    $aviso = strtotime($disparar_em) - $antecedencia * 60;
    if ($aviso <= time()) return "Já tá perto — te aviso agora mesmo.";
    return sprintf("Te aviso %s (%s).", rotulo_antecedencia($antecedencia), formatar_data_hora_amigavel(date('Y-m-d H:i:s', $aviso)));
}

function confirmacao_antecedencia($texto, $disparar_em, $antecedencia) {
    return sprintf("👍 Combinado: \"%s\" é %s.\n⏰ %s", $texto, formatar_data_hora_amigavel($disparar_em), frase_aviso($disparar_em, $antecedencia));
}

function botoes_antecedencia($id, $tem_hora) {
    if (!$tem_hora) {
        return [[['No dia, às 9h', "V:0:$id"], ['1 dia antes', "V:1440:$id"]]];
    }
    return [
        [['Na hora', "V:0:$id"], ['30 min antes', "V:30:$id"]],
        [['1 h antes', "V:60:$id"], ['1 dia antes', "V:1440:$id"]],
    ];
}

// Mesmo compromisso no mesmo dia (pra não duplicar quando a pessoa só quer mudar o aviso)
function lembrete_igual_no_dia($usuario_id, $texto, $dia) {
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' AND DATE(disparar_em) = ? ORDER BY id DESC");
    $stmt->execute([$usuario_id, $dia]);
    $alvo = sem_acentos(trim($texto));
    foreach ($stmt->fetchAll() as $l) {
        $t = sem_acentos($l['texto']);
        if ($t === $alvo || str_contains($t, $alvo) || str_contains($alvo, $t) || levenshtein($t, $alvo) <= 2) return $l;
    }
    return null;
}

// Clique num botão de antecedência (V:<min>:<id>). Devolve [marca pra mensagem original, resposta]
function handle_callback_antecedencia($usuario, $callback_data) {
    if (!preg_match('/^V:(\d+):(\d+)$/', $callback_data, $m)) return [null, "Esse botão não funciona mais 🤔"];
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE id = ? AND usuario_id = ? AND status = 'pendente'");
    $stmt->execute([$m[2], $usuario['id']]);
    $lembrete = $stmt->fetch();
    if (!$lembrete) return [null, "Esse lembrete não está mais marcado 🤔"];
    get_db()->prepare("UPDATE lembretes SET antecedencia_min = ? WHERE id = ?")->execute([(int) $m[1], $lembrete['id']]);
    marcar_lembrete_mexido($lembrete['id']);
    $rotulo = (int) $m[1] === 0 && substr($lembrete['disparar_em'], 11, 5) === '09:00' ? 'no dia, às 9h' : rotulo_antecedencia($m[1]);
    return ["⏰ Aviso: $rotulo", confirmacao_antecedencia($lembrete['texto'], $lembrete['disparar_em'], (int) $m[1])];
}

// "me lembra 30 minutos antes" / "avisa a consulta 1 dia antes" (sem data nova na frase): muda o aviso de um lembrete
// que já existe — o que tiver a palavra da frase, ou o último criado na última hora. Null = não é esse caso.
function ajustar_antecedencia_pelo_texto($usuario, $texto) {
    $min = detectar_antecedencia($texto);
    if ($min === null || preg_match('/\b(gastei|paguei|comprei|recebi|ganhei|guardei|juntei)\b/iu', $texto)) return null;
    $resto = sem_trecho_antecedencia($texto);
    if (hora_da_frase($resto) || data_da_frase($resto) || detectar_dia_semana($resto) || preg_match('/\b(amanh|hoje|depois de amanh)/iu', $resto)) {
        return null; // tem data/hora = é um lembrete novo (ou vai pela IA)
    }
    $pdo = get_db();
    $lembrete = null;
    preg_match_all('/\p{L}{4,}/u', sem_acentos($resto), $m);
    $ignorar = ['lembra', 'lembre', 'lembrar', 'avisa', 'avise', 'avisar', 'aviso', 'avisado', 'quero', 'pode', 'favor', 'antes', 'minutos', 'horas', 'hora', 'dias', 'sobre', 'para', 'isso', 'esse', 'essa', 'disso', 'dele', 'dela', 'tambem', 'muda', 'mude', 'altera'];
    foreach (array_diff(array_unique($m[0]), $ignorar) as $palavra) {
        if ($lembrete = buscar_lembrete_pendente($usuario['id'], $palavra)) break;
    }
    $lembrete ??= lembrete_recente($usuario['id'], 60);
    if (!$lembrete) return null;
    $pdo->prepare("UPDATE lembretes SET antecedencia_min = ? WHERE id = ?")->execute([$min, $lembrete['id']]);
    marcar_lembrete_mexido($lembrete['id']);
    return confirmacao_antecedencia($lembrete['texto'], $lembrete['disparar_em'], $min);
}

// Transforma uma conta de data única em conta de todo mês (no dia em que ela está marcada)
function tornar_conta_mensal($lembrete) {
    $dia = (int) date('j', strtotime($lembrete['disparar_em']));
    get_db()->prepare("UPDATE lembretes SET recorrente = 'mensal', dia_mes = ? WHERE id = ?")->execute([$dia, $lembrete['id']]);
    marcar_lembrete_mexido($lembrete['id']);
    return sprintf("🔁 Combinado: \"%s\" é todo mês no dia %d. Entra nos seus gastos fixos.", $lembrete['texto'], $dia);
}

// Clique em "Sim, todo mês" / "Só dessa vez" (R:<mensal|unico>:<id>). Devolve [marca pra mensagem original, resposta]
function handle_callback_recorrencia($usuario, $callback_data) {
    if (!preg_match('/^R:(mensal|unico):(\d+)$/', $callback_data, $m)) return [null, "Esse botão não funciona mais 🤔"];
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE id = ? AND usuario_id = ? AND status = 'pendente'");
    $stmt->execute([$m[2], $usuario['id']]);
    $lembrete = $stmt->fetch();
    if (!$lembrete) return [null, "Esse lembrete não está mais marcado 🤔"];
    if ($m[1] === 'unico') return ['Só dessa vez', "👍 Certo, só dessa vez."];
    if ($oferta = exige_plus($usuario, 'Os gastos fixos')) return [null, $oferta];
    return ['🔁 Todo mês', tornar_conta_mensal($lembrete)];
}

// Resposta escrita à pergunta "é uma conta de todo mês?": "todo mês", "sim, todo mês", "mensal" / "só dessa vez"
function resposta_recorrencia_conta($usuario, $texto) {
    $t = sem_acentos(trim($texto, " .!?\n\t"));
    $mensal = (bool) preg_match('/^(?:sim[, ]+)?(?:e\s+)?(?:todo\s+mes|todos\s+os\s+meses|mensal|e\s+mensal|todo\s+mes\s+sim)$/u', $t);
    $unico = (bool) preg_match('/^(?:nao[, ]+)?(?:e\s+)?so\s+(?:dessa|desta|essa|esta|uma)\s+vez$/u', $t);
    if (!$mensal && !$unico) return null;
    $lembrete = lembrete_recente($usuario['id'], 15);
    if (!$lembrete || !$lembrete['eh_conta'] || $lembrete['recorrente']) return null;
    if ($unico) return "👍 Certo, só dessa vez.";
    return exige_plus($usuario, 'Os gastos fixos') ?? tornar_conta_mensal($lembrete);
}

// "me avisa às 7" logo depois de falar de um lembrete: o aviso dele passa a ser às 7 daquele dia
// (antes do horário = antecedência; depois = o próprio lembrete muda de hora). Null = não é esse caso.
function avisar_as_pelo_texto($usuario, $texto) {
    if (!preg_match('/^\s*(?:e\s+)?(?:me\s+)?(?:avisa|avise|avisar|lembra|lembre|lembrar)(?:-me)?\s+(?:disso\s+|dela\s+|dele\s+)?(?:[àa]s|a\s+partir\s+das|pelas)\s+\d/iu', $texto)) return null;
    $hora = hora_da_frase($texto);
    if (!$hora || data_da_frase($texto) || detectar_dia_semana($texto) || preg_match('/\b(amanh|hoje)/iu', $texto)) return null;
    $lembrete = lembrete_recente($usuario['id'], 30);
    return $lembrete ? avisar_lembrete_as($usuario, $lembrete, $hora) : null;
}

function avisar_lembrete_as($usuario, $lembrete, $hora) {
    $evento = strtotime($lembrete['disparar_em']);
    $aviso = strtotime(date('Y-m-d', $evento) . " $hora:00");
    if ($aviso < $evento) {
        $min = (int) round(($evento - $aviso) / 60);
        get_db()->prepare("UPDATE lembretes SET antecedencia_min = ? WHERE id = ?")->execute([$min, $lembrete['id']]);
        marcar_lembrete_mexido($lembrete['id']);
        return confirmacao_antecedencia($lembrete['texto'], $lembrete['disparar_em'], $min);
    }
    return aplicar_edicao_lembrete($usuario['id'], $lembrete, ['disparar_em_novo' => date('Y-m-d', $evento) . " $hora"]);
}

// Início/fim (timestamps) de um período da agenda. Nunca começa antes de agora — o que já passou já foi avisado.
function calcular_intervalo_agenda($periodo) {
    $agora = time();
    $fim_do_dia = fn($ts) => strtotime(date('Y-m-d 23:59:59', $ts));

    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    // Devolve [início, fim, rótulo] — o rótulo completa "Agenda de ..." / "Nada marcado pra ..."
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodo) && validar_data_ou_invalida($periodo)) {
        $ts = strtotime($periodo);
        return [max($agora, $ts), $fim_do_dia($ts), 'dia ' . date('d/m', $ts)];
    }
    if (preg_match('/^\d{4}-\d{2}$/', $periodo)) {
        $ts = strtotime($periodo . '-01');
        return [max($agora, $ts), strtotime(date('Y-m-t 23:59:59', $ts)), $meses[(int) date('n', $ts)]];
    }

    switch ($periodo) {
        case 'hoje':
            return [$agora, $fim_do_dia($agora), 'hoje'];
        case 'amanha':
            $amanha = strtotime('tomorrow');
            return [$amanha, $fim_do_dia($amanha), 'amanhã'];
        case 'proxima_semana':
            $segunda = strtotime('next monday');
            return [$segunda, $fim_do_dia(strtotime('+6 days', $segunda)), 'próxima semana'];
        case 'mes':
            return [$agora, strtotime(date('Y-m-t 23:59:59')), $meses[(int) date('n')] . ' (até o fim do mês)'];
        case 'proximo_mes':
            $inicio = strtotime('first day of next month 00:00:00');
            return [$inicio, strtotime(date('Y-m-t 23:59:59', $inicio)), $meses[(int) date('n', $inicio)]];
        case 'semana':
        default:
            return [$agora, $fim_do_dia(strtotime('+6 days')), 'próximos 7 dias'];
    }
}

function handle_listar_lembretes($usuario, $dados = []) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT texto, disparar_em, recorrente, intervalo_horas, dia_mes, valor, moeda, antecedencia_min FROM lembretes WHERE usuario_id = ? AND status = 'pendente' ORDER BY disparar_em ASC");
    $stmt->execute([$usuario['id']]);
    $linhas = $stmt->fetchAll();

    $periodo = $dados['periodo'] ?? null;

    // Pergunta sobre UM lembrete ("que horas vai me lembrar da água?", "quando é o lembrete do seguro?")
    $termo = trim($dados['termo'] ?? '');
    if ($termo !== '' && !$periodo) {
        $achados = array_filter($linhas, fn($l) => mb_stripos(sem_acentos($l['texto']), sem_acentos($termo)) !== false);
        if (!$achados) {
            return sprintf("Não tenho nenhum lembrete marcado sobre \"%s\". 🤔 Quer que eu crie? Tipo \"me lembra de %s amanhã às 9h\".", $termo, $termo);
        }
        $texto = '';
        foreach ($achados as $l) {
            $rec = rotulo_recorrencia($l['recorrente'], $l['intervalo_horas'], 'parenteses', $l['dia_mes'], $l['disparar_em']);
            $valor = $l['valor'] !== null ? ' — ' . formatar_dinheiro($l['valor'], $l['moeda'] ?: 'EUR') : '';
            $texto .= sprintf("🔔 \"%s\"%s — o próximo aviso é %s%s.\n", $l['texto'], $valor, formatar_data_hora_amigavel($l['disparar_em']), $rec);
        }
        return trim($texto);
    }

    if (!$periodo) {
        if (!$linhas) {
            return "Você não tem nenhum lembrete marcado. 👀";
        }
        $texto = "Seus lembretes:\n";
        foreach ($linhas as $linha) {
            $quando = $linha['disparar_em'] ? formatar_data_hora_amigavel($linha['disparar_em']) : 'sem hora definida';
            $rec = rotulo_recorrencia($linha['recorrente'], $linha['intervalo_horas'], 'parenteses', $linha['dia_mes'], $linha['disparar_em']);
            $valor = $linha['valor'] !== null ? ' — ' . formatar_dinheiro($linha['valor'], $linha['moeda'] ?: 'EUR') : '';
            $aviso = (int) $linha['antecedencia_min'] > 0 ? ' ⏰ aviso ' . rotulo_antecedencia($linha['antecedencia_min']) : '';
            $texto .= sprintf("• %s%s — %s%s%s\n", $linha['texto'], $valor, $quando, $rec, $aviso);
        }
        return trim($texto);
    }

    // Agenda: expande as repetições que caem dentro do período
    [$inicio, $fim, $rotulo] = calcular_intervalo_agenda($periodo);
    $ocorrencias = [];
    $frequentes = []; // diário / de X em X horas — listar cada repetição só polui, mostra uma vez só

    foreach ($linhas as $linha) {
        if (!$linha['disparar_em']) continue;

        if (in_array($linha['recorrente'], ['diario', 'horas'], true)) {
            if (strtotime($linha['disparar_em']) <= $fim) {
                $frequentes[] = sprintf("• %s — %s%s", $linha['texto'], date('H:i', strtotime($linha['disparar_em'])), rotulo_recorrencia($linha['recorrente'], $linha['intervalo_horas']));
            }
            continue;
        }

        $quando = $linha['disparar_em'];
        for ($i = 0; $i < 62 && $quando && strtotime($quando) <= $fim; $i++) {
            if (strtotime($quando) >= $inicio) {
                $ocorrencias[] = ['ts' => strtotime($quando), 'texto' => $linha['texto'], 'rec' => rotulo_recorrencia($linha['recorrente'], null, 'parenteses', $linha['dia_mes'], $linha['disparar_em'])];
            }
            if (!$linha['recorrente']) break;
            $quando = avancar_recorrencia($quando, $linha['recorrente'], $linha['intervalo_horas'], $linha['dia_mes']);
        }
    }

    if (!$ocorrencias && !$frequentes) {
        return "🗓️ Agenda — {$rotulo}: nada marcado. 🙌";
    }

    usort($ocorrencias, fn($a, $b) => $a['ts'] <=> $b['ts']);
    $dias_semana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    $texto = "🗓️ Agenda — {$rotulo}:\n";
    $dia_atual = null;
    foreach ($ocorrencias as $o) {
        $dia = date('Y-m-d', $o['ts']);
        if ($dia !== $dia_atual) {
            $texto .= sprintf("\n%s, %s\n", $dias_semana[(int) date('w', $o['ts'])], date('d/m', $o['ts']));
            $dia_atual = $dia;
        }
        $texto .= sprintf("• %s — %s%s\n", date('H:i', $o['ts']), $o['texto'], $o['rec']);
    }
    if ($frequentes) {
        $texto .= "\n🔁 Repetem sempre:\n" . implode("\n", $frequentes) . "\n";
    }

    return trim($texto);
}

function buscar_lembrete_pendente($usuario_id, $termo, $dia = null) {
    return buscar_lembretes_pendentes($usuario_id, $termo, $dia)[0] ?? null;
}

// Lembretes pendentes que batem com o termo (sem acento, plural, erro de digitação: "reunões" acha "reunião"),
// do mais novo pro mais antigo. $dia filtra: 'Y-m-d' (data) ou número (dia do mês: "a consulta do dia 22").
function buscar_lembretes_pendentes($usuario_id, $termo, $dia = null) {
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' ORDER BY id DESC");
    $stmt->execute([$usuario_id]);
    $singular = fn($p) => preg_replace(['/oes$/u', '/aes$/u', '/s$/u'], ['ao', 'ao', ''], $p);
    $alvo = sem_acentos(trim($termo ?? ''));
    preg_match_all('/\p{L}{4,}/u', $alvo, $m);
    $palavras = array_map($singular, $m[0]);

    $achados = [];
    foreach ($stmt->fetchAll() as $l) {
        if ($dia !== null) {
            $d = substr($l['disparar_em'], 0, 10);
            if (is_int($dia) ? (int) substr($d, 8, 2) !== $dia : $d !== $dia) continue;
        }
        $t = sem_acentos($l['texto']);
        $bate = $alvo === '' || str_contains($t, $alvo);
        if (!$bate && $palavras) {
            preg_match_all('/\p{L}{3,}/u', $t, $mt);
            $do_lembrete = array_map($singular, $mt[0]);
            // Todas as palavras do termo aparecem no lembrete (igual ou com 1 letra de diferença)
            $bate = count(array_filter($palavras, fn($p) => (bool) array_filter($do_lembrete,
                fn($w) => $w === $p || (mb_strlen($p) >= 5 && levenshtein($w, $p) <= 1)))) === count($palavras);
        }
        if ($bate) $achados[] = $l;
    }
    return $achados;
}

// "a reunião de hoje", "a consulta do dia 22", "a de amanhã" — qual lembrete (pela data que ele TEM agora).
// Devolve 'Y-m-d', um número (dia do mês) ou null.
function dia_referido($texto) {
    $t = sem_acentos($texto);
    if (preg_match('/\b(?:de|do|da)\s+hoje\b/u', $t)) return date('Y-m-d');
    if (preg_match('/\b(?:de|do|da)\s+amanha\b/u', $t)) return date('Y-m-d', strtotime('+1 day'));
    if (preg_match('/\b(?:de|do|da)\s+dia\s+(\d{1,2})(?:\/(\d{1,2}))?\b/u', $t, $m)) {
        if (!isset($m[2])) return (int) $m[1];
        return data_da_frase("{$m[1]}/{$m[2]}");
    }
    return null;
}

// Tira da frase a referência ao lembrete ("do dia 15", "de hoje") pra sobrar só a data NOVA
function sem_dia_referido($texto) {
    return preg_replace('/\b(?:de|do|da)\s+(?:hoje|amanh[ãa]|dia\s+\d{1,2}(?:\/\d{1,2})?)\b/iu', '', $texto);
}

function marcar_lembrete_mexido($lembrete_id) {
    get_db()->prepare("UPDATE lembretes SET mexido_em = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $lembrete_id]);
}

// O lembrete de que acabamos de falar (criado/alterado nos últimos $minutos), se nenhum gasto foi anotado depois
function lembrete_recente($usuario_id, $minutos) {
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' AND mexido_em >= ? AND (recorrente IS NULL OR recorrente NOT IN ('horas', 'diario')) ORDER BY mexido_em DESC, id DESC LIMIT 1");
    $stmt->execute([$usuario_id, date('Y-m-d H:i:s', strtotime("-{$minutos} minutes"))]);
    $l = $stmt->fetch();
    if (!$l) return null;
    // Gasto anotado DEPOIS do lembrete (">" e não ">=": no mesmo segundo ainda conta como antes — os segundos são arredondados)
    $seg = max(0, time() - strtotime($l['mexido_em']));
    $st = get_db()->prepare("SELECT 1 FROM transacoes WHERE usuario_id = ? AND criado_em > NOW() - INTERVAL ? SECOND LIMIT 1");
    $st->execute([$usuario_id, $seg]);
    return $st->fetch() ? null : $l;
}

// "altera a consulta para o dia 29" / "altera, é 28/10" logo depois de marcar a consulta: a IA manda editar_transacao,
// mas o que existe é um lembrete. Desvia pra editar_lembrete quando a palavra só bate com lembrete pendente
// (nenhuma transação tem ela), ou quando, sem palavra nenhuma, a última coisa criada foi um lembrete há poucos minutos.
function corrigir_edicao_lembrete($usuario_id, $intent, $dados, $texto) {
    // A IA acertou o intent mas esqueceu o que muda ("altera a consulta para 20/10" sem data): tira da frase
    $ref = dia_referido($texto);          // "a reunião DE HOJE" = qual lembrete
    $texto_novo_dia = sem_dia_referido($texto); // o que sobra traz a data nova ("pra 30/10")
    if ($intent === 'editar_lembrete') {
        $campos = ['texto_novo', 'disparar_em_novo', 'nova_data_dia', 'recorrente_novo', 'dia_mes', 'valor_novo', 'conta_nova'];
        if (!array_filter(array_intersect_key($dados, array_flip($campos)))) {
            if (!empty($dados['data_nova']) && validar_data_ou_invalida($dados['data_nova'])) $dados['nova_data_dia'] = $dados['data_nova'];
            elseif ($dia = data_da_frase($texto_novo_dia) ?? dia_relativo_da_frase($texto_novo_dia)) $dados['nova_data_dia'] = $dia;
            if (!empty($dados['descricao_nova'])) $dados['texto_novo'] = $dados['descricao_nova'];
        }
        // Só a data mudou ("altera a consulta para 20/10"): a IA inventa 09:00 — mantém a hora que o lembrete já tinha
        if (!empty($dados['disparar_em_novo']) && strlen($dados['disparar_em_novo']) >= 10 && !hora_da_frase($texto_novo_dia)
            && !detectar_tempo_relativo($texto) && (strlen($dados['disparar_em_novo']) < 16 || substr($dados['disparar_em_novo'], 11, 5) === '09:00')) {
            $dados['nova_data_dia'] = substr($dados['disparar_em_novo'], 0, 10);
            unset($dados['disparar_em_novo']);
        }
        if ($ref !== null && !empty($dados['termo']) && ($l = buscar_lembrete_pendente($usuario_id, $dados['termo'], $ref))) {
            $dados['lembrete_id'] = (int) $l['id'];
        }
        return [$intent, $dados];
    }
    $pedido_de_mudanca = preg_match('/^\s*(altera|alterar|altere|muda|mudar|mude|troca|trocar|troque|corrige|corrigir)\b/iu', $texto)
        || eh_correcao_curta($texto);
    if ($intent !== 'editar_transacao' && !($intent === 'unknown' && $pedido_de_mudanca)) return [$intent, $dados];
    $pdo = get_db();
    $tem_transacao = function ($palavra) use ($pdo, $usuario_id) {
        $st = $pdo->prepare("SELECT 1 FROM transacoes WHERE usuario_id = ? AND (descricao LIKE ? OR categoria LIKE ?) LIMIT 1");
        $st->execute([$usuario_id, "%$palavra%", "%$palavra%"]);
        return (bool) $st->fetch();
    };

    $lembrete = null;
    $termo = trim($dados['termo'] ?? '');
    $candidatas = $termo !== '' ? [$termo] : [];
    preg_match_all('/\p{L}{4,}/u', mb_strtolower($texto), $m);
    $ignorar = ['altera', 'alterar', 'altere', 'muda', 'mudar', 'mude', 'troca', 'trocar', 'troque', 'corrige', 'corrigir', 'para', 'hoje', 'amanha', 'amanhã', 'horas', 'data', 'dia', 'valor', 'nome'];
    foreach (array_diff(array_unique($m[0]), $ignorar) as $p) $candidatas[] = $p;
    // Data nova no futuro não é de gasto (gasto é o que já aconteceu); "lembrete" na frase também decide
    $data_nova = $dados['data_nova'] ?? null;
    $com_certeza_lembrete = ($data_nova && $data_nova > date('Y-m-d')) || preg_match('/\blembrete/iu', $texto);
    foreach ($candidatas as $palavra) {
        $achado = buscar_lembrete_pendente($usuario_id, $palavra, $ref);
        if ($achado && ($com_certeza_lembrete || $ref !== null || !$tem_transacao($palavra))) { $lembrete = $achado; break; }
    }
    if (!$lembrete && ($termo === '' || !$tem_transacao($termo)) && empty($dados['valor_antigo'])
        && (empty($dados['valor_novo']) || data_da_frase($texto))) {
        // Sem palavra que ache transação: vale o lembrete de que acabamos de falar, se nenhum gasto veio depois
        $lembrete = lembrete_recente($usuario_id, 15);
    }
    if (!$lembrete) return [$intent, $dados];

    $novo = ['intent' => 'editar_lembrete', 'termo' => $lembrete['texto'], 'lembrete_id' => (int) $lembrete['id']];
    if (!empty($dados['descricao_nova'])) $novo['texto_novo'] = $dados['descricao_nova'];
    // Data dita na frase ("dia 29", "28/10"); a hora do lembrete é mantida, a não ser que a frase diga outra
    $dia = data_da_frase($texto_novo_dia) ?? dia_relativo_da_frase($texto_novo_dia)
        ?? (!empty($dados['data_nova']) && validar_data_ou_invalida($dados['data_nova']) ? $dados['data_nova'] : null);
    if ($hora = hora_da_frase($texto_novo_dia)) {
        $novo['disparar_em_novo'] = ($dia ?? substr($lembrete['disparar_em'], 0, 10)) . ' ' . $hora;
    } elseif ($dia) {
        $novo['nova_data_dia'] = $dia;
    }
    return ['editar_lembrete', $novo];
}

// Correção curta logo depois de marcar um lembrete: "é 30/10", "na verdade é às 10h", "não, é dia 5",
// "amanhã não, hoje", "não, hoje", "hoje não, amanhã às 10h"
function eh_correcao_curta($texto) {
    return (bool) (preg_match('/^\s*(?:n[aã]o,?\s+)?(?:na\s+verdade,?\s+)?(?:[eé]|era|foi|ser[aá])\s+(?:dia\s+|pra\s+|para\s+|[àa]s\s+|no\s+dia\s+)?(?:\d|hoje(?![\p{L}])|amanh[ãa](?![\p{L}]))/iu', $texto)
        || preg_match('/^\s*(?:(?:hoje|amanh[ãa])\s+n[aã]o|n[aã]o)\s*[,.;]?\s*(?:[eé]\s+|para\s+|pra\s+)?(?:hoje|amanh[ãa])(?![\p{L}])/iu', $texto));
}

// Correção curta + lembrete de que acabamos de falar = nem precisa da IA (o corrigir_edicao_lembrete resolve)
function atalho_correcao_lembrete($usuario, $texto) {
    return eh_correcao_curta($texto) && lembrete_recente($usuario['id'], 15) ? ['intent' => 'unknown'] : null;
}

// "amanhã não, hoje" -> hoje; "é amanhã" -> amanhã. O pedaço negado ("amanhã não") não conta.
function dia_relativo_da_frase($texto) {
    $t = preg_replace('/(?<![\p{L}])(?:hoje|amanh[ãa])\s+n[aã]o(?![\p{L}])/iu', '', $texto);
    if (preg_match('/(?<![\p{L}])depois\s+de\s+amanh[ãa]/iu', $t)) return date('Y-m-d', strtotime('+2 days'));
    if (preg_match('/(?<![\p{L}])amanh[ãa](?![\p{L}])/iu', $t)) return date('Y-m-d', strtotime('+1 day'));
    if (preg_match('/(?<![\p{L}])hoje(?![\p{L}])/iu', $t)) return date('Y-m-d');
    return null;
}

// "dia 29" -> o próximo dia 29 (hoje conta); "28/10" -> 28/10 deste ano (ou do próximo, se já passou)
function data_da_frase($texto) {
    $hoje = date('Y-m-d');
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $texto, $m)) {
        $ano = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) date('Y');
        if (!checkdate((int) $m[2], (int) $m[1], $ano)) return null;
        $data = sprintf('%04d-%02d-%02d', $ano, $m[2], $m[1]);
        return (!isset($m[3]) && $data < $hoje) ? sprintf('%04d-%02d-%02d', $ano + 1, $m[2], $m[1]) : $data;
    }
    if (preg_match('/\bdia\s+(\d{1,2})\b(?!\s*(?:h|:|horas))/u', $texto, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31) {
        $data = substr(data_no_dia_do_mes((int) date('Y'), (int) date('n'), (int) $m[1], '00:00'), 0, 10);
        return $data >= $hoje ? $data : substr(avancar_recorrencia("$data 00:00:00", 'mensal', null, (int) $m[1]), 0, 10);
    }
    return null;
}

// Muda texto, horário e/ou repetição de um lembrete que já existe
function handle_editar_lembrete($usuario, $dados) {
    $termo = trim($dados['termo'] ?? '');
    $mudancas = array_intersect_key($dados, array_flip(['texto_novo', 'disparar_em_novo', 'nova_data_dia', 'recorrente_novo', 'intervalo_horas', 'dia_mes', 'valor_novo', 'conta_nova']));

    $lembrete = null;
    if (!empty($dados['lembrete_id'])) {
        $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE id = ? AND usuario_id = ? AND status = 'pendente'");
        $stmt->execute([$dados['lembrete_id'], $usuario['id']]);
        $lembrete = $stmt->fetch() ?: null;
    }
    if (!$lembrete && !$termo) {
        salvar_estado($usuario['id'], 'aguardando_qual_lembrete_editar', $mudancas);
        return "Qual lembrete você quer mudar? Me diz uma palavra-chave dele.";
    }

    $lembrete ??= buscar_lembrete_pendente($usuario['id'], $termo);
    if (!$lembrete) {
        return sprintf("Não achei nenhum lembrete pendente com \"%s\". 🤔", $termo);
    }

    // Mudou pra HOJE sem dizer a hora, e a hora que o lembrete tinha já passou hoje: pergunta a hora
    if (($mudancas['nova_data_dia'] ?? null) === date('Y-m-d') && empty($mudancas['disparar_em_novo']) && !$lembrete['recorrente']
        && strtotime(date('Y-m-d') . ' ' . date('H:i:s', strtotime($lembrete['disparar_em']))) <= time()) {
        salvar_estado($usuario['id'], 'aguardando_mudanca_lembrete', ['lembrete_id' => $lembrete['id'], 'dia' => date('Y-m-d')]);
        botoes_resposta(botoes_hora_hoje());
        return sprintf("Certo, \"%s\" é hoje. A que horas você quer que eu te lembre? 🕐", $lembrete['texto']);
    }

    if (!array_filter($mudancas, fn($v) => $v !== null && $v !== '')) {
        salvar_estado($usuario['id'], 'aguardando_mudanca_lembrete', ['lembrete_id' => $lembrete['id']]);
        return sprintf("O que muda no lembrete \"%s\"? Me diz o novo horário (ex: \"sexta às 10h\") ou o novo texto.", $lembrete['texto']);
    }

    return aplicar_edicao_lembrete($usuario['id'], $lembrete, $mudancas);
}

function aplicar_edicao_lembrete($usuario_id, $lembrete, $mudancas) {
    $texto       = trim($mudancas['texto_novo'] ?? '') ?: $lembrete['texto'];
    $disparar_em = $lembrete['disparar_em'];
    $recorrente  = $lembrete['recorrente'];
    $intervalo   = $lembrete['intervalo_horas'];
    $dia_mes     = $lembrete['dia_mes'];

    // Se a data antiga já passou, parte da próxima repetição dela (antes de mexer na recorrência)
    $disparar_em = ajustar_para_futuro($disparar_em, $recorrente, $intervalo, $dia_mes);

    if (!empty($mudancas['nova_data_dia'])) {
        // Só mudou o dia ("o lembrete da reunião é na sexta") — mantém a hora que já tinha
        $disparar_em = $mudancas['nova_data_dia'] . ' ' . date('H:i:s', strtotime($disparar_em));
        if (strtotime($disparar_em) <= time()) {
            $disparar_em = date('Y-m-d H:i:s', strtotime('+7 days', strtotime($disparar_em)));
        }
    }

    if (!empty($mudancas['recorrente_novo'])) {
        $recorrente = $mudancas['recorrente_novo'] === 'nenhum' ? null : (normalizar_recorrencia($mudancas['recorrente_novo']) ?? $recorrente);
        $intervalo  = $recorrente === 'horas' ? max(1, (int) ($mudancas['intervalo_horas'] ?? $intervalo ?? 1)) : null;
    }

    if (!empty($mudancas['disparar_em_novo'])) {
        $novo = $mudancas['disparar_em_novo'];
        if (validar_data_ou_invalida(substr($novo, 0, 10)) === false) {
            return sprintf("\"%s\" não existe no calendário 🤔 Me diz outra data.", substr($novo, 0, 10));
        }
        $disparar_em = substr($novo, 0, 16) . ':00';
        if ($recorrente === 'mensal' && empty($mudancas['dia_mes'])) {
            $dia_mes = (int) date('j', strtotime($disparar_em));
        }
    }

    if ($recorrente === 'mensal' && !empty($mudancas['dia_mes'])) {
        $dia_mes = max(1, min(31, (int) $mudancas['dia_mes']));
        $disparar_em = primeira_ocorrencia_mensal($dia_mes, date('H:i:s', strtotime($disparar_em)));
    }
    if ($recorrente !== 'mensal') {
        $dia_mes = null;
    } elseif (!$dia_mes) {
        $dia_mes = (int) date('j', strtotime($disparar_em));
    }
    $disparar_em = ajustar_para_futuro($disparar_em, $recorrente, $intervalo, $dia_mes);

    $valor = isset($mudancas['valor_novo']) && is_numeric($mudancas['valor_novo']) ? (float) $mudancas['valor_novo'] : $lembrete['valor'];
    $conta = !empty($mudancas['conta_nova']) ? salvar_conta($usuario_id, $mudancas['conta_nova']) : $lembrete['conta'];
    $eh_conta = $lembrete['eh_conta'] || parece_conta_a_pagar($texto, $valor !== null ? (float) $valor : null);

    get_db()->prepare("UPDATE lembretes SET texto = ?, disparar_em = ?, recorrente = ?, intervalo_horas = ?, dia_mes = ?, valor = ?, conta = ?, eh_conta = ? WHERE id = ? AND usuario_id = ?")
        ->execute([$texto, $disparar_em, $recorrente, $intervalo, $dia_mes, $valor, $conta, $eh_conta ? 1 : 0, $lembrete['id'], $usuario_id]);
    marcar_lembrete_mexido($lembrete['id']);

    $extra = '';
    if ($valor !== null) $extra .= ' — ' . formatar_dinheiro($valor, $lembrete['moeda'] ?: 'EUR');
    if ($conta) $extra .= ' — sai da ' . ucfirst($conta);

    // Compromisso com aviso antecipado: lembra quando o aviso chega (a data de cima é a do compromisso)
    if ((int) ($lembrete['antecedencia_min'] ?? 0) > 0) {
        $extra .= ' ⏰ aviso ' . rotulo_antecedencia($lembrete['antecedencia_min']);
    }
    $o_que = ($eh_conta && in_array($recorrente, ['mensal', 'semanal'], true)) ? 'o gasto fixo' : 'o lembrete';
    return sprintf("✏️ Atualizei %s: \"%s\" — %s %s%s%s.", $o_que, $texto, (int) ($lembrete['antecedencia_min'] ?? 0) > 0 ? 'agora é' : 'próximo aviso', formatar_data_hora_amigavel($disparar_em), rotulo_recorrencia($recorrente, $intervalo, 'travessao', $dia_mes, $disparar_em), $extra);
}

// "apaga a consulta do dia 22, a do dia 23 e as duas reuniões do dia 15" -> lista de lembretes (ou null se não achou nada).
// Cada pedaço herda a palavra do anterior ("a do dia 23" = consulta); "as duas"/"todas" pega todos daquele dia.
function lembretes_do_pedido_de_apagar($usuario_id, $texto, &$ambiguos = null) {
    $ambiguos = null;
    // Botão "qual delas?": "cancela o lembrete #123"
    if (preg_match('/#(\d+)\s*$/', $texto, $m)) {
        $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE id = ? AND usuario_id = ? AND status = 'pendente'");
        $stmt->execute([$m[1], $usuario_id]);
        return ($l = $stmt->fetch()) ? [$l] : null;
    }
    $t = preg_replace('/^\s*(?:me\s+)?(?:apaga|apague|apagar|cancela|cancele|cancelar|exclui|exclua|excluir|remove|remova|remover|tira|tire|deleta)\s+(?:(?:o|os)\s+)?(?:lembretes?\s+)?(?:(?:d[aeo]s?)\s+)?/iu', '', $texto, 1, $n);
    if (!$n || trim($t) === '') return null;
    $vazias = ['a', 'o', 'as', 'os', 'do', 'da', 'de', 'dos', 'das', 'dia', 'e', 'duas', 'dois', 'tres', 'todas', 'todos', 'lembrete', 'lembretes', 'minha', 'meu', 'minhas', 'meus', 'que', 'tenho', 'marcada', 'marcado', 'hoje', 'amanha'];
    $termo_anterior = null;
    $achados = [];
    $partes = array_values(array_filter(preg_split('/\s*(?:,|;|\s+e\s+)\s*/u', trim($t, " .!?\"'")), fn($p) => $p !== ''));
    foreach ($partes as $parte) {
        if ($parte === '') continue;
        $p = sem_acentos($parte);
        $dia = dia_referido($parte) ?? (preg_match('/\bdia\s+(\d{1,2})\b/u', $p, $m) ? (int) $m[1] : null)
            ?? (count($partes) === 1 ? dia_referido($texto) : null); // "cancela o lembrete de amanhã": o "de" ficou no começo
        preg_match_all('/\p{L}+/u', $p, $m);
        $termo = implode(' ', array_diff($m[0], $vazias)) ?: $termo_anterior;
        if ($termo === null && $dia !== null) $termo = ''; // sem nome, só o dia = o(s) lembrete(s) daquele dia
        if ($termo === null) continue;
        $termo_anterior = $termo;
        $lista = buscar_lembretes_pendentes($usuario_id, $termo, $dia);
        if (!$lista) continue;
        $todos = preg_match('/\b(duas|dois|tres|todas|todos|ambas|ambos)\b/u', $p);
        // Pediu UM ("apaga a reunião de hoje") e tem mais de um que bate: quem escolhe é a pessoa
        if (!$todos && count($lista) > 1 && count($partes) === 1) {
            $ambiguos = $lista;
            return null;
        }
        foreach ($todos ? $lista : [$lista[0]] as $l) $achados[$l['id']] = $l;
    }
    return $achados ? array_values($achados) : null;
}

// A IA não entendeu (ou achou que era gasto) um "apaga a consulta do dia 22, ...": se bate com lembretes, é cancelar lembrete
function corrigir_cancelar_lembrete($usuario_id, $intent, $dados, $texto) {
    if (!in_array($intent, ['unknown', 'cancelar_lembrete', 'deletar_transacao'], true)) return [$intent, $dados];
    $lembretes = lembretes_do_pedido_de_apagar($usuario_id, $texto, $ambiguos);
    if ($ambiguos && ($intent !== 'deletar_transacao' || preg_match('/\blembrete|\bdia\s+\d|\b(de|do|da)\s+(hoje|amanh)/iu', $texto))) {
        return ['cancelar_lembrete', ['intent' => 'cancelar_lembrete', 'escolher_ids' => array_column($ambiguos, 'id')]];
    }
    if (!$lembretes) return corrigir_apagar_conta($usuario_id, $intent, $dados, $texto);
    if ($intent === 'deletar_transacao' && !preg_match('/\blembrete|\bdia\s+\d/iu', $texto)) return [$intent, $dados];
    return ['cancelar_lembrete', ['intent' => 'cancelar_lembrete', 'lembrete_ids' => array_column($lembretes, 'id')]];
}

// "apaga 'cancela'" quando "Cancela" é o nome de uma conta (e não de um gasto): remove a conta
function corrigir_apagar_conta($usuario_id, $intent, $dados, $texto) {
    if (!in_array($intent, ['unknown', 'deletar_transacao', 'cancelar_lembrete'], true)) return [$intent, $dados];
    if (!preg_match('/^\s*(?:apaga|apague|exclui|exclua|remove|remova|deleta|tira|cancela)\s+(?:a\s+conta\s+|o\s+banco\s+|a\s+|o\s+)?["\'“”]?(.+?)["\'“”]?\s*[.!]*$/iu', $texto, $m)) return [$intent, $dados];
    $alvo = sem_acentos(trim($m[1]));
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT nome FROM contas WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $conta) {
        if (sem_acentos($conta) !== $alvo) continue;
        $st = $pdo->prepare("SELECT 1 FROM transacoes WHERE usuario_id = ? AND descricao = ? LIMIT 1");
        $st->execute([$usuario_id, $conta]);
        if (!$st->fetch()) return ['apagar_conta', ['intent' => 'apagar_conta', 'conta' => $conta]];
    }
    return [$intent, $dados];
}

function handle_cancelar_lembrete($usuario, $dados) {
    // "apaga a reunião de hoje" com duas reuniões hoje: pergunta qual, com um botão pra cada
    if (!empty($dados['escolher_ids'])) {
        $ids = array_map('intval', $dados['escolher_ids']);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' AND id IN ($marcadores) ORDER BY disparar_em LIMIT 6");
        $stmt->execute(array_merge([$usuario['id']], $ids));
        $opcoes = $stmt->fetchAll();
        botoes_resposta(array_map(fn($l) => [botao_texto(mb_strimwidth("{$l['texto']} — " . formatar_data_hora_amigavel($l['disparar_em']), 0, 40, '…'), "cancela o lembrete #{$l['id']}")], $opcoes));
        return "Achei mais de um 🤔 Qual você quer cancelar?";
    }
    // Vários de uma vez: lista e pede confirmação
    if (!empty($dados['lembrete_ids'])) {
        $ids = array_map('intval', $dados['lembrete_ids']);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' AND id IN ($marcadores) ORDER BY disparar_em");
        $stmt->execute(array_merge([$usuario['id']], $ids));
        $lembretes = $stmt->fetchAll();
        if (count($lembretes) === 1) {
            $dados = ['termo' => $lembretes[0]['texto'], 'lembrete_id' => $lembretes[0]['id']];
        } elseif ($lembretes) {
            $lista = implode("\n", array_map(fn($l) => "• {$l['texto']} — " . formatar_data_hora_amigavel($l['disparar_em']), $lembretes));
            salvar_estado($usuario['id'], 'aguardando_confirmacao_apagar', ['lembrete_ids' => array_column($lembretes, 'id'), 'descricao' => 'aqueles ' . count($lembretes) . ' lembretes']);
            botoes_resposta([[botao_texto('🗑️ Sim, cancela', 'sim'), botao_texto('Não', 'não')]]);
            return sprintf("Cancelo estes %d lembretes? 🗑️\n%s\n(sim/não)", count($lembretes), $lista);
        }
    }
    $termo = trim($dados['termo'] ?? '');
    if (!$termo) {
        salvar_estado($usuario['id'], 'aguardando_qual_lembrete_cancelar', []);
        return "Qual lembrete você quer cancelar? Me diz uma palavra-chave dele.";
    }

    $pdo = get_db();
    $row = null;
    if (!empty($dados['lembrete_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM lembretes WHERE id = ? AND usuario_id = ? AND status = 'pendente'");
        $stmt->execute([$dados['lembrete_id'], $usuario['id']]);
        $row = $stmt->fetch() ?: null;
    }
    $row ??= buscar_lembrete_pendente($usuario['id'], $termo);

    if (!$row) {
        return sprintf("Não achei nenhum lembrete ou gasto fixo com \"%s\". 🤔", $termo);
    }

    $pdo->prepare("UPDATE lembretes SET status = 'cancelado' WHERE id = ?")->execute([$row['id']]);

    if ($row['eh_conta'] && in_array($row['recorrente'], ['mensal', 'semanal'], true)) {
        return sprintf("Removido! ❌ \"%s\" saiu dos seus gastos fixos — não vou mais lembrar. (O que você já pagou continua registrado.)", descricao_da_conta($row['texto']));
    }
    return sprintf("Cancelado! ❌ Não vou mais te lembrar de \"%s\".", $row['texto']);
}

// Verifica se a mensagem parece ser só uma resposta direta a uma pergunta pendente
// (curta, sem verbo de ação nova) — se for, nem chama a IA, resolve na hora.
function parece_resposta_direta($texto) {
    $texto = trim($texto);
    if ($texto === '') return false;

    $palavras = preg_split('/\s+/', $texto);
    if (count($palavras) > 6) return false;

    if (preg_match('/\b(gaste|gastei|pague|paguei|compre|comprei|receb|ganh|tenho|tinha|quanto|quais?|quando|onde|como|porque|quem|despesa|receita|lista|listar|lembr|convert|transfer|apaga|apague|delet|corrig|altera|muda|mude|detalha|mostra|resumo|extrato|edita|exclui|remov|renomeia|agenda|categorias|or[çc]amento|limite|ajuda|bebi|tomei|resumo|balan[çc]o|guardei|meta|poupan|juntar|plano|assina|plus|levant|saque|saquei|risc|adicion|acrescent|coloc|bota|anota|limpa)\w*/ui', $texto)) {
        return false;
    }

    return true;
}

function handle_unknown($usuario, $dados, $texto_original = '') {
    if (parece_resposta_direta($texto_original)) {
        $transacao_id = buscar_gasto_pendente_recente($usuario['id'], $texto_original);
        if ($transacao_id) {
            return categorizar_gasto_pendente($usuario['id'], $transacao_id, $texto_original);
        }
    }

    return "Hmm, não peguei bem 🤔 Pode tentar de outro jeito? Tipo \"gastei 20 no mercado\" ou \"quanto gastei esse mês\".\n(Se acha que é um erro meu, manda /reportar — ajuda muito!)";
}

// ============================================================================
// Contas a pagar: lembrete com botões Paguei / Amanhã / Não vou pagar
// ============================================================================

// Texto + botões do aviso que o cron manda. callback_data: "L:<ação>:<id do lembrete>:<AAAAMMDD do vencimento>"
function montar_aviso_lembrete($lembrete) {
    $data = date('Ymd', strtotime($lembrete['disparar_em']));
    $id = $lembrete['id'];
    $texto = "🔔 Lembrete: " . $lembrete['texto'];
    // Grátis: o lembrete chega igual, só sem os botões de conta ("Paguei") e de água, que são do Plus
    $plus = tem_plus_por_id($lembrete['usuario_id']);

    if (!empty($lembrete['eh_conta']) && $plus) {
        if ($lembrete['valor'] !== null) {
            $texto .= ' — ' . formatar_dinheiro($lembrete['valor'], $lembrete['moeda'] ?: 'EUR');
        }
        return [$texto, [
            [['✅ Paguei', "L:pago:$id:$data"]],
            [['⏰ Amanhã', "L:amanha:$id:$data"], ['❌ Não vou pagar', "L:nao:$id:$data"]],
        ]];
    }

    if (eh_lembrete_agua($lembrete['texto']) && $plus) {
        // Lembrete de água: mostra como está o dia e deixa registrar com um toque
        $stmt = get_db()->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$lembrete['usuario_id']]);
        if ($usuario = $stmt->fetch()) {
            $texto .= "\n" . progresso_agua_hoje($usuario);
        }
        return [$texto, [
            [['💧 Bebi um copo', "A:copo:$id"], ['💧 Bebi 500 ml', "A:500:$id"]],
            [['⏰ Daqui 1h', "L:1h:$id:$data"]],
        ]];
    }

    // Compromisso avisado antes: diz quando é e quanto falta; dá pra pedir outro aviso na hora
    if ((int) ($lembrete['antecedencia_min'] ?? 0) > 0) {
        $falta = max(0, strtotime($lembrete['disparar_em']) - time());
        $daqui = $falta < 5400 ? max(1, (int) round($falta / 60)) . ' min'
            : ($falta < 129600 ? (int) round($falta / 3600) . ' h' : (int) round($falta / 86400) . ' dias');
        $texto = sprintf("⏰ Daqui a %s: %s — %s.", $daqui, $lembrete['texto'], formatar_data_hora_amigavel($lembrete['disparar_em']));
        return [$texto, [[['🔔 Me avisa de novo na hora', "L:hora:$id:$data"]]]];
    }

    return [$texto, [[['⏰ Daqui 1h', "L:1h:$id:$data"], ['⏰ Amanhã', "L:amanha:$id:$data"]]]];
}

// Um lembrete adiado é uma cópia; pagamento/pulo sempre conta pro lembrete original e pro vencimento original
function resolver_origem_lembrete($lembrete, $data_ocorrencia) {
    if (!empty($lembrete['origem_id'])) {
        return [(int) $lembrete['origem_id'], $lembrete['ocorrencia_origem'] ?: $data_ocorrencia];
    }
    return [(int) $lembrete['id'], $data_ocorrencia];
}

function buscar_pagamento_conta($lembrete_id, $data_ocorrencia) {
    $stmt = get_db()->prepare("SELECT * FROM pagamentos_conta WHERE lembrete_id = ? AND data_ocorrencia = ?");
    $stmt->execute([$lembrete_id, $data_ocorrencia]);
    return $stmt->fetch() ?: null;
}

function gravar_pagamento_conta($usuario_id, $lembrete_id, $data_ocorrencia, $status) {
    get_db()->prepare("INSERT INTO pagamentos_conta (usuario_id, lembrete_id, data_ocorrencia, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)")
        ->execute([$usuario_id, $lembrete_id, $data_ocorrencia, $status]);
}

// Marca a conta como paga nesse vencimento e registra o gasto (pergunta valor/conta se faltar, pelo fluxo normal de gasto)
function registrar_pagamento_conta($usuario, $lembrete, $data_ocorrencia, $valor = null, $conta = null) {
    [$origem_id, $data] = resolver_origem_lembrete($lembrete, $data_ocorrencia);

    $existente = buscar_pagamento_conta($origem_id, $data);
    if ($existente && $existente['status'] === 'pago') {
        return sprintf("Essa já estava marcada como paga ✅ (\"%s\", vencimento %s).", $lembrete['texto'], date('d/m', strtotime($data)));
    }
    gravar_pagamento_conta($usuario['id'], $origem_id, $data, 'pago');

    $dados = array_filter([
        'valor'       => $valor ?? ($lembrete['valor'] !== null ? (float) $lembrete['valor'] : null),
        'moeda'       => $lembrete['moeda'] ?: null,
        'categoria'   => $lembrete['categoria'] ?: 'contas',
        'descricao'   => descricao_da_conta($lembrete['texto']),
        'conta'       => $conta ?? ($lembrete['conta'] ?: null),
        'lembrete_id' => $origem_id, // se faltar o valor, o que ele responder vira o valor da conta
    ], fn($v) => $v !== null);

    return sprintf("✅ Marquei \"%s\" (vencimento %s) como paga.\n", $lembrete['texto'], date('d/m', strtotime($data)))
         . handle_log_expense($usuario, $dados);
}

function adiar_lembrete($usuario, $lembrete, $data_ocorrencia, $quando) {
    [$origem_id, $data] = resolver_origem_lembrete($lembrete, $data_ocorrencia);

    $novo = match ($quando) {
        '1h'    => date('Y-m-d H:i:00', strtotime('+1 hour')),
        'hora'  => date('Y-m-d H:i:00', strtotime($lembrete['disparar_em'])), // compromisso avisado antes: de novo na hora dele
        default => date('Y-m-d', strtotime('tomorrow')) . ' ' . date('H:i:00', strtotime($lembrete['disparar_em'])),
    };

    get_db()->prepare("INSERT INTO lembretes (usuario_id, texto, disparar_em, valor, moeda, categoria, conta, eh_conta, origem_id, ocorrencia_origem, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente')")
        ->execute([$usuario['id'], $lembrete['texto'], $novo, $lembrete['valor'], $lembrete['moeda'], $lembrete['categoria'], $lembrete['conta'], $lembrete['eh_conta'], $origem_id, $data]);

    return sprintf("⏰ Beleza, te lembro de novo %s.", formatar_data_hora_amigavel($novo));
}

// Clique num botão de lembrete. Devolve [marca curta pra pôr na mensagem original, resposta nova (ou null)]
function handle_callback_lembrete($usuario, $callback_data) {
    if (!preg_match('/^L:(pago|nao|amanha|1h|hora):(\d+):(\d{8})$/', $callback_data, $m)) {
        return [null, "Esse botão não funciona mais 🤔"];
    }
    [, $acao, $id, $data] = $m;
    $data = substr($data, 0, 4) . '-' . substr($data, 4, 2) . '-' . substr($data, 6, 2);

    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id, $usuario['id']]);
    $lembrete = $stmt->fetch();
    if (!$lembrete) {
        return [null, "Não achei mais esse lembrete 🤔"];
    }
    // Botão "Paguei"/"Não vou pagar" de um aviso antigo, depois que o teste acabou
    if (in_array($acao, ['pago', 'nao'], true) && ($oferta = exige_plus($usuario, 'As contas a pagar'))) {
        return [null, $oferta];
    }

    switch ($acao) {
        case 'pago':
            return ['✅ Paga', registrar_pagamento_conta($usuario, $lembrete, $data)];
        case 'nao':
            [$origem_id, $data_origem] = resolver_origem_lembrete($lembrete, $data);
            gravar_pagamento_conta($usuario['id'], $origem_id, $data_origem, 'pulado');
            return ['❌ Não vai pagar dessa vez', sprintf("👍 Ok, marquei que \"%s\" (vencimento %s) não vai ser paga dessa vez.", $lembrete['texto'], date('d/m', strtotime($data_origem)))];
        case 'amanha':
        case '1h':
        case 'hora':
            if ($acao === 'hora' && strtotime($lembrete['disparar_em']) <= time()) {
                return [null, "Já é a hora de \"{$lembrete['texto']}\" 🙂"];
            }
            $resposta = adiar_lembrete($usuario, $lembrete, $data, $acao);
            return [['1h' => '⏰ Adiado 1h', 'amanha' => '⏰ Adiado pra amanhã', 'hora' => '🔔 Aviso na hora marcado'][$acao], $resposta];
    }
    return [null, null];
}

// Vencimentos (datas Y-m-d) de uma conta entre $inicio e $fim — nunca antes do dia em que o lembrete foi criado
function vencimentos_no_periodo($lembrete, $inicio, $fim) {
    $base = $lembrete['disparar_em'];
    $hora = date('H:i:s', strtotime($base));
    $criado = $lembrete['criado_em'] ?? $base;
    $datas = [];

    switch ($lembrete['recorrente']) {
        case null:
        case '':
            $d = date('Y-m-d', strtotime($base));
            if ($d >= $inicio && $d <= $fim) $datas[] = $d;
            break;
        case 'mensal':
            $dia = (int) ($lembrete['dia_mes'] ?: date('j', strtotime($base)));
            for ($ts = strtotime(date('Y-m-01', strtotime($inicio))); date('Y-m-d', $ts) <= $fim; $ts = strtotime('+1 month', $ts)) {
                $d = substr(data_no_dia_do_mes((int) date('Y', $ts), (int) date('n', $ts), $dia, '00:00:00'), 0, 10);
                if ($d >= $inicio && $d <= $fim) $datas[] = $d;
            }
            break;
        case 'semanal':
            $ts = strtotime(date('Y-m-d', strtotime($base)));
            while (date('Y-m-d', $ts) > $inicio) $ts = strtotime('-7 days', $ts);
            for (; date('Y-m-d', $ts) <= $fim; $ts = strtotime('+7 days', $ts)) {
                $d = date('Y-m-d', $ts);
                if ($d >= $inicio) $datas[] = $d;
            }
            break;
        // diario / horas: não faz sentido como conta a pagar
    }
    // Vencimento antes da criação do lembrete não conta (ex: criado dia 27 às 15h pra "todo dia 27 às 9h" — o 1º é o do mês que vem)
    return array_values(array_filter($datas, fn($d) => "$d $hora" >= $criado || empty($lembrete['recorrente'])));
}

// Todas as contas do período com o status de cada vencimento: pendente, pago ou pulado
function listar_vencimentos_contas($usuario_id, $inicio, $fim) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND eh_conta = 1 AND origem_id IS NULL AND status <> 'cancelado'");
    $stmt->execute([$usuario_id]);
    $lembretes = $stmt->fetchAll();

    $stmt2 = $pdo->prepare("SELECT lembrete_id, data_ocorrencia, status FROM pagamentos_conta WHERE usuario_id = ? AND data_ocorrencia BETWEEN ? AND ?");
    $stmt2->execute([$usuario_id, $inicio, $fim]);
    $status = [];
    foreach ($stmt2->fetchAll() as $p) {
        $status[$p['lembrete_id'] . '|' . $p['data_ocorrencia']] = $p['status'];
    }

    $vencimentos = [];
    foreach ($lembretes as $l) {
        foreach (vencimentos_no_periodo($l, $inicio, $fim) as $d) {
            $vencimentos[] = ['lembrete' => $l, 'data' => $d, 'status' => $status[$l['id'] . '|' . $d] ?? 'pendente'];
        }
    }
    usort($vencimentos, fn($a, $b) => strcmp($a['data'], $b['data']));
    return $vencimentos;
}

function handle_contas_pendentes($usuario, $dados) {
    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $periodo = $dados['periodo'] ?? 'mes';
    $ref = match (true) {
        $periodo === 'proximo_mes'                   => strtotime('first day of next month'),
        $periodo === 'mes_passado'                   => strtotime('first day of last month'),
        (bool) preg_match('/^\d{4}-\d{2}$/', $periodo) => strtotime($periodo . '-01'),
        default                                      => time(),
    };
    $inicio = date('Y-m-01', $ref);
    $fim = date('Y-m-t', $ref);

    $vencimentos = listar_vencimentos_contas($usuario['id'], $inicio, $fim);
    if (!$vencimentos) {
        // Tem conta marcada, só não vence nesse mês? Mostra a próxima em vez de dizer que não tem nada
        $proximas = listar_vencimentos_contas($usuario['id'], date('Y-m-d', strtotime($fim . ' +1 day')), date('Y-m-d', strtotime($fim . ' +3 months')));
        if ($proximas) {
            $p = $proximas[0];
            return sprintf("Nenhuma conta vence em %s. 🙌 A próxima é %s em %s%s.",
                $meses[(int) date('n', $ref)], descricao_da_conta($p['lembrete']['texto']), date('d/m', strtotime($p['data'])),
                $p['status'] === 'pago' ? ' (já paga ✅)' : '');
        }
        return sprintf("Não tenho nenhuma conta marcada pra %s. 👀 Pra criar: \"me lembra de pagar o aluguel todo dia 5, são 600 euros\".", $meses[(int) date('n', $ref)]);
    }

    $hoje = date('Y-m-d');
    $linha = function ($v) use ($hoje) {
        $l = $v['lembrete'];
        $valor = $l['valor'] !== null ? ' — ' . formatar_dinheiro($l['valor'], $l['moeda'] ?: 'EUR') : '';
        $atraso = ($v['status'] === 'pendente' && $v['data'] < $hoje) ? ' ⚠️ atrasada' : '';
        return sprintf("• %s — %s%s%s", date('d/m', strtotime($v['data'])), descricao_da_conta($l['texto']), $valor, $atraso);
    };

    $pendentes = array_values(array_filter($vencimentos, fn($v) => $v['status'] === 'pendente'));
    $pagas     = array_filter($vencimentos, fn($v) => $v['status'] === 'pago');
    $puladas   = array_filter($vencimentos, fn($v) => $v['status'] === 'pulado');

    $texto = "💸 Contas de " . $meses[(int) date('n', $ref)] . ":\n";
    if ($pendentes) {
        $texto .= "\n⏳ Falta pagar:\n" . implode("\n", array_map($linha, $pendentes)) . "\n";
        $totais = [];
        $sem_valor = 0;
        foreach ($pendentes as $v) {
            if ($v['lembrete']['valor'] === null) { $sem_valor++; continue; }
            $moeda = $v['lembrete']['moeda'] ?: 'EUR';
            $totais[$moeda] = ($totais[$moeda] ?? 0) + $v['lembrete']['valor'];
        }
        if ($totais) {
            $texto .= "Total: " . implode(' + ', array_map(fn($m, $t) => formatar_dinheiro($t, $m), array_keys($totais), $totais))
                    . ($sem_valor ? " (+{$sem_valor} sem valor definido)" : '') . "\n";
        }
    } else {
        $texto .= "\n🎉 Tudo pago!\n";
    }
    if ($pagas)   $texto .= "\n✅ Pagas:\n" . implode("\n", array_map($linha, $pagas)) . "\n";
    if ($puladas) $texto .= "\n❌ Não vai pagar:\n" . implode("\n", array_map($linha, $puladas)) . "\n";

    // Botão "Paguei" pras pendentes (até 5)
    $botoes = [];
    foreach (array_slice($pendentes, 0, 5) as $v) {
        $rotulo = mb_strimwidth('✅ Paguei: ' . descricao_da_conta($v['lembrete']['texto']), 0, 40, '…');
        $botoes[] = [[$rotulo, sprintf('L:pago:%d:%s', $v['lembrete']['id'], str_replace('-', '', $v['data']))]];
    }
    if ($botoes) botoes_resposta($botoes);

    return trim($texto);
}

// ============================================================================
// Gastos fixos (arrendamento, cartão, internet...) = conta a pagar mensal. Por baixo é um lembrete com eh_conta = 1.
// ============================================================================

function handle_cadastrar_conta_fixa($usuario, $dados, $texto_original = '') {
    $texto = trim($dados['texto_lembrete'] ?? '') ?: trim($dados['descricao'] ?? '');
    // "cadastra como gasto fixo" / "cadastra isso como fixo" logo depois de anotar um gasto: é dele que a pessoa está falando
    if ($texto === '' && preg_match('/\b(como|isso|esse|este|essa|esta|ele|ela)\b/iu', $texto_original)) {
        $stmt = get_db()->prepare("SELECT descricao, valor, moeda, data, conta, categoria FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND criado_em >= NOW() - INTERVAL 10 MINUTE AND descricao <> '' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$usuario['id']]);
        if ($ultimo = $stmt->fetch()) {
            $texto = $ultimo['descricao'];
            $dados['valor'] ??= (float) $ultimo['valor'];
            $dados['moeda'] ??= $ultimo['moeda'];
            $dados['conta'] ??= $ultimo['conta'];
            $dados['dia_mes'] ??= (int) date('j', strtotime($ultimo['data']));
        }
    }
    if ($texto === '') {
        salvar_estado($usuario['id'], 'aguardando_nome_conta_fixa', $dados);
        return "Qual é o gasto fixo? 📌 (ex: \"arrendamento\", \"cartão de crédito\", \"internet\")";
    }
    if (!preg_match('/^(pagar|pago|paga)\b/ui', $texto)) {
        $texto = 'pagar ' . $texto;
    }
    $dados['texto_lembrete'] = $texto;

    // Semanal ("toda sexta pago a empregada") usa o fluxo normal de lembrete
    if (($dados['recorrente'] ?? null) === 'semanal') {
        return "📌 Gasto fixo cadastrado!\n" . handle_set_reminder($usuario, $dados);
    }

    $dia = (int) ($dados['dia_mes'] ?? 0);
    if ($dia < 1 || $dia > 31) {
        salvar_estado($usuario['id'], 'aguardando_dia_conta_fixa', $dados);
        botoes_resposta([
            array_map(fn($d) => botao_texto((string) $d, (string) $d), [1, 5, 8, 10]),
            array_map(fn($d) => botao_texto((string) $d, (string) $d), [15, 20, 25, 30]),
        ]);
        return sprintf("Em que dia do mês vence %s? 📅 (toca num ou escreve o dia)", conta_com_artigo($texto));
    }

    $hora = (!empty($dados['disparar_em']) && strlen($dados['disparar_em']) >= 16) ? substr($dados['disparar_em'], 11, 5) : '09:00';
    $extras = [
        'valor'     => isset($dados['valor']) && is_numeric($dados['valor']) ? (float) $dados['valor'] : null,
        'moeda'     => $dados['moeda'] ?? ($usuario['moeda_padrao'] ?? 'EUR'),
        'conta'     => !empty($dados['conta']) ? salvar_conta($usuario['id'], $dados['conta']) : null,
        'categoria' => !empty($dados['categoria']) ? normalizar_categoria($dados['categoria']) : null,
    ];

    return "📌 Gasto fixo cadastrado!\n"
         . salvar_lembrete($usuario['id'], $texto, primeira_ocorrencia_mensal($dia, "$hora:00"), 'mensal', null, $dia, $extras);
}

function handle_listar_contas_fixas($usuario) {
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND eh_conta = 1 AND origem_id IS NULL AND status = 'pendente' AND recorrente IN ('mensal', 'semanal') ORDER BY recorrente, dia_mes, disparar_em");
    $stmt->execute([$usuario['id']]);
    $contas = $stmt->fetchAll();

    if (!$contas) {
        return "Você ainda não tem gastos fixos cadastrados. 📌\nPra cadastrar: \"gasto fixo: arrendamento 700 euros todo dia 1\" ou \"cartão de crédito vence dia 10\".";
    }

    $totais = [];
    $variaveis = 0;
    $linhas = [];
    foreach ($contas as $c) {
        $quando = $c['recorrente'] === 'mensal'
            ? 'Dia ' . ($c['dia_mes'] ?: date('j', strtotime($c['disparar_em'])))
            : ucfirst(ltrim(rotulo_recorrencia('semanal', null, 'travessao', null, $c['disparar_em']), ' —'));
        $valor = $c['valor'] !== null ? formatar_dinheiro($c['valor'], $c['moeda'] ?: 'EUR') : 'valor varia';
        $conta = $c['conta'] ? ' — ' . ucfirst($c['conta']) : '';
        $linhas[] = sprintf("• %s — %s — %s%s", $quando, descricao_da_conta($c['texto']), $valor, $conta);

        if ($c['valor'] === null) {
            $variaveis++;
        } else {
            // Semanal conta ~4,33 vezes por mês na estimativa
            $mensal = $c['recorrente'] === 'semanal' ? $c['valor'] * 52 / 12 : $c['valor'];
            $moeda = $c['moeda'] ?: 'EUR';
            $totais[$moeda] = ($totais[$moeda] ?? 0) + $mensal;
        }
    }

    $texto = "📌 Seus gastos fixos:\n" . implode("\n", $linhas) . "\n";
    if ($totais) {
        $texto .= "\nTotal fixo por mês: " . implode(' + ', array_map(fn($m, $t) => formatar_dinheiro($t, $m), array_keys($totais), $totais))
                . ($variaveis ? " (+{$variaveis} com valor variável)" : '') . "\n";
    }

    // Situação deste mês
    $vencimentos = listar_vencimentos_contas($usuario['id'], date('Y-m-01'), date('Y-m-t'));
    $pagas = count(array_filter($vencimentos, fn($v) => $v['status'] === 'pago'));
    $pendentes = count(array_filter($vencimentos, fn($v) => $v['status'] === 'pendente'));
    if ($vencimentos) {
        $texto .= sprintf("\nEste mês: %d paga%s, %d pendente%s.", $pagas, $pagas === 1 ? '' : 's', $pendentes, $pendentes === 1 ? '' : 's');
        if ($pendentes) {
            botoes_resposta([[botao_texto('💸 Ver o que falta pagar', 'o que falta pagar?')]]);
        }
    }

    return trim($texto);
}

// "paguei o seguro" — se bater com uma conta marcada, dá baixa nela; senão é um gasto normal
function handle_marcar_conta_paga($usuario, $dados) {
    $termo = trim($dados['termo'] ?? '');
    $valor = isset($dados['valor']) && is_numeric($dados['valor']) ? (float) $dados['valor'] : null;
    $conta = $dados['conta'] ?? null;

    $candidatos = [];
    if ($termo !== '') {
        // Vencimentos do mês passado até o fim deste — pega conta atrasada também
        $inicio = date('Y-m-01', strtotime('first day of last month'));
        $fim = date('Y-m-t');
        foreach (listar_vencimentos_contas($usuario['id'], $inicio, $fim) as $v) {
            if ($v['status'] === 'pendente' && mb_stripos($v['lembrete']['texto'], $termo) !== false) {
                $candidatos[] = $v;
            }
        }
    }

    if (!$candidatos) {
        // Não é uma conta cadastrada (ou já está paga) — registra como gasto normal, na categoria "contas"
        $resposta = handle_log_expense($usuario, array_filter([
            'valor' => $valor, 'moeda' => $dados['moeda'] ?? null, 'descricao' => $termo ?: null,
            'categoria' => $dados['categoria'] ?? 'contas', 'conta' => $conta, 'data' => $dados['data'] ?? null,
        ], fn($v) => $v !== null));

        // Se não existe nenhum fixo com esse nome, sugere cadastrar (com o dia, se ele disse quando pagou)
        $existe = get_db()->prepare("SELECT 1 FROM lembretes WHERE usuario_id = ? AND eh_conta = 1 AND status <> 'cancelado' AND texto LIKE ? LIMIT 1");
        $existe->execute([$usuario['id'], '%' . $termo . '%']);
        if ($termo !== '' && !$existe->fetch()) {
            $dia = !empty($dados['data']) ? ', vence dia ' . (int) date('j', strtotime($dados['data'])) : '';
            $resposta .= sprintf("\n\n💡 Se for uma conta de todo mês, manda \"cadastra %s como gasto fixo%s\" que eu passo a te lembrar.", $termo, $dia);
        }
        return $resposta;
    }

    // A mais antiga que já venceu; se nenhuma venceu ainda, a próxima (pagou adiantado)
    $hoje = date('Y-m-d');
    $vencidas = array_values(array_filter($candidatos, fn($v) => $v['data'] <= $hoje));
    $escolhida = $vencidas[0] ?? $candidatos[0];

    return registrar_pagamento_conta($usuario, $escolhida['lembrete'], $escolhida['data'], $valor, $conta);
}

// ============================================================================
// Orçamento mensal por categoria (categoria '*' = total de todos os gastos do mês)
// ============================================================================

const ORCAMENTO_TOTAL = '*';

// "restaurantes" -> "restaurante" se o usuário já usa "restaurante" (evita orçamento numa categoria que nunca bate)
function casar_categoria_existente($usuario_id, $categoria) {
    $categoria = normalizar_categoria($categoria);
    $stmt = get_db()->prepare("SELECT DISTINCT categoria FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND categoria <> 'pendente'");
    $stmt->execute([$usuario_id]);
    $existentes = array_merge(array_column($stmt->fetchAll(), 'categoria'), ['mercado', 'restaurante', 'transporte', 'lazer', 'contas', 'saude', 'roupa', 'presente']);

    foreach ($existentes as $e) {
        if (sem_acentos($e) === sem_acentos($categoria)) return $e;
    }
    foreach ($existentes as $e) {
        if (levenshtein(sem_acentos($e), sem_acentos($categoria)) <= 2 && mb_strlen($categoria) >= 5) return $e;
    }
    return $categoria;
}

function eh_orcamento_total($categoria) {
    return in_array(sem_acentos(trim($categoria ?? '')), ['*', 'total', 'geral', 'tudo', 'todos', 'todas', 'mes', 'mensal'], true);
}

function rotulo_orcamento($categoria) {
    return $categoria === ORCAMENTO_TOTAL ? 'Total do mês' : ucfirst(rotulo_categoria($categoria));
}

function gasto_do_mes($usuario_id, $categoria, $moeda) {
    $sql = "SELECT COALESCE(SUM(valor), 0) FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND moeda = ? AND data BETWEEN ? AND ?";
    $params = [$usuario_id, $moeda, date('Y-m-01'), date('Y-m-t')];
    if ($categoria !== ORCAMENTO_TOTAL) {
        $sql .= " AND categoria = ?";
        $params[] = $categoria;
    }
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function dias_restantes_no_mes() {
    return (int) date('t') - (int) date('j') + 1; // conta hoje
}

// Linha de situação de um orçamento. $detalhado = com barra (listagem); senão, linha curta (depois de um gasto)
function linha_orcamento($orcamento, $gasto, $detalhado = false) {
    $limite = (float) $orcamento['valor'];
    $moeda = $orcamento['moeda'];
    $pct = $limite > 0 ? (int) round($gasto / $limite * 100) : 0;
    $nome = rotulo_orcamento($orcamento['categoria']);
    $sobra = $limite - $gasto;
    $dias = dias_restantes_no_mes();

    if ($detalhado) {
        $cheios = min(10, (int) floor($pct / 10));
        $barra = str_repeat('▓', $cheios) . str_repeat('░', 10 - $cheios);
        $situacao = $sobra >= 0
            ? sprintf("sobram %s (~%s/dia)", formatar_dinheiro($sobra, $moeda), formatar_dinheiro($sobra / $dias, $moeda))
            : sprintf("passou %s 🚨", formatar_dinheiro(-$sobra, $moeda));
        $alerta = ($pct >= 80 && $pct < 100) ? ' ⚠️' : '';
        return sprintf("• %s — %s de %s\n  %s %d%%%s — %s", $nome, formatar_dinheiro($gasto, $moeda), formatar_dinheiro($limite, $moeda), $barra, $pct, $alerta, $situacao);
    }

    if ($pct >= 100) {
        return sprintf("🚨 %s: %s de %s — passou %s do orçamento!", $nome, formatar_dinheiro($gasto, $moeda), formatar_dinheiro($limite, $moeda), formatar_dinheiro(-$sobra, $moeda));
    }
    if ($pct >= 80) {
        return sprintf("⚠️ %s: %s de %s (%d%%) — sobram %s pra %d dia%s (~%s/dia).", $nome, formatar_dinheiro($gasto, $moeda), formatar_dinheiro($limite, $moeda), $pct,
            formatar_dinheiro($sobra, $moeda), $dias, $dias === 1 ? '' : 's', formatar_dinheiro($sobra / $dias, $moeda));
    }
    return sprintf("📊 %s: %s de %s (%d%%)", $nome, formatar_dinheiro($gasto, $moeda), formatar_dinheiro($limite, $moeda), $pct);
}

// Depois de registrar/alterar um gasto: situação do orçamento da categoria dele e do total (se existirem). '' se não houver.
function linhas_orcamento_apos_gasto($usuario_id, $transacao_id) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT categoria, moeda, data, tipo FROM transacoes WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$transacao_id, $usuario_id]);
    $t = $stmt->fetch();
    if (!$t || $t['tipo'] !== 'gasto' || substr($t['data'], 0, 7) !== date('Y-m') || !tem_plus_por_id($usuario_id)) {
        return ''; // orçamento é do mês atual (e é recurso do Plus)
    }

    $stmt = $pdo->prepare("SELECT * FROM orcamentos WHERE usuario_id = ? AND moeda = ? AND categoria IN (?, ?) ORDER BY categoria = ?");
    $stmt->execute([$usuario_id, $t['moeda'], $t['categoria'], ORCAMENTO_TOTAL, ORCAMENTO_TOTAL]);
    $linhas = [];
    foreach ($stmt->fetchAll() as $o) {
        $linhas[] = linha_orcamento($o, gasto_do_mes($usuario_id, $o['categoria'], $o['moeda']));
    }
    return $linhas ? "\n" . implode("\n", $linhas) : '';
}

function handle_definir_orcamento($usuario, $dados) {
    $valor = isset($dados['valor']) && is_numeric($dados['valor']) ? (float) $dados['valor'] : null;
    $bruta = trim($dados['categoria'] ?? '');
    $moeda = $dados['moeda'] ?? ($usuario['moeda_padrao'] ?? 'EUR');

    if ($bruta === '' && $valor === null) {
        return "Pra qual categoria e quanto por mês? 📊 Ex: \"quero gastar no máximo 300 em restaurante por mês\" ou \"meu orçamento total é 1500 por mês\".";
    }
    if ($valor === null || $valor <= 0) {
        return sprintf("Quanto por mês pra %s? Ex: \"orçamento de 300 pra %s\".", $bruta ?: 'essa categoria', $bruta ?: 'restaurante');
    }

    $categoria = ($bruta === '' || eh_orcamento_total($bruta)) ? ORCAMENTO_TOTAL : casar_categoria_existente($usuario['id'], $bruta);

    get_db()->prepare("INSERT INTO orcamentos (usuario_id, categoria, valor, moeda) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute([$usuario['id'], $categoria, $valor, $moeda]);

    $o = ['categoria' => $categoria, 'valor' => $valor, 'moeda' => $moeda];
    return sprintf("📊 Orçamento definido: %s — %s por mês.\nAté agora em %s: %s\n\nA cada gasto %s eu te mostro como está, e aviso quando passar de 80%%.",
        rotulo_orcamento($categoria), formatar_dinheiro($valor, $moeda), date('m/Y'),
        linha_orcamento($o, gasto_do_mes($usuario['id'], $categoria, $moeda)),
        $categoria === ORCAMENTO_TOTAL ? 'que você registrar' : 'em ' . rotulo_categoria($categoria));
}

function handle_listar_orcamentos($usuario, $dados) {
    $sql = "SELECT * FROM orcamentos WHERE usuario_id = ?";
    $params = [$usuario['id']];
    if (!empty($dados['categoria'])) {
        $categoria = eh_orcamento_total($dados['categoria']) ? ORCAMENTO_TOTAL : casar_categoria_existente($usuario['id'], $dados['categoria']);
        $sql .= " AND categoria = ?";
        $params[] = $categoria;
    }
    $sql .= " ORDER BY categoria = '*', categoria";
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    $orcamentos = $stmt->fetchAll();

    if (!$orcamentos) {
        return !empty($dados['categoria'])
            ? sprintf("Você não tem orçamento pra %s. 📊 Quer criar? Ex: \"orçamento de 200 pra %s\".", $dados['categoria'], $dados['categoria'])
            : "Você ainda não tem nenhum orçamento. 📊\nPra criar: \"quero gastar no máximo 300 em restaurante por mês\" ou \"meu orçamento total é 1500 por mês\".";
    }

    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $dias = dias_restantes_no_mes();
    $texto = sprintf("📊 Orçamento de %s (falta%s %d dia%s):\n\n", $meses[(int) date('n')], $dias === 1 ? '' : 'm', $dias, $dias === 1 ? '' : 's');
    $linhas = [];
    foreach ($orcamentos as $o) {
        $linhas[] = linha_orcamento($o, gasto_do_mes($usuario['id'], $o['categoria'], $o['moeda']), true);
    }
    return $texto . implode("\n", $linhas);
}

function handle_remover_orcamento($usuario, $dados) {
    $bruta = trim($dados['categoria'] ?? '');
    if ($bruta === '') {
        return "Qual orçamento você quer tirar? Ex: \"tira o orçamento de lazer\".";
    }
    $categoria = eh_orcamento_total($bruta) ? ORCAMENTO_TOTAL : casar_categoria_existente($usuario['id'], $bruta);
    $stmt = get_db()->prepare("DELETE FROM orcamentos WHERE usuario_id = ? AND categoria = ?");
    $stmt->execute([$usuario['id'], $categoria]);

    return $stmt->rowCount()
        ? sprintf("Pronto, tirei o orçamento de %s. ✅", rotulo_orcamento($categoria))
        : sprintf("Você não tinha orçamento pra %s. 🤔", rotulo_orcamento($categoria));
}

// ============================================================================
// Água: registro do que bebeu (em ml), meta diária e relatórios
// ============================================================================

const AGUA_META_PADRAO = 2000;
const AGUA_COPO_PADRAO = 250;

function eh_lembrete_agua($texto) {
    return (bool) preg_match('/\b(agua|hidrat\w*)\b/u', sem_acentos($texto));
}

function meta_agua($usuario) { return (int) ($usuario['agua_meta_ml'] ?? 0) ?: AGUA_META_PADRAO; }
function copo_agua($usuario) { return (int) ($usuario['agua_copo_ml'] ?? 0) ?: AGUA_COPO_PADRAO; }

// 750 -> "750 ml", 1750 -> "1,75 L", 2000 -> "2 L"
function formatar_ml($ml) {
    if ($ml < 1000) return $ml . ' ml';
    return rtrim(rtrim(number_format($ml / 1000, 2, ',', ''), '0'), ',') . ' L';
}

function agua_no_dia($usuario_id, $dia) {
    $stmt = get_db()->prepare("SELECT COALESCE(SUM(ml), 0) FROM agua_registros WHERE usuario_id = ? AND registrado_em BETWEEN ? AND ?");
    $stmt->execute([$usuario_id, "$dia 00:00:00", "$dia 23:59:59"]);
    return (int) $stmt->fetchColumn();
}

function progresso_agua_hoje($usuario) {
    $total = agua_no_dia($usuario['id'], date('Y-m-d'));
    $meta = meta_agua($usuario);
    $pct = (int) round($total / $meta * 100);
    $barra = str_repeat('▓', min(10, (int) floor($pct / 10))) . str_repeat('░', 10 - min(10, (int) floor($pct / 10)));

    $texto = sprintf("💧 Hoje: %s de %s\n%s %d%%", formatar_ml($total), formatar_ml($meta), $barra, $pct);
    if ($total >= $meta) {
        $texto .= " — 🎉 meta batida!";
    } elseif ($total > 0) {
        $falta = $meta - $total;
        $texto .= sprintf(" — faltam %s (~%d copo%s)", formatar_ml($falta), $n = (int) ceil($falta / copo_agua($usuario)), $n === 1 ? '' : 's');
    }
    return $texto;
}

function registrar_agua($usuario, $ml) {
    $ml = max(1, min(5000, (int) $ml)); // limite de sanidade: nada de "bebi 50 litros" por engano
    get_db()->prepare("INSERT INTO agua_registros (usuario_id, ml, registrado_em) VALUES (?, ?, ?)")
        ->execute([$usuario['id'], $ml, date('Y-m-d H:i:s')]);

    $antes = agua_no_dia($usuario['id'], date('Y-m-d')) - $ml;
    $texto = sprintf("💧 +%s anotado!\n%s", formatar_ml($ml), progresso_agua_hoje($usuario));
    if ($antes < meta_agua($usuario) && $antes + $ml >= meta_agua($usuario)) {
        $texto .= "\n\nBoa! Bateu a meta de água de hoje. 💪";
    }
    return $texto;
}

// "bebi 2 copos", "bebi 500ml", "bebi 1,5 litro", "tomei água" (sem quantidade = 1 copo)
function handle_registrar_agua($usuario, $dados) {
    $ml = null;
    if (!empty($dados['ml']) && is_numeric($dados['ml']))         $ml = (float) $dados['ml'];
    elseif (!empty($dados['litros']) && is_numeric($dados['litros'])) $ml = (float) $dados['litros'] * 1000;
    elseif (!empty($dados['copos']) && is_numeric($dados['copos']))   $ml = (float) $dados['copos'] * copo_agua($usuario);

    $nota = '';
    if ($ml === null) {
        $ml = copo_agua($usuario);
        $nota = sprintf("\n(Contei como 1 copo de %s — se foi outra quantidade, me diz tipo \"bebi 500ml\".)", formatar_ml($ml));
    }
    return registrar_agua($usuario, $ml) . $nota;
}

function handle_apagar_agua($usuario) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id, ml, registrado_em FROM agua_registros WHERE usuario_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$usuario['id']]);
    $r = $stmt->fetch();
    if (!$r) {
        return "Não tem nenhum registro de água pra apagar. 💧";
    }
    $pdo->prepare("DELETE FROM agua_registros WHERE id = ?")->execute([$r['id']]);
    return sprintf("🗑️ Apaguei o registro de %s (%s).\n%s", formatar_ml($r['ml']), formatar_data_hora_amigavel($r['registrado_em']), progresso_agua_hoje($usuario));
}

// "minha meta de água é 2,5 litros" / "meu copo é de 300ml"
function handle_definir_meta_agua($usuario, $dados) {
    $pdo = get_db();
    $partes = [];

    $meta = !empty($dados['meta_ml']) ? (int) $dados['meta_ml'] : (!empty($dados['litros']) ? (int) round($dados['litros'] * 1000) : null);
    if ($meta) {
        $meta = max(250, min(8000, $meta));
        $pdo->prepare("UPDATE usuarios SET agua_meta_ml = ? WHERE id = ?")->execute([$meta, $usuario['id']]);
        $usuario['agua_meta_ml'] = $meta;
        $partes[] = "meta diária de água: " . formatar_ml($meta);
    }
    if (!empty($dados['copo_ml'])) {
        $copo = max(50, min(2000, (int) $dados['copo_ml']));
        $pdo->prepare("UPDATE usuarios SET agua_copo_ml = ? WHERE id = ?")->execute([$copo, $usuario['id']]);
        $usuario['agua_copo_ml'] = $copo;
        $partes[] = "copo de " . formatar_ml($copo);
    }

    if (!$partes) {
        return sprintf("Qual a meta? Ex: \"minha meta de água é 2 litros\" (hoje está em %s) ou \"meu copo é de 300ml\" (hoje %s).", formatar_ml(meta_agua($usuario)), formatar_ml(copo_agua($usuario)));
    }
    return "✅ Anotado — " . implode(' e ', $partes) . ".\n" . progresso_agua_hoje($usuario);
}

function handle_relatorio_agua($usuario, $dados) {
    $periodo = $dados['periodo'] ?? 'hoje';
    if ($periodo === 'hoje') {
        $stmt = get_db()->prepare("SELECT ml, registrado_em FROM agua_registros WHERE usuario_id = ? AND registrado_em >= ? ORDER BY registrado_em");
        $stmt->execute([$usuario['id'], date('Y-m-d 00:00:00')]);
        $registros = $stmt->fetchAll();
        if (!$registros) {
            botoes_resposta([[['💧 Bebi um copo', 'A:copo:0'], ['💧 Bebi 500 ml', 'A:500:0']]]);
            return "Ainda não anotei água hoje. 💧 Bebeu? Toca abaixo ou me diz tipo \"bebi 2 copos\".";
        }
        $horas = implode(', ', array_map(fn($r) => date('H:i', strtotime($r['registrado_em'])) . ' (' . formatar_ml($r['ml']) . ')', $registros));
        return progresso_agua_hoje($usuario) . "\n\n🕐 " . $horas;
    }

    // Semana (últimos 7 dias) ou mês (do dia 1 até hoje) — mas nunca antes do 1º registro, pra não encher de "nada" nem puxar a média pra baixo
    $inicio = $periodo === 'mes' ? date('Y-m-01') : date('Y-m-d', strtotime('-6 days'));
    $primeiro = get_db()->prepare("SELECT MIN(DATE(registrado_em)) FROM agua_registros WHERE usuario_id = ?");
    $primeiro->execute([$usuario['id']]);
    $primeiro = $primeiro->fetchColumn();
    if (!$primeiro) {
        return "Ainda não tenho nenhum registro de água. 💧 Me diz quando beber (\"bebi 500ml\") ou cria um lembrete: \"me lembra de beber água de 2 em 2 horas\".";
    }
    $inicio = max($inicio, $primeiro);
    $stmt = get_db()->prepare("SELECT DATE(registrado_em) as dia, SUM(ml) as total FROM agua_registros WHERE usuario_id = ? AND registrado_em >= ? GROUP BY DATE(registrado_em)");
    $stmt->execute([$usuario['id'], "$inicio 00:00:00"]);
    $por_dia = array_column($stmt->fetchAll(), 'total', 'dia');

    $meta = meta_agua($usuario);
    $dias_semana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $linhas = [];
    $bateu = 0;
    $soma = 0;
    $n_dias = 0;
    for ($ts = strtotime($inicio); date('Y-m-d', $ts) <= date('Y-m-d'); $ts = strtotime('+1 day', $ts)) {
        $dia = date('Y-m-d', $ts);
        $total = (int) ($por_dia[$dia] ?? 0);
        $n_dias++;
        $soma += $total;
        if ($total >= $meta) $bateu++;
        $marca = $total >= $meta ? '✅' : ($total > 0 ? '▫️' : '—');
        $linhas[] = sprintf("%s %s %s — %s", $marca, $dias_semana[(int) date('w', $ts)], date('d/m', $ts), $total ? formatar_ml($total) : 'nada');
    }

    $titulo = $periodo === 'mes' ? 'deste mês' : 'dos últimos 7 dias';
    return sprintf("💧 Água %s (meta %s/dia):\n\n%s\n\nMédia: %s/dia · Meta batida em %d de %d dia%s.",
        $titulo, formatar_ml($meta), implode("\n", $linhas), formatar_ml((int) round($soma / max(1, $n_dias))), $bateu, $n_dias, $n_dias === 1 ? '' : 's');
}

// Botão do aviso de água: "A:<copo|ml>:<id do lembrete>". Devolve [marca pra mensagem original, resposta]
function handle_callback_agua($usuario, $callback_data) {
    if (!preg_match('/^A:(copo|\d+):\d+$/', $callback_data, $m)) {
        return [null, "Esse botão não funciona mais 🤔"];
    }
    if ($oferta = exige_plus($usuario, 'O controle de água')) {
        return [null, $oferta];
    }
    $ml = $m[1] === 'copo' ? copo_agua($usuario) : (int) $m[1];
    return ['💧 +' . formatar_ml($ml), registrar_agua($usuario, $ml)];
}

// ============================================================================
// Resumos (semanal/mensal): comparação com o período anterior, categorias, orçamento, contas e água.
// Usado pelo cron_resumo.php (automático) e pelo intent "resumo" (quando o usuário pede).
// ============================================================================

const MESES_NOME = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

// [início, fim, início do período anterior, fim do período anterior, título]
function intervalo_resumo($tipo) {
    $d = fn($expr, $fmt = 'Y-m-d') => date($fmt, strtotime($expr));
    switch ($tipo) {
        case 'semana_fechada': // cron de segunda: a semana que acabou (seg a dom)
            $ini = $d('-7 days'); $fim = $d('-1 day');
            return [$ini, $fim, $d('-14 days'), $d('-8 days'), sprintf("Resumo da semana (%s a %s)", date('d/m', strtotime($ini)), date('d/m', strtotime($fim)))];
        case 'mes':            // mês atual até hoje, comparado com os mesmos dias do mês passado
            $dia = (int) date('j');
            $ini_ant = $d('first day of last month');
            $fim_ant = date('Y-m-', strtotime($ini_ant)) . sprintf('%02d', min($dia, (int) date('t', strtotime($ini_ant))));
            return [date('Y-m-01'), date('Y-m-d'), $ini_ant, $fim_ant, sprintf("Resumo de %s (até dia %d)", MESES_NOME[(int) date('n')], $dia)];
        case 'mes_passado':    // cron do dia 1: o mês que fechou inteiro
            $ini = $d('first day of last month');
            $ini_ant = date('Y-m-d', strtotime('first day of last month', strtotime($ini)));
            return [$ini, date('Y-m-t', strtotime($ini)), $ini_ant, date('Y-m-t', strtotime($ini_ant)), "Resumo de " . MESES_NOME[(int) date('n', strtotime($ini))]];
        case 'semana':         // últimos 7 dias até hoje
        default:
            $ini = $d('-6 days');
            return [$ini, date('Y-m-d'), $d('-13 days'), $d('-7 days'), sprintf("Resumo dos últimos 7 dias (%s a %s)", date('d/m', strtotime($ini)), date('d/m'))];
    }
}

function somar_transacoes($usuario_id, $tipo, $inicio, $fim, $moeda = null, $categoria = null) {
    $sql = "SELECT COALESCE(SUM(valor), 0) FROM transacoes WHERE usuario_id = ? AND tipo = ? AND data BETWEEN ? AND ?";
    $params = [$usuario_id, $tipo, $inicio, $fim];
    if ($moeda)     { $sql .= " AND moeda = ?";     $params[] = $moeda; }
    if ($categoria) { $sql .= " AND categoria = ?"; $params[] = $categoria; }
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function totais_por_moeda($usuario_id, $tipo, $inicio, $fim) {
    $stmt = get_db()->prepare("SELECT moeda, SUM(valor) as total FROM transacoes WHERE usuario_id = ? AND tipo = ? AND data BETWEEN ? AND ? GROUP BY moeda ORDER BY total DESC");
    $stmt->execute([$usuario_id, $tipo, $inicio, $fim]);
    return array_map('floatval', array_column($stmt->fetchAll(), 'total', 'moeda'));
}

function gastos_por_categoria($usuario_id, $inicio, $fim, $moeda) {
    $stmt = get_db()->prepare("SELECT categoria, SUM(valor) as total FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND moeda = ? AND data BETWEEN ? AND ? GROUP BY categoria ORDER BY total DESC");
    $stmt->execute([$usuario_id, $moeda, $inicio, $fim]);
    return array_map('floatval', array_column($stmt->fetchAll(), 'total', 'categoria'));
}

// "↑ 18%" / "↓ 12%" / "= igual" comparado com o anterior ('' se não dá pra comparar)
function variacao($atual, $anterior) {
    if ($anterior <= 0) return '';
    $pct = (int) round(($atual - $anterior) / $anterior * 100);
    if ($pct === 0) return '= igual ao período anterior';
    return ($pct > 0 ? '↑ ' : '↓ ') . abs($pct) . '% vs período anterior';
}

// Monta o resumo. Devolve null se não teve nenhum gasto nem receita no período.
function montar_resumo($usuario, $tipo) {
    [$ini, $fim, $ini_ant, $fim_ant, $titulo] = intervalo_resumo($tipo);
    $uid = $usuario['id'];

    $gastos = totais_por_moeda($uid, 'gasto', $ini, $fim);
    $receitas = totais_por_moeda($uid, 'receita', $ini, $fim);
    if (!$gastos && !$receitas) {
        return null;
    }

    // Comparações e categorias na moeda principal (a que mais teve gasto) — moedas nunca se somam
    $moeda = array_key_first($gastos) ?? ($usuario['moeda_padrao'] ?? 'EUR');
    $texto = "📊 {$titulo}\n\n";

    foreach ($gastos as $m => $total) {
        $comp = variacao($total, somar_transacoes($uid, 'gasto', $ini_ant, $fim_ant, $m));
        $texto .= sprintf("💸 Gastos: %s%s\n", formatar_dinheiro($total, $m), $comp ? " ($comp)" : '');
    }
    foreach ($receitas as $m => $total) {
        $texto .= sprintf("💰 Receitas: %s\n", formatar_dinheiro($total, $m));
    }
    if (in_array($tipo, ['mes', 'mes_passado'], true) && isset($receitas[$moeda])) {
        $sobra = $receitas[$moeda] - ($gastos[$moeda] ?? 0);
        $texto .= $sobra >= 0
            ? sprintf("✅ Sobrou %s no período (receitas − gastos)\n", formatar_dinheiro($sobra, $moeda))
            : sprintf("⚠️ Gastou %s a mais do que recebeu no período\n", formatar_dinheiro(-$sobra, $moeda));
    }

    // Onde foi o dinheiro
    $cats = gastos_por_categoria($uid, $ini, $fim, $moeda);
    $cats_ant = gastos_por_categoria($uid, $ini_ant, $fim_ant, $moeda);
    $total_moeda = array_sum($cats);
    if ($cats) {
        $texto .= "\n🏷️ Onde foi:\n";
        foreach (array_slice($cats, 0, 4, true) as $cat => $valor) {
            $antes = $cats_ant[$cat] ?? 0;
            $delta = $valor - $antes;
            $comp = ($antes > 0 && abs($delta) >= 10) ? sprintf(" %s %s", $delta > 0 ? '↑' : '↓', formatar_dinheiro(abs($delta), $moeda)) : '';
            $texto .= sprintf("• %s — %s (%d%%)%s\n", ucfirst(rotulo_categoria($cat)), formatar_dinheiro($valor, $moeda), round($valor / max(0.01, $total_moeda) * 100), $comp);
        }

        $stmt = get_db()->prepare("SELECT valor, descricao, categoria, data FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto' AND moeda = ? AND data BETWEEN ? AND ? ORDER BY valor DESC LIMIT 1");
        $stmt->execute([$uid, $moeda, $ini, $fim]);
        if ($maior = $stmt->fetch()) {
            $texto .= sprintf("🔝 Maior gasto: %s — %s (%s)\n", formatar_dinheiro($maior['valor'], $moeda),
                $maior['descricao'] ?: rotulo_categoria($maior['categoria']), date('d/m', strtotime($maior['data'])));
        }
    }

    // Destaque: o que mais chama atenção (no máximo 1 frase — resumo não é relatório)
    $insight = '';
    $total_ant = somar_transacoes($uid, 'gasto', $ini_ant, $fim_ant, $moeda);
    $maior_alta = null;
    foreach ($cats as $cat => $valor) {
        $delta = $valor - ($cats_ant[$cat] ?? 0);
        if (($cats_ant[$cat] ?? 0) > 0 && $delta >= 20 && $delta / $cats_ant[$cat] >= 0.3 && (!$maior_alta || $delta > $maior_alta[1])) {
            $maior_alta = [$cat, $delta, $delta / $cats_ant[$cat]];
        }
    }
    // Se o total caiu bem, isso é o que importa (elogio); senão, aponta a categoria que mais subiu
    if ($total_ant > 0 && ($gastos[$moeda] ?? 0) < $total_ant * 0.9) {
        $insight = sprintf("Você gastou %d%% menos que no período anterior — boa! 👏", round((1 - ($gastos[$moeda] ?? 0) / $total_ant) * 100));
    } elseif ($maior_alta) {
        $insight = sprintf("O que mais subiu foi %s: +%s (+%d%%) em relação ao período anterior.", rotulo_categoria($maior_alta[0]), formatar_dinheiro($maior_alta[1], $moeda), round($maior_alta[2] * 100));
    }
    if ($insight) {
        $texto .= "\n💡 {$insight}\n";
    }

    // Orçamento: como fechou (mês passado) ou como está agora (semana / mês atual)
    $orcamentos = get_db()->prepare("SELECT * FROM orcamentos WHERE usuario_id = ? ORDER BY categoria = '*', categoria");
    $orcamentos->execute([$uid]);
    $orcamentos = $orcamentos->fetchAll();
    if ($orcamentos) {
        if ($tipo === 'mes_passado') {
            $texto .= "\n📊 Orçamento de " . MESES_NOME[(int) date('n', strtotime($ini))] . ":\n";
            foreach ($orcamentos as $o) {
                $gasto = somar_transacoes($uid, 'gasto', $ini, $fim, $o['moeda'], $o['categoria'] === ORCAMENTO_TOTAL ? null : $o['categoria']);
                $dentro = $gasto <= (float) $o['valor'];
                $texto .= sprintf("%s %s — %s de %s\n", $dentro ? '✅' : '🚨', rotulo_orcamento($o['categoria']), formatar_dinheiro($gasto, $o['moeda']), formatar_dinheiro($o['valor'], $o['moeda']));
            }
        } else {
            $texto .= "\n📊 Orçamento de " . MESES_NOME[(int) date('n')] . ":\n";
            foreach ($orcamentos as $o) {
                $texto .= linha_orcamento($o, gasto_do_mes($uid, $o['categoria'], $o['moeda'])) . "\n";
            }
        }
    }

    // Contas fixas
    $botoes = [];
    if ($tipo === 'mes_passado') {
        $venc = listar_vencimentos_contas($uid, $ini, $fim);
        if ($venc) {
            $pagas = count(array_filter($venc, fn($v) => $v['status'] === 'pago'));
            $nao = count($venc) - $pagas;
            $texto .= sprintf("\n📌 Contas de %s: %d paga%s%s\n", MESES_NOME[(int) date('n', strtotime($ini))], $pagas, $pagas === 1 ? '' : 's', $nao ? ", {$nao} sem registro de pagamento" : ' ✅');
        }
    } else {
        $proximas = array_filter(listar_vencimentos_contas($uid, date('Y-m-d'), date('Y-m-d', strtotime('+7 days'))), fn($v) => $v['status'] === 'pendente');
        if ($proximas) {
            $texto .= "\n📌 Contas nos próximos 7 dias:\n";
            foreach ($proximas as $v) {
                $l = $v['lembrete'];
                $texto .= sprintf("• %s — %s%s\n", date('d/m', strtotime($v['data'])), descricao_da_conta($l['texto']), $l['valor'] !== null ? ' — ' . formatar_dinheiro($l['valor'], $l['moeda'] ?: 'EUR') : '');
            }
            $botoes[] = botao_texto('💸 O que falta pagar', 'o que falta pagar?');
        }
    }

    // Metas de poupança
    $texto .= linhas_metas_resumo($uid, $ini, $fim);

    // Água (só se a pessoa registra)
    $stmt = get_db()->prepare("SELECT DATE(registrado_em) as dia, SUM(ml) as total FROM agua_registros WHERE usuario_id = ? AND registrado_em BETWEEN ? AND ? GROUP BY DATE(registrado_em)");
    $stmt->execute([$uid, "$ini 00:00:00", "$fim 23:59:59"]);
    $agua = array_map('intval', array_column($stmt->fetchAll(), 'total', 'dia'));
    if ($agua) {
        $inicio_agua = max($ini, min(array_keys($agua)));
        $n_dias = (int) round((strtotime($fim) - strtotime($inicio_agua)) / 86400) + 1;
        $bateu = count(array_filter($agua, fn($ml) => $ml >= meta_agua($usuario)));
        $texto .= sprintf("\n💧 Água: meta batida em %d de %d dia%s (média %s/dia)\n", $bateu, $n_dias, $n_dias === 1 ? '' : 's', formatar_ml((int) round(array_sum($agua) / max(1, $n_dias))));
    }

    if ($orcamentos && $tipo !== 'mes_passado') {
        array_unshift($botoes, botao_texto('📊 Ver orçamento', 'como tá meu orçamento?'));
    }
    if ($botoes) {
        botoes_resposta([$botoes]);
    }
    return trim($texto);
}

function handle_resumo($usuario, $dados) {
    $tipo = match ($dados['periodo'] ?? 'semana') {
        'mes'         => 'mes',
        'mes_passado' => 'mes_passado',
        default       => 'semana',
    };
    return montar_resumo($usuario, $tipo) ?? "Não tive nenhum gasto nem receita registrado nesse período ainda. 👀 Me conta um gasto que eu começo a acompanhar.";
}

// ============================================================================
// Metas de poupança. Guardar numa meta NÃO é gasto — não entra em transacoes nem mexe no saldo.
// ============================================================================

function metas_ativas($usuario_id) {
    $stmt = get_db()->prepare("SELECT * FROM metas WHERE usuario_id = ? AND status = 'ativa' ORDER BY criado_em");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll();
}

function guardado_na_meta($meta_id) {
    $stmt = get_db()->prepare("SELECT COALESCE(SUM(valor), 0) FROM metas_aportes WHERE meta_id = ?");
    $stmt->execute([$meta_id]);
    return (float) $stmt->fetchColumn();
}

// Acha a meta pelo nome (sem acento, parcial ou quase igual). Sem nome: se só tem uma ativa, é ela.
// Devolve [meta ou null, lista de ativas]
function achar_meta($usuario_id, $nome) {
    $ativas = metas_ativas($usuario_id);
    $nome = sem_acentos(trim($nome ?? ''));
    if ($nome === '') {
        return [count($ativas) === 1 ? $ativas[0] : null, $ativas];
    }
    foreach ($ativas as $m) {
        $n = sem_acentos($m['nome']);
        if ($n === $nome || str_contains($n, $nome) || str_contains($nome, $n)) return [$m, $ativas];
    }
    foreach ($ativas as $m) {
        if (levenshtein(sem_acentos($m['nome']), $nome) <= 2) return [$m, $ativas];
    }
    // Todas as palavras que importam estão no nome da meta ("calção do quarto" acha "calção do novo quarto")
    $palavras = array_filter(preg_split('/\s+/', $nome), fn($p) => mb_strlen($p) >= 3);
    if ($palavras) {
        foreach ($ativas as $m) {
            $n = sem_acentos($m['nome']);
            if (count(array_filter($palavras, fn($p) => str_contains($n, $p))) === count($palavras)) return [$m, $ativas];
        }
    }
    return [null, $ativas];
}

// "quanto preciso guardar por semana pra viagem?" — quanto separar por dia/semana/mês pra chegar no prazo
function quanto_guardar_por($meta, $frequencia, $perguntar_prazo = true) {
    $falta = (float) $meta['valor_alvo'] - guardado_na_meta($meta['id']);
    $nome = ucfirst($meta['nome']);
    if ($falta <= 0) {
        return "🎉 A meta \"{$nome}\" já foi alcançada — não precisa guardar mais nada!";
    }
    if (empty($meta['prazo']) || $meta['prazo'] <= date('Y-m-d')) {
        if (!$perguntar_prazo) {
            return sprintf("🎯 %s: faltam %s, mas não tem prazo — me diz \"o prazo do %s é dezembro\" que eu calculo.", $nome, formatar_dinheiro($falta, $meta['moeda']), mb_strtolower($meta['nome']));
        }
        // A próxima mensagem ("15 dias", "dezembro", "20/12") vira o prazo
        salvar_estado($meta['usuario_id'], 'aguardando_prazo_meta', ['meta_id' => (int) $meta['id'], 'frequencia' => $frequencia]);
        return sprintf("🎯 Pra \"%s\" faltam %s, mas a meta não tem prazo%s — então não dá pra dividir por %s.\nAté quando você quer juntar? (ex: \"15 dias\", \"3 meses\", \"dezembro\", \"20/12\")",
            $nome, formatar_dinheiro($falta, $meta['moeda']), empty($meta['prazo']) ? '' : ' (o prazo já passou)',
            ['dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês'][$frequencia]);
    }
    $dias = max(1, round((strtotime($meta['prazo']) - strtotime(date('Y-m-d'))) / 86400)); // round: horário de verão
    $partes = ['dia' => $dias, 'semana' => $dias / 7, 'mes' => $dias / 30.44];
    $pedido = max(1, $partes[$frequencia]);
    $texto = sprintf("🎯 %s: faltam %s até %s.\n💰 Guardando %s por %s você chega lá.",
        $nome, formatar_dinheiro($falta, $meta['moeda']), date('d/m/Y', strtotime($meta['prazo'])),
        formatar_dinheiro($falta / $pedido, $meta['moeda']), ['dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês'][$frequencia]);
    // Mostra as outras frequências também, pra comparar (só as que cabem no prazo: 15 dias não tem "por mês")
    $outras = [];
    foreach ($partes as $f => $n) {
        if ($f !== $frequencia && $n >= 1.5) $outras[] = formatar_dinheiro($falta / max(1, $n), $meta['moeda']) . ' por ' . ['dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês'][$f];
    }
    return $outras ? $texto . "\n(ou " . implode(', ou ', $outras) . ")" : $texto;
}

// Meses (fracionados) de hoje até o prazo — mínimo "meio mês" pra não dividir por quase zero
// "~116,67 €/mês" — mas prazo curto fala por semana ou por dia (15 dias não é "1.400 €/mês")
function ritmo_meta($falta, $prazo, $moeda) {
    $dias = max(1, round((strtotime($prazo) - strtotime(date('Y-m-d'))) / 86400));
    if ($dias >= 60) return '~' . formatar_dinheiro($falta / ($dias / 30.44), $moeda) . '/mês';
    if ($dias >= 14) return '~' . formatar_dinheiro($falta / ($dias / 7), $moeda) . '/semana';
    return '~' . formatar_dinheiro($falta / $dias, $moeda) . '/dia';
}

function meses_ate($prazo) {
    return max(0.5, (strtotime($prazo) - strtotime(date('Y-m-d'))) / 86400 / 30.44);
}

// Linha de situação da meta: barra, quanto falta, quanto por mês e se está no ritmo
function linha_meta($meta, $detalhado = true) {
    $guardado = guardado_na_meta($meta['id']);
    $alvo = (float) $meta['valor_alvo'];
    $moeda = $meta['moeda'];
    $pct = $alvo > 0 ? (int) floor($guardado / $alvo * 100) : 0;
    $cheios = max(0, min(10, (int) floor($pct / 10)));
    $barra = str_repeat('▓', $cheios) . str_repeat('░', 10 - $cheios);

    $texto = sprintf("🎯 %s — %s de %s (%d%%)", ucfirst($meta['nome']), formatar_dinheiro($guardado, $moeda), formatar_dinheiro($alvo, $moeda), $pct);
    if (!$detalhado) return $texto;

    $texto .= "\n{$barra}";
    $falta = $alvo - $guardado;
    if ($falta <= 0) {
        return $texto . " — 🎉 meta alcançada!";
    }
    $texto .= " — faltam " . formatar_dinheiro($falta, $moeda);

    if ($meta['prazo']) {
        if ($meta['prazo'] < date('Y-m-d')) {
            return $texto . sprintf("\n⏰ O prazo (%s) já passou — quer mudar? Ex: \"o prazo da %s passa pra março\".", date('d/m/Y', strtotime($meta['prazo'])), $meta['nome']);
        }
        $texto .= sprintf(" até %s (%s)", date('d/m/Y', strtotime($meta['prazo'])), ritmo_meta($falta, $meta['prazo'], $moeda));

        // No ritmo? Compara com o que já devia ter guardado se fosse igualzinho todo dia desde que criou
        $total_dias = max(1, (strtotime($meta['prazo']) - strtotime(date('Y-m-d', strtotime($meta['criado_em'])))) / 86400);
        $passados = max(0, (strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($meta['criado_em'])))) / 86400);
        $esperado = $alvo * $passados / $total_dias;
        if ($passados >= 7) { // na primeira semana ainda não faz sentido cobrar ritmo
            $texto .= $guardado >= $esperado * 0.9 ? "\n✅ No ritmo certo" : sprintf("\n⚠️ Um pouco atrás do ritmo (o esperado até hoje era ~%s)", formatar_dinheiro($esperado, $moeda));
        }
    }
    return $texto;
}

// "até dezembro" etc. chega da IA como data; aqui só valida (existe e é no futuro)
// Prazo dito do jeito que a pessoa fala: "15 dias", "daqui 3 meses", "um ano", "dezembro", "até março de 2027",
// "20/12", "fim do ano". Devolve YYYY-MM-DD (fim do mês quando só diz o mês) ou null.
function interpretar_prazo($texto) {
    $t = sem_acentos(trim($texto, " .!?\n\t"));
    $t = preg_replace('/^(?:o\s+)?(?:prazo\s+)?(?:e\s+)?(?:(?:de|em|daqui(?:\s+a)?|ate|pra|para|no|pro)\s+)?/u', '', $t);
    $t = preg_replace('/^(?:o\s+)?(?:fim|final)\s+de\s+/u', 'fim do ', $t);
    $numeros = ['um' => 1, 'uma' => 1, 'dois' => 2, 'duas' => 2, 'tres' => 3, 'quatro' => 4, 'cinco' => 5, 'seis' => 6, 'sete' => 7, 'oito' => 8, 'nove' => 9, 'dez' => 10, 'doze' => 12];
    // Unidade tolerante a erro de digitação ("15 duas", "3 semans", "2 mese")
    if (preg_match('/^(\d{1,3}|' . implode('|', array_keys($numeros)) . ')\s*(d(?!e\b|ez\b)\w{0,3}|sem\w*|mes\w*|ano\w?)\b/u', $t, $m)) {
        $n = is_numeric($m[1]) ? (int) $m[1] : $numeros[$m[1]];
        $unidade = ['d' => 'days', 's' => 'weeks', 'm' => 'months', 'a' => 'years'][$m[2][0]];
        return $n > 0 ? date('Y-m-d', strtotime("+{$n} {$unidade}")) : null;
    }
    if (preg_match('/^fim do (ano|mes)$/u', $t, $m)) {
        return $m[1] === 'ano' ? date('Y-12-31') : date('Y-m-t');
    }
    $meses = ['janeiro', 'fevereiro', 'marco', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    if (preg_match('/^(?:fim do |final do )?(?:mes de )?(' . implode('|', $meses) . ')(?:\s+(?:de\s+)?(\d{4}))?$/u', $t, $m)) {
        $mes = array_search($m[1], $meses) + 1;
        $ano = isset($m[2]) ? (int) $m[2] : (int) date('Y');
        $fim = date('Y-m-t', mktime(0, 0, 0, $mes, 1, $ano));
        if (!isset($m[2]) && $fim <= date('Y-m-d')) $fim = date('Y-m-t', mktime(0, 0, 0, $mes, 1, $ano + 1));
        return $fim;
    }
    if (preg_match('/^\d{1,2}\/\d{1,2}(?:\/\d{2,4})?$/', $t)) {
        return data_da_frase($t);
    }
    return null;
}

// Grava o prazo que a pessoa deu e já responde o "quanto guardar por ..." que ela tinha perguntado
function responder_prazo_meta($usuario, $meta_id, $prazo, $frequencia) {
    [$prazo, $erro] = validar_prazo_meta($prazo);
    if ($erro) return $erro;
    $pdo = get_db();
    $pdo->prepare("UPDATE metas SET prazo = ? WHERE id = ? AND usuario_id = ?")->execute([$prazo, $meta_id, $usuario['id']]);
    $stmt = $pdo->prepare("SELECT * FROM metas WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$meta_id, $usuario['id']]);
    $meta = $stmt->fetch();
    if (!$meta) return "Não achei mais essa meta 🤔";
    return "✏️ Prazo: " . date('d/m/Y', strtotime($prazo)) . ".\n" . ($frequencia ? quanto_guardar_por($meta, $frequencia) : linha_meta($meta));
}

function validar_prazo_meta($prazo) {
    if (empty($prazo)) return [null, null];
    if (!validar_data_ou_invalida($prazo)) return [null, "\"{$prazo}\" não é uma data válida 🤔"];
    if ($prazo <= date('Y-m-d')) return [null, "O prazo precisa ser uma data no futuro 🤔"];
    return [$prazo, null];
}

function handle_criar_meta($usuario, $dados, $texto_original = '') {
    $nome = trim($dados['meta'] ?? '') ?: trim($dados['descricao'] ?? '');
    $valor = isset($dados['valor']) && is_numeric($dados['valor']) ? (float) $dados['valor'] : null;
    $moeda = $dados['moeda'] ?? ($usuario['moeda_padrao'] ?? 'EUR');
    // "preciso juntar num prazo de 15 dias" — a IA nem sempre converte o prazo em data
    if (empty($dados['prazo']) && preg_match('/\b(?:prazo\s+(?:de\s+)?|at[eé]\s+|daqui\s+(?:a\s+)?|em\s+)(.+?)[.!?]*$/iu', $texto_original, $m)) {
        $dados['prazo'] = interpretar_prazo($m[1]);
    }
    [$prazo, $erro] = validar_prazo_meta($dados['prazo'] ?? null);

    if ($erro) return $erro;
    // Sem valor, mas é uma meta que já existe ("o calção do quarto, preciso juntar num prazo de 15 dias") = mudar o prazo
    if ((!$valor || $valor <= 0) && $nome !== '' && ([$existente] = achar_meta($usuario['id'], $nome)) && $existente) {
        return $prazo
            ? handle_editar_meta($usuario, ['meta' => $existente['nome'], 'prazo_novo' => $prazo])
            : linha_meta($existente) . "\n\nQuer mudar algo? Ex: \"o prazo do {$existente['nome']} é dezembro\" ou \"a meta do {$existente['nome']} agora é 900\".";
    }
    if (!$valor || $valor <= 0) {
        return "Quanto você quer juntar? 🎯 Ex: \"quero juntar 1000 euros até dezembro pra viagem\".";
    }
    $nome = $nome !== '' ? mb_strtolower($nome) : 'poupança';

    [$existente] = achar_meta($usuario['id'], $nome);
    if ($existente && sem_acentos($existente['nome']) === sem_acentos($nome)) {
        // Meta com esse nome já existe: "quero juntar 150 pra reserva" / "a meta da reserva agora é 150" = mudar o valor
        if ((float) $existente['valor_alvo'] !== $valor || ($prazo && $prazo !== $existente['prazo'])) {
            return handle_editar_meta($usuario, ['meta' => $existente['nome'], 'valor_novo' => $valor, 'prazo_novo' => $prazo]);
        }
        return sprintf("Você já tem a meta \"%s\" de %s. 🎯 Pra guardar dinheiro nela: \"guardei 100 pra %s\".", $existente['nome'], formatar_dinheiro($valor, $existente['moeda']), $existente['nome']);
    }

    get_db()->prepare("INSERT INTO metas (usuario_id, nome, valor_alvo, moeda, prazo, criado_em) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$usuario['id'], $nome, $valor, $moeda, $prazo, date('Y-m-d H:i:s')]);

    $texto = sprintf("🎯 Meta criada: %s — %s", ucfirst($nome), formatar_dinheiro($valor, $moeda));
    if ($prazo) {
        $texto .= sprintf(" até %s.\nPra chegar lá: %s.", date('d/m/Y', strtotime($prazo)), str_replace('/', ' por ', ritmo_meta($valor, $prazo, $moeda)));
    } else {
        $texto .= ".\n(Se quiser um prazo, me diz tipo \"o prazo da {$nome} é dezembro\" que eu calculo quanto guardar por mês.)";
    }
    return $texto . "\n\nQuando separar dinheiro, é só me dizer: \"guardei 100 pra {$nome}\". 💪";
}

function handle_aporte_meta($usuario, $dados) {
    $valor = isset($dados['valor']) && is_numeric($dados['valor']) ? abs((float) $dados['valor']) : null;
    if (!$valor) {
        return "Quanto você guardou? Ex: \"guardei 100 pra viagem\".";
    }
    if (!empty($dados['retirar'])) {
        $valor = -$valor;
    }

    [$meta, $ativas] = achar_meta($usuario['id'], $dados['meta'] ?? null);
    if (!$ativas) {
        return "Você ainda não tem nenhuma meta. 🎯 Cria uma assim: \"quero juntar 1000 euros até dezembro pra viagem\".";
    }
    if (!$meta) {
        // Várias metas e não disse qual (ou não achou): pergunta com botões
        salvar_estado($usuario['id'], 'aguardando_meta_aporte', ['valor' => $valor]);
        botoes_resposta(array_chunk(array_map(fn($m) => botao_texto(ucfirst($m['nome']), $m['nome']), $ativas), 2));
        return sprintf("%s pra qual meta? 🎯", $valor > 0 ? 'Guardou ' . formatar_dinheiro($valor, $ativas[0]['moeda']) : 'Tirou ' . formatar_dinheiro(-$valor, $ativas[0]['moeda']));
    }

    return registrar_aporte_meta($usuario, $meta, $valor);
}

function registrar_aporte_meta($usuario, $meta, $valor) {
    $antes = guardado_na_meta($meta['id']);
    if ($valor < 0 && -$valor > $antes) {
        return sprintf("A meta \"%s\" só tem %s guardado — não dá pra tirar %s. 🤔", $meta['nome'], formatar_dinheiro($antes, $meta['moeda']), formatar_dinheiro(-$valor, $meta['moeda']));
    }

    get_db()->prepare("INSERT INTO metas_aportes (meta_id, usuario_id, valor, criado_em) VALUES (?, ?, ?, ?)")
        ->execute([$meta['id'], $usuario['id'], $valor, date('Y-m-d H:i:s')]);

    $alvo = (float) $meta['valor_alvo'];
    $depois = $antes + $valor;
    $texto = $valor > 0
        ? sprintf("💰 +%s na meta \"%s\"!\n", formatar_dinheiro($valor, $meta['moeda']), $meta['nome'])
        : sprintf("💸 Tirei %s da meta \"%s\".\n", formatar_dinheiro(-$valor, $meta['moeda']), $meta['nome']);

    if ($valor > 0 && $depois >= $alvo) {
        get_db()->prepare("UPDATE metas SET status = 'concluida' WHERE id = ?")->execute([$meta['id']]);
        return $texto . sprintf("🎉🎉 META ALCANÇADA! Você juntou %s pra %s. Parabéns, isso é disciplina! 🏆", formatar_dinheiro($depois, $meta['moeda']), $meta['nome']);
    }

    $texto .= linha_meta($meta);
    // Marcos: 25 / 50 / 75%
    if ($valor > 0) {
        foreach ([75 => 'Já passou dos 75% — reta final! 🔥', 50 => 'Metade do caminho! 🙌', 25 => 'Primeiro quarto concluído! 👏'] as $marco => $frase) {
            if ($antes < $alvo * $marco / 100 && $depois >= $alvo * $marco / 100) {
                $texto .= "\n\n" . $frase;
                break;
            }
        }
    }
    return $texto;
}

function handle_listar_metas($usuario, $dados) {
    $frequencia = in_array($dados['frequencia'] ?? null, ['dia', 'semana', 'mes'], true) ? $dados['frequencia'] : null;
    if (!empty($dados['meta'])) {
        [$meta] = achar_meta($usuario['id'], $dados['meta']);
        if ($meta) {
            return $frequencia ? quanto_guardar_por($meta, $frequencia) : linha_meta($meta);
        }
    }
    // "quanto tenho que guardar por dia?" sem dizer a meta: responde pra cada meta ativa
    if ($frequencia) {
        $ativas = metas_ativas($usuario['id']);
        if (!$ativas) {
            return "Você ainda não tem metas de poupança. 🎯 Cria uma assim: \"quero juntar 1000 até dezembro pra viagem\" — aí eu te digo quanto guardar por dia, semana ou mês.";
        }
        if (count($ativas) === 1) return quanto_guardar_por($ativas[0], $frequencia);
        return implode("\n\n", array_map(fn($m) => quanto_guardar_por($m, $frequencia, false), $ativas));
    }

    $ativas = metas_ativas($usuario['id']);
    $stmt = get_db()->prepare("SELECT * FROM metas WHERE usuario_id = ? AND status = 'concluida' ORDER BY id DESC LIMIT 3");
    $stmt->execute([$usuario['id']]);
    $concluidas = $stmt->fetchAll();

    if (!$ativas && !$concluidas) {
        return "Você ainda não tem metas de poupança. 🎯\nPra criar: \"quero juntar 1000 euros até dezembro pra viagem\" ou \"meta de 5000 pra reserva de emergência\".";
    }

    $texto = $ativas ? "Suas metas de poupança:\n\n" . implode("\n\n", array_map('linha_meta', $ativas)) : "Nenhuma meta ativa agora.";
    if ($concluidas) {
        $texto .= "\n\n🏆 Alcançadas: " . implode(', ', array_map(fn($m) => $m['nome'] . ' (' . formatar_dinheiro($m['valor_alvo'], $m['moeda']) . ')', $concluidas));
    }
    return $texto;
}

function handle_editar_meta($usuario, $dados) {
    [$meta, $ativas] = achar_meta($usuario['id'], $dados['meta'] ?? null);
    if (!$meta) {
        return $ativas
            ? "Qual meta? Tenho: " . implode(', ', array_column($ativas, 'nome')) . ". Ex: \"a meta da " . $ativas[0]['nome'] . " agora é 1500\"."
            : "Você ainda não tem nenhuma meta. 🎯";
    }

    // A IA às vezes manda "valor"/"prazo" em vez de "valor_novo"/"prazo_novo" — numa edição, dá no mesmo
    $dados['valor_novo'] ??= $dados['valor'] ?? null;
    $dados['prazo_novo'] ??= $dados['prazo'] ?? null;

    $mudou = [];
    $pdo = get_db();
    if (isset($dados['valor_novo']) && is_numeric($dados['valor_novo']) && $dados['valor_novo'] > 0) {
        $pdo->prepare("UPDATE metas SET valor_alvo = ? WHERE id = ?")->execute([(float) $dados['valor_novo'], $meta['id']]);
        $mudou[] = 'valor pra ' . formatar_dinheiro($dados['valor_novo'], $meta['moeda']);
    }
    if (!empty($dados['prazo_novo'])) {
        [$prazo, $erro] = validar_prazo_meta($dados['prazo_novo']);
        if ($erro) return $erro;
        $pdo->prepare("UPDATE metas SET prazo = ? WHERE id = ?")->execute([$prazo, $meta['id']]);
        $mudou[] = 'prazo pra ' . date('d/m/Y', strtotime($prazo));
    }
    if (!empty($dados['nome_novo'])) {
        $pdo->prepare("UPDATE metas SET nome = ? WHERE id = ?")->execute([mb_strtolower(trim($dados['nome_novo'])), $meta['id']]);
        $mudou[] = 'nome pra "' . mb_strtolower(trim($dados['nome_novo'])) . '"';
    }
    if (!$mudou) {
        return sprintf("O que muda na meta \"%s\"? Ex: \"a meta da %s agora é 1500\" ou \"o prazo da %s passa pra março\".", $meta['nome'], $meta['nome'], $meta['nome']);
    }

    $stmt = $pdo->prepare("SELECT * FROM metas WHERE id = ?");
    $stmt->execute([$meta['id']]);
    return "✏️ Mudei " . implode(' e ', $mudou) . ".\n" . linha_meta($stmt->fetch());
}

function handle_cancelar_meta($usuario, $dados) {
    [$meta, $ativas] = achar_meta($usuario['id'], $dados['meta'] ?? null);
    if (!$meta) {
        return $ativas ? "Qual meta você quer cancelar? Tenho: " . implode(', ', array_column($ativas, 'nome')) . "." : "Você não tem nenhuma meta ativa. 🎯";
    }
    get_db()->prepare("UPDATE metas SET status = 'cancelada' WHERE id = ?")->execute([$meta['id']]);
    $guardado = guardado_na_meta($meta['id']);
    return sprintf("❌ Cancelei a meta \"%s\".%s", $meta['nome'],
        $guardado > 0 ? sprintf(" (Você tinha %s guardado nela — o dinheiro é seu, só parei de acompanhar.)", formatar_dinheiro($guardado, $meta['moeda'])) : '');
}

// Linhas das metas pro resumo semanal/mensal ('' se não tiver meta ativa)
function linhas_metas_resumo($usuario_id, $inicio, $fim) {
    $ativas = metas_ativas($usuario_id);
    if (!$ativas) return '';
    $stmt = get_db()->prepare("SELECT COALESCE(SUM(valor), 0) FROM metas_aportes WHERE usuario_id = ? AND criado_em BETWEEN ? AND ?");
    $stmt->execute([$usuario_id, "$inicio 00:00:00", "$fim 23:59:59"]);
    $no_periodo = (float) $stmt->fetchColumn();

    $texto = "\n🎯 Metas" . ($no_periodo > 0 ? sprintf(" (você guardou %s no período)", formatar_dinheiro($no_periodo, $ativas[0]['moeda'])) : '') . ":\n";
    foreach ($ativas as $m) {
        $texto .= "• " . preg_replace('/^🎯 /u', '', linha_meta($m, false)) . "\n";
    }
    return $texto;
}

// ============================================================================
// Plano Plus pago em Telegram Stars (regra do Telegram: serviço digital dentro do bot = só Stars).
// Teste de 14 dias pra todo usuário novo; mensal renova sozinho a cada 30 dias; anual = pagamento único de 365 dias;
// preço de fundador pros 100 primeiros assinantes, ENQUANTO a assinatura estiver ativa (a renovação mantém o preço do
// link); quem cancela e deixa vencer volta pagando o preço normal (decisão do Nico, 01/10/2026).
// ============================================================================

const PRECOS_STARS = [
    'BR' => ['mensal' => 150, 'anual' => 1500, 'fundador_mensal' => 100, 'fundador_anual' => 1000,
             'aprox' => [150 => '≈ R$ 15', 1500 => '≈ R$ 150', 100 => '≈ R$ 10', 1000 => '≈ R$ 100']],
    'PT' => ['mensal' => 350, 'anual' => 3500, 'fundador_mensal' => 250, 'fundador_anual' => 2500,
             'aprox' => [350 => '≈ 6 €', 3500 => '≈ 60 €', 250 => '≈ 4 €', 2500 => '≈ 42 €']],
];
const VAGAS_FUNDADOR = 100;
const PERIODO_ASSINATURA = 2592000; // 30 dias — o único período que o Telegram aceita pra assinatura em Stars

// language_code do Telegram → tabela de preço ("pt-br" = Brasil; outro português = Portugal)
function regiao_por_idioma($language_code) {
    $lc = strtolower((string) $language_code);
    if ($lc === 'pt-br') return 'BR';
    if (str_starts_with($lc, 'pt')) return 'PT';
    return null;
}

function regiao_usuario($usuario) {
    if (in_array($usuario['regiao'] ?? null, ['BR', 'PT'], true)) return $usuario['regiao'];
    return ($usuario['moeda_padrao'] ?? 'EUR') === 'BRL' ? 'BR' : 'PT';
}

function definir_regiao($usuario_id, $regiao) {
    if (!in_array($regiao, ['BR', 'PT'], true)) return;
    $pdo = get_db();
    $pdo->prepare("UPDATE usuarios SET regiao = ? WHERE id = ?")->execute([$regiao, $usuario_id]);
    // Quem ainda não registrou nada começa na moeda do país (R$ pro Brasil, € pra Portugal).
    // Se depois escrever outra moeda nos primeiros gastos, ajustar_moeda_padrao() troca sozinho.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transacoes WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->prepare("UPDATE usuarios SET moeda_padrao = ? WHERE id = ?")->execute([$regiao === 'BR' ? 'BRL' : 'EUR', $usuario_id]);
    }
}

// ['ativo' => bool, 'tipo' => mensal|anual|cortesia|teste|null, 'ate' => 'Y-m-d H:i:s'|null]
function status_plus($usuario) {
    $agora = date('Y-m-d H:i:s');
    if (!empty($usuario['plus_ate']) && $usuario['plus_ate'] > $agora) {
        return ['ativo' => true, 'tipo' => $usuario['plus_tipo'] ?: 'mensal', 'ate' => $usuario['plus_ate']];
    }
    if (!empty($usuario['trial_ate']) && $usuario['trial_ate'] > $agora) {
        return ['ativo' => true, 'tipo' => 'teste', 'ate' => $usuario['trial_ate']];
    }
    return ['ativo' => false, 'tipo' => null, 'ate' => null];
}

function tem_plus($usuario) {
    return status_plus($usuario)['ativo'];
}

function tem_plus_por_id($usuario_id) {
    $stmt = get_db()->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $u = $stmt->fetch();
    return $u && tem_plus($u);
}

function vagas_fundador() {
    $usadas = (int) get_db()->query("SELECT COUNT(DISTINCT usuario_id) FROM pagamentos_stars WHERE fundador = 1 AND reembolsado = 0")->fetchColumn();
    return max(0, VAGAS_FUNDADOR - $usadas);
}

// Folga depois do vencimento em que ainda conta como "assinatura ativa" (renovação que atrasou um pouco)
const FOLGA_FUNDADOR_DIAS = 3;

// Fundador mantém o preço enquanto a assinatura (mensal ou anual) estiver ativa. Cancelou e venceu: perde.
function fundador_ativo($usuario) {
    if (empty($usuario['fundador']) || empty($usuario['plus_ate']) || !in_array($usuario['plus_tipo'] ?? null, ['mensal', 'anual'], true)) return false;
    return strtotime($usuario['plus_ate']) >= strtotime('-' . FOLGA_FUNDADOR_DIAS . ' days');
}

// Vaga nova de fundador: só pra quem nunca assinou — quem já foi assinante e saiu volta no preço normal
function pode_virar_fundador($usuario) {
    if (!empty($usuario['fundador']) || vagas_fundador() <= 0) return false;
    $stmt = get_db()->prepare("SELECT 1 FROM pagamentos_stars WHERE usuario_id = ? AND reembolsado = 0 LIMIT 1");
    $stmt->execute([$usuario['id']]);
    return !$stmt->fetch();
}

// Preço pra esse usuário: [stars, é_fundador, "≈ R$ 15"]. Fundador = já é e a assinatura está ativa, OU nunca assinou e ainda tem vaga.
function preco_plus($usuario, $tipo) {
    $tabela = PRECOS_STARS[regiao_usuario($usuario)];
    $fundador = fundador_ativo($usuario) || pode_virar_fundador($usuario);
    $stars = $tabela[($fundador ? 'fundador_' : '') . $tipo];
    return [$stars, $fundador, $tabela['aprox'][$stars] ?? ''];
}

// Link de pagamento (createInvoiceLink). Mensal = assinatura que renova sozinha. Devolve a URL ou null.
function criar_link_pagamento($usuario, $tipo) {
    [$stars, $fundador] = preco_plus($usuario, $tipo);
    $params = [
        'title'       => $tipo === 'anual' ? 'Zágora Plus — 1 ano' : 'Zágora Plus — mensal',
        'description' => 'Contas fixas com "Paguei", orçamento com alertas, metas de poupança, resumos inteligentes, lembretes ilimitados e controle de água.'
                       . ($fundador ? ' Preço de fundador: fica assim enquanto a assinatura estiver ativa.' : ''),
        'payload'     => implode('|', ['plus', $tipo, $usuario['id'], $fundador ? 1 : 0]),
        'currency'    => 'XTR',
        'prices'      => [['label' => $tipo === 'anual' ? 'Plus — 1 ano' : 'Plus — mensal', 'amount' => $stars]],
    ];
    if ($tipo === 'mensal') {
        $params['subscription_period'] = PERIODO_ASSINATURA;
    }
    $resposta = json_decode(chamar_telegram(config_zagora(), 'createInvoiceLink', $params), true);
    if (empty($resposta['ok'])) {
        registrar_erro('createInvoiceLink falhou: ' . json_encode($resposta));
        return null;
    }
    return $resposta['result'];
}

// Botões "Assinar" (abrem o pagamento do Telegram direto)
function botoes_assinatura($usuario) {
    $botoes = [];
    foreach (['mensal', 'anual'] as $tipo) {
        [$stars] = preco_plus($usuario, $tipo);
        if ($url = criar_link_pagamento($usuario, $tipo)) {
            $botoes[] = [[$tipo === 'mensal' ? "⭐ Assinar — {$stars} Stars/mês" : "⭐ Anual — {$stars} Stars (2 meses grátis)", $url]];
        }
    }
    return $botoes;
}

function texto_oferta_plus($usuario) {
    [$mensal, $fundador, $aprox_m] = preco_plus($usuario, 'mensal');
    [$anual, , $aprox_a] = preco_plus($usuario, 'anual');
    $texto = sprintf("⭐ Plus: %d Stars/mês (%s) ou %d Stars/ano (%s, 2 meses grátis).", $mensal, $aprox_m, $anual, $aprox_a);
    if ($fundador && empty($usuario['fundador'])) {
        $texto .= sprintf("\n🏅 Preço de fundador — fica assim enquanto você mantiver a assinatura. Restam %d vagas.", vagas_fundador());
    }
    return $texto . "\nPago em Telegram Stars, direto aqui no chat.";
}

// Recurso do Plus: null se pode usar; senão, a mensagem de oferta (com os botões de assinar)
function exige_plus($usuario, $recurso) {
    if (tem_plus($usuario)) {
        return null;
    }
    botoes_resposta(botoes_assinatura($usuario));
    return "✨ {$recurso} " . (str_ends_with($recurso, 's') ? 'são' : 'é') . " do Zágora Plus.\n\n" . texto_oferta_plus($usuario);
}

// Uso justo do Grátis: até 20 mensagens por dia que precisem da IA (comandos, botões, respostas curtas e as frases comuns
// resolvidas sem IA — saldo, listas, agenda... — não contam). Era 40: um Grátis no limite custava mais que um Plus BR paga.
// Teste e Plus: sem limite. Conta a mensagem ANTES de chamar a IA. null = pode seguir; senão, a mensagem de limite.
const LIMITE_IA_GRATIS = 20;

function limite_uso_ia($usuario) {
    $hoje = date('Y-m-d');
    $usadas = ($usuario['ia_uso_data'] ?? null) === $hoje ? (int) $usuario['ia_uso_qtd'] : 0;
    if (!tem_plus($usuario) && $usadas >= LIMITE_IA_GRATIS) {
        botoes_resposta(botoes_assinatura($usuario));
        return sprintf("Hoje você já usou as %d mensagens do plano Grátis. 🙏 Amanhã libera de novo — e os comandos (/ajuda, /plano) e botões continuam funcionando.\n\nCom o Plus não tem limite:\n%s", LIMITE_IA_GRATIS, texto_oferta_plus($usuario));
    }
    get_db()->prepare("UPDATE usuarios SET ia_uso_data = ?, ia_uso_qtd = ? WHERE id = ?")->execute([$hoje, $usadas + 1, $usuario['id']]);
    return null;
}

// Grátis: até 3 lembretes ativos. null = pode criar; senão, a oferta do Plus.
function limite_lembretes_gratis($usuario) {
    if (tem_plus($usuario)) return null;
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM lembretes WHERE usuario_id = ? AND status = 'pendente' AND origem_id IS NULL");
    $stmt->execute([$usuario['id']]);
    if ((int) $stmt->fetchColumn() < 3) return null;
    botoes_resposta(botoes_assinatura($usuario));
    return "🔔 No Grátis dá pra ter até 3 lembretes ativos — você já tem 3. Pra liberar espaço: \"cancela o lembrete de X\".\n\nOu libera lembretes ilimitados com o Plus:\n" . texto_oferta_plus($usuario);
}

function handle_plano($usuario) {
    $s = status_plus($usuario);
    $ate = $s['ate'] ? date('d/m/Y', strtotime($s['ate'])) : null;

    if ($s['tipo'] === 'cortesia') {
        return "⭐ Você tem o Zágora Plus liberado (cortesia). Tudo liberado, sem cobrança. 💚";
    }
    if (in_array($s['tipo'], ['mensal', 'anual'], true)) {
        $texto = $s['tipo'] === 'mensal'
            ? "⭐ Você é Zágora Plus (mensal). Renova sozinho em {$ate}."
            : "⭐ Você é Zágora Plus (anual) até {$ate}.";
        if (fundador_ativo($usuario)) $texto .= "\n🏅 Com preço de fundador — fica assim enquanto a assinatura estiver ativa (se cancelar e deixar vencer, a volta é pelo preço normal).";
        if ($s['tipo'] === 'mensal') $texto .= "\n\nPra cancelar: Telegram → Configurações → Minhas Stars → assinatura do Zágora. Você continua com o Plus até o fim do período já pago.";
        return $texto . "\nProblema com pagamento? /paysupport";
    }

    botoes_resposta(botoes_assinatura($usuario));
    if ($s['tipo'] === 'teste') {
        $dias = max(1, (int) ceil((strtotime($s['ate']) - time()) / 86400));
        return sprintf("🎁 Você está no teste grátis do Plus: falta%s %d dia%s (até %s). Tudo liberado!\n\n%s\n\nSe não assinar, você continua no Grátis (gastos, saldo e até 3 lembretes) — nada se perde.",
            $dias === 1 ? '' : 'm', $dias, $dias === 1 ? '' : 's', $ate, texto_oferta_plus($usuario));
    }
    return "Você está no plano Grátis: gastos e receitas ilimitados, saldo, categorias e até 3 lembretes.\n\nO Plus libera contas fixas com \"Paguei\", orçamento com alertas, metas, resumos inteligentes, lembretes ilimitados e controle de água.\n\n" . texto_oferta_plus($usuario);
}

// payload "plus|mensal|123|1" → ['tipo' => 'mensal', 'usuario_id' => 123, 'fundador' => true] (ou null se não é nosso)
function ler_payload_pagamento($payload) {
    $p = explode('|', (string) $payload);
    if (count($p) !== 4 || $p[0] !== 'plus' || !in_array($p[1], ['mensal', 'anual'], true)) return null;
    return ['tipo' => $p[1], 'usuario_id' => (int) $p[2], 'fundador' => $p[3] === '1'];
}

// Telegram pergunta "posso cobrar?" antes do pagamento — responder em até 10s. Devolve [ok, mensagem de erro].
function validar_pre_checkout($usuario, $pre_checkout) {
    $p = ler_payload_pagamento($pre_checkout['invoice_payload'] ?? '');
    if (!$p || $p['usuario_id'] !== (int) $usuario['id'] || ($pre_checkout['currency'] ?? '') !== 'XTR') {
        return [false, 'Esse link de pagamento não é seu ou expirou. Manda /plano pra gerar um novo.'];
    }
    if ($p['tipo'] === 'mensal' && in_array(status_plus($usuario)['tipo'], ['mensal', 'cortesia'], true)) {
        return [false, 'Você já tem o Plus ativo. 💚'];
    }
    // Link antigo com preço de fundador, de quando ainda valia (as vagas acabaram, ou a assinatura venceu nesse meio-tempo)
    if ($p['fundador'] && !fundador_ativo($usuario) && !pode_virar_fundador($usuario)) {
        return [false, 'Esse link com preço de fundador não vale mais. Manda /plano pra gerar um novo.'];
    }
    return [true, null];
}

// Pagamento confirmado (1º pagamento, renovação automática do mensal, ou anual). Devolve a mensagem pro usuário.
function registrar_pagamento_stars($usuario, $pagamento) {
    $p = ler_payload_pagamento($pagamento['invoice_payload'] ?? '');
    if (!$p) {
        registrar_erro('successful_payment com payload desconhecido: ' . json_encode($pagamento));
        return "Recebi um pagamento que não reconheci 🤔 Manda /paysupport que a gente resolve.";
    }
    $pdo = get_db();

    if ($p['tipo'] === 'mensal' && !empty($pagamento['subscription_expiration_date'])) {
        $expira = date('Y-m-d H:i:s', (int) $pagamento['subscription_expiration_date']);
    } else {
        // Anual soma 365 dias a partir de hoje — ou do fim do Plus atual, se ainda estiver valendo
        $base = (!empty($usuario['plus_ate']) && $usuario['plus_ate'] > date('Y-m-d H:i:s') && $usuario['plus_tipo'] !== 'cortesia') ? $usuario['plus_ate'] : date('Y-m-d H:i:s');
        $expira = date('Y-m-d H:i:s', strtotime($base . ($p['tipo'] === 'anual' ? ' +365 days' : ' +30 days')));
    }
    $renovacao = !empty($pagamento['is_recurring']) && empty($pagamento['is_first_recurring']);

    $stmt = $pdo->prepare("INSERT IGNORE INTO pagamentos_stars (usuario_id, telegram_payment_charge_id, valor_stars, tipo, fundador, recorrente, expira_em, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$usuario['id'], $pagamento['telegram_payment_charge_id'], (int) ($pagamento['total_amount'] ?? 0), $p['tipo'], $p['fundador'] ? 1 : 0, $renovacao ? 1 : 0, $expira, date('Y-m-d H:i:s')]);
    if ($stmt->rowCount() === 0) {
        return null; // mesmo pagamento chegando de novo (o Telegram reenvia se demorarmos) — já registrado
    }

    // fundador = o que este pagamento é (e não "uma vez fundador, sempre fundador"): quem voltou pagando o preço normal deixa de ser
    $pdo->prepare("UPDATE usuarios SET plus_ate = ?, plus_tipo = ?, fundador = ?, aviso_plano = 0 WHERE id = ?")
        ->execute([$expira, $p['tipo'], $p['fundador'] ? 1 : 0, $usuario['id']]);

    $ate = date('d/m/Y', strtotime($expira));
    if ($renovacao) {
        return "✅ Assinatura do Zágora Plus renovada — válida até {$ate}. Obrigado por continuar com a gente! 💚";
    }
    return "🎉 Bem-vindo ao Zágora Plus!" . ($p['fundador'] ? " 🏅 Com preço de fundador — fica assim enquanto você mantiver a assinatura." : '') . "\n\n"
         . "Tudo liberado " . ($p['tipo'] === 'mensal' ? "(renova sozinho em {$ate})" : "até {$ate}") . ":\n"
         . "📌 contas fixas com \"Paguei\" · 📊 orçamento com alertas · 🎯 metas · 📈 resumos · 🔔 lembretes ilimitados · 💧 água\n\n"
         . "Manda /plano quando quiser ver sua assinatura, ou /paysupport se algo der errado.";
}

// ============================================================================
// Reportes de erro (/reportar): texto e/ou print → tabela reportes + aviso pro dono + painel
// ============================================================================

function handle_reportar($usuario, $texto_depois_do_comando = '') {
    if (trim($texto_depois_do_comando) !== '') {
        return salvar_reporte($usuario, trim($texto_depois_do_comando), null);
    }
    salvar_estado($usuario['id'], 'aguardando_reporte', []);
    return "🐞 Me conta o que aconteceu — pode escrever e/ou mandar um print (captura de tela). Vai direto pra equipe.";
}

function contexto_reporte($usuario) {
    $s = status_plus($usuario);
    return sprintf('plano=%s modo=%s regiao=%s estado=%s', $s['ativo'] ? $s['tipo'] : 'gratis', $usuario['modo_contas'] ?? '-', $usuario['regiao'] ?? '-', $usuario['estado'] ?? '-');
}

// Grava o reporte e avisa o dono (com o print, se tiver). Devolve a resposta pro usuário.
function salvar_reporte($usuario, $texto, $foto_file_id) {
    $pdo = get_db();
    $pdo->prepare("INSERT INTO reportes (usuario_id, texto, foto_file_id, contexto, criado_em) VALUES (?, ?, ?, ?, ?)")
        ->execute([$usuario['id'], $texto !== '' ? $texto : null, $foto_file_id, contexto_reporte($usuario), date('Y-m-d H:i:s')]);
    $id = (int) $pdo->lastInsertId();
    limpar_estado($usuario['id']);

    $config = config_zagora();
    if (!empty($config['admin_telegram_id'])) {
        $aviso = sprintf("🐞 Reporte #%d de %s (#%d)\n%s\n\nVer e responder no painel: /painel", $id, $usuario['nome'] ?: 'sem nome', $usuario['id'], $texto ?: '(só o print)');
        if ($foto_file_id) {
            chamar_telegram($config, 'sendPhoto', ['chat_id' => $config['admin_telegram_id'], 'photo' => $foto_file_id, 'caption' => mb_substr($aviso, 0, 1000)]);
        } else {
            enviar_telegram($config, $config['admin_telegram_id'], $aviso);
        }
    }

    if ($foto_file_id && ($texto === null || $texto === '')) {
        salvar_estado($usuario['id'], 'aguardando_reporte_descricao', ['reporte_id' => $id]);
        return "Recebido ✅ Reporte #{$id}. Quer contar em poucas palavras o que aconteceu? Pode escrever agora (ou manda \"não\").";
    }
    return "Recebido ✅ Reporte #{$id}. Obrigado por avisar — a gente olha e te responde por aqui se precisar. 💚";
}

// Complemento em texto depois de um print sem legenda
function completar_reporte($usuario, $texto) {
    $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];
    limpar_estado($usuario['id']);
    if (resposta_sim_nao($texto) === false) {
        return "Beleza, o print já basta. Obrigado! 💚";
    }
    get_db()->prepare("UPDATE reportes SET texto = ? WHERE id = ? AND usuario_id = ?")->execute([trim($texto), $s['reporte_id'] ?? 0, $usuario['id']]);
    $config = config_zagora();
    if (!empty($config['admin_telegram_id'])) {
        enviar_telegram($config, $config['admin_telegram_id'], sprintf("🐞 Reporte #%d — descrição: %s", $s['reporte_id'] ?? 0, trim($texto)));
    }
    return "Anotado ✅ Obrigado! 💚";
}

// Áudio, figurinha, vídeo, arquivo, localização... (o bot só entende texto) — responde em vez de ficar calado
function processar_nao_texto($mensagem) {
    if (!empty($mensagem['voice']) || !empty($mensagem['audio']) || !empty($mensagem['video_note'])) {
        return "🎙️ Ainda não entendo áudio — mas é só escrever do jeito que você falaria, tipo \"gastei 20 no mercado\". 🙂";
    }
    if (!empty($mensagem['sticker'])) {
        return "😄";
    }
    return "Por enquanto eu só entendo texto 🙂 Me escreve, tipo \"gastei 20 no mercado\" ou \"quanto gastei esse mês?\".";
}

// Foto recebida (o bot só entende texto): se é um reporte, guarda; senão, explica
function processar_foto($usuario, $mensagem) {
    $fotos = $mensagem['photo'] ?? [];
    $file_id = $fotos ? end($fotos)['file_id'] : null; // a última é a maior resolução
    $legenda = trim($mensagem['caption'] ?? '');
    $pediu = preg_match('/^\/(reportar|bug|erro)\b\s*/i', $legenda, $m);
    if ($pediu) $legenda = trim(substr($legenda, strlen($m[0])));

    if ($file_id && ($pediu || in_array($usuario['estado'] ?? null, ['aguardando_reporte', 'aguardando_reporte_descricao'], true))) {
        return salvar_reporte($usuario, $legenda, $file_id);
    }
    return "Recebi a imagem 👀 mas por enquanto eu só entendo texto. Se for pra reportar um erro, manda /reportar e depois o print.";
}

// /paysupport — exigência do Telegram pra bots que cobram. A próxima mensagem vai pro dono do bot.
function handle_paysupport($usuario) {
    salvar_estado($usuario['id'], 'aguardando_suporte', []);
    return "💬 Suporte de pagamentos\n\nMe conta o que aconteceu (ex: cobrou e não liberou, cobrança em dobro, quero reembolso) que eu encaminho — resposta em até 48h.\n\n"
         . "Pra cancelar a assinatura você não precisa de ajuda: Telegram → Configurações → Minhas Stars → assinatura do Zágora.";
}

function encaminhar_suporte($usuario, $texto) {
    limpar_estado($usuario['id']);
    $config = config_zagora();
    $pags = get_db()->prepare("SELECT valor_stars, tipo, recorrente, reembolsado, criado_em FROM pagamentos_stars WHERE usuario_id = ? ORDER BY id DESC LIMIT 3");
    $pags->execute([$usuario['id']]);
    $lista = implode("\n", array_map(fn($p) => sprintf("• %s — %d⭐ %s%s%s", date('d/m H:i', strtotime($p['criado_em'])), $p['valor_stars'], $p['tipo'], $p['recorrente'] ? ' (renovação)' : '', $p['reembolsado'] ? ' [reembolsado]' : ''), $pags->fetchAll())) ?: '(nenhum)';
    $s = status_plus($usuario);

    if (!empty($config['admin_telegram_id'])) {
        enviar_telegram($config, $config['admin_telegram_id'], sprintf(
            "🆘 Suporte de pagamento\nUsuário #%d (%s) — plano: %s%s\nÚltimos pagamentos:\n%s\n\nMensagem:\n%s\n\nResponder: /responder %d texto\nReembolsar o último: /reembolsar %d",
            $usuario['id'], $usuario['nome'] ?: 'sem nome', $s['tipo'] ?? 'grátis', $s['ate'] ? ' até ' . date('d/m/Y', strtotime($s['ate'])) : '',
            $lista, $texto, $usuario['id'], $usuario['id']));
    } else {
        registrar_erro("Suporte de pagamento sem admin_telegram_id configurado — usuário #{$usuario['id']}: {$texto}");
    }
    return "Recebido ✅ Já encaminhei — você recebe a resposta aqui mesmo, em até 48h.";
}

function eh_admin($usuario) {
    $admin = config_zagora()['admin_telegram_id'] ?? null;
    return $admin && (string) $usuario['telegram_id'] === (string) $admin;
}

// /painel (só o dono): link de acesso ao painel, uso único, vale 10 minutos. Guarda só o hash do token.
function gerar_link_painel() {
    $token = bin2hex(random_bytes(32));
    get_db()->prepare("INSERT INTO admin_tokens (token_hash, expira_em, criado_em) VALUES (?, ?, ?)")
        ->execute([hash('sha256', $token), date('Y-m-d H:i:s', strtotime('+10 minutes')), date('Y-m-d H:i:s')]);
    botoes_resposta([[['📊 Abrir o painel', 'https://zagora.space/admin/?t=' . $token]]]);
    return "🔐 Seu link do painel — vale 10 minutos e uma vez só. Não encaminhe pra ninguém.";
}

// Comandos só do dono do bot: /responder <usuario_id> <texto>, /reembolsar <usuario_id>
function handle_comando_admin($usuario, $comando) {
    if (!eh_admin($usuario)) return null;
    $config = config_zagora();

    if (preg_match('/^\/responder\s+(\d+)\s+(.+)$/su', $comando, $m)) {
        $stmt = get_db()->prepare("SELECT telegram_id FROM usuarios WHERE id = ?");
        $stmt->execute([(int) $m[1]]);
        $destino = $stmt->fetchColumn();
        if (!$destino) return "Usuário #{$m[1]} não existe.";
        enviar_telegram($config, $destino, "💬 Resposta do suporte do Zágora:\n\n" . trim($m[2]));
        return "Enviado pro usuário #{$m[1]}. ✅";
    }

    if (preg_match('/^\/reembolsar\s+(\d+)$/', $comando, $m)) {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT p.*, u.telegram_id FROM pagamentos_stars p JOIN usuarios u ON u.id = p.usuario_id WHERE p.usuario_id = ? AND p.reembolsado = 0 ORDER BY p.id DESC LIMIT 1");
        $stmt->execute([(int) $m[1]]);
        $pag = $stmt->fetch();
        if (!$pag) return "Usuário #{$m[1]} não tem pagamento pra reembolsar.";

        $r = json_decode(chamar_telegram($config, 'refundStarPayment', ['user_id' => (int) $pag['telegram_id'], 'telegram_payment_charge_id' => $pag['telegram_payment_charge_id']]), true);
        if (empty($r['ok'])) return "O Telegram recusou o reembolso: " . ($r['description'] ?? 'erro desconhecido');

        $pdo->prepare("UPDATE pagamentos_stars SET reembolsado = 1 WHERE id = ?")->execute([$pag['id']]);
        $pdo->prepare("UPDATE usuarios SET plus_ate = NOW() WHERE id = ? AND plus_tipo <> 'cortesia'")->execute([(int) $m[1]]);
        enviar_telegram($config, $pag['telegram_id'], "💸 Reembolsamos {$pag['valor_stars']} Stars — já voltaram pro seu saldo do Telegram. O Plus foi encerrado; você continua no Grátis.");
        return "Reembolso de {$pag['valor_stars']}⭐ feito pro usuário #{$m[1]}. ✅";
    }
    return null;
}

// Resumo diário pro dono (cron das 9h): o dia de ontem — usuários, pagamentos, reportes e erros do debug.log
function relatorio_diario_admin($config) {
    if (empty($config['admin_telegram_id'])) return false;
    $marca = sys_get_temp_dir() . '/zagora_relatorio_admin_' . date('Y-m-d');
    if (is_file($marca)) return false; // já mandou hoje (o cron pode rodar mais de uma vez)

    $pdo = get_db();
    $ontem = date('Y-m-d', strtotime('-1 day'));
    [$ini, $fim] = ["$ontem 00:00:00", "$ontem 23:59:59"];
    $conta = function ($sql, $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); };

    $novos = $conta("SELECT COUNT(*) FROM usuarios WHERE criado_em BETWEEN ? AND ?", [$ini, $fim]);
    $ativos = $conta("SELECT COUNT(*) FROM usuarios WHERE ultima_atividade BETWEEN ? AND ?", [$ini, $fim]);
    $pagamentos = $conta("SELECT COUNT(*) FROM pagamentos_stars WHERE criado_em BETWEEN ? AND ? AND reembolsado = 0", [$ini, $fim]);
    $stars = $conta("SELECT COALESCE(SUM(valor_stars), 0) FROM pagamentos_stars WHERE criado_em BETWEEN ? AND ? AND reembolsado = 0", [$ini, $fim]);
    $reportes = $conta("SELECT COUNT(*) FROM reportes WHERE status = 'aberto'", []);

    // Erros de ontem no debug.log, por tipo
    $erros = ['IA' => 0, 'pagamento' => 0, 'outros' => 0];
    $log = __DIR__ . '/debug.log';
    if (is_file($log)) {
        foreach (file($log, FILE_IGNORE_NEW_LINES) as $linha) {
            if (!str_starts_with($linha, "[$ontem")) continue;
            $tipo = preg_match('/Cerebras|curl|JSON da IA|decodificar/i', $linha) ? 'IA' : (preg_match('/createInvoiceLink|payment|pagamento/i', $linha) ? 'pagamento' : 'outros');
            $erros[$tipo]++;
        }
    }
    // Erros do próprio PHP (aviso, exceção não tratada) — formato "[30-Sep-2026 21:50:00 Europe/Lisbon] PHP Warning: ..."
    $log_php = __DIR__ . '/php_erros.log';
    if (is_file($log_php)) {
        $prefixo = '[' . date('d-M-Y', strtotime($ontem));
        foreach (file($log_php, FILE_IGNORE_NEW_LINES) as $linha) {
            if (str_starts_with($linha, $prefixo) && !str_contains($linha, 'PHP Deprecated')) $erros['outros']++;
        }
        // Não deixa o arquivo crescer pra sempre
        if (filesize($log_php) > 5 * 1024 * 1024) @rename($log_php, __DIR__ . '/php_erros_antigo.log'); // .log no fim: continua bloqueado pela web
    }
    $total_erros = array_sum($erros);

    $texto = sprintf("📋 Zágora — ontem (%s)\n\n👥 %d novo%s · %d ativo%s\n⭐ %d pagamento%s%s\n🐞 %d reporte%s aberto%s%s\n%s",
        date('d/m', strtotime($ontem)),
        $novos, $novos === 1 ? '' : 's', $ativos, $ativos === 1 ? '' : 's',
        $pagamentos, $pagamentos === 1 ? '' : 's', $stars ? " ({$stars} Stars)" : '',
        $reportes, $reportes === 1 ? '' : 's', $reportes === 1 ? '' : 's', $reportes ? ' — veja no /painel' : '',
        $total_erros ? sprintf("⚠️ %d erro%s: IA %d · pagamento %d · outros %d", $total_erros, $total_erros === 1 ? '' : 's', $erros['IA'], $erros['pagamento'], $erros['outros']) : "✅ Nenhum erro registrado");

    enviar_telegram($config, $config['admin_telegram_id'], $texto);
    @touch($marca);
    return true;
}

// Avisos de fim do teste e do anual (chamado 1x por dia pelo cron_resumo). Devolve quantos avisos mandou.
function enviar_avisos_plano($config) {
    $pdo = get_db();
    $agora = date('Y-m-d H:i:s');
    $em3dias = date('Y-m-d H:i:s', strtotime('+3 days'));
    $enviados = 0;

    foreach ($pdo->query("SELECT * FROM usuarios")->fetchAll() as $u) {
        $pago = !empty($u['plus_ate']) && $u['plus_ate'] > $agora;
        $texto = null;
        $nivel = (int) $u['aviso_plano'];

        if (!$pago && $u['trial_ate'] && $u['trial_ate'] > $agora && $u['trial_ate'] <= $em3dias && $nivel < 1) {
            $dias = max(1, (int) ceil((strtotime($u['trial_ate']) - time()) / 86400));
            $texto = sprintf("⏳ Faltam %d dia%s do seu teste grátis do Zágora Plus.\n\nPra continuar com contas fixas, orçamento, metas e resumos:\n%s", $dias, $dias === 1 ? '' : 's', texto_oferta_plus($u));
            $nivel = 1;
        } elseif (!$pago && $u['trial_ate'] && $u['trial_ate'] <= $agora && $nivel < 2 && $u['plus_tipo'] === null) {
            $texto = "Seu teste do Zágora Plus terminou. Você continua no Grátis — gastos, saldo e até 3 lembretes, nada se perde.\n\nQuando quiser voltar pro Plus:\n" . texto_oferta_plus($u);
            $nivel = 2;
        } elseif ($pago && $u['plus_tipo'] === 'anual' && $u['plus_ate'] <= date('Y-m-d H:i:s', strtotime('+7 days')) && $nivel < 1) {
            $texto = sprintf("⏳ Seu Zágora Plus anual vence em %s. Pra não perder nada, renova aqui:\n%s", date('d/m', strtotime($u['plus_ate'])), texto_oferta_plus($u));
            $nivel = 1;
        }

        if ($texto) {
            botoes_resposta(null, true);
            $botoes = botoes_assinatura($u);
            enviar_telegram($config, $u['telegram_id'], $texto, $botoes);
            $pdo->prepare("UPDATE usuarios SET aviso_plano = ? WHERE id = ?")->execute([$nivel, $u['id']]);
            $enviados++;
        }
    }
    return $enviados;
}

// ================= Listas (compras, tarefas, filmes...) =================
// Sem dizer qual lista = a única que existe, ou a de compras. Riscar = feito=1 (continua aparecendo, riscado,
// até "limpa os riscados"). Grátis: é o uso de todo dia — as frases comuns nem passam pela IA (atalho_lista).

const LISTA_PADRAO = 'compras';
const LISTA_MAX_ITENS = 100;
const LISTA_MAX_LISTAS = 20;

// "a lista de compras" -> "compras"; mercado/supermercado/feira = compras; afazeres/to do = tarefas
function nome_lista($nome) {
    $n = trim(mb_strtolower($nome ?? ''), " .!?\"'“”");
    $n = preg_replace('/^(?:a\s+|as\s+|minha\s+|minhas\s+|na\s+|da\s+)?(?:lista\s+|listas\s+)?(?:(?:de|do|da|dos|das|pra|para|pro)\s+)?/u', '', $n);
    $n = trim($n);
    if (preg_match('/^(?:(?:o|a)\s+)?(?:super)?mercado$|^feira$|^compras?$/u', sem_acentos($n))) return LISTA_PADRAO;
    if (preg_match('/^(?:afazeres|to\s*-?\s*do|coisas\s+(?:pra|para)\s+fazer|tarefa)$/u', sem_acentos($n))) return 'tarefas';
    return mb_substr($n, 0, 60);
}

function listas_do_usuario($usuario_id) {
    $stmt = get_db()->prepare("SELECT l.*, (SELECT COUNT(*) FROM lista_itens i WHERE i.lista_id = l.id AND i.feito = 0) AS pendentes,
        (SELECT COUNT(*) FROM lista_itens i WHERE i.lista_id = l.id) AS total FROM listas l WHERE l.usuario_id = ? ORDER BY l.id");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll();
}

// Acha a lista pelo nome (sem acento, 1 letra de diferença). Sem nome: a única que existe, ou a de compras.
function achar_lista($usuario_id, $nome, $criar = false) {
    $listas = listas_do_usuario($usuario_id);
    $nome = trim($nome ?? '') !== '' ? nome_lista($nome) : (count($listas) === 1 ? $listas[0]['nome'] : LISTA_PADRAO);
    if ($nome === '') $nome = LISTA_PADRAO;
    foreach ($listas as $l) {
        $a = sem_acentos($l['nome']);
        $b = sem_acentos($nome);
        if ($a === $b || rtrim($a, 's') === rtrim($b, 's') || (mb_strlen($b) >= 5 && levenshtein($a, $b) <= 1)) return $l;
    }
    if (!$criar) return null;
    if (count($listas) >= LISTA_MAX_LISTAS) return null;
    get_db()->prepare("INSERT INTO listas (usuario_id, nome, criado_em) VALUES (?, ?, ?)")->execute([$usuario_id, $nome, date('Y-m-d H:i:s')]);
    return ['id' => (int) get_db()->lastInsertId(), 'usuario_id' => $usuario_id, 'nome' => $nome, 'pendentes' => 0, 'total' => 0];
}

// "tomar banho, lavar roupas" (começam com verbo no infinitivo) = tarefas; "leite, pão" = compras
function lista_pelos_itens($itens) {
    if (!$itens) return LISTA_PADRAO;
    $verbos = 0;
    foreach ($itens as $i) {
        $primeira = strtok(sem_acentos(trim($i)), ' ');
        // infinitivo ("lavar", "buscar", "fazer", "pôr") — com cuidado pra "açúcar", "bar", "colher", "mulher" não contarem
        if ($primeira && preg_match('/^\p{L}{2,}(ar|er|ir|or)$/u', $primeira) && !in_array($primeira, ['acucar', 'bar', 'colher', 'mulher', 'talher', 'licor', 'amor', 'cor', 'flor', 'ar', 'mar', 'lugar', 'jantar', 'almocar', 'par', 'luar', 'elixir', 'lazer', 'computador', 'ventilador', 'secador', 'carregador', 'liquidificador', 'barbeador', 'removedor', 'desodor', 'amaciante', 'hambur'], true)) {
            $verbos++;
        }
    }
    return $verbos * 2 >= count($itens) ? 'tarefas' : LISTA_PADRAO;
}

// "leite, pão e 2 dúzias de ovos" -> ["leite", "pão", "2 dúzias de ovos"]
function itens_do_texto($texto) {
    $partes = preg_split('/\s*(?:,|;|\n|\s+e\s+|\s+\+\s+)\s*/u', trim($texto, " .!?"));
    $itens = [];
    foreach ($partes as $p) {
        $p = trim(preg_replace('/^(?:o|a|os|as|um|uma|uns|umas)\s+/iu', '', trim($p, " .!?\"'“”-•")));
        if ($p !== '' && mb_strlen($p) <= 120) $itens[] = $p;
    }
    return array_slice($itens, 0, 30);
}

// Itens da IA ou do texto, limpos
function normalizar_itens($itens) {
    if (is_string($itens)) return itens_do_texto($itens);
    if (!is_array($itens)) return [];
    $saida = [];
    foreach ($itens as $i) {
        if (!is_scalar($i)) continue;
        foreach (itens_do_texto((string) $i) as $x) $saida[] = $x;
    }
    return array_slice($saida, 0, 30);
}

function titulo_lista($nome) {
    $emoji = match (sem_acentos($nome)) { 'compras' => '🛒', 'tarefas' => '✅', 'filmes', 'series' => '🎬', 'presentes' => '🎁', 'livros' => '📚', default => '📝' };
    return "$emoji Lista de $nome";
}

// Texto + botões de uma lista: um botão por item pendente pra riscar, e "limpar riscados" se tiver
function montar_lista($lista) {
    $stmt = get_db()->prepare("SELECT * FROM lista_itens WHERE lista_id = ? ORDER BY feito, id");
    $stmt->execute([$lista['id']]);
    $itens = $stmt->fetchAll();
    $pendentes = array_values(array_filter($itens, fn($i) => !$i['feito']));
    $feitos = array_values(array_filter($itens, fn($i) => $i['feito']));

    if (!$itens) {
        return [titulo_lista($lista['nome']) . " está vazia. ✨\nPra pôr coisas: \"adiciona leite e pão na lista" . ($lista['nome'] !== LISTA_PADRAO ? " de {$lista['nome']}" : '') . "\".", null];
    }
    $texto = titulo_lista($lista['nome']) . ($pendentes ? ' (' . count($pendentes) . ')' : '') . ":\n";
    foreach ($pendentes as $i) $texto .= "▫️ {$i['texto']}\n";
    foreach ($feitos as $i) $texto .= "✔️ " . implode('', array_map(fn($c) => $c . "\u{0336}", mb_str_split($i['texto']))) . "\n";
    if (!$pendentes) $texto .= "\n🎉 Tudo feito!";
    elseif (count($pendentes) > 12) $texto .= "\n(Botões pros 12 primeiros — pros outros, diga \"risca o X\".)";

    $botoes = array_chunk(array_map(fn($i) => ['✔️ ' . mb_strimwidth($i['texto'], 0, 28, '…'), "I:ok:{$i['id']}"], array_slice($pendentes, 0, 12)), 2);
    if ($feitos) $botoes[] = [['🧹 Limpar riscados', "I:limpar:{$lista['id']}"]];
    return [trim($texto), $botoes];
}

function responder_lista($lista, $prefixo = '') {
    [$texto, $botoes] = montar_lista($lista);
    if ($botoes) botoes_resposta($botoes);
    return ($prefixo !== '' ? $prefixo . "\n\n" : '') . $texto;
}

// "e" bonito: "leite, pão e ovos"
function juntar_itens($itens) {
    if (count($itens) <= 1) return implode('', $itens);
    return implode(', ', array_slice($itens, 0, -1)) . ' e ' . end($itens);
}

// "cria uma lista de compras" -> cria e pergunta os itens; "cria uma lista" / "cria um checklist" -> pergunta o nome antes
function handle_lista_criar($usuario, $dados) {
    $itens = normalizar_itens($dados['itens'] ?? []);
    if (empty($dados['lista'])) {
        salvar_estado($usuario['id'], 'aguardando_nome_lista', ['itens' => $itens]);
        botoes_resposta([[botao_texto('🛒 Compras', 'compras'), botao_texto('✅ Tarefas', 'tarefas')]]);
        return "Bora! 📝 Qual o nome da lista? (ex: compras, tarefas, checklist da viagem, filmes)";
    }
    $lista = achar_lista($usuario['id'], $dados['lista'], true);
    if (!$lista) return "Você já tem " . LISTA_MAX_LISTAS . " listas — apaga uma (\"apaga a lista de X\") pra criar outra. 🙂";
    if ($itens) return handle_lista_adicionar($usuario, ['lista' => $lista['nome'], 'itens' => $itens]);
    salvar_estado($usuario['id'], 'aguardando_itens_lista', ['lista' => $lista['nome']]);
    return sprintf("%s pronta! O que vai nela? Manda tudo numa mensagem, separado por vírgula (ex: \"%s\").",
        titulo_lista($lista['nome']), sem_acentos($lista['nome']) === 'compras' ? 'leite, pão, café' : 'item 1, item 2, item 3');
}

// Resposta às perguntas "qual o nome da lista?" / "o que vai nela?". Null = não era resposta (é outro pedido).
function continuar_criacao_lista($usuario, $texto) {
    $estado = $usuario['estado'] ?? null;
    if (!in_array($estado, ['aguardando_nome_lista', 'aguardando_itens_lista'], true)) return null;
    $s = json_decode($usuario['estado_dados'] ?? '{}', true) ?: [];
    // Pedido novo no meio ("gastei 20", "mostra a lista", "me lembra..."): larga a pergunta e segue
    if (preg_match('/^\s*(?:\/|gastei|paguei|recebi|quanto|qual|quais|me\s+lembra|lembra|mostra|mostre|minhas?\s|saldo|resumo|ajuda|apaga|cancela|tenho\s+\d|comprei\s+\S+\s+\d)/iu', $texto)) {
        limpar_estado($usuario['id']);
        return null;
    }
    limpar_estado($usuario['id']);
    if ($estado === 'aguardando_nome_lista') {
        // Mandou os itens em vez do nome ("leite, pão, ovos"): a lista sai pelo jeito dos itens
        if (preg_match('/,/', $texto) && count(itens_do_texto($texto)) >= 2) {
            $itens = array_merge($s['itens'] ?? [], itens_do_texto($texto));
            return handle_lista_criar($usuario, ['lista' => lista_pelos_itens($itens), 'itens' => $itens]);
        }
        $nome = nome_lista($texto);
        if ($nome === '' || mb_strlen($nome) > 40) return "Hmm, esse nome ficou estranho 🤔 Tenta de novo: \"cria uma lista de compras\".";
        return handle_lista_criar($usuario, ['lista' => $nome, 'itens' => $s['itens'] ?? []]);
    }
    return handle_lista_adicionar($usuario, ['lista' => $s['lista'] ?? null, 'itens' => itens_do_texto($texto)]);
}

function handle_lista_adicionar($usuario, $dados) {
    $itens = normalizar_itens($dados['itens'] ?? []);
    if (!$itens) {
        // "cria uma lista de viagem" que a IA mandou como adicionar sem itens = criar
        return handle_lista_criar($usuario, $dados);
    }
    // Sem dizer a lista e os itens são ações ("tomar banho, lavar roupas"): vão pra tarefas, não pra compras
    if (empty($dados['lista']) && lista_pelos_itens($itens) === 'tarefas') {
        $dados['lista'] = 'tarefas';
    }
    $lista = achar_lista($usuario['id'], $dados['lista'] ?? null, true);
    if (!$lista) return "Você já tem " . LISTA_MAX_LISTAS . " listas — apaga uma (\"apaga a lista de X\") pra criar outra. 🙂";

    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM lista_itens WHERE lista_id = ?");
    $stmt->execute([$lista['id']]);
    $existentes = $stmt->fetchAll();
    $novos = [];
    $ja_tinha = [];
    foreach ($itens as $item) {
        $igual = current(array_filter($existentes, fn($e) => sem_acentos($e['texto']) === sem_acentos($item)));
        if ($igual && !$igual['feito']) { $ja_tinha[] = $igual['texto']; continue; }
        if ($igual) { // estava riscado: volta pra lista
            $pdo->prepare("UPDATE lista_itens SET feito = 0, feito_em = NULL WHERE id = ?")->execute([$igual['id']]);
            $novos[] = $item;
            continue;
        }
        if (count($existentes) + count($novos) >= LISTA_MAX_ITENS) break;
        $pdo->prepare("INSERT INTO lista_itens (lista_id, usuario_id, texto, criado_em) VALUES (?, ?, ?, ?)")
            ->execute([$lista['id'], $usuario['id'], $item, date('Y-m-d H:i:s')]);
        $existentes[] = ['texto' => $item, 'feito' => 0];
        $novos[] = $item;
    }

    $partes = [];
    if ($novos) $partes[] = "📝 Anotei na lista de {$lista['nome']}: " . juntar_itens($novos) . '.';
    if ($ja_tinha) $partes[] = (count($ja_tinha) === 1 ? 'Já estava lá: ' : 'Já estavam lá: ') . juntar_itens($ja_tinha) . '.';
    if (!$novos && !$ja_tinha) $partes[] = "A lista de {$lista['nome']} está cheia (" . LISTA_MAX_ITENS . " itens) — risca ou limpa alguns antes. 🙂";
    return responder_lista($lista, implode(' ', $partes));
}

function handle_lista_ver($usuario, $dados) {
    $listas = listas_do_usuario($usuario['id']);
    if (!empty($dados['lista'])) {
        $lista = achar_lista($usuario['id'], $dados['lista']);
        if (!$lista) {
            return "Não tenho nenhuma lista de " . nome_lista($dados['lista']) . " 🤔" . ($listas ? " Suas listas: " . implode(', ', array_column($listas, 'nome')) . '.' : " Pra criar: \"adiciona X na lista de " . nome_lista($dados['lista']) . "\".");
        }
        return responder_lista($lista);
    }
    if (!$listas) {
        return "Você ainda não tem nenhuma lista. 📝\nPra criar: \"adiciona leite e pão na lista\" (vai pra de compras) ou \"lista de tarefas: pagar o IRS, ligar pro contador\".";
    }
    if (count($listas) === 1) return responder_lista($listas[0]);
    $texto = "📝 Suas listas:\n";
    $botoes = [];
    foreach ($listas as $l) {
        $texto .= sprintf("• %s — %s\n", ucfirst($l['nome']), $l['pendentes'] ? $l['pendentes'] . ' pra fazer' : 'tudo feito');
        $botoes[] = botao_texto(titulo_lista($l['nome']), "mostra a lista de {$l['nome']}");
    }
    botoes_resposta(array_chunk($botoes, 2));
    return trim($texto);
}

// Acha itens (pendentes, ou todos) pelo nome: igual, um contém o outro, plural, 1 letra de diferença
function achar_itens_lista($usuario_id, $lista, $nomes, $so_pendentes = true) {
    $sql = "SELECT i.*, l.nome AS lista_nome FROM lista_itens i JOIN listas l ON l.id = i.lista_id WHERE i.usuario_id = ?" . ($so_pendentes ? " AND i.feito = 0" : '');
    $params = [$usuario_id];
    if ($lista) { $sql .= " AND i.lista_id = ?"; $params[] = $lista['id']; }
    $stmt = get_db()->prepare($sql . " ORDER BY i.id DESC");
    $stmt->execute($params);
    $todos = $stmt->fetchAll();
    $singular = fn($p) => preg_replace('/(?<=\p{L}{3})s$/u', '', $p);
    $achados = [];
    foreach ($nomes as $nome) {
        $alvo = $singular(sem_acentos(trim($nome)));
        if ($alvo === '') continue;
        foreach ($todos as $i) {
            if (isset($achados[$i['id']])) continue;
            $t = $singular(sem_acentos($i['texto']));
            if ($t === $alvo || (mb_strlen($alvo) >= 3 && (str_contains($t, $alvo) || str_contains($alvo, $t))) || (mb_strlen($alvo) >= 5 && levenshtein($t, $alvo) <= 1)) {
                $achados[$i['id']] = $i;
                break; // um item por nome
            }
        }
    }
    return array_values($achados);
}

function handle_lista_marcar($usuario, $dados, $remover = false) {
    $nomes = normalizar_itens($dados['itens'] ?? []);
    if (!$nomes) return $remover ? "O que você quer tirar da lista?" : "O que você quer riscar da lista?";
    $lista = !empty($dados['lista']) ? achar_lista($usuario['id'], $dados['lista']) : null;
    $itens = achar_itens_lista($usuario['id'], $lista, $nomes, !$remover);
    if (!$itens) {
        return sprintf("Não achei %s na%s lista 🤔 Manda \"mostra a lista\" pra ver o que tem.", juntar_itens($nomes), $lista ? " {$lista['nome']}" : '');
    }
    $ids = array_column($itens, 'id');
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $sql = $remover ? "DELETE FROM lista_itens WHERE usuario_id = ? AND id IN ($marcadores)"
                    : "UPDATE lista_itens SET feito = 1, feito_em = '" . date('Y-m-d H:i:s') . "' WHERE usuario_id = ? AND id IN ($marcadores)";
    get_db()->prepare($sql)->execute(array_merge([$usuario['id']], $ids));

    $lista ??= achar_lista($usuario['id'], $itens[0]['lista_nome']);
    $feitos = array_column($itens, 'texto');
    return responder_lista($lista, ($remover ? '🗑️ Tirei da lista: ' : '✔️ Risquei: ') . juntar_itens($feitos) . '.');
}

// "limpa os riscados" apaga só os feitos (sem perguntar); "limpa/apaga a lista" pede confirmação
function handle_lista_limpar($usuario, $dados) {
    $lista = achar_lista($usuario['id'], $dados['lista'] ?? null);
    if (!$lista) return "Não achei essa lista 🤔 Manda \"minhas listas\" pra ver as que você tem.";
    if (!empty($dados['so_feitos'])) {
        $stmt = get_db()->prepare("DELETE FROM lista_itens WHERE lista_id = ? AND feito = 1");
        $stmt->execute([$lista['id']]);
        return responder_lista($lista, $stmt->rowCount() ? "🧹 Limpei {$stmt->rowCount()} riscado(s)." : "Não tinha nada riscado. 🙂");
    }
    salvar_estado($usuario['id'], 'aguardando_confirmacao_apagar', ['lista_id' => (int) $lista['id'], 'descricao' => "a lista de {$lista['nome']}"]);
    botoes_resposta([[botao_texto('🗑️ Sim, apaga', 'sim'), botao_texto('Não', 'não')]]);
    return sprintf("Apago a lista de %s inteira (%d %s)? 🗑️ (sim/não)", $lista['nome'], $lista['total'], $lista['total'] == 1 ? 'item' : 'itens');
}

// Botão de um item (I:ok:<id>) ou "limpar riscados" (I:limpar:<lista_id>): muda e devolve a lista atualizada
// (texto + botões) pra editar a própria mensagem — null se o botão não vale mais
function handle_callback_lista($usuario, $callback_data) {
    if (!preg_match('/^I:(ok|limpar):(\d+)$/', $callback_data, $m)) return null;
    $pdo = get_db();
    if ($m[1] === 'ok') {
        $stmt = $pdo->prepare("SELECT lista_id FROM lista_itens WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$m[2], $usuario['id']]);
        $lista_id = $stmt->fetchColumn();
        if (!$lista_id) return null;
        $pdo->prepare("UPDATE lista_itens SET feito = 1, feito_em = ? WHERE id = ? AND usuario_id = ?")->execute([date('Y-m-d H:i:s'), $m[2], $usuario['id']]);
    } else {
        $lista_id = (int) $m[2];
        $pdo->prepare("DELETE FROM lista_itens WHERE lista_id = ? AND usuario_id = ? AND feito = 1")->execute([$lista_id, $usuario['id']]);
    }
    $stmt = $pdo->prepare("SELECT * FROM listas WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$lista_id, $usuario['id']]);
    $lista = $stmt->fetch();
    return $lista ? montar_lista($lista) : null;
}

// Frases comuns de lista resolvidas sem IA. Devolve os $dados (com intent) ou null.
function atalho_lista($usuario, $texto) {
    $t = trim($texto, " .!?\n\t");
    $s = sem_acentos($t);
    // Ver: "lista", "minha lista", "mostra a lista de compras", "o que tem na lista?", "minhas listas"
    if (preg_match('/^(?:me\s+)?(?:mostra|mostre|ver|ve|abre|abrir|manda)?\s*(?:a\s+|as\s+|minha\s+|minhas\s+)?listas?(?:\s+(?:de|do|da|dos|das)\s+(.+))?$/u', $s, $m)
        || preg_match('/^o\s+que\s+(?:tem|falta|ta|esta)\s+na\s+(?:minha\s+)?lista(?:\s+(?:de|do|da)\s+(.+))?$/u', $s, $m)) {
        // "lista dos gastos", "lista de lembretes" são as outras listagens do bot, não uma lista do usuário
        if (isset($m[1]) && preg_match('/gast|despes|receit|lembret|categor|conta|meta|transa|fix|orcament|agenda/u', $m[1])) return null;
        return array_filter(['intent' => 'lista_ver', 'lista' => isset($m[1]) ? nome_lista_original($t, $m[1]) : null]);
    }
    // Criar: "cria uma lista de compras", "cria um checklist que preciso seguir", "cira uma lista" (erro), "nova lista: X, Y"
    if (preg_match('/^(?:cria|crie|criar|cira|cria\s+ai|faz|faca|faça|monta|monte|abre|abrir|nova|novo|quero)\s+(?:uma?\s+)?(?:nova\s+|novo\s+)?(?:lista|checklist|check-list|check\s+list|to-?\s?do)\b(.*)$/iu', $t, $m)) {
        $resto = trim($m[1]);
        $itens = [];
        if (preg_match('/^(.*?)\s*(?::|\s+com\s+)\s*(.+)$/u', $resto, $x)) { $resto = trim($x[1]); $itens = itens_do_texto($x[2]); }
        // Nome só com "de/do/da X" ("lista de compras"); "pra eu lembrar", "que preciso seguir" não é nome
        $nome = preg_match('/^(?:de|do|da|dos|das)\s+(.+)$/iu', $resto, $x) ? $x[1] : null;
        return array_filter(['intent' => 'lista_criar', 'lista' => $nome, 'itens' => $itens]);
    }
    // Adicionar: "adiciona leite e pão na lista (de compras)", "coloca X na lista"
    if (preg_match('/^(?:adiciona|adicione|adicionar|acrescenta|acrescente|coloca|coloque|poe|bota|inclui|inclua|anota|anote|p[oõ]e)\s+(.+?)\s+(?:na|a|à|pra|para\s+a|pra\s+a)\s+(?:minha\s+)?lista(?:\s+(?:de|do|da|dos|das)\s+(.+))?$/iu', $t, $m)) {
        return array_filter(['intent' => 'lista_adicionar', 'itens' => itens_do_texto($m[1]), 'lista' => $m[2] ?? null]);
    }
    // "lista de compras: leite, pão" / "compras: leite, pão"
    if (preg_match('/^(?:lista(?:\s+(?:de|do|da)\s+([^:]+?))?|compras)\s*:\s*(.+)$/iu', $t, $m)) {
        return array_filter(['intent' => 'lista_adicionar', 'itens' => itens_do_texto($m[2]), 'lista' => ($m[1] ?? '') !== '' ? $m[1] : null]);
    }
    // Riscar: "risca o leite", "marca o pão como feito" — só se estiver mesmo numa lista ("marca o seguro como pago" é conta)
    if (preg_match('/^(?:risca|risque|riscar|marca|marque)\s+(.+?)(?:\s+como\s+(?:feito|feita|comprado|comprada)s?)?(?:\s+da\s+lista(?:\s+(?:de|do|da)\s+(.+))?)?$/iu', $t, $m)
        && achar_itens_lista($usuario['id'], null, itens_do_texto($m[1]))) {
        return array_filter(['intent' => 'lista_marcar', 'itens' => itens_do_texto($m[1]), 'lista' => $m[2] ?? null]);
    }
    // Tirar sem ter feito: "tira o café da lista"
    if (preg_match('/^(?:tira|tire|remove|remova|apaga|apague)\s+(.+?)\s+da\s+(?:minha\s+)?lista(?:\s+(?:de|do|da)\s+(.+))?$/iu', $t, $m)) {
        return array_filter(['intent' => 'lista_remover', 'itens' => itens_do_texto($m[1]), 'lista' => $m[2] ?? null]);
    }
    // Limpar: "limpa os riscados/comprados/feitos", "limpa a lista (de X)"
    if (preg_match('/^(?:limpa|limpe|limpar)\s+(?:os\s+|as\s+)?(?:riscad|comprad|feit|conclu)\w*(?:\s+da\s+lista(?:\s+(?:de|do|da)\s+(.+))?)?$/u', $s, $m)) {
        return array_filter(['intent' => 'lista_limpar', 'so_feitos' => true, 'lista' => $m[1] ?? null]);
    }
    if (preg_match('/^(?:limpa|limpe|limpar|apaga|apague|zera|esvazia)\s+(?:a\s+)?(?:minha\s+)?lista(?:\s+(?:de|do|da)\s+(.+))?$/u', $s, $m)) {
        return array_filter(['intent' => 'lista_limpar', 'lista' => $m[1] ?? null]);
    }
    // "já comprei o leite" / "comprei pão" SEM valor: se bate com item da lista, é riscar (com valor é gasto — ver lista_riscar_pelo_gasto)
    if (preg_match('/^(?:ja\s+)?(?:comprei|peguei|fiz|liguei|mandei|busquei)\s+(.+)$/u', $s, $m) && !preg_match('/\d/', $t)
        && ($itens = achar_itens_lista($usuario['id'], null, itens_do_texto($m[1])))) {
        return ['intent' => 'lista_marcar', 'itens' => array_column($itens, 'texto')];
    }
    return null;
}

// O nome da lista como a pessoa escreveu (com acento), a partir do pedaço achado no texto sem acento
function nome_lista_original($original, $pedaco_sem_acento) {
    $pos = mb_strrpos(sem_acentos($original), $pedaco_sem_acento);
    return $pos === false ? $pedaco_sem_acento : mb_substr($original, $pos, mb_strlen($pedaco_sem_acento));
}

// "comprei leite 2 euros": além de anotar o gasto, risca o leite da lista (se estiver lá). Devolve a nota ou ''.
function lista_riscar_pelo_gasto($usuario, $dados) {
    $nomes = array_filter([$dados['descricao'] ?? null, $dados['estabelecimento'] ?? null]);
    if (!$nomes) return '';
    $itens = achar_itens_lista($usuario['id'], null, [reset($nomes)]);
    if (!$itens) return '';
    get_db()->prepare("UPDATE lista_itens SET feito = 1, feito_em = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $itens[0]['id']]);
    return "\n✔️ Risquei \"{$itens[0]['texto']}\" da lista de {$itens[0]['lista_nome']}.";
}
