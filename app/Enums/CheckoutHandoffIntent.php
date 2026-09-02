<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckoutHandoffIntent: string
{
    case Issue = 'ISSUE';
    case Reissue = 'REISSUE';
}
