<?php

return [
    'n8n' => [
        'url' => env('N8N_WEBHOOK_URL'),
        'token' => env('N8N_WEBHOOK_TOKEN'),
        'connect_timeout_seconds' => 3,
        'timeout_seconds' => 10,
    ],
];
