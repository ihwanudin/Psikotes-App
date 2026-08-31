<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final readonly class RecordAssessmentOutcome
{
    private const array RECOMMENDATIONS = ['RECOMMENDED', 'RECOMMENDED_WITH_NOTES', 'NOT_RECOMMENDED', 'NEEDS_REVIEW'];

    public function __construct(private RlsContextRunner $runner) {}

    public function start(AssessmentParticipant $assessment): AssessmentParticipant
    {
        return $this->transition($assessment, 'IN_PROGRESS', 'PSYCHOTEST_STARTED');
    }

    public function complete(AssessmentParticipant $assessment): AssessmentParticipant
    {
        return $this->transition($assessment, 'COMPLETED', 'PSYCHOTEST_COMPLETED');
    }

    public function finalize(AssessmentParticipant $assessment, string $recommendation): AssessmentParticipant
    {
        if (! in_array($recommendation, self::RECOMMENDATIONS, true)) {
            throw new InvalidArgumentException('Unsupported assessment recommendation.');
        }

        return $this->transition($assessment, 'FINALIZED', 'PSYCHOTEST_RESULT_FINALIZED', $recommendation);
    }

    public function revoke(AssessmentParticipant $assessment): AssessmentParticipant
    {
        return $this->transition($assessment, 'REVOKED', 'PSYCHOTEST_RESULT_REVOKED');
    }

    public function void(AssessmentParticipant $assessment): AssessmentParticipant
    {
        return $this->transition($assessment, 'VOID', 'PSYCHOTEST_ATTEMPT_VOIDED');
    }

    private function transition(AssessmentParticipant $assessment, string $status, string $eventType, ?string $recommendation = null): AssessmentParticipant
    {
        return $this->runner->run(new RlsContext('service'), function () use ($assessment, $status, $eventType, $recommendation): AssessmentParticipant {
            return DB::transaction(function () use ($assessment, $status, $eventType, $recommendation): AssessmentParticipant {
                $locked = AssessmentParticipant::query()->with('client.organization')->lockForUpdate()->findOrFail($assessment->id);
                if ($locked->assessment_status === $status
                    && ($recommendation === null || $locked->recommendation === $recommendation)) {
                    return $locked;
                }

                $allowedFrom = match ($status) {
                    'IN_PROGRESS' => ['READY'],
                    'COMPLETED' => ['IN_PROGRESS'],
                    'FINALIZED' => ['COMPLETED', 'UNDER_REVIEW'],
                    'REVOKED' => ['FINALIZED'],
                    'VOID' => ['PROVISIONED', 'READY', 'IN_PROGRESS', 'COMPLETED', 'UNDER_REVIEW', 'FINALIZED', 'REVOKED'],
                    default => [],
                };
                if (! in_array($locked->assessment_status, $allowedFrom, true)) {
                    throw new LogicException('Invalid assessment status transition.');
                }

                $now = now()->utc();
                $locked->assessment_status = $status;
                if (in_array($status, ['FINALIZED', 'REVOKED', 'VOID'], true)) {
                    $locked->result_version++;
                }
                if ($recommendation !== null) {
                    $locked->recommendation = $recommendation;
                }
                if ($status === 'FINALIZED') {
                    $locked->finalized_at = $now;
                    $locked->revoked_at = null;
                } elseif ($status === 'REVOKED') {
                    $locked->revoked_at = $now;
                }
                $locked->save();

                $this->enqueue($locked, $eventType, $now);

                return $locked;
            });
        });
    }

    private function enqueue(AssessmentParticipant $assessment, string $eventType, CarbonInterface $now): void
    {
        /** @var IntegrationClient $client */
        $client = $assessment->client;
        $eventId = (string) Str::ulid();
        $payload = [
            'eventId' => $eventId,
            'eventType' => $eventType,
            'eventVersion' => 'v1',
            'sourceSystem' => 'PSIKOTES',
            'targetSystem' => $assessment->source_system,
            'organizationCode' => $client->organization->organization_code,
            'integrationClientId' => $client->client_id,
            'externalCandidateId' => $assessment->external_candidate_id,
            'externalProcessId' => $assessment->external_process_id,
            'assessmentRoundId' => $assessment->assessment_round_id,
            'participantId' => (string) $assessment->participant_id,
            'assessmentStatus' => $assessment->assessment_status,
            'recommendation' => $assessment->recommendation,
            'resultVersion' => $assessment->result_version,
            'finalizedAt' => $assessment->finalized_at?->toISOString(),
            'revokedAt' => $assessment->revoked_at?->toISOString(),
            'correlationId' => $assessment->assessment_attempt_id,
        ];

        DB::table('outbox_messages')->insertOrIgnore([
            'message_id' => $eventId,
            'deduplication_key' => hash('sha256', $eventType.'|'.$assessment->id.'|'.$assessment->result_version),
            'topic' => 'psychotest.assessment-event',
            'aggregate_type' => AssessmentParticipant::class,
            'aggregate_id' => (string) $assessment->id,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'processed_at' => null,
            'expires_at' => $now->copy()->addYears(2),
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
