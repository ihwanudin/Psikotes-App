<?php

declare(strict_types=1);

namespace App\Actions\Dass;

use App\Domain\Dass\DassAssessmentSnapshot;
use App\Domain\Dass\DassAssessmentStatus;
use App\Security\RlsContextRunner;
use App\Services\Dass\DassTableNames;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Status-only read (no score/category/follow-up content -- see
 * `DassAssessmentSnapshot`'s own doc) for the "did I already finish this"
 * rehydrate-on-reload check Lead approved: if not completed, the
 * participant just starts from item 1 again (no partial-answer resume --
 * DASS-21 is submit-once, per Lead's decision (a)).
 */
final readonly class GetDassAssessment
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(int $participantId, string $publicId): ?DassAssessmentSnapshot
    {
        $this->assertCleanOuterBoundary();

        if (! Str::isUlid($publicId)) {
            return null;
        }

        return $this->contexts->runAsService(function () use ($participantId, $publicId): ?DassAssessmentSnapshot {
            $row = DB::table(DassTableNames::assessments())
                ->where('public_id', $publicId)
                ->where('participant_id', $participantId)
                ->first();

            if ($row === null) {
                return null;
            }

            $status = DassAssessmentStatus::tryFrom((string) $row->status)
                ?? throw new LogicException('The persisted DASS-21 assessment status is invalid.');

            return new DassAssessmentSnapshot(
                (string) $row->public_id,
                $status,
                $this->storedTimestamp($row->started_at),
                $this->storedTimestamp($row->completed_at),
            );
        });
    }

    private function storedTimestamp(mixed $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable((string) $value))->format('Y-m-d\TH:i:s.up');
    }

    private function assertCleanOuterBoundary(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Reading a DASS-21 assessment must own its outer service transaction.');
        }
    }
}
