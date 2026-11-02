<?php

// O servidor vem com o registro de erros do PHP desligado — sem isso, um aviso ou uma exceção com usuário real
// não deixava rastro nenhum. Vai pra php_erros.log (bloqueado pela web no .htaccess, como todo *.log);
// o relatório diário pro dono conta as linhas de ontem.
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_erros.log');

// Config lida uma vez só. Os testes passam uma versão com token falso ($substituir) pra nada ir pro Telegram de verdade.
function config_zagora($substituir = null) {
    static $config = null;
    if ($substituir !== null) {
        $config = $substituir;
    }
    return $config ??= require __DIR__ . '/config.php';
}

// Log de erros em debug.log (bloqueado pela web). Disponível em todo lugar — o cron não carrega o classifier.php.
function registrar_erro($mensagem) {
    @file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL, FILE_APPEND);
}

// Aviso urgente pro dono no Telegram, no máximo 1 a cada $intervalo segundos por $chave (pra não inundar)
function alerta_admin($chave, $texto, $intervalo = 7200) {
    $config = config_zagora();
    if (empty($config['admin_telegram_id'])) return;
    $marca = sys_get_temp_dir() . '/zagora_alerta_' . md5($chave);
    if (is_file($marca) && filemtime($marca) > time() - $intervalo) return;
    @touch($marca);
    enviar_telegram($config, $config['admin_telegram_id'], "🚨 Zágora: " . $texto);
}

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $config = require __DIR__ . '/config.php';
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function get_or_create_user($telegram_id, $nome) {
    $pdo = get_db();

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch();

    if (!$user) {
        // Todo usuário novo começa com 14 dias de Plus grátis
        $pdo->prepare("INSERT INTO usuarios (telegram_id, nome, trial_ate) VALUES (?, ?, ?)")
            ->execute([$telegram_id, $nome, date('Y-m-d H:i:s', strtotime('+14 days'))]);

        $stmt->execute([$telegram_id]);
        $user = $stmt->fetch();
    }

    return $user;
}

// Procura se esse usuário já ensinou a categoria desse estabelecimento antes
function buscar_categoria_conhecida($usuario_id, $nome_estabelecimento) {
    if (!$nome_estabelecimento) return null;

    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT categoria FROM estabelecimentos WHERE usuario_id = ? AND nome = ?");
    $stmt->execute([$usuario_id, strtolower(trim($nome_estabelecimento))]);
    $row = $stmt->fetch();
    return $row['categoria'] ?? null;
}

// Guarda a relação "nome do estabelecimento -> categoria" pra não perguntar de novo
function salvar_estabelecimento($usuario_id, $nome_estabelecimento, $categoria) {
    if (!$nome_estabelecimento) return;

    $pdo = get_db();
    $pdo->prepare("INSERT INTO estabelecimentos (usuario_id, nome, categoria) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE categoria = VALUES(categoria)")
        ->execute([$usuario_id, strtolower(trim($nome_estabelecimento)), $categoria]);
}

// Marca que estamos esperando o usuário responder qual a categoria de uma transação
function marcar_aguardando_categoria($usuario_id, $transacao_id, $nome_estabelecimento) {
    $pdo = get_db();
    // Também limpa o "estado" novo, pra nunca ter dois tipos de pendência ativos ao mesmo tempo
    $pdo->prepare("UPDATE usuarios SET aguardando_transacao_id = ?, aguardando_estabelecimento = ?, estado = NULL, estado_dados = NULL WHERE id = ?")
        ->execute([$transacao_id, $nome_estabelecimento, $usuario_id]);
}

function limpar_aguardando_categoria($usuario_id) {
    $pdo = get_db();
    $pdo->prepare("UPDATE usuarios SET aguardando_transacao_id = NULL, aguardando_estabelecimento = NULL, estado = NULL, estado_dados = NULL WHERE id = ?")
        ->execute([$usuario_id]);
}

// Guarda o id de cada mensagem que o BOT manda, pra dar pra apagar depois com /limpar
function registrar_mensagem_enviada($usuario_id, $message_id) {
    $pdo = get_db();
    $pdo->prepare("INSERT INTO mensagens (usuario_id, message_id) VALUES (?, ?)")
        ->execute([$usuario_id, $message_id]);
}

function listar_mensagens_enviadas($usuario_id) {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT message_id FROM mensagens WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    return array_column($stmt->fetchAll(), 'message_id');
}

function limpar_mensagens_enviadas($usuario_id) {
    $pdo = get_db();
    $pdo->prepare("DELETE FROM mensagens WHERE usuario_id = ?")->execute([$usuario_id]);
}

// Corrige variação de digitação (ex: "milenium" -> "millenium") comparando com contas que já existem,
// pra não criar uma conta fantasma por causa de um erro de digitação.
function normalizar_nome_conta($usuario_id, $nome_bruto) {
    $nome = strtolower(trim(limpar_nome_conta($nome_bruto)));
    if (!$nome) return $nome;

    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT nome FROM contas WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    $existentes = array_column($stmt->fetchAll(), 'nome');

    foreach ($existentes as $existente) {
        if ($existente === $nome) return $nome;
        similar_text($existente, $nome, $percentual);
        if ($percentual >= 80 || levenshtein($existente, $nome) <= 2) {
            return $existente; // é bem parecido, considera a mesma conta
        }
    }

    return $nome;
}

// Contas/bancos aprendidos por usuário (ex: "wise", "millennium")
function buscar_conta_conhecida($usuario_id, $nome) {
    if (!$nome) return false;
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT 1 FROM contas WHERE usuario_id = ? AND nome = ?");
    $stmt->execute([$usuario_id, strtolower(trim($nome))]);
    return (bool) $stmt->fetch();
}

// Salva a conta (já corrigindo pra nome parecido existente) e devolve o nome canônico usado
function salvar_conta($usuario_id, $nome) {
    if (!$nome) return null;
    $canonico = normalizar_nome_conta($usuario_id, $nome);
    $pdo = get_db();
    $pdo->prepare("INSERT IGNORE INTO contas (usuario_id, nome) VALUES (?, ?)")
        ->execute([$usuario_id, $canonico]);
    return $canonico;
}

// Estado genérico de conversa em várias etapas (ex: aguardando o valor de uma receita, aguardando a conta, etc.)
function salvar_estado($usuario_id, $estado, $dados) {
    $pdo = get_db();
    // Também limpa a pendência antiga de categoria, pelo mesmo motivo
    $pdo->prepare("UPDATE usuarios SET estado = ?, estado_dados = ?, aguardando_transacao_id = NULL, aguardando_estabelecimento = NULL WHERE id = ?")
        ->execute([$estado, json_encode($dados), $usuario_id]);
}

function limpar_estado($usuario_id) {
    $pdo = get_db();
    $pdo->prepare("UPDATE usuarios SET estado = NULL, estado_dados = NULL, aguardando_transacao_id = NULL, aguardando_estabelecimento = NULL WHERE id = ?")
        ->execute([$usuario_id]);
}

// Chama um método qualquer da API do Telegram (sendMessage, answerCallbackQuery, editMessageText...)
// De quem é a mensagem sendo processada agora (pra contar o uso da IA por usuário). Definido em processar_mensagem/callback.
function usuario_atual($id = null) {
    static $atual = null;
    if ($id !== null) $atual = (int) $id;
    return $atual;
}

// Preço da Cerebras (gpt-oss-120b) em US$ por milhão de tokens — conferir no painel deles se mudar
const IA_US_POR_MILHAO_ENTRADA = 0.35;
const IA_US_POR_MILHAO_SAIDA = 0.75;

// Soma uma chamada à IA (com os tokens que a Cerebras devolveu) no dia do usuário atual. Falha aqui nunca atrapalha o bot.
function registrar_uso_ia($resposta_ia) {
    $usuario_id = usuario_atual();
    if (!$usuario_id) return;
    $uso = is_array($resposta_ia) ? ($resposta_ia['usage'] ?? []) : [];
    try {
        get_db()->prepare("INSERT INTO uso_ia_diario (usuario_id, data, chamadas, tokens_entrada, tokens_saida) VALUES (?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE chamadas = chamadas + 1, tokens_entrada = tokens_entrada + VALUES(tokens_entrada), tokens_saida = tokens_saida + VALUES(tokens_saida)")
            ->execute([$usuario_id, date('Y-m-d'), (int) ($uso['prompt_tokens'] ?? 0), (int) ($uso['completion_tokens'] ?? 0)]);
    } catch (Throwable $e) {
        registrar_erro('uso_ia_diario: ' . $e->getMessage());
    }
}

// Idempotência do webhook: grava o update_id e diz se ele JÁ tinha sido processado (o Telegram reenvia quando
// não recebe resposta a tempo). Se o banco falhar aqui, deixa processar — melhor que perder a mensagem.
function ja_processado_update($update_id) {
    if (!is_numeric($update_id)) return false;
    try {
        $stmt = get_db()->prepare("INSERT IGNORE INTO updates_processados (update_id, criado_em) VALUES (?, ?)");
        $stmt->execute([(int) $update_id, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() === 0;
    } catch (Throwable $e) {
        registrar_erro('updates_processados: ' . $e->getMessage());
        return false;
    }
}

// Resposta do Telegram deu certo? ('ok' => true no JSON)
function telegram_ok($resposta) {
    $dados = is_string($resposta) ? json_decode($resposta, true) : $resposta;
    return !empty($dados['ok']);
}

// Erro que não adianta tentar de novo: bot bloqueado, conversa não existe, mensagem inválida (4xx menos 429)
function telegram_erro_permanente($resposta) {
    $dados = is_string($resposta) ? json_decode($resposta, true) : $resposta;
    $codigo = (int) ($dados['error_code'] ?? 0);
    return $codigo >= 400 && $codigo < 500 && $codigo !== 429;
}

function chamar_telegram($config, $metodo, $params) {
    $ch = curl_init("https://api.telegram.org/bot{$config['telegram_token']}/{$metodo}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resposta = curl_exec($ch);
    $erro = curl_error($ch);
    curl_close($ch);
    return $erro ?: $resposta;
}

// Manda uma mensagem no Telegram — usado pelo webhook e pelos crons (lembretes, resumo).
// $botoes: linhas de botões, cada botão ['texto', 'callback_data'] (ver montar_teclado)
function enviar_telegram($config, $chat_id, $texto, $botoes = null) {
    $params = ['chat_id' => $chat_id, 'text' => $texto];
    if ($botoes) {
        $params['reply_markup'] = montar_teclado($botoes);
    }
    return chamar_telegram($config, 'sendMessage', $params);
}

// Botão = ['texto', 'callback_data'] — ou ['texto', 'https://...'] pra botão que abre um link (ex: pagamento)
function montar_teclado($botoes) {
    return ['inline_keyboard' => array_map(
        fn($linha) => array_map(fn($b) => str_starts_with($b[1], 'https://')
            ? ['text' => $b[0], 'url' => $b[1]]
            : ['text' => $b[0], 'callback_data' => $b[1]], $linha),
        $botoes
    )];
}

// Botão que "digita" um texto pelo usuário: o clique cai no mesmo fluxo de uma mensagem normal.
// O Telegram limita callback_data a 64 bytes.
function botao_texto($rotulo, $texto) {
    return [$rotulo, mb_strcut('tx:' . $texto, 0, 64)];
}

// Canal lateral pros handlers pedirem botões na resposta sem mudar o tipo de retorno (continua string)
function botoes_resposta($definir = null, $limpar = false) {
    static $botoes = null;
    if ($limpar) {
        $atual = $botoes;
        $botoes = null;
        return $atual;
    }
    if ($definir !== null) {
        $botoes = $definir;
    }
    return $botoes;
}

// Data/hora (Y-m-d H:i:s) do dia $dia no mês de $ano-$mes; se o mês for mais curto, cai no último dia (31 -> 30, 28/29 em fevereiro)
function data_no_dia_do_mes($ano, $mes, $dia, $hora) {
    $ultimo = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
    return sprintf('%04d-%02d-%02d %s', $ano, $mes, min($dia, $ultimo), $hora);
}

// Um passo da recorrência a partir de $datahora. Mensal usa $dia_mes (e não "+1 month" do PHP,
// que transforma 31/01 em 03/03).
function avancar_recorrencia($datahora, $recorrente, $intervalo_horas = null, $dia_mes = null) {
    $ts = strtotime($datahora);
    switch ($recorrente) {
        case 'diario':
            return date('Y-m-d H:i:s', strtotime('+1 day', $ts));
        case 'semanal':
            return date('Y-m-d H:i:s', strtotime('+1 week', $ts));
        case 'horas':
            return date('Y-m-d H:i:s', strtotime('+' . max(1, (int) ($intervalo_horas ?: 1)) . ' hours', $ts));
        case 'mensal':
            $ano = (int) date('Y', $ts);
            $mes = (int) date('n', $ts) + 1;
            if ($mes > 12) { $mes = 1; $ano++; }
            return data_no_dia_do_mes($ano, $mes, $dia_mes ?: (int) date('j', $ts), date('H:i:s', $ts));
        default:
            return null;
    }
}

// Calcula a próxima vez que um lembrete recorrente deve disparar, sempre no futuro
// (se o cron ficou parado um tempo, avança quantas repetições forem necessárias em vez de disparar tudo de uma vez)
function proxima_ocorrencia($disparar_em, $recorrente, $intervalo_horas = null, $dia_mes = null) {
    $proximo = avancar_recorrencia($disparar_em, $recorrente, $intervalo_horas, $dia_mes);
    $tentativas = 0;
    while ($proximo && strtotime($proximo) <= time() && $tentativas < 400) {
        $proximo = avancar_recorrencia($proximo, $recorrente, $intervalo_horas, $dia_mes);
        $tentativas++;
    }
    return $proximo;
}

// Primeira ocorrência de um lembrete mensal no dia $dia_mes, a partir de agora (este mês se ainda não passou, senão o próximo)
function primeira_ocorrencia_mensal($dia_mes, $hora = '09:00:00') {
    $candidato = data_no_dia_do_mes((int) date('Y'), (int) date('n'), $dia_mes, $hora);
    if (strtotime($candidato) > time()) {
        return $candidato;
    }
    return avancar_recorrencia($candidato, 'mensal', null, $dia_mes);
}

// Acha um gasto "solto" (sem categoria) registrado há pouco tempo, pra poder categorizar
// mesmo quando o usuário responde livre, sem o bot ter deixado uma pergunta pendente de verdade.
// Só considera isso quando a resposta não tem número — se tiver, é bem mais provável que seja uma transação nova.
function buscar_gasto_pendente_recente($usuario_id, $texto = null) {
    if ($texto !== null && preg_match('/\d/', $texto)) {
        return null;
    }
    // Só vale se esse gasto solto ainda for a última coisa registrada — se depois dele veio outra
    // transação ou um lembrete, a conversa já mudou de assunto e um "perfeito" não é categoria.
    $pdo = get_db();
    $stmt = $pdo->prepare("
        SELECT t.id FROM transacoes t
        WHERE t.usuario_id = ? AND t.tipo = 'gasto' AND t.categoria = 'pendente'
          AND t.criado_em >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
          AND t.id = (SELECT MAX(id) FROM transacoes WHERE usuario_id = ?)
          AND NOT EXISTS (SELECT 1 FROM lembretes l WHERE l.usuario_id = ? AND l.criado_em > t.criado_em)
        LIMIT 1
    ");
    $stmt->execute([$usuario_id, $usuario_id, $usuario_id]);
    $row = $stmt->fetch();
    return $row['id'] ?? null;
}

// Limpa palavras soltas de preposição/artigo que o usuário às vezes inclui na resposta
// (ex: "foi revolut" -> "revolut"), pra não salvar a conta com nome errado.
function limpar_nome_conta($texto) {
    $texto = trim($texto, " .,!?\n\t");
    $padroes = [
        '/^foi\s+/i', '/^na\s+/i', '/^no\s+/i', '/^pela\s+/i', '/^pelo\s+/i',
        '/^com\s+o\s+cart[aã]o\s+d[oa]\s+/i', '/^com\s+o\s+cart[aã]o\s+/i',
        '/^com\s+a\s+/i', '/^com\s+o\s+/i', '/^com\s+/i',
        '/^cart[aã]o\s+d[oa]\s+/i', '/^cart[aã]o\s+/i',
        '/^conta\s+d[oa]\s+/i', '/^conta\s+/i',
        '/^d[oa]\s+/i', '/^em\s+/i',
    ];
    foreach ($padroes as $p) {
        $texto = preg_replace($p, '', $texto);
    }
    return trim($texto);
}

// Palavras que claramente não são uma categoria de verdade (evita salvar "não" como categoria)
function parece_categoria_valida($texto) {
    $texto = strtolower(trim($texto));
    $invalidas = ['nao', 'não', 'n', 'sim', 's', 'ya', 'yea', 'yeah', 'yes', 'y', 'oui', 'ok', 'okay', 'okey', 'certo', 'exato', 'isso', 'isso mesmo', 'aham', 'uhum', 'e', 'é', 'oi', 'ola', 'olá', 'que', 'ué', 'hm', 'hmm'];
    if (function_exists('parece_confirmacao') && parece_confirmacao($texto)) {
        return false; // "perfeito", "valeu", "show" etc. são reação, não categoria
    }
    if (function_exists('parece_finalizacao') && parece_finalizacao($texto)) {
        return false; // "pronto", "é isso" = terminou de lançar, não categoria
    }
    return $texto !== '' && !in_array($texto, $invalidas, true);
}
