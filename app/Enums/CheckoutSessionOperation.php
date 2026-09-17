<?php

declare(strict_types=1);

namespace App\Enums;

/** Closed internal operations, never selected by request parameters. */
enum CheckoutSessionOperation
{
    case Hydrate;
    case HydrateWithCsrfDelivery;
    case Logout;
    case Profile;
    case Payment;
    case Summary;
}
