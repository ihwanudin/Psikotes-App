<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckoutSessionRecoveryState: string
{
    case ActiveRevoked = 'ACTIVE_REVOKED';

    case ActiveDueExpired = 'ACTIVE_DUE_EXPIRED';

    case Expired = 'EXPIRED';

    case LoggedOut = 'LOGOUT';
}
