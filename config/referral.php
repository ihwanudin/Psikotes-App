<?php

declare(strict_types=1);

return [
    'cookie_name' => env('REFERRAL_COOKIE_NAME', 'psikotes_referral'),
    'ttl_days' => (int) env('REFERRAL_TTL_DAYS', 30),
    'user_agent_max_length' => 512,
];
