<?php
// Pelo terminal no servidor (NUNCA no public_html), a partir de uma cópia do projeto com o config.php:
//   php ferramentas/list_models.php
// Mostra a lista exata de modelos que a chave da Cerebras pode usar.

$config = require dirname(__DIR__) . '/config.php';

$ch = curl_init('https://api.cerebras.ai/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $config['cerebras_api_key'],
    ],
]);
$response = curl_exec($ch);
curl_close($ch);

echo $response, "\n";
