<?php

declare(strict_types=1);

namespace App\Actions\Dass;

use App\Domain\Dass\DassAssessmentSnapshot;
use App\Domain\Dass\DassAssessmentStatus;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use App\Services\Dass\DassTableNames;
use App\Services\ParticipantAuth\AssessmentAccessPrerequisites;
use App\Services\ParticipantAuth\ParticipantEntitlementGate;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

/**
 * Idempotent DASS-21 start: replays the participant's existing
 * `dass.assessments` row (any non-withdrawn/declined status) rather than
 * ever creating a second one, since nothing in the schema enforces
 * one-per-participant at the DB level (`2026_08_25_000300_create_isolated_dass_schema.php`
 * only has a unique `public_id`, no unique on `participant_id`) -- the
 * `lockForUpdate()` check-then-insert below narrows but does not fully
 * eliminate a genuine concurrent-double-click race; accepted as
 * proportionate for a single participant's own action, not the
 * high-contention multi-writer case the generic four-instrument engine's
 * retry/locking machinery exists for.
 *
 * Runs as its own service transaction, the same pattern every other
 * session-taking action in this codebase uses -- not because DASS needs
 * the generic engine's complexity (it doesn't: no case authorization, no
 * revision conflicts, no deadline locking), but because the assessment can
 * only be started with entitlement/prerequisite reads that themselves need
 * a stable, single elevated context, and because `dass.results`'s own
 * write policy is service-only (see `SubmitDassAssessment`, which writes
 * results in the SAME action) -- `RlsContextRunner::runAsService()`
 * explicitly forbids a participant context elevating mid-request
 * (`Only administrator contexts may elevate to service`), so a single
 * DASS action that ever needs a service-only write cannot also run any
 * part of itself as a raw participant-role connection.
 */
final readonly class AllocateAndStartDassAssessment
{
    private Closure $clock;

    public function __construct(
        private RlsContextRunner $contexts,
        private ParticipantEntitlementGate $entitlementGate,
        private AssessmentAccessPrerequisites $prerequisites,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(ParticipantPrincipal $principal): DassAssessmentSnapshot
    {
        $this->assertCleanOuterBoundary();

        return $this->contexts->runAsService(fn (): DassAssessmentSnapshot => $this->withinTransaction($principal));
    }

    private function withinTransaction(ParticipantPrincipal $principal): DassAssessmentSnapshot
    {
        return DB::transaction(function () use ($principal): DassAssessmentSnapshot {
            $existing = DB::table(DassTableNames::assessments())
                ->where('participant_id', $principal->participantId)
                ->whereIn('status', ['not_started', 'in_progress', 'completed'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->snapshotOf($existing);
            }

            $participant = Participant::query()->findOrFail($principal->participantId);
            $this->entitlementGate->assertReady($principal->participantId, 'dass21');
            $this->prerequisites->assertSatisfied($participant, 'dass21');

            $now = ($this->clock)();
            if (! $now instanceof DateTimeImmutable) {
                throw new LogicException('The DASS-21 start clock must return DateTimeImmutable.');
            }

            $consentRecordId = DB::table('consent_records')
                ->where('participant_id', $principal->participantId)
                ->where('consent_type', 'dass')
                ->where('status', 'accepted')
                ->whereNull('withdrawn_at')
                ->orderByDesc('consented_at')
                ->value('id');

            $publicId = (string) Str::ulid();
            $id = DB::table(DassTableNames::assessments())->insertGetId([
                'public_id' => $publicId,
                'participant_id' => $principal->participantId,
                'consent_record_id' => $consentRecordId,
                'status' => DassAssessmentStatus::InProgress->value,
                'started_at' => $this->timestamp($now),
                'completed_at' => null,
                'withdrawn_at' => null,
                'expires_at' => $this->timestamp($now->modify('+2 years')),
                'created_at' => $this->timestamp($now),
                'updated_at' => $this->timestamp($now),
            ]);

            return new DassAssessmentSnapshot(
                $publicId,
                DassAssessmentStatus::InProgress,
                $this->rfc3339($now),
                null,
            );
        });
    }

    private function snapshotOf(stdClass $row): DassAssessmentSnapshot
    {
        $status = DassAssessmentStatus::tryFrom((string) $row->status)
            ?? throw new LogicException('The persisted DASS-21 assessment status is invalid.');

        return new DassAssessmentSnapshot(
            (string) $row->public_id,
            $status,
            $this->storedTimestamp($row->started_at),
            $this->storedTimestamp($row->completed_at),
        );
    }

    private function storedTimestamp(mixed $value): ?string
    {
        return $value === null ? null : $this->rfc3339(new DateTimeImmutable((string) $value));
    }

    private function assertCleanOuterBoundary(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('DASS-21 assessment start must own its outer service transaction.');
        }
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }

    private function rfc3339(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.up');
    }
}
