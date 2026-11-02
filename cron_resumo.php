<?php
// Chamado automaticamente pela Hostinger (Cron Job) uma vez por dia.
// Manda resumo semanal toda segunda-feira e resumo mensal todo dia 1, só pra quem teve movimento no período.
// O conteúdo do resumo é montado em montar_resumo() (handlers.php) — o mesmo que o usuário recebe quando pede "resumo da semana".

date_default_timezone_set('Europe/Lisbon');

require_once __DIR__ . '/handlers.php'; // montar_resumo() (e db.php junto)
require_once __DIR__ . '/backup_banco.php';

function log_resumo($mensagem) {
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    @file_put_contents(__DIR__ . '/cron_resumo_debug.log', $linha, FILE_APPEND);
}

// Manda um resumo (se teve movimento) com os botões que ele pediu.
// Devolve 'enviado', 'vazio' (sem movimento no período — nada a mandar) ou 'falhou' (o Telegram não aceitou; tenta amanhã).
function enviar_resumo($config, $usuario, $tipo) {
    botoes_resposta(null, true); // garante que não sobra botão do usuário anterior
    $texto = montar_resumo($usuario, $tipo);
    $botoes = botoes_resposta(null, true);
    if (!$texto) {
        return 'vazio';
    }
    $resultado = enviar_telegram($config, $usuario['telegram_id'], $texto, $botoes);
    if (telegram_ok($resultado)) return 'enviado';
    // Bot bloqueado / conversa apagada: não adianta tentar de novo amanhã
    return telegram_erro_permanente($resultado) ? 'vazio' : 'falhou';
}

try {
    $config = require __DIR__ . '/config.php';
    $pdo = get_db();

    $hoje = date('Y-m-d');
    $dia_semana = date('N'); // 1 = segunda ... 7 = domingo
    $dia_mes = date('j');

    log_resumo("Cron de resumo rodou em {$hoje} (dia da semana: {$dia_semana}, dia do mês: {$dia_mes})");

    // Backup diário do banco (aproveita que esse cron roda 1x por dia) — falha no backup não impede os resumos
    try {
        $b = fazer_backup_banco();
        log_resumo($b['pulado'] ? "Backup do banco: o de hoje já existia" : sprintf("Backup do banco: %s (%d tabelas, %d linhas, %d KB, %d antigo(s) apagado(s))",
            basename($b['arquivo']), $b['tabelas'], $b['linhas'], round($b['bytes'] / 1024), $b['apagados']));
    } catch (Throwable $e) {
        log_resumo('ERRO no backup do banco: ' . $e->getMessage());
    }

    // Resumo do dia de ontem pro dono (usuários, pagamentos, reportes, erros)
    try {
        if (relatorio_diario_admin($config)) log_resumo('Relatório diário enviado pro dono');
    } catch (Throwable $e) {
        log_resumo('ERRO no relatório diário: ' . $e->getMessage());
    }

    // Avisos de fim do teste grátis / do anual (com os botões de assinar)
    try {
        $avisos = enviar_avisos_plano($config);
        if ($avisos) log_resumo("Avisos de plano enviados: {$avisos}");
    } catch (Throwable $e) {
        log_resumo('ERRO nos avisos de plano: ' . $e->getMessage());
    }

    // updates do Telegram já processados (proteção contra reenvio) só precisam ficar uns dias
    try {
        $pdo->prepare("DELETE FROM updates_processados WHERE criado_em < ?")->execute([date('Y-m-d H:i:s', strtotime('-7 days'))]);
    } catch (Throwable $e) {
        log_resumo('ERRO limpando updates_processados: ' . $e->getMessage());
    }

    $usuarios = $pdo->query("SELECT * FROM usuarios")->fetchAll();
    $segunda = date('Y-m-d', strtotime('monday this week'));
    $dia_1 = date('Y-m-01');

    foreach ($usuarios as $usuario) {
        // Resumo automático é recurso do Plus
        if (!tem_plus($usuario)) {
            continue;
        }
        // Erro com um usuário não pode impedir o resumo dos outros
        try {
            // Resumo semanal: toda segunda-feira, sobre a semana que acabou (seg a dom).
            // Se o envio falhou na segunda, tenta de novo na terça e na quarta (só quem já recebia resumo — quem virou
            // Plus no meio da semana espera a próxima segunda).
            $ultimo_sem = $usuario['ultimo_resumo_semanal'];
            if ($ultimo_sem !== $hoje && ($dia_semana == 1 || ($ultimo_sem && $ultimo_sem < $segunda && $dia_semana <= 3))) {
                $r = enviar_resumo($config, $usuario, 'semana_fechada');
                log_resumo("Resumo semanal do usuário {$usuario['id']}: {$r}");
                if ($r !== 'falhou') {
                    $pdo->prepare("UPDATE usuarios SET ultimo_resumo_semanal = ? WHERE id = ?")->execute([$hoje, $usuario['id']]);
                }
            }

            // Resumo mensal: todo dia 1, sobre o mês anterior completo (se falhar, tenta nos dias 2 e 3)
            $ultimo_mes = $usuario['ultimo_resumo_mensal'];
            if ($ultimo_mes !== $hoje && ($dia_mes == 1 || ($ultimo_mes && $ultimo_mes < $dia_1 && $dia_mes <= 3))) {
                $r = enviar_resumo($config, $usuario, 'mes_passado');
                log_resumo("Resumo mensal do usuário {$usuario['id']}: {$r}");
                if ($r !== 'falhou') {
                    $pdo->prepare("UPDATE usuarios SET ultimo_resumo_mensal = ? WHERE id = ?")->execute([$hoje, $usuario['id']]);
                }
            }
        } catch (Throwable $e) {
            log_resumo("ERRO no usuário {$usuario['id']}: " . $e->getMessage());
        }
    }

    echo "Resumo processado em {$hoje}\n";
} catch (Throwable $e) {
    log_resumo('ERRO: ' . $e->getMessage());
    echo "Erro: " . $e->getMessage() . "\n";
}
