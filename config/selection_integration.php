<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('SELECTION_INTEGRATION_ENABLED', false),
    'client_id' => env('SELECTION_INTEGRATION_CLIENT_ID'),
    'client_secret' => env('SELECTION_INTEGRATION_CLIENT_SECRET'),
    'branch_ref' => env('SELECTION_INTEGRATION_BRANCH_REF'),
    'intended_field' => env('SELECTION_INTEGRATION_INTENDED_FIELD', 'UMUM'),
    'test_types' => array_values(array_filter(array_map(
        static fn (string $value): string => strtolower(trim($value)),
        explode(',', (string) env('SELECTION_INTEGRATION_TEST_TYPES', 'ist')),
    ))),
    'signature_tolerance_seconds' => (int) env('SELECTION_INTEGRATION_SIGNATURE_TOLERANCE_SECONDS', 300),
    'selection_base_url' => env('SELECTION_APP_BASE_URL'),
    'timeout_seconds' => (int) env('SELECTION_APP_TIMEOUT_SECONDS', 10),
    'allow_insecure_local_http' => (bool) env('SELECTION_APP_ALLOW_INSECURE_LOCAL_HTTP', false),
];
