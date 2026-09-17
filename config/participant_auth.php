<?php

declare(strict_types=1);

return [
    'test_number_timezone' => env('TEST_NUMBER_TIMEZONE', 'Asia/Jakarta'),
    'jwt' => [
        'secret' => env('PARTICIPANT_JWT_SECRET', ''),
        'issuer' => env('APP_URL', 'http://localhost').'/participant-auth',
        'audience' => env('APP_URL', 'http://localhost').'/participant-api',
        'ttl_seconds' => 12 * 60 * 60,
        'clock_skew_seconds' => 30,
    ],
    'login_lockout' => [
        'first_lock_attempt' => 3,
        'failure_memory_seconds' => 24 * 60 * 60,
    ],
];
