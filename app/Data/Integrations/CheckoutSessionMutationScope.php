<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Transaction-bound capability; safe principal facts are inaccessible after commit or rollback. */
final class CheckoutSessionMutationScope
{
    private bool $active = true;

    public function __construct(
        private readonly CheckoutSessionPrincipal $validatedPrincipal,
        private readonly RlsContextRunner $contexts,
        private readonly int $transactionLevel,
    ) {
        if ($contexts->current()?->role !== 'service' || $transactionLevel < 1
            || DB::transactionLevel() !== $transactionLevel) {
            throw new LogicException('Checkout session mutation scope requires its service transaction.');
        }
        DB::afterCommit(fn () => $this->active = false);
        DB::afterRollBack(fn () => $this->active = false);
    }

    public function principal(): CheckoutSessionPrincipal
    {
        if (! $this->active || $this->contexts->current()?->role !== 'service'
            || DB::transactionLevel() !== $this->transactionLevel) {
            throw new LogicException('Checkout session mutation scope is no longer active.');
        }

        return $this->validatedPrincipal;
    }
}
