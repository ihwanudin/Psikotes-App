<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\AssessmentParticipant;
use App\Models\GenericAssessmentResultVersion;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class GenericAssessmentResultStore
{
    public function __construct(private GenericAssessmentResultProjector $projector) {}

    /**
     * Persist an explicit snapshot produced by the future authorized scoring boundary.
     * This service is intentionally not a route and must not infer a result from session state.
     *
     * @param  array<string, mixed>  $authorizedSnapshot
     * @return array{action:string,envelope:array<string, mixed>}
     */
    public function persistAuthorizedSnapshot(array $authorizedSnapshot): array
    {
        $attemptId = $authorizedSnapshot['assessmentAttemptId'] ?? null;
        if (! is_string($attemptId) || ! Str::isUlid($attemptId)) {
            throw new DomainException('ASSESSMENT_RESULT_ATTEMPT_NOT_FOUND');
        }

        return DB::transaction(function () use ($attemptId, $authorizedSnapshot): array {
            $assessment = AssessmentParticipant::query()
                ->where('assessment_attempt_id', $attemptId)
                ->lockForUpdate()
                ->first();
            if ($assessment === null) {
                throw new DomainException('ASSESSMENT_RESULT_ATTEMPT_NOT_FOUND');
            }

            $latest = GenericAssessmentResultVersion::query()
                ->where('assessment_participant_id', $assessment->id)
                ->where('assessment_attempt_id', $attemptId)
                ->orderByDesc('result_version')
                ->lockForUpdate()
                ->first();
            $previous = $latest === null ? null : $this->envelopeFromRow($latest);
            $envelope = $this->projector->project($authorizedSnapshot, $previous);

            if ($latest !== null && $envelope['resultVersion'] === (int) $latest->result_version) {
                $this->audit($assessment, 'generic_assessment_result.replayed', $envelope);

                return ['action' => 'REPLAYED', 'envelope' => $envelope];
            }

            if ($latest === null && $envelope['revokedAt'] !== null) {
                throw new LogicException('ASSESSMENT_RESULT_INITIAL_REVOCATION_INVALID');
            }
            if ($latest !== null && $latest->revoked_at !== null) {
                throw new LogicException('ASSESSMENT_RESULT_ALREADY_REVOKED');
            }
            if ($latest !== null && $envelope['revokedAt'] !== null
                && ! $this->sameAuthoritativePayload($previous, $envelope)) {
                throw new LogicException('ASSESSMENT_RESULT_REVOCATION_INVALID');
            }
            if ($latest !== null && $envelope['revokedAt'] === null
                && $this->sameAuthoritativePayload($previous, $envelope)) {
                throw new LogicException('ASSESSMENT_RESULT_VERSION_NO_CHANGE');
            }

            $id = (string) Str::ulid();
            DB::table('generic_assessment_result_versions')->insert([
                'id' => $id,
                'assessment_participant_id' => $assessment->id,
                'assessment_attempt_id' => $attemptId,
                'result_version' => $envelope['resultVersion'],
                'supersedes_id' => $latest?->id,
                'iq' => $envelope['iq'],
                'iq_canonical' => $this->canonicalIq($envelope['iq']),
                'engine_version' => $envelope['engineVersion'],
                'completed_at' => $envelope['completedAt'],
                'finality' => $envelope['finality'],
                'revoked_at' => $envelope['revokedAt'],
                'result_checksum' => $envelope['resultChecksum'],
                'created_at' => CarbonImmutable::instance(now())->utc(),
            ]);

            $action = $latest === null
                ? 'CREATED'
                : ($envelope['revokedAt'] === null ? 'CORRECTED' : 'REVOKED');
            $this->audit($assessment, 'generic_assessment_result.'.strtolower($action), $envelope);

            return ['action' => $action, 'envelope' => $envelope];
        });
    }

    /**
     * @return array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int,resultChecksum:string}
     */
    private function envelopeFromRow(GenericAssessmentResultVersion $row): array
    {
        $iq = str_contains((string) $row->iq_canonical, '.')
            || str_contains(strtolower((string) $row->iq_canonical), 'e')
            ? (float) $row->iq_canonical
            : (int) $row->iq_canonical;

        return [
            'assessmentAttemptId' => (string) $row->assessment_attempt_id,
            'iq' => $iq,
            'engineVersion' => (string) $row->engine_version,
            'completedAt' => CarbonImmutable::instance($row->completed_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'finality' => (string) $row->finality,
            'revokedAt' => $row->revoked_at === null
                ? null
                : CarbonImmutable::instance($row->revoked_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'resultVersion' => (int) $row->result_version,
            'resultChecksum' => (string) $row->result_checksum,
        ];
    }

    /**
     * @param  array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int,resultChecksum:string}  $previous
     * @param  array{assessmentAttemptId:string,iq:int|float,engineVersion:string,completedAt:string,finality:string,revokedAt:?string,resultVersion:int,resultChecksum:string}  $next
     */
    private function sameAuthoritativePayload(array $previous, array $next): bool
    {
        foreach (['assessmentAttemptId', 'iq', 'engineVersion', 'completedAt', 'finality'] as $key) {
            if ($key === 'iq' ? (float) $previous[$key] !== (float) $next[$key] : $previous[$key] !== $next[$key]) {
                return false;
            }
        }

        return true;
    }

    private function canonicalIq(int|float $iq): string
    {
        return json_encode($iq, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string, mixed> $envelope */
    private function audit(AssessmentParticipant $assessment, string $action, array $envelope): void
    {
        $at = CarbonImmutable::instance(now())->utc();
        $context = $this->projector->safeAuditContext($envelope);
        unset($context['event']);

        DB::table('audit_logs')->insert([
            'branch_id' => $assessment->organization_id,
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => $action,
            'subject_type' => AssessmentParticipant::class,
            'subject_id' => (string) $assessment->id,
            'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at,
            'expires_at' => $at->addYearsNoOverflow(2),
        ]);
    }
}
