<?php
// Este arquivo é chamado automaticamente pela Hostinger (Cron Job), não pelo usuário.
// Ele checa lembretes vencidos e manda a mensagem no Telegram.

date_default_timezone_set('Europe/Lisbon'); // mesmo fuso usado no webhook, pra não desalinhar o horário

require_once __DIR__ . '/handlers.php'; // montar_aviso_lembrete() (e db.php junto)

function log_cron($mensagem) {
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    @file_put_contents(__DIR__ . '/cron_debug.log', $linha, FILE_APPEND);
}

// proxima_ocorrencia() fica no db.php — o bot usa a mesma lógica pra montar a agenda

// Uma execução por vez: se a anterior ainda está rodando (Telegram lento), esta sai — senão o mesmo aviso iria duas vezes
$trava = fopen(sys_get_temp_dir() . '/zagora_cron_lembretes.lock', 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) {
    echo "Outra execução ainda está rodando\n";
    exit;
}

try {
    $config = require __DIR__ . '/config.php';
    $pdo = get_db();

    $agora = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT l.*, u.telegram_id
        FROM lembretes l
        JOIN usuarios u ON u.id = l.usuario_id
        WHERE l.status = 'pendente' AND DATE_SUB(l.disparar_em, INTERVAL COALESCE(l.antecedencia_min, 0) MINUTE) <= ?
    ");
    $stmt->execute([$agora]);
    $lembretes = $stmt->fetchAll();

    // Só registra no log quando tem algo pra disparar (antes eram 3 linhas por minuto, o log só crescia)
    if ($lembretes) {
        log_cron(count($lembretes) . " lembrete(s) encontrado(s) pra disparar (agora: {$agora})");
    }

    $enviados = 0;
    foreach ($lembretes as $lembrete) {
        [$texto, $botoes] = montar_aviso_lembrete($lembrete);
        $resultado = enviar_telegram($config, $lembrete['telegram_id'], $texto, $botoes);
        log_cron("Lembrete #{$lembrete['id']} -> Telegram respondeu: " . $resultado);

        // Só dá o aviso por entregue se o Telegram confirmou. Falha passageira (rede, 429, 5xx) fica pendente e
        // o próximo minuto tenta de novo — até 1 h depois da hora do aviso; aí desiste (e registra) pra não insistir pra sempre.
        // Erro permanente (bot bloqueado, conversa apagada) não adianta repetir: segue como se tivesse ido.
        if (!telegram_ok($resultado)) {
            $hora_aviso = strtotime($lembrete['disparar_em']) - 60 * (int) ($lembrete['antecedencia_min'] ?? 0);
            if (!telegram_erro_permanente($resultado) && time() - $hora_aviso < 3600) {
                log_cron("Lembrete #{$lembrete['id']}: envio falhou, tento de novo no próximo minuto");
                continue;
            }
            registrar_erro("Lembrete #{$lembrete['id']} não foi entregue: " . mb_substr((string) $resultado, 0, 300));
        } else {
            $enviados++;
        }

        $proximo = proxima_ocorrencia($lembrete['disparar_em'], $lembrete['recorrente'], $lembrete['intervalo_horas'], $lembrete['dia_mes']);

        if ($proximo) {
            // É recorrente: reagenda pra próxima vez, continua "pendente"
            $pdo->prepare("UPDATE lembretes SET disparar_em = ? WHERE id = ?")->execute([$proximo, $lembrete['id']]);
            log_cron("Lembrete #{$lembrete['id']} é recorrente ({$lembrete['recorrente']}), reagendado pra {$proximo}");
        } else {
            $pdo->prepare("UPDATE lembretes SET status = 'enviado', enviado_em = NOW() WHERE id = ?")
                ->execute([$lembrete['id']]);
        }
    }

    echo "{$enviados} de " . count($lembretes) . " lembrete(s) enviado(s) em {$agora}\n";
} catch (Throwable $e) {
    log_cron('ERRO: ' . $e->getMessage());
    echo "Erro: " . $e->getMessage() . "\n";
}
