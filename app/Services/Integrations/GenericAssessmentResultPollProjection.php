<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\GenericAssessmentResultVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class GenericAssessmentResultPollProjection
{
    public function __construct(private GenericAssessmentResultProjector $projector) {}

    /**
     * @return array{status:'AVAILABLE'|'REPLAYED',envelope:array<string,mixed>}|array{status:'UNAVAILABLE',envelope:null}
     */
    public function project(
        int $integrationClientId,
        string $assessmentAttemptId,
        ?int $knownVersion = null,
        ?string $knownChecksum = null,
    ): array {
        $canonicalAttemptId = strtoupper($assessmentAttemptId);

        return DB::transaction(function () use ($integrationClientId, $canonicalAttemptId, $knownVersion, $knownChecksum): array {
            $row = $this->projectionRow(DB::table('integration_clients as c')
                ->leftJoin('assessment_participants as p', function (JoinClause $join) use ($canonicalAttemptId): void {
                    $join->on('p.integration_client_id', '=', 'c.id')
                        ->where('p.assessment_attempt_id', '=', $canonicalAttemptId);
                })
                ->leftJoin('generic_assessment_result_outbox as o', 'o.assessment_participant_id', '=', 'p.id')
                ->leftJoin('generic_assessment_result_versions as r', 'r.id', '=', 'o.generic_assessment_result_version_id')
                ->where('c.id', $integrationClientId)
                ->where('c.enabled', true)
                ->select([
                    'c.organization_id as client_organization_id',
                    'p.id as participant_id', 'p.assessment_attempt_id as participant_attempt_id',
                    'o.id as outbox_id', 'o.assessment_participant_id as outbox_participant_id',
                    'o.assessment_attempt_id as outbox_attempt_id', 'o.result_version as outbox_version',
                    'o.result_checksum as outbox_checksum', 'o.envelope_contract',
                    'o.generic_assessment_result_version_id as outbox_source_id',
                    'r.id as source_id', 'r.assessment_participant_id as source_participant_id',
                    'r.assessment_attempt_id as source_attempt_id', 'r.result_version as source_version',
                    'r.result_checksum as source_checksum', 'r.iq_canonical', 'r.engine_version',
                    'r.completed_at', 'r.finality', 'r.revoked_at',
                ])
                ->selectSub(function ($query): void {
                    $query->from('generic_assessment_result_versions as latest')
                        ->selectRaw('MAX(latest.result_version)')
                        ->whereColumn('latest.assessment_participant_id', 'p.id')
                        ->whereColumn('latest.assessment_attempt_id', 'p.assessment_attempt_id');
                }, 'latest_result_version')
                ->orderByDesc('r.result_version')
                ->first());

            $cursorValid = ($knownVersion === null && $knownChecksum === null)
                || ($knownVersion !== null && $knownVersion > 0 && is_string($knownChecksum)
                    && preg_match('/^[a-f0-9]{64}$/', $knownChecksum) === 1);
            $inputValid = $integrationClientId > 0 && Str::isUlid($canonicalAttemptId) && $cursorValid;
            $envelope = $inputValid ? $this->validatedEnvelope($row, $canonicalAttemptId) : null;
            if ($envelope === null) {
                $this->auditUnavailable(
                    $integrationClientId,
                    $row?->clientOrganizationId,
                    $canonicalAttemptId,
                );

                return ['status' => 'UNAVAILABLE', 'envelope' => null];
            }

            $status = 'AVAILABLE';
            if ($knownVersion !== null && is_string($knownChecksum)) {
                if ($knownVersion === $envelope['resultVersion']
                    && hash_equals($envelope['resultChecksum'], $knownChecksum)) {
                    $status = 'REPLAYED';
                } elseif ($knownVersion >= $envelope['resultVersion']) {
                    $this->auditUnavailable(
                        $integrationClientId,
                        $row->clientOrganizationId,
                        $canonicalAttemptId,
                        (string) $row->sourceId,
                        $envelope,
                    );

                    return ['status' => 'UNAVAILABLE', 'envelope' => null];
                }
            }
            $this->auditAvailable(
                $integrationClientId,
                (int) $row->clientOrganizationId,
                (string) $row->sourceId,
                $status,
                $envelope,
            );

            return ['status' => $status, 'envelope' => $envelope];
        });
    }

    /** @return array<string,mixed>|null */
    private function validatedEnvelope(?GenericAssessmentResultPollRow $row, string $canonicalAttemptId): ?array
    {
        if ($row === null || $row->participantId === null || $row->outboxId === null || $row->sourceId === null
            || $row->participantId !== $row->outboxParticipantId
            || $row->participantId !== $row->sourceParticipantId
            || $row->participantAttemptId !== $canonicalAttemptId
            || $row->outboxAttemptId !== $canonicalAttemptId
            || $row->sourceAttemptId !== $canonicalAttemptId
            || $row->outboxSourceId !== $row->sourceId
            || $row->outboxVersion !== $row->sourceVersion
            || $row->sourceVersion !== $row->latestResultVersion
            || ! is_string($row->outboxChecksum) || ! is_string($row->sourceChecksum)
            || ! hash_equals($row->outboxChecksum, $row->sourceChecksum)
            || $row->envelopeContract !== 'generic-assessment-result:v1') {
            return null;
        }

        try {
            $iqCanonical = (string) $row->iqCanonical;
            $envelope = [
                'assessmentAttemptId' => $canonicalAttemptId,
                'iq' => str_contains($iqCanonical, '.') || str_contains(strtolower($iqCanonical), 'e')
                    ? (float) $iqCanonical
                    : (int) $iqCanonical,
                'engineVersion' => (string) $row->engineVersion,
                'completedAt' => CarbonImmutable::parse((string) $row->completedAt)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'finality' => (string) $row->finality,
                'revokedAt' => $row->revokedAt === null
                    ? null
                    : CarbonImmutable::parse($row->revokedAt)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'resultVersion' => (int) $row->sourceVersion,
                'resultChecksum' => $row->sourceChecksum,
            ];
            $this->projector->safeAuditContext($envelope);

            return $envelope;
        } catch (Throwable) {
            return null;
        }
    }

    private function projectionRow(?object $row): ?GenericAssessmentResultPollRow
    {
        if ($row === null) {
            return null;
        }
        $data = (array) $row;

        return new GenericAssessmentResultPollRow(
            clientOrganizationId: isset($data['client_organization_id']) ? (int) $data['client_organization_id'] : null,
            participantId: isset($data['participant_id']) ? (int) $data['participant_id'] : null,
            participantAttemptId: isset($data['participant_attempt_id']) ? (string) $data['participant_attempt_id'] : null,
            outboxId: isset($data['outbox_id']) ? (string) $data['outbox_id'] : null,
            outboxParticipantId: isset($data['outbox_participant_id']) ? (int) $data['outbox_participant_id'] : null,
            outboxAttemptId: isset($data['outbox_attempt_id']) ? (string) $data['outbox_attempt_id'] : null,
            outboxVersion: isset($data['outbox_version']) ? (int) $data['outbox_version'] : null,
            outboxChecksum: isset($data['outbox_checksum']) ? (string) $data['outbox_checksum'] : null,
            envelopeContract: isset($data['envelope_contract']) ? (string) $data['envelope_contract'] : null,
            outboxSourceId: isset($data['outbox_source_id']) ? (string) $data['outbox_source_id'] : null,
            sourceId: isset($data['source_id']) ? (string) $data['source_id'] : null,
            sourceParticipantId: isset($data['source_participant_id']) ? (int) $data['source_participant_id'] : null,
            sourceAttemptId: isset($data['source_attempt_id']) ? (string) $data['source_attempt_id'] : null,
            sourceVersion: isset($data['source_version']) ? (int) $data['source_version'] : null,
            sourceChecksum: isset($data['source_checksum']) ? (string) $data['source_checksum'] : null,
            iqCanonical: isset($data['iq_canonical']) ? (string) $data['iq_canonical'] : null,
            engineVersion: isset($data['engine_version']) ? (string) $data['engine_version'] : null,
            completedAt: isset($data['completed_at']) ? (string) $data['completed_at'] : null,
            finality: isset($data['finality']) ? (string) $data['finality'] : null,
            revokedAt: isset($data['revoked_at']) ? (string) $data['revoked_at'] : null,
            latestResultVersion: isset($data['latest_result_version']) ? (int) $data['latest_result_version'] : null,
        );
    }

    /** @param array<string,mixed>|null $envelope */
    private function auditUnavailable(
        int $clientId,
        mixed $organizationId,
        string $attemptId,
        ?string $sourceId = null,
        ?array $envelope = null,
    ): void {
        $at = CarbonImmutable::instance(now())->utc();
        $context = $envelope === null
            ? [
                'assessmentAttemptReference' => hash('sha256', $attemptId),
                'resultVersion' => null, 'resultChecksum' => null,
                'finality' => null, 'isRevoked' => null,
            ]
            : $this->projector->safeAuditContext($envelope);
        unset($context['event']);
        DB::table('audit_logs')->insert([
            'branch_id' => is_numeric($organizationId) ? (int) $organizationId : null,
            'actor_type' => 'integration_client', 'actor_id' => $clientId > 0 ? (string) $clientId : null,
            'action' => 'generic_assessment_result_poll.unavailable',
            'subject_type' => GenericAssessmentResultVersion::class, 'subject_id' => $sourceId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at, 'expires_at' => $at->addYearsNoOverflow(2),
        ]);
    }

    /** @param array<string,mixed> $envelope */
    private function auditAvailable(
        int $clientId,
        int $organizationId,
        string $sourceId,
        string $status,
        array $envelope,
    ): void {
        $context = $this->projector->safeAuditContext($envelope);
        unset($context['event']);
        $at = CarbonImmutable::instance(now())->utc();
        DB::table('audit_logs')->insert([
            'branch_id' => $organizationId, 'actor_type' => 'integration_client', 'actor_id' => (string) $clientId,
            'action' => 'generic_assessment_result_poll.'.strtolower($status),
            'subject_type' => GenericAssessmentResultVersion::class, 'subject_id' => $sourceId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at, 'expires_at' => $at->addYearsNoOverflow(2),
        ]);
    }
}

final readonly class GenericAssessmentResultPollRow
{
    public function __construct(
        public ?int $clientOrganizationId,
        public ?int $participantId,
        public ?string $participantAttemptId,
        public ?string $outboxId,
        public ?int $outboxParticipantId,
        public ?string $outboxAttemptId,
        public ?int $outboxVersion,
        public ?string $outboxChecksum,
        public ?string $envelopeContract,
        public ?string $outboxSourceId,
        public ?string $sourceId,
        public ?int $sourceParticipantId,
        public ?string $sourceAttemptId,
        public ?int $sourceVersion,
        public ?string $sourceChecksum,
        public ?string $iqCanonical,
        public ?string $engineVersion,
        public ?string $completedAt,
        public ?string $finality,
        public ?string $revokedAt,
        public ?int $latestResultVersion,
    ) {}
}
