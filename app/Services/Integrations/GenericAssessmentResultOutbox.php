<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\AssessmentParticipant;
use App\Models\GenericAssessmentResultVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class GenericAssessmentResultOutbox
{
    private const string ENVELOPE_CONTRACT = 'generic-assessment-result:v1';

    public function __construct(
        private GenericAssessmentResultProjector $projector,
        private RetentionPolicy $retention,
    ) {}

    /** @return array{action:string,outboxId:?string} */
    public function enqueueExact(
        string $resultVersionId,
        string $assessmentAttemptId,
        int $resultVersion,
        string $resultChecksum,
    ): array {
        try {
            $this->validateExpectedBinding($resultVersionId, $assessmentAttemptId, $resultVersion, $resultChecksum);

            return DB::transaction(function () use ($resultVersionId, $assessmentAttemptId, $resultVersion, $resultChecksum): array {
                $assessment = AssessmentParticipant::query()
                    ->where('assessment_attempt_id', $assessmentAttemptId)
                    ->lockForUpdate()
                    ->first();
                if ($assessment === null) {
                    throw new LogicException('ASSESSMENT_RESULT_OUTBOX_SOURCE_NOT_FOUND');
                }

                $source = GenericAssessmentResultVersion::query()->whereKey($resultVersionId)->first();
                if ($source === null
                    || (int) $source->assessment_participant_id !== (int) $assessment->id
                    || (string) $source->assessment_attempt_id !== $assessmentAttemptId
                    || (int) $source->result_version !== $resultVersion
                    || ! hash_equals((string) $source->result_checksum, $resultChecksum)) {
                    throw new LogicException('ASSESSMENT_RESULT_OUTBOX_SOURCE_CONFLICT');
                }

                $envelope = $this->envelopeFromRow($source);
                $safeContext = $this->safeContext($envelope);
                $existing = DB::table('generic_assessment_result_outbox')
                    ->where('generic_assessment_result_version_id', $source->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if ((int) $existing->assessment_participant_id !== (int) $assessment->id
                        || (string) $existing->assessment_attempt_id !== $assessmentAttemptId
                        || (int) $existing->result_version !== $resultVersion
                        || ! hash_equals((string) $existing->result_checksum, $resultChecksum)
                        || (string) $existing->envelope_contract !== self::ENVELOPE_CONTRACT) {
                        throw new LogicException('ASSESSMENT_RESULT_OUTBOX_BINDING_CORRUPT');
                    }

                    $this->audit($assessment, $source->id, 'replayed', $safeContext);

                    return ['action' => 'REPLAYED', 'outboxId' => (string) $existing->id];
                }

                $latestId = GenericAssessmentResultVersion::query()
                    ->where('assessment_participant_id', $assessment->id)
                    ->where('assessment_attempt_id', $assessmentAttemptId)
                    ->orderByDesc('result_version')
                    ->value('id');
                if ($latestId !== $source->id) {
                    $this->audit($assessment, $source->id, 'skipped', $safeContext, 'SOURCE_STALE');

                    return ['action' => 'SKIPPED_STALE', 'outboxId' => null];
                }

                $outboxId = (string) Str::ulid();
                DB::table('generic_assessment_result_outbox')->insert([
                    'id' => $outboxId,
                    'generic_assessment_result_version_id' => $source->id,
                    'assessment_participant_id' => $assessment->id,
                    'assessment_attempt_id' => $assessmentAttemptId,
                    'result_version' => $resultVersion,
                    'result_checksum' => $resultChecksum,
                    'envelope_contract' => self::ENVELOPE_CONTRACT,
                    'created_at' => CarbonImmutable::instance(now())->utc(),
                ]);
                $this->audit($assessment, $source->id, 'created', $safeContext);

                return ['action' => 'CREATED', 'outboxId' => $outboxId];
            });
        } catch (LogicException $exception) {
            $this->auditFailure(
                $resultVersionId,
                $assessmentAttemptId,
                $resultVersion,
                $resultChecksum,
                $this->failureReason($exception),
            );

            throw $exception;
        }
    }

    private function validateExpectedBinding(
        string $resultVersionId,
        string $assessmentAttemptId,
        int $resultVersion,
        string $resultChecksum,
    ): void {
        if (! Str::isUlid($resultVersionId) || ! Str::isUlid($assessmentAttemptId)
            || $resultVersion < 1 || preg_match('/^[a-f0-9]{64}$/', $resultChecksum) !== 1) {
            throw new LogicException('ASSESSMENT_RESULT_OUTBOX_BINDING_INVALID');
        }
    }

    /** @return array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int,resultChecksum:string} */
    private function envelopeFromRow(GenericAssessmentResultVersion $source): array
    {
        $iqCanonical = (string) $source->iq_canonical;
        $iq = str_contains($iqCanonical, '.') || str_contains(strtolower($iqCanonical), 'e')
            ? (float) $iqCanonical
            : (int) $iqCanonical;

        return [
            'assessmentAttemptId' => (string) $source->assessment_attempt_id,
            'iq' => $iq,
            'engineVersion' => (string) $source->engine_version,
            'completedAt' => CarbonImmutable::instance($source->completed_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'finality' => (string) $source->finality,
            'revokedAt' => $source->revoked_at === null
                ? null
                : CarbonImmutable::instance($source->revoked_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'resultVersion' => (int) $source->result_version,
            'resultChecksum' => (string) $source->result_checksum,
        ];
    }

    /** @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function safeContext(array $envelope): array
    {
        $context = $this->projector->safeAuditContext($envelope);
        unset($context['event']);

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function audit(
        AssessmentParticipant $assessment,
        string $sourceId,
        string $outcome,
        array $context,
        ?string $reasonCode = null,
    ): void {
        $at = CarbonImmutable::instance(now())->utc();
        DB::table('audit_logs')->insert([
            'branch_id' => $assessment->organization_id,
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'generic_assessment_result_outbox.'.$outcome,
            'subject_type' => GenericAssessmentResultVersion::class,
            'subject_id' => $sourceId,
            'context' => json_encode([
                ...$context,
                'reasonCode' => $reasonCode,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at,
            'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $at),
        ]);
    }

    private function auditFailure(
        string $sourceId,
        string $assessmentAttemptId,
        int $resultVersion,
        string $resultChecksum,
        string $reasonCode,
    ): void {
        $assessment = Str::isUlid($assessmentAttemptId)
            ? AssessmentParticipant::query()->where('assessment_attempt_id', $assessmentAttemptId)->first()
            : null;
        $at = CarbonImmutable::instance(now())->utc();
        DB::transaction(function () use ($assessment, $sourceId, $assessmentAttemptId, $resultVersion, $resultChecksum, $reasonCode, $at): void {
            DB::table('audit_logs')->insert([
                'branch_id' => $assessment?->organization_id,
                'actor_type' => 'system',
                'actor_id' => null,
                'action' => 'generic_assessment_result_outbox.failed',
                'subject_type' => GenericAssessmentResultVersion::class,
                'subject_id' => Str::isUlid($sourceId) ? $sourceId : null,
                'context' => json_encode([
                    'assessmentAttemptReference' => hash('sha256', $assessmentAttemptId),
                    'resultVersion' => $resultVersion > 0 ? $resultVersion : null,
                    'resultChecksum' => preg_match('/^[a-f0-9]{64}$/', $resultChecksum) === 1 ? $resultChecksum : null,
                    'finality' => null,
                    'isRevoked' => null,
                    'reasonCode' => $reasonCode,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'occurred_at' => $at,
                'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $at),
            ]);
        });
    }

    private function failureReason(LogicException $exception): string
    {
        return match ($exception->getMessage()) {
            'ASSESSMENT_RESULT_OUTBOX_SOURCE_NOT_FOUND' => 'SOURCE_NOT_FOUND',
            'ASSESSMENT_RESULT_OUTBOX_SOURCE_CONFLICT' => 'SOURCE_CONFLICT',
            'ASSESSMENT_RESULT_OUTBOX_BINDING_CORRUPT' => 'OUTBOX_BINDING_CORRUPT',
            default => 'BINDING_INVALID',
        };
    }
}
