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
    'invitation_ttl_hours' => (int) env('ASSESSMENT_INVITATION_TTL_HOURS', 168),
];
