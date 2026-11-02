<?php
// Testes das regras em PHP puro (sem IA) — rápidos, rodam sempre.
// Cada bloco protege um bug que já aconteceu ou uma decisão do handoff.

echo "\n=== Unidade (regras sem IA) ===\n";
$GLOBALS['testes']['cenario'] = 'unidade';

// Confirmações casuais nunca viram categoria/transação (bug: "Perfeito" virou categoria)
foreach (['Perfeito', 'ótimo!', 'Beleza!', 'valeu cara', 'legal', 'show'] as $t) {
    verdadeiro("confirmação: '$t'", parece_confirmacao($t));
}
foreach (['boa tarde', 'mercado', 'tabacaria', 'Wise', 'sim'] as $t) {
    verdadeiro("não é confirmação: '$t'", !parece_confirmacao($t));
}
verdadeiro("'Perfeito' não é categoria válida", !parece_categoria_valida('Perfeito'));

// Sim/não da confirmação de apagar
foreach (['sim', 's', 'pode', 'apaga', 'beleza', 'ok'] as $t)   igual("sim/não: '$t'", resposta_sim_nao($t), true);
foreach (['não', 'Nao', 'n', 'cancela', 'deixa'] as $t)         igual("sim/não: '$t'", resposta_sim_nao($t), false);
foreach (['lista meus gastos', 'gastei 10 no mercado'] as $t)   igual("sim/não: '$t'", resposta_sim_nao($t), null);

// Resposta a "em qual conta?" nunca cria conta-fantasma
igual('conta: não uso contas', interpretar_resposta_conta('não uso contas'), 'desligar');
igual('conta: nenhuma', interpretar_resposta_conta('nenhuma'), 'sem');
igual('conta: sem conta', interpretar_resposta_conta('sem conta'), 'sem');
igual('conta: em dinheiro', interpretar_resposta_conta('em dinheiro'), 'dinheiro');
igual('conta: wise', interpretar_resposta_conta('wise'), null);
igual('limpar "foi revolut"', limpar_nome_conta('foi revolut'), 'revolut');

// Datas: a IA erra dia da semana, então é PHP que calcula
igual('sexta (futuro)', detectar_dia_semana('me lembra sexta'), date('Y-m-d', strtotime(date('w') == 5 ? 'today' : 'next friday')));
igual('segunda-feira (futuro)', detectar_dia_semana('toda segunda-feira'), date('Y-m-d', strtotime(date('w') == 1 ? 'today' : 'next monday')));
igual('sexta (passado)', detectar_dia_semana('paguei na sexta', 'passado'), date('Y-m-d', strtotime(date('w') == 5 ? 'today' : 'last friday')));
igual('sem dia da semana', detectar_dia_semana('segundamente nada'), null);
igual('daqui há 2 minutos', detectar_tempo_relativo('daqui há 2 minutos'), date('Y-m-d H:i', strtotime('+2 minutes')));
igual('daqui a 3 horas', detectar_tempo_relativo('daqui a 3 horas'), date('Y-m-d H:i', strtotime('+3 hours')));
igual('31/09 não existe', validar_data_ou_invalida('2026-09-31'), false);
igual('data "ontem"', interpretar_data_simples('ontem'), date('Y-m-d', strtotime('-1 day')));
igual('data "25/09/2026"', interpretar_data_simples('25/09/2026'), '2026-09-25');

// "quanto preciso guardar por semana pro calção do quarto?" não depende da IA (voltava unknown)
igual('atalho quanto guardar por semana', atalho_sem_ia('Quanto preciso guardar por semana para o calção do quarto?'), ['intent' => 'listar_metas', 'frequencia' => 'semana', 'meta' => 'calcao do quarto']);
igual('atalho quanto guardar por dia', atalho_sem_ia('quanto tenho que guardar por dia?'), ['intent' => 'listar_metas', 'frequencia' => 'dia']);
igual('atalho quanto juntar por mês', atalho_sem_ia('quanto devo juntar por mês pra viagem'), ['intent' => 'listar_metas', 'frequencia' => 'mes', 'meta' => 'viagem']);

// Prazo de meta dito do jeito que a pessoa fala (bug: "prazo de 15 dias" virou apagar transação)
igual('prazo "prazo de 15 dias"', interpretar_prazo('prazo de 15 dias'), date('Y-m-d', strtotime('+15 days')));
igual('prazo "daqui a 3 meses"', interpretar_prazo('daqui a 3 meses'), date('Y-m-d', strtotime('+3 months')));
igual('prazo "um ano"', interpretar_prazo('um ano'), date('Y-m-d', strtotime('+1 year')));
igual('prazo "dezembro"', interpretar_prazo('dezembro'), date('m') == 12 && date('j') == 31 ? (date('Y') + 1) . '-12-31' : date('Y') . '-12-31');
igual('prazo "até março de 2027"', interpretar_prazo('até março de 2027'), '2027-03-31');
igual('prazo "fim do ano"', interpretar_prazo('fim do ano'), date('Y') . '-12-31');
igual('não é prazo', interpretar_prazo('apaga o gasto do mercado'), null);
igual('prazo com erro de digitação "15 duas"', interpretar_prazo('15 duas'), date('Y-m-d', strtotime('+15 days')));
verdadeiro('"15 de dezembro" não vira 15 dias', interpretar_prazo('15 de dezembro') !== date('Y-m-d', strtotime('+15 days')));
igual('"apaga" sozinho = último registro', atalho_sem_ia('apaga'), ['intent' => 'deletar_transacao', 'ultimo' => true]);

// Aviso com antecedência
igual('antecedência "30 minutos antes"', detectar_antecedencia('me lembra da reunião do dia 15, 30 minutos antes'), 30);
igual('antecedência "meia hora antes"', detectar_antecedencia('avisa meia hora antes'), 30);
igual('antecedência "1h antes"', detectar_antecedencia('me lembra 1h antes'), 60);
igual('antecedência "um dia antes"', detectar_antecedencia('avisa a consulta um dia antes'), 1440);
igual('antecedência "na véspera"', detectar_antecedencia('me avisa na véspera'), 1440);
igual('sem antecedência', detectar_antecedencia('me lembra da reunião às 15h'), null);
igual('hora "às 15h"', hora_da_frase('tenho dentista dia 27 às 15h'), '15:00');
igual('hora "15h30"', hora_da_frase('reunião 15h30'), '15:30');
igual('hora "19 horas"', hora_da_frase('consulta dia 28 as 19 horas'), '19:00');
igual('"2 horas antes" não é horário', hora_da_frase(sem_trecho_antecedencia('reunião amanhã, 2 horas antes')), null);
verdadeiro('consulta é compromisso', eh_compromisso('consulta no dentista'));
verdadeiro('reunião é compromisso', eh_compromisso('reunião'));
verdadeiro('tomar remédio não é compromisso', !eh_compromisso('tomar o remédio'));
verdadeiro('"tenho X dia Y" é compromisso', eh_compromisso('buscar a Ana', true));
igual('rótulo 90 min', rotulo_antecedencia(90), '1 h 30 min antes');
igual('hora "as 10" (sem h)', hora_da_frase('tenho consulta dia 22 as 10'), '10:00');
igual('hora "me avisa as 7"', hora_da_frase('me avisa as 7'), '07:00');
igual('hora "às 3 da tarde"', hora_da_frase('reunião amanhã às 3 da tarde'), '15:00');
igual('"as 2 reuniões" não é hora', hora_da_frase('apaga as 2 reuniões'), null);
igual('hora "3h da tarde"', hora_da_frase('reunião amanhã 3h da tarde'), '15:00');
igual('hora "15:45"', hora_da_frase('consulta às 15:45'), '15:45');
igual('"tenho consulta dia N as 10" não é gasto de 10', corrigir_compromisso('log_expense', ['valor' => 10], 'tenho consulta dia ' . date('j', strtotime('+1 day')) . ' as 10'),
    ['set_reminder', ['intent' => 'set_reminder', 'texto_lembrete' => 'consulta', 'disparar_em' => date('Y-m-d', strtotime('+1 day')) . ' 10:00']]);
// "altera a consulta para 10/01": a IA inventa 09:00 — vira "só o dia mudou" (mantém a hora do lembrete)
$ed = corrigir_edicao_lembrete(0, 'editar_lembrete', ['termo' => 'consulta', 'disparar_em_novo' => '2030-01-10 09:00'], 'altera a consulta para 10/01')[1];
verdadeiro('edição só de data não inventa 09:00', ($ed['nova_data_dia'] ?? null) === '2030-01-10' && empty($ed['disparar_em_novo']));
$ed = corrigir_edicao_lembrete(0, 'editar_lembrete', ['termo' => 'consulta', 'disparar_em_novo' => '2030-01-10 09:00'], 'altera a consulta para 10/01 às 9h')[1];
igual('edição com hora dita mantém a hora nova', $ed['disparar_em_novo'] ?? null, '2030-01-10 09:00');
// Levantar dinheiro não é gasto (bug: "Levantei 20 euros do millenium" virou gasto "levantei")
igual('levantamento com conta', atalho_levantamento('Levantei 20 euros do millenium'), ['intent' => 'levantamento', 'valor' => 20.0, 'moeda' => 'EUR', 'conta_origem' => 'millenium']);
igual('levantamento no multibanco (sem conta)', atalho_levantamento('levantei 50 no multibanco'), ['intent' => 'levantamento', 'valor' => 50.0]);
igual('"saquei 100 reais da nubank"', atalho_levantamento('saquei 100 reais da nubank'), ['intent' => 'levantamento', 'valor' => 100.0, 'moeda' => 'BRL', 'conta_origem' => 'nubank']);
igual('gasto normal não é levantamento', atalho_levantamento('gastei 20 no mercado'), null);
// "preciso pagar o cartão dia 5" não é "todo mês" só porque a IA quis: vira data única + pergunta
$r = corrigir_datas('set_reminder', ['texto_lembrete' => 'pagar cartão de crédito', 'recorrente' => 'mensal', 'dia_mes' => 5], 'Preciso pagar cartão de crédito dia 5');
verdadeiro('sem "todo mês" na frase: não é recorrente e pergunta', empty($r['recorrente']) && empty($r['dia_mes']) && !empty($r['perguntar_mensal']) && (int) substr($r['disparar_em'], 8, 2) === 5);
$r = corrigir_datas('set_reminder', ['texto_lembrete' => 'pagar o aluguel', 'recorrente' => 'mensal', 'dia_mes' => 5], 'me lembra de pagar o aluguel todo dia 5');
verdadeiro('com "todo dia 5" continua mensal', ($r['recorrente'] ?? null) === 'mensal' && empty($r['perguntar_mensal']));
$r = corrigir_datas('set_reminder', ['texto_lembrete' => 'pagar o cartão', 'recorrente' => 'mensal', 'dia_mes' => 10], 'o cartão vence dia 10');
verdadeiro('"vence dia 10" continua mensal', ($r['recorrente'] ?? null) === 'mensal');
igual('nome da moeda', nome_moeda('BRL'), 'real (R$)');
// Listas: nome e itens
igual('nome "a lista de compras"', nome_lista('a lista de compras'), 'compras');
igual('nome "supermercado" = compras', nome_lista('supermercado'), 'compras');
igual('nome "lista de filmes"', nome_lista('lista de filmes'), 'filmes');
igual('nome "afazeres" = tarefas', nome_lista('afazeres'), 'tarefas');
igual('itens "leite, pão e 2 dúzias de ovos"', itens_do_texto('leite, pão e 2 dúzias de ovos'), ['leite', 'pão', '2 dúzias de ovos']);
igual('itens sem artigo', itens_do_texto('o café e a manteiga'), ['café', 'manteiga']);
igual('pt-PT: "adiciona leite e pão à lista"', atalho_lista(['id' => 0], 'adiciona leite e pão à lista'), ['intent' => 'lista_adicionar', 'itens' => ['leite', 'pão']]);
igual('"lista de lembretes" não é lista do usuário', atalho_lista(['id' => 0], 'lista de lembretes'), null);
igual('"cria uma lista de compras"', atalho_lista(['id' => 0], 'cria uma lista de compras'), ['intent' => 'lista_criar', 'lista' => 'compras']);
igual('"cria um checklist que preciso seguir" pergunta o nome', atalho_lista(['id' => 0], 'cria um checklist que preciso seguir'), ['intent' => 'lista_criar']);
igual('"cira uma lista" (erro de digitação)', atalho_lista(['id' => 0], 'cira uma lista'), ['intent' => 'lista_criar']);
igual('"cria uma lista pra eu lembrar" não vira nome', atalho_lista(['id' => 0], 'cria uma lista pra eu lembrar'), ['intent' => 'lista_criar']);
igual('itens com verbo = tarefas', lista_pelos_itens(['tomar banho', 'lavar roupas', 'lavar louça', 'buscar o zion']), 'tarefas');
igual('itens de mercado = compras', lista_pelos_itens(['açúcar', 'leite', 'papel higiênico']), 'compras');
// Resposta a "quando?" sem IA (bug: "dia 03" -> "Ainda não entendi quando")
$dia3 = data_da_frase('dia 3');
igual('quando: "dia 03"', quando_da_frase('dia 03'), "$dia3 09:00");
igual('quando: "dia 3 as 15h"', quando_da_frase('dia 3 as 15h'), "$dia3 15:00");
igual('quando: "amanhã às 8h"', quando_da_frase('amanhã às 8h'), date('Y-m-d', strtotime('+1 day')) . ' 08:00');
igual('quando: "às 18h" = hoje', quando_da_frase('às 18h'), date('Y-m-d') . ' 18:00');
igual('quando: "28/10 às 9h30"', quando_da_frase('28/10 às 9h30'), data_da_frase('28/10') . ' 09:30');
igual('quando: texto sem data', quando_da_frase('reunião com o chefe'), null);
// Fundador: só enquanto a assinatura estiver ativa (decisão de 01/10/2026)
$em = fn($dias) => date('Y-m-d H:i:s', strtotime("$dias days"));
verdadeiro('fundador com mensal ativo mantém o preço', fundador_ativo(['fundador' => 1, 'plus_tipo' => 'mensal', 'plus_ate' => $em('+10')]));
verdadeiro('fundador que venceu ontem ainda tem folga', fundador_ativo(['fundador' => 1, 'plus_tipo' => 'mensal', 'plus_ate' => $em('-1')]));
verdadeiro('fundador que cancelou e venceu há 10 dias perde', !fundador_ativo(['fundador' => 1, 'plus_tipo' => 'mensal', 'plus_ate' => $em('-10')]));
verdadeiro('ex-fundador não pega vaga nova', !pode_virar_fundador(['id' => 0, 'fundador' => 1]));
$ex = ['id' => 0, 'fundador' => 1, 'plus_tipo' => 'mensal', 'plus_ate' => $em('-30'), 'regiao' => 'BR'];
igual('ex-fundador volta pagando o preço normal', preco_plus($ex, 'mensal')[0], PRECOS_STARS['BR']['mensal']);
$ativo = ['id' => 0, 'fundador' => 1, 'plus_tipo' => 'mensal', 'plus_ate' => $em('+5'), 'regiao' => 'PT'];
igual('fundador ativo renova/compra anual com preço de fundador', preco_plus($ativo, 'anual')[0], PRECOS_STARS['PT']['fundador_anual']);
[$ok] = validar_pre_checkout(['id' => 7, 'fundador' => 1, 'plus_tipo' => 'mensal', 'plus_ate' => $em('-30')], ['invoice_payload' => 'plus|mensal|7|1', 'currency' => 'XTR']);
verdadeiro('link antigo de fundador é recusado pra quem perdeu o preço', !$ok);
// Resposta do Telegram: só "ok" conta como entregue; 4xx (menos 429) é erro permanente; rede/429/5xx tenta de novo
verdadeiro('telegram ok', telegram_ok('{"ok":true,"result":{}}'));
verdadeiro('telegram falhou', !telegram_ok('{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}'));
verdadeiro('erro de rede não é ok', !telegram_ok('Operation timed out after 15000 milliseconds'));
verdadeiro('bot bloqueado é permanente', telegram_erro_permanente('{"ok":false,"error_code":403}'));
verdadeiro('429 não é permanente', !telegram_erro_permanente('{"ok":false,"error_code":429}'));
verdadeiro('erro de rede não é permanente', !telegram_erro_permanente('Operation timed out'));
// Idempotência do webhook: o mesmo update_id só passa uma vez
$uid_teste = 9000000000000000 + random_int(1, 999999);
verdadeiro('update novo é processado', !ja_processado_update($uid_teste));
verdadeiro('o mesmo update de novo é ignorado', ja_processado_update($uid_teste));
get_db()->prepare("DELETE FROM updates_processados WHERE update_id = ?")->execute([$uid_teste]);
// Banco conhecido não precisa de "O que é millenium?"
foreach (['millenium', 'Millennium BCP', 'wise', 'Revolut', 'nubank', 'caixa', 'dinheiro'] as $b) verdadeiro("banco conhecido: $b", eh_banco_conhecido($b));
foreach (['joão', 'cofre da sala', 'vanessa'] as $b) verdadeiro("não é banco conhecido: $b", !eh_banco_conhecido($b));
// "Detalha" depende do pedido anterior
igual('detalha depois do saldo', atalho_detalha(['ultimo_intent' => 'saldo'], 'Detalha'), ['intent' => 'saldo_por_conta']);
igual('detalha depois de quanto gastei', atalho_detalha(['ultimo_intent' => 'query_spending'], 'detalha'), ['intent' => 'list_expenses', 'periodo' => 'mes']);
igual('detalha sem contexto vai pra IA', atalho_detalha(['ultimo_intent' => null], 'detalha'), null);
igual('"detalha contas" não é o atalho', atalho_detalha(['ultimo_intent' => 'saldo'], 'detalha contas'), null);
// "Amanhã não, hoje"
verdadeiro('"Amanhã não, hoje" é correção', eh_correcao_curta('Amanhã não, hoje'));
verdadeiro('"é 30/10" é correção', eh_correcao_curta('é 30/10'));
verdadeiro('"gastei 20 hoje" não é correção', !eh_correcao_curta('gastei 20 hoje'));
igual('"Amanhã não, hoje" = hoje', dia_relativo_da_frase('Amanhã não, hoje'), date('Y-m-d'));
igual('"hoje não, amanhã" = amanhã', dia_relativo_da_frase('hoje não, amanhã'), date('Y-m-d', strtotime('+1 day')));
igual('resposta "às 18h" no dia guardado', hora_no_dia_guardado(['dia' => '2030-01-10'], 'hoje às 18h'), '2030-01-10 18:00');
igual('resposta "21" no dia guardado', hora_no_dia_guardado(['dia' => '2030-01-10'], '21'), '2030-01-10 21:00');
igual('sem dia guardado não decide', hora_no_dia_guardado([], 'às 18h'), null);
verdadeiro('"hoje" sem hora marca pra perguntar', corrigir_datas('set_reminder', ['disparar_em' => date('Y-m-d') . ' 09:00'], 'me lembra de ver o filme, hoje')['hoje_sem_hora']);
verdadeiro('"hoje às 20h" não pergunta', !corrigir_datas('set_reminder', ['disparar_em' => date('Y-m-d') . ' 20:00'], 'me lembra de ver o filme hoje às 20h')['hoje_sem_hora']);
// A hora da frase só substitui a da IA quando ela pôs o padrão 09:00
igual('"as 10" corrige o 09:00 da IA', substr(corrigir_datas('set_reminder', ['disparar_em' => '2030-01-10 09:00'], 'consulta dia 10 as 10')['disparar_em'], 11, 5), '10:00');
igual('hora que a IA entendeu fica', substr(corrigir_datas('set_reminder', ['disparar_em' => '2030-01-10 12:30'], 'almoço dia 10 às 12h e meia')['disparar_em'], 11, 5), '12:30');
verdadeiro('"reunão" (erro) é compromisso', eh_compromisso('minha reunão'));
igual('"de hoje" = qual lembrete', dia_referido('a reunião de hoje, altera a data pra 30/10'), date('Y-m-d'));
igual('"do dia 22" = dia do mês', dia_referido('apaga a consulta do dia 22'), 22);
igual('data nova sem a referência', data_da_frase(sem_dia_referido('altera a reunião do dia 15 para o dia 20')), data_da_frase('dia 20'));
igual('rótulo 1440', rotulo_antecedencia(1440), '1 dia antes');
igual('atalho "o prazo do X é dezembro"', atalho_sem_ia('o prazo do calção do novo quarto é dezembro'), ['intent' => 'editar_meta', 'meta' => 'calcao do novo quarto', 'prazo_novo' => interpretar_prazo('dezembro')]);
igual('atalho "o prazo do X é 15 dias"', atalho_sem_ia('o prazo do calção do novo quarto é 15 dias')['prazo_novo'] ?? null, date('Y-m-d', strtotime('+15 days')));

// Lembrete único num dia que já passou vai pro mesmo dia do mês que vem (bug: "consulta dia 28" dito no dia 29 disparou na hora)
$ontem = date('Y-m-d', strtotime('-1 day')) . ' 19:00';
$esperado = primeira_ocorrencia_mensal((int) date('j', strtotime($ontem)), '19:00:00');
igual('lembrete de ontem vai pro mês que vem', ajustar_para_futuro($ontem, null), $esperado);
verdadeiro('... e fica no futuro', strtotime($esperado) > time());
// Datas ditas na frase de edição
$d = date('Y-m-d', strtotime('+10 days'));
igual('data "dd/mm" da frase', data_da_frase('altera, é ' . date('d/m', strtotime($d)) . '.. hoje já é outro dia'), $d);
igual('"dia N" de hoje é hoje', data_da_frase('altera a consulta para o dia ' . date('j')), date('Y-m-d'));
igual('"dia N" que passou é o do mês que vem', data_da_frase('muda para o dia ' . date('j', strtotime('-1 day'))), substr($esperado, 0, 10));
igual('sem data na frase', data_da_frase('altera consulta'), null);
igual('editar_lembrete sem data pega a da frase', corrigir_edicao_lembrete(0, 'editar_lembrete', ['termo' => 'consulta'], 'altera a consulta para ' . date('d/m', strtotime($d)))[1]['nova_data_dia'] ?? null, $d);

// "tenho consulta dia N às 19 horas" é compromisso, não gasto de N (a IA anotou "consulta 28,00 €")
$amanha = date('Y-m-d', strtotime('+1 day'));
igual('compromisso vira lembrete', corrigir_compromisso('log_expense', ['intent' => 'log_expense', 'valor' => 28], 'tenho consulta dia ' . date('j', strtotime($amanha)) . ' às 19 horas'),
    ['set_reminder', ['intent' => 'set_reminder', 'texto_lembrete' => 'consulta', 'disparar_em' => "$amanha 19:00"]]);
igual('com valor de verdade continua gasto', corrigir_compromisso('log_expense', ['valor' => 50], 'tenho consulta dia 5 de 50 euros')[0], 'log_expense');

// Lembrete mensal no dia 31 (bug: "+1 month" do PHP transforma 31/01 em 03/03)
igual('31/01 → fevereiro', avancar_recorrencia('2026-01-31 09:00:00', 'mensal', null, 31), '2026-02-28 09:00:00');
igual('28/02 → março volta pro 31', avancar_recorrencia('2026-02-28 09:00:00', 'mensal', null, 31), '2026-03-31 09:00:00');
igual('semanal', avancar_recorrencia('2026-09-28 10:00:00', 'semanal'), '2026-10-05 10:00:00');
verdadeiro('lembrete nunca no passado', strtotime(ajustar_para_futuro(date('Y-m-d 00:01:00'), 'diario')) > time());

// Limpeza do JSON da IA (bugs: "intentTracing", "intent μεγά", campo 'modo' descartado)
igual('chave suja', limpar_chaves_json(['intent_dual' => 'x', 'valor_novoXYZ' => 5, 'lixo' => 1]), ['intent' => 'x', 'valor_novo' => 5]);
igual('campo modo sobrevive', limpar_chaves_json(['intent' => 'definir_modo_contas', 'modo' => 'simples'])['modo'] ?? null, 'simples');
igual('palavra da IA corrigida', corrigir_palavras_pela_mensagem(['texto_lembrete' => 'pagar o ginásico'], 'pago 35 de ginásio')['texto_lembrete'], 'pagar o ginásio');

// Formatação
igual('dinheiro negativo', formatar_dinheiro(-50, 'EUR'), '-50,00 €');
igual('real no padrão BR', formatar_dinheiro(1255, 'BRL'), 'R$ 1.255,00');
igual('euro no padrão PT', formatar_dinheiro(87, 'EUR'), '87,00 €');
igual('ml', formatar_ml(750), '750 ml');
igual('litros', formatar_ml(1750), '1,75 L');
igual('extrair renomeação', extrair_renomeacao_categoria('Altera a categoria perfeito'), ['perfeito', null]);
igual('normalizar "sem categoria"', normalizar_categoria('Sem categoria'), 'pendente');
igual('descrição da conta', descricao_da_conta('pagar o seguro do carro'), 'seguro do carro');
igual('conta com artigo', conta_com_artigo('pagar a internet'), 'a internet');
verdadeiro('lembrete de água', eh_lembrete_agua('tomar água') && !eh_lembrete_agua('pagar a internet'));

// "desse gasto" sem a IA marcar "ultimo" (bug: variação da IA fazia perguntar "qual transação?")
$vazio = ['valor' => null, 'termo' => null, 'categoria' => null, 'tipo' => null, 'ultimo' => false];
igual('"desse gasto" vira o último', completar_alvo_pelo_contexto(['aguardando_transacao_id' => null], $vazio, 'altera a categoria desse gasto')['ultimo'], true);
igual('gasto esperando categoria é o "esse"', completar_alvo_pelo_contexto(['aguardando_transacao_id' => 42], $vazio, 'altera a categoria')['id'], 42);
igual('alvo já identificado não muda', completar_alvo_pelo_contexto(['aguardando_transacao_id' => 42], ['termo' => 'uber'] + $vazio, 'esse uber')['termo'], 'uber');

// "altera a categoria desse gasto" classificado como renomear categoria vai pra edição do gasto (variação da IA)
$r = handle_renomear_categoria(['id' => 0, 'aguardando_transacao_id' => null], ['categoria_antiga' => 'desse gasto'], 'altera a categoria desse gasto');
verdadeiro('"desse gasto" não vira nome de categoria', !str_contains($r, 'vira o quê'), $r);

// "tenho X na conta" é saldo mesmo se a IA disser gasto (e "cartão de crédito 700" continua gasto)
igual('"tenho 700 no cartão de crédito" vira saldo', corrigir_saldo_inicial('log_expense', ['valor' => 700], 'tenho 700 no cartão de crédito'),
    ['saldo_inicial', ['intent' => 'saldo_inicial', 'valor' => 700, 'conta' => 'cartão de crédito']]);
igual('"cartão de crédito 700" continua gasto', corrigir_saldo_inicial('log_expense', ['valor' => 700], 'cartão de crédito 700')[0], 'log_expense');
igual('"tenho que pagar 50" não vira saldo', corrigir_saldo_inicial('log_expense', ['valor' => 50], 'tenho que pagar 50 de luz')[0], 'log_expense');

// "guardei 100" quando a IA não entende
igual('"guardei 100" vira aporte', corrigir_aporte_meta('unknown', [], 'guardei 100'), ['aporte_meta', ['intent' => 'aporte_meta', 'valor' => 100.0]]);
igual('"separei 50 pra viagem"', corrigir_aporte_meta('unknown', [], 'separei 50 pra viagem')[1]['meta'], 'viagem');
igual('"tirei 30 da reserva" é retirada', corrigir_aporte_meta('unknown', [], 'tirei 30 da reserva')[1]['retirar'] ?? null, true);
igual('IA entendeu: não mexe', corrigir_aporte_meta('log_expense', ['valor' => 5], 'guardei 5')[0], 'log_expense');

// Encerrar ("pronto") e categorias com acento
foreach (['Pronto', 'pronto!', 'é isso', 'Só foram estas as despesas', 'foram essas', 'acabei', 'como finaliza?'] as $t) verdadeiro("finalização: '$t'", parece_finalizacao($t));
foreach (['pronto socorro 50', 'mercado', 'isso mesmo'] as $t) verdadeiro("não é finalização: '$t'", !parece_finalizacao($t));
verdadeiro('"Pronto" não é categoria', !parece_categoria_valida('Pronto'));
igual('saúde digitada = botão Saúde', normalizar_categoria('Saúde'), 'saude');
igual('saude aparece com acento', rotulo_categoria('saude'), 'saúde');

// Link do painel: botão com token de 64 hex, e só o hash fica no banco
$pdo_teste = get_db();
$pdo_teste->beginTransaction();
gerar_link_painel();
$url_painel = botoes_resposta(null, true)[0][0][1] ?? '';
verdadeiro('link do painel com token', (bool) preg_match('#^https://zagora\.space/admin/\?t=[0-9a-f]{64}$#', $url_painel), $url_painel);
$token_teste = substr($url_painel, -64);
$stmt = $pdo_teste->prepare("SELECT COUNT(*) FROM admin_tokens WHERE token_hash = ?");
$stmt->execute([hash('sha256', $token_teste)]);
igual('banco guarda só o hash do token', (int) $stmt->fetchColumn(), 1);
$pdo_teste->rollBack();

// Categoria pela descrição exata (quando a IA esquece)
igual('"restaurante" → restaurante', categoria_por_palavra('restaurante'), 'restaurante');
igual('"no restaurante" → restaurante', categoria_por_palavra('no restaurante'), 'restaurante');
igual('"Farmácia" → saúde', categoria_por_palavra('Farmácia'), 'saude');
igual('"restaurante do zé" não adivinha', categoria_por_palavra('restaurante do zé'), null);

// Atalhos sem IA: só a frase exata
igual('atalho "Saldo"', atalho_sem_ia('Saldo'), ['intent' => 'saldo']);
igual('atalho "O que falta pagar?"', atalho_sem_ia('O que falta pagar?'), ['intent' => 'contas_pendentes']);
igual('atalho "meus gastos" = do mês', atalho_sem_ia('meus gastos'), ['intent' => 'list_expenses', 'periodo' => 'mes']);
igual('atalho com acento "Orçamento"', atalho_sem_ia('Orçamento'), ['intent' => 'listar_orcamentos']);
igual('frase com mais palavras vai pra IA', atalho_sem_ia('meus gastos de agosto'), null);
igual('gasto nunca é atalho', atalho_sem_ia('mercado 240'), null);

// Plano / pagamento
igual('idioma pt-br → BR', regiao_por_idioma('pt-br'), 'BR');
igual('idioma pt → PT', regiao_por_idioma('pt'), 'PT');
igual('idioma en → sem região', regiao_por_idioma('en'), null);
igual('payload lido', ler_payload_pagamento('plus|anual|42|1'), ['tipo' => 'anual', 'usuario_id' => 42, 'fundador' => true]);
igual('payload estranho recusado', ler_payload_pagamento('outra|coisa'), null);
igual('cortesia é Plus', status_plus(['plus_ate' => '2099-12-31 00:00:00', 'plus_tipo' => 'cortesia', 'trial_ate' => null])['tipo'], 'cortesia');
igual('teste vencido = Grátis', status_plus(['plus_ate' => null, 'plus_tipo' => null, 'trial_ate' => '2020-01-01 00:00:00'])['ativo'], false);
$assinatura_ativa = ['fundador' => 1, 'plus_tipo' => 'mensal', 'plus_ate' => date('Y-m-d H:i:s', strtotime('+10 days'))];
igual('preço PT fundador (assinatura ativa)', preco_plus(['regiao' => 'PT'] + $assinatura_ativa, 'mensal')[0], 250);
igual('preço BR anual fundador (assinatura ativa)', preco_plus(['regiao' => 'BR'] + $assinatura_ativa, 'anual')[0], 1000);
igual('botão de link vira url', montar_teclado([[['Pagar', 'https://t.me/$abc']]])['inline_keyboard'][0][0], ['text' => 'Pagar', 'url' => 'https://t.me/$abc']);

// Metas: prazo e conta de meses
igual('prazo no passado recusado', validar_prazo_meta('2020-01-01')[0], null);
igual('prazo inválido recusado', validar_prazo_meta('2026-02-30')[0], null);
igual('prazo futuro aceito', validar_prazo_meta(date('Y-m-d', strtotime('+2 months')))[0], date('Y-m-d', strtotime('+2 months')));
verdadeiro('meses até daqui 6 meses ≈ 6', abs(meses_ate(date('Y-m-d', strtotime('+6 months'))) - 6) < 0.2);
verdadeiro('meses nunca perto de zero', meses_ate(date('Y-m-d', strtotime('+1 day'))) >= 0.5);

// Backup do banco: completo e legível (numa pasta temporária)
$pasta = sys_get_temp_dir() . '/zagora_backup_teste_' . getmypid();
$b = fazer_backup_banco($pasta);
$sql = gzdecode(file_get_contents($b['arquivo']));
verdadeiro('backup gerou arquivo com todas as tabelas', $b['tabelas'] >= 10 && str_contains($sql, 'CREATE TABLE `transacoes`') && str_contains($sql, 'CREATE TABLE `usuarios`'));
array_map('unlink', glob("$pasta/*") ?: []);
@rmdir($pasta);
