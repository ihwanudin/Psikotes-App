<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use RuntimeException;

final class InvalidCheckoutSession extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CHECKOUT_SESSION_INVALID');
    }
}
