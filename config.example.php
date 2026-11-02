<?php
// Modelo do config.php — copie pra config.php e preencha. O config.php de verdade NUNCA vai pro git.
return [
    'telegram_token'   => '123456:ABC...',            // do @BotFather
    'cerebras_api_key' => 'csk-...',                  // cloud.cerebras.ai
    'cerebras_model'   => 'gpt-oss-120b',
    'db_host'          => 'localhost',
    'db_name'          => 'u000000000_zagora',
    'db_user'          => 'u000000000_zagora',
    'db_pass'          => '...',
    'webhook_secret'   => '...',                      // gerado no servidor; o mesmo registrado no setWebhook (ver ferramentas/setup_webhook.php)
];
