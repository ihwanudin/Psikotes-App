<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Participant;
use App\Models\SelectionParticipant;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutContractAdapter;
use App\Services\TestNumber\MonthlyTestNumberIssuer;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProvisionSelectionParticipant
{
    private const array INTENDED_FIELDS = ['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'];

    public function __construct(
        private RlsContextRunner $runner,
        private MonthlyTestNumberIssuer $testNumbers,
        private RetentionPolicy $retention,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{participant_id: int, replayed: bool}
     */
    public function handle(array $input, string $clientId, string $idempotencyKey): array
    {
        $requestHash = $this->requestHash($input);

        try {
            return $this->runner->run(
                new RlsContext('service'),
                fn (): array => $this->createOnce($input, $clientId, $idempotencyKey, $requestHash),
            );
        } catch (QueryException $exception) {
            return $this->runner->run(
                new RlsContext('service'),
                function () use ($clientId, $idempotencyKey, $input, $requestHash, $exception): array {
                    $existing = $this->findReplay(
                        $clientId,
                        $idempotencyKey,
                        (string) $input['externalCandidateId'],
                        true,
                    );
                    if ($existing === null) {
                        throw $exception;
                    }

                    return $this->replayResult($existing, $requestHash);
                },
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{participant_id: int, replayed: bool}
     */
    private function createOnce(array $input, string $clientId, string $idempotencyKey, string $requestHash): array
    {
        // Check before idempotent replay; the dedicated client is mapped by server branch_ref.
        $organizationId = Branch::query()->where('ref_code', config('selection_integration.branch_ref'))->value('id');
        if ($organizationId !== null) {
            try {
                app(CheckoutContractAdapter::class)->assertLegacyAllowed((int) $organizationId, 'SELEKSI_BEASISWA_JEPANG');
            } catch (IntegrationContractViolation) {
                throw new SelectionIntegrationUnavailable;
            }
        }
        $existing = $this->findReplay($clientId, $idempotencyKey, (string) $input['externalCandidateId'], true);

        if ($existing !== null) {
            return $this->replayResult($existing, $requestHash);
        }

        $branchRef = config('selection_integration.branch_ref');
        $branch = is_string($branchRef)
            ? Branch::query()->where('ref_code', $branchRef)->where('is_active', true)->sharedLock()->first()
            : null;
        $intendedField = strtoupper((string) config('selection_integration.intended_field', 'UMUM'));
        $configuredTestTypes = config('selection_integration.test_types', ['ist']);

        if ($branch === null
            || ! in_array($intendedField, self::INTENDED_FIELDS, true)
            || ! is_array($configuredTestTypes)
            || ! array_is_list($configuredTestTypes)
            || $configuredTestTypes === []
            || in_array('dass21', $configuredTestTypes, true)) {
            throw new SelectionIntegrationUnavailable;
        }
        try {
            $testTypes = TestPackage::canonicalComposition([...$configuredTestTypes, 'dass21']);
        } catch (DomainException) {
            throw new SelectionIntegrationUnavailable;
        }

        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'manual',
            'source_system' => 'SELEKSI_BEASISWA_JEPANG',
            'attribution_source' => $branch->ref_code,
            'full_name' => $input['fullName'],
            'gender' => $input['gender'],
            'birth_date' => $input['birthDate'],
            'education_level' => $input['educationLevel'],
            'intended_field' => $intendedField,
            'phone' => preg_replace('/[^0-9+]/', '', (string) $input['phone']),
            'email' => strtolower((string) $input['email']),
            'test_number' => $this->testNumbers->issue(),
        ]);

        $now = now()->toImmutable();
        $case = AssessmentCase::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => null,
            'origin' => 'LEGACY_SELECTION',
            'intended_field_snapshot' => $intendedField,
        ]);

        SelectionParticipant::query()->create([
            'client_id' => $clientId,
            'external_candidate_id' => $input['externalCandidateId'],
            'selection_round_id' => $input['selectionRoundId'],
            'registration_id' => $input['registrationId'],
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
        ]);

        $participant->entitlements()->createMany(array_map(
            static fn (string $testType): array => [
                'order_id' => null,
                'assessment_case_id' => $testType === 'dass21' ? null : $case->id,
                'test_type' => $testType,
                'status' => 'ready',
                'ready_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $testTypes,
        ));

        DB::table('audit_logs')->insert([
            'branch_id' => $branch->id,
            'actor_type' => 'service',
            'actor_id' => $clientId,
            'action' => 'selection_participant.provisioned',
            'subject_type' => Participant::class,
            'subject_id' => (string) $participant->id,
            'context' => json_encode([
                'selection_round_id' => $input['selectionRoundId'],
                'test_types' => $testTypes,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $now),
        ]);

        return ['participant_id' => $participant->id, 'replayed' => false];
    }

    private function findReplay(
        string $clientId,
        string $idempotencyKey,
        string $externalCandidateId,
        bool $lock,
    ): ?SelectionParticipant {
        $query = SelectionParticipant::query()
            ->where('client_id', $clientId)
            ->where(function ($query) use ($idempotencyKey, $externalCandidateId): void {
                $query->where('idempotency_key', $idempotencyKey)
                    ->orWhere('external_candidate_id', $externalCandidateId);
            })
            ->orderBy('id')
            ->limit(2);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get();
        if ($matches->count() > 1) {
            throw new IdempotencyConflict;
        }

        return $matches->first();
    }

    /** @return array{participant_id:int,replayed:bool} */
    private function replayResult(SelectionParticipant $existing, string $requestHash): array
    {
        $this->assertSameRequest($existing, $requestHash);
        $case = $existing->assessmentCase()->first();
        $participant = $existing->participant()->first();
        $entitlements = $participant?->entitlements()
            ->orderBy('id')->lockForUpdate()->get();
        if ($case === null
            || $participant === null
            || $case->participant_id !== $existing->participant_id
            || $case->organization_id !== $participant->branch_id
            || $case->origin !== 'LEGACY_SELECTION'
            || $case->package_id !== null
            || $case->intended_field_snapshot !== $participant->intended_field
            || $entitlements === null
            || $entitlements->count() < 2
            || $entitlements->pluck('test_type')->unique()->count() !== $entitlements->count()
            || $entitlements->where('test_type', 'dass21')->count() !== 1
            || $entitlements->contains(fn (Entitlement $entitlement): bool => $entitlement->order_id !== null)
            || $entitlements->contains(fn (Entitlement $entitlement): bool => ! in_array(
                $entitlement->test_type,
                ['dass21', 'ist', 'kraepelin', 'papi', 'rmib'],
                true,
            ))
            || $entitlements->contains(
                fn (Entitlement $entitlement): bool => $entitlement->assessment_case_id
                    !== ($entitlement->test_type === 'dass21' ? null : $case->id),
            )) {
            throw new IdempotencyConflict;
        }

        return ['participant_id' => $existing->participant_id, 'replayed' => true];
    }

    private function assertSameRequest(SelectionParticipant $existing, string $requestHash): void
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new IdempotencyConflict;
        }
    }

    /** @param array<string, mixed> $input */
    private function requestHash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
