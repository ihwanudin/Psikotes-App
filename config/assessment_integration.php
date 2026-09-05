<?php

declare(strict_types=1);

$credentials = json_decode((string) env('ASSESSMENT_INTEGRATION_CREDENTIALS_JSON', '{}'), true);

return [
    'signature_tolerance_seconds' => (int) env('ASSESSMENT_INTEGRATION_SIGNATURE_TOLERANCE_SECONDS', 300),
    'credentials' => is_array($credentials) ? $credentials : [],
    'metadata_keys' => ['cohortCode'],
    'checkout' => [
        'enabled' => false,
        'allow_legacy_funding_mapping' => false,
    ],
    // Internal issuer remains fail-closed until separately wired and activated.
    'checkout_handoff' => [
        'enabled' => false,
        'ttl_seconds' => 600,
    ],
    'checkout_session' => [
        'enabled' => false,
        'idle_minutes' => 30,
        'absolute_minutes' => 120,
        'terminal_retention_days' => 30,
        'http' => [
            'destination_origin' => 'https://psikotes.oncam.id',
            'trusted_exchange_origins' => [
                'https://seleksi.beasiswajepang.id',
                'https://seleksi.serbaindo.com',
            ],
            'exchange_per_minute' => 10,
            'hydrate_per_minute' => 60,
            'mutation_per_minute' => 10,
            // Production routes are registered but remain inert until both switches are reviewed.
            'confirmation' => [
                'enabled' => false,
                'writer_enabled' => false,
                'max_body_bytes' => 4096,
            ],
            // No production route is registered; both switches require a later reviewed wiring increment.
            'payment' => [
                'enabled' => false,
                'writer_enabled' => false,
                'max_body_bytes' => 256,
            ],
        ],
    ],
    'invitation_ttl_hours' => (int) env('ASSESSMENT_INVITATION_TTL_HOURS', 168),
];
