<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TestNumber\MonthlyTestNumberIssuer;
use Illuminate\Console\Command;

final class PrepareTestNumberMonth extends Command
{
    protected $signature = 'test-numbers:prepare-month';

    protected $description = 'Idempotently prepare the current monthly test-number sequence';

    public function handle(MonthlyTestNumberIssuer $issuer): int
    {
        $issuer->prepare();
        $this->components->info('Current test-number period is ready.');

        return self::SUCCESS;
    }
}
