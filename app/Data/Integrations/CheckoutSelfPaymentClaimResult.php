<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Internal post-commit classification. The message ID is an issuance routing hint, never a provider permit. */
final readonly class CheckoutSelfPaymentClaimResult
{
    public function __construct(
        public string $state,
        public ?string $messageId,
    ) {
        if (($state === 'issuance_required' && (! is_string($messageId) || ! Str::isUlid($messageId)))
            || (in_array($state, ['pending', 'paid'], true) && $messageId !== null)
            || ! in_array($state, ['issuance_required', 'pending', 'paid'], true)) {
            throw new InvalidArgumentException('Invalid checkout payment claim result.');
        }
    }
}
