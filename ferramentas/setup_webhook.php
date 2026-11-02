<?php
// Uso único, pelo terminal no servidor (NUNCA no public_html), a partir de uma cópia do projeto com o config.php:
//   php ferramentas/setup_webhook.php
// Isso avisa o Telegram pra onde mandar as mensagens do bot, com o secret_token que o webhook.php confere.

$config = require dirname(__DIR__) . '/config.php';
$webhook_url = 'https://zagora.space/webhook.php';

$params = ['url' => $webhook_url];
if (!empty($config['webhook_secret'])) {
    $params['secret_token'] = $config['webhook_secret'];
}

$url = "https://api.telegram.org/bot{$config['telegram_token']}/setWebhook?" . http_build_query($params);
echo file_get_contents($url), "\n";
