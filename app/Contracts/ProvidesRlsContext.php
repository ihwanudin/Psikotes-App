<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Security\RlsContext;

interface ProvidesRlsContext
{
    public function rlsContext(): RlsContext;
}
