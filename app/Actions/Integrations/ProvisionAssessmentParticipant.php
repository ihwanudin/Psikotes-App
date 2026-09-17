<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\AssessmentCase;
use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutContractAdapter;
use App\Services\TestNumber\MonthlyTestNumberIssuer;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProvisionAssessmentParticipant
{
    public function __construct(
        private RlsContextRunner $runner,
        private MonthlyTestNumberIssuer $testNumbers,
        private RetentionPolicy $retention,
    ) {}

    /** @param array<string, mixed> $input
     * @return array{participant_id:int, assessment_attempt_id:string, assessment_status:string, replayed:bool}
     */
    public function handle(array $input, IntegrationClient $client, string $idempotencyKey): array
    {
        $requestHash = $this->requestHash($input);
        $logicalKey = $this->logicalKey($input);

        try {
            return $this->runner->run(new RlsContext('service'), function () use ($input, $client, $idempotencyKey, $requestHash, $logicalKey): array {
                app(CheckoutContractAdapter::class)->assertLegacyAllowed($client->organization_id, $input['sourceSystem']);
                $replay = DB::transaction(function () use ($client, $idempotencyKey, $logicalKey, $requestHash): ?array {
                    $existing = $this->findReplay($client, $idempotencyKey, $logicalKey);

                    return $existing === null ? null : $this->replayResult($existing, $requestHash);
                });
                if ($replay !== null) {
                    return $replay;
                }

                return DB::transaction(function () use ($input, $client, $idempotencyKey, $requestHash, $logicalKey): array {
                    $client->loadMissing('organization');
                    if ($client->organization->organization_code !== $input['organizationCode']) {
                        throw new IntegrationContractViolation('ORGANIZATION_MISMATCH');
                    }

                    $source = IntegrationSource::query()
                        ->where('integration_client_id', $client->id)
                        ->where('source_system', $input['sourceSystem'])
                        ->where('contract_version', 'v1')
                        ->where('status', 'ACTIVE')
                        ->first();
                    if ($source === null || ! $this->effective($source)) {
                        throw new IntegrationContractViolation('SOURCE_NOT_ALLOWED');
                    }

                    if (! in_array($input['assessmentPackageCode'], $source->allowed_assessment_packages ?? [], true)) {
                        throw new IntegrationContractViolation('PACKAGE_NOT_ALLOWED');
                    }
                    $organizationFunding = $client->organization->allowed_funding_modes ?? [];
                    if (! in_array($input['fundingMode'], $source->allowed_funding_modes ?? [], true)
                        || ! in_array($input['fundingMode'], $organizationFunding, true)) {
                        throw new IntegrationContractViolation('FUNDING_MODE_NOT_ALLOWED');
                    }

                    $package = TestPackage::query()->with('items')->where('code', $input['assessmentPackageCode'])->where('is_active', true)->first();
                    if ($package === null || $package->items->isEmpty()) {
                        throw new IntegrationContractViolation('PACKAGE_NOT_ALLOWED');
                    }
                    try {
                        $testTypes = TestPackage::canonicalComposition($package->items->pluck('test_type')->values()->all());
                    } catch (DomainException) {
                        throw new IntegrationContractViolation('PACKAGE_NOT_ALLOWED');
                    }

                    $participant = $this->existingIdentity($client, $input) ?? $this->createParticipant($client, $package, $input);
                    $now = now()->toImmutable();
                    $attemptId = (string) Str::ulid();
                    $case = AssessmentCase::query()->create([
                        'public_id' => $attemptId,
                        'participant_id' => $participant->id,
                        'organization_id' => $client->organization_id,
                        'package_id' => $package->id,
                        'origin' => 'INTEGRATED',
                        // Generic v1 has no intended-field input; the mutable participant default is not evidence.
                        'intended_field_snapshot' => null,
                    ]);
                    $mapping = AssessmentParticipant::query()->create([
                        'assessment_case_id' => $case->id,
                        'integration_client_id' => $client->id,
                        'organization_id' => $client->organization_id,
                        'participant_id' => $participant->id,
                        'package_id' => $package->id,
                        'assessment_attempt_id' => $attemptId,
                        'source_system' => $input['sourceSystem'],
                        'external_candidate_id' => $input['externalCandidateId'],
                        'external_process_id' => $input['externalProcessId'] ?? null,
                        'external_registration_id' => $input['externalRegistrationId'] ?? null,
                        'assessment_round_id' => $input['assessmentRoundId'] ?? null,
                        'funding_mode' => $input['fundingMode'],
                        'assessment_status' => 'READY',
                        'result_version' => 0,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'logical_assessment_key' => $logicalKey,
                        'metadata' => $input['metadata'] ?? [],
                    ]);
                    foreach ($testTypes as $testType) {
                        $participant->entitlements()->firstOrCreate([
                            'test_type' => $testType,
                            'assessment_case_id' => $testType === 'dass21' ? null : $case->id,
                        ], [
                            'order_id' => null, 'status' => 'ready', 'ready_at' => $now,
                        ]);
                    }

                    DB::table('audit_logs')->insert([
                        'branch_id' => $client->organization_id,
                        'actor_type' => 'integration_client',
                        'actor_id' => $client->client_id,
                        'action' => 'assessment_participant.provisioned',
                        'subject_type' => Participant::class,
                        'subject_id' => (string) $participant->id,
                        'context' => json_encode(['source_system' => $input['sourceSystem'], 'assessment_attempt_id' => $mapping->assessment_attempt_id], JSON_THROW_ON_ERROR),
                        'occurred_at' => $now,
                        'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $now),
                    ]);
                    $this->enqueueProvisioned($mapping, $client, $now);

                    return $this->result($mapping, false);
                });
            });
        } catch (QueryException $exception) {
            return $this->runner->run(new RlsContext('service'), fn (): array => DB::transaction(function () use ($client, $idempotencyKey, $logicalKey, $requestHash, $exception): array {
                $existing = $this->findReplay($client, $idempotencyKey, $logicalKey);
                if ($existing === null) {
                    throw $exception;
                }

                return $this->replayResult($existing, $requestHash);
            }));
        }
    }

    private function findReplay(IntegrationClient $client, string $idempotencyKey, string $logicalKey): ?AssessmentParticipant
    {
        $matches = AssessmentParticipant::query()
            ->where('integration_client_id', $client->id)
            ->where(fn ($query) => $query
                ->where('idempotency_key', $idempotencyKey)
                ->orWhere('logical_assessment_key', $logicalKey))
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($matches->count() > 1) {
            throw new IdempotencyConflict;
        }

        return $matches->first();
    }

    /** @return array{participant_id:int, assessment_attempt_id:string, assessment_status:string, replayed:bool} */
    private function replayResult(AssessmentParticipant $mapping, string $requestHash): array
    {
        if (! hash_equals($mapping->request_hash, $requestHash)) {
            throw new IdempotencyConflict;
        }
        $this->assertCaseBinding($mapping);

        return $this->result($mapping, true);
    }

    private function assertCaseBinding(AssessmentParticipant $mapping): void
    {
        $case = $mapping->assessmentCase()->first();
        if ($case === null
            || ! hash_equals($case->public_id, $mapping->assessment_attempt_id)
            || $case->participant_id !== $mapping->participant_id
            || $case->organization_id !== $mapping->organization_id
            || $case->package_id !== $mapping->package_id
            || $case->origin !== 'INTEGRATED') {
            throw new IdempotencyConflict;
        }
    }

    /** @param array<string,mixed> $input */
    private function existingIdentity(IntegrationClient $client, array $input): ?Participant
    {
        $mapping = AssessmentParticipant::query()
            ->where('integration_client_id', $client->id)
            ->where('source_system', $input['sourceSystem'])
            ->where('external_candidate_id', $input['externalCandidateId'])
            ->oldest('id')->first();

        return $mapping?->participant;
    }

    /** @param array<string,mixed> $input */
    private function createParticipant(IntegrationClient $client, TestPackage $package, array $input): Participant
    {
        $profile = $input['profile'];

        return Participant::query()->create([
            'branch_id' => $client->organization_id,
            'referral_branch_id' => $client->organization_id,
            'referral_source' => 'manual',
            'package_id' => $package->id,
            'source_system' => $input['sourceSystem'],
            'attribution_source' => $client->organization->ref_code,
            'full_name' => $profile['fullName'],
            'gender' => strtolower($profile['gender']),
            'birth_date' => $profile['birthDate'],
            'education_level' => $profile['educationLevel'],
            'intended_field' => 'UMUM',
            'phone' => preg_replace('/[^0-9+]/', '', (string) $profile['phone']),
            'email' => isset($profile['email']) ? strtolower((string) $profile['email']) : null,
            'test_number' => $this->testNumbers->issue(),
        ]);
    }

    private function effective(IntegrationSource $source): bool
    {
        return ($source->effective_from === null || ! $source->effective_from->isFuture())
            && ($source->effective_until === null || $source->effective_until->isFuture());
    }

    private function enqueueProvisioned(AssessmentParticipant $assessment, IntegrationClient $client, CarbonInterface $now): void
    {
        $eventId = (string) Str::ulid();
        $payload = [
            'eventId' => $eventId,
            'eventType' => 'PSYCHOTEST_PARTICIPANT_PROVISIONED',
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
            'recommendation' => null,
            'resultVersion' => 0,
            'finalizedAt' => null,
            'revokedAt' => null,
            'correlationId' => $assessment->assessment_attempt_id,
        ];

        DB::table('outbox_messages')->insertOrIgnore([
            'message_id' => $eventId,
            'deduplication_key' => hash('sha256', 'PSYCHOTEST_PARTICIPANT_PROVISIONED|'.$assessment->id),
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

    /** @param array<string,mixed> $input */
    private function requestHash(array $input): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            ksort($value);
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $normalize($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $input */
    private function logicalKey(array $input): string
    {
        return hash('sha256', implode('|', [
            $input['sourceSystem'],
            $input['externalCandidateId'],
            $input['externalProcessId'] ?? '',
            $input['assessmentRoundId'] ?? '',
            $input['assessmentPackageCode'],
        ]));
    }

    /** @return array{participant_id:int, assessment_attempt_id:string, assessment_status:string, replayed:bool} */
    private function result(AssessmentParticipant $mapping, bool $replayed): array
    {
        return [
            'participant_id' => $mapping->participant_id,
            'assessment_attempt_id' => $mapping->assessment_attempt_id,
            'assessment_status' => $mapping->assessment_status,
            'replayed' => $replayed,
        ];
    }
}
