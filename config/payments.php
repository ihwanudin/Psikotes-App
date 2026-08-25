<?php

return [
    'manual_proof_disk' => env('PAYMENT_PROOF_FILESYSTEM_DISK', 'payment-proofs'),
    'manual_proof_max_kilobytes' => 5_000,
    'manual_proof_temporary_url_minutes' => 15,
];
