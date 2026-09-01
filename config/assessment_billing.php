<?php

declare(strict_types=1);

return [
    // Operational batch limit only; prices remain in the package catalog.
    'max_items' => 100,
    'invoice_duration_hours' => 24,

    // Reconciliation remains inactive until command/scheduler wiring is reviewed.
    'invoice_reconciliation_batch_size' => 25,
    'invoice_reconciliation_scan_limit' => 100,
    'invoice_reconciliation_lease_seconds' => 60,
    'invoice_reconciliation_cooldown_seconds' => 300,
    'invoice_reconciliation_max_lookups' => 12,
];
