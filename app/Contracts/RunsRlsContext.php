<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Security\RlsContext;

interface RunsRlsContext
{
    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function run(RlsContext $context, callable $callback): mixed;
}
