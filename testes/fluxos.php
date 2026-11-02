<?php
// Testes de conversa de ponta a ponta, com a IA de verdade (mais lentos; podem oscilar de vez em quando,
// porque o modelo não é 100% determinístico — se uma falha não se repetir rodando de novo, foi a IA).

echo "\n=== Fluxos (conversa completa, com IA) ===\n";

cenario('boas-vindas e modo "só gastos"', function (Conversa $c) {
    $r = $c->msg('/start site_br'); // botão da landing page
    contem('/start com origem da landing dá boas-vindas', $r, 'como você prefere');
    $r = $c->msg('oi');
    contem('boas-vindas oferece os dois modos', $r, 'como você prefere', '/ajuda');
    verdadeiro('botões de modo', in_array('M:simples', $c->dados_botoes(), true));
    $r = $c->clicar('M:simples');
    contem('confirma modo simples', $r, 'sem complicação');
    $r = $c->msg('gastei 20 no mercado');
    contem('registra gasto', $r, 'anotei', '20');
    nao_contem('não pergunta conta', $r, 'em qual conta');
    $c->msg('tenho 800');
    contem('saldo simples', $c->msg('quanto eu tenho?'), '780');
});

cenario('modo por conta, "nenhuma" e dinheiro', function (Conversa $c) {
    $c->clicar('M:contas');
    $r = $c->msg('tenho 500 na wise e 200 no millennium');
    contem('saldos iniciais das duas contas', $r, 'Wise', 'Millennium');
    $r = $c->msg('gastei 12 no mercado');
    contem('pergunta a conta', $r, 'em qual conta');
    verdadeiro('botões de conta + sem conta', in_array('tx:wise', $c->dados_botoes(), true) && in_array('tx:sem conta', $c->dados_botoes(), true));
    contem('registra na wise', $c->clicar('tx:wise'), 'Saldo Wise', '488');
    $c->msg('gastei 5 no pão');
    $c->msg('nenhuma');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM contas WHERE usuario_id = ? AND nome IN ('nenhuma', 'nenhum')");
    $stmt->execute([$c->usuario()['id']]);
    igual('"nenhuma" não vira conta', (int) $stmt->fetchColumn(), 0);
});

cenario('apagar pede confirmação e acha o gasto certo', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $c->msg('gastei 8 de internet, categoria contas');
    $c->msg('gastei 8');
    $r = $c->msg('exclui o gasto de 8 euros sem categoria');
    contem('pede confirmação', $r, 'Apago', '8,00');
    nao_contem('não escolhe o da internet', $r, 'internet');
    contem('"não" cancela', $c->msg('não'), 'não apaguei');
    $c->msg('exclui o gasto de 8 euros sem categoria');
    contem('"sim" apaga', $c->clicar('tx:sim'), 'Apaguei');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM transacoes WHERE usuario_id = ?");
    $stmt->execute([$c->usuario()['id']]);
    igual('sobrou só o da internet', (int) $stmt->fetchColumn(), 1);
});

cenario('editar categoria de um gasto', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $c->msg('comprei umas coisas no chinês, 12 euros');
    contem('pergunta o que mudar', $c->msg('altera a categoria desse gasto'), 'categoria nova');
    contem('muda a categoria', $c->msg('compras variadas'), 'compras variadas');
});

cenario('contas a pagar: Paguei e clique duplo', function (Conversa $c) {
    $c->modo(MODO_CONTAS);
    $c->msg('tenho 1000 na wise');
    $r = $c->msg('me lembra de pagar o aluguel todo dia 5, são 600 euros, sai da wise');
    contem('cria conta com valor', $r, '600', 'Paguei');
    $l = get_db()->query("SELECT * FROM lembretes WHERE usuario_id = " . (int) $c->usuario()['id'] . " ORDER BY id DESC LIMIT 1")->fetch();
    verdadeiro('marcado como conta', (int) $l['eh_conta'] === 1);
    [$aviso, $botoes] = montar_aviso_lembrete($l);
    $pagar = $botoes[0][0][1];
    verdadeiro('aviso tem botão Paguei', str_starts_with($pagar, 'L:pago:'));
    contem('Paguei registra o gasto', $c->clicar($pagar, $aviso), 'como paga', '600');
    contem('clique duplo não paga de novo', $c->clicar($pagar, $aviso), 'já estava marcada');
});

cenario('gasto fixo e lista de fixos', function (Conversa $c) {
    $c->modo(MODO_CONTAS);
    contem('cadastra fixo', $c->msg('tenho um gasto fixo de 700 de arrendamento todo dia 1'), 'Gasto fixo cadastrado');
    contem('lista fixos', $c->msg('meus gastos fixos'), 'arrendamento', '700');
});

cenario('orçamento avisa em 80% e 100%', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    contem('define orçamento', $c->msg('quero gastar no máximo 100 em restaurante por mês'), 'Orçamento definido', 'Restaurante');
    contem('aviso de 80%', $c->msg('gastei 90 no restaurante'), '⚠️');
    contem('aviso de estouro', $c->msg('gastei 20 no restaurante'), '🚨');
});

cenario('lembretes: dia 31 e dia da semana', function (Conversa $c) {
    contem('mensal no dia 31', $c->msg('me lembra de pagar o seguro do carro todo mês dia 31'), 'dia 31');
    $sexta = date('d/m', strtotime(date('w') == 5 ? 'today' : 'next friday'));
    $r = $c->msg('me lembra sexta às 10h de ligar pro banco');
    verdadeiro('sexta calculada certo', str_contains($r, $sexta) || str_contains($r, 'hoje') || str_contains($r, 'amanhã'), $r);
});

cenario('água', function (Conversa $c) {
    contem('registra água', $c->msg('bebi 500ml'), '500 ml');
    contem('relatório de hoje', $c->msg('quanto de água bebi hoje?'), '500 ml', 'Hoje');
});

cenario('metas de poupança', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    contem('cria meta com prazo', $c->msg('quero juntar 1200 euros até dezembro pra viagem'), 'Meta criada', 'Viagem', '1.200', 'por mês');
    contem('guardar + marco de 25%', $c->msg('guardei 300 pra viagem'), '+300,00 €', 'Primeiro quarto');
    $c->msg('quero juntar 500 pra reserva de emergência');
    contem('sem dizer a meta, pergunta qual', $c->msg('guardei 100'), 'pra qual meta');
    verdadeiro('botões com as metas', count($c->dados_botoes()) === 2);
    contem('escolhe pelo botão', $c->clicar('tx:reserva de emergência'), 'reserva', '100');
    contem('tirar dinheiro', $c->msg('tirei 50 da viagem'), 'Tirei', '50');
    contem('lista as metas', $c->msg('minhas metas'), 'Viagem', '250', 'Reserva');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM transacoes WHERE usuario_id = ?");
    $stmt->execute([$c->usuario()['id']]);
    igual('guardar não vira gasto nem mexe no saldo', (int) $stmt->fetchColumn(), 0);
    $c->msg('gastei 20 no mercado');
    contem('meta aparece no resumo', $c->msg('resumo da semana'), 'Metas', 'Viagem');
    $c->msg('a meta da reserva de emergência agora é 150');
    contem('alcança a meta', $c->msg('guardei 60 pra reserva de emergência'), 'META ALCANÇADA');
    contem('cancela meta', $c->msg('cancela a meta da viagem'), 'Cancelei', '250');
});

cenario('metas: quanto guardar por semana e nome parcial', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $prazo = date('Y-m-d', strtotime('+70 days'));
    get_db()->prepare("INSERT INTO metas (usuario_id, nome, valor_alvo, moeda, prazo, criado_em) VALUES (?, 'calção do novo quarto', 700, 'EUR', ?, NOW()), (?, 'viagem', 500, 'EUR', NULL, NOW())")
        ->execute([$c->usuario()['id'], $prazo, $c->usuario()['id']]);
    contem('acha pelo nome parcial e divide por semana', $c->msg('Quanto preciso guardar por semana para o calção do quarto?'), 'Calção do novo quarto', '70,00 €', 'por semana');
    contem('meta sem prazo pede o prazo', $c->msg('quanto tenho que guardar por dia pra viagem?'), 'não tem prazo');
    contem('"prazo de 10 dias" responde a pergunta', $c->msg('prazo de 10 dias'), 'Prazo', '50,00 € por dia');
    contem('"o prazo do X é dezembro" muda o prazo', $c->msg('o prazo do calção do novo quarto é dezembro'), 'Mudei prazo', '31/12');
    get_db()->prepare("INSERT INTO metas (usuario_id, nome, valor_alvo, moeda, prazo, criado_em) VALUES (?, 'bicicleta', 300, 'EUR', NULL, NOW())")->execute([$c->usuario()['id']]);
    contem('pergunta o prazo da bicicleta', $c->msg('quanto preciso guardar por dia pra bicicleta'), 'não tem prazo');
    contem('"15 duas" (erro de digitação) vira o prazo', $c->msg('15 duas'), 'Prazo', '20,00 € por dia');
    contem('prazo curto fala por semana/dia (não "por mês")', $c->msg('minhas metas'), '~140,00 €/semana', '~50,00 €/dia');
    contem('meta existente + "prazo de 20 dias" muda o prazo', $c->msg('o calção do quarto, preciso juntar num prazo de 20 dias'), 'Mudei prazo', date('d/m/Y', strtotime('+20 days')));
});

cenario('cancelar no meio e apagar o último', function (Conversa $c) {
    $c->modo(MODO_CONTAS);
    contem('pergunta a conta', $c->msg('gastei 15 no mercado'), 'Em qual conta');
    contem('"cancela" larga o gasto', $c->msg('cancela'), 'Cancelado');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM transacoes WHERE usuario_id = ?");
    $stmt->execute([$c->usuario()['id']]);
    igual('nada foi anotado', (int) $stmt->fetchColumn(), 0);
    $c->msg('gastei 12 no café');
    $c->msg('nenhuma');
    contem('"apaga" pede confirmação do último', $c->msg('apaga'), 'Apago', '12');
    contem('"sim" apaga', $c->msg('sim'), 'Apaguei');
});

cenario('compromisso avisa antes', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $dia = date('d/m', strtotime('+3 days'));
    $r = $c->msg("tenho reunião dia {$dia} às 15h");
    contem('compromisso: avisa 1 h antes por padrão e pergunta', $r, 'Reunião', '15:00', '1 h antes', 'Prefere');
    $v30 = current(array_filter($c->dados_botoes(), fn($d) => str_starts_with($d, 'V:30:')));
    verdadeiro('botões de antecedência', (bool) $v30 && count($c->dados_botoes()) === 4);
    contem('botão "30 min antes"', $c->clicar($v30), '30 min antes', '14:30');
    $r = $c->msg('me lembra de tomar o remédio amanhã às 8h');
    nao_contem('tarefa (remédio) não pergunta antecedência', $r, 'Prefere');
    contem('muda o aviso pelo nome', $c->msg('avisa a reunião 1 dia antes'), 'reunião', '1 dia antes');
    $c->msg("me lembra da reunião do dia {$dia}, 2 horas antes");
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' AND texto LIKE 'reuni%'");
    $stmt->execute([$c->usuario()['id']]);
    $linhas = $stmt->fetchAll();
    igual('não duplica a reunião', count($linhas), 1);
    igual('... só muda o aviso (2 h)', (int) $linhas[0]['antecedencia_min'], 120);
    igual('... e mantém as 15h', substr($linhas[0]['disparar_em'], 11, 5), '15:00');
    // O cron pega o compromisso 2 h antes (não na hora)
    $cron = get_db()->prepare("SELECT COUNT(*) FROM lembretes WHERE id = ? AND status = 'pendente' AND DATE_SUB(disparar_em, INTERVAL COALESCE(antecedencia_min, 0) MINUTE) <= ?");
    $cron->execute([$linhas[0]['id'], date('Y-m-d H:i:s', strtotime($linhas[0]['disparar_em']) - 119 * 60)]);
    igual('cron dispara 2 h antes', (int) $cron->fetchColumn(), 1);
    [$aviso] = montar_aviso_lembrete($linhas[0]);
    contem('aviso diz quando é', $aviso, 'Daqui a', 'reuni', '15:00');
    contem('lista mostra o aviso', $c->msg('meus lembretes'), 'aviso 2 h antes');
});

cenario('corrigir, avisar às 7 e apagar vários lembretes', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    [$d1, $d2, $d3] = array_map(fn($n) => (int) date('j', strtotime("+$n days")), [5, 6, 7]);
    $c->msg("tenho consulta dia {$d1} as 10");
    $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$c->usuario()['id']]);
    $l = $stmt->fetch();
    igual('"as 10" é o horário', substr($l['disparar_em'], 11, 5), '10:00');
    igual('... e o aviso padrão é 1 h antes', (int) $l['antecedencia_min'], 60);
    $c->msg("me lembra da minha reunão do dia {$d3}");
    $nova = date('d/m', strtotime('+9 days'));
    contem('"é dd/mm" corrige o lembrete recém-criado', $c->msg("é {$nova}"), 'Atualizei o lembrete', 'reun', $nova);
    contem('"me avisa as 7" = aviso às 7 do dia', $c->msg('me avisa as 7'), '2 h antes', '07:00');
    $c->msg("tenho outra consulta dia {$d2} as 18");
    $r = $c->msg("apaga a consulta do dia {$d1}, a do dia {$d2} e a reunião");
    contem('lista os 3 e pede confirmação', $r, 'Cancelo estes 3');
    contem('"sim" cancela os 3', $c->msg('sim'), 'Cancelei 3');
    // Daqui a pouco: não pergunta antecedência
    nao_contem('compromisso daqui a 30 min não pergunta', $c->msg('tenho reunião daqui 30 minutos'), 'Prefere');
    // Duas reuniões hoje: pergunta qual
    $c->msg('tenho reunião daqui 2 minutos');
    contem('duas reuniões hoje: pergunta qual', $c->msg('apaga a reunião de hoje'), 'Qual você quer cancelar');
    $botoes = $c->dados_botoes();
    igual('um botão pra cada', count($botoes), 2);
    contem('botão cancela só aquela', $c->clicar($botoes[0]), 'Cancelado');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM lembretes WHERE usuario_id = ? AND status = 'pendente' AND texto LIKE 'reuni%'");
    $stmt->execute([$c->usuario()['id']]);
    igual('a outra continua', (int) $stmt->fetchColumn(), 1);
});

// As frases dos testes do Nico (29–30/09), na ordem em que deram erro — tudo junto, como numa conversa de verdade
cenario('conversa real do Nico (29-30/09): metas, compromissos, edições', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $conta = function ($tabela, $filtro = '1=1') use ($c) {
        $stmt = get_db()->prepare("SELECT COUNT(*) FROM $tabela WHERE usuario_id = ? AND $filtro");
        $stmt->execute([$c->usuario()['id']]);
        return (int) $stmt->fetchColumn();
    };
    contem('cria a meta', $c->msg('quero juntar 700 euros pro calção do novo quarto'), 'Meta criada', '700');
    contem('sem prazo: pergunta', $c->msg('quanto preciso guardar por semana para o calção do quarto?'), 'não tem prazo');
    contem('"prazo de 15 dias" responde', $c->msg('prazo de 15 dias'), 'Prazo', 'por semana');
    contem('"quanto preciso juntar por dia"', $c->msg('quanto preciso juntar por dia'), '46,67 € por dia');
    igual('nenhum gasto criado por engano', $conta('transacoes'), 0);

    $ontem = (int) date('j', strtotime('-1 day'));
    $r = $c->msg("tenho dentista dia {$ontem} às 15 horas");
    contem('compromisso, não gasto', $r, 'Dentista', '15:00');
    if ((int) date('j') > 1) contem('avisa que o dia já passou', $r, 'já passou');
    igual('dentista não virou gasto', $conta('transacoes'), 0);

    contem('gasto de consulta', $c->msg('paguei 40 na consulta ontem'), '40');
    $d = (int) date('j', strtotime('+4 days'));
    $c->msg("tenho consulta dia {$d} as 19");
    $nova = date('d/m', strtotime('+20 days'));
    contem('gasto e lembrete "consulta": data futura muda o lembrete', $c->msg("altera a consulta para {$nova}"), 'Atualizei o lembrete', $nova, '19:00');
    igual('o gasto da consulta ficou com a data dele', $conta('transacoes', "data = '" . date('Y-m-d', strtotime('-1 day')) . "'"), 1);

    $r = $c->msg('me lembra de beber água daqui 2 minutos');
    nao_contem('água é tarefa: não pergunta antecedência', $r, 'Prefere');

    $d2 = (int) date('j', strtotime('+6 days'));
    contem('reunião sem hora', $c->msg("tenho uma reunião dia {$d2}"), 'Reunião', '09:00');
    contem('"me lembra 30 minutos antes" ajusta a reunião', $c->msg('me lembra 30 minutos antes'), 'reunião', '30 min antes');
    $c->msg("me lembra da reuniao do dia {$d2}, 1 hora antes");
    igual('não duplicou a reunião', $conta('lembretes', "status = 'pendente' AND texto LIKE 'reuni%'"), 1);
    igual('... e o aviso virou 1 h', $conta('lembretes', "status = 'pendente' AND texto LIKE 'reuni%' AND antecedencia_min = 60"), 1);

    contem('apaga um lembrete pelo nome', $c->msg('apaga o lembrete do dentista'), 'Cancelado', 'dentista');
    $c->msg('gastei 15 no mercado');
    contem('"apaga" = último gasto, com confirmação', $c->msg('apaga'), 'Apago', '15');
    contem('"não" mantém', $c->msg('não'), 'não apaguei');
    igual('os 2 gastos continuam lá', $conta('transacoes'), 2);
});

// Teste do Nico de 30/09 (prints): levantamento, "detalha", "hoje" sem hora, "amanhã não, hoje"
cenario('levantamento, detalha e lembrete pra hoje', function (Conversa $c) {
    $c->modo(MODO_CONTAS);
    $c->msg('tenho 500 no millenium e 100 na wise');
    $r = $c->msg('Levantei 20 euros do millenium');
    contem('levantamento não é gasto', $r, 'Levantamento', 'Millenium', 'Dinheiro');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM transacoes WHERE usuario_id = ?");
    $stmt->execute([$c->usuario()['id']]);
    igual('nenhum gasto criado', (int) $stmt->fetchColumn(), 0);
    $r = $c->msg('Quanto eu tenho?');
    contem('saldo total não muda com o levantamento', $r, '600,00');
    verdadeiro('saldo oferece "ver por conta"', in_array('tx:saldo por conta', $c->dados_botoes(), true));
    contem('"Detalha" depois do saldo = por conta', $c->msg('Detalha'), 'Millenium: 480,00', 'Dinheiro: 20,00');

    $dia_util = (int) date('G') >= 9 && (int) date('G') < 21; // fora disso "hoje" não tem o que perguntar / vira amanhã
    $r = $c->msg('Me lembra de ver o filme: "brilho eterno de uma mente sem lembranças", hoje');
    if ($dia_util) {
        contem('"hoje" sem hora pergunta a hora', $r, 'Hoje a que horas');
        contem('resposta com a hora marca pra hoje', $c->msg('daqui 1 hora'), 'Combinado', 'hoje às');
    }
    $c->msg('me lembra de ligar pro João amanhã');
    $r = $c->msg('Amanhã não, hoje');
    if ($dia_util) {
        contem('"Amanhã não, hoje" pergunta a hora de hoje', $r, 'é hoje', 'A que horas');
        contem('... e muda o lembrete pra hoje', $c->msg('daqui 1 hora'), 'Atualizei o lembrete', 'hoje às');
    }
});

// Teste do Nico depois do /reset (30/09, prints): ordenado no millenium e "65 cadastra como gasto fixo"
cenario('ordenado em banco conhecido e "65 cadastra como gasto fixo"', function (Conversa $c) {
    $c->modo(MODO_CONTAS);
    $r = $c->msg('Recebi meu ordenado ontem, no millenium, 1100,00');
    nao_contem('não pergunta o que é millenium', $r, 'O que é');
    contem('registra o ordenado na conta', $r, '1.100,00', 'Millenium');
    $c->msg('Tenho 300 na Wise');
    contem('pagamento sem valor pergunta quanto', $c->msg('O seguro do carro foi pago ontem'), 'Quanto foi');
    $r = $c->msg('65 cadastra como gasto fixo');
    contem('cadastra o fixo com o que já sabia', $r, 'Gasto fixo cadastrado', 'seguro', '65,00');
    nao_contem('não pergunta de novo qual é o gasto', $r, 'Qual é o gasto fixo');
    contem('... e segue com o gasto (pergunta a conta)', $r, 'Em qual conta');
    contem('anota o pagamento de ontem', $c->msg('wise'), '65,00', 'Saldo Wise: 235,00');
    $stmt = get_db()->prepare("SELECT data FROM transacoes WHERE usuario_id = ? AND tipo = 'gasto'");
    $stmt->execute([$c->usuario()['id']]);
    igual('gasto com a data de ontem', $stmt->fetchAll(PDO::FETCH_COLUMN), [date('Y-m-d', strtotime('-1 day'))]);
    $stmt = get_db()->prepare("SELECT dia_mes, valor, recorrente FROM lembretes WHERE usuario_id = ? AND eh_conta = 1 AND status = 'pendente'");
    $stmt->execute([$c->usuario()['id']]);
    $fixo = $stmt->fetch(PDO::FETCH_ASSOC);
    igual('fixo mensal no dia em que pagou, com o valor', [(int) ($fixo['dia_mes'] ?? 0), (float) ($fixo['valor'] ?? 0), $fixo['recorrente'] ?? null], [(int) date('j', strtotime('-1 day')), 65.0, 'mensal']);
});

cenario('conta sem "todo mês" pergunta se é mensal', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $lembrete = function () use ($c) {
        $stmt = get_db()->prepare("SELECT * FROM lembretes WHERE usuario_id = ? AND status = 'pendente' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$c->usuario()['id']]);
        return $stmt->fetch();
    };
    $r = $c->msg('Preciso pagar cartão de crédito dia 5');
    contem('pergunta se é todo mês', $r, 'todo mês?', 'Paguei');
    igual('fica só pra essa data até responder', $lembrete()['recorrente'], null);
    $botao = current(array_filter($c->dados_botoes(), fn($d) => str_starts_with($d, 'R:mensal:')));
    contem('botão "Sim, todo mês"', $c->clicar((string) $botao), 'todo mês no dia 5');
    igual('virou mensal no dia 5', [$lembrete()['recorrente'], (int) $lembrete()['dia_mes']], ['mensal', 5]);
    contem('o valor ainda pode ser dito depois', $c->msg('57'), '57,00');
    $c->msg('preciso pagar a conta da luz dia 12');
    contem('resposta escrita "todo mês"', $c->msg('todo mês'), 'todo mês no dia 12');
    contem('"todo dia 8" já entra como mensal, sem perguntar', $c->msg('me lembra de pagar a internet todo dia 8'), 'todo mês no dia 8');
});

cenario('moeda inicial pelo país e troca nas boas-vindas', function (Conversa $c) {
    $moeda = function () use ($c) {
        $stmt = get_db()->prepare("SELECT moeda_padrao FROM usuarios WHERE id = ?");
        $stmt->execute([$c->usuario()['id']]);
        return $stmt->fetchColumn();
    };
    definir_regiao($c->usuario()['id'], 'BR'); // o que o webhook faz pra Telegram em pt-br (ou /start site_br)
    $r = $c->msg('oi');
    contem('boas-vindas diz a moeda', $r, 'Vou anotar em real (R$)');
    verdadeiro('botão pra trocar pra euro', in_array('C:EUR', $c->dados_botoes(), true));
    contem('troca pra euro com um toque', $c->clicar('C:EUR'), 'euro (€)');
    igual('moeda virou EUR', $moeda(), 'EUR');
    contem('e volta pra real', $c->clicar('C:BRL'), 'real (R$)');
    $c->clicar('M:simples');
    contem('gasto sai em real', $c->msg('gastei 20 no mercado'), 'R$ 20,00');
    $c->msg('/reset');
    igual('/reset não volta a moeda pra euro', $moeda(), 'BRL');
});

cenario('listas: compras, tarefas, riscar e limpar', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $item = function ($texto) use ($c) {
        $stmt = get_db()->prepare("SELECT * FROM lista_itens WHERE usuario_id = ? AND texto = ?");
        $stmt->execute([$c->usuario()['id'], $texto]);
        return $stmt->fetch();
    };
    $r = $c->msg('adiciona leite, pão e ovos na lista');
    contem('adiciona na lista de compras', $r, 'Anotei na lista de compras', 'leite', 'pão', 'ovos');
    igual('um botão pra riscar cada item', count(array_filter($c->dados_botoes(), fn($d) => str_starts_with($d, 'I:ok:'))), 3);
    contem('não duplica item', $c->msg('coloca leite na lista'), 'Já estava lá: leite');
    contem('outra lista com dois pontos', $c->msg('lista de tarefas: ligar pro contador, pagar o IRS'), 'lista de tarefas', 'ligar pro contador');
    contem('minhas listas', $c->msg('minhas listas'), 'Compras', 'Tarefas');
    contem('frase livre vai pra lista (IA)', $c->msg('preciso comprar detergente'), 'detergente');
    $uso = get_db()->prepare("SELECT chamadas, tokens_entrada FROM uso_ia_diario WHERE usuario_id = ? AND data = ?");
    $uso->execute([$c->usuario()['id'], date('Y-m-d')]);
    $uso = $uso->fetch();
    verdadeiro('a chamada à IA foi contada pro usuário (com tokens)', $uso && $uso['chamadas'] >= 1 && $uso['tokens_entrada'] > 0);
    $c->clicar('I:ok:' . $item('leite')['id']);
    igual('botão risca o item', (int) $item('leite')['feito'], 1);
    contem('"já comprei o pão" risca', $c->msg('já comprei o pão'), 'Risquei: pão');
    $r = $c->msg('comprei ovos 3 euros');
    contem('gasto com valor anota E risca da lista', $r, '3,00', 'Risquei "ovos"');
    contem('tira sem ter feito', $c->msg('tira o detergente da lista'), 'Tirei da lista: detergente');
    contem('limpa os riscados', $c->msg('limpa os riscados'), 'Limpei 3');
    contem('lista de compras vazia', $c->msg('mostra a lista de compras'), 'está vazia');
    contem('apagar a lista pede confirmação', $c->msg('apaga a lista de tarefas'), 'Apago a lista de tarefas');
    contem('"sim" apaga a lista', $c->msg('sim'), 'Apaguei a lista de tarefas');
    contem('"lista meus gastos" continua sendo os gastos', $c->msg('lista meus gastos'), 'ovos');
});

// Teste do Nico (30/09, prints): criar lista e responder os itens depois
cenario('listas: criar e responder os itens', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    contem('"cria uma lista de compras" pergunta os itens', $c->msg('cria uma lista de compras'), 'Lista de compras pronta', 'O que vai nela');
    contem('resposta curta vira item', $c->msg('álcool'), 'Anotei na lista de compras: álcool');
    contem('"cria um checklist" pergunta o nome', $c->msg('cria um checklist que preciso seguir'), 'Qual o nome da lista');
    contem('botão "Tarefas" cria a lista', $c->clicar('tx:tarefas'), 'Lista de tarefas pronta');
    contem('resposta longa vira itens da lista certa', $c->msg('tomar banho, lavar roupas, lavar louça e buscar o zion'), 'Anotei na lista de tarefas', 'buscar o zion');
    contem('itens com verbo vão pra tarefas mesmo sem dizer', $c->msg('adiciona pagar a luz e ligar pro banco na lista'), 'lista de tarefas');
    contem('plural certo na confirmação', $c->msg('apaga a lista de compras'), '(1 item)');
    $c->msg('não');
    contem('pedido novo no meio da pergunta não vira item', ($c->msg('cria uma lista') and true) ? $c->msg('quanto gastei esse mês?') : '', 'gast');
});

cenario('apagar conta pelo nome', function (Conversa $c) {
    $c->modo(MODO_CONTAS);
    salvar_conta($c->usuario()['id'], 'cancela');
    contem('"apaga \'cancela\'" remove a conta', $c->msg('apaga "cancela"'), 'Removi a conta', 'Cancela');
});

cenario('lembrete em dia que já passou e edição pelo nome', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $ontem = (int) date('j', strtotime('-1 day'));
    $c->msg("tenho consulta dia {$ontem} às 19 horas");
    $stmt = get_db()->prepare("SELECT disparar_em FROM lembretes WHERE usuario_id = ? AND status = 'pendente'");
    $stmt->execute([$c->usuario()['id']]);
    verdadeiro('dia que já passou vai pro mês que vem (não dispara na hora)', strtotime($stmt->fetchColumn()) > time() + 86400 * 20);
    $novo = date('Y-m-d', strtotime('+12 days'));
    $r = $c->msg('altera, é ' . date('d/m', strtotime($novo)));
    contem('"altera, é dd/mm" edita o lembrete recém-criado', $r, 'Atualizei o lembrete');
    $stmt = get_db()->prepare("SELECT disparar_em FROM lembretes WHERE usuario_id = ? AND status = 'pendente'");
    $stmt->execute([$c->usuario()['id']]);
    igual('nova data mantém a hora', $stmt->fetchColumn(), "$novo 19:00:00");
    $c->msg('gastei 10 no mercado');
    contem('"altera a consulta ..." edita o lembrete mesmo depois de um gasto', $c->msg('altera a consulta para as 20h'), 'Atualizei o lembrete', '20:00');
    $c->msg('paguei 40 na consulta ontem');
    $data = date('d/m', strtotime('+20 days'));
    contem('gasto e lembrete com a mesma palavra: data futura = lembrete', $c->msg("altera a consulta para $data"), 'Atualizei o lembrete', $data);
});

cenario('plano: teste grátis, Grátis, limite de lembretes', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    igual('usuário novo começa no teste', status_plus($c->usuario())['tipo'], 'teste');
    contem('/plano no teste mostra dias e preço', $c->msg('/plano'), 'teste grátis', 'Stars');
    $c->acabar_teste();
    $r = $c->msg('quero juntar 1000 pra viagem');
    contem('recurso Plus no Grátis mostra a oferta', $r, 'do Zágora Plus', 'Stars');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM metas WHERE usuario_id = ?");
    $stmt->execute([$c->usuario()['id']]);
    igual('no Grátis não cria a meta', (int) $stmt->fetchColumn(), 0);
    contem('gasto continua liberado no Grátis', $c->msg('gastei 20 no mercado'), 'anotei');
    foreach (['me lembra de ligar pro banco amanhã às 10h', 'me lembra de pagar a luz dia 10', 'me lembra de ir ao médico sexta às 15h'] as $m) {
        $c->msg($m);
    }
    contem('4º lembrete no Grátis bloqueia', $c->msg('me lembra de comprar pão amanhã às 8h'), 'até 3 lembretes');
});

cenario('pagamento em Stars: assinar, renovar, anual', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $c->acabar_teste();
    definir_regiao($c->usuario()['id'], 'BR');
    $u = $c->usuario();
    [$stars, $fundador] = preco_plus($u, 'mensal');
    verdadeiro('preço BR do mensal (fundador ou normal)', in_array($stars, [100, 150], true));

    // Pré-checkout: só aceita o próprio link, em Stars
    igual('pré-checkout do próprio link aceito', validar_pre_checkout($u, ['invoice_payload' => "plus|mensal|{$u['id']}|1", 'currency' => 'XTR'])[0], true);
    igual('pré-checkout de outro usuário recusado', validar_pre_checkout($u, ['invoice_payload' => 'plus|mensal|999999|1', 'currency' => 'XTR'])[0], false);

    contem('1º pagamento dá boas-vindas ao Plus', $c->pagar('mensal', 100), 'Bem-vindo ao Zágora Plus', 'fundador');
    igual('vira Plus mensal', status_plus($c->usuario())['tipo'], 'mensal');
    igual('fica marcado como fundador', (int) $c->usuario()['fundador'], 1);
    contem('recurso Plus liberado depois de pagar', $c->msg('quero juntar 1000 pra viagem'), 'Meta criada');
    contem('renovação automática agradece', $c->pagar('mensal', 100, ['is_first_recurring' => false, 'subscription_expiration_date' => strtotime('+60 days')]), 'renovada');
    contem('/plano de assinante mostra como cancelar', $c->msg('/plano'), 'Plus (mensal)', 'Minhas Stars');

    // Mesmo pagamento chegando duas vezes (Telegram reenvia) não duplica
    $u = $c->usuario();
    $pag = ['currency' => 'XTR', 'total_amount' => 1000, 'invoice_payload' => "plus|anual|{$u['id']}|1", 'telegram_payment_charge_id' => 'teste_duplicado'];
    registrar_pagamento_stars($u, $pag);
    igual('pagamento repetido é ignorado', registrar_pagamento_stars($c->usuario(), $pag), null);
    verdadeiro('anual soma 365 dias ao que já tinha', strtotime($c->usuario()['plus_ate']) > strtotime('+400 days'));
});

cenario('uso justo do Grátis (20 mensagens com IA por dia)', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $c->acabar_teste();
    get_db()->prepare("UPDATE usuarios SET ia_uso_data = ?, ia_uso_qtd = ? WHERE id = ?")->execute([date('Y-m-d'), LIMITE_IA_GRATIS, $c->usuario()['id']]);
    contem('no limite, mensagem que precisa de IA é bloqueada', $c->msg('gastei 20 no mercado'), 'mensagens do plano Grátis');
    contem('atalho continua funcionando no limite', $c->msg('saldo'), 'saldo');
    contem('comando continua funcionando no limite', $c->msg('/plano'), 'Grátis');
    // No teste/Plus não tem limite
    get_db()->prepare("UPDATE usuarios SET trial_ate = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', strtotime('+5 days')), $c->usuario()['id']]);
    nao_contem('no teste grátis não há limite', $c->msg('gastei 20 no mercado'), 'mensagens do plano Grátis');
});

cenario('reporte de erro (/reportar, texto e print)', function (Conversa $c) {
    contem('boas-vindas mostram o /reportar', $c->msg('/start'), '/reportar');
    contem('/reportar pede os detalhes', $c->msg('/reportar'), 'Me conta o que aconteceu');
    contem('texto vira reporte', $c->msg('o bot não entendeu academia'), 'Reporte #');
    contem('/reportar com texto junto grava direto', $c->msg('/reportar faltou a categoria educação'), 'Reporte #');
    $foto = ['photo' => [['file_id' => 'pequena'], ['file_id' => 'grande_teste']], 'caption' => ''];
    contem('print sem estado explica', processar_foto($c->usuario(), $foto), 'só entendo texto');
    $c->msg('/reportar');
    contem('print depois do /reportar grava e pede descrição', processar_foto($c->usuario(), $foto), 'poucas palavras');
    contem('descrição completa o reporte', $c->msg('a tela de saldo ficou vazia'), 'Anotado');
    $stmt = get_db()->prepare("SELECT COUNT(*) AS n, SUM(foto_file_id = 'grande_teste') AS fotos, SUM(texto = 'a tela de saldo ficou vazia') AS completo FROM reportes WHERE usuario_id = ?");
    $stmt->execute([$c->usuario()['id']]);
    $r = $stmt->fetch();
    igual('3 reportes, 1 com a maior foto, descrição gravada', [(int) $r['n'], (int) $r['fotos'], (int) $r['completo']], [3, 1, 1]);
    igual('estado limpo no fim', $c->usuario()['estado'], null);
});

cenario('origem do usuário e mensagens que não são texto', function (Conversa $c) {
    $c->msg('/start instagram');
    igual('origem gravada pelo /start', $c->usuario()['origem'], 'instagram');
    $c->msg('/start site_br');
    igual('origem não muda depois', $c->usuario()['origem'], 'instagram');
    contem('áudio tem resposta', processar_nao_texto(['voice' => ['file_id' => 'x']]), 'áudio');
    igual('figurinha tem resposta', processar_nao_texto(['sticker' => ['file_id' => 'x']]), '😄');
    contem('arquivo tem resposta', processar_nao_texto(['document' => ['file_id' => 'x']]), 'só entendo texto');
});

cenario('suporte de pagamento', function (Conversa $c) {
    contem('/paysupport explica e pergunta', $c->msg('/paysupport'), 'Suporte de pagamentos', 'Minhas Stars');
    contem('próxima mensagem é encaminhada', $c->msg('paguei e não liberou'), 'Recebido');
    igual('estado limpo depois', $c->usuario()['estado'], null);
});

cenario('conversa real de testadora (27/09): vários gastos, academia, pronto', function (Conversa $c) {
    $c->msg('/start site_br');
    $c->clicar('M:simples');
    igual('Brasil começa em real', $c->usuario()['moeda_padrao'], 'BRL');
    $r = $c->msg('Exercício 87€');
    contem('exercício é saúde e 87€ adota euro', $r, '87', 'saúde', 'euro');
    igual('moeda padrão virou euro', $c->usuario()['moeda_padrao'], 'EUR');
    $r = $c->msg('gastei 507 com o carro, 450 aluguel e 30 na farmácia');
    contem('3 gastos numa mensagem, nenhum perdido', $r, 'Anotei 3 gastos', '507', '450', '30');
    $r = $c->msg('Academia 84');
    contem('"Academia 84" registra 84', $r, '84');
    nao_contem('não pergunta o valor', $r, 'Quanto foi');
    $r = $c->msg('Cartão de crédito 700');
    nao_contem('"Cartão de crédito 700" não vira conta', $r, 'já tinha');
    contem('"Pronto" encerra com resumo', $c->msg('Pronto'), 'Fechado');
    contem('"Só foram estas as despesas" encerra', $c->msg('Só foram estas as despesas'), 'Fechado');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM transacoes WHERE usuario_id = ?");
    $stmt->execute([$c->usuario()['id']]);
    igual('6 gastos registrados no total', (int) $stmt->fetchColumn(), 6);
});

cenario('vários gastos com conta + remover conta errada', function (Conversa $c) {
    $c->modo(MODO_CONTAS);
    $c->msg('tenho 500 na wise');
    $r = $c->msg('gastei 20 no mercado e 15 na farmácia');
    contem('pergunta a conta uma vez só', $r, 'Anotei 2 gastos', 'vale pra todos');
    contem('conta vale pros dois', $c->clicar('tx:wise'), 'nos 2 gastos', 'Saldo Wise', '465');
    contem('"tenho 700 no cartão de crédito" cria saldo', $c->msg('tenho 700 no cartão de crédito'), 'já tinha', '700');
    contem('remove conta criada por engano', $c->msg('remove a conta cartão de crédito'), 'Removi a conta');
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM saldos_iniciais WHERE usuario_id = ? AND conta LIKE '%cr%dito%'");
    $stmt->execute([$c->usuario()['id']]);
    igual('saldo inicial da conta errada sumiu', (int) $stmt->fetchColumn(), 0);
});

cenario('resumo e ajuda', function (Conversa $c) {
    $c->modo(MODO_SIMPLES);
    $c->msg('gastei 30 no mercado');
    contem('resumo da semana', $c->msg('resumo da semana'), 'Resumo', 'Onde foi', 'Mercado');
    contem('ajuda', $c->msg('/ajuda'), 'Tudo que eu faço');
});
