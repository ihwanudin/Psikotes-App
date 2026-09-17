<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentWebhookOutcome: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
    case Conflict = 'conflict';
}
