<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case BridgeFunded = 'bridge_funded';

    /**
     * Whether this status grants the participant the same session-start
     * access as a paying participant. Paid and BridgeFunded both do --
     * bridge funding means the holding company covers the cost and the
     * owner's decision (item 18) is that the participant experience must be
     * identical to a paying participant. Deliberately separate from "money
     * has actually been received" (see the two commission-ledger/paid_at
     * sites that intentionally keep their own literal === Paid checks
     * instead of calling this method).
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Paid, self::BridgeFunded => true,
            default => false,
        };
    }
}
