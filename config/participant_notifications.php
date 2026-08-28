<?php

return [
    'n8n' => [
        'url' => env('N8N_WEBHOOK_URL'),
        'token' => env('N8N_WEBHOOK_TOKEN'),
        'allow_insecure_local_http' => env('N8N_ALLOW_INSECURE_LOCAL_HTTP', false),
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 10,
    ],
];
