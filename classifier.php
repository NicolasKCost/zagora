<?php

// Detecta "daqui X minutos/horas" ou "em X minutos/horas" direto em PHP — mais confiável que pedir pra IA fazer conta de hora.
function detectar_tempo_relativo($texto) {
    // aceita "daqui 5", "daqui a 5", "daqui há 5" (erro comum) e "em 5"
    if (preg_match('/\b(daqui(?:\s+(?:h[aá]|a))?|em)\s+(\d+)\s*(minutos?|mins?)\b/ui', $texto, $m)) {
        return date('Y-m-d H:i', strtotime("+{$m[2]} minutes"));
    }
    if (preg_match('/\b(daqui(?:\s+(?:h[aá]|a))?|em)\s+(\d+)\s*(horas?|hrs?|h)\b/ui', $texto, $m)) {
        return date('Y-m-d H:i', strtotime("+{$m[2]} hours"));
    }
    return null;
}

// Acha um dia da semana na frase ("sexta", "segunda-feira", "sábado") e devolve a data (Y-m-d) dele.
// A IA erra conta de calendário com frequência (ex: "segunda" virando sábado), então isso é feito aqui.
// $direcao: 'futuro' = hoje ou próximos 6 dias; 'passado' = hoje ou 6 dias pra trás.
function detectar_dia_semana($texto, $direcao = 'futuro') {
    $dias = ['domingo' => 0, 'segunda' => 1, 'terca' => 2, 'terça' => 2, 'quarta' => 3, 'quinta' => 4, 'sexta' => 5, 'sabado' => 6, 'sábado' => 6];
    if (!preg_match('/(?<![\p{L}])(domingo|segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado)(?:[\s-]*feira)?(?![\p{L}])/ui', $texto, $m)) {
        return null;
    }
    $alvo = $dias[mb_strtolower($m[1])];
    $hoje = (int) date('w');
    $diferenca = $direcao === 'passado' ? -(($hoje - $alvo + 7) % 7) : ($alvo - $hoje + 7) % 7;
    return date('Y-m-d', strtotime("{$diferenca} days"));
}

// Pedidos comuns que são EXATAMENTE uma dessas frases (sem mais nada) não precisam da IA — vão direto pro mesmo
// intent que ela devolveria. Qualquer palavra a mais ("meus gastos de agosto") continua indo pra IA.
function atalho_sem_ia($texto) {
    $t = sem_acentos(trim(preg_replace('/\s+/', ' ', $texto), " .,!?\n\t"));
    $atalhos = [
        'saldo' => ['saldo'], 'meu saldo' => ['saldo'], 'quanto eu tenho' => ['saldo'], 'quanto tenho' => ['saldo'],
        'saldo por conta' => ['saldo_por_conta'], 'quanto tenho em cada conta' => ['saldo_por_conta'], 'minhas contas' => ['saldo_por_conta'],
        'meus gastos' => ['list_expenses', 'mes'], 'lista meus gastos' => ['list_expenses', 'mes'], 'lista os gastos' => ['list_expenses', 'mes'],
        'mostra meus gastos' => ['list_expenses', 'mes'], 'minhas despesas' => ['list_expenses', 'mes'], 'lista minhas despesas' => ['list_expenses', 'mes'],
        'minhas receitas' => ['list_income', 'mes'], 'lista minhas receitas' => ['list_income', 'mes'],
        'quanto gastei esse mes' => ['query_spending', 'mes'], 'quanto gastei este mes' => ['query_spending', 'mes'],
        'quanto gastei hoje' => ['query_spending', 'hoje'], 'quanto gastei essa semana' => ['query_spending', 'semana'], 'quanto gastei esta semana' => ['query_spending', 'semana'],
        'meus lembretes' => ['listar_lembretes'], 'lista meus lembretes' => ['listar_lembretes'], 'lembretes' => ['listar_lembretes'],
        'minha agenda' => ['listar_lembretes', 'semana'], 'agenda da semana' => ['listar_lembretes', 'semana'], 'minha agenda da semana' => ['listar_lembretes', 'semana'],
        'o que tenho hoje' => ['listar_lembretes', 'hoje'], 'o que tenho amanha' => ['listar_lembretes', 'amanha'],
        'o que falta pagar' => ['contas_pendentes'], 'contas a pagar' => ['contas_pendentes'],
        'meus gastos fixos' => ['listar_contas_fixas'], 'gastos fixos' => ['listar_contas_fixas'], 'minhas contas fixas' => ['listar_contas_fixas'], 'minhas despesas fixas' => ['listar_contas_fixas'],
        'minhas metas' => ['listar_metas'], 'metas' => ['listar_metas'],
        'meu orcamento' => ['listar_orcamentos'], 'orcamento' => ['listar_orcamentos'], 'como ta meu orcamento' => ['listar_orcamentos'], 'como esta meu orcamento' => ['listar_orcamentos'],
        'minhas categorias' => ['listar_categorias'], 'lista minhas categorias' => ['listar_categorias'], 'categorias' => ['listar_categorias'],
        'resumo' => ['resumo', 'semana'], 'resumo da semana' => ['resumo', 'semana'], 'resumo do mes' => ['resumo', 'mes'],
        'ajuda' => ['ajuda'], 'o que voce faz' => ['ajuda'],
        'meu plano' => ['plano'], 'plano' => ['plano'],
        'quanto de agua bebi hoje' => ['relatorio_agua', 'hoje'],
    ];
    // "quanto preciso guardar por semana pro calção do quarto?" — a IA devolvia unknown
    if (preg_match('/^(?:e\s+)?quanto\s+(?:eu\s+)?(?:tenho\s+que|tenho\s+de|preciso|devo|vou\s+ter\s+que|teria\s+que|precisaria)\s+(?:guardar|juntar|poupar|separar|economizar|por\s+de\s+lado)\s+(?:por|ao|a|cada)\s+(dia|semana|mes)(?:\s+(?:pra|para|pro|pela|pelo|na|no|da|do)\s+(?:a\s+|o\s+)?(?:meta\s+(?:da|do|de)\s+)?(.+))?$/u', $t, $m)) {
        return array_filter(['intent' => 'listar_metas', 'frequencia' => $m[1], 'meta' => $m[2] ?? null]);
    }
    // Botão "qual delas?" do cancelar lembrete
    if (preg_match('/^cancela o lembrete #(\d+)$/u', $t, $m)) {
        return ['intent' => 'cancelar_lembrete', 'termo' => '#' . $m[1]];
    }
    // "apaga" / "apaga isso" logo depois de anotar = o último registro (com a confirmação de sempre)
    if (preg_match('/^(apaga|apague|exclui|deleta|remove|desfaz|desfazer)(\s+(isso|esse|este|esse gasto|o ultimo|o ultimo gasto|essa|esta))?$/u', $t)) {
        return ['intent' => 'deletar_transacao', 'ultimo' => true];
    }
    // "o prazo do calção do novo quarto é dezembro" / "... é 15 dias" — a IA devolvia unknown
    if (preg_match('/^(?:o\s+)?prazo\s+(?:da\s+meta\s+)?(?:do|da|de|pro|pra|para\s+o|para\s+a)\s+(.+?)\s+(?:e|vai\s+ser|agora\s+e|passa\s+(?:a\s+ser|pra|para)|fica(?:\s+(?:pra|para|em))?|muda\s+(?:pra|para))\s+(.+)$/u', $t, $m)
        && ($prazo = interpretar_prazo($m[2]))) {
        return ['intent' => 'editar_meta', 'meta' => $m[1], 'prazo_novo' => $prazo];
    }
    if (!isset($atalhos[$t])) return null;
    [$intent, $periodo] = $atalhos[$t] + [1 => null];
    return array_filter(['intent' => $intent, 'periodo' => $periodo]);
}

// "guardei 100", "separei 50 pra viagem", "tirei 30 da reserva" — padrão claro demais pra depender da IA
// (visto nos testes: "guardei 100" voltando "unknown" duas vezes seguidas). Só age se a IA não entendeu.
function corrigir_aporte_meta($intent, $dados, $texto) {
    if ($intent !== 'unknown') return [$intent, $dados];
    if (!preg_match('/^\s*(?:eu\s+)?(guardei|separei|poupei|juntei|coloquei|pus|tirei|retirei|usei)\s+(?:r\$|€|\$)?\s*(\d+(?:[.,]\d+)?)\s*(?:€|euros?|reais)?(?:\s+(?:pra|para|na|no|da|do|nas|nos)\s+(?:a\s+|o\s+)?(?:meta\s+(?:da|do|de)\s+)?(.+?))?[.!]*$/iu', $texto, $m)) {
        return [$intent, $dados];
    }
    $retirar = in_array(mb_strtolower($m[1]), ['tirei', 'retirei', 'usei'], true);
    return ['aporte_meta', array_filter(['intent' => 'aporte_meta', 'valor' => (float) str_replace(',', '.', $m[2]), 'meta' => $m[3] ?? null, 'retirar' => $retirar ?: null])];
}

// "tenho consulta dia 28 às 19 horas" = compromisso, nunca gasto de 28 (visto nos testes: a IA anotou "consulta 28,00 €").
// Só age quando o único número do "gasto" é o dia ou a hora da frase.
function corrigir_compromisso($intent, $dados, $texto) {
    if ($intent !== 'log_expense' || !empty($dados['gastos'])) return [$intent, $dados];
    if (!preg_match('/^\s*(?:eu\s+)?(?:tenho|terei|vou\s+ter|marquei|agendei)\s+(?:uma?\s+)?(.+?)\s+(?:no\s+|para\s+o\s+|pro\s+)?dia\s+(\d{1,2})(?:\/\d{1,2})?\b(.*)$/iu', $texto, $m)) return [$intent, $dados];
    $hora = hora_da_frase($m[3]) ?? '09:00';
    // Números da frase que não são o dia/mês nem a hora = valor de verdade -> deixa como gasto
    $resto = preg_replace(['/\bdia\s+\d{1,2}(?:\/\d{1,2})?/u', '/\b\d{1,2}\s*(?:h(?:oras?)?\s*(?:\d{2})?\b|:\d{2})/u'], '', $texto);
    if (hora_da_frase($m[3])) $resto = preg_replace('/(?:^|\s)(?:[àa]s|pelas)\s+\d{1,2}\b/iu', '', $resto); // "as 10" é hora, não valor
    if (preg_match('/\d/', $resto)) return [$intent, $dados];
    $dia = data_da_frase($texto);
    if (!$dia) return [$intent, $dados];
    return ['set_reminder', ['intent' => 'set_reminder', 'texto_lembrete' => trim($m[1]), 'disparar_em' => "$dia $hora"]];
}

// "tenho 700 no cartão de crédito" = saldo (dinheiro que JÁ tem), nunca gasto — mesmo se a IA disser log_expense.
// (A regra "coisa + número = gasto" do prompt às vezes contamina esse caso.) Devolve [intent, dados].
function corrigir_saldo_inicial($intent, $dados, $texto) {
    if ($intent !== 'log_expense' || !empty($dados['gastos'])) return [$intent, $dados];
    if (!preg_match('/^\s*(eu\s+)?(j[aá]\s+)?(tenho|tinha)\s+(r\$|€|\$)?\s*\d/iu', $texto)) return [$intent, $dados];
    $valor = $dados['valor'] ?? null;
    if ($valor === null && preg_match('/(\d+(?:[.,]\d+)?)/', $texto, $m)) $valor = (float) str_replace(',', '.', $m[1]);
    $conta = $dados['conta'] ?? null;
    if (!$conta && preg_match('/\b(?:no|na|nos|nas|em)\s+(.+?)[.!]?$/iu', $texto, $m)) $conta = trim($m[1]);
    return ['saldo_inicial', array_filter(['intent' => 'saldo_inicial', 'valor' => $valor, 'moeda' => $dados['moeda'] ?? null, 'conta' => $conta], fn($v) => $v !== null)];
}

// Depois da IA: corrige em PHP as datas que ela costuma errar (tempo relativo e dia da semana)
function corrigir_datas($intent, $dados, $texto) {
    if ($intent === 'set_reminder' || $intent === 'editar_lembrete' || $intent === 'cadastrar_conta_fixa') {
        $campo = $intent === 'editar_lembrete' ? 'disparar_em_novo' : 'disparar_em';

        if ($intent === 'set_reminder') {
            // "preciso pagar o cartão dia 5": a IA assume "todo mês" sem a pessoa ter dito. Sem palavra de repetição na frase,
            // fica só pra aquela data — e, se for conta, o handler pergunta "é todo mês?" com botões.
            if (($dados['recorrente'] ?? null) === 'mensal'
                && !preg_match('/(?<![\p{L}])(tod[oa]s?|cada|mensal|mensalmente|sempre|fix[oa]s?|mensais|vence|vencem|vencimento)(?![\p{L}])|(?:por|ao)\s+m[eê]s/iu', $texto)) {
                if (!empty($dados['dia_mes'])) {
                    $hora = !empty($dados[$campo]) && strlen($dados[$campo]) >= 16 ? substr($dados[$campo], 11, 5) : '09:00';
                    $dados[$campo] = substr(primeira_ocorrencia_mensal(max(1, min(31, (int) $dados['dia_mes'])), "$hora:00"), 0, 16);
                }
                unset($dados['recorrente'], $dados['dia_mes']);
                $dados['perguntar_mensal'] = true;
            }
            // Aviso antes ("30 minutos antes"): o horário salvo é o do compromisso — a IA às vezes já subtrai, então a hora vem da frase
            $ant = detectar_antecedencia($texto);
            $sem_ant = $ant !== null ? sem_trecho_antecedencia($texto) : $texto;
            $hora_frase = hora_da_frase($sem_ant);
            $dados['tem_hora'] = $hora_frase !== null || detectar_tempo_relativo($texto) !== null
                || ($ant === null && !empty($dados[$campo]) && strlen($dados[$campo]) >= 16 && substr($dados[$campo], 11, 5) !== '09:00');
            // A IA às vezes ignora "as 10" (sem "h") e põe o padrão 09:00: aí a hora da frase manda.
            // (Se ela pôs outra hora, confia nela — "meio-dia e meia", "8 e 15" ela entende melhor que a regex.)
            if ($hora_frase && !empty($dados[$campo]) && strlen($dados[$campo]) >= 10 && !detectar_tempo_relativo($texto)
                && (strlen($dados[$campo]) < 16 || substr($dados[$campo], 11, 5) === '09:00')) {
                $dados[$campo] = substr($dados[$campo], 0, 10) . ' ' . $hora_frase;
            }
            if ($ant !== null) {
                $dados['antecedencia_min'] = $ant;
                if (!empty($dados[$campo]) && strlen($dados[$campo]) >= 16) {
                    $dados[$campo] = substr($dados[$campo], 0, 10) . ' ' . ($hora_frase ?? '09:00');
                }
            }
            // "me lembra de X hoje" sem hora: o handler pergunta a hora (em vez de empurrar pra amanhã às 9h)
            $dados['hoje_sem_hora'] = !$dados['tem_hora'] && empty($dados['recorrente']) && (bool) preg_match('/(?<![\p{L}])hoje(?![\p{L}])/iu', $texto);
            $dados['compromisso_frase'] = (bool) preg_match('/^\s*(?:eu\s+)?(?:tenho|terei|vou\s+ter|marquei|agendei)\b/iu', $texto);
        }

        if ($tempo = detectar_tempo_relativo($texto)) {
            $dados[$campo] = $tempo;
            return $dados;
        }
        if (!empty($dados['dia_mes'])) {
            return $dados; // mensal por dia do mês — a data é calculada no handler
        }
        // "dentista dia 27" dito no dia 30 = 27 do mês que vem — avisa a pessoa (a IA às vezes já manda o mês certo, sem aviso)
        if ($intent === 'set_reminder' && empty($dados['recorrente']) && preg_match('/\bdia\s+(\d{1,2})\b(?!\s*\/)/u', $texto, $m)
            && (int) $m[1] < (int) date('j') && !preg_match('/janeiro|fevereiro|mar[cç]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro/iu', $texto)) {
            $dia = data_da_frase($texto);
            $hora = !empty($dados[$campo]) && strlen($dados[$campo]) >= 16 ? substr($dados[$campo], 11, 5) : '09:00';
            $dados[$campo] = "$dia $hora";
            $dados['dia_passou'] = (int) $m[1];
        }

        $dia = detectar_dia_semana($texto, 'futuro');
        if ($dia) {
            $disse_hora = preg_match('/\b\d{1,2}\s*(h\b|:\d{2}|horas?\b)|meio[\s-]dia|meia[\s-]noite/ui', $texto);
            if ($intent === 'editar_lembrete' && (empty($dados[$campo]) || !$disse_hora)) {
                unset($dados[$campo]); // a IA inventa 09:00 quando só o dia mudou
                $dados['nova_data_dia'] = $dia; // só o dia mudou — o handler mantém a hora que o lembrete já tinha
                return $dados;
            }
            $hora = !empty($dados[$campo]) && strlen($dados[$campo]) >= 16 ? substr($dados[$campo], 11, 5) : '09:00';
            $quando = "$dia $hora";
            if (strtotime($quando) <= time()) {
                $quando = date('Y-m-d', strtotime('+7 days', strtotime($dia))) . " $hora"; // "sexta" dito na sexta depois da hora = próxima sexta
            }
            $dados[$campo] = $quando;
        }
        return $dados;
    }

    if ($intent === 'listar_lembretes') {
        $dia = detectar_dia_semana($texto, 'futuro');
        if ($dia && (empty($dados['periodo']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dados['periodo']))) {
            $dados['periodo'] = $dia;
        }
        return $dados;
    }

    // Gasto/receita: dia da semana se refere ao passado ("paguei sexta" = a última sexta)
    $campo = match ($intent) {
        'log_expense', 'log_income', 'marcar_conta_paga' => 'data',
        'editar_transacao'          => 'data_nova',
        default                     => null,
    };
    if ($campo && ($intent !== 'editar_transacao' || !empty($dados[$campo]))) {
        $dia = detectar_dia_semana($texto, 'passado');
        if ($dia) {
            $dados[$campo] = $dia;
        }
    }
    return $dados;
}

const CAMPOS_CONHECIDOS = [
    'intent', 'valor', 'moeda', 'categoria', 'estabelecimento', 'descricao', 'data', 'periodo', 'conta', 'contas', 'termo', 'tipo', 'ultimo', 'campo',
    'texto_lembrete', 'disparar_em', 'recorrente', 'intervalo_horas', 'dia_mes',
    'valor_antigo', 'valor_novo', 'descricao_nova', 'categoria_nova', 'data_nova', 'conta_nova',
    'conta_origem', 'conta_destino', 'conta_antiga', 'categoria_antiga',
    'texto_novo', 'disparar_em_novo', 'recorrente_novo',
    'modo', 'ml', 'litros', 'copos', 'meta_ml', 'copo_ml',
    'meta', 'prazo', 'prazo_novo', 'nome_novo', 'retirar', 'frequencia', 'antecedencia_min', 'lista', 'itens', 'so_feitos',
    'gastos',
];

// A IA às vezes "escorrega" ao reescrever o que a pessoa disse ("ginásio" -> "ginásico").
// Palavra do texto dela que não aparece na mensagem, mas tem uma quase igual lá, volta a ser a do usuário.
function corrigir_palavras_pela_mensagem($dados, $mensagem) {
    preg_match_all('/[\p{L}]{4,}/u', mb_strtolower($mensagem), $m);
    $originais = array_unique($m[0]);
    if (!$originais) return $dados;

    foreach (['texto_lembrete', 'texto_novo', 'descricao', 'descricao_nova', 'estabelecimento'] as $campo) {
        if (empty($dados[$campo]) || !is_string($dados[$campo])) continue;
        $dados[$campo] = preg_replace_callback('/[\p{L}]{4,}/u', function ($p) use ($originais) {
            $palavra = mb_strtolower($p[0]);
            if (in_array($palavra, $originais, true)) return $p[0];
            // palavras que o próprio bot põe no texto ("pagar o ginásio") não se corrigem
            if (mb_strlen($palavra) < 5 || in_array($palavra, ['pagar', 'pague', 'lembrar', 'conta', 'contas'], true)) return $p[0];
            foreach ($originais as $original) {
                if (abs(strlen($original) - strlen($palavra)) <= 2 && levenshtein($original, $palavra) <= 2) {
                    return $original;
                }
            }
            return $p[0]; // palavra que a IA acrescentou de propósito ("pagar", artigo...) — mantém
        }, $dados[$campo]);
    }
    return $dados;
}

// O modelo às vezes devolve chaves com lixo grudado ("intent μεγά", "intent_dual", "intentTracing").
// Chave desconhecida vira o campo conhecido mais longo que ela começa (ex: "intentTracing" -> "intent").
function limpar_chaves_json($dados) {
    $limpo = [];
    foreach ($dados as $chave => $valor) {
        if (!is_string($chave)) continue;
        $chave = strtolower(trim($chave));
        if (!in_array($chave, CAMPOS_CONHECIDOS, true)) {
            $melhor = '';
            foreach (CAMPOS_CONHECIDOS as $conhecido) {
                if (str_starts_with($chave, $conhecido) && strlen($conhecido) > strlen($melhor)) {
                    $melhor = $conhecido;
                }
            }
            $chave = $melhor;
        }
        if ($chave === '' || isset($limpo[$chave])) continue;
        $limpo[$chave] = $valor;
    }
    return $limpo;
}

// O modelo às vezes devolve lixo, ou "unknown" pra uma frase que na tentativa seguinte ele entende
// (visto nos testes: 1 em ~30). Tenta de novo uma vez — frase incompreensível de verdade dá "unknown" de novo.
function classificar_intencao($texto, $ultima_conta = null) {
    $resultado = classificar_intencao_uma_vez($texto, $ultima_conta);
    if (($resultado['intent'] ?? 'unknown') === 'unknown') {
        $resultado = classificar_intencao_uma_vez($texto, $ultima_conta);
    }
    unset($resultado['_falhou']);
    return $resultado;
}

function classificar_intencao_uma_vez($texto, $ultima_conta = null) {
    $config = require __DIR__ . '/config.php';

    $hoje = date('Y-m-d');
    $agora = date('H:i');
    $dia_semana = ['Sunday'=>'domingo','Monday'=>'segunda','Tuesday'=>'terça','Wednesday'=>'quarta','Thursday'=>'quinta','Friday'=>'sexta','Saturday'=>'sábado'][date('l')];
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $amanha = date('Y-m-d', strtotime('+1 day'));
    $dia20 = date('Y-m-20'); // só pros exemplos
    $fim_dezembro = date('Y') . '-12-31';
    $fim_marco = (date('n') > 3 ? date('Y') + 1 : date('Y')) . '-03-31';
    $dia14 = strtotime(date('Y-m-14')) > time() ? date('Y-m-14', strtotime('first day of last month')) : date('Y-m-14'); // "dia 14" = o último que passou

    $dica_conta = $ultima_conta
        ? "\nSe o usuário se referir a uma conta de forma indireta (\"lá\", \"lá também\", \"nessa conta\", \"na mesma\"), sem nomear, a última conta mencionada na conversa foi \"{$ultima_conta}\" — use esse nome no campo conta."
        : '';

    $system_prompt = <<<PROMPT
Você é um classificador de intenção para um assistente financeiro pessoal em português (Portugal e Brasil).
Hoje é {$hoje} ({$dia_semana}), agora são {$agora}. Use isso pra resolver datas e horas relativas ou parciais que o usuário mencionar.{$dica_conta}
Responda APENAS com um JSON válido, sem nenhum texto antes ou depois, neste formato:

{"intent": "...", "valor": ..., "moeda": "...", "categoria": "...", "estabelecimento": "...", "descricao": "...", "data": "...", "periodo": "...", "texto_lembrete": "..."}

Intenções possíveis:
- log_expense: usuário registrou um gasto. Pode vir junto com uma saudação ou frase solta (ex: "Boa! Gastei 15 no mercado") — ignore a parte social e extraia só o gasto.
  - valor: número do gasto, se o usuário disser. Se ele NÃO disser o valor (ex: "gastei no mercado hoje"), OMITA este campo — não invente.
  - moeda: código de 3 letras da moeda mencionada — EUR (€, euro, euros), BRL (R$, real, reais), USD ($, dólar, dolares, dollar, USD). Se não mencionar nenhuma moeda, OMITA este campo (não invente).
  - estabelecimento: nome do lugar/loja mencionado, se houver (ex: "Pingo Doce", "Zara", "Uber"), mesmo que você não saiba a categoria dele.
  - VÁRIOS GASTOS na mesma mensagem (ex: "gastei 507 com o carro, 450 aluguel e 30 na farmácia", ou uma lista em várias linhas): preencha "gastos" como uma lista de objetos {"valor":..,"descricao":..,"categoria":..,"estabelecimento":..,"moeda":..} — um por gasto, NUNCA ignore nenhum — em vez de valor/descricao soltos.
  - FRASE CURTA "coisa + número" (ex: "academia 84", "mercado 240", "cartão de crédito 700", "luz 58") é SEMPRE um gasto (log_expense): o número é o valor e a coisa é a descrição. Só é saldo_inicial se a pessoa disser que TEM/TINHA o dinheiro ("tenho 700 no cartão", "a wise tinha 200").
  - categoria: SÓ preencha se você tiver certeza real da categoria (mercado, transporte, lazer, contas, saude, presente, roupa, restaurante, outros). Guia: academia/ginásio/exercício/pilates/personal/farmácia/médico/dentista/consulta = saude; carro/combustível/gasolina/estacionamento/portagem/pedágio/oficina/uber/ônibus = transporte; aluguel/arrendamento/renda/condomínio/luz/água/gás/internet/telemóvel/celular/fatura/cartão de crédito/seguro = contas; supermercado/feira/padaria = mercado.
    Se o estabelecimento for uma marca muito conhecida e óbvia (ex: supermercados como Pingo Doce, Continente, Lidl, Mercadona, Carrefour, Extra, Pão de Açúcar = mercado; lojas de roupa como Zara, H&M, Primark, C&A, Renner = roupa; Uber, Bolt, 99 = transporte; Netflix, Spotify = lazer), preencha a categoria.
    Se não tiver certeza do tipo de lugar, DEIXE categoria de fora do JSON (não invente). Atenção: em Portugal "o chinês"/"loja do chinês" é loja de variedades (NÃO restaurante) — sem certeza, omita a categoria.
  - descricao: breve, ex: "mercado" ou o nome do item comprado.
  - conta: nome da conta/cartão usado pra pagar, SE o usuário mencionar explicitamente (ex: "com o cartão revolut", "paguei com a wise"). Se não mencionar, OMITA.
  - data: SÓ preencha (formato YYYY-MM-DD) se o usuário mencionar uma data específica ou relativa diferente de hoje (ex: "dia 14/09", "ontem", "terça passada"). Se não mencionar nada, OMITA este campo — o sistema já assume hoje sozinho.
- log_income: usuário registrou uma receita — dinheiro que ACABOU DE CHEGAR agora (ex: "recebi", "ganhei", "me pagaram", "me deram"). Se a frase for do tipo "tenho X" ou "tinha X" (dinheiro que já existia), use saldo_inicial em vez disso, NUNCA log_income.
  - valor: número da receita. Se o usuário NÃO disser o valor (ex: "recebi meu ordenado hoje"), OMITA este campo — não invente.
  - moeda: mesma regra do log_expense. Se não mencionar, OMITA.
  - conta: nome do banco/conta/carteira mencionado, se houver (ex: "Millennium", "Wise", "Revolut", "carteira"). Se não mencionar, OMITA.
  - descricao: breve (ex: "ordenado", "trabalho extra").
  - data: mesma regra do log_expense (só preenche se for diferente de hoje).
- query_spending: usuário quer saber o TOTAL que gastou (soma). Preencha periodo (hoje, semana, mes, mes_passado, ou um mês específico nomeado como "YYYY-MM" — ex: "agosto" com hoje em 2026 vira "2026-08", ajustando o ano se o mês nomeado já passou esse ano). Se ele mencionar uma categoria específica (ex: "gastei de mercado", "só com transporte"), preencha categoria também.
- list_expenses: usuário quer ver a LISTA/detalhe dos gastos, não só o total (ex: "lista meus gastos", "quais foram", "mostra as datas"). Preencha periodo (mesma regra acima; padrão mes). Se mencionar uma categoria específica, preencha categoria também.
- list_income: usuário quer ver a LISTA/detalhe das receitas (ex: "lista minhas receitas", "o que eu recebi"). Preencha periodo (mesma regra acima; padrão mes).
- buscar_transacao: usuário quer saber detalhes/data de UMA transação específica que já fez, mencionando do que se trata (ex: "quando eu recebi meu ordenado", "quando comprei aquela tela"). Preencha termo (palavra-chave da descrição, ex: "ordenado").
- renomear_categoria: usuário quer corrigir/renomear uma CATEGORIA INTEIRA (todos os gastos dela), sem apontar um gasto específico (ex: "altera a categoria fogo pra tabacaria", "o correto é tabacaria, não fogo", "corrige a categoria X"). Preencha categoria_antiga e categoria_nova (se ele não disser a nova, omita categoria_nova). O nome da categoria pode ser qualquer palavra, mesmo comum (ex: "altera a categoria perfeito" -> categoria_antiga "perfeito"). Se ele apontar UM gasto específico (pelo valor, por "esse gasto", ou pelo item), use editar_transacao com categoria_nova.
- saldo: usuário quer saber quanto tem de saldo/dinheiro no total (receitas menos gastos, ex: "quanto eu tenho", "qual meu saldo").
- saldo_por_conta: usuário quer ver o saldo separado por conta/banco (ex: "quanto tenho em cada conta", "saldo por banco", "lista bancos", "minhas contas", "quais contas tenho", "detalha bancos").
- deletar_transacao: usuário quer APAGAR/EXCLUIR um gasto ou receita já registrado (ex: "apaga o gasto de 30 do restaurante", "remove aquela receita", "exclui o último gasto"). Preencha valor e/ou termo (palavra-chave da descrição). Se ele disser a categoria ATUAL do gasto pra identificar (ex: "o de 8 euros sem categoria", "o gasto de mercado de 20"), preencha categoria (use "sem categoria" quando ele disser isso). Preencha tipo ("gasto" ou "receita") se ele disser qual. Se ele falar do "último"/"esse" sem outra pista, preencha "ultimo": true.
- editar_transacao: usuário quer CORRIGIR uma transação específica já registrada (gasto ou receita) — valor, nome/descrição, categoria, data e/ou conta (ex: "muda os 15 do pingo doce pra 12", "corrige, foi 20 não 30", "o nome correto do gasto da bike é óleo do carro", "esse gasto de 5,50 é tabacaria", "o gasto do cigarro foi ontem", "a receita de 1200 caiu na wise"). Preencha valor_antigo (se mencionado) e/ou termo (palavra-chave pra achar — o item ou o lugar), categoria (a categoria ATUAL, só se ele usar ela pra identificar o gasto), tipo ("gasto"/"receita") se ele disser, "ultimo": true se ele falar do último/desse/dele sem outra pista, e o que precisa mudar: valor_novo, descricao_nova, categoria_nova, data_nova (YYYY-MM-DD, resolvendo "ontem", "dia 20" etc. com base em hoje) e/ou conta_nova. Se ele disser O QUE quer mudar mas não o valor novo (ex: "altera a categoria desse gasto", "muda a data do gasto de 12"), preencha campo com "categoria", "data", "valor", "descricao" ou "conta" — NÃO invente o valor novo.
- listar_categorias: usuário quer ver as categorias que tem (ex: "lista minhas categorias", "quais categorias eu tenho", "mostra as categorias"). Preencha periodo só se ele mencionar um (mesma regra do query_spending).
- apagar_categoria: usuário quer apagar/excluir/remover uma CATEGORIA (não um gasto) — ex: "apaga a categoria fogo", "exclui a categoria teste". Preencha categoria.
- transferencia: usuário moveu dinheiro de uma conta pra outra (ex: "transferi 200 da revolut pro millennium", "passei 50 da wise pro banco"). Preencha valor, moeda, conta_origem e conta_destino.
- saldo_inicial: usuário quer declarar quanto uma conta JÁ TINHA antes de começar a usar o bot (não é dinheiro que acabou de entrar — é o que já existia, ex: "a wise já tinha 200 euros antes de eu começar a usar o bot", "eu já tinha 500 no millennium"). Preencha valor, moeda e conta. Se ele mencionar MAIS DE UMA conta na mesma mensagem (ex: "tenho 45 no wise e 30 no revolut"), preencha "contas" como uma lista de objetos {"valor":..,"moeda":..,"conta":..} em vez de valor/moeda/conta soltos — NUNCA ignore uma conta mencionada, mesmo que venha "escondida" depois de um "e". Se a frase indicar que o dinheiro acabou de chegar agora, use log_income em vez disso.
- definir_conta_gasto: usuário está dizendo de qual conta/cartão saiu um gasto que JÁ foi registrado antes (não é uma compra nova) — frases indicando correção/complemento de algo já dito, tipo "os 150 do pingo doce saiu do millenium", "aquele gasto de ontem foi no cartão revolut", "paguei a farmácia com a wise". Preencha valor (se mencionado) ou termo (palavra-chave da descrição/estabelecimento) e conta.
- apagar_conta: usuário quer remover/apagar uma CONTA/banco (ex: "remove a conta cartão de crédito", "apaga a conta nubank", "essa conta não existe, apaga"). Preencha conta.
- renomear_conta: usuário quer corrigir/juntar o nome de uma CONTA que ficou errada (não confundir com renomear_categoria) — ex: "renomeia a conta Ya pra Wise", "a conta Ya é a mesma coisa que Wise", "junta Ya com Wise". Preencha conta_antiga e conta_nova.
- set_reminder: usuário quer ser lembrado de algo. Preencha texto_lembrete e disparar_em (formato "YYYY-MM-DD HH:MM", 24h), resolvendo a expressão de tempo com base em hoje/agora acima — funciona pra relativo ("daqui 10 minutos", "em 2 horas") e absoluto ("amanhã às 9h", "sexta às 18h", "dia 30"). Se ele só disser o dia sem horário, assuma 09:00 desse dia. Se não disser NENHUMA referência de tempo, OMITA disparar_em (não invente). Se pedir aviso antes ("30 minutos antes", "1 dia antes"), preencha antecedencia_min (em minutos) e deixe disparar_em no horário do compromisso — NÃO subtraia.
  - recorrente: preencha "diario", "semanal", "mensal" ou "horas" SE o usuário indicar repetição (ex: "todo dia", "toda semana", "todo mês", "de hora em hora", "a cada 2 horas", "sempre às sextas"). Se for "horas", preencha também intervalo_horas (número, ex: 1 pra "de hora em hora", 3 pra "a cada 3 horas"). Se for um lembrete único (não repete), OMITA esses campos.
  - Se for uma CONTA A PAGAR e ele disser o valor, a conta/banco de onde sai ou a categoria, preencha valor, moeda, conta e/ou categoria (ex: "me lembra de pagar a luz todo dia 10, uns 60 euros, sai da wise").
  - dia_mes: se for "mensal" e ele disser o dia do mês (ex: "todo mês dia 31", "todo dia 5"), preencha dia_mes com o número (1 a 31) — mesmo que esse dia não exista em todos os meses. Nesse caso, em disparar_em mande só o horário que ele disse no dia de hoje (ou omita se ele não disse hora); o sistema calcula a data.
- listar_lembretes: usuário quer ver os lembretes/agenda/compromissos (ex: "meus lembretes", "o que eu tenho marcado", "minha agenda da semana", "o que tenho hoje", "agenda do mês", "o que tenho amanhã"). Preencha periodo SE ele disser: "hoje", "amanha", "semana" (esta semana/próximos dias), "proxima_semana", "mes" (este mês), "proximo_mes", um mês "YYYY-MM" ou um dia "YYYY-MM-DD" (ex: "o que tenho sexta" -> a data da próxima sexta). Se ele só pedir "meus lembretes" sem período, OMITA periodo. Se ele PERGUNTAR sobre um lembrete específico (ex: "que horas você vai me lembrar de tomar água?", "quando é o lembrete do seguro?", "você vai me lembrar da reunião?"), preencha termo com a palavra-chave dele.
- editar_lembrete: usuário quer MUDAR um lembrete que já existe — horário, data, texto, repetição, valor ou conta de onde sai (ex: "muda o lembrete da internet pra sexta às 10h", "o lembrete do seguro é todo dia 31", "o lembrete da água não precisa mais repetir", "o lembrete do seguro é 45 euros", "o seguro sai da wise"). Preencha termo (palavra-chave do lembrete) e o que muda: disparar_em_novo ("YYYY-MM-DD HH:MM"), texto_novo, recorrente_novo ("diario", "semanal", "mensal", "horas" ou "nenhum" se não deve mais repetir), intervalo_horas, dia_mes, valor_novo e/ou conta_nova.
- cadastrar_conta_fixa: usuário quer cadastrar um GASTO FIXO / conta que se repete todo mês (arrendamento, aluguel, cartão de crédito, internet, luz, água, ginásio/academia, seguro, assinatura, mensalidade, condomínio, prestação...) — ex: "tenho um gasto fixo de 700 de arrendamento todo dia 1", "cadastra o cartão de crédito, vence dia 10", "pago 30 de internet todo mês", "adiciona a netflix como conta fixa". Preencha texto_lembrete ("pagar o arrendamento", "pagar o cartão de crédito" — sempre começando com "pagar" + artigo), dia_mes (se ele disser o dia), valor/moeda (se ele disser — cartão de crédito normalmente varia, então omita), conta (de onde sai, se disser) e categoria (se disser). Se for semanal ("toda sexta pago a empregada 50"), preencha recorrente "semanal" e disparar_em com o próximo dia. NÃO confunda com log_expense (gasto que JÁ aconteceu uma vez) nem com set_reminder genérico.
- listar_contas_fixas: usuário quer ver a lista dos GASTOS FIXOS / contas fixas / despesas fixas / assinaturas que tem cadastrados, e quanto dá por mês (ex: "meus gastos fixos", "quais são minhas contas fixas", "lista as despesas fixas", "quanto gasto fixo por mês").
- definir_orcamento: usuário quer definir/mudar um ORÇAMENTO (limite de gasto por mês) pra uma categoria ou no total (ex: "quero gastar no máximo 300 em restaurante por mês", "orçamento de 200 pra lazer", "meu orçamento total é 1500 por mês", "limite de 100 em roupa"). Preencha categoria (use "total" se for o orçamento geral do mês, sem categoria específica), valor e moeda (se disser).
- listar_orcamentos: usuário quer ver como está o orçamento / quanto ainda pode gastar (ex: "como tá meu orçamento?", "meus orçamentos", "quanto ainda posso gastar em lazer?", "já estourei o orçamento?"). Preencha categoria só se ele perguntar de uma específica ("total" pro geral).
- remover_orcamento: usuário quer tirar/apagar um orçamento (ex: "tira o orçamento de lazer", "não quero mais limite em roupa"). Preencha categoria ("total" pro geral).
- contas_pendentes: usuário quer ver as CONTAS A PAGAR — o que falta pagar, o que já pagou, as contas do mês (ex: "o que falta pagar?", "quais contas tenho esse mês", "já paguei tudo?", "contas do mês que vem"). Preencha periodo só se ele disser: "mes" (padrão), "proximo_mes", "mes_passado" ou "YYYY-MM".
- marcar_conta_paga: usuário diz que PAGOU uma conta/boleto/fatura recorrente (aluguel, luz, água, internet, seguro, fatura do cartão, condomínio, mensalidade...) — ex: "paguei o seguro", "já paguei a luz, deu 58", "paguei o aluguel pela wise". Preencha termo (palavra-chave da conta, ex: "seguro", "luz", "cartão de crédito"), valor/conta se ele disser, e data (YYYY-MM-DD) se ele disser quando pagou (ex: "dia 14", "ontem"). Compras comuns ("paguei 20 no mercado", "paguei o uber") continuam sendo log_expense.
- cancelar_lembrete: usuário quer apagar/cancelar/excluir um lembrete OU um gasto fixo/conta fixa (ex: "cancela o lembrete de pagar a internet", "apaga o lembrete da água", "remove o gasto fixo da netflix", "não pago mais o ginásio"). Preencha termo (palavra-chave do texto do lembrete).
- greeting: o usuário só cumprimentou ou iniciou a conversa (oi, olá, bom dia, /start).
- registrar_agua: usuário diz que BEBEU água (ex: "bebi 2 copos de água", "bebi 500ml", "tomei 1,5 litro de água", "bebi água", "já bebi"). Preencha ml (mililitros), litros ou copos — o que ele disser; se não disser quantidade, omita todos.
- relatorio_agua: usuário quer saber quanto de água bebeu (ex: "quanto de água bebi hoje?", "relatório de água da semana", "como tá minha água?", "bati a meta de água?"). Preencha periodo: "hoje" (padrão), "semana" ou "mes".
- definir_meta_agua: usuário define a meta diária de água ou o tamanho do copo (ex: "minha meta de água é 2 litros", "quero beber 2500ml por dia", "meu copo é de 300ml"). Preencha meta_ml (ou litros) e/ou copo_ml.
- apagar_agua: usuário quer desfazer/apagar o último registro de água (ex: "apaga o último registro de água", "errei, não bebi aquele copo").
- criar_meta: usuário quer criar uma META DE POUPANÇA / juntar dinheiro pra algo (ex: "quero juntar 1000 euros até dezembro pra viagem", "meta de 5000 pra reserva de emergência", "quero economizar 300 pro presente da minha mãe até o natal"). Preencha meta (nome curto: "viagem", "reserva de emergência", "presente da mãe"), valor, moeda (se disser) e prazo (YYYY-MM-DD; "até dezembro" = último dia de dezembro, "até o natal" = 25/12, "em 6 meses" = hoje + 6 meses; se não disser prazo, omita).
- aporte_meta: usuário GUARDOU/separou dinheiro numa meta, ou TIROU dinheiro dela (ex: "guardei 100 pra viagem", "coloquei 50 na meta", "separei 200 pra reserva", "tirei 30 da viagem"). Preencha valor, meta (nome, se disser) e "retirar": true se ele tirou dinheiro. NÃO é gasto (log_expense) nem receita.
- listar_metas: usuário quer ver as metas / como está uma meta (ex: "minhas metas", "como tá a meta da viagem?", "quanto falta pra viagem?", "quanto guardar por semana pra viagem?"). Preencha meta se perguntar de uma específica e frequencia ("dia", "semana" ou "mes") se perguntar quanto guardar por período.
- editar_meta: usuário quer mudar uma meta (ex: "a meta da viagem agora é 1500", "o prazo da viagem passa pra março", "muda o nome da meta viagem pra férias"). Preencha meta e o que muda: valor_novo, prazo_novo (YYYY-MM-DD) e/ou nome_novo.
- cancelar_meta: usuário quer cancelar/apagar/desistir de uma meta (ex: "cancela a meta da viagem", "desisti da meta do carro"). Preencha meta.
- plano: usuário pergunta sobre o plano/assinatura/preço do Zágora ou quer assinar (ex: "quero assinar", "meu plano", "quanto custa o plus?", "quanto tempo falta do teste?", "como cancelo a assinatura?").
- resumo: usuário quer um RESUMO/balanço geral do período — não só um total (ex: "resumo da semana", "me dá um resumo", "como foi meu mês?", "como foi o mês passado?", "balanço do mês", "como estão minhas finanças?"). Preencha periodo: "semana" (padrão, últimos 7 dias), "mes" (mês atual até hoje) ou "mes_passado". Pergunta só de valor ("quanto gastei essa semana?") continua sendo query_spending.
- ajuda: o usuário quer saber o que o bot faz / como usar (ex: "ajuda", "o que você faz?", "como funciona?", "quais comandos?", "me ensina a usar").
- definir_modo_contas: o usuário quer mudar COMO usa o bot — controlar por conta/banco ou só anotar gastos sem conta (ex: "quero controlar por conta", "quero usar contas", "não quero mais usar contas", "não uso contas, só quero anotar gastos", "para de perguntar a conta"). Preencha modo: "contas" ou "simples".
- confirmacao: o usuário só está reagindo/agradecendo/confirmando de forma casual, sem pedir nada — formal ou informal (ex: "boa!", "beleza", "show", "valeu", "obrigado", "obrigada", "vlw", "blz", "top", "joia", "fixe", "massa", "tamo junto", "é nois", "fechou", "gratidão", "thanks", "de boa"). NÃO é início de conversa nem saudação.
- lista_adicionar: pôr itens numa lista (compras, tarefas, filmes...) (ex: "adiciona leite e pão na lista", "preciso comprar ovos", "lista de tarefas: lavar o carro"). Preencha itens (array de textos curtos) e lista (nome, só se disser qual).
- lista_ver: ver uma lista ou as listas (ex: "o que tem na lista?", "mostra a lista de compras", "minhas listas"). Preencha lista se disser qual.
- lista_marcar: riscar item já feito/comprado (ex: "risca o leite", "já comprei o pão e os ovos", "já liguei pro contador"). Preencha itens e lista (se disser).
- lista_remover: tirar item da lista sem ter feito (ex: "tira o café da lista"). Preencha itens e lista (se disser).
- lista_limpar: esvaziar/apagar lista (ex: "limpa a lista de compras", "apaga a lista de filmes", "limpa os riscados"). Preencha lista e so_feitos=true se for só os riscados/comprados/feitos.
- unknown: não deu pra entender a intenção, ou é uma frase solta/palavra única sem contexto claro de ação nova (nesse caso pode ser resposta a uma pergunta anterior do sistema).

Omita os campos que não se aplicam.

Exemplos:
"gastei 14,50 no mercado" -> {"intent":"log_expense","valor":14.50,"categoria":"mercado","descricao":"mercado"}
"Boa! Gastei 15 euros no pingo doce." -> {"intent":"log_expense","valor":15,"moeda":"EUR","categoria":"mercado","estabelecimento":"pingo doce","descricao":"pingo doce"}
"gastei 150 euros no continente dia 14/09" -> {"intent":"log_expense","valor":150,"moeda":"EUR","categoria":"mercado","estabelecimento":"continente","descricao":"continente","data":"{$hoje}"}
"gastei 20 no pingo doce" -> {"intent":"log_expense","valor":20,"categoria":"mercado","estabelecimento":"pingo doce","descricao":"pingo doce"}
"gastei 50 reais no mercado" -> {"intent":"log_expense","valor":50,"moeda":"BRL","categoria":"mercado","descricao":"mercado"}
"paguei 30 dolares de uber" -> {"intent":"log_expense","valor":30,"moeda":"USD","categoria":"transporte","estabelecimento":"uber","descricao":"uber"}
"comprei uma camisa de 10 na zara" -> {"intent":"log_expense","valor":10,"categoria":"roupa","estabelecimento":"zara","descricao":"camisa"}
"gastei 8 no cafezinho da esquina" -> {"intent":"log_expense","valor":8,"estabelecimento":"cafezinho da esquina","descricao":"cafezinho da esquina"}
"gastei no mercado hoje" -> {"intent":"log_expense","categoria":"mercado","descricao":"mercado"}
"comprei cigarro" -> {"intent":"log_expense","descricao":"cigarro"}
"academia 84" -> {"intent":"log_expense","valor":84,"categoria":"saude","descricao":"academia"}
"cartão de crédito 700" -> {"intent":"log_expense","valor":700,"categoria":"contas","descricao":"cartão de crédito"}
"exercício 87€" -> {"intent":"log_expense","valor":87,"moeda":"EUR","categoria":"saude","descricao":"exercício"}
"gastei 507 com o carro, 450 aluguel e 30 na farmácia" -> {"intent":"log_expense","gastos":[{"valor":507,"descricao":"carro","categoria":"transporte"},{"valor":450,"descricao":"aluguel","categoria":"contas"},{"valor":30,"descricao":"farmácia","categoria":"saude"}]}
"remove a conta cartão de crédito" -> {"intent":"apagar_conta","conta":"cartão de crédito"}
"quanto gastei esse mes" -> {"intent":"query_spending","periodo":"mes"}
"lista meus gastos" -> {"intent":"list_expenses","periodo":"mes"}
"lista minhas receitas" -> {"intent":"list_income","periodo":"mes"}
"quanto eu tenho de dinheiro" -> {"intent":"saldo"}
"quanto tenho em cada conta" -> {"intent":"saldo_por_conta"}
"transferi os 200 euros da revolut pro millenium" -> {"intent":"transferencia","valor":200,"moeda":"EUR","conta_origem":"revolut","conta_destino":"millenium"}
"quanto gastei de mercado esse mes" -> {"intent":"query_spending","periodo":"mes","categoria":"mercado"}
"quanto gastei de mercado mes passado" -> {"intent":"query_spending","periodo":"mes_passado","categoria":"mercado"}
"lista meus gastos de agosto" -> {"intent":"list_expenses","periodo":"2026-08"}
"quando eu recebi meu ordenado" -> {"intent":"buscar_transacao","termo":"ordenado"}
"altera a categoria fogo pra tabacaria" -> {"intent":"renomear_categoria","categoria_antiga":"fogo","categoria_nova":"tabacaria"}
"altera categoria nao" -> {"intent":"renomear_categoria","categoria_antiga":"nao"}
"altera categoria" -> {"intent":"renomear_categoria"}
"corrige uma categoria" -> {"intent":"renomear_categoria"}
"a wise já tinha 200 euros antes de eu começar a usar o bot" -> {"intent":"saldo_inicial","valor":200,"moeda":"EUR","conta":"wise"}
"tenho 45 no wise e 30 no revolut" -> {"intent":"saldo_inicial","contas":[{"valor":45,"moeda":"EUR","conta":"wise"},{"valor":30,"moeda":"EUR","conta":"revolut"}]}
"os 150 do pingo doce saiu do millenium" -> {"intent":"definir_conta_gasto","valor":150,"termo":"pingo doce","conta":"millenium"}
"comprei 20 no pingo doce com o cartao revolut" -> {"intent":"log_expense","valor":20,"categoria":"mercado","estabelecimento":"pingo doce","conta":"revolut","descricao":"pingo doce"}
"lista bancos" -> {"intent":"saldo_por_conta"}
"apaga o gasto de 30 do restaurante" -> {"intent":"deletar_transacao","valor":30,"termo":"restaurante"}
"muda os 15 do pingo doce pra 12" -> {"intent":"editar_transacao","valor_antigo":15,"termo":"pingo doce","valor_novo":12}
"edita gasto bike, nome correto é oleo do carro" -> {"intent":"editar_transacao","termo":"bike","descricao_nova":"oleo do carro"}
"altera esse gasto de 5.50 de carro para tabacaria" -> {"intent":"editar_transacao","valor_antigo":5.50,"termo":"carro","categoria_nova":"tabacaria"}
"o gasto do cigarro é tabacaria" -> {"intent":"editar_transacao","termo":"cigarro","categoria_nova":"tabacaria"}
"altera a categoria perfeito" -> {"intent":"renomear_categoria","categoria_antiga":"perfeito"}
"renomeia a conta Ya pra Wise" -> {"intent":"renomear_conta","conta_antiga":"Ya","conta_nova":"Wise"}
"me lembra sexta de pagar a internet" -> {"intent":"set_reminder","texto_lembrete":"pagar a internet","disparar_em":"2026-09-25 09:00"}
"me lembra de tomar água daqui 5 minutos" -> {"intent":"set_reminder","texto_lembrete":"tomar água","disparar_em":"2026-09-21 14:37"}
"me lembra de pagar a conta" -> {"intent":"set_reminder","texto_lembrete":"pagar a conta"}
"tenho consulta dia 30 às 19h" -> {"intent":"set_reminder","texto_lembrete":"consulta","disparar_em":"2026-09-30 19:00"}
"me lembra da reunião sexta às 10h, 30 minutos antes" -> {"intent":"set_reminder","texto_lembrete":"reunião","disparar_em":"2026-09-25 10:00","antecedencia_min":30}
"todo dia às 8h me lembra de beber água" -> {"intent":"set_reminder","texto_lembrete":"beber água","disparar_em":"2026-09-22 08:00","recorrente":"diario"}
"todo mes dia 5 me lembra de pagar o aluguel" -> {"intent":"set_reminder","texto_lembrete":"pagar o aluguel","disparar_em":"2026-10-05 09:00","recorrente":"mensal"}
"me lembra de beber água de hora em hora" -> {"intent":"set_reminder","texto_lembrete":"beber água","disparar_em":"2026-09-21 15:00","recorrente":"horas","intervalo_horas":1}
"me lembra a cada 3 horas de alongar" -> {"intent":"set_reminder","texto_lembrete":"alongar","disparar_em":"2026-09-21 15:00","recorrente":"horas","intervalo_horas":3}
"me lembra de pagar o seguro do carro todo mês dia 31" -> {"intent":"set_reminder","texto_lembrete":"pagar o seguro do carro","recorrente":"mensal","dia_mes":31}
"todo dia 10 às 14h me lembra da fatura" -> {"intent":"set_reminder","texto_lembrete":"pagar a fatura","recorrente":"mensal","dia_mes":10,"disparar_em":"{$hoje} 14:00"}
"meus lembretes" -> {"intent":"listar_lembretes"}
"me lembra de pagar o aluguel todo dia 5, são 600 euros, sai do millenium" -> {"intent":"set_reminder","texto_lembrete":"pagar o aluguel","recorrente":"mensal","dia_mes":5,"valor":600,"moeda":"EUR","conta":"millenium"}
"o lembrete do seguro é 45 euros" -> {"intent":"editar_lembrete","termo":"seguro","valor_novo":45}
"tenho um gasto fixo de 700 de arrendamento todo dia 1" -> {"intent":"cadastrar_conta_fixa","texto_lembrete":"pagar o arrendamento","dia_mes":1,"valor":700}
"cadastra o cartão de crédito como conta fixa, vence dia 10" -> {"intent":"cadastrar_conta_fixa","texto_lembrete":"pagar o cartão de crédito","dia_mes":10}
"pago 35 de ginásio todo mês pela revolut" -> {"intent":"cadastrar_conta_fixa","texto_lembrete":"pagar o ginásio","valor":35,"conta":"revolut"}
"meus gastos fixos" -> {"intent":"listar_contas_fixas"}
"quanto eu gasto fixo por mês?" -> {"intent":"listar_contas_fixas"}
"não pago mais a netflix" -> {"intent":"cancelar_lembrete","termo":"netflix"}
"o arrendamento agora é 750" -> {"intent":"editar_lembrete","termo":"arrendamento","valor_novo":750}
"quero gastar no máximo 300 em restaurante por mês" -> {"intent":"definir_orcamento","categoria":"restaurante","valor":300}
"meu orçamento total é 1500 por mês" -> {"intent":"definir_orcamento","categoria":"total","valor":1500}
"como tá meu orçamento?" -> {"intent":"listar_orcamentos"}
"quanto ainda posso gastar em lazer?" -> {"intent":"listar_orcamentos","categoria":"lazer"}
"tira o orçamento de lazer" -> {"intent":"remover_orcamento","categoria":"lazer"}
"o que falta pagar?" -> {"intent":"contas_pendentes"}
"contas do mês que vem" -> {"intent":"contas_pendentes","periodo":"proximo_mes"}
"paguei o seguro" -> {"intent":"marcar_conta_paga","termo":"seguro"}
"eu paguei o cartão de crédito dia 14" -> {"intent":"marcar_conta_paga","termo":"cartão de crédito","data":"{$dia14}"}
"já paguei a luz, deu 58 na wise" -> {"intent":"marcar_conta_paga","termo":"luz","valor":58,"conta":"wise"}
"minha agenda da semana" -> {"intent":"listar_lembretes","periodo":"semana"}
"o que tenho pra hoje" -> {"intent":"listar_lembretes","periodo":"hoje"}
"que horas você vai me lembrar de tomar água?" -> {"intent":"listar_lembretes","termo":"água"}
"quando é o lembrete do seguro?" -> {"intent":"listar_lembretes","termo":"seguro"}
"agenda do mês" -> {"intent":"listar_lembretes","periodo":"mes"}
"muda o lembrete da internet pra amanhã às 10h" -> {"intent":"editar_lembrete","termo":"internet","disparar_em_novo":"{$amanha} 10:00"}
"o lembrete do seguro é todo dia 31" -> {"intent":"editar_lembrete","termo":"seguro","recorrente_novo":"mensal","dia_mes":31}
"o lembrete da água não precisa repetir" -> {"intent":"editar_lembrete","termo":"água","recorrente_novo":"nenhum"}
"lista minhas categorias" -> {"intent":"listar_categorias"}
"apaga a categoria fogo" -> {"intent":"apagar_categoria","categoria":"fogo"}
"apaga o último gasto" -> {"intent":"deletar_transacao","tipo":"gasto","ultimo":true}
"exclui a receita de 1200" -> {"intent":"deletar_transacao","tipo":"receita","valor":1200}
"o gasto do cigarro foi ontem" -> {"intent":"editar_transacao","termo":"cigarro","data_nova":"{$ontem}"}
"altera a categoria desse gasto" -> {"intent":"editar_transacao","tipo":"gasto","ultimo":true,"campo":"categoria"}
"altera categoria do gasto de 12 euros" -> {"intent":"editar_transacao","tipo":"gasto","valor_antigo":12,"campo":"categoria"}
"muda a categoria do gasto de 12 euros pra compras variadas" -> {"intent":"editar_transacao","tipo":"gasto","valor_antigo":12,"categoria_nova":"compras variadas"}
"exclui esse gasto de 8 euros sem categoria" -> {"intent":"deletar_transacao","tipo":"gasto","valor":8,"categoria":"sem categoria"}
"comprei umas coisas no chinês" -> {"intent":"log_expense","estabelecimento":"chinês","descricao":"chinês"}
"muda a data do último gasto pra dia 20" -> {"intent":"editar_transacao","tipo":"gasto","ultimo":true,"data_nova":"{$dia20}"}
"a receita do salário caiu na wise" -> {"intent":"editar_transacao","tipo":"receita","termo":"salário","conta_nova":"wise"}
"cancela o lembrete da agua" -> {"intent":"cancelar_lembrete","termo":"agua"}
"recebi 1200 de salario" -> {"intent":"log_income","valor":1200,"descricao":"salario"}
"recebi meu ordenado hoje" -> {"intent":"log_income","descricao":"ordenado"}
"converti 50 euros na wise" -> {"intent":"log_income","valor":50,"moeda":"EUR","descricao":"conversão","conta":"wise"}
"tenho 50 reais na wise" -> {"intent":"saldo_inicial","valor":50,"moeda":"BRL","conta":"wise"}
"tenho lá também 250 reais" (com última conta = wise) -> {"intent":"saldo_inicial","valor":250,"moeda":"BRL","conta":"wise"}
"oi" -> {"intent":"greeting"}
"o que você faz?" -> {"intent":"ajuda"}
"quero juntar 1000 euros até dezembro pra viagem" -> {"intent":"criar_meta","meta":"viagem","valor":1000,"moeda":"EUR","prazo":"{$fim_dezembro}"}
"guardei 100 pra viagem" -> {"intent":"aporte_meta","valor":100,"meta":"viagem"}
"tirei 30 da viagem" -> {"intent":"aporte_meta","valor":30,"meta":"viagem","retirar":true}
"minhas metas" -> {"intent":"listar_metas"}
"preciso comprar detergente e papel higiênico" -> {"intent":"lista_adicionar","itens":["detergente","papel higiênico"]}
"já comprei o arroz" -> {"intent":"lista_marcar","itens":["arroz"]}
"limpa os comprados da lista" -> {"intent":"lista_limpar","so_feitos":true}
"o prazo da viagem passa pra março" -> {"intent":"editar_meta","meta":"viagem","prazo_novo":"{$fim_marco}"}
"quanto custa o plus?" -> {"intent":"plano"}
"resumo da semana" -> {"intent":"resumo","periodo":"semana"}
"como foi meu mês?" -> {"intent":"resumo","periodo":"mes"}
"como foi o mês passado?" -> {"intent":"resumo","periodo":"mes_passado"}
"bebi 2 copos de água" -> {"intent":"registrar_agua","copos":2}
"bebi 500ml" -> {"intent":"registrar_agua","ml":500}
"bebi água" -> {"intent":"registrar_agua"}
"quanto de água bebi hoje?" -> {"intent":"relatorio_agua","periodo":"hoje"}
"relatório de água da semana" -> {"intent":"relatorio_agua","periodo":"semana"}
"minha meta de água é 2,5 litros" -> {"intent":"definir_meta_agua","litros":2.5}
"meu copo é de 300ml" -> {"intent":"definir_meta_agua","copo_ml":300}
"para de perguntar a conta" -> {"intent":"definir_modo_contas","modo":"simples"}
"quero controlar por conta" -> {"intent":"definir_modo_contas","modo":"contas"}
"tenho 800" -> {"intent":"saldo_inicial","valor":800}
"boa!" -> {"intent":"confirmacao"}
"beleza" -> {"intent":"confirmacao"}
"valeu" -> {"intent":"confirmacao"}
"tamo junto" -> {"intent":"confirmacao"}
"é nois" -> {"intent":"confirmacao"}
"obrigado" -> {"intent":"confirmacao"}
"perfeito" -> {"intent":"confirmacao"}
"cigarros" -> {"intent":"unknown"}
PROMPT;

    $payload = [
        'model'                  => $config['cerebras_model'],
        'messages'               => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $texto],
        ],
        'temperature'            => 0.1,
        'max_completion_tokens'  => 500,
        'reasoning_effort'       => 'low', // classificação simples não precisa de raciocínio profundo
        'response_format'        => ['type' => 'json_object'],
    ];

    [$response, $curl_erro, $status_http] = chamar_cerebras($payload, $config);

    // Cerebras recusou (sem crédito, limite, fora do ar): avisa o dono na hora — nesse caso o bot para de entender todo mundo
    if ($curl_erro || $status_http >= 400) {
        $motivo = match (true) {
            $status_http === 402 || (bool) preg_match('/credit|balance|payment|insufficient|quota/i', (string) $response) => "a Cerebras recusou por falta de crédito/cota. Recarregue em cloud.cerebras.ai — até lá o bot não entende as mensagens.",
            $status_http === 429 => "a Cerebras está limitando as chamadas (muitas ao mesmo tempo). Se continuar, veja os limites da conta.",
            $status_http === 401 => "a chave da Cerebras foi recusada (inválida ou revogada).",
            default => "a IA falhou (HTTP {$status_http}" . ($curl_erro ? ", {$curl_erro}" : '') . "). Se continuar, veja o status da Cerebras.",
        };
        alerta_admin('cerebras_' . ($status_http ?: 'rede'), $motivo);
    }

    if ($curl_erro) {
        registrar_debug("Erro de curl chamando a Cerebras: $curl_erro");
        return ['intent' => 'unknown', '_falhou' => true];
    }

    $data = json_decode($response, true);
    registrar_uso_ia($data);
    $conteudo = $data['choices'][0]['message']['content'] ?? null;

    if (!$conteudo) {
        registrar_debug("Resposta da Cerebras sem conteúdo esperado: " . $response);
        return ['intent' => 'unknown', '_falhou' => true];
    }

    // Tira qualquer texto fora do JSON (o modelo às vezes fala antes/depois, mesmo mandando não fazer isso)
    $conteudo = preg_replace('/```json|```/', '', $conteudo);
    if (preg_match('/\{.*\}/s', $conteudo, $match)) {
        $conteudo = $match[0];
    }

    $intencao = json_decode(trim($conteudo), true);

    if (!$intencao) {
        registrar_debug("Não consegui decodificar o JSON da IA. Texto recebido: " . $conteudo);
        return ['intent' => 'unknown', '_falhou' => true];
    }

    $intencao = limpar_chaves_json($intencao);
    if (empty($intencao['intent'])) {
        registrar_debug("JSON da IA sem intent. Texto recebido: " . $conteudo);
        return ['intent' => 'unknown', '_falhou' => true];
    }

    return $intencao;
}

// Grava um log simples num arquivo debug.log na mesma pasta, pra dar pra investigar pelo Gerenciador de Arquivos
function registrar_debug($mensagem) {
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    @file_put_contents(__DIR__ . '/debug.log', $linha, FILE_APPEND);
}

// Chama a Cerebras. Devolve [resposta crua, erro do curl, status HTTP].
// Nos TESTES (constante ZAGORA_CACHE_IA = pasta) a resposta da mesma pergunta, no mesmo dia, é reaproveitada: o prompt
// é ~97% do custo e quase nunca muda entre uma rodada e outra. A chave ignora o "agora são HH:MM" (muda a cada minuto)
// mas inclui a data, o prompt inteiro e a mensagem — mudou qualquer coisa no prompt, pergunta de novo.
function chamar_cerebras($payload, $config) {
    $cache = null;
    if (defined('ZAGORA_CACHE_IA')) {
        $chave = $payload;
        $chave['messages'][0]['content'] = preg_replace('/agora são \d{2}:\d{2}/u', '', $chave['messages'][0]['content']);
        $cache = ZAGORA_CACHE_IA . '/' . sha1(json_encode($chave, JSON_UNESCAPED_UNICODE)) . '.json';
        if (is_file($cache)) {
            $GLOBALS['ia_cache_acertos'] = ($GLOBALS['ia_cache_acertos'] ?? 0) + 1;
            return [file_get_contents($cache), '', 200];
        }
    }

    $ch = curl_init('https://api.cerebras.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['cerebras_api_key'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 20,
    ]);
    $response = curl_exec($ch);
    $curl_erro = curl_error($ch);
    $status_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($cache) {
        $GLOBALS['ia_cache_chamadas'] = ($GLOBALS['ia_cache_chamadas'] ?? 0) + 1;
        $ok = !$curl_erro && $status_http === 200 && !empty(json_decode((string) $response, true)['choices'][0]['message']['content']);
        if ($ok) @file_put_contents($cache, $response);
    }
    return [$response, $curl_erro, $status_http];
}

// Chamada quando o usuário responde só "quando" quer o lembrete (ex: "daqui 10 minutos", "amanhã às 9h")
function resolver_data_hora_livre($texto) {
    $config = require __DIR__ . '/config.php';
    $hoje = date('Y-m-d');
    $agora = date('H:i');
    $dia_semana = ['Sunday'=>'domingo','Monday'=>'segunda','Tuesday'=>'terça','Wednesday'=>'quarta','Thursday'=>'quinta','Friday'=>'sexta','Saturday'=>'sábado'][date('l')];

    $prompt = <<<PROMPT
Hoje é {$hoje} ({$dia_semana}), agora são {$agora}.
O usuário está dizendo QUANDO quer ser lembrado de algo. Responda APENAS com JSON: {"disparar_em": "YYYY-MM-DD HH:MM"}, resolvendo a expressão de tempo (relativa tipo "daqui 10 minutos", ou absoluta tipo "amanhã às 9h"). Se só disser o dia sem horário, assuma 09:00. Se não conseguir entender nenhuma referência de tempo, responda {"disparar_em": null}.
PROMPT;

    $payload = [
        'model'                 => $config['cerebras_model'],
        'messages'              => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $texto],
        ],
        'temperature'           => 0.1,
        'max_completion_tokens' => 100,
        'reasoning_effort'      => 'low',
        'response_format'       => ['type' => 'json_object'],
    ];

    [$response] = chamar_cerebras($payload, $config);

    $data = json_decode($response, true);
    registrar_uso_ia($data);
    $conteudo = $data['choices'][0]['message']['content'] ?? null;
    if (!$conteudo) return null;

    if (preg_match('/\{.*\}/s', $conteudo, $m)) {
        $conteudo = $m[0];
    }
    $resultado = json_decode(trim($conteudo), true);
    return $resultado['disparar_em'] ?? null;
}
