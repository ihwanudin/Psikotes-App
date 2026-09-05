<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\GenericAssessmentResultVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final readonly class GenericAssessmentResultDispatch
{
    /** @var array<string, list<string>> */
    private const array OUTCOME_REASON_CODES = [
        'RETRYABLE' => ['TRANSIENT_UNAVAILABLE', 'RATE_LIMITED'],
        'PERMANENT' => ['REMOTE_REJECTED'],
        'UNKNOWN' => ['OUTCOME_UNCERTAIN'],
    ];

    /** @var list<int> */
    private array $backoffSeconds;

    /** @param list<int> $backoffSeconds */
    public function __construct(
        private int $leaseSeconds = 300,
        private int $maxAttempts = 4,
        array $backoffSeconds = [60, 300, 900],
    ) {
        if ($leaseSeconds < 30 || $leaseSeconds > 1800 || $maxAttempts < 1 || $maxAttempts > 10
            || $backoffSeconds === []
            || array_any($backoffSeconds, static fn (int $seconds): bool => $seconds < 1 || $seconds > 86_400)) {
            throw new InvalidArgumentException('ASSESSMENT_RESULT_DISPATCH_CONFIG_INVALID');
        }
        $this->backoffSeconds = $backoffSeconds;
    }

    /** @return array{action:string,attemptId:?string,attemptNumber:?int,leaseExpiresAt:?string} */
    public function claimExact(
        string $outboxId,
        string $resultVersionId,
        int $resultVersion,
        string $resultChecksum,
        string $leaseToken,
    ): array {
        try {
            $this->validateIdentity($outboxId, $resultVersionId, $resultVersion, $resultChecksum, $leaseToken);

            return DB::transaction(function () use ($outboxId, $resultVersionId, $resultVersion, $resultChecksum, $leaseToken): array {
                $binding = $this->binding($outboxId, $resultVersionId, $resultVersion, $resultChecksum, true);
                $latest = DB::table('generic_assessment_result_dispatch_attempts')
                    ->where('outbox_id', $outboxId)
                    ->orderByDesc('attempt_number')
                    ->lockForUpdate()
                    ->first();
                $now = CarbonImmutable::instance(now())->utc();
                $tokenHash = hash('sha256', $leaseToken);

                if ($latest !== null) {
                    $attemptNumber = (int) $latest->attempt_number;
                    if ((string) $latest->outcome === 'PROCESSING') {
                        $notExpired = $now->lt(CarbonImmutable::parse((string) $latest->lease_expires_at)->utc());
                        if (hash_equals((string) $latest->lease_token_hash, $tokenHash)) {
                            if ($notExpired) {
                                $this->audit($binding, 'replayed', $attemptNumber, 'PROCESSING', null);

                                return $this->claimResult(
                                    'REPLAYED',
                                    (string) $latest->id,
                                    (int) $latest->attempt_number,
                                    (string) $latest->lease_expires_at,
                                );
                            }

                            return $this->skip($binding, 'SKIPPED_EXPIRED_TOKEN', $attemptNumber, 'TOKEN_EXPIRED');
                        }
                        if ($notExpired) {
                            return $this->skip($binding, 'SKIPPED_LEASE_ACTIVE', $attemptNumber, 'LEASE_ACTIVE');
                        }

                        return $this->insertAttempt($binding, $attemptNumber + 1, $tokenHash, $now, 'TAKEN_OVER');
                    }

                    if (in_array((string) $latest->outcome, ['ACKNOWLEDGED', 'PERMANENT', 'UNKNOWN'], true)) {
                        return $this->skip($binding, 'SKIPPED_TERMINAL', $attemptNumber, 'TERMINAL_OUTCOME');
                    }
                    if ((string) $latest->outcome !== 'RETRYABLE' || $attemptNumber >= $this->maxAttempts
                        || $latest->next_attempt_at === null) {
                        return $this->skip($binding, 'SKIPPED_EXHAUSTED', $attemptNumber, 'ATTEMPTS_EXHAUSTED');
                    }
                    if ($now->lt(CarbonImmutable::parse((string) $latest->next_attempt_at)->utc())) {
                        return $this->skip($binding, 'SKIPPED_NOT_DUE', $attemptNumber, 'RETRY_NOT_DUE');
                    }

                    return $this->insertAttempt($binding, $attemptNumber + 1, $tokenHash, $now, 'CLAIMED');
                }

                return $this->insertAttempt($binding, 1, $tokenHash, $now, 'CLAIMED');
            });
        } catch (LogicException $exception) {
            $this->auditFailure($outboxId, $resultVersionId, $resultVersion, $resultChecksum, $exception);

            throw $exception;
        }
    }

    /** @return array{action:string,outcome:string,nextAttemptAt:?string} */
    public function completeExact(
        string $outboxId,
        string $resultVersionId,
        int $resultVersion,
        string $resultChecksum,
        string $attemptId,
        string $leaseToken,
        string $outcome,
        ?string $reasonCode,
    ): array {
        try {
            $this->validateIdentity($outboxId, $resultVersionId, $resultVersion, $resultChecksum, $leaseToken);
            if (! Str::isUlid($attemptId)
                || ! in_array($outcome, ['ACKNOWLEDGED', 'RETRYABLE', 'PERMANENT', 'UNKNOWN'], true)
                || ($outcome === 'ACKNOWLEDGED'
                    ? $reasonCode !== null
                    : ! is_string($reasonCode) || ! in_array($reasonCode, self::OUTCOME_REASON_CODES[$outcome], true))) {
                throw new LogicException('ASSESSMENT_RESULT_DISPATCH_COMPLETION_INVALID');
            }

            return DB::transaction(function () use ($outboxId, $resultVersionId, $resultVersion, $resultChecksum, $attemptId, $leaseToken, $outcome, $reasonCode): array {
                $binding = $this->binding($outboxId, $resultVersionId, $resultVersion, $resultChecksum, true);
                $attempt = DB::table('generic_assessment_result_dispatch_attempts')
                    ->where('id', $attemptId)->where('outbox_id', $outboxId)->lockForUpdate()->first();
                if ($attempt === null || ! hash_equals((string) $attempt->lease_token_hash, hash('sha256', $leaseToken))) {
                    throw new LogicException('ASSESSMENT_RESULT_DISPATCH_ATTEMPT_CONFLICT');
                }

                if ((string) $attempt->outcome !== 'PROCESSING') {
                    if ((string) $attempt->outcome === $outcome && (string) ($attempt->reason_code ?? '') === (string) ($reasonCode ?? '')) {
                        $this->audit($binding, 'replayed', (int) $attempt->attempt_number, $outcome, null);

                        return [
                            'action' => 'REPLAYED',
                            'outcome' => $outcome,
                            'nextAttemptAt' => $this->formatNullable($attempt->next_attempt_at),
                        ];
                    }
                    throw new LogicException('ASSESSMENT_RESULT_DISPATCH_COMPLETION_CONFLICT');
                }

                $now = CarbonImmutable::instance(now())->utc();
                if (! $now->lt(CarbonImmutable::parse((string) $attempt->lease_expires_at)->utc())) {
                    throw new LogicException('ASSESSMENT_RESULT_DISPATCH_LEASE_EXPIRED');
                }
                $nextAttemptAt = $outcome === 'RETRYABLE' && (int) $attempt->attempt_number < $this->maxAttempts
                    ? $now->addSeconds($this->backoffFor((int) $attempt->attempt_number))
                    : null;
                DB::table('generic_assessment_result_dispatch_attempts')->where('id', $attemptId)->update([
                    'outcome' => $outcome,
                    'completed_at' => $now,
                    'next_attempt_at' => $nextAttemptAt,
                    'reason_code' => $reasonCode,
                ]);
                $this->audit($binding, 'completed', (int) $attempt->attempt_number, $outcome, $reasonCode);

                return [
                    'action' => 'COMPLETED',
                    'outcome' => $outcome,
                    'nextAttemptAt' => $nextAttemptAt?->format('Y-m-d\TH:i:s.u\Z'),
                ];
            });
        } catch (LogicException $exception) {
            $this->auditFailure($outboxId, $resultVersionId, $resultVersion, $resultChecksum, $exception);

            throw $exception;
        }
    }

    private function binding(string $outboxId, string $sourceId, int $version, string $checksum, bool $lock): GenericAssessmentResultDispatchBinding
    {
        $query = DB::table('generic_assessment_result_outbox as o')
            ->join('generic_assessment_result_versions as r', 'r.id', '=', 'o.generic_assessment_result_version_id')
            ->join('assessment_participants as ap', 'ap.id', '=', 'o.assessment_participant_id')
            ->where('o.id', $outboxId)
            ->select([
                'o.id as outbox_id', 'o.generic_assessment_result_version_id as source_id',
                'o.assessment_participant_id', 'ap.organization_id', 'o.assessment_attempt_id', 'o.result_version',
                'o.result_checksum', 'r.finality', 'r.revoked_at',
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $binding = $query->first();
        if ($binding === null || (string) $binding->source_id !== $sourceId
            || (int) $binding->result_version !== $version
            || ! hash_equals((string) $binding->result_checksum, $checksum)) {
            throw new LogicException('ASSESSMENT_RESULT_DISPATCH_BINDING_CONFLICT');
        }

        return new GenericAssessmentResultDispatchBinding(
            outboxId: (string) $binding->outbox_id,
            sourceId: (string) $binding->source_id,
            assessmentParticipantId: (int) $binding->assessment_participant_id,
            organizationId: (int) $binding->organization_id,
            assessmentAttemptId: (string) $binding->assessment_attempt_id,
            resultVersion: (int) $binding->result_version,
            resultChecksum: (string) $binding->result_checksum,
            finality: (string) $binding->finality,
            isRevoked: $binding->revoked_at !== null,
        );
    }

    /** @return array{action:string,attemptId:string,attemptNumber:int,leaseExpiresAt:string} */
    private function insertAttempt(GenericAssessmentResultDispatchBinding $binding, int $attemptNumber, string $tokenHash, CarbonImmutable $now, string $action): array
    {
        $id = (string) Str::ulid();
        $expires = $now->addSeconds($this->leaseSeconds);
        DB::table('generic_assessment_result_dispatch_attempts')->insert([
            'id' => $id, 'outbox_id' => $binding->outboxId, 'attempt_number' => $attemptNumber,
            'mode' => 'CALLBACK_AND_POLL', 'lease_token_hash' => $tokenHash,
            'claimed_at' => $now, 'lease_expires_at' => $expires, 'outcome' => 'PROCESSING',
            'completed_at' => null, 'next_attempt_at' => null, 'reason_code' => null, 'created_at' => $now,
        ]);
        $this->audit($binding, strtolower($action), $attemptNumber, 'PROCESSING', null);

        return ['action' => $action, 'attemptId' => $id, 'attemptNumber' => $attemptNumber, 'leaseExpiresAt' => $expires->format('Y-m-d\TH:i:s.u\Z')];
    }

    /** @return array{action:string,attemptId:null,attemptNumber:int,leaseExpiresAt:null} */
    private function skip(GenericAssessmentResultDispatchBinding $binding, string $action, int $attemptNumber, string $reason): array
    {
        $this->audit($binding, 'skipped', $attemptNumber, null, $reason);

        return ['action' => $action, 'attemptId' => null, 'attemptNumber' => $attemptNumber, 'leaseExpiresAt' => null];
    }

    /** @return array{action:string,attemptId:string,attemptNumber:int,leaseExpiresAt:string} */
    private function claimResult(string $action, string $attemptId, int $attemptNumber, string $leaseExpiresAt): array
    {
        return [
            'action' => $action, 'attemptId' => $attemptId,
            'attemptNumber' => $attemptNumber,
            'leaseExpiresAt' => CarbonImmutable::parse($leaseExpiresAt)->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    private function validateIdentity(string $outboxId, string $sourceId, int $version, string $checksum, string $token): void
    {
        if (! Str::isUlid($outboxId) || ! Str::isUlid($sourceId) || $version < 1
            || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1 || strlen($token) < 32 || strlen($token) > 200) {
            throw new LogicException('ASSESSMENT_RESULT_DISPATCH_BINDING_INVALID');
        }
    }

    private function backoffFor(int $attemptNumber): int
    {
        return $this->backoffSeconds[min($attemptNumber - 1, count($this->backoffSeconds) - 1)];
    }

    private function formatNullable(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function audit(GenericAssessmentResultDispatchBinding $binding, string $action, ?int $attemptNumber, ?string $outcome, ?string $reasonCode): void
    {
        $at = CarbonImmutable::instance(now())->utc();
        DB::table('audit_logs')->insert([
            'branch_id' => $binding->organizationId,
            'actor_type' => 'system', 'actor_id' => null,
            'action' => 'generic_assessment_result_dispatch.'.$action,
            'subject_type' => GenericAssessmentResultVersion::class, 'subject_id' => $binding->sourceId,
            'context' => json_encode([
                'assessmentAttemptReference' => hash('sha256', $binding->assessmentAttemptId),
                'resultVersion' => $binding->resultVersion, 'resultChecksum' => $binding->resultChecksum,
                'finality' => $binding->finality, 'isRevoked' => $binding->isRevoked,
                'attemptNumber' => $attemptNumber, 'outcome' => $outcome, 'reasonCode' => $reasonCode,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at, 'expires_at' => $at->addYearsNoOverflow(2),
        ]);
    }

    private function auditFailure(string $outboxId, string $sourceId, int $version, string $checksum, LogicException $exception): void
    {
        try {
            $binding = $this->binding($outboxId, $sourceId, $version, $checksum, false);
        } catch (LogicException) {
            $binding = null;
        }
        $at = CarbonImmutable::instance(now())->utc();
        DB::table('audit_logs')->insert([
            'branch_id' => $binding?->organizationId,
            'actor_type' => 'system', 'actor_id' => null,
            'action' => 'generic_assessment_result_dispatch.failed',
            'subject_type' => GenericAssessmentResultVersion::class,
            'subject_id' => $binding?->sourceId,
            'context' => json_encode([
                'assessmentAttemptReference' => $binding === null ? null : hash('sha256', $binding->assessmentAttemptId),
                'resultVersion' => $version > 0 ? $version : null,
                'resultChecksum' => preg_match('/^[a-f0-9]{64}$/', $checksum) === 1 ? $checksum : null,
                'finality' => $binding?->finality, 'isRevoked' => $binding?->isRevoked,
                'reasonCode' => match ($exception->getMessage()) {
                    'ASSESSMENT_RESULT_DISPATCH_LEASE_EXPIRED' => 'LEASE_EXPIRED',
                    'ASSESSMENT_RESULT_DISPATCH_ATTEMPT_CONFLICT' => 'ATTEMPT_CONFLICT',
                    'ASSESSMENT_RESULT_DISPATCH_COMPLETION_CONFLICT' => 'COMPLETION_CONFLICT',
                    'ASSESSMENT_RESULT_DISPATCH_BINDING_CONFLICT' => 'BINDING_CONFLICT',
                    default => 'INPUT_INVALID',
                },
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at, 'expires_at' => $at->addYearsNoOverflow(2),
        ]);
    }
}

final readonly class GenericAssessmentResultDispatchBinding
{
    public function __construct(
        public string $outboxId,
        public string $sourceId,
        public int $assessmentParticipantId,
        public int $organizationId,
        public string $assessmentAttemptId,
        public int $resultVersion,
        public string $resultChecksum,
        public string $finality,
        public bool $isRevoked,
    ) {}
}
