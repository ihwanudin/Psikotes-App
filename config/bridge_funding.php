<?php

declare(strict_types=1);

return [
    // Per-grant amount cap in IDR. null (default) = unlimited, per the
    // owner's explicit decision (item 18) that this may be set later
    // without a migration.
    'max_amount' => env('BRIDGE_FUNDING_MAX_AMOUNT') !== null
        ? (int) env('BRIDGE_FUNDING_MAX_AMOUNT')
        : null,
];
