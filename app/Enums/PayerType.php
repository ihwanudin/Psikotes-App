<?php

declare(strict_types=1);

namespace App\Enums;

enum PayerType: string
{
    case SelfPay = 'self';
    case Organization = 'organization';
}
