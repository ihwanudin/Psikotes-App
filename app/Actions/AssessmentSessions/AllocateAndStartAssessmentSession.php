<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Contracts\AssessmentItemContentAuthority;
use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentAttempt;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocation;
use App\Domain\AssessmentSessions\AssessmentAttemptAllocationPolicy;
use App\Domain\AssessmentSessions\AssessmentRetestGrant;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionErrorCode;
use App\Domain\AssessmentSessions\AssessmentSessionStartFailureCode;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\SessionGrantIdentity;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\CaseAuthorizationResolver;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use RuntimeException;

/**
 * First-attempt and retest allocator with exact replay.
 *
 * Retest authorization (item 19, 2026-09-22): the first
 * config('assessment_retests.free_attempt_limit') attempts need no admin
 * grant at all; every attempt past that looks up an active
 * assessment_retest_grants row and requires one. Does not resolve
 * multi-case history (ADR-0031 multi-case wiring is a separate,
 * not-yet-active slice).
 */
final class AllocateAndStartAssessmentSession
{
    private const MAX_TRANSACTION_ATTEMPTS = 3;

    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly CaseAuthorizationResolver $authorizations,
        private readonly AssessmentSessionDefinitionAuthority $definitions,
        private readonly AssessmentItemContentAuthority $itemContent,
        private readonly AssessmentAttemptAllocationPolicy $allocationPolicy,
        private readonly AssessmentSessionStateMachine $stateMachine,
        private readonly AssessmentSessionDeadlinePolicy $deadlinePolicy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function executeIntegrated(
        AssessmentPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): AssessmentSessionAllocationResult {
        $this->assertCleanOuterBoundary();

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                /** @var AssessmentSessionAllocationResult $result */
                $result = $this->contexts->runAsService(
                    fn (): AssessmentSessionAllocationResult => $this->withinIntegratedTransaction($principal, $instrument),
                );

                return $result;
            } catch (QueryException $exception) {
                if ($attempt === self::MAX_TRANSACTION_ATTEMPTS || ! $this->isRetryableTransactionFailure($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Assessment session allocation exhausted its transaction attempts.');
    }

    public function allocateSelectedParticipantForUpdate(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
    ): AssessmentSessionAllocationResult {
        $this->assertServiceTransaction();
        if ($authorization->participantId !== $principal->participantId
            || $authorization->organizationId !== $principal->branchId
            || $authorization->instrument !== $instrument
            || $authorization->origin === CaseAuthorizationOrigin::Integrated) {
            throw new InvalidAssessmentSessionState('Selected participant authorization scope changed before allocation.');
        }

        $replay = $this->replay($principal, $instrument, $authorization->origin, $authorization);
        if ($replay !== null) {
            return $replay;
        }

        return $this->allocateNew($principal, $instrument, $authorization);
    }

    private function withinIntegratedTransaction(
        AssessmentPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): AssessmentSessionAllocationResult {
        $participant = $this->lockParticipant($principal);
        $origin = $this->expectedOrigin($principal, $participant['source_system']);

        $replay = $this->replay($principal, $instrument, $origin);
        if ($replay !== null) {
            return $replay;
        }

        $authorization = $this->authorizations->resolveIntegratedForUpdate($principal, $instrument);
        if ($authorization->origin !== $origin) {
            throw new InvalidAssessmentSessionState('Resolved authorization origin changed during allocation.');
        }

        // A second lookup is required after waiting on the resolver's source locks.
        $replay = $this->replay($principal, $instrument, $origin);
        if ($replay !== null) {
            return $replay;
        }

        return $this->allocateNew($principal, $instrument, $authorization);
    }

    private function allocateNew(
        AssessmentPrincipal|ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
    ): AssessmentSessionAllocationResult {
        $grant = $this->grantIdentity($principal, $authorization);
        $authorizationId = $this->authorizationId($authorization->origin, $authorization->grantKind, $authorization->grantId);
        $intentId = $this->intentId($authorization->origin, $authorization->grantKind, $authorization->grantId, $instrument);
        $history = $this->lockHistory($authorization->participantId, $instrument, $authorization->caseId);
        $attempts = $this->attempts($history);
        $newSessionPublicId = (string) Str::ulid();
        $freeAttemptLimit = (int) config('assessment_retests.free_attempt_limit', 3);
        $nextAttemptNumber = count($attempts) + 1;
        $retestGrantRow = $nextAttemptNumber > $freeAttemptLimit
            ? $this->lockActiveRetestGrant($authorization->participantId, $instrument, $nextAttemptNumber)
            : null;
        $retestGrant = $retestGrantRow === null ? null : $this->hydrateRetestGrant($retestGrantRow);
        $decision = $this->allocationPolicy->decide(
            $attempts,
            $intentId,
            $authorizationId,
            $newSessionPublicId,
            null,
            $retestGrant,
            $freeAttemptLimit,
        );
        if (! $decision->accepted || ! $decision->shouldPersist || $decision->allocation === null) {
            $errorCode = $decision->errorCode;
            // decide() can only reject here with AttemptAlreadyExists or
            // RetestNotAuthorized (item 19: the first $freeAttemptLimit
            // attempts are automatic, every attempt past that needs its own
            // valid, looked-up-above grant) -- its other cases belong to the
            // deadline/autosave policies for different endpoints and can
            // never reach this call. RetestNotAuthorized maps to 403 verbatim
            // per ADR-0030 ("... tidak memiliki authority retest"), not 409.
            throw new InvalidAssessmentSessionState(
                $errorCode === null ? 'FIRST_ATTEMPT_ALLOCATION_REJECTED' : $errorCode->value,
                match ($errorCode) {
                    AssessmentSessionErrorCode::AttemptAlreadyExists => AssessmentSessionStartFailureCode::Conflict,
                    AssessmentSessionErrorCode::RetestNotAuthorized => AssessmentSessionStartFailureCode::NotAvailable,
                    AssessmentSessionErrorCode::SessionNotStarted,
                    AssessmentSessionErrorCode::SessionClosed,
                    AssessmentSessionErrorCode::DeadlineExceeded,
                    AssessmentSessionErrorCode::AutosaveStaleRevision,
                    AssessmentSessionErrorCode::AutosaveRevisionGap,
                    AssessmentSessionErrorCode::MutationPayloadMismatch,
                    AssessmentSessionErrorCode::InvalidAnswerBatch,
                    null => null,
                },
            );
        }

        $definition = $this->definitions->issueForNewSession($instrument, $authorization, $newSessionPublicId);
        if ($definition->instrument !== $instrument) {
            throw new InvalidAssessmentSessionState('Definition authority returned the wrong instrument.');
        }
        // A session must never start for an instrument whose item content
        // cannot actually be delivered -- otherwise the participant's timer
        // runs against a question screen with nothing to show (Lead
        // sign-off, 2026-09-21). Called before any row is written, same as
        // the definition-authority call above it: a thrown
        // AssessmentItemContentUnavailable rolls back this whole
        // transaction via the same mechanism, no separate cleanup needed.
        // The returned content itself is not needed here beyond
        // $resolvedVariant -- only that content could be produced;
        // GET /sessions/{id}/items reads the content for real.
        //
        // $lockedVariant is null here: this is the ONE call per session
        // where a reader with a variant axis (RMIB) may resolve fresh from
        // the participant's current profile (Lead sign-off, 2026-09-21).
        // $resolvedVariant is persisted into item_content_variant below and
        // handed to every later contentFor() call for this session (see
        // GetAssessmentSessionItems::load()) -- so a profile change after
        // this moment never changes what the participant is shown.
        $content = $this->itemContent->contentFor($instrument, $definition, $authorization->participantId);
        $itemContentVariant = $content->resolvedVariant;
        $serverTime = $this->serverTime();
        $start = $this->stateMachine->start(
            AssessmentSessionStatus::Created,
            null,
            null,
            $serverTime,
            $definition->totalDurationSeconds,
        );
        $deadline = $this->deadlinePolicy->evaluateAnswerWrite($start->status, $start->endsAt, $serverTime);
        if (! $deadline->accepted) {
            throw new InvalidAssessmentSessionState('A new assessment session must have an active deadline.');
        }

        $sessionId = $this->insertCreatedSession(
            $authorization,
            $decision->allocation,
            $definition,
            $serverTime,
            $itemContentVariant,
        );
        DB::table('test_session_grants')->insert([
            'test_session_id' => $sessionId,
            ...$grant->toArray(),
            'created_at' => $this->timestamp($serverTime),
        ]);
        $this->consumeSourceGrant($authorization, $serverTime);
        if ($retestGrantRow !== null) {
            $this->consumeRetestGrant((int) $retestGrantRow['id'], $serverTime);
        }
        if ($principal instanceof AssessmentPrincipal) {
            $this->startIntegratedParticipant($principal, $serverTime);
        }
        $this->startSession($sessionId, $start->startedAt, $start->endsAt, $serverTime);

        return new AssessmentSessionAllocationResult(
            $newSessionPublicId,
            $start->status,
            $decision->allocation->attempt->attemptNumber,
            0,
            $start->startedAt,
            $start->endsAt,
            $serverTime,
            $definition,
            false,
        );
    }

    /**
     * item 19: only reached when nextAttemptNumber is past
     * config('assessment_retests.free_attempt_limit'). Locked so the same
     * grant cannot be raced into consumption by two concurrent allocation
     * attempts -- though in practice the participant/history lock already
     * serializes callers for the same participant+instrument before this
     * point is ever reached.
     *
     * @return array<string, mixed>|null
     */
    private function lockActiveRetestGrant(int $participantId, GenericAssessmentInstrument $instrument, int $attemptNumber): ?array
    {
        $row = DB::table('assessment_retest_grants')
            ->where('participant_id', $participantId)
            ->where('test_type', $instrument->value)
            ->where('attempt_number', $attemptNumber)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        return $row === null ? null : (array) $row;
    }

    /** @param array<string, mixed> $row */
    private function hydrateRetestGrant(array $row): AssessmentRetestGrant
    {
        return new AssessmentRetestGrant(
            (string) $row['public_id'],
            true,
            (string) $row['reason'],
            'admin:'.$row['approved_by_admin_id'],
            (string) $row['authorization_id'],
            (int) $row['attempt_number'],
        );
    }

    private function consumeRetestGrant(int $grantId, DateTimeImmutable $serverTime): void
    {
        $updated = DB::table('assessment_retest_grants')
            ->where('id', $grantId)
            ->where('status', 'active')
            ->update([
                'status' => 'consumed',
                'consumed_at' => $this->timestamp($serverTime),
                'updated_at' => $this->timestamp($serverTime),
            ]);
        if ($updated !== 1) {
            throw new InvalidAssessmentSessionState('The exact retest grant could not be consumed.');
        }
    }

    /** @return array{source_system: string} */
    private function lockParticipant(AssessmentPrincipal|ParticipantPrincipal $principal): array
    {
        $organizationId = $principal instanceof AssessmentPrincipal
            ? $principal->organizationId
            : $principal->branchId;
        $participant = DB::table('participants')
            ->where('id', $principal->participantId)
            ->where('branch_id', $organizationId)
            ->lockForUpdate()
            ->first();
        if ($participant === null) {
            throw new CaseAuthorizationRejected;
        }

        $row = (array) $participant;

        return ['source_system' => (string) ($row['source_system'] ?? '')];
    }

    private function expectedOrigin(
        AssessmentPrincipal|ParticipantPrincipal $principal,
        string $sourceSystem,
    ): CaseAuthorizationOrigin {
        if ($principal instanceof AssessmentPrincipal) {
            return CaseAuthorizationOrigin::Integrated;
        }

        return match ($sourceSystem) {
            CaseAuthorizationOrigin::DirectPublic->value => CaseAuthorizationOrigin::DirectPublic,
            'SELEKSI_BEASISWA_JEPANG' => CaseAuthorizationOrigin::LegacySelection,
            default => throw new CaseAuthorizationRejected,
        };
    }

    private function replay(
        AssessmentPrincipal|ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
        CaseAuthorizationOrigin $origin,
        ?CaseAuthorization $authorization = null,
    ): ?AssessmentSessionAllocationResult {
        $query = DB::table('test_session_grants')
            ->where('participant_id', $principal->participantId)
            ->where('organization_id', $principal instanceof AssessmentPrincipal
                ? $principal->organizationId
                : $principal->branchId)
            ->where('test_type', $instrument->value)
            ->where('origin', $origin->value);
        if ($principal instanceof AssessmentPrincipal) {
            $query->where('assessment_participant_id', $principal->assessmentParticipantId);
        }
        if ($authorization !== null) {
            $query->where('assessment_case_id', $authorization->caseId)
                ->where('entitlement_id', $authorization->grantId);
        }
        // test_session_grants is an append-only ledger: psikotes_runtime
        // holds only SELECT+INSERT on it (see TestSessionGrantSecurityTest),
        // and PostgreSQL requires UPDATE privilege to use FOR UPDATE at all
        // -- a real-PostgreSQL-only failure SQLite never caught (S4,
        // 2026-09-21). No code anywhere ever mutates this table's rows, so a
        // row-level lock here guarded nothing; the serialization that
        // matters comes from the participant/case/history locks already
        // held upstream by the time this runs.
        $grants = $query->orderBy('test_session_id')->limit(2)->get();
        if ($grants->count() > 1) {
            throw new InvalidAssessmentSessionState('Assessment session grant history is ambiguous.');
        }
        $grantRow = $grants->first();
        if ($grantRow === null) {
            return null;
        }
        $grant = (array) $grantRow;

        $testSessionId = (int) ($grant['test_session_id'] ?? 0);
        $sessionRow = DB::table('test_sessions')->where('id', $testSessionId)->lockForUpdate()->first();
        if ($testSessionId < 1 || $sessionRow === null) {
            throw new InvalidAssessmentSessionState('Assessment session grant has no session.');
        }
        $session = (array) $sessionRow;
        $history = $this->lockHistory($principal->participantId, $instrument, $authorization?->caseId);
        $serverTime = $this->serverTime();
        if ($history->count() !== 1 || (int) ($session['attempt_no'] ?? 0) !== 1) {
            throw new InvalidAssessmentSessionState('Only one first attempt can be replayed.');
        }

        $grantKind = CaseAuthorizationGrantKind::tryFrom((string) ($grant['grant_kind'] ?? ''))
            ?? throw new InvalidAssessmentSessionState('Stored grant kind is invalid.');
        $sourceGrantId = $this->storedGrantId($grant, $origin, $grantKind, $principal);
        $authorizationId = $this->authorizationId($origin, $grantKind, $sourceGrantId);
        $intentId = $this->intentId($origin, $grantKind, $sourceGrantId, $instrument);
        if ((int) ($session['participant_id'] ?? 0) !== $principal->participantId
            || (int) ($session['assessment_case_id'] ?? 0) !== (int) ($grant['assessment_case_id'] ?? 0)
            || (string) ($session['test_type'] ?? '') !== $instrument->value
            || (string) ($session['authorization_id'] ?? '') !== $authorizationId
            || (string) ($session['allocation_intent_id'] ?? '') !== $intentId) {
            throw new InvalidAssessmentSessionState('Stored session replay identity is inconsistent.');
        }

        $attempts = $this->attempts($history);
        $existing = new AssessmentAttemptAllocation($intentId, $attempts[0]);
        $decision = $this->allocationPolicy->decide(
            $attempts,
            $intentId,
            $authorizationId,
            (string) Str::ulid(),
            $existing,
            null,
        );
        if (! $decision->accepted || $decision->shouldPersist) {
            throw new InvalidAssessmentSessionState('Stored assessment allocation cannot be replayed.');
        }

        $status = AssessmentSessionStatus::tryFrom((string) ($session['status'] ?? ''))
            ?? throw new InvalidAssessmentSessionState('Stored assessment session status is invalid.');
        if ($status !== AssessmentSessionStatus::InProgress) {
            throw new InvalidAssessmentSessionState('Terminal and unstarted sessions cannot be replayed.');
        }
        $startedAt = $this->requiredDate($session['started_at'] ?? null, 'started_at');
        $endsAt = $this->requiredDate($session['ends_at'] ?? null, 'ends_at');
        $start = $this->stateMachine->start(
            $status,
            $startedAt,
            $endsAt,
            $serverTime,
            (int) ($session['duration_seconds'] ?? 0),
        );
        $deadline = $this->deadlinePolicy->evaluateAnswerWrite($status, $endsAt, $serverTime);
        if (! $deadline->accepted) {
            // Genuinely reachable, not an invariant: the stored session status is
            // still 'in_progress' (a sweep job has not yet lazily transitioned it),
            // so isLiveReplay() legitimately selected it, but its ends_at has
            // already passed. ADR-0030 403 bucket: "... stale". Maps to
            // NotAvailable, not Conflict -- retrying will never succeed.
            throw new InvalidAssessmentSessionState(
                'Expired assessment sessions cannot be replayed.',
                AssessmentSessionStartFailureCode::NotAvailable,
            );
        }
        $definition = $this->storedDefinition($session);

        return new AssessmentSessionAllocationResult(
            (string) ($session['public_id'] ?? ''),
            $start->status,
            (int) ($session['attempt_no'] ?? 0),
            (int) ($session['answers_revision'] ?? -1),
            $start->startedAt,
            $start->endsAt,
            $serverTime,
            $definition,
            true,
        );
    }

    private function grantIdentity(
        AssessmentPrincipal|ParticipantPrincipal $principal,
        CaseAuthorization $authorization,
    ): SessionGrantIdentity {
        return match ($authorization->origin) {
            CaseAuthorizationOrigin::Integrated => $principal instanceof AssessmentPrincipal
                ? SessionGrantIdentity::integrated($authorization, $principal->assessmentParticipantId)
                : throw new InvalidAssessmentSessionState('Integrated authorization requires an assessment principal.'),
            CaseAuthorizationOrigin::DirectPublic => SessionGrantIdentity::directPublic(
                $authorization,
                $this->soleRelatedId('orders', $authorization, 'assessment case order'),
            ),
            CaseAuthorizationOrigin::LegacySelection => SessionGrantIdentity::legacySelection(
                $authorization,
                $this->soleRelatedId('selection_participants', $authorization, 'assessment case selection'),
            ),
        };
    }

    private function soleRelatedId(string $table, CaseAuthorization $authorization, string $label): int
    {
        $rows = DB::table($table)
            ->where('participant_id', $authorization->participantId)
            ->where('assessment_case_id', $authorization->caseId)
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($rows->count() !== 1) {
            throw new InvalidAssessmentSessionState("Exact {$label} is unavailable.");
        }

        $row = (array) $rows->first();
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            throw new InvalidAssessmentSessionState("Exact {$label} identity is invalid.");
        }

        return $id;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function lockHistory(
        int $participantId,
        GenericAssessmentInstrument $instrument,
        ?int $caseId = null,
    ): Collection {
        $query = DB::table('test_sessions')
            ->where('participant_id', $participantId)
            ->where('test_type', $instrument->value);
        if ($caseId !== null) {
            $query->where('assessment_case_id', $caseId);
        }

        return $query
            ->orderBy('attempt_no')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map($this->databaseRow(...));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $history
     * @return list<AssessmentAttempt>
     */
    private function attempts(Collection $history): array
    {
        return array_values($history->map(static function (array $session): AssessmentAttempt {
            $status = AssessmentSessionStatus::tryFrom((string) ($session['status'] ?? ''))
                ?? throw new InvalidAssessmentSessionState('Persisted assessment attempt status is invalid.');

            return new AssessmentAttempt(
                (string) ($session['public_id'] ?? ''),
                (int) ($session['attempt_no'] ?? 0),
                (string) ($session['authorization_id'] ?? ''),
                $status,
            );
        })->all());
    }

    private function insertCreatedSession(
        CaseAuthorization $authorization,
        AssessmentAttemptAllocation $allocation,
        SessionDefinition $definition,
        DateTimeImmutable $serverTime,
        ?string $itemContentVariant,
    ): int {
        try {
            $payload = json_encode(
                $definition->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidAssessmentSessionState('Session definition snapshot cannot be persisted.', previous: $exception);
        }

        return (int) DB::table('test_sessions')->insertGetId([
            'public_id' => $allocation->attempt->sessionPublicId,
            'participant_id' => $authorization->participantId,
            'assessment_case_id' => $authorization->caseId,
            'test_type' => $authorization->instrument->value,
            'attempt_no' => $allocation->attempt->attemptNumber,
            'authorization_id' => $allocation->attempt->authorizationId,
            'allocation_intent_id' => $allocation->intentId,
            'duration_seconds' => $definition->totalDurationSeconds,
            'status' => AssessmentSessionStatus::Created->value,
            'answers_revision' => 0,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => $payload,
            'item_content_variant' => $itemContentVariant,
            'created_at' => $this->timestamp($serverTime),
            'updated_at' => $this->timestamp($serverTime),
        ]);
    }

    private function consumeSourceGrant(CaseAuthorization $authorization, DateTimeImmutable $serverTime): void
    {
        $table = $authorization->grantKind === CaseAuthorizationGrantKind::AssessmentEntitlement
            ? 'assessment_entitlements'
            : 'entitlements';
        $query = DB::table($table)
            ->where('id', $authorization->grantId)
            ->where('participant_id', $authorization->participantId)
            ->where('test_type', $authorization->instrument->value);
        if ($authorization->grantKind === CaseAuthorizationGrantKind::Entitlement) {
            $query->where('assessment_case_id', $authorization->caseId);
        }
        $updated = $query
            ->where('status', 'ready')
            ->whereNotNull('ready_at')
            ->whereNull('started_at')
            ->whereNull('completed_at')
            ->update([
                'status' => 'in_progress',
                'started_at' => $this->timestamp($serverTime),
                'updated_at' => $this->timestamp($serverTime),
            ]);
        if ($updated !== 1) {
            throw new InvalidAssessmentSessionState('The exact session source grant could not be consumed.');
        }
    }

    private function startIntegratedParticipant(AssessmentPrincipal $principal, DateTimeImmutable $serverTime): void
    {
        $updated = DB::table('assessment_participants')
            ->where('id', $principal->assessmentParticipantId)
            ->where('participant_id', $principal->participantId)
            ->where('organization_id', $principal->organizationId)
            ->where('assessment_status', 'READY')
            ->update([
                'assessment_status' => 'IN_PROGRESS',
                'updated_at' => $this->timestamp($serverTime),
            ]);
        if ($updated === 1) {
            return;
        }
        $status = DB::table('assessment_participants')
            ->where('id', $principal->assessmentParticipantId)
            ->where('participant_id', $principal->participantId)
            ->where('organization_id', $principal->organizationId)
            ->value('assessment_status');
        if ($status !== 'IN_PROGRESS') {
            throw new InvalidAssessmentSessionState('The integrated participant could not be started.');
        }
    }

    private function startSession(
        int $sessionId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $serverTime,
    ): void {
        $updated = DB::table('test_sessions')
            ->where('id', $sessionId)
            ->where('status', AssessmentSessionStatus::Created->value)
            ->update([
                'status' => AssessmentSessionStatus::InProgress->value,
                'started_at' => $this->timestamp($startedAt),
                'ends_at' => $this->timestamp($endsAt),
                'updated_at' => $this->timestamp($serverTime),
            ]);
        if ($updated !== 1) {
            throw new InvalidAssessmentSessionState('The allocated assessment session could not be started.');
        }
    }

    /** @param array<string, mixed> $grant */
    private function storedGrantId(
        array $grant,
        CaseAuthorizationOrigin $origin,
        CaseAuthorizationGrantKind $grantKind,
        AssessmentPrincipal|ParticipantPrincipal $principal,
    ): int {
        $value = match ([$origin, $grantKind]) {
            [CaseAuthorizationOrigin::Integrated, CaseAuthorizationGrantKind::AssessmentEntitlement] => $grant['assessment_entitlement_id'] ?? null,
            [CaseAuthorizationOrigin::DirectPublic, CaseAuthorizationGrantKind::Entitlement],
            [CaseAuthorizationOrigin::LegacySelection, CaseAuthorizationGrantKind::Entitlement] => $grant['entitlement_id'] ?? null,
            default => null,
        };
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 1
            || ($principal instanceof AssessmentPrincipal
                && (int) ($grant['assessment_participant_id'] ?? 0) !== $principal->assessmentParticipantId)) {
            throw new InvalidAssessmentSessionState('Stored session grant shape is inconsistent.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $session */
    private function storedDefinition(array $session): SessionDefinition
    {
        $definitionPayload = $session['session_definition_payload'] ?? null;
        if (! is_string($definitionPayload)
            || ($session['session_definition_version'] ?? null) === null
            || ($session['session_definition_provenance'] ?? null) === null
            || ($session['session_definition_checksum'] ?? null) === null) {
            throw new InvalidAssessmentSessionState('Stored session definition snapshot is incomplete.');
        }
        try {
            $payload = json_decode($definitionPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidAssessmentSessionState('Stored session definition snapshot is invalid.', previous: $exception);
        }
        if (! is_array($payload)) {
            throw new InvalidAssessmentSessionState('Stored session definition snapshot must be an object.');
        }
        $definition = SessionDefinition::fromArray($payload);
        if ($definition->version !== (string) $session['session_definition_version']
            || $definition->provenance !== (string) $session['session_definition_provenance']
            || $definition->checksum !== (string) $session['session_definition_checksum']
            || $definition->totalDurationSeconds !== (int) ($session['duration_seconds'] ?? 0)
            || $definition->instrument->value !== (string) ($session['test_type'] ?? '')) {
            throw new InvalidAssessmentSessionState('Stored session definition identity is inconsistent.');
        }

        return $definition;
    }

    /** @return array<string, mixed> */
    private function databaseRow(object $row): array
    {
        return (array) $row;
    }

    private function authorizationId(
        CaseAuthorizationOrigin $origin,
        CaseAuthorizationGrantKind $grantKind,
        int $grantId,
    ): string {
        return "grant:v1:{$origin->value}:{$grantKind->value}:{$grantId}";
    }

    private function intentId(
        CaseAuthorizationOrigin $origin,
        CaseAuthorizationGrantKind $grantKind,
        int $grantId,
        GenericAssessmentInstrument $instrument,
    ): string {
        return "allocation:v1:{$origin->value}:{$grantKind->value}:{$grantId}:{$instrument->value}";
    }

    private function serverTime(): DateTimeImmutable
    {
        $time = ($this->clock)();
        if (! $time instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment session allocation clock must return DateTimeImmutable.');
        }

        return $this->utc($time);
    }

    private function requiredDate(mixed $value, string $field): DateTimeImmutable
    {
        if ($value === null) {
            throw new InvalidAssessmentSessionState("Stored assessment session {$field} is missing.");
        }

        return $this->utc(new DateTimeImmutable((string) $value));
    }

    private function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $this->utc($time)->format('Y-m-d H:i:s.uP');
    }

    private function assertCleanOuterBoundary(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Assessment session allocation must own its outer service transaction.');
        }
    }

    private function assertServiceTransaction(): void
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('Selected participant allocation requires an active service transaction.');
        }
    }

    private function isRetryableTransactionFailure(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return in_array((string) $sqlState, ['40001', '40P01'], true);
    }
}
