<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AssessmentSessions\SweepExpiredAssessmentSessions;
use Illuminate\Console\Command;

final class SweepExpiredAssessmentSessionsCommand extends Command
{
    protected $signature = 'sessions:sweep-expired {--limit=200}';

    protected $description = 'Transition overdue in_progress assessment sessions to expired, scoring each one (IST/PAPI/RMIB) in the same transaction as its seal';

    public function handle(SweepExpiredAssessmentSessions $sweep): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 2000) {
            $this->error('The limit must be an integer between 1 and 2000.');

            return self::INVALID;
        }

        $result = $sweep->handle($limit);
        $this->info("Sealed {$result['sealed']} overdue session(s) to expired, {$result['failed']} failure(s).");

        return self::SUCCESS;
    }
}
