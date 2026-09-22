<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Security\RlsContextRunner;
use App\Services\TestNumber\MonthlyTestNumberIssuer;
use Illuminate\Console\Command;

/**
 * RLS-GAP-06 remediation (tasks/handoffs/f2/database-rls-coverage-audit.md,
 * Group B). This command previously called MonthlyTestNumberIssuer::prepare()
 * with no RLS context at all -- harmless while test_number_sequences had no
 * RLS, but would break outright once that table's write path is
 * service-only, since this is a scheduled command with no ambient
 * app.role. Wrapped in an explicit service context, matching every other
 * write-path command in this codebase.
 */
final class PrepareTestNumberMonth extends Command
{
    protected $signature = 'test-numbers:prepare-month';

    protected $description = 'Idempotently prepare the current monthly test-number sequence';

    public function handle(MonthlyTestNumberIssuer $issuer, RlsContextRunner $runner): int
    {
        $runner->runAsService(fn () => $issuer->prepare());
        $this->components->info('Current test-number period is ready.');

        return self::SUCCESS;
    }
}
