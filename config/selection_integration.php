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
];
