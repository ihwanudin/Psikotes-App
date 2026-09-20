<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Issues report numbers in the `HPP/{YYYY}/{MM}/{NNNN}` format (Template
 * HPP v2.3 Bagian I.A), one per case, resetting to 1 each calendar month.
 * Deliberately a plain allocator only: "does this case already have a
 * number" is decided by the caller (ReportDocumentIssuer, which reuses
 * an existing report_documents row's number across re-signs) — this
 * class just hands out the next free number for the current period,
 * mirroring App\Services\TestNumber\MonthlyTestNumberIssuer's row-lock
 * pattern for the same reason: safe under concurrent callers without a
 * database sequence object.
 */
final readonly class ReportNumberIssuer
{
    public function __construct(private DatabaseManager $database) {}

    public function issue(?CarbonInterface $at = null): string
    {
        $period = $this->period($at);
        $sequence = $this->database->transaction(function () use ($period): int {
            $now = Date::now();
            $this->database->table('report_number_sequences')->insertOrIgnore([
                'period' => $period,
                'last_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = $this->database->table('report_number_sequences')
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if ($row === null || ! is_numeric($row->last_value)) {
                throw new LogicException('Report number sequence could not be initialized.');
            }

            $next = (int) $row->last_value + 1;
            $this->database->table('report_number_sequences')
                ->where('period', $period)
                ->update(['last_value' => $next, 'updated_at' => $now]);

            return $next;
        }, 5);

        return sprintf('HPP/%s/%s/%04d', substr($period, 0, 4), substr($period, 4, 2), $sequence);
    }

    private function period(?CarbonInterface $at): string
    {
        return ($at ?? Date::now())->format('Ym');
    }
}
